<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Cancellation\ProductionStop;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Production\ProductionStage;
use Arasya\Operations\Production\ProductionWorkflow;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Quality\ExceptionQueries;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Server-authoritative production mutations.
 *
 * Every mutation runs in one transaction that locks the order row, replays a
 * previously committed result for the same employee idempotency key, checks
 * the expected production version, lets the server choose the next canonical
 * stage, and writes the relation, the immutable activity event and the
 * idempotency result before commit. Nothing is reported as successful before
 * the commit succeeds.
 */
final readonly class OrderOperationsService
{
    public const OPERATION_CLAIM = 'claim';
    public const OPERATION_TRANSITION = 'transition';
    /** Stored as a claim in order_operation_idempotency (its own request hash keeps the two apart). */
    public const OPERATION_CUTTING_INTAKE = 'cutting_intake';
    public const INTAKE_STARTED = 'started';
    public const INTAKE_CLAIMED = 'claimed';
    public const INTAKE_ALREADY_MINE = 'already_mine';

    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1062, 1205, 1213];

    public function __construct(
        private PDO $pdo,
        private ProductionWorkflowService $workflows,
        private OperationalOrderRepository $orders,
        private OrderAccessPolicy $policy,
        private OrderSerializer $serializer,
        private Clock $clock,
        private AuditLogger $audit,
        private ?ExceptionQueries $quality = null,
        private ?\Arasya\Operations\Document\DocumentQueries $documents = null,
        private ?\Arasya\Operations\Production\ReworkTrackService $tracks = null,
    ) {
    }

    /** @return array<string, mixed> serialized order after the committed (or replayed) claim */
    public function claim(EmployeeIdentity $employee, GlobalOrderId $orderId, int $expectedVersion, string $idempotencyKey, OperationContext $context, array $claimIntent = []): array
    {
        return $this->mutate(self::OPERATION_CLAIM, $employee, $orderId, $expectedVersion, $idempotencyKey, $context, $claimIntent);
    }

    /**
     * @param array{pieceOutcome?: string, originalDisposition?: string} $completion API 2.32.0: when the order's pieces are
     *        re-cut in a rework cycle, the cutter declares repair or replacement (PieceReplacement)
     * @return array<string, mixed> serialized order after the committed (or replayed) stage completion
     */
    public function completeCurrentStage(EmployeeIdentity $employee, GlobalOrderId $orderId, int $expectedVersion, string $idempotencyKey, OperationContext $context, array $completion = []): array
    {
        return $this->mutate(self::OPERATION_TRANSITION, $employee, $orderId, $expectedVersion, $idempotencyKey, $context, $completion);
    }

    /**
     * Cutting intake from a scanned Arasya QR, in one transaction: a stage-1 order moves to cutting and is claimed by
     * the scanning cutter (two canonical events, `stage_completed` waiting -> material-preparation and `claimed`,
     * each with its own production version); an unowned cutting order is claimed as by the ordinary QR claim; an
     * order the cutter already owns changes nothing. The server reads the version under the row lock, so a scan
     * needs no expected version; the QR, the workload confirmation and the authorization are checked again under
     * the locks. Never completes cutting.
     *
     * @param array{confirmedMultiple?: bool, ownedCount?: int} $intent
     * @return array{outcome: string, order: array<string, mixed>}
     */
    public function cuttingIntake(EmployeeIdentity $employee, GlobalOrderId $orderId, QrReference $qr, array $intent, string $idempotencyKey, OperationContext $context): array
    {
        if (!self::isValidIdempotencyKey($idempotencyKey)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        try {
            $workflow = $this->workflows->current();
        } catch (RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
        }
        $requestHash = hash('sha256', implode('|', [self::OPERATION_CUTTING_INTAKE, $orderId->toString(), json_encode([$qr->value, $intent['confirmedMultiple'] ?? false, $intent['ownedCount'] ?? null], JSON_THROW_ON_ERROR)]), true);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attemptIntake($employee, $orderId, $qr, $intent, $idempotencyKey, $requestHash, $workflow, $context);
            } catch (ApiException $error) {
                $this->recordDenied(self::OPERATION_CUTTING_INTAKE, $employee, $orderId, $error, $context);
                throw $error;
            } catch (PDOException $error) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if ($attempt < self::MAX_ATTEMPTS && in_array($driverCode, self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    public static function isValidIdempotencyKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D', $key) === 1;
    }

    /** @return array<string, mixed> */
    private function mutate(string $operation, EmployeeIdentity $employee, GlobalOrderId $orderId, int $expectedVersion, string $idempotencyKey, OperationContext $context, array $claimIntent = []): array
    {
        if (!self::isValidIdempotencyKey($idempotencyKey)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if ($expectedVersion < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        try {
            $workflow = $this->workflows->current();
        } catch (RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
        }
        $baseIntent = implode('|', [$operation, $orderId->toString(), (string) $expectedVersion]);
        // Preserve hashes/replay for pre-V1 commands. QR/multiple intent gets its own canonical suffix.
        $extra = array_intersect_key($claimIntent, array_flip(['qrToken','confirmedMultiple','ownedCount']));
        $outcome = array_intersect_key($claimIntent, array_flip(['pieceOutcome', 'originalDisposition', 'defectResolved', 'pieceOutcomes']));
        $requestHash = hash('sha256', $baseIntent . ($extra === [] ? '' : '|' . json_encode([$extra['qrToken'] ?? null, $extra['confirmedMultiple'] ?? false, $extra['ownedCount'] ?? null], JSON_THROW_ON_ERROR))
            . ($outcome === [] ? '' : '|outcome|' . json_encode([$outcome['pieceOutcome'] ?? null, $outcome['originalDisposition'] ?? null, $outcome['defectResolved'] ?? null, $outcome['pieceOutcomes'] ?? null], JSON_THROW_ON_ERROR)), true);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($operation, $employee, $orderId, $expectedVersion, $idempotencyKey, $requestHash, $workflow, $context, $claimIntent);
            } catch (ApiException $error) {
                $this->recordDenied($operation, $employee, $orderId, $error, $context);
                throw $error;
            } catch (PDOException $error) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if ($attempt < self::MAX_ATTEMPTS && in_array($driverCode, self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    /** @return array<string, mixed> */
    private function attempt(string $operation, EmployeeIdentity $employee, GlobalOrderId $orderId, int $expectedVersion, string $idempotencyKey, string $requestHash, ProductionWorkflow $workflow, OperationContext $context, array $claimIntent): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT order_uuid, source_key FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
            $lock->execute([$orderId->toString()]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            $orderUuid = is_array($locked) ? $locked['order_uuid'] : false;
            if (!is_string($orderUuid)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            }

            // Before any consistent read: serialize this employee's claims across different orders.
            $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$employee->employeeUuid]);

            // The authorization is re-read under the employee lock (IAM changes lock the same row), so a stage or
            // scope change committed while this request waited applies here, never the session-time snapshot.
            $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid($employee->employeeUuid) ?? throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
            if (!$employee->isOperationallyActive() || !$employee->hasApplication('staff') || $employee->mustChangePassword) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');

            $replay = $this->replay($employee->employeeUuid, $idempotencyKey, $operation, $orderUuid, $requestHash);
            if ($replay !== null) {
                // A response committed before a production stop is replayed as committed, but never presents the
                // manufacturing action as still allowed (ProductionStop::overlayOrder).
                $stop = $this->currentStop($employee, $orderId, $orderUuid);
                $this->pdo->rollBack();
                // A stored response carries order details: it is replayed only while the employee still reaches the
                // order's source.
                if (!$employee->reachesSource((string) $locked['source_key'])) {
                    throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
                }
                return ProductionStop::overlayOrder($replay, $stop);
            }

            $order = $this->orders->findByGlobalId($employee->employeeUuid, $orderId->toString());
            if ($order === null || !$this->policy->canView($employee, $order)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            }
            // The shared cancellation guard, on the locked row: a stop committed while this request waited applies here.
            ProductionStop::assertOpen($this->pdo, $orderUuid);
            if ($order->productionVersion !== $expectedVersion) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }

            $decision = $this->policy->evaluate($employee, $order, $workflow);
            if ($decision['action'] === null) throw $this->blocked((string) $decision['blockedReason'], $operation, $order);
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $cutting = new CuttingLifecycle($this->pdo);
            if ($order->productionStageId === CuttingLifecycle::STAGE) {
                $cutting->requireNoTransfer($orderUuid);
                if ($operation === self::OPERATION_CLAIM && $order->productionOwnerEmployeeUuid === null) {
                    $availability = $this->pdo->prepare('SELECT o.source_reported_unavailable_at, s.status FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE o.order_uuid = ?');
                    $availability->execute([$orderUuid]); $source = $availability->fetch(PDO::FETCH_ASSOC);
                    if ($source['source_reported_unavailable_at'] !== null || $source['status'] !== 'active') throw $this->blocked('order_unavailable', $operation);
                    if (!CuttingLifecycle::eligible($employee)) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu poți prelua comenzi de tăiere.');
                    $cutting->qr($orderUuid, $claimIntent['qrToken'] ?? null);
                    $cutting->confirmMultiple($employee, $claimIntent, $context->requestId, $now);
                }
            }
            if ($operation === self::OPERATION_CLAIM) {
                if ($decision['action'] === OrderAccessPolicy::ACTION_COMPLETE_STAGE || $decision['action'] === OrderAccessPolicy::ACTION_COMPLETE_PRODUCTION) {
                    // Already owned by this employee: a new claim request changes nothing.
                    $response = $this->serialize($employee, $order->globalId->toString(), $workflow);
                    $this->storeResult($employee->employeeUuid, $idempotencyKey, $operation, $orderUuid, $requestHash, $response, $now);
                    $this->pdo->commit();
                    return $response;
                }
                if ($decision['action'] !== OrderAccessPolicy::ACTION_CLAIM) {
                    throw $this->blocked((string) $decision['blockedReason'], $operation, $order);
                }
                $this->applyClaim($employee, $order, $workflow, $expectedVersion, $idempotencyKey, $context, $now);
            } else {
                if ($decision['action'] === OrderAccessPolicy::ACTION_CLAIM) {
                    throw new ApiException(409, 'INVALID_STAGE_TRANSITION', 'The order must be claimed before its stage can be completed.');
                }
                if ($decision['action'] === null) {
                    throw $this->blocked((string) $decision['blockedReason'], $operation, $order);
                }
                $this->applyTransition($employee, $order, $workflow, $expectedVersion, $idempotencyKey, $context, $now, $claimIntent);
            }

            $response = $this->serialize($employee, $order->globalId->toString(), $workflow);
            $this->storeResult($employee->employeeUuid, $idempotencyKey, $operation, $orderUuid, $requestHash, $response, $now);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{outcome: string, order: array<string, mixed>} */
    private function attemptIntake(EmployeeIdentity $employee, GlobalOrderId $orderId, QrReference $qr, array $intent, string $idempotencyKey, string $requestHash, ProductionWorkflow $workflow, OperationContext $context): array
    {
        $this->pdo->beginTransaction();
        try {
            // Same lock order as every order mutation: the order row, then the employee row.
            $lock = $this->pdo->prepare('SELECT order_uuid, source_key FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
            $lock->execute([$orderId->toString()]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            }
            $orderUuid = (string) $locked['order_uuid'];
            $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$employee->employeeUuid]);
            $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid($employee->employeeUuid) ?? throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
            if (!$employee->isOperationallyActive() || !$employee->hasApplication('staff') || $employee->mustChangePassword) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
            if (!$this->policy->mayUseCuttingIntake($employee)) throw self::intakeNotAllowed();
            // A source the employee does not cut for is reported exactly like an order that does not exist.
            if (!$employee->worksAt(CuttingLifecycle::STAGE, (string) $locked['source_key'])) throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');

            $replay = $this->replay($employee->employeeUuid, $idempotencyKey, self::OPERATION_CLAIM, $orderUuid, $requestHash);
            if ($replay !== null) {
                $stop = $this->currentStop($employee, $orderId, $orderUuid);
                $this->pdo->rollBack();
                if ($stop !== null && isset($replay['order']) && is_array($replay['order'])) {
                    $replay['order'] = ProductionStop::overlayOrder($replay['order'], $stop);
                }
                return $replay;
            }

            $order = $this->orders->findByGlobalId($employee->employeeUuid, $orderId->toString()) ?? throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            $decision = $this->policy->evaluateCuttingIntake($employee, $order, $workflow);
            if ($decision['action'] !== null) {
                ProductionStop::assertOpen($this->pdo, $orderUuid);
            }
            if ($decision['action'] === null) throw $this->intakeBlocked((string) $decision['blockedReason'], $order);
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            if ($decision['action'] === OrderAccessPolicy::ACTION_ALREADY_MINE) {
                // The cutter's own job: nothing is claimed, moved or recorded again.
                (new CuttingLifecycle($this->pdo))->qr($orderUuid, $qr->payload());
                $response = ['outcome' => self::INTAKE_ALREADY_MINE, 'order' => $this->serialize($employee, $order->globalId->toString(), $workflow)];
                $this->storeResult($employee->employeeUuid, $idempotencyKey, self::OPERATION_CLAIM, $orderUuid, $requestHash, $response, $now);
                $this->pdo->commit();
                return $response;
            }

            $cutting = new CuttingLifecycle($this->pdo);
            $cutting->requireNoTransfer($orderUuid);
            $availability = $this->pdo->prepare('SELECT o.source_reported_unavailable_at, s.status FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE o.order_uuid = ?');
            $availability->execute([$orderUuid]);
            $source = $availability->fetch(PDO::FETCH_ASSOC);
            if (!is_array($source) || $source['source_reported_unavailable_at'] !== null || $source['status'] !== 'active') throw $this->blocked('order_unavailable', self::OPERATION_CLAIM);
            // Under the lock: the scanned QR is still the active QR of exactly this order and its document is usable.
            $cutting->qr($orderUuid, $qr->payload());
            $cutting->confirmMultiple($employee, $intent, $context->requestId, $now);

            if ($decision['action'] === OrderAccessPolicy::ACTION_CUTTING_INTAKE) {
                $this->applyIntakeHandoff($employee, $order, $workflow, $idempotencyKey, $context, $now);
                $outcome = self::INTAKE_STARTED;
            } else {
                $this->applyClaim($employee, $order, $workflow, $order->productionVersion, $idempotencyKey, $context, $now);
                $outcome = self::INTAKE_CLAIMED;
            }
            $response = ['outcome' => $outcome, 'order' => $this->serialize($employee, $order->globalId->toString(), $workflow)];
            $this->storeResult($employee->employeeUuid, $idempotencyKey, self::OPERATION_CLAIM, $orderUuid, $requestHash, $response, $now);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Stage 1 -> cutting for the scanning cutter, inside the caller's transaction. First the stage completion of
     * stage 1 (version v -> v+1, the order enters the cutting pool), then the ordinary cutting claim (v+1 -> v+2),
     * so reports, the cutting facts and the ownership intervals read it exactly like the two existing steps. The
     * intermediate state is never committed. The IAM audit marks the pair as one QR intake.
     */
    private function applyIntakeHandoff(EmployeeIdentity $employee, OperationalOrder $order, ProductionWorkflow $workflow, string $idempotencyKey, OperationContext $context, string $now): void
    {
        $from = $this->stage($workflow, OrderProjectionWriter::INITIAL_STAGE_ID);
        $to = $this->stage($workflow, CuttingLifecycle::STAGE);
        $version = $order->productionVersion;
        $update = $this->pdo->prepare(
            "UPDATE operational_orders
             SET production_stage_id = ?, production_changed_at = ?, production_version = production_version + 1, version = version + 1, updated_at = ?
             WHERE order_uuid = ? AND production_version = ? AND production_stage_id = ? AND production_owner_employee_uuid IS NULL AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN ('none', 'active') AND production_stopped_at IS NULL",
        );
        $update->execute([$to->id, $now, $now, $order->orderUuid, $version, $from->id]);
        if ($update->rowCount() !== 1) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
        }
        // Each canonical event carries its own idempotency key (unique per employee); the handoff event's key is
        // derived from the scan's key, and both events share the request id.
        $this->recordActivity($employee, $order, $workflow, 'stage_completed', $from, $to, $version, substr($idempotencyKey, 0, 90) . '.handoff', $context, $now);
        // Stage 1 applies to every product type: the per-piece ledger records the completion for materialized pieces.
        $routing = new \Arasya\Operations\Production\PieceRouting($this->pdo);
        $routing->apply($workflow, ['next' => $to, 'skipped' => [], 'types' => [], 'units' => (new \Arasya\Operations\Production\WorkUnits($this->pdo))->carrierUnits($order->orderUuid, null), 'splits' => [], 'unverified' => null, 'routing' => false, 'typeItems' => []],
            $order->orderUuid, null, $from->id, $employee->employeeUuid, $version, substr($idempotencyKey, 0, 90) . '.handoff', $context->requestId, $now);
        $meters = $this->pdo->prepare('SELECT SUM(meters) FROM operational_order_items WHERE order_uuid = ?');
        $meters->execute([$order->orderUuid]);
        $total = $meters->fetchColumn();
        (new CuttingLifecycle($this->pdo))->changed($order->orderUuid, $version + 1, $from->id, $to->id, $employee->employeeUuid, $now, $total === null || $total === false ? null : (string) $total);
        $moved = $this->orders->findByGlobalId($employee->employeeUuid, $order->globalId->toString()) ?? throw new RuntimeException('Moved order could not be reloaded.');
        $this->applyClaim($employee, $moved, $workflow, $version + 1, $idempotencyKey, $context, $now);
        (new IamAuditLogger($this->pdo))->record($employee, 'cutting.intake_from_qr', 'order', $order->globalId->toString(), '#' . $order->orderNumber, [
            'orderId' => $order->globalId->toString(),
            'source' => $order->globalId->sourceKey,
            'fromStage' => $from->id,
            'toStage' => $to->id,
            'owner' => $employee->employeeUuid,
            'productionVersionBefore' => $version,
            'productionVersionAfter' => $version + 2,
        ], $context->requestId, $now);
    }

    private function applyClaim(EmployeeIdentity $employee, OperationalOrder $order, ProductionWorkflow $workflow, int $expectedVersion, string $idempotencyKey, OperationContext $context, string $now): void
    {
        $stage = $this->stage($workflow, $order->productionStageId);
        $update = $this->pdo->prepare(
            "UPDATE operational_orders
             SET production_owner_employee_uuid = ?, production_claimed_at = ?, production_authority = 'operations',
                 production_version = production_version + 1, version = version + 1, updated_at = ?
             WHERE order_uuid = ? AND production_version = ? AND production_owner_employee_uuid IS NULL AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN ('none', 'active') AND production_stopped_at IS NULL",
        );
        $update->execute([$employee->employeeUuid, $now, $now, $order->orderUuid, $expectedVersion]);
        if ($update->rowCount() !== 1) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
        }
        $previousHandover = $this->pdo->prepare("SELECT 1 FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' LIMIT 1");
        $previousHandover->execute([$order->orderUuid]);
        $relation = $previousHandover->fetchColumn() === false ? 'claimed' : 'handover_in';
        $this->upsertRelation($employee->employeeUuid, $order->orderUuid, $relation, $now);
        $this->recordActivity($employee, $order, $workflow, 'claimed', $stage, null, $expectedVersion, $idempotencyKey, $context, $now);
        if ($this->policy->isSourceManagedUnderObservation($order)) {
            // Observe mode: this legacy implicit authority change would be refused under enforcement.
            $this->pdo->prepare(
                "INSERT INTO production_authority_events (
                    event_uuid, order_uuid, global_order_id, source_key, action, authority_mode, previous_authority, new_authority,
                    previous_stage_id, new_stage_id, production_version_before, production_version_after, actor_employee_uuid,
                    reason_code, request_id, idempotency_key, occurred_at
                ) VALUES (?, ?, ?, ?, 'claim_observed', 'observe', 'source', 'operations', ?, ?, ?, ?, ?, 'staff_claim_of_source_order', ?, ?, ?)",
            )->execute([Uuid::v4(), $order->orderUuid, $order->globalId->toString(), $order->globalId->sourceKey, $stage->id, $stage->id, $expectedVersion, $expectedVersion + 1, $employee->employeeUuid, mb_substr($context->requestId, 0, 100), $idempotencyKey, $now]);
        }
        if ($stage->id === CuttingLifecycle::STAGE) (new CuttingLifecycle($this->pdo))->claimed($order->orderUuid, $expectedVersion + 1, $employee->employeeUuid, $now);
        (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($order->orderUuid);
    }

    /** @param array<string, mixed> $completion */
    private function applyTransition(EmployeeIdentity $employee, OperationalOrder $order, ProductionWorkflow $workflow, int $expectedVersion, string $idempotencyKey, OperationContext $context, string $now, array $completion = []): void
    {
        $current = $this->stage($workflow, $order->productionStageId);
        $routing = new \Arasya\Operations\Production\PieceRouting($this->pdo);
        // Re-cutting the order's pieces in a rework cycle: the cutter states whether new pieces were cut.
        $replacement = new \Arasya\Operations\Production\PieceReplacement($this->pdo);
        $replacement->applyOutcomes($order->orderUuid, null, $order->openExceptionUuid, $current->id, $completion, $employee->employeeUuid, $context->requestId, $idempotencyKey, $now);
        (new \Arasya\Operations\Production\PieceVerification($this->pdo))->complete($workflow, $order->orderUuid, null, $current->id, ($completion['defectResolved'] ?? null) === true, $employee->employeeUuid, $expectedVersion, $context->requestId, $now);
        // The central route policy chooses the next stage (PERDEA skips 6-7; a mixed order splits by route).
        (new \Arasya\Operations\Production\WorkUnits($this->pdo))->ensure($order->orderUuid, $now, 'on_demand');
        $route = $routing->plan($workflow, $order->orderUuid, null, $current->id, $now);
        $next = $route['next'];
        if ($next === null) {
            $update = $this->pdo->prepare(
                'UPDATE operational_orders
                 SET production_completed_at = ?, production_owner_employee_uuid = NULL, production_claimed_at = NULL, production_changed_at = ?,
                     production_version = production_version + 1, version = version + 1, updated_at = ?
                 WHERE order_uuid = ? AND production_version = ? AND production_owner_employee_uuid = ? AND production_stage_id = ? AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN (\'none\', \'active\') AND production_stopped_at IS NULL',
            );
            $update->execute([$now, $now, $now, $order->orderUuid, $expectedVersion, $employee->employeeUuid, $current->id]);
            $relation = 'completed';
            $action = 'production_completed';
        } else {
            $update = $this->pdo->prepare(
                'UPDATE operational_orders
                 SET production_stage_id = ?, production_owner_employee_uuid = NULL, production_claimed_at = NULL, production_changed_at = ?,
                     production_version = production_version + 1, version = version + 1, updated_at = ?
                 WHERE order_uuid = ? AND production_version = ? AND production_owner_employee_uuid = ? AND production_stage_id = ? AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN (\'none\', \'active\') AND production_stopped_at IS NULL',
            );
            $update->execute([$next->id, $now, $now, $order->orderUuid, $expectedVersion, $employee->employeeUuid, $current->id]);
            $relation = 'handover_out';
            $action = 'stage_completed';
        }
        if ($update->rowCount() !== 1) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
        }
        $this->upsertRelation($employee->employeeUuid, $order->orderUuid, $relation, $now);
        $this->recordActivity($employee, $order, $workflow, $action, $current, $next, $expectedVersion, $idempotencyKey, $context, $now);
        // Per-piece ledger, stage_not_applicable decisions and route tracks of the pieces that follow another route.
        $routing->apply($workflow, $route, $order->orderUuid, null, $current->id, $employee->employeeUuid, $expectedVersion, $idempotencyKey, $context->requestId, $now);
        $tracks = new \Arasya\Operations\Production\ProductionTracks($this->pdo);
        if ($next?->id === \Arasya\Operations\Production\ProductionTracks::GATE_STAGE) {
            // Reworked pieces already waiting at packing travel with the order again from here.
            $tracks->mergeAtGate($order->orderUuid, $employee->employeeUuid, $idempotencyKey, $context->requestId, $now);
        }
        // Pieces of another route that wait, unowned, at the stage the order reached travel with it again.
        $tracks->mergeAligned($order->orderUuid, $employee->employeeUuid, $idempotencyKey, $context->requestId, $now);
        $meters = $this->pdo->prepare('SELECT SUM(meters) FROM operational_order_items WHERE order_uuid = ?');
        $meters->execute([$order->orderUuid]);
        $total = $meters->fetchColumn();
        (new CuttingLifecycle($this->pdo))->changed($order->orderUuid, $expectedVersion + 1, $current->id, $next?->id, $employee->employeeUuid, $now, $total === null || $total === false ? null : (string) $total);
        (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($order->orderUuid);
    }

    private function stage(ProductionWorkflow $workflow, string $stageId): ProductionStage
    {
        foreach ($workflow->stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }
        throw new ApiException(409, 'SOURCE_STAGE_UNKNOWN', 'The order stage is not part of the active production workflow.');
    }

    private function upsertRelation(string $employeeUuid, string $orderUuid, string $type, string $now): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at)
             VALUES (?, ?, ?, 'active', ?, ?, ?)
             ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type), status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)",
        );
        $statement->execute([$employeeUuid, $orderUuid, $type, $now, $now, $now]);
    }

    private function recordActivity(EmployeeIdentity $employee, OperationalOrder $order, ProductionWorkflow $workflow, string $action, ProductionStage $from, ?ProductionStage $to, int $versionBefore, string $idempotencyKey, OperationContext $context, string $now): void
    {
        $sum = $this->pdo->prepare('SELECT SUM(meters) FROM operational_order_items WHERE order_uuid = ?');
        $sum->execute([$order->orderUuid]);
        $meters = $sum->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO order_activity_events (
                event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action,
                workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot,
                production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            Uuid::v4(),
            $employee->employeeUuid,
            $order->orderUuid,
            $order->globalId->toString(),
            $order->globalId->sourceKey,
            $order->orderNumber,
            $action,
            $workflow->id,
            $workflow->version,
            $from->id,
            $from->label,
            $to?->id,
            $to?->label,
            $versionBefore,
            $versionBefore + 1,
            $meters === null || $meters === false ? null : (string) $meters,
            $context->requestId,
            $idempotencyKey,
            $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function replay(string $employeeUuid, string $idempotencyKey, string $operation, string $orderUuid, string $requestHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, order_uuid, request_hash, response_json FROM order_operation_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$employeeUuid, $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['operation'] !== $operation || $row['order_uuid'] !== $orderUuid || !hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
        }
        try {
            $decoded = json_decode((string) $row['response_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $response */
    private function storeResult(string $employeeUuid, string $idempotencyKey, string $operation, string $orderUuid, string $requestHash, array $response, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO order_operation_idempotency (employee_uuid, idempotency_key, operation, order_uuid, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->bindValue(1, $employeeUuid);
        $statement->bindValue(2, $idempotencyKey);
        $statement->bindValue(3, $operation);
        $statement->bindValue(4, $orderUuid);
        $statement->bindValue(5, $requestHash, PDO::PARAM_LOB);
        $statement->bindValue(6, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $statement->bindValue(7, $now);
        $statement->execute();
    }

    /** @return array<string, mixed> */
    private function serialize(EmployeeIdentity $employee, string $globalOrderId, ProductionWorkflow $workflow): array
    {
        $order = $this->orders->findByGlobalId($employee->employeeUuid, $globalOrderId)
            ?? throw new RuntimeException('Mutated order could not be reloaded.');
        $pieces = $this->tracks === null ? null : ($this->tracks->piecesFor($this->tracks->orderRows([$order->orderUuid]), $employee, $workflow)[$order->orderUuid] ?? null);
        if ($pieces !== null && $this->quality !== null) {
            foreach ($pieces['tracks'] as &$track) {
                if ($track['openException'] !== null) {
                    $track['openException'] = $this->quality->viewerSummary($track['openException']['id'], $employee->employeeUuid) ?? $track['openException'];
                }
            }
            unset($track);
        }
        return $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $workflow), $this->quality?->orderQuality($order->orderUuid, $order->openExceptionUuid, $employee->employeeUuid), $this->documents?->summary($order->orderUuid), $pieces);
    }

    /** The current Staff stop view of the locked order, or null when production is not stopped. @return array<string, mixed>|null */
    private function currentStop(EmployeeIdentity $employee, GlobalOrderId $orderId, string $orderUuid): ?array
    {
        if (!ProductionStop::isStopped($this->pdo, $orderUuid)) {
            return null;
        }
        return $this->orders->findByGlobalId($employee->employeeUuid, $orderId->toString())?->productionStop
            ?? ['state' => 'stopped', 'canContinue' => false, 'managementAttention' => true];
    }

    private function blocked(string $reason, string $operation, ?OperationalOrder $order = null): ApiException
    {
        return match ($reason) {
            \Arasya\Operations\Production\ConfirmationGate::BLOCKED_REASON => \Arasya\Operations\Production\ConfirmationGate::closed($order?->workflowCohort),
            \Arasya\Operations\Production\ConfirmationGate::DOCUMENT_BLOCKED_REASON => \Arasya\Operations\Production\ConfirmationGate::documentRequired(),
            \Arasya\Operations\Production\PaymentGate::BLOCKED_REASON => \Arasya\Operations\Production\PaymentGate::closed(
                \Arasya\Operations\Production\PaymentGate::state($order?->paymentMethod, $order?->advanceStatus),
            ),
            'claimed_by_other' => new ApiException(409, 'ORDER_ALREADY_CLAIMED', 'Another employee is working on this order.'),
            ProductionStop::BLOCKED_REASON => ProductionStop::blocked(),
            OrderAccessPolicy::BLOCKED_AUTHORITY_SOURCE => new ApiException(409, 'PRODUCTION_AUTHORITY_SOURCE', 'Production of this order is still managed by its source. A manager must take it over first.'),
            'order_unavailable' => new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.'),
            'production_completed' => new ApiException(409, 'INVALID_STAGE_TRANSITION', 'Production is already completed.'),
            'exception_pending' => new ApiException(409, 'ORDER_BLOCKED_BY_EXCEPTION', 'The order is waiting for a production exception decision.'),
            OrderAccessPolicy::BLOCKED_PIECES_IN_REWORK => new ApiException(409, 'ORDER_PIECES_IN_REWORK', 'Comanda nu poate fi ambalată: o bucată este încă în refacere.'),
            'pieces_awaiting_reunion' => new ApiException(409, 'ORDER_PIECES_NOT_READY', 'Comanda așteaptă reunirea pieselor.'),
            'pieces_held' => new ApiException(409, 'ORDER_PIECES_HELD', 'O bucată este reținută fizic; eliberează reținerea înainte de ambalare.'),
            'defect_unresolved' => new ApiException(409, 'DEFECT_RESOLUTION_REQUIRED', 'Defectul inițial nu a fost confirmat ca remediat în ciclul curent.'),
            'pieces_incomplete' => new ApiException(409, 'ORDER_PIECES_INCOMPLETE', 'Comanda nu are toate bucățile necesare, valide, pentru ambalare.'),
            'route_incomplete' => new ApiException(409, 'ORDER_ROUTE_INCOMPLETE', 'O bucată nu a trecut prin toate etapele aplicabile tipului ei.'),
            'plan_confirmation_required' => new ApiException(409, 'PLAN_CONFIRMATION_REQUIRED', 'Planul de producție trebuie reconfirmat: comanda s-a schimbat la sursă.'),
            \Arasya\Operations\Document\DocumentGuard::BLOCKED_REASON => \Arasya\Operations\Document\DocumentGuard::blocked(),
            'workflow_unavailable' => new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.'),
            default => new ApiException(403, 'UNAUTHORIZED_ACTION', $operation === self::OPERATION_CLAIM ? 'You cannot claim this order.' : 'You cannot complete this stage.'),
        };
    }

    private function intakeBlocked(string $reason, ?OperationalOrder $order = null): ApiException
    {
        return match ($reason) {
            'intake_not_allowed' => self::intakeNotAllowed(),
            'not_found' => new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.'),
            'intake_not_applicable' => new ApiException(409, 'CUTTING_INTAKE_NOT_APPLICABLE', 'Comanda nu așteaptă preluarea la tăiere.'),
            'claimed_by_other' => new ApiException(409, 'ORDER_ALREADY_CLAIMED', 'Comanda este deja preluată de un coleg.'),
            default => $this->blocked($reason, self::OPERATION_CLAIM, $order),
        };
    }

    public static function intakeNotAllowed(): ApiException
    {
        return new ApiException(403, 'CUTTING_INTAKE_NOT_ALLOWED', 'Preluarea prin scanare este disponibilă doar angajaților eligibili pentru tăiere.');
    }

    private function recordDenied(string $operation, EmployeeIdentity $employee, GlobalOrderId $orderId, ApiException $error, OperationContext $context): void
    {
        try {
            $this->audit->record(
                match ($operation) { self::OPERATION_CLAIM => 'ORDER_CLAIM_DENIED', self::OPERATION_CUTTING_INTAKE => 'CUTTING_INTAKE_DENIED', default => 'ORDER_TRANSITION_DENIED' },
                $employee->employeeUuid,
                null,
                $context->ipAddress,
                $context->userAgent,
                $context->requestId,
                $this->clock->now()->format('Y-m-d H:i:s.u'),
                ['order' => $orderId->toString(), 'code' => $error->errorCode],
            );
        } catch (Throwable) {
            // A failed denial audit must never turn a safe rejection into a different outcome.
        }
    }
}
