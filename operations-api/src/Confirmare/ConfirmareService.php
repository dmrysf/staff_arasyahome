<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Production\PaymentGate;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Confirmare server operations (API 2.38.0, Phase 2): the order pool, the automatic distribution and the decisions on
 * the pre-production Confirmare state of an order (order_confirmations).
 *
 * Concurrency. Every command on one order runs in one transaction that locks, in this order, the operator's
 * confirmare_operator_state row (only when the command changes that operator's own load: next, claim), the
 * operational_orders row and the order_confirmations row. The production gate (ConfirmationGate) locks the same order
 * row before it reads the decision, so a production start is either fully before or fully after a decision. Each
 * command names the confirmation version it was decided on (expectedVersion); a stale view is refused
 * (409 CONFIRMARE_CHANGED), and every change increments the version. An Idempotency-Key replays the committed answer.
 *
 * Audit. Every change writes one append-only order_confirmation_events row in the same transaction (unique per order
 * and version): actor, before/after status and assignee, an enumerated reason code. No customer data, no free text.
 *
 * Effect. Only `approved` opens the production gate; approving never confirms a payment (the advance payment gate
 * still applies) and a payment never approves an order. Rejecting keeps the gate closed; nothing is cancelled in the
 * shop. An undetermined order (unknown submission instant) cannot be approved here. No source can be activated while
 * ConfirmationGate::WORKFLOW_READY is false, so in this release no order reaches this service outside tests.
 */
final class ConfirmareService
{
    public const MAX_OPEN_PER_OPERATOR = 10;
    public const POOL_LIMIT = 200;

    /** Rejection reasons (codes stored, Romanian labels for the screens). */
    public const REJECT_REASONS = [
        'customer_unreachable' => 'Clientul nu poate fi contactat',
        'customer_cancelled' => 'Clientul a renunțat la comandă',
        'order_details_wrong' => 'Detaliile comenzii nu sunt corecte',
        'duplicate_order' => 'Comandă duplicată',
        'payment_problem' => 'Problemă de plată',
        'other' => 'Alt motiv',
    ];

    /** Shop states in which an order can no longer be approved for production. */
    public const CLOSED_COMMERCE = ['cancelled', 'refunded', 'failed', 'trash'];

    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';
    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly ConfirmareScopePolicy $scopes,
        private readonly Clock $clock,
    ) {
    }

    // ---- Reads ---------------------------------------------------------------------------------------------------

    /**
     * The pool the actor works on.
     * - mine: pending orders assigned to the actor;
     * - open: pending orders the actor can take or holds (operate: unassigned or own; supervise: every pending order);
     * - all:  supervise only, every order of the supervised sources, newest first.
     *
     * @return array<string, mixed>
     */
    public function pool(EmployeeIdentity $actor, ?string $source, string $view): array
    {
        $this->requireActor($actor);
        if (!in_array($view, ['mine', 'open', 'all'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'view must be mine, open or all.');
        }
        if ($source !== null && preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'source is invalid.');
        }
        $operate = $this->scopes->sources($actor, ConfirmareScopePolicy::OPERATE);
        $supervise = $this->scopes->sources($actor, ConfirmareScopePolicy::SUPERVISE);
        if ($operate === [] && $supervise === []) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'You have no Confirmare scope.');
        }
        if ($view === 'all' && $supervise === []) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'Only a Confirmare supervisor sees every order.');
        }
        $parameters = [];
        $where = match ($view) {
            'mine' => "c.status = 'pending' AND c.assigned_employee_uuid = ?",
            'open' => "c.status = 'pending' AND ((" . ConfirmareScopePolicy::condition($operate, 'c.source_key', $parameters)
                . " AND o.workflow_cohort = 'confirmare' AND (c.assigned_employee_uuid IS NULL OR c.assigned_employee_uuid = ?)) OR "
                . ConfirmareScopePolicy::condition($supervise, 'c.source_key', $parameters) . ')',
            default => ConfirmareScopePolicy::condition($supervise, 'c.source_key', $parameters),
        };
        if ($view === 'mine') {
            $parameters[] = $actor->employeeUuid;
        } elseif ($view === 'open') {
            // The actor id belongs to the first condition; positional parameters are spliced after its sources.
            $operateCount = $operate === null ? 0 : count($operate);
            array_splice($parameters, $operateCount, 0, [$actor->employeeUuid]);
        }
        $visible = [];
        $where .= ' AND (' . ConfirmareScopePolicy::condition($this->union($operate, $supervise), 'c.source_key', $visible) . ')';
        array_push($parameters, ...$visible);
        if ($source !== null) {
            $where .= ' AND c.source_key = ?';
            $parameters[] = $source;
        }
        $order = $view === 'all' ? 'c.updated_at DESC, c.order_uuid' : 'c.opened_at, c.order_uuid';
        $statement = $this->pdo->prepare($this->selectSql() . " WHERE {$where} ORDER BY {$order} LIMIT " . self::POOL_LIMIT);
        $statement->execute($parameters);
        $items = array_map(fn (array $row): array => $this->present($row), $statement->fetchAll(PDO::FETCH_ASSOC));
        return [
            'view' => $view,
            'items' => $items,
            'limit' => self::POOL_LIMIT,
            'capabilities' => ['operate' => $operate, 'supervise' => $supervise],
            'rejectReasons' => self::REJECT_REASONS,
            'maxOpenPerOperator' => self::MAX_OPEN_PER_OPERATOR,
        ];
    }

    /** One order's Confirmare state and its audit trail (actor names, no customer data). @return array<string, mixed> */
    public function detail(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireActor($actor);
        $statement = $this->pdo->prepare($this->selectSql() . ' WHERE o.global_order_id = ?');
        $statement->execute([$this->globalId($globalOrderId)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scopes->sees($actor, (string) $row['source_key'])) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $events = $this->pdo->prepare(
            'SELECT ev.action, ev.actor_kind, a.display_name AS actor_name, ev.previous_status, ev.new_status, pa.display_name AS previous_assignee, na.display_name AS new_assignee,
                    ev.reason_code, ev.version_after, ev.occurred_at
             FROM order_confirmation_events ev
             LEFT JOIN employees a ON a.employee_uuid = ev.actor_employee_uuid
             LEFT JOIN employees pa ON pa.employee_uuid = ev.previous_assignee_uuid
             LEFT JOIN employees na ON na.employee_uuid = ev.new_assignee_uuid
             WHERE ev.order_uuid = ? ORDER BY ev.version_after',
        );
        $events->execute([(string) $row['order_uuid']]);
        return $this->present($row) + ['events' => array_map(static fn (array $event): array => [
            'action' => (string) $event['action'],
            'actor' => $event['actor_kind'] === 'system' ? null : (string) $event['actor_name'],
            'previousStatus' => $event['previous_status'],
            'status' => (string) $event['new_status'],
            'previousAssignee' => $event['previous_assignee'],
            'assignee' => $event['new_assignee'],
            'reasonCode' => $event['reason_code'],
            'version' => (int) $event['version_after'],
            'at' => self::iso((string) $event['occurred_at']),
        ], $events->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** The actor's own availability and load. @return array<string, mixed> */
    public function availability(EmployeeIdentity $actor): array
    {
        $this->requireActor($actor);
        return $this->availabilityOf($actor->employeeUuid);
    }

    // ---- Commands ------------------------------------------------------------------------------------------------

    /**
     * Pull distribution: assigns the oldest unassigned Confirmare order of the actor's operate sources (or of `source`)
     * to the actor, within MAX_OPEN_PER_OPERATOR. Answers {assigned: null} when nothing is waiting.
     *
     * @return array<string, mixed>
     */
    public function next(EmployeeIdentity $actor, ?string $source, string $key, string $requestId): array
    {
        $this->requireActor($actor);
        $this->requireKey($key);
        if ($source !== null && preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'source is invalid.');
        }
        $hash = $this->hash('next', [$source]);
        return $this->retrying(function () use ($actor, $source, $key, $hash, $requestId): array {
            $this->pdo->beginTransaction();
            try {
                $this->lockOperator($actor->employeeUuid);
                $replay = $this->replay($actor->employeeUuid, $key, 'next', null, $hash);
                if ($replay !== null) {
                    $this->pdo->rollBack();
                    return $replay;
                }
                $sources = $this->scopes->sources($actor, ConfirmareScopePolicy::OPERATE, true);
                if ($actor->isRoot || $sources === [] || ($source !== null && !in_array($source, $sources, true))) {
                    throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'You do not receive Confirmare orders of this source.');
                }
                $this->requireCapacity($actor->employeeUuid);
                $now = $this->now();
                $assigned = null;
                foreach ($this->candidates($source === null ? $sources : [$source]) as $orderUuid) {
                    $assigned = $this->assignLocked($orderUuid, $actor->employeeUuid, 'auto', $actor->employeeUuid, 'assigned', $now, $requestId, $key);
                    if ($assigned !== null) {
                        break;
                    }
                }
                $response = ['assigned' => $assigned];
                $this->store($actor->employeeUuid, $key, 'next', null, $hash, $response, $now);
                $this->pdo->commit();
                return $response;
            } catch (\Throwable $error) {
                $this->rollBack();
                throw $error;
            }
        });
    }

    /** @return array<string, mixed> */
    public function claim(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $key, string $requestId): array
    {
        return $this->command($actor, 'claim', $globalOrderId, $expectedVersion, [], $key, $requestId, true, function (array $order, array $confirmation, string $now) use ($actor, $key, $requestId): array {
            if (!$this->scopes->allows($actor, ConfirmareScopePolicy::OPERATE, (string) $order['source_key'], true) || $actor->isRoot) {
                throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'You do not receive Confirmare orders of this source.');
            }
            $this->requirePending($confirmation);
            if ($order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE) {
                throw new ApiException(409, 'CONFIRMARE_UNDETERMINED', 'The submission instant of this order is not known; a supervisor reviews it.');
            }
            if ($confirmation['assigned_employee_uuid'] === $actor->employeeUuid) {
                return [];
            }
            if ($confirmation['assigned_employee_uuid'] !== null) {
                throw new ApiException(409, 'CONFIRMARE_ASSIGNED_TO_OTHER', 'Another colleague is confirming this order.');
            }
            $this->requireCapacity($actor->employeeUuid);
            $this->assign($order, $confirmation, $actor->employeeUuid, 'claim', $actor->employeeUuid, 'claimed', $now, $requestId, $key);
            return [];
        });
    }

    /** @return array<string, mixed> */
    public function release(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $key, string $requestId): array
    {
        return $this->command($actor, 'release', $globalOrderId, $expectedVersion, [], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $key, $requestId): array {
            $this->requirePending($confirmation);
            $assignee = $confirmation['assigned_employee_uuid'];
            if ($assignee === null) {
                return [];
            }
            if ($assignee !== $actor->employeeUuid && !$this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, (string) $order['source_key'], true)) {
                throw new ApiException(409, 'CONFIRMARE_ASSIGNED_TO_OTHER', 'Another colleague is confirming this order.');
            }
            $this->change($order, $confirmation, ['assigned_employee_uuid' => null, 'assigned_at' => null, 'assignment_kind' => null], $actor->employeeUuid, 'released', null, $now, $requestId, $key);
            return [];
        });
    }

    /** Supervisor: give the order to a colleague who holds `operate` for its source and can work now. @return array<string, mixed> */
    public function reassign(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, mixed $employeeId, string $key, string $requestId): array
    {
        if (!is_string($employeeId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $employeeId) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'employeeId is required.');
        }
        return $this->command($actor, 'reassign', $globalOrderId, $expectedVersion, [$employeeId], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $employeeId, $key, $requestId): array {
            $this->requireSupervisor($actor, (string) $order['source_key']);
            $this->requirePending($confirmation);
            if ($order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE) {
                throw new ApiException(409, 'CONFIRMARE_UNDETERMINED', 'The submission instant of this order is not known; it is not distributed.');
            }
            if ($confirmation['assigned_employee_uuid'] === $employeeId) {
                return [];
            }
            if (!$this->scopes->canWork($employeeId, ConfirmareScopePolicy::OPERATE, (string) $order['source_key'], true)) {
                throw new ApiException(409, 'CONFIRMARE_OPERATOR_INELIGIBLE', 'The colleague does not confirm orders of this source or cannot work now.');
            }
            $this->assign($order, $confirmation, $employeeId, 'supervisor', $actor->employeeUuid, 'reassigned', $now, $requestId, $key);
            return [];
        });
    }

    /** @return array<string, mixed> */
    public function approve(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $key, string $requestId): array
    {
        return $this->command($actor, 'approve', $globalOrderId, $expectedVersion, [], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $key, $requestId): array {
            $this->requireDecider($actor, $order, $confirmation);
            if ($order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE) {
                throw new ApiException(409, 'CONFIRMARE_UNDETERMINED', 'The submission instant of this order is not known; it cannot be approved for production here.');
            }
            if (in_array((string) $order['source_commerce_status_code'], self::CLOSED_COMMERCE, true) || $order['operational_status'] === 'unavailable') {
                throw new ApiException(409, 'CONFIRMARE_ORDER_CLOSED', 'The order is cancelled or no longer available in the shop; it cannot be approved.');
            }
            $this->change($order, $confirmation, [
                'status' => self::STATUS_APPROVED, 'decided_at' => $now, 'decided_by_employee_uuid' => $actor->employeeUuid, 'decision_reason_code' => null,
                'assigned_employee_uuid' => null, 'assigned_at' => null, 'assignment_kind' => null,
            ], $actor->employeeUuid, 'approved', null, $now, $requestId, $key);
            return [];
        });
    }

    /** @return array<string, mixed> */
    public function reject(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, mixed $reasonCode, string $key, string $requestId): array
    {
        if (!is_string($reasonCode) || !array_key_exists($reasonCode, self::REJECT_REASONS)) {
            throw new ApiException(422, 'INVALID_REASON', 'reasonCode must be one of: ' . implode(', ', array_keys(self::REJECT_REASONS)) . '.');
        }
        return $this->command($actor, 'reject', $globalOrderId, $expectedVersion, [$reasonCode], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $reasonCode, $key, $requestId): array {
            $this->requireDecider($actor, $order, $confirmation);
            $this->change($order, $confirmation, [
                'status' => self::STATUS_REJECTED, 'decided_at' => $now, 'decided_by_employee_uuid' => $actor->employeeUuid, 'decision_reason_code' => $reasonCode,
                'assigned_employee_uuid' => null, 'assigned_at' => null, 'assignment_kind' => null,
            ], $actor->employeeUuid, 'rejected', $reasonCode, $now, $requestId, $key);
            return [];
        });
    }

    /** Supervisor: a rejected order returns to the pool (unassigned). An approval is never reopened here. @return array<string, mixed> */
    public function reopen(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $key, string $requestId): array
    {
        return $this->command($actor, 'reopen', $globalOrderId, $expectedVersion, [], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $key, $requestId): array {
            $this->requireSupervisor($actor, (string) $order['source_key']);
            if ($confirmation['status'] !== self::STATUS_REJECTED) {
                throw new ApiException(409, 'CONFIRMARE_NOT_REJECTED', 'Only a rejected order can be reopened.');
            }
            $this->change($order, $confirmation, [
                'status' => self::STATUS_PENDING, 'decided_at' => null, 'decided_by_employee_uuid' => null, 'decision_reason_code' => null,
            ], $actor->employeeUuid, 'reopened', null, $now, $requestId, $key);
            return [];
        });
    }

    /**
     * Whether an operator accepts automatic assignments. Self, or a supervisor of one of the operator's sources. Going
     * unavailable returns the operator's pending orders to the pool at once (one audited `released` each).
     *
     * @return array<string, mixed>
     */
    public function setAvailability(EmployeeIdentity $actor, ?string $employeeId, mixed $accepting, string $key, string $requestId): array
    {
        $this->requireActor($actor);
        $this->requireKey($key);
        if (!is_bool($accepting)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'accepting must be true or false.');
        }
        $target = $employeeId ?? $actor->employeeUuid;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $target) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'employeeId is invalid.');
        }
        $hash = $this->hash('availability', [$target, $accepting]);
        return $this->retrying(function () use ($actor, $target, $accepting, $key, $hash, $requestId): array {
            $this->pdo->beginTransaction();
            try {
                $this->lockOperator($target);
                $replay = $this->replay($actor->employeeUuid, $key, 'availability', null, $hash);
                if ($replay !== null) {
                    $this->pdo->rollBack();
                    return $replay;
                }
                $operates = $this->operatesSources($target);
                if ($target === $actor->employeeUuid) {
                    if ($operates === [] || $actor->isRoot) {
                        throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'You do not receive Confirmare orders.');
                    }
                } else {
                    $supervised = $this->scopes->sources($actor, ConfirmareScopePolicy::SUPERVISE, true);
                    if ($operates === [] || ($supervised !== null && array_intersect($operates, $supervised) === [])) {
                        throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'You do not supervise this colleague\'s Confirmare sources.');
                    }
                }
                $now = $this->now();
                $this->pdo->prepare('UPDATE confirmare_operator_state SET accepting = ?, accepting_changed_at = ?, accepting_changed_by_employee_uuid = ? WHERE employee_uuid = ?')
                    ->execute([$accepting ? 1 : 0, $now, $actor->employeeUuid, $target]);
                $released = 0;
                if (!$accepting) {
                    $released = $this->releaseAll($target, $actor->employeeUuid, 'operator_unavailable', $now, $requestId, $key);
                }
                $response = $this->availabilityOf($target) + ['released' => $released];
                $this->store($actor->employeeUuid, $key, 'availability', null, $hash, $response, $now);
                $this->pdo->commit();
                return $response;
            } catch (\Throwable $error) {
                $this->rollBack();
                throw $error;
            }
        });
    }

    // ---- System distribution (bin/confirmare-distribute.php) -------------------------------------------------------

    /**
     * Returns to the pool every pending assignment whose assignee can no longer work on it (scope removed, account or
     * department inactive, application removed), then assigns unassigned Confirmare orders to accepting operators,
     * one at a time, fewest open assignments first, oldest order first, within MAX_OPEN_PER_OPERATOR. Each assignment
     * is its own short transaction. `dryRun` reads only.
     *
     * @return array{released: int, assigned: int, waiting: array<string, int>, operators: array<string, int>}
     */
    public function distribute(?string $source = null, bool $dryRun = false, int $maxAssignments = 500): array
    {
        $released = $dryRun ? count($this->ineligibleAssignments()) : $this->reclaim();
        $assigned = 0;
        $sources = $this->waitingSources($source);
        while (!$dryRun && $assigned < $maxAssignments) {
            $progress = false;
            foreach ($this->waitingSources($source) as $sourceKey => $waiting) {
                foreach ($this->scopes->distributionTargets($sourceKey) as $target) {
                    if ($target['open'] >= self::MAX_OPEN_PER_OPERATOR) {
                        continue;
                    }
                    if ($this->assignOne($sourceKey, $target['employee_uuid'])) {
                        $assigned++;
                        $progress = true;
                    }
                    break;
                }
            }
            if (!$progress) {
                break;
            }
        }
        $operators = [];
        foreach (array_keys($sources) as $sourceKey) {
            $operators[$sourceKey] = count($this->scopes->distributionTargets($sourceKey));
        }
        return ['released' => $released, 'assigned' => $assigned, 'waiting' => $this->waitingSources($source), 'operators' => $operators];
    }

    // ---- Internals -------------------------------------------------------------------------------------------------

    /**
     * One command on one order: lock the operator (when `lockActor`), the order and its confirmation; replay; check the
     * version; run the change; store the answer.
     *
     * @param list<mixed> $intent
     * @param callable(array<string, mixed>, array<string, mixed>, string): array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function command(EmployeeIdentity $actor, string $operation, string $globalOrderId, mixed $expectedVersion, array $intent, string $key, string $requestId, bool $lockActor, callable $change): array
    {
        $this->requireActor($actor);
        $this->requireKey($key);
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        $globalId = $this->globalId($globalOrderId);
        $hash = $this->hash($operation, [$globalId, $expectedVersion, ...$intent]);
        return $this->retrying(function () use ($actor, $operation, $globalId, $expectedVersion, $key, $hash, $requestId, $lockActor, $change): array {
            $this->pdo->beginTransaction();
            try {
                if ($lockActor) {
                    $this->lockOperator($actor->employeeUuid);
                }
                $order = $this->lockOrder($globalId);
                $replay = $order === null ? null : $this->replay($actor->employeeUuid, $key, $operation, (string) $order['order_uuid'], $hash);
                if ($replay !== null) {
                    $this->pdo->rollBack();
                    return $replay;
                }
                $confirmation = $order === null ? null : $this->lockConfirmation((string) $order['order_uuid']);
                if ($order === null || $confirmation === null || !$this->scopes->sees($actor, (string) $order['source_key'], true)) {
                    throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
                }
                if ((int) $confirmation['version'] !== $expectedVersion) {
                    throw new ApiException(409, 'CONFIRMARE_CHANGED', 'The Confirmare state changed. Reload it before continuing.', ['currentVersion' => (int) $confirmation['version']]);
                }
                $now = $this->now();
                $change($order, $confirmation, $now);
                $response = $this->current((string) $order['order_uuid']);
                $this->store($actor->employeeUuid, $key, $operation, (string) $order['order_uuid'], $hash, $response, $now);
                $this->pdo->commit();
                return $response;
            } catch (\Throwable $error) {
                $this->rollBack();
                throw $error;
            }
        });
    }

    /**
     * Applies column changes to a locked confirmation, increments its version and writes one audit event.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $confirmation
     * @param array<string, mixed> $set
     */
    private function change(array $order, array $confirmation, array $set, ?string $actorUuid, string $action, ?string $reasonCode, string $now, string $requestId, ?string $key): void
    {
        $version = (int) $confirmation['version'] + 1;
        $columns = [];
        $values = [];
        foreach ($set as $column => $value) {
            $columns[] = "{$column} = ?";
            $values[] = $value;
        }
        $statement = $this->pdo->prepare('UPDATE order_confirmations SET ' . implode(', ', $columns) . ', version = ?, updated_at = ? WHERE order_uuid = ? AND version = ?');
        $statement->execute([...$values, $version, $now, (string) $order['order_uuid'], (int) $confirmation['version']]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The locked Confirmare row changed unexpectedly.');
        }
        $this->pdo->prepare(
            'INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, previous_status, new_status,
                previous_assignee_uuid, new_assignee_uuid, reason_code, version_after, request_id, idempotency_key, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), (string) $order['order_uuid'], (string) $order['global_order_id'], (string) $order['source_key'], $action,
            $actorUuid === null ? 'system' : 'employee', $actorUuid, (string) $confirmation['status'], (string) ($set['status'] ?? $confirmation['status']),
            $confirmation['assigned_employee_uuid'], array_key_exists('assigned_employee_uuid', $set) ? $set['assigned_employee_uuid'] : $confirmation['assigned_employee_uuid'],
            $reasonCode, $version, mb_substr($requestId, 0, 100), $key,
            $now,
        ]);
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $confirmation */
    private function assign(array $order, array $confirmation, string $assignee, string $kind, ?string $actorUuid, string $action, string $now, string $requestId, ?string $key): void
    {
        $this->change($order, $confirmation, ['assigned_employee_uuid' => $assignee, 'assigned_at' => $now, 'assignment_kind' => $kind], $actorUuid, $action, null, $now, $requestId, $key);
        if ($kind !== 'supervisor') {
            // The caller holds this operator's lock (taken before the order lock). A supervisor's reassignment holds only
            // the order lock and never takes an operator lock after it, so the two lock orders can never cross.
            $this->pdo->prepare('UPDATE confirmare_operator_state SET last_assigned_at = ? WHERE employee_uuid = ?')->execute([$now, $assignee]);
        }
    }

    /**
     * Locks one candidate order and its confirmation and assigns it when it is still an unassigned pending Confirmare
     * order the assignee may receive. The caller holds the assignee's operator lock. @return array<string, mixed>|null
     */
    private function assignLocked(string $orderUuid, string $assignee, string $kind, ?string $actorUuid, string $action, string $now, string $requestId, ?string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key, workflow_cohort, source_commerce_status_code, operational_status FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        $confirmation = is_array($order) ? $this->lockConfirmation($orderUuid) : null;
        if (!is_array($order) || $confirmation === null || $confirmation['status'] !== self::STATUS_PENDING || $confirmation['assigned_employee_uuid'] !== null
            || $order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE || !$this->scopes->canWork($assignee, ConfirmareScopePolicy::OPERATE, (string) $order['source_key'], true)) {
            return null;
        }
        $this->assign($order, $confirmation, $assignee, $kind, $actorUuid, $action, $now, $requestId, $key);
        return $this->current($orderUuid);
    }

    /** System assignment of the oldest unassigned order of a source to one operator, in its own transaction. */
    private function assignOne(string $sourceKey, string $operatorUuid): bool
    {
        return $this->retrying(function () use ($sourceKey, $operatorUuid): bool {
            $this->pdo->beginTransaction();
            try {
                $state = $this->lockOperator($operatorUuid);
                if ((int) $state['accepting'] !== 1 || $this->openCount($operatorUuid) >= self::MAX_OPEN_PER_OPERATOR) {
                    $this->pdo->rollBack();
                    return false;
                }
                $now = $this->now();
                foreach ($this->candidates([$sourceKey]) as $orderUuid) {
                    if ($this->assignLocked($orderUuid, $operatorUuid, 'auto', null, 'assigned', $now, 'confirmare-distribute', null) !== null) {
                        $this->pdo->commit();
                        return true;
                    }
                }
                $this->pdo->rollBack();
                return false;
            } catch (\Throwable $error) {
                $this->rollBack();
                throw $error;
            }
        });
    }

    /** Returns ineligible assignments to the pool (system `released`, reason operator_unavailable). */
    private function reclaim(): int
    {
        $released = 0;
        foreach ($this->ineligibleAssignments() as $row) {
            $released += $this->retrying(function () use ($row): int {
                $this->pdo->beginTransaction();
                try {
                    $this->lockOperator((string) $row['assigned_employee_uuid']);
                    $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
                    $statement->execute([(string) $row['order_uuid']]);
                    $order = $statement->fetch(PDO::FETCH_ASSOC);
                    $confirmation = $this->lockConfirmation((string) $row['order_uuid']);
                    if (!is_array($order) || $confirmation === null || $confirmation['status'] !== self::STATUS_PENDING || $confirmation['assigned_employee_uuid'] !== $row['assigned_employee_uuid']
                        || $this->keepsAssignment((string) $row['assigned_employee_uuid'], (string) $order['source_key'])) {
                        $this->pdo->rollBack();
                        return 0;
                    }
                    $now = $this->now();
                    $this->change($order, $confirmation, ['assigned_employee_uuid' => null, 'assigned_at' => null, 'assignment_kind' => null], null, 'released', 'operator_unavailable', $now, 'confirmare-distribute', null);
                    $this->pdo->commit();
                    return 1;
                } catch (\Throwable $error) {
                    $this->rollBack();
                    throw $error;
                }
            });
        }
        return $released;
    }

    /** @return list<array{order_uuid: string, assigned_employee_uuid: string}> */
    private function ineligibleAssignments(): array
    {
        $rows = $this->pdo->query("SELECT order_uuid, assigned_employee_uuid, source_key FROM order_confirmations WHERE status = 'pending' AND assigned_employee_uuid IS NOT NULL ORDER BY order_uuid")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, fn (array $row): bool => !$this->keepsAssignment((string) $row['assigned_employee_uuid'], (string) $row['source_key'])));
    }

    /** An assignee keeps an order while they can work with operate or supervise for its source. */
    private function keepsAssignment(string $employeeUuid, string $sourceKey): bool
    {
        return $this->scopes->canWork($employeeUuid, ConfirmareScopePolicy::OPERATE, $sourceKey) || $this->scopes->canWork($employeeUuid, ConfirmareScopePolicy::SUPERVISE, $sourceKey);
    }

    /** Releases every pending assignment of an operator whose operator row the caller locked. */
    private function releaseAll(string $operatorUuid, ?string $actorUuid, string $reason, string $now, string $requestId, ?string $key): int
    {
        $statement = $this->pdo->prepare("SELECT order_uuid FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending' ORDER BY order_uuid");
        $statement->execute([$operatorUuid]);
        $released = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $orderUuid) {
            $lock = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([(string) $orderUuid]);
            $order = $lock->fetch(PDO::FETCH_ASSOC);
            $confirmation = $this->lockConfirmation((string) $orderUuid);
            if (is_array($order) && $confirmation !== null && $confirmation['status'] === self::STATUS_PENDING && $confirmation['assigned_employee_uuid'] === $operatorUuid) {
                $this->change($order, $confirmation, ['assigned_employee_uuid' => null, 'assigned_at' => null, 'assignment_kind' => null], $actorUuid, 'released', $reason, $now, $requestId, $key);
                $released++;
            }
        }
        return $released;
    }

    /** Oldest unassigned pending Confirmare orders of the sources (non-locking; each is re-checked under its locks). @param list<string> $sources @return list<string> */
    private function candidates(array $sources): array
    {
        if ($sources === []) {
            return [];
        }
        $parameters = [];
        $condition = ConfirmareScopePolicy::condition($sources, 'c.source_key', $parameters);
        $statement = $this->pdo->prepare(
            "SELECT c.order_uuid FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
             WHERE {$condition} AND c.status = 'pending' AND c.assigned_employee_uuid IS NULL AND o.workflow_cohort = 'confirmare'
             ORDER BY c.opened_at, c.order_uuid LIMIT 5",
        );
        $statement->execute($parameters);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, int> unassigned distributable orders per source */
    private function waitingSources(?string $source): array
    {
        $parameters = [];
        $where = "c.status = 'pending' AND c.assigned_employee_uuid IS NULL AND o.workflow_cohort = 'confirmare'";
        if ($source !== null) {
            $where .= ' AND c.source_key = ?';
            $parameters[] = $source;
        }
        $statement = $this->pdo->prepare("SELECT c.source_key, COUNT(*) FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE {$where} GROUP BY c.source_key ORDER BY c.source_key");
        $statement->execute($parameters);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Creates (when missing) and locks the operator's state row. @return array<string, mixed> */
    private function lockOperator(string $employeeUuid): array
    {
        $this->pdo->prepare('INSERT INTO confirmare_operator_state (employee_uuid, accepting) VALUES (?, 0) ON DUPLICATE KEY UPDATE employee_uuid = employee_uuid')->execute([$employeeUuid]);
        $statement = $this->pdo->prepare('SELECT employee_uuid, accepting, last_assigned_at FROM confirmare_operator_state WHERE employee_uuid = ? FOR UPDATE');
        $statement->execute([$employeeUuid]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Operator state row is missing.');
    }

    /** @return array<string, mixed>|null */
    private function lockOrder(string $globalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key, workflow_cohort, production_stage_id, source_commerce_status_code, operational_status FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function lockConfirmation(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, status, version, assigned_employee_uuid FROM order_confirmations WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['assigned_employee_uuid'] = $row['assigned_employee_uuid'] === null ? null : (string) $row['assigned_employee_uuid'];
        return $row;
    }

    private function openCount(string $employeeUuid): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'");
        $statement->execute([$employeeUuid]);
        return (int) $statement->fetchColumn();
    }

    private function requireCapacity(string $employeeUuid): void
    {
        if ($this->openCount($employeeUuid) >= self::MAX_OPEN_PER_OPERATOR) {
            throw new ApiException(409, 'CONFIRMARE_CAPACITY_REACHED', 'Finish or release an order before taking another one.', ['maxOpen' => self::MAX_OPEN_PER_OPERATOR]);
        }
    }

    /** @param array<string, mixed> $confirmation */
    private function requirePending(array $confirmation): void
    {
        if ($confirmation['status'] !== self::STATUS_PENDING) {
            throw new ApiException(409, 'CONFIRMARE_NOT_PENDING', 'This order was already decided.', ['status' => (string) $confirmation['status']]);
        }
    }

    private function requireSupervisor(EmployeeIdentity $actor, string $sourceKey): void
    {
        if (!$this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, $sourceKey, true)) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'Only a Confirmare supervisor of this source may do this.');
        }
    }

    /** A decision is taken by the operator the order is assigned to, or by a supervisor of its source. */
    private function requireDecider(EmployeeIdentity $actor, array $order, array $confirmation): void
    {
        $this->requirePending($confirmation);
        if ($this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, (string) $order['source_key'], true)) {
            return;
        }
        if (!$this->scopes->allows($actor, ConfirmareScopePolicy::OPERATE, (string) $order['source_key'], true)) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'You do not confirm orders of this source.');
        }
        if ($confirmation['assigned_employee_uuid'] !== $actor->employeeUuid) {
            throw new ApiException(409, 'CONFIRMARE_NOT_ASSIGNED_TO_YOU', 'Take the order first; only its assigned colleague or a supervisor decides it.');
        }
    }

    private function requireActor(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, $actor->hasApplication('staff') ? 'staff' : 'dashboard');
    }

    private function requireKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D', $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
    }

    /** @return list<string> */
    private function operatesSources(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare("SELECT source_key FROM employee_confirmare_scopes WHERE employee_uuid = ? AND capability = 'operate' ORDER BY source_key LOCK IN SHARE MODE");
        $statement->execute([$employeeUuid]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    private function availabilityOf(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare('SELECT accepting, accepting_changed_at, last_assigned_at FROM confirmare_operator_state WHERE employee_uuid = ?');
        $statement->execute([$employeeUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ['accepting' => 0, 'accepting_changed_at' => null, 'last_assigned_at' => null];
        return [
            'employeeId' => $employeeUuid,
            'accepting' => (int) $row['accepting'] === 1,
            'changedAt' => $row['accepting_changed_at'] === null ? null : self::iso((string) $row['accepting_changed_at']),
            'lastAssignedAt' => $row['last_assigned_at'] === null ? null : self::iso((string) $row['last_assigned_at']),
            'open' => $this->openCount($employeeUuid),
            'maxOpen' => self::MAX_OPEN_PER_OPERATOR,
            'sources' => $this->scopes->scopesOf($employeeUuid),
        ];
    }

    /** @return array<string, mixed> */
    private function current(string $orderUuid): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . ' WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->present($row) : throw new RuntimeException('Confirmare row disappeared.');
    }

    private function selectSql(): string
    {
        return 'SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.workflow_cohort, o.source_submitted_at, o.production_stage_id, o.source_commerce_status_code,
                       o.operational_status, o.payment_method, o.advance_status,
                       c.status, c.version, c.opened_at, c.decided_at, c.decision_reason_code, c.assigned_employee_uuid, c.assigned_at, c.assignment_kind,
                       ae.display_name AS assignee_name, de.display_name AS decider_name, c.decided_by_employee_uuid
                FROM order_confirmations c
                INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
                LEFT JOIN employees ae ON ae.employee_uuid = c.assigned_employee_uuid
                LEFT JOIN employees de ON de.employee_uuid = c.decided_by_employee_uuid';
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        return [
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'sourceKey' => (string) $row['source_key'],
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'workflowCohort' => (string) $row['workflow_cohort'],
            'openedAt' => self::iso((string) $row['opened_at']),
            'submittedAt' => $row['source_submitted_at'] === null ? null : self::iso((string) $row['source_submitted_at']),
            'productionStageId' => (string) $row['production_stage_id'],
            'commerceStatus' => $row['source_commerce_status_code'],
            'commerceClosed' => in_array((string) $row['source_commerce_status_code'], self::CLOSED_COMMERCE, true) || $row['operational_status'] === 'unavailable',
            'payment' => ['state' => PaymentGate::state($row['payment_method'], $row['advance_status'])],
            'assignment' => $row['assigned_employee_uuid'] === null ? null : [
                'employeeId' => (string) $row['assigned_employee_uuid'],
                'displayName' => (string) $row['assignee_name'],
                'at' => self::iso((string) $row['assigned_at']),
                'kind' => (string) $row['assignment_kind'],
            ],
            'decision' => $row['decided_at'] === null ? null : [
                'at' => self::iso((string) $row['decided_at']),
                'by' => $row['decider_name'] === null ? null : (string) $row['decider_name'],
                'reasonCode' => $row['decision_reason_code'],
            ],
        ];
    }

    /** @param list<string>|null $a @param list<string>|null $b @return list<string>|null */
    private function union(?array $a, ?array $b): ?array
    {
        if ($a === null || $b === null) {
            return null;
        }
        $all = array_values(array_unique([...$a, ...$b]));
        sort($all);
        return $all;
    }

    /** @param list<mixed> $intent */
    private function hash(string $operation, array $intent): string
    {
        return hash('sha256', $operation . '|' . json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    /** @return array<string, mixed>|null */
    private function replay(string $actorUuid, string $key, string $operation, ?string $orderUuid, string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, order_uuid, request_hash, response_json FROM order_confirmation_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$actorUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['operation'] !== $operation || $row['order_uuid'] !== $orderUuid || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
        }
        try {
            $decoded = json_decode((string) $row['response_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        return is_array($decoded) ? $decoded : throw new RuntimeException('Stored idempotent result is unreadable.');
    }

    /** @param array<string, mixed> $response */
    private function store(string $actorUuid, string $key, string $operation, ?string $orderUuid, string $hash, array $response, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO order_confirmation_idempotency (employee_uuid, idempotency_key, operation, order_uuid, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->bindValue(1, $actorUuid);
        $statement->bindValue(2, $key);
        $statement->bindValue(3, $operation);
        $statement->bindValue(4, $orderUuid);
        $statement->bindValue(5, $hash, PDO::PARAM_LOB);
        $statement->bindValue(6, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $statement->bindValue(7, $now);
        $statement->execute();
    }

    /**
     * @template T
     * @param callable(): T $attempt
     * @return T
     */
    private function retrying(callable $attempt): mixed
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

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
