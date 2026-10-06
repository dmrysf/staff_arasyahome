<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Production\ProductionStage;
use Arasya\Operations\Production\ProductionWorkflow;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Production Control V2: supervisor interventions on the current production owner.
 *
 * Exactly two mutations exist: release (owner becomes null) and reassign (owner becomes another
 * eligible employee). Neither touches the production stage, the stage timestamps, completion,
 * commerce columns, items or the source; neither sends anything anywhere. The single owner truth
 * stays operational_orders.production_owner_employee_uuid.
 *
 * Every mutation runs in one transaction that locks the order row, replays a committed result for
 * the same actor idempotency key, checks the expected production version, re-validates the target
 * employee under a row lock, and writes the owner change, the immutable production activity event,
 * the IAM audit event and the idempotency result before commit. A stale version is a 409, never an
 * overwrite.
 *
 * Authority: dashboard application + orders.view_all + production.manage_owner. A non-root actor may
 * only affect employees ranked strictly below its own authority (the IAM rule), never root, and may
 * not assign an order to itself; it may release its own ownership.
 */
final readonly class OrderOwnershipService
{
    public const PERMISSION = 'production.manage_owner';
    public const OPERATION_RELEASE = 'release_owner';
    public const OPERATION_REASSIGN = 'reassign_owner';
    public const ACTION_RELEASED = 'owner_released';
    public const ACTION_REASSIGNED = 'owner_reassigned';
    public const AUDIT_RELEASED = 'production.owner.released';
    public const AUDIT_REASSIGNED = 'production.owner.reassigned';

    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1062, 1205, 1213];
    private const CANDIDATE_LIMIT = 200;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private EmployeeRepository $employees,
        private ProductionWorkflowService $workflows,
        private IamAuditLogger $audit,
        private Clock $clock,
    ) {
    }

    public function canManage(EmployeeIdentity $actor): bool
    {
        return $actor->hasApplication('dashboard') && $this->authorization->can($actor, 'orders.view_all') && $this->authorization->can($actor, self::PERMISSION);
    }

    /**
     * Why an intervention is impossible for this order right now, independent of the actor.
     *
     * @param array<string, mixed> $order
     */
    public static function blockedReason(array $order): ?string
    {
        if ($order['production_completed_at'] !== null) {
            return 'production_completed';
        }
        if ($order['operational_status'] === 'unavailable') {
            return 'order_unavailable';
        }
        if (($order['open_exception_uuid'] ?? null) !== null) {
            return 'exception_pending';
        }
        return null;
    }

    /**
     * Whether an employee may own work at a stage: the same rules OrderAccessPolicy applies when the
     * employee acts in Staff. A pending password change does not disqualify: it is resolved at login.
     */
    public static function isEligibleOwner(EmployeeIdentity $employee, string $stageId): bool
    {
        return !$employee->isRoot
            && $employee->isOperationallyActive()
            && $employee->hasApplication('staff')
            && in_array($stageId, $employee->allowedStageIds, true)
            && in_array('orders.advance_stage', $employee->permissions, true);
    }

    /** Staff-eligible employees for the order's current stage that this actor may assign. @return array<string, mixed> */
    public function eligibleOwners(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireAccess($actor);
        $globalId = $this->globalId($globalOrderId);
        $workflow = $this->workflow();
        $order = $this->order($globalId, false);
        $stage = $this->stage($workflow, (string) $order['production_stage_id']);
        $blocked = self::blockedReason($order);
        $base = ['stage' => ['id' => $stage->id, 'label' => $stage->label], 'productionVersion' => (int) $order['production_version'], 'blockedReason' => $blocked, 'items' => []];
        if ($blocked !== null) {
            return $base;
        }
        $statement = $this->pdo->prepare(
            "SELECT e.employee_uuid, e.display_name, e.position_title, d.name AS department_name, ps.display_name AS stage_label,
                    (SELECT COALESCE(MAX(r.authority_rank), 0) FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid = e.employee_uuid) AS authority_rank
             FROM employees e
             INNER JOIN departments d ON d.department_id = e.department_id AND d.status = 'active'
             INNER JOIN employee_stage_access esa ON esa.employee_uuid = e.employee_uuid AND esa.stage_id = :stage
             INNER JOIN production_stages ps ON ps.stage_id = esa.stage_id AND ps.status = 'active'
             INNER JOIN employee_application_access eaa ON eaa.employee_uuid = e.employee_uuid AND eaa.application_key = 'staff'
             INNER JOIN applications a ON a.application_key = eaa.application_key AND a.status = 'active'
             WHERE e.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM system_root_identity sri WHERE sri.employee_uuid = e.employee_uuid)
               AND e.employee_uuid <> :actor
               AND (:owner IS NULL OR e.employee_uuid <> :owner_eq)
             ORDER BY e.display_name, e.employee_uuid
             LIMIT " . self::CANDIDATE_LIMIT,
        );
        $owner = $order['production_owner_employee_uuid'];
        $statement->execute(['stage' => $stage->id, 'actor' => $actor->employeeUuid, 'owner' => $owner, 'owner_eq' => $owner ?? '']);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$actor->isRoot && (int) $row['authority_rank'] >= $actor->authorityRank) {
                continue;
            }
            $base['items'][] = [
                'id' => (string) $row['employee_uuid'],
                'displayName' => (string) $row['display_name'],
                'department' => (string) $row['department_name'],
                'positionTitle' => $row['position_title'] === null ? null : (string) $row['position_title'],
                'stage' => ['id' => $stage->id, 'label' => (string) $row['stage_label']],
            ];
        }
        return $base;
    }

    /** @return array<string, mixed> */
    public function release(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        return $this->mutate(self::OPERATION_RELEASE, $actor, $globalOrderId, null, $expectedVersion, $idempotencyKey, $requestId);
    }

    /** @return array<string, mixed> */
    public function reassign(EmployeeIdentity $actor, string $globalOrderId, mixed $employeeId, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        if (!is_string($employeeId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $employeeId) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'employeeId must be an employee id.');
        }
        return $this->mutate(self::OPERATION_REASSIGN, $actor, $globalOrderId, $employeeId, $expectedVersion, $idempotencyKey, $requestId);
    }

    /** @return array<string, mixed> */
    private function mutate(string $operation, EmployeeIdentity $actor, string $globalOrderId, ?string $targetId, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        $this->requireAccess($actor);
        if (!OrderOperationsService::isValidIdempotencyKey($idempotencyKey)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        $globalId = $this->globalId($globalOrderId);
        $workflow = $this->workflow();
        $requestHash = hash('sha256', implode('|', [$operation, $globalId, (string) $expectedVersion, $targetId ?? '']), true);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($operation, $actor, $globalId, $targetId, $expectedVersion, $idempotencyKey, $requestHash, $workflow, $requestId);
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
    private function attempt(string $operation, EmployeeIdentity $actor, string $globalId, ?string $targetId, int $expectedVersion, string $idempotencyKey, string $requestHash, ProductionWorkflow $workflow, string $requestId): array
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($globalId, true);
            $orderUuid = (string) $order['order_uuid'];
            // Lock the target before any plain read opens the transaction snapshot, so a deactivation,
            // application removal or stage removal committed while we waited is visible below.
            $targetExists = false;
            if ($targetId !== null) {
                $lock = $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE');
                $lock->execute([$targetId]);
                $targetExists = $lock->fetchColumn() !== false;
            }

            $replay = $this->replay($actor->employeeUuid, $idempotencyKey, $operation, $orderUuid, $requestHash);
            if ($replay !== null) {
                $this->pdo->rollBack();
                return $replay;
            }
            if ((int) $order['production_version'] !== $expectedVersion) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            if ($order['production_stage_id'] === \Arasya\Operations\Cutting\CuttingLifecycle::STAGE) (new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo))->requireNoTransfer($orderUuid);
            $blocked = self::blockedReason($order);
            if ($blocked === 'production_completed') {
                throw new ApiException(409, 'PRODUCTION_COMPLETED', 'Production is completed; its ownership can no longer change.');
            }
            if ($blocked === 'order_unavailable') {
                throw new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.');
            }
            if ($blocked === 'exception_pending') {
                throw new ApiException(409, 'ORDER_BLOCKED_BY_EXCEPTION', 'The order is waiting for a production exception decision.');
            }
            $stage = $this->stage($workflow, (string) $order['production_stage_id']);
            $previousId = $order['production_owner_employee_uuid'] === null ? null : (string) $order['production_owner_employee_uuid'];
            if ($operation === self::OPERATION_RELEASE && $previousId === null) {
                throw new ApiException(409, 'ORDER_NOT_CLAIMED', 'The order has no production owner to release.');
            }
            if ($previousId !== null && $previousId !== $actor->employeeUuid) {
                $this->assertWithinAuthority($actor, $previousId);
            }

            $target = null;
            if ($operation === self::OPERATION_REASSIGN) {
                $target = $this->eligibleTarget($actor, $targetExists ? (string) $targetId : null, $previousId, $stage);
            }

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $update = $this->pdo->prepare(
                'UPDATE operational_orders
                 SET production_owner_employee_uuid = :new_owner, production_claimed_at = :claimed_at,
                     production_version = production_version + 1, version = version + 1, updated_at = :now
                 WHERE order_uuid = :id AND production_version = :expected AND production_stage_id = :stage
                   AND production_completed_at IS NULL AND open_exception_uuid IS NULL AND production_owner_employee_uuid <=> :previous_owner',
            );
            $update->execute([
                'new_owner' => $target?->employeeUuid,
                'claimed_at' => $target === null ? null : $now,
                'now' => $now,
                'id' => $orderUuid,
                'expected' => $expectedVersion,
                'stage' => $stage->id,
                'previous_owner' => $previousId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }

            // The previous owner's work list no longer shows the order as theirs; their own history stays.
            if ($previousId !== null) {
                $this->pdo->prepare("UPDATE employee_order_relations SET status = 'inactive', updated_at = :now WHERE employee_uuid = :employee AND order_uuid = :order")
                    ->execute(['now' => $now, 'employee' => $previousId, 'order' => $orderUuid]);
            }
            if ($target !== null) {
                $this->pdo->prepare(
                    "INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at)
                     VALUES (:employee, :order, 'assigned', 'active', :now, :now_action, :now_updated)
                     ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type), status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)",
                )->execute(['employee' => $target->employeeUuid, 'order' => $orderUuid, 'now' => $now, 'now_action' => $now, 'now_updated' => $now]);
            }

            $action = $operation === self::OPERATION_RELEASE ? self::ACTION_RELEASED : self::ACTION_REASSIGNED;
            $this->pdo->prepare(
                'INSERT INTO order_activity_events (
                    event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action,
                    workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot,
                    previous_owner_employee_uuid, new_owner_employee_uuid,
                    production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?, ?, ?)',
            )->execute([
                Uuid::v4(), $actor->employeeUuid, $orderUuid, $globalId, (string) $order['source_key'], (string) $order['order_number'], $action,
                $workflow->id, $workflow->version, $stage->id, $stage->label,
                $previousId, $target?->employeeUuid,
                $expectedVersion, $expectedVersion + 1, mb_substr($requestId, 0, 100), $idempotencyKey, $now,
            ]);

            $previousName = $previousId === null ? null : $this->displayName($previousId);
            if ($stage->id === \Arasya\Operations\Cutting\CuttingLifecycle::STAGE) {
                $life = new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo);
                if ($previousId !== null) $life->fact($orderUuid, $expectedVersion + 1, 'interval_ended', $previousId, $now);
                if ($target !== null) $life->claimed($orderUuid, $expectedVersion + 1, $target->employeeUuid, $now);
                else { $life->fact($orderUuid, $expectedVersion + 1, 'pool_entered', null, $now); (new \Arasya\Operations\Quality\LiveEvents($this->pdo))->cuttingChanged($now); }
            }
            $this->audit->record(
                $actor,
                $operation === self::OPERATION_RELEASE ? self::AUDIT_RELEASED : self::AUDIT_REASSIGNED,
                'order',
                $globalId,
                "{$order['order_number']} · {$order['source_key']}",
                [
                    'stage' => ['id' => $stage->id, 'label' => $stage->label],
                    'previousOwner' => $previousId === null ? null : ['id' => $previousId, 'displayName' => $previousName],
                    'newOwner' => $target === null ? null : ['id' => $target->employeeUuid, 'displayName' => $target->displayName],
                    'productionVersion' => ['before' => $expectedVersion, 'after' => $expectedVersion + 1],
                ],
                $requestId,
                $now,
            );

            $response = [
                'globalOrderId' => $globalId,
                'action' => $action,
                'production' => [
                    'version' => $expectedVersion + 1,
                    'stage' => ['id' => $stage->id, 'label' => $stage->label],
                    'owner' => $target === null ? null : ['id' => $target->employeeUuid, 'displayName' => $target->displayName],
                    'previousOwner' => $previousId === null ? null : ['id' => $previousId, 'displayName' => $previousName],
                ],
            ];
            $this->storeResult($actor->employeeUuid, $idempotencyKey, $operation, $orderUuid, $requestHash, $response, $now);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** The target row is already locked by the caller, so its identity is current for this transaction. */
    private function eligibleTarget(EmployeeIdentity $actor, ?string $targetId, ?string $previousId, ProductionStage $stage): EmployeeIdentity
    {
        $target = $targetId === null ? null : $this->employees->findByUuid($targetId);
        if ($target === null) {
            throw new ApiException(404, 'EMPLOYEE_NOT_FOUND', 'Employee was not found.');
        }
        if ($target->isRoot) {
            throw new ApiException(403, 'ROOT_PROTECTED', 'The principal administrator is a protected system identity.');
        }
        if ($target->employeeUuid === $actor->employeeUuid) {
            throw new ApiException(403, 'SELF_MODIFICATION_DENIED', 'You cannot assign production work to yourself; claim it in Staff.');
        }
        if ($target->employeeUuid === $previousId) {
            throw new ApiException(400, 'INVALID_REQUEST', 'The employee already owns this order.');
        }
        $this->assertWithinAuthority($actor, $target->employeeUuid);
        if (!self::isEligibleOwner($target, $stage->id)) {
            throw new ApiException(422, 'EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE', 'The employee cannot work on the current production stage.');
        }
        return $target;
    }

    /** Same ceiling as identity management: strictly below the actor's highest role rank; root only by root. */
    private function assertWithinAuthority(EmployeeIdentity $actor, string $employeeUuid): void
    {
        if ($actor->isRoot) {
            return;
        }
        $root = $this->pdo->prepare('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = ?');
        $root->execute([$employeeUuid]);
        if ((int) $root->fetchColumn() !== 0) {
            throw new ApiException(403, 'ROOT_PROTECTED', 'The principal administrator is a protected system identity.');
        }
        $rank = $this->pdo->prepare('SELECT COALESCE(MAX(r.authority_rank), 0) FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid = ?');
        $rank->execute([$employeeUuid]);
        if ((int) $rank->fetchColumn() >= $actor->authorityRank) {
            throw new ApiException(403, 'AUTHORITY_EXCEEDED', 'The employee is at or above your authority.');
        }
    }

    private function requireAccess(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, 'orders.view_all');
        $this->authorization->require($actor, self::PERMISSION);
    }

    private function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        }
    }

    private function workflow(): ProductionWorkflow
    {
        try {
            return $this->workflows->current();
        } catch (RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
        }
    }

    /** @return array<string, mixed> */
    private function order(string $globalId, bool $lock): array
    {
        $statement = $this->pdo->prepare(
            'SELECT order_uuid, global_order_id, source_key, order_number, production_stage_id, production_owner_employee_uuid,
                    production_completed_at, operational_status, production_version, open_exception_uuid
             FROM operational_orders WHERE global_order_id = ?' . ($lock ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        }
        return $row;
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

    private function displayName(string $employeeUuid): string
    {
        $statement = $this->pdo->prepare('SELECT display_name FROM employees WHERE employee_uuid = ?');
        $statement->execute([$employeeUuid]);
        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    private function replay(string $actorUuid, string $idempotencyKey, string $operation, string $orderUuid, string $requestHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, order_uuid, request_hash, response_json FROM order_operation_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$actorUuid, $idempotencyKey]);
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
    private function storeResult(string $actorUuid, string $idempotencyKey, string $operation, string $orderUuid, string $requestHash, array $response, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO order_operation_idempotency (employee_uuid, idempotency_key, operation, order_uuid, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->bindValue(1, $actorUuid);
        $statement->bindValue(2, $idempotencyKey);
        $statement->bindValue(3, $operation);
        $statement->bindValue(4, $orderUuid);
        $statement->bindValue(5, $requestHash, PDO::PARAM_LOB);
        $statement->bindValue(6, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $statement->bindValue(7, $now);
        $statement->execute();
    }
}
