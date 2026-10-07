<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Http\ApiException;
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
    ) {
    }

    /** @return array<string, mixed> serialized order after the committed (or replayed) claim */
    public function claim(EmployeeIdentity $employee, GlobalOrderId $orderId, int $expectedVersion, string $idempotencyKey, OperationContext $context, array $claimIntent = []): array
    {
        return $this->mutate(self::OPERATION_CLAIM, $employee, $orderId, $expectedVersion, $idempotencyKey, $context, $claimIntent);
    }

    /** @return array<string, mixed> serialized order after the committed (or replayed) stage completion */
    public function completeCurrentStage(EmployeeIdentity $employee, GlobalOrderId $orderId, int $expectedVersion, string $idempotencyKey, OperationContext $context): array
    {
        return $this->mutate(self::OPERATION_TRANSITION, $employee, $orderId, $expectedVersion, $idempotencyKey, $context);
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
        $requestHash = hash('sha256', $baseIntent . ($extra === [] ? '' : '|' . json_encode([$extra['qrToken'] ?? null, $extra['confirmedMultiple'] ?? false, $extra['ownedCount'] ?? null], JSON_THROW_ON_ERROR)), true);

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
            $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
            $lock->execute([$orderId->toString()]);
            $orderUuid = $lock->fetchColumn();
            if (!is_string($orderUuid)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            }

            // Before any consistent read: serialize this employee's claims across different orders.
            $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$employee->employeeUuid]);

            $replay = $this->replay($employee->employeeUuid, $idempotencyKey, $operation, $orderUuid, $requestHash);
            if ($replay !== null) {
                $this->pdo->rollBack();
                return $replay;
            }

            $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid($employee->employeeUuid) ?? throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
            if (!$employee->isOperationallyActive() || !$employee->hasApplication('staff') || $employee->mustChangePassword) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');

            $order = $this->orders->findByGlobalId($employee->employeeUuid, $orderId->toString());
            if ($order === null || !$this->policy->canView($employee, $order)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
            }
            if ($order->productionVersion !== $expectedVersion) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }

            $decision = $this->policy->evaluate($employee, $order, $workflow);
            if ($decision['action'] === null) throw $this->blocked((string) $decision['blockedReason'], $operation);
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
                    throw $this->blocked((string) $decision['blockedReason'], $operation);
                }
                $this->applyClaim($employee, $order, $workflow, $expectedVersion, $idempotencyKey, $context, $now);
            } else {
                if ($decision['action'] === OrderAccessPolicy::ACTION_CLAIM) {
                    throw new ApiException(409, 'INVALID_STAGE_TRANSITION', 'The order must be claimed before its stage can be completed.');
                }
                if ($decision['action'] === null) {
                    throw $this->blocked((string) $decision['blockedReason'], $operation);
                }
                $this->applyTransition($employee, $order, $workflow, $expectedVersion, $idempotencyKey, $context, $now);
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

    private function applyClaim(EmployeeIdentity $employee, OperationalOrder $order, ProductionWorkflow $workflow, int $expectedVersion, string $idempotencyKey, OperationContext $context, string $now): void
    {
        $stage = $this->stage($workflow, $order->productionStageId);
        $update = $this->pdo->prepare(
            "UPDATE operational_orders
             SET production_owner_employee_uuid = ?, production_claimed_at = ?, production_authority = 'operations',
                 production_version = production_version + 1, version = version + 1, updated_at = ?
             WHERE order_uuid = ? AND production_version = ? AND production_owner_employee_uuid IS NULL AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN ('none', 'active')",
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

    private function applyTransition(EmployeeIdentity $employee, OperationalOrder $order, ProductionWorkflow $workflow, int $expectedVersion, string $idempotencyKey, OperationContext $context, string $now): void
    {
        $current = $this->stage($workflow, $order->productionStageId);
        $next = null;
        foreach ($workflow->stages as $index => $stage) {
            if ($stage->id === $current->id) {
                $next = $workflow->stages[$index + 1] ?? null;
                break;
            }
        }
        if ($next === null) {
            $update = $this->pdo->prepare(
                'UPDATE operational_orders
                 SET production_completed_at = ?, production_owner_employee_uuid = NULL, production_claimed_at = NULL, production_changed_at = ?,
                     production_version = production_version + 1, version = version + 1, updated_at = ?
                 WHERE order_uuid = ? AND production_version = ? AND production_owner_employee_uuid = ? AND production_stage_id = ? AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN (\'none\', \'active\')',
            );
            $update->execute([$now, $now, $now, $order->orderUuid, $expectedVersion, $employee->employeeUuid, $current->id]);
            $relation = 'completed';
            $action = 'production_completed';
        } else {
            $update = $this->pdo->prepare(
                'UPDATE operational_orders
                 SET production_stage_id = ?, production_owner_employee_uuid = NULL, production_claimed_at = NULL, production_changed_at = ?,
                     production_version = production_version + 1, version = version + 1, updated_at = ?
                 WHERE order_uuid = ? AND production_version = ? AND production_owner_employee_uuid = ? AND production_stage_id = ? AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND document_status IN (\'none\', \'active\')',
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
        return $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $workflow), $this->quality?->orderQuality($order->orderUuid, $order->openExceptionUuid, $employee->employeeUuid), $this->documents?->summary($order->orderUuid));
    }

    private function blocked(string $reason, string $operation): ApiException
    {
        return match ($reason) {
            'claimed_by_other' => new ApiException(409, 'ORDER_ALREADY_CLAIMED', 'Another employee is working on this order.'),
            OrderAccessPolicy::BLOCKED_AUTHORITY_SOURCE => new ApiException(409, 'PRODUCTION_AUTHORITY_SOURCE', 'Production of this order is still managed by its source. A manager must take it over first.'),
            'order_unavailable' => new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.'),
            'production_completed' => new ApiException(409, 'INVALID_STAGE_TRANSITION', 'Production is already completed.'),
            'exception_pending' => new ApiException(409, 'ORDER_BLOCKED_BY_EXCEPTION', 'The order is waiting for a production exception decision.'),
            \Arasya\Operations\Document\DocumentGuard::BLOCKED_REASON => \Arasya\Operations\Document\DocumentGuard::blocked(),
            'workflow_unavailable' => new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.'),
            default => new ApiException(403, 'UNAUTHORIZED_ACTION', $operation === self::OPERATION_CLAIM ? 'You cannot claim this order.' : 'You cannot complete this stage.'),
        };
    }

    private function recordDenied(string $operation, EmployeeIdentity $employee, GlobalOrderId $orderId, ApiException $error, OperationContext $context): void
    {
        try {
            $this->audit->record(
                $operation === self::OPERATION_CLAIM ? 'ORDER_CLAIM_DENIED' : 'ORDER_TRANSITION_DENIED',
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
