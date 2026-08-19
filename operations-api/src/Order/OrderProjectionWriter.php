<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use PDO;

final readonly class OrderProjectionWriter
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    public function apply(SourceOrderSnapshot $snapshot): string
    {
        $globalId = $snapshot->globalId()->toString();
        $hashData = [
            'source' => $snapshot->sourceKey,
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
            ], $snapshot->items),
        ];
        // Ensure deterministic JSON
        $payloadHash = hash('sha256', json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);

        try {
            $this->pdo->beginTransaction();

            // Validate source is active
            $stmt = $this->pdo->prepare('SELECT schema_version, status FROM order_sources WHERE source_key = ?');
            $stmt->execute([$snapshot->sourceKey]);
            $sourceRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sourceRow) {
                throw new ApiException(500, 'SOURCE_UNKNOWN', 'The source key is not registered.');
            }
            if ($sourceRow['status'] !== 'active') {
                throw new ApiException(500, 'SOURCE_INACTIVE', 'The source is currently inactive.');
            }
            if ((int)$sourceRow['schema_version'] !== $snapshot->sourceSchemaVersion) {
                throw new ApiException(500, 'SOURCE_SCHEMA_UNSUPPORTED', 'Snapshot schema version does not match active registry version.');
            }

            // Validate stage is canonical
            $stmt = $this->pdo->prepare('SELECT 1 FROM production_stages WHERE stage_id = ?');
            $stmt->execute([$snapshot->productionStageId]);
            if (!$stmt->fetchColumn()) {
                throw new ApiException(500, 'SOURCE_STAGE_UNKNOWN', 'The production stage is not canonical.');
            }

            // Check if receipt already exists
            $stmt = $this->pdo->prepare('SELECT payload_hash FROM order_projection_receipts WHERE source_key = ? AND source_event_id = ?');
            $stmt->execute([$snapshot->sourceKey, $snapshot->sourceEventId]);
            $existingReceiptHash = $stmt->fetchColumn();

            if ($existingReceiptHash !== false) {
                if ($existingReceiptHash === $payloadHash) {
                    $this->pdo->rollBack();
                    return 'duplicate';
                }
                throw new ApiException(500, 'SOURCE_EVENT_CONFLICT', 'Event ID conflict with different payload hash.');
            }

            // Lock order row
            $stmt = $this->pdo->prepare('SELECT order_uuid, source_changed_at, projection_hash, version, freshness_status FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
            $stmt->execute([$globalId]);
            $currentOrder = $stmt->fetch(PDO::FETCH_ASSOC);

            $nowSql = $this->clock->now()->format('Y-m-d H:i:s.u');
            $sourceChangedSql = $snapshot->sourceChangedAt->format('Y-m-d H:i:s.u');

            if ($currentOrder) {
                $orderUuid = $currentOrder['order_uuid'];
                $currentChangedAt = $currentOrder['source_changed_at'];

                if ($sourceChangedSql < $currentChangedAt) {
                    $this->insertReceipt($snapshot, $globalId, $payloadHash, 'out_of_order', $nowSql);
                    $this->pdo->commit();
                    return 'out_of_order';
                }
                
                if ($sourceChangedSql === $currentChangedAt && $currentOrder['projection_hash'] !== $payloadHash) {
                    throw new ApiException(500, 'SOURCE_REVISION_CONFLICT', 'Conflicting changes at the same timestamp.');
                }
                
                if ($sourceChangedSql === $currentChangedAt && $currentOrder['projection_hash'] === $payloadHash) {
                    $this->insertReceipt($snapshot, $globalId, $payloadHash, 'duplicate', $nowSql);
                    $this->pdo->commit();
                    return 'duplicate';
                }

                $version = (int)$currentOrder['version'];
                if ($currentOrder['projection_hash'] !== $payloadHash) {
                    $version++;
                }

                // Update order
                $stmt = $this->pdo->prepare('
                    UPDATE operational_orders SET 
                        order_number = ?,
                        production_stage_id = ?,
                        source_commerce_status_code = ?,
                        source_commerce_status_label = ?,
                        production_notes = ?,
                        operational_status = ?,
                        freshness_status = ?,
                        source_schema_version = ?,
                        source_event_id = ?,
                        source_changed_at = ?,
                        last_source_seen_at = ?,
                        projected_at = ?,
                        accepted_at = ?,
                        projection_hash = ?,
                        version = ?,
                        updated_at = ?
                    WHERE order_uuid = ?
                ');
                $stmt->execute([
                    $snapshot->orderNumber,
                    $snapshot->productionStageId,
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
                    $version,
                    $nowSql,
                    $orderUuid,
                ]);

                if ($currentOrder['projection_hash'] !== $payloadHash) {
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
                            $item->quantity
                        );
                    }

                    $this->insertItems($newItems, $orderUuid, $nowSql);
                }

            } else {
                // Insert new order
                $orderUuid = Uuid::v4();
                $stmt = $this->pdo->prepare('
                    INSERT INTO operational_orders (
                        order_uuid, global_order_id, source_key, source_order_id, order_number, 
                        production_stage_id, source_commerce_status_code, source_commerce_status_label, 
                        production_notes, operational_status, freshness_status, source_schema_version, 
                        source_event_id, source_changed_at, last_source_seen_at, projected_at, 
                        accepted_at, projection_hash, version, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $orderUuid,
                    $globalId,
                    $snapshot->sourceKey,
                    $snapshot->sourceOrderId,
                    $snapshot->orderNumber,
                    $snapshot->productionStageId,
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
                ]);

                $this->insertItems($snapshot->items, $orderUuid, $nowSql);
            }

            $this->insertReceipt($snapshot, $globalId, $payloadHash, 'applied', $nowSql);

            $this->pdo->commit();
            return 'applied';

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            ]);
        }
    }
}
