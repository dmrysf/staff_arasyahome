<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Document\DocumentStaleness;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Production\ProductionAuthority;
use Arasya\Operations\Production\ProductionAuthorityMode;
use Arasya\Operations\Production\ProductionAuthorityModes;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use Arasya\Operations\Production\ProductionQrLedger;
use PDO;

final readonly class OrderProjectionWriter
{
    public const INITIAL_STAGE_ID = 'waiting';

    /**
     * @param ProductionAuthorityModes|null $authorityModes per-source production authority modes; without
     *   them every source is LEGACY (new orders keep source authority, as before API 2.18.0)
     */
    public function __construct(private PDO $pdo, private Clock $clock, private ?ProductionAuthorityModes $authorityModes = null)
    {
    }

    public function apply(SourceOrderSnapshot $snapshot): string
    {
        // Internal B2B identity can only be created by the authenticated explicit handoff command.
        if ($snapshot->sourceKey === 'b2b') {
            throw new ApiException(403, 'INTERNAL_SOURCE_ONLY', 'Internal orders cannot be ingested from a source.');
        }
        // Trendyol packages enter production only through an explicit approval in the Staff workspace.
        if ($snapshot->sourceKey === 'trendyol') {
            throw new ApiException(403, 'MARKETPLACE_APPROVAL_ONLY', 'Trendyol orders enter production only through an explicit approval.');
        }
        $globalId = $snapshot->globalId()->toString();
        $itemsForHash = $snapshot->items;
        usort($itemsForHash, fn($a, $b) => $a->lineNumber <=> $b->lineNumber);

        $hashData = [
            'source' => $snapshot->sourceKey,
            'source_order_id' => $snapshot->sourceOrderId,
            'order_number' => $snapshot->orderNumber,
            'stage' => $snapshot->productionStageId,
            'status_code' => $snapshot->sourceCommerceStatusCode,
            'status_label' => $snapshot->sourceCommerceStatusLabel,
            'notes' => $snapshot->productionNotes,
            'op_status' => $snapshot->operationalStatus,
            'accepted_at' => $snapshot->acceptedAt?->format('Y-m-d\TH:i:s.v\Z'),
            'items' => array_map(fn(OperationalOrderItem $i) => [
                'source_item_id' => $i->sourceItemId,
                'line_number' => $i->lineNumber,
                'name' => $i->name,
                'code' => $i->productCode,
                'variant' => $i->variant,
                'color' => $i->color,
                'w' => $i->widthValue !== null ? number_format((float)$i->widthValue, 3, '.', '') : null,
                'h' => $i->heightValue !== null ? number_format((float)$i->heightValue, 3, '.', '') : null,
                'u' => $i->measurementUnit,
                'm' => $i->meters !== null ? number_format((float)$i->meters, 3, '.', '') : null,
                'qty' => $i->quantity
            ] + (($i->productionContext['options'] ?? []) === [] ? [] : ['opt' => $i->productionContext['options']]), $itemsForHash),
        ];
        // The printable delivery identity is kept outside the receipt hash so existing source events keep
        // their hash; it is applied below and decides document staleness like any printed field.
        $documentContext = $snapshot->delivery === null ? null : json_encode($snapshot->delivery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Ensure deterministic JSON
        $payloadHash = hash('sha256', json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);

        try {
            $this->pdo->beginTransaction();

            // Validate source is active
            $stmt = $this->pdo->prepare('SELECT schema_version, status FROM order_sources WHERE source_key = ?');
            $stmt->execute([$snapshot->sourceKey]);
            $sourceRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sourceRow) {
                throw new ApiException(404, 'SOURCE_UNKNOWN', 'The source key is not registered.');
            }
            if ($sourceRow['status'] !== 'active') {
                throw new ApiException(409, 'SOURCE_INACTIVE', 'The source is currently inactive.');
            }
            if ((int)$sourceRow['schema_version'] !== $snapshot->sourceSchemaVersion) {
                throw new ApiException(422, 'SOURCE_SCHEMA_UNSUPPORTED', 'Snapshot schema version does not match active registry version.');
            }

            // An explicit source stage must be an active canonical stage; it is never guessed.
            $sourceStageOrdinal = null;
            if ($snapshot->productionStageId !== null) {
                $sourceStageOrdinal = $this->activeStageOrdinal($snapshot->productionStageId);
                if ($sourceStageOrdinal === null) {
                    throw new ApiException(422, 'SOURCE_STAGE_UNKNOWN', 'The production stage does not belong to the active canonical workflow or is inactive.');
                }
            }

            // Check if receipt already exists
            $stmt = $this->pdo->prepare('SELECT payload_hash, global_order_id FROM order_projection_receipts WHERE source_key = ? AND source_event_id = ?');
            $stmt->execute([$snapshot->sourceKey, $snapshot->sourceEventId]);
            $existingReceipt = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingReceipt !== false) {
                if ($existingReceipt['global_order_id'] !== $globalId) {
                    throw new ApiException(409, 'SOURCE_EVENT_CONFLICT', 'Event ID conflict with different global order ID.');
                }
                if (hash_equals((string)$existingReceipt['payload_hash'], $payloadHash)) {
                    // A replayed event may fill a delivery identity that was never stored, only while no
                    // central document exists (nothing printed can become stale through this path).
                    if ($documentContext !== null) {
                        $this->pdo->prepare("UPDATE operational_orders SET document_context = ? WHERE global_order_id = ? AND document_context IS NULL AND document_status = 'none'")->execute([$documentContext, $globalId]);
                        $this->pdo->commit();
                        return 'duplicate';
                    }
                    $this->pdo->rollBack();
                    return 'duplicate';
                }
                throw new ApiException(409, 'SOURCE_EVENT_CONFLICT', 'Event ID conflict with different payload hash.');
            }

            // Lock order row
            $stmt = $this->pdo->prepare('SELECT order_uuid, source_changed_at, projection_hash, version, production_version, production_stage_id, production_authority, operational_status, cutting_first_claimed_at, document_context FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
            $stmt->execute([$globalId]);
            $currentOrder = $stmt->fetch(PDO::FETCH_ASSOC);

            $nowSql = $this->clock->now()->format('Y-m-d H:i:s.u');
            $sourceChangedSql = $snapshot->sourceChangedAt->format('Y-m-d H:i:s.u');

            if ($currentOrder) {
                $orderUuid = $currentOrder['order_uuid'];
                $currentChangedAt = $currentOrder['source_changed_at'];

                if ($sourceChangedSql < $currentChangedAt) {
                    $this->insertReceipt($snapshot, $globalId, $payloadHash, 'out_of_order', $nowSql);
                    $this->touchSource($snapshot->sourceKey, $nowSql);
                    $this->pdo->commit();
                    return 'out_of_order';
                }
                
                if ($sourceChangedSql === $currentChangedAt && !hash_equals((string)$currentOrder['projection_hash'], $payloadHash)) {
                    throw new ApiException(409, 'SOURCE_REVISION_CONFLICT', 'Conflicting changes at the same timestamp.');
                }
                
                if (hash_equals((string)$currentOrder['projection_hash'], $payloadHash)) {
                    // Same semantic payload: refresh observation time only; the order version never changes.
                    $stmt = $this->pdo->prepare('UPDATE operational_orders SET source_event_id = ?, source_changed_at = ?, last_source_seen_at = ?, projected_at = ? WHERE order_uuid = ?');
                    $stmt->execute([$snapshot->sourceEventId, $sourceChangedSql, $nowSql, $nowSql, $orderUuid]);
                    if ($snapshot->delivery !== null && self::sortedKeys($snapshot->delivery) !== self::sortedKeys(json_decode((string) ($currentOrder['document_context'] ?? 'null'), true))) {
                        $this->pdo->prepare('UPDATE operational_orders SET document_context = ?, version = version + 1, updated_at = ? WHERE order_uuid = ?')->execute([$documentContext, $nowSql, $orderUuid]);
                        (new DocumentStaleness($this->pdo, $this->clock))->onContentChanged($orderUuid, $snapshot->sourceEventId);
                    }
                    $this->ensureQrReference($orderUuid, $globalId, $snapshot->sourceKey, $nowSql, $snapshot->sourceEventId);
                    $this->insertReceipt($snapshot, $globalId, $payloadHash, 'duplicate', $nowSql);
                    $this->touchSource($snapshot->sourceKey, $nowSql);
                    $this->pdo->commit();
                    return 'duplicate';
                }

                $version = (int)$currentOrder['version'] + 1;
                $productionVersion = (int)$currentOrder['production_version'];
                $stageId = (string)$currentOrder['production_stage_id'];
                // Production belongs to Operations once an employee acted on the order. Before that an
                // explicit source stage may only move the order forward; commerce status never moves it.
                if ($sourceStageOrdinal !== null && $currentOrder['production_authority'] === 'source' && $snapshot->productionStageId !== $stageId) {
                    $currentOrdinal = $this->activeStageOrdinal($stageId);
                    if ($currentOrdinal === null || $sourceStageOrdinal > $currentOrdinal) {
                        $stageId = (string) $snapshot->productionStageId;
                        $productionVersion++;
                    }
                }
                $productionChanged = $stageId !== (string)$currentOrder['production_stage_id'];

                // Update order
                $stmt = $this->pdo->prepare('
                    UPDATE operational_orders SET 
                        order_number = ?,
                        order_lookup_code = ?,
                        production_stage_id = ?,
                        production_version = ?,
                        production_changed_at = IF(?, ?, production_changed_at),
                        source_commerce_status_code = ?,
                        source_commerce_status_label = ?,
                        production_notes = ?,
                        operational_status = ?,
                        source_reported_unavailable_at = IF(?, ?, NULL),
                        freshness_status = ?,
                        source_schema_version = ?,
                        source_event_id = ?,
                        source_changed_at = ?,
                        last_source_seen_at = ?,
                        projected_at = ?,
                        accepted_at = ?,
                        projection_hash = ?,
                        document_context = COALESCE(?, document_context),
                        version = ?,
                        updated_at = ?
                    WHERE order_uuid = ?
                ');
                $stmt->execute([
                    $snapshot->orderNumber,
                    OrderLookupCode::fromOrderNumber($snapshot->orderNumber),
                    $stageId,
                    $productionVersion,
                    $productionChanged ? 1 : 0,
                    $nowSql,
                    $snapshot->sourceCommerceStatusCode,
                    $snapshot->sourceCommerceStatusLabel,
                    $snapshot->productionNotes,
                    $snapshot->operationalStatus === 'unavailable' && $currentOrder['cutting_first_claimed_at'] !== null ? $currentOrder['operational_status'] : $snapshot->operationalStatus,
                    $snapshot->operationalStatus === 'unavailable' ? 1 : 0,
                    $nowSql,
                    'fresh',
                    $snapshot->sourceSchemaVersion,
                    $snapshot->sourceEventId,
                    $sourceChangedSql,
                    $nowSql,
                    $nowSql,
                    $snapshot->acceptedAt?->format('Y-m-d H:i:s.u'),
                    $payloadHash,
                    $documentContext,
                    $version,
                    $nowSql,
                    $orderUuid,
                ]);

                $stmt = $this->pdo->prepare('SELECT item_uuid, source_item_id FROM operational_order_items WHERE order_uuid = ?');
                $stmt->execute([$orderUuid]);
                $existingItems = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $existingItems[$row['source_item_id']] = $row['item_uuid'];
                }

                // Update items: delete all and insert new ones
                $stmt = $this->pdo->prepare('DELETE FROM operational_order_items WHERE order_uuid = ?');
                $stmt->execute([$orderUuid]);

                $newItems = [];
                foreach ($snapshot->items as $item) {
                    $uuid = $existingItems[$item->sourceItemId] ?? $item->itemUuid;
                    $newItems[] = new OperationalOrderItem(
                        $uuid,
                        $item->sourceItemId,
                        $item->lineNumber,
                        $item->name,
                        $item->productCode,
                        $item->variant,
                        $item->color,
                        $item->widthValue,
                        $item->heightValue,
                        $item->measurementUnit,
                        $item->meters,
                        $item->quantity,
                        $item->productionContext,
                    );
                }

                $this->insertItems($newItems, $orderUuid, $nowSql);
                // A printed field may have changed: an active production document becomes stale here,
                // inside the same transaction, so production is blocked before the change is visible.
                (new DocumentStaleness($this->pdo, $this->clock))->onContentChanged($orderUuid, $snapshot->sourceEventId);

            } else {
                // Insert new order. A source whose production authority is enforced hands every genuinely
                // new order to Operations at the initial canonical stage; a source stage is never used then.
                $authorityMode = $this->authorityModes?->modeFor($snapshot->sourceKey) ?? ProductionAuthorityMode::Legacy;
                $operationsManaged = $authorityMode === ProductionAuthorityMode::Enforce;
                $initialStage = $operationsManaged ? self::INITIAL_STAGE_ID : ($snapshot->productionStageId ?? self::INITIAL_STAGE_ID);
                $orderUuid = Uuid::v4();
                $stmt = $this->pdo->prepare('
                    INSERT INTO operational_orders (
                        order_uuid, global_order_id, source_key, source_order_id, order_number, order_lookup_code,
                        production_stage_id, production_authority, production_changed_at, source_commerce_status_code, source_commerce_status_label, 
                        production_notes, operational_status, freshness_status, source_schema_version, 
                        source_event_id, source_changed_at, last_source_seen_at, projected_at, 
                        accepted_at, projection_hash, version, created_at, updated_at, document_context
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $orderUuid,
                    $globalId,
                    $snapshot->sourceKey,
                    $snapshot->sourceOrderId,
                    $snapshot->orderNumber,
                    OrderLookupCode::fromOrderNumber($snapshot->orderNumber),
                    $initialStage,
                    $operationsManaged ? ProductionAuthority::OPERATIONS : ProductionAuthority::SOURCE,
                    $operationsManaged ? $nowSql : null,
                    $snapshot->sourceCommerceStatusCode,
                    $snapshot->sourceCommerceStatusLabel,
                    $snapshot->productionNotes,
                    $snapshot->operationalStatus,
                    'fresh',
                    $snapshot->sourceSchemaVersion,
                    $snapshot->sourceEventId,
                    $sourceChangedSql,
                    $nowSql,
                    $nowSql,
                    $snapshot->acceptedAt?->format('Y-m-d H:i:s.u'),
                    $payloadHash,
                    1,
                    $nowSql,
                    $nowSql,
                    $documentContext,
                ]);

                $this->insertItems($snapshot->items, $orderUuid, $nowSql);
                if ($operationsManaged) {
                    $this->recordNewOrderAuthority($orderUuid, $globalId, $snapshot->sourceKey, $nowSql);
                }
            }

            $this->ensureQrReference($orderUuid, $globalId, $snapshot->sourceKey, $nowSql, $snapshot->sourceEventId);
            $life = new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo);
            $currentStage = $currentOrder ? $stageId : $initialStage;
            if ($currentStage === $life::STAGE && (!$currentOrder || $currentOrder['production_stage_id'] !== $currentStage)) $life->fact($orderUuid, $currentOrder ? $productionVersion : 1, 'pool_entered', null, $nowSql);
            if ($snapshot->operationalStatus === 'unavailable') $life->fact($orderUuid, $currentOrder ? $productionVersion : 1, 'source_cancelled', null, $nowSql);
            if ($currentStage === $life::STAGE || $snapshot->operationalStatus === 'unavailable') (new \Arasya\Operations\Quality\LiveEvents($this->pdo))->cuttingChanged($nowSql);
            $this->insertReceipt($snapshot, $globalId, $payloadHash, 'applied', $nowSql);
            $this->touchSource($snapshot->sourceKey, $nowSql);

            (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($orderUuid);
            $this->pdo->commit();
            return 'applied';

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Source keys whose canonical orders are created only by an explicit, authenticated command, with their source type. */
    private const COMMAND_SOURCES = ['b2b' => 'internal', 'trendyol' => 'marketplace'];

    /**
     * Create the canonical order inside the caller's atomic command transaction: the internal B2B handoff or the
     * approval of a prepared Trendyol package. The order starts at the canonical initial stage, managed by
     * Operations, with its Arasya production QR.
     *
     * @param array<string, mixed> $context frozen order-level production context (B2B company); empty stores none
     */
    public function createInternal(SourceOrderSnapshot $snapshot, array $context): string
    {
        $sourceType = self::COMMAND_SOURCES[$snapshot->sourceKey] ?? null;
        if (!$this->pdo->inTransaction() || $sourceType === null || $snapshot->productionStageId !== self::INITIAL_STAGE_ID) {
            throw new \LogicException('Internal creation requires the handoff transaction and canonical initial stage.');
        }
        $s = $this->pdo->prepare("SELECT 1 FROM order_sources WHERE source_key=? AND source_type=? AND status='active' AND schema_version=1 FOR UPDATE");
        $s->execute([$snapshot->sourceKey, $sourceType]);
        if ($s->fetchColumn() === false) throw new ApiException(409, 'SOURCE_INACTIVE', 'Internal production source is not active.');
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $id = Uuid::v4();
        $globalId = $snapshot->globalId()->toString();
        $this->pdo->prepare('INSERT INTO operational_orders (
            order_uuid,global_order_id,source_key,source_order_id,order_number,order_lookup_code,production_stage_id,
            production_authority,production_changed_at,source_commerce_status_code,source_commerce_status_label,
            production_notes,production_context,operational_status,freshness_status,source_schema_version,source_event_id,
            source_changed_at,last_source_seen_at,projected_at,accepted_at,projection_hash,version,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,\'operations\',?,?,?, ?,?,\'in_progress\',\'fresh\',1,?,?,?,?,?, ?,1,?,?)')->execute([
                $id,$globalId,$snapshot->sourceKey,$snapshot->sourceOrderId,$snapshot->orderNumber,
                OrderLookupCode::fromOrderNumber($snapshot->orderNumber),self::INITIAL_STAGE_ID,$now,
                $snapshot->sourceCommerceStatusCode,$snapshot->sourceCommerceStatusLabel,$snapshot->productionNotes,
                $context === [] ? null : json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$snapshot->sourceEventId,
                $snapshot->sourceChangedAt->format('Y-m-d H:i:s.u'),$now,$now,($snapshot->acceptedAt ?? $this->clock->now())->format('Y-m-d H:i:s.u'),
                hash('sha256',json_encode([$context,$snapshot->items],JSON_THROW_ON_ERROR),true),$now,$now,
            ]);
        $this->insertItems($snapshot->items,$id,$now);
        $s=$this->pdo->prepare('UPDATE operational_order_items SET production_context=? WHERE item_uuid=? AND order_uuid=?');
        foreach($snapshot->items as $item) $s->execute([json_encode($item->productionContext,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$item->itemUuid,$id]);
        $this->ensureQrReference($id, $globalId, $snapshot->sourceKey, $now, null);
        return $id;
    }

    /**
     * Commerce status of an approved marketplace order (caller's transaction). A cancellation makes the order
     * unavailable exactly like a source cancellation (an order already in cutting keeps its status and only
     * records the report); any other status is shown as commerce data. Production stage, owner, items and the
     * printed document are never changed here.
     */
    public function recordMarketplaceStatus(string $orderUuid, string $status, bool $cancelled): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('A marketplace status update requires the caller transaction.');
        }
        $statement = $this->pdo->prepare('SELECT production_version, production_stage_id, operational_status, cutting_first_claimed_at FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            throw new \RuntimeException('Approved marketplace order is missing.');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $status = mb_substr($status, 0, 100);
        $operational = $cancelled && $order['cutting_first_claimed_at'] === null ? 'unavailable' : (string) $order['operational_status'];
        $this->pdo->prepare('UPDATE operational_orders SET source_commerce_status_code = ?, source_commerce_status_label = ?, operational_status = ?,
                source_reported_unavailable_at = IF(?, COALESCE(source_reported_unavailable_at, ?), source_reported_unavailable_at),
                last_source_seen_at = ?, version = version + 1, updated_at = ? WHERE order_uuid = ?')
            ->execute([$status, $status, $operational, $cancelled ? 1 : 0, $now, $now, $now, $orderUuid]);
        if ($cancelled) {
            $life = new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo);
            $life->fact($orderUuid, (int) $order['production_version'], 'source_cancelled', null, $now);
            (new \Arasya\Operations\Quality\LiveEvents($this->pdo))->cuttingChanged($now);
        }
        (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($orderUuid);
    }

    /** Returns the current active QR payload for an order, issuing one if none exists. */
    public function qrPayloadFor(string $globalOrderId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT q.qr_reference FROM order_qr_references q INNER JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active' ORDER BY q.created_at DESC LIMIT 1");
        $stmt->execute([$globalOrderId]);
        $reference = $stmt->fetchColumn();
        return is_string($reference) ? QrReference::fromStored($reference)->payload() : null;
    }

    /**
     * Operator tool (bin/order-qr.php --rotate): retires every active QR reference of an order as
     * superseded and issues a new one, with QR evidence. Managers use the audited Staff rotation instead.
     */
    public function rotateQrReference(string $globalOrderId): string
    {
        $nowSql = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
            $stmt->execute([$globalOrderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            }
            $retired = ProductionQrLedger::retireActive($this->pdo, (string) $order['order_uuid'], ProductionQrLedger::RETIRED_SUPERSEDED, $nowSql);
            $reference = \Arasya\Operations\Order\QrReference::generate()->value;
            $this->pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$reference, $order['order_uuid'], $nowSql]);
            ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_ROTATED, $reference, $retired[0] ?? null, null, 'operator_cli', ProductionQrLedger::modeLabel($this->authorityModes, (string) $order['source_key']), null, $nowSql);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->qrPayloadFor($globalOrderId) ?? throw new \RuntimeException('QR reference rotation failed.');
    }

    /** Records a signed heartbeat so source freshness recovers without an order event. */
    public function recordHeartbeat(string $sourceKey): void
    {
        if ($sourceKey==='b2b') throw new ApiException(403,'INTERNAL_SOURCE_ONLY','B2B production is submitted through its authenticated commercial command.');
        $nowSql = $this->clock->now()->format('Y-m-d H:i:s.u');
        $stmt = $this->pdo->prepare("UPDATE order_sources SET last_contact_at = ?, updated_at = ? WHERE source_key = ? AND status = 'active'");
        $stmt->execute([$nowSql, $nowSql, $sourceKey]);
        if ($stmt->rowCount() !== 1) {
            throw new ApiException(409, 'SOURCE_INACTIVE', 'The source is not registered or inactive.');
        }
    }

    /** Authority evidence for a new order created under an enforcing source (no actor: source policy). */
    private function recordNewOrderAuthority(string $orderUuid, string $globalId, string $sourceKey, string $nowSql): void
    {
        $this->pdo->prepare(
            "INSERT INTO production_authority_events (
                event_uuid, order_uuid, global_order_id, source_key, action, authority_mode, previous_authority, new_authority,
                previous_stage_id, new_stage_id, production_version_before, production_version_after, actor_employee_uuid,
                reason_code, request_id, idempotency_key, occurred_at
            ) VALUES (?, ?, ?, ?, 'new_order_policy', 'enforce', NULL, 'operations', NULL, ?, NULL, 1, NULL, 'source_authority_enforced', NULL, NULL, ?)",
        )->execute([Uuid::v4(), $orderUuid, $globalId, $sourceKey, self::INITIAL_STAGE_ID, $nowSql]);
    }

    /** JSON columns may return object keys in another order; compare by content. */
    private static function sortedKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::sortedKeys(...), $value);
    }

    private function activeStageOrdinal(string $stageId): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT ps.ordinal
            FROM production_stages ps
            JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id
            WHERE ps.stage_id = ? AND pw.workflow_key = 'curtain-production' AND pw.status = 'active' AND ps.status = 'active'
        ");
        $stmt->execute([$stageId]);
        $ordinal = $stmt->fetchColumn();
        return $ordinal === false ? null : (int) $ordinal;
    }

    /**
     * Issues the order's canonical QR when it has no active one (a new order, or an order whose references
     * were all retired). Never rotates: an existing active QR is always kept. Caller holds the order lock.
     */
    private function ensureQrReference(string $orderUuid, string $globalId, string $sourceKey, string $nowSql, ?string $requestId): void
    {
        $order = ['order_uuid' => $orderUuid, 'global_order_id' => $globalId, 'source_key' => $sourceKey];
        $known = $this->pdo->prepare("SELECT MAX(status = 'active') FROM order_qr_references WHERE order_uuid = ?");
        $known->execute([$orderUuid]);
        $state = $known->fetchColumn();
        if ($state !== null && (int) $state === 1) {
            return;
        }
        $reason = $state === null ? 'intake' : 'reissued_without_active';
        ProductionQrLedger::ensureActive($this->pdo, $order, ProductionQrLedger::modeLabel($this->authorityModes, $sourceKey), $reason, null, $requestId, $nowSql);
    }

    private function touchSource(string $sourceKey, string $nowSql): void
    {
        $stmt = $this->pdo->prepare('UPDATE order_sources SET last_contact_at = ?, last_event_at = ? WHERE source_key = ?');
        $stmt->execute([$nowSql, $nowSql, $sourceKey]);
    }

    private function insertReceipt(SourceOrderSnapshot $snapshot, string $globalId, string $payloadHash, string $outcome, string $nowSql): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO order_projection_receipts (
                source_key, source_event_id, global_order_id, payload_hash, source_changed_at, outcome, received_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $snapshot->sourceKey,
            $snapshot->sourceEventId,
            $globalId,
            $payloadHash,
            $snapshot->sourceChangedAt->format('Y-m-d H:i:s.u'),
            $outcome,
            $nowSql,
        ]);
    }

    /** @param list<OperationalOrderItem> $items */
    private function insertItems(array $items, string $orderUuid, string $nowSql): void
    {
        if (empty($items)) return;

        $stmt = $this->pdo->prepare('
            INSERT INTO operational_order_items (
                item_uuid, order_uuid, source_item_id, line_number, name, product_code, 
                variant, color, width_value, height_value, measurement_unit, meters, quantity, 
                created_at, updated_at, production_context
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($items as $item) {
            $stmt->execute([
                $item->itemUuid,
                $orderUuid,
                $item->sourceItemId,
                $item->lineNumber,
                $item->name,
                $item->productCode,
                $item->variant,
                $item->color,
                $item->widthValue,
                $item->heightValue,
                $item->measurementUnit,
                $item->meters,
                $item->quantity,
                $nowSql,
                $nowSql,
                $item->productionContext === null ? null : json_encode($item->productionContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}
