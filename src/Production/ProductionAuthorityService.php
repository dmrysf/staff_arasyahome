<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Document\DocumentGuard;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Explicit production authority takeover: a manager moves one source-managed order to Arasya at a
 * canonical stage the manager selects. Nothing is inferred: the legacy (source) production stage is
 * never read, mapped or translated, and an order existing in Arasya never changes authority by itself.
 *
 * Exactly two mutations exist:
 * - takeover: 'source' -> 'operations' at the selected stage of the active curtain-production workflow;
 * - release: the restricted way back ('operations' -> 'source', previous stage restored), allowed only
 *   while the takeover is the order's latest production change (nobody claimed, moved or reassigned it
 *   since), so an order that Arasya already produced can never return to the source.
 *
 * Every mutation runs in one transaction that locks the order row, replays a committed result for the
 * same actor idempotency key, checks the expected production version, and writes the order change, the
 * immutable production activity event, the authority evidence row, the IAM audit event and the
 * idempotency result before commit. A stale version is a 409, never an overwrite. Repeating a takeover
 * that already reached the requested state changes nothing and records nothing.
 *
 * Authority: Dashboard application access + production.manage_authority (also used from the Staff
 * application by managers who hold both). The source must be in `observe` or `enforce` authority mode.
 */
final readonly class ProductionAuthorityService
{
    public const PERMISSION = 'production.manage_authority';
    public const OPERATION_TAKEOVER = 'authority_takeover';
    public const OPERATION_RELEASE = 'authority_release';
    public const ACTION_TAKEN_OVER = 'authority_taken_over';
    public const ACTION_RELEASED = 'authority_released';
    public const AUDIT_TAKEN_OVER = 'production.authority.taken_over';
    public const AUDIT_RELEASED = 'production.authority.released';
    public const AUDIT_DENIED = 'PRODUCTION_AUTHORITY_DENIED';

    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1062, 1205, 1213];
    private const ORDER_COLUMNS = 'order_uuid, global_order_id, source_key, order_number, production_stage_id, production_authority, production_owner_employee_uuid,
        production_completed_at, operational_status, production_version, open_exception_uuid, document_status';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private ProductionWorkflowService $workflows,
        private ProductionAuthorityModes $modes,
        private IamAuditLogger $iamAudit,
        private AuditLogger $securityAudit,
        private Clock $clock,
    ) {
    }

    public function canManage(EmployeeIdentity $actor): bool
    {
        return $this->authorization->can($actor, self::PERMISSION);
    }

    /** Read-only authority view of one order for the manager screen. @return array<string, mixed> */
    public function inspect(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireAccess($actor);
        $workflow = $this->workflow();
        $order = $this->order($this->globalId($globalOrderId), false);
        $mode = $this->modes->modeFor((string) $order['source_key']);
        $stage = $this->stageOrNull($workflow, (string) $order['production_stage_id']);
        return [
            'globalOrderId' => (string) $order['global_order_id'],
            'orderNumber' => (string) $order['order_number'],
            'source' => (string) $order['source_key'],
            'authorityMode' => $mode->value,
            'productionAuthority' => (string) $order['production_authority'],
            'stage' => ['id' => (string) $order['production_stage_id'], 'label' => $stage?->label],
            'productionVersion' => (int) $order['production_version'],
            'operationalStatus' => (string) $order['operational_status'],
            'productionCompleted' => $order['production_completed_at'] !== null,
            'hasOwner' => $order['production_owner_employee_uuid'] !== null,
            'takeover' => ['allowed' => $this->takeoverBlockedReason($order, $mode) === null, 'blockedReason' => $this->takeoverBlockedReason($order, $mode)],
            'release' => ['allowed' => $this->releaseBlockedReason($order) === null, 'blockedReason' => $this->releaseBlockedReason($order)],
            'workflow' => [
                'id' => $workflow->id,
                'version' => $workflow->version,
                'stages' => array_map(static fn (ProductionStage $s): array => ['id' => $s->id, 'label' => $s->label, 'ordinal' => $s->ordinal], $workflow->stages),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input {expectedVersion: int, stageId: string, workflowId?: string, workflowVersion?: int}
     * @return array<string, mixed>
     */
    public function takeOver(EmployeeIdentity $actor, string $globalOrderId, array $input, string $idempotencyKey, string $requestId, string $ipAddress = '', string $userAgent = ''): array
    {
        try {
            $this->requireAccess($actor);
            if (array_diff(array_keys($input), ['expectedVersion', 'stageId', 'workflowId', 'workflowVersion']) !== []
                || !is_int($input['expectedVersion'] ?? null) || $input['expectedVersion'] < 1
                || !is_string($input['stageId'] ?? null) || $input['stageId'] === '' || strlen($input['stageId']) > 100
                || (isset($input['workflowId']) && !is_string($input['workflowId'])) || (isset($input['workflowVersion']) && !is_int($input['workflowVersion']))) {
                throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion and stageId are required.');
            }
            $this->requireKey($idempotencyKey);
            $workflow = $this->workflow();
            if (($input['workflowId'] ?? $workflow->id) !== $workflow->id || ($input['workflowVersion'] ?? $workflow->version) !== $workflow->version) {
                throw new ApiException(422, 'WORKFLOW_MISMATCH', 'The requested workflow is not the active canonical production workflow.');
            }
            $target = $this->stageOrNull($workflow, $input['stageId'])
                ?? throw new ApiException(422, 'INVALID_STAGE', 'The stage is not part of the active canonical production workflow.');
            $globalId = $this->globalId($globalOrderId);
            $requestHash = hash('sha256', implode('|', [self::OPERATION_TAKEOVER, $globalId, (string) $input['expectedVersion'], $target->id, $workflow->id, (string) $workflow->version]), true);
            return $this->retrying(fn (): array => $this->attemptTakeover($actor, $globalId, $input['expectedVersion'], $target, $workflow, $idempotencyKey, $requestHash, $requestId));
        } catch (ApiException $error) {
            $this->recordDenied($actor, $globalOrderId, 'takeover', $error, $requestId, $ipAddress, $userAgent);
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function release(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $idempotencyKey, string $requestId, string $ipAddress = '', string $userAgent = ''): array
    {
        try {
            $this->requireAccess($actor);
            if (!is_int($expectedVersion) || $expectedVersion < 1) {
                throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
            }
            $this->requireKey($idempotencyKey);
            $workflow = $this->workflow();
            $globalId = $this->globalId($globalOrderId);
            $requestHash = hash('sha256', implode('|', [self::OPERATION_RELEASE, $globalId, (string) $expectedVersion]), true);
            return $this->retrying(fn (): array => $this->attemptRelease($actor, $globalId, $expectedVersion, $workflow, $idempotencyKey, $requestHash, $requestId));
        } catch (ApiException $error) {
            $this->recordDenied($actor, $globalOrderId, 'release', $error, $requestId, $ipAddress, $userAgent);
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function attemptTakeover(EmployeeIdentity $actor, string $globalId, int $expectedVersion, ProductionStage $target, ProductionWorkflow $workflow, string $idempotencyKey, string $requestHash, string $requestId): array
    {
        // MySQL and MariaDB evaluate SET assignments left to right: production_changed_at must compare
        // against the stage before production_stage_id is assigned.
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($globalId, true);
            $orderUuid = (string) $order['order_uuid'];
            $replay = $this->replay($actor->employeeUuid, $idempotencyKey, self::OPERATION_TAKEOVER, $orderUuid, $requestHash);
            if ($replay !== null) {
                $this->pdo->rollBack();
                return $replay;
            }
            $mode = $this->modes->modeFor((string) $order['source_key']);
            if ($order['production_authority'] === ProductionAuthority::OPERATIONS) {
                // Idempotent for the same final state; anything else is a stage change, never a takeover.
                if ($order['production_stage_id'] === $target->id) {
                    $this->pdo->rollBack();
                    return $this->response($order, self::ACTION_TAKEN_OVER, false, $workflow);
                }
                throw new ApiException(409, 'AUTHORITY_ALREADY_OPERATIONS', 'Arasya already manages the production of this order.');
            }
            $blocked = $this->takeoverBlockedReason($order, $mode);
            if ($blocked !== null) {
                throw $this->blockedError($blocked);
            }
            if ((int) $order['production_version'] !== $expectedVersion) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $previous = (string) $order['production_stage_id'];
            if ($previous === CuttingLifecycle::STAGE) {
                (new CuttingLifecycle($this->pdo))->requireNoTransfer($orderUuid);
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $update = $this->pdo->prepare(
                "UPDATE operational_orders
                 SET production_changed_at = IF(production_stage_id <> :stage_cmp, :changed_at, production_changed_at),
                     production_authority = 'operations', production_stage_id = :stage,
                     production_version = production_version + 1, version = version + 1, updated_at = :now
                 WHERE order_uuid = :id AND production_version = :expected AND production_authority = 'source'
                   AND production_owner_employee_uuid IS NULL AND production_completed_at IS NULL AND open_exception_uuid IS NULL
                   AND operational_status <> 'unavailable' AND document_status IN ('none', 'active')",
            );
            $update->execute(['stage' => $target->id, 'stage_cmp' => $target->id, 'changed_at' => $now, 'now' => $now, 'id' => $orderUuid, 'expected' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $from = $this->stageOrNull($workflow, $previous);
            $this->recordActivity($actor, $order, $workflow, self::ACTION_TAKEN_OVER, $previous, $from?->label ?? $previous, $target->id, $target->label, $expectedVersion, $idempotencyKey, $requestId, $now);
            $this->recordAuthorityEvent($order, 'takeover', $mode, ProductionAuthority::SOURCE, ProductionAuthority::OPERATIONS, $previous, $target->id, $expectedVersion, $actor->employeeUuid, 'explicit_manager_takeover', $requestId, $idempotencyKey, $now);
            if ($target->id === CuttingLifecycle::STAGE && $previous !== CuttingLifecycle::STAGE) {
                (new CuttingLifecycle($this->pdo))->fact($orderUuid, $expectedVersion + 1, 'pool_entered', null, $now);
                (new LiveEvents($this->pdo))->cuttingChanged($now);
            }
            $this->iamAudit->record($actor, self::AUDIT_TAKEN_OVER, 'order', $globalId, "{$order['order_number']} · {$order['source_key']}", [
                'source' => (string) $order['source_key'],
                'authorityMode' => $mode->value,
                'authority' => ['before' => ProductionAuthority::SOURCE, 'after' => ProductionAuthority::OPERATIONS],
                'stage' => ['before' => $previous, 'after' => $target->id],
                'productionVersion' => ['before' => $expectedVersion, 'after' => $expectedVersion + 1],
            ], $requestId, $now);
            $order = $this->order($globalId, false);
            $response = $this->response($order, self::ACTION_TAKEN_OVER, true, $workflow);
            $this->storeResult($actor->employeeUuid, $idempotencyKey, self::OPERATION_TAKEOVER, $orderUuid, $requestHash, $response, $now);
            (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($orderUuid);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function attemptRelease(EmployeeIdentity $actor, string $globalId, int $expectedVersion, ProductionWorkflow $workflow, string $idempotencyKey, string $requestHash, string $requestId): array
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($globalId, true);
            $orderUuid = (string) $order['order_uuid'];
            $replay = $this->replay($actor->employeeUuid, $idempotencyKey, self::OPERATION_RELEASE, $orderUuid, $requestHash);
            if ($replay !== null) {
                $this->pdo->rollBack();
                return $replay;
            }
            if ((int) $order['production_version'] !== $expectedVersion) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $blocked = $this->releaseBlockedReason($order);
            if ($blocked !== null) {
                throw $this->blockedError($blocked);
            }
            $takeover = $this->latestTakeover($orderUuid, $expectedVersion);
            $restored = (string) $takeover['from_stage_id'];
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $update = $this->pdo->prepare(
                "UPDATE operational_orders
                 SET production_changed_at = IF(production_stage_id <> :stage_cmp, :changed_at, production_changed_at),
                     production_authority = 'source', production_stage_id = :stage,
                     production_version = production_version + 1, version = version + 1, updated_at = :now
                 WHERE order_uuid = :id AND production_version = :expected AND production_authority = 'operations'
                   AND production_owner_employee_uuid IS NULL AND production_completed_at IS NULL AND open_exception_uuid IS NULL",
            );
            $update->execute(['stage' => $restored, 'stage_cmp' => $restored, 'changed_at' => $now, 'now' => $now, 'id' => $orderUuid, 'expected' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $current = (string) $order['production_stage_id'];
            $mode = $this->modes->modeFor((string) $order['source_key']);
            $this->recordActivity($actor, $order, $workflow, self::ACTION_RELEASED, $current, $this->stageOrNull($workflow, $current)?->label ?? $current, $restored, (string) $takeover['from_stage_label_snapshot'], $expectedVersion, $idempotencyKey, $requestId, $now);
            $this->recordAuthorityEvent($order, 'release', $mode, ProductionAuthority::OPERATIONS, ProductionAuthority::SOURCE, $current, $restored, $expectedVersion, $actor->employeeUuid, 'untouched_takeover_released', $requestId, $idempotencyKey, $now);
            if ($current === CuttingLifecycle::STAGE && $restored !== CuttingLifecycle::STAGE) {
                (new LiveEvents($this->pdo))->cuttingChanged($now);
            }
            $this->iamAudit->record($actor, self::AUDIT_RELEASED, 'order', $globalId, "{$order['order_number']} · {$order['source_key']}", [
                'source' => (string) $order['source_key'],
                'authorityMode' => $mode->value,
                'authority' => ['before' => ProductionAuthority::OPERATIONS, 'after' => ProductionAuthority::SOURCE],
                'stage' => ['before' => $current, 'after' => $restored],
                'productionVersion' => ['before' => $expectedVersion, 'after' => $expectedVersion + 1],
            ], $requestId, $now);
            $order = $this->order($globalId, false);
            $response = $this->response($order, self::ACTION_RELEASED, true, $workflow);
            $this->storeResult($actor->employeeUuid, $idempotencyKey, self::OPERATION_RELEASE, $orderUuid, $requestHash, $response, $now);
            (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($orderUuid);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Why a takeover is impossible right now, independent of the actor. @param array<string, mixed> $order */
    private function takeoverBlockedReason(array $order, ProductionAuthorityMode $mode): ?string
    {
        return match (true) {
            !$this->modes->isManagedSource((string) $order['source_key']) => 'source_not_supported',
            !$mode->allowsTakeover() => 'authority_cutover_disabled',
            $order['production_authority'] === ProductionAuthority::OPERATIONS => 'already_operations',
            $order['production_completed_at'] !== null => 'production_completed',
            $order['operational_status'] === 'unavailable' => 'order_unavailable',
            $order['open_exception_uuid'] !== null => 'exception_pending',
            DocumentGuard::isBlocked((string) $order['document_status']) => DocumentGuard::BLOCKED_REASON,
            $order['production_owner_employee_uuid'] !== null => 'owner_present',
            default => null,
        };
    }

    /** Release is only for an untouched takeover: its takeover must still be the latest production change. @param array<string, mixed> $order */
    private function releaseBlockedReason(array $order): ?string
    {
        if ($order['production_authority'] !== ProductionAuthority::OPERATIONS) {
            return 'not_operations';
        }
        if ($order['production_completed_at'] !== null) {
            return 'production_completed';
        }
        if ($order['production_owner_employee_uuid'] !== null || $order['open_exception_uuid'] !== null) {
            return 'production_started';
        }
        $statement = $this->pdo->prepare('SELECT action, production_version_after FROM order_activity_events WHERE order_uuid = ? ORDER BY production_version_after DESC, occurred_at DESC LIMIT 1');
        $statement->execute([(string) $order['order_uuid']]);
        $latest = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($latest) || $latest['action'] !== self::ACTION_TAKEN_OVER || (int) $latest['production_version_after'] !== (int) $order['production_version']) {
            return 'not_untouched_takeover';
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function latestTakeover(string $orderUuid, int $productionVersion): array
    {
        $statement = $this->pdo->prepare("SELECT from_stage_id, from_stage_label_snapshot FROM order_activity_events WHERE order_uuid = ? AND action = 'authority_taken_over' AND production_version_after = ? LIMIT 1");
        $statement->execute([$orderUuid, $productionVersion]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(409, 'AUTHORITY_RELEASE_NOT_ALLOWED', 'Only an untouched takeover can be released.');
    }

    private function blockedError(string $reason): ApiException
    {
        return match ($reason) {
            'source_not_supported' => new ApiException(409, 'AUTHORITY_NOT_SUPPORTED', 'Production authority of this source cannot be changed.'),
            'authority_cutover_disabled' => new ApiException(409, 'AUTHORITY_CUTOVER_DISABLED', 'Production authority takeover is not enabled for this source.'),
            'already_operations' => new ApiException(409, 'AUTHORITY_ALREADY_OPERATIONS', 'Arasya already manages the production of this order.'),
            'production_completed' => new ApiException(409, 'PRODUCTION_COMPLETED', 'Production is completed.'),
            'order_unavailable' => new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.'),
            'exception_pending' => new ApiException(409, 'ORDER_BLOCKED_BY_EXCEPTION', 'The order is waiting for a production exception decision.'),
            DocumentGuard::BLOCKED_REASON => DocumentGuard::blocked(),
            'not_operations' => new ApiException(409, 'AUTHORITY_NOT_OPERATIONS', 'The order is not managed by Arasya.'),
            default => new ApiException(409, 'AUTHORITY_RELEASE_NOT_ALLOWED', 'Only an untouched takeover can be released.'),
        };
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function response(array $order, string $action, bool $changed, ProductionWorkflow $workflow): array
    {
        $stage = $this->stageOrNull($workflow, (string) $order['production_stage_id']);
        return [
            'globalOrderId' => (string) $order['global_order_id'],
            'action' => $action,
            'changed' => $changed,
            'productionAuthority' => (string) $order['production_authority'],
            'stage' => ['id' => (string) $order['production_stage_id'], 'label' => $stage?->label],
            'productionVersion' => (int) $order['production_version'],
        ];
    }

    /** @param array<string, mixed> $order */
    private function recordActivity(EmployeeIdentity $actor, array $order, ProductionWorkflow $workflow, string $action, string $fromId, string $fromLabel, string $toId, string $toLabel, int $versionBefore, string $idempotencyKey, string $requestId, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO order_activity_events (
                event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action,
                workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot,
                production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)',
        )->execute([
            Uuid::v4(), $actor->employeeUuid, (string) $order['order_uuid'], (string) $order['global_order_id'], (string) $order['source_key'], (string) $order['order_number'], $action,
            $workflow->id, $workflow->version, $fromId, $fromLabel, $toId, $toLabel,
            $versionBefore, $versionBefore + 1, mb_substr($requestId, 0, 100), $idempotencyKey, $now,
        ]);
    }

    /** @param array<string, mixed> $order */
    private function recordAuthorityEvent(array $order, string $action, ProductionAuthorityMode $mode, string $previousAuthority, string $newAuthority, string $previousStage, string $newStage, int $versionBefore, string $actorUuid, string $reason, string $requestId, string $idempotencyKey, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO production_authority_events (
                event_uuid, order_uuid, global_order_id, source_key, action, authority_mode, previous_authority, new_authority,
                previous_stage_id, new_stage_id, production_version_before, production_version_after, actor_employee_uuid,
                reason_code, request_id, idempotency_key, occurred_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), (string) $order['order_uuid'], (string) $order['global_order_id'], (string) $order['source_key'], $action, $mode->value, $previousAuthority, $newAuthority,
            $previousStage, $newStage, $versionBefore, $versionBefore + 1, $actorUuid, $reason, mb_substr($requestId, 0, 100), $idempotencyKey, $now,
        ]);
    }

    private function recordDenied(EmployeeIdentity $actor, string $globalOrderId, string $operation, ApiException $error, string $requestId, string $ipAddress, string $userAgent): void
    {
        try {
            $this->securityAudit->record(self::AUDIT_DENIED, $actor->employeeUuid, null, $ipAddress, $userAgent, $requestId, $this->clock->now()->format('Y-m-d H:i:s.u'), [
                'order' => mb_substr($globalOrderId, 0, 191),
                'operation' => $operation,
                'code' => $error->errorCode,
            ]);
        } catch (Throwable) {
            // A failed denial audit must never turn a safe rejection into a different outcome.
        }
    }

    /** @param callable(): array<string, mixed> $attempt @return array<string, mixed> */
    private function retrying(callable $attempt): array
    {
        for ($try = 1; ; $try++) {
            try {
                return $attempt();
            } catch (PDOException $error) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if ($try < self::MAX_ATTEMPTS && in_array($driverCode, self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    private function requireAccess(EmployeeIdentity $actor): void
    {
        $this->authorization->require($actor, self::PERMISSION);
    }

    private function requireKey(string $idempotencyKey): void
    {
        if (!OrderOperationsService::isValidIdempotencyKey($idempotencyKey)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
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

    private function stageOrNull(ProductionWorkflow $workflow, string $stageId): ?ProductionStage
    {
        foreach ($workflow->stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function order(string $globalId, bool $lock): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE global_order_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
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
        return is_array($decoded) ? $decoded : throw new RuntimeException('Stored idempotent result is unreadable.');
    }

    /** @param array<string, mixed> $response */
    private function storeResult(string $actorUuid, string $idempotencyKey, string $operation, string $orderUuid, string $requestHash, array $response, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO order_operation_idempotency (employee_uuid, idempotency_key, operation, order_uuid, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
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
