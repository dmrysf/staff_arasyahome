<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cancellation\ProductionStop;
use Arasya\Operations\Document\DocumentScopePolicy;
use Arasya\Operations\Document\DocumentService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Production\ConfirmationGate;
use Arasya\Operations\Production\PaymentGate;
use Arasya\Operations\Production\ProductionAuthorityModes;
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
 * Effect. `approved` alone never opens production (owner rule): ConfirmationClearance also needs the confirmed customer
 * call, every cart line confirmed, the source's pricing fingerprint the approval was given on, an unchanged review and
 * the financial condition (whatever the payment gate mode). Approving never confirms a payment; a payment never approves. Rejecting keeps the gate closed; nothing is cancelled in the
 * shop. An undetermined order (unknown submission instant) cannot be approved here. No source can be activated while
 * Orders reach this service only from a source whose Confirmare activation passed its readiness (ConfirmareReadiness).
 *
 * Handoff (API 2.42.0, Phase 6): the order detail carries its production handoff state (ConfirmareHandoff), and
 * handoffList() lists the cleared orders at stage 1 for production document employees. The list needs no Confirmare
 * scope (a document employee never joins the Confirmare distribution, which follows the `operate` scope only): it needs
 * the document generate permission and lists only the sources of the actor's document `operate` scope.
 */
final class ConfirmareService
{
    /**
     * Balance target per operator, never a hard limit (owner rule: a strict cap must not block the queue). Distribution
     * prefers operators below it; when every operator is at or above it, the least loaded still receives the order.
     */
    public const TARGET_OPEN_PER_OPERATOR = 10;
    /** An assignment without any change for this long raises one supervisor alert; it is never reassigned for it. */
    public const INACTIVITY_ALERT_MINUTES = 120;
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

    /**
     * Outcomes of a recorded telephone contact attempt (API 2.39.0, order_confirmation_contacts). Codes only: no free
     * text, no duration, no recording. Not answering is never a cancellation.
     */
    public const CONTACT_OUTCOMES = ['confirmed', 'no_answer', 'callback', 'needs_correction'];

    /** Shop states in which an order can no longer be approved for production. */
    public const CLOSED_COMMERCE = ['cancelled', 'refunded', 'failed', 'trash'];

    /** Due date first (orders without one after), then stable FIFO. */
    private const PRIORITY_ORDER = 'o.source_due_date IS NULL, o.source_due_date, c.opened_at, c.order_uuid';
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
        /** API 2.40.0: shops with source access configured; their approvals need the approver's shop read. */
        private readonly ?SourceAccessRegistry $sourceAccess = null,
        /** API 2.42.0: the sources' enforced advance payment gates, for the handoff state. */
        private readonly ?ProductionAuthorityModes $modes = null,
    ) {
    }

    // ---- Reads ---------------------------------------------------------------------------------------------------

    /**
     * The pool the actor works on.
     * - mine: pending orders assigned to the actor;
     * - open: pending orders the actor can take or holds (operate: unassigned or own; supervise: every pending order);
     * - all:  supervise only, every order of the supervised sources, newest first;
     * - followup (API 2.39.0): approved orders whose production is not cleared yet (waiting for the advance, or changed
     *   in the shop after the review): an operator's own approvals, every one of the supervised sources.
     *
     * @return array<string, mixed>
     */
    public function pool(EmployeeIdentity $actor, ?string $source, string $view): array
    {
        $this->requireActor($actor);
        if (!in_array($view, ['mine', 'open', 'all', 'followup'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'view must be mine, open, all or followup.');
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
            'followup' => "c.status = 'approved' AND COALESCE(" . ConfirmationGate::CLEARED_SQL . ", 0) = 0 AND (c.decided_by_employee_uuid = ? OR " . ConfirmareScopePolicy::condition($supervise, 'c.source_key', $parameters) . ')',
            'open' => "c.status = 'pending' AND ((" . ConfirmareScopePolicy::condition($operate, 'c.source_key', $parameters)
                . " AND o.workflow_cohort = 'confirmare' AND (c.assigned_employee_uuid IS NULL OR c.assigned_employee_uuid = ?)) OR "
                . ConfirmareScopePolicy::condition($supervise, 'c.source_key', $parameters) . ')',
            default => ConfirmareScopePolicy::condition($supervise, 'c.source_key', $parameters),
        };
        if ($view === 'mine') {
            $parameters[] = $actor->employeeUuid;
        } elseif ($view === 'followup') {
            array_unshift($parameters, $actor->employeeUuid);
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
        $order = $view === 'all' ? 'c.updated_at DESC, c.order_uuid' : self::PRIORITY_ORDER;
        $statement = $this->pdo->prepare($this->selectSql() . " WHERE {$where} ORDER BY {$order} LIMIT " . self::POOL_LIMIT);
        $statement->execute($parameters);
        $items = array_map(fn (array $row): array => $this->present($row), $statement->fetchAll(PDO::FETCH_ASSOC));
        return [
            'view' => $view,
            'items' => $items,
            'limit' => self::POOL_LIMIT,
            'capabilities' => ['operate' => $operate, 'supervise' => $supervise],
            'rejectReasons' => self::REJECT_REASONS,
            'targetOpenPerOperator' => self::TARGET_OPEN_PER_OPERATOR,
        ];
    }

    /** One order's Confirmare state and its audit trail (actor names, no customer data). @return array<string, mixed> */
    public function detail(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireActor($actor);
        $statement = $this->pdo->prepare($this->selectSql() . ' WHERE o.global_order_id = ?');
        $statement->execute([$this->globalId($globalOrderId)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        // API 2.41.0: a finance employee of the source opens the order too (read-only: no customer data, no decision;
        // every Confirmare command still checks the Confirmare scopes).
        if (!is_array($row) || (!$this->scopes->sees($actor, (string) $row['source_key']) && !$this->financeViewer($actor, (string) $row['source_key']))) {
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
        $orderUuid = (string) $row['order_uuid'];
        $fingerprint = ConfirmationClearance::fingerprint($this->pdo, $orderUuid);
        $review = [
            'fingerprint' => $fingerprint,
            'lineIds' => ConfirmationClearance::lineIds($this->pdo, $orderUuid),
            'pricingAvailable' => $row['source_pricing_fingerprint'] !== null,
            // API 2.39.0: the latest recorded contact attempt is `confirmed` on this review (approval needs it).
            'callRecorded' => $this->recordedCall($orderUuid, $fingerprint) !== null,
            // Whether the shop sent a telephone number for the customer (Arasya keeps only its masked form). Without
            // one nobody can have confirmed the order on the telephone, so neither a confirmed call nor an approval.
            'contactAvailable' => $this->contactAvailable($orderUuid),
        ];
        return $this->present($row) + [
            'review' => $review,
            'lines' => $this->lines($orderUuid),
            'contacts' => $this->contacts($orderUuid, $fingerprint),
            'customer' => $this->customer($actor, $row),
            'source' => [
                'commerceStatusLabel' => $row['source_commerce_status_label'] === null ? null : (string) $row['source_commerce_status_label'],
                'changedAt' => $row['source_changed_at'] === null ? null : self::iso((string) $row['source_changed_at']),
                'lastSeenAt' => $row['last_source_seen_at'] === null ? null : self::iso((string) $row['last_source_seen_at']),
                'freshness' => $row['freshness_status'] === null ? null : (string) $row['freshness_status'],
            ],
            'production' => ['cleared' => (int) $row['production_cleared'] === 1, 'missing' => ConfirmationClearance::missing($this->pdo, $orderUuid)],
            'handoff' => $this->handoff($actor, $orderUuid, (string) $row['source_key']),
            'events' => array_map(static fn (array $event): array => [
                'action' => (string) $event['action'],
                'actor' => $event['actor_kind'] === 'system' ? null : (string) $event['actor_name'],
                'previousStatus' => $event['previous_status'],
                'status' => (string) $event['new_status'],
                'previousAssignee' => $event['previous_assignee'],
                'assignee' => $event['new_assignee'],
                'reasonCode' => $event['reason_code'],
                'version' => (int) $event['version_after'],
                'at' => self::iso((string) $event['occurred_at']),
            ], $events->fetchAll(PDO::FETCH_ASSOC)),
        ];
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
     * to the actor (due date first, then FIFO). No hard cap: the balance target only steers the automatic distribution.
     * A suspended source holds its orders: nothing is handed out. Answers {assigned: null} when nothing is waiting.
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
                if ($source !== null && $this->held($source)) {
                    throw new ApiException(409, 'CONFIRMARE_SOURCE_SUSPENDED', 'The source is suspended: its orders are held.');
                }
                $sources = array_values(array_filter($source === null ? $sources : [$source], fn (string $key): bool => !$this->held($key)));
                $now = $this->now();
                $assigned = null;
                foreach ($this->candidates($sources) as $orderUuid) {
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
            if ($this->held((string) $order['source_key'])) {
                throw new ApiException(409, 'CONFIRMARE_SOURCE_SUSPENDED', 'The source is suspended: its orders are held.');
            }
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

    /**
     * Approval with its production checklist (owner rule). The body states the confirmed customer call
     * (`customerCall: "confirmed"`), every confirmed cart line (`confirmedLineIds`: exactly the order's current lines),
     * `priceVerified: true` against the shop, and the `reviewFingerprint` the operator saw (detail.review.fingerprint).
     * The approval is bound to that review; ConfirmationClearance opens production only while it still matches and the
     * financial condition is met.
     *
     * @param array<string, mixed> $checklist
     * @return array<string, mixed>
     */
    public function approve(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, array $checklist, string $key, string $requestId): array
    {
        if (($checklist['customerCall'] ?? null) !== 'confirmed') {
            throw new ApiException(422, 'CONFIRMARE_CALL_REQUIRED', 'Record the confirmed telephone call with the customer (customerCall: "confirmed").');
        }
        if (($checklist['priceVerified'] ?? null) !== true) {
            throw new ApiException(422, 'CONFIRMARE_PRICE_NOT_VERIFIED', 'Verify the prices against the shop order first (priceVerified: true).');
        }
        $lines = $checklist['confirmedLineIds'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 200 || array_filter($lines, static fn (mixed $v): bool => !is_string($v) || $v === '' || strlen($v) > 128) !== []) {
            throw new ApiException(422, 'CONFIRMARE_LINES_NOT_CONFIRMED', 'Confirm every cart line (confirmedLineIds).');
        }
        $fingerprint = $checklist['reviewFingerprint'] ?? null;
        if (!is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_REVIEW_REQUIRED', 'reviewFingerprint (the review the decision is based on) is required.');
        }
        sort($lines, SORT_STRING);
        return $this->command($actor, 'approve', $globalOrderId, $expectedVersion, [$lines, $fingerprint], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $lines, $fingerprint, $key, $requestId): array {
            $this->requireDecider($actor, $order, $confirmation);
            if ($order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE) {
                throw new ApiException(409, 'CONFIRMARE_UNDETERMINED', 'The submission instant of this order is not known; it cannot be approved for production here.');
            }
            if (in_array((string) $order['source_commerce_status_code'], self::CLOSED_COMMERCE, true) || $order['operational_status'] === 'unavailable') {
                throw new ApiException(409, 'CONFIRMARE_ORDER_CLOSED', 'The order is cancelled or no longer available in the shop; it cannot be approved.');
            }
            $orderUuid = (string) $order['order_uuid'];
            // Owner rule 1 (production stop): an order whose production is stopped is never approved for production; the stop is
            // read with a locking read of the order row (the shared cancellation guard).
            ProductionStop::assertOpen($this->pdo, $orderUuid);
            $pricing = $this->pdo->prepare('SELECT source_pricing_fingerprint FROM operational_orders WHERE order_uuid = ?');
            $pricing->execute([$orderUuid]);
            if ($pricing->fetchColumn() === null) {
                throw new ApiException(409, 'CONFIRMARE_PRICE_UNAVAILABLE', 'The shop has not sent its pricing fingerprint for this order yet; the price cannot be verified.');
            }
            if (!$this->contactAvailable($orderUuid)) {
                throw new ApiException(409, 'CONFIRMARE_CONTACT_UNAVAILABLE', 'The shop sent no telephone number for this customer; the order cannot be confirmed by telephone.');
            }
            // API 2.40.0 (Phase 4B): a change made in the shop through Staff must be settled and its order update received.
            if (ConfirmareSourceEdits::changePending($this->pdo, $orderUuid)) {
                throw new ApiException(409, 'CONFIRMARE_SHOP_CHANGE_PENDING', 'A change of this order in the shop is not settled yet. Check it first.');
            }
            $current = ConfirmationClearance::lineIds($this->pdo, $orderUuid);
            $sortedCurrent = $current;
            sort($sortedCurrent, SORT_STRING);
            if ($lines !== $sortedCurrent) {
                throw new ApiException(409, 'CONFIRMARE_LINES_NOT_CONFIRMED', 'Every current cart line must be confirmed, and only those.', ['lineIds' => $current]);
            }
            $currentFingerprint = ConfirmationClearance::fingerprint($this->pdo, $orderUuid);
            if (!hash_equals($currentFingerprint, $fingerprint)) {
                throw new ApiException(409, 'CONFIRMARE_REVIEW_STALE', 'The order changed in the shop since it was reviewed. Review it again.', ['reviewFingerprint' => $currentFingerprint]);
            }
            // API 2.40.0 (after the review check, so a changed order is reported as such first): where the shop's own
            // order can be read in Staff, the approver must have read it with the prices Arasya holds now (no approval
            // of prices nobody looked at).
            if ($this->sourceAccess?->find((string) $order['source_key']) !== null && !ConfirmareSourceAccess::reviewedShop($this->pdo, $orderUuid, $actor->employeeUuid)) {
                throw new ApiException(409, 'CONFIRMARE_SHOP_NOT_REVIEWED', 'Open the shop order in Staff (with its current prices) before approving.');
            }
            // API 2.39.0: the confirmed call is an attempt an operator recorded on this review (never inferred from the
            // approval itself, from opening the order or from a telephone link).
            $call = $this->recordedCall($orderUuid, $currentFingerprint);
            if ($call === null) {
                throw new ApiException(409, 'CONFIRMARE_CALL_NOT_RECORDED', 'Record the confirmed telephone call with the customer on the current order first.');
            }
            $this->change($order, $confirmation, [
                'status' => self::STATUS_APPROVED, 'decided_at' => $now, 'decided_by_employee_uuid' => $actor->employeeUuid, 'decision_reason_code' => null,
                'assigned_employee_uuid' => null, 'assigned_at' => null, 'assignment_kind' => null,
                'call_confirmed_at' => $call['recorded_at'], 'call_confirmed_by_employee_uuid' => $call['employee_uuid'],
                'confirmed_line_ids' => json_encode($lines, JSON_THROW_ON_ERROR), 'approved_review_fingerprint' => $fingerprint,
            ], $actor->employeeUuid, 'approved', null, $now, $requestId, $key);
            ConfirmationClearance::refresh($this->pdo, $orderUuid, $now);
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

    /** Supervisor: a rejected order, or a stale approval (changed in the shop before production), returns to the pool. @return array<string, mixed> */
    public function reopen(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, string $key, string $requestId): array
    {
        return $this->command($actor, 'reopen', $globalOrderId, $expectedVersion, [], $key, $requestId, false, function (array $order, array $confirmation, string $now) use ($actor, $key, $requestId): array {
            $this->requireSupervisor($actor, (string) $order['source_key']);
            // A rejected order, or an approved one that lost its production clearance because the shop changed it after
            // the review (it never started production), returns to the pool for a new review. A cleared approval never.
            $staleApproval = $confirmation['status'] === self::STATUS_APPROVED && !ConfirmationClearance::refresh($this->pdo, (string) $order['order_uuid'], $now)
                && $order['production_stage_id'] === \Arasya\Operations\Order\OrderProjectionWriter::INITIAL_STAGE_ID
                && array_intersect(ConfirmationClearance::missing($this->pdo, (string) $order['order_uuid']), ['sync', 'lines', 'price']) !== [];
            if ($confirmation['status'] !== self::STATUS_REJECTED && !$staleApproval) {
                throw new ApiException(409, 'CONFIRMARE_NOT_REOPENABLE', 'Only a rejected order, or an approval the shop changed before production, can be reopened.');
            }
            $this->change($order, $confirmation, [
                'status' => self::STATUS_PENDING, 'decided_at' => null, 'decided_by_employee_uuid' => null, 'decision_reason_code' => null,
                'call_confirmed_at' => null, 'call_confirmed_by_employee_uuid' => null, 'confirmed_line_ids' => null, 'approved_review_fingerprint' => null,
                'production_cleared' => 0, 'cleared_at' => null,
            ], $actor->employeeUuid, 'reopened', null, $now, $requestId, $key);
            return [];
        });
    }

    /**
     * Records one telephone contact attempt (API 2.39.0): what the operator says happened on the call, nothing else.
     * Only the assignee or a supervisor of the source, on a pending Confirmare order still open in the shop, on the
     * review the operator saw (`reviewFingerprint`) and the version they read. Append-only: it changes neither the
     * confirmation version nor the assignment, and the approval later needs the latest attempt to be `confirmed` on the
     * current review. The Idempotency-Key is unique per employee: a retried request answers with the same attempt.
     *
     * @return array<string, mixed>
     */
    public function contact(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, mixed $outcome, mixed $reviewFingerprint, string $key, string $requestId): array
    {
        $this->requireActor($actor);
        $this->requireKey($key);
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        if (!is_string($outcome) || !in_array($outcome, self::CONTACT_OUTCOMES, true)) {
            throw new ApiException(422, 'INVALID_OUTCOME', 'outcome must be one of: ' . implode(', ', self::CONTACT_OUTCOMES) . '.');
        }
        if (!is_string($reviewFingerprint) || preg_match('/^[0-9a-f]{64}$/D', $reviewFingerprint) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_REVIEW_REQUIRED', 'reviewFingerprint (the order the call was about) is required.');
        }
        $globalId = $this->globalId($globalOrderId);
        $hash = $this->hash('contact', [$globalId, $expectedVersion, $outcome, $reviewFingerprint]);
        return $this->retrying(function () use ($actor, $globalId, $expectedVersion, $outcome, $reviewFingerprint, $key, $hash, $requestId): array {
            $this->pdo->beginTransaction();
            try {
                $order = $this->lockOrder($globalId);
                $confirmation = $order === null ? null : $this->lockConfirmation((string) $order['order_uuid']);
                if ($order === null || $confirmation === null || !$this->scopes->sees($actor, (string) $order['source_key'], true)) {
                    throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
                }
                $orderUuid = (string) $order['order_uuid'];
                $replay = $this->pdo->prepare('SELECT * FROM order_confirmation_contacts WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
                $replay->execute([$actor->employeeUuid, $key]);
                $previous = $replay->fetch(PDO::FETCH_ASSOC);
                if (is_array($previous)) {
                    if ($previous['order_uuid'] !== $orderUuid || !hash_equals((string) $previous['request_hash'], $hash)) {
                        throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
                    }
                    $this->pdo->rollBack();
                    return ['contact' => $this->presentContact($previous, ConfirmationClearance::fingerprint($this->pdo, $orderUuid), $this->reopenedVersion($orderUuid)), 'item' => $this->current($orderUuid)];
                }
                if ((int) $confirmation['version'] !== $expectedVersion) {
                    throw new ApiException(409, 'CONFIRMARE_CHANGED', 'The Confirmare state changed. Reload it before continuing.', ['currentVersion' => (int) $confirmation['version']]);
                }
                $this->requireDecider($actor, $order, $confirmation);
                if ($order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE) {
                    throw new ApiException(409, 'CONFIRMARE_UNDETERMINED', 'The submission instant of this order is not known; a supervisor reviews it.');
                }
                if (in_array((string) $order['source_commerce_status_code'], self::CLOSED_COMMERCE, true) || $order['operational_status'] === 'unavailable') {
                    throw new ApiException(409, 'CONFIRMARE_ORDER_CLOSED', 'The order is cancelled or no longer available in the shop.');
                }
                $current = ConfirmationClearance::fingerprint($this->pdo, $orderUuid);
                if (!hash_equals($current, $reviewFingerprint)) {
                    throw new ApiException(409, 'CONFIRMARE_REVIEW_STALE', 'The order changed in the shop since it was reviewed. Review it again.', ['reviewFingerprint' => $current]);
                }
                if ($outcome === 'confirmed' && !$this->contactAvailable($orderUuid)) {
                    throw new ApiException(409, 'CONFIRMARE_CONTACT_UNAVAILABLE', 'The shop sent no telephone number for this customer; a confirmed call cannot be recorded.');
                }
                $attempt = $this->pdo->prepare('SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM order_confirmation_contacts WHERE order_uuid = ?');
                $attempt->execute([$orderUuid]);
                $number = (int) $attempt->fetchColumn();
                $now = $this->now();
                $insert = $this->pdo->prepare('INSERT INTO order_confirmation_contacts (contact_uuid, order_uuid, global_order_id, source_key, attempt_number, outcome, employee_uuid, review_fingerprint,
                        confirmation_version, request_id, idempotency_key, request_hash, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->bindValue(1, Uuid::v4());
                $insert->bindValue(2, $orderUuid);
                $insert->bindValue(3, (string) $order['global_order_id']);
                $insert->bindValue(4, (string) $order['source_key']);
                $insert->bindValue(5, $number, PDO::PARAM_INT);
                $insert->bindValue(6, $outcome);
                $insert->bindValue(7, $actor->employeeUuid);
                $insert->bindValue(8, $reviewFingerprint);
                $insert->bindValue(9, $expectedVersion, PDO::PARAM_INT);
                $insert->bindValue(10, mb_substr($requestId, 0, 100));
                $insert->bindValue(11, $key);
                $insert->bindValue(12, $hash, PDO::PARAM_LOB);
                $insert->bindValue(13, $now);
                $insert->execute();
                $this->pdo->commit();
                $row = $this->pdo->prepare('SELECT * FROM order_confirmation_contacts WHERE order_uuid = ? AND attempt_number = ?');
                $row->execute([$orderUuid, $number]);
                return ['contact' => $this->presentContact($row->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Contact attempt disappeared.'), $current, $this->reopenedVersion($orderUuid)), 'item' => $this->current($orderUuid)];
            } catch (\Throwable $error) {
                $this->rollBack();
                throw $error;
            }
        });
    }

    /**
     * Whether an operator accepts automatic assignments. Self, or a supervisor of one of the operator's sources. Going
     * unavailable returns the operator's pending orders to the pool at once (one audited `released` each).
     *
     * @return array<string, mixed>
     */
    public function setAvailability(EmployeeIdentity $actor, ?string $employeeId, mixed $available, string $key, string $requestId): array
    {
        $this->requireActor($actor);
        $this->requireKey($key);
        if (!is_bool($available)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'available must be true or false.');
        }
        $target = $employeeId ?? $actor->employeeUuid;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $target) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'employeeId is invalid.');
        }
        $hash = $this->hash('availability', [$target, $available]);
        $response = $this->retrying(function () use ($actor, $target, $available, $key, $hash, $requestId): array {
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
                $this->pdo->prepare('UPDATE confirmare_operator_state SET available = ?, available_changed_at = ?, available_changed_by_employee_uuid = ? WHERE employee_uuid = ?')
                    ->execute([$available ? 1 : 0, $now, $actor->employeeUuid, $target]);
                $released = 0;
                if (!$available) {
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
        // Leave returns the operator's orders to the pool; they are redistributed to colleagues at once (controlled:
        // the same fair, audited distribution as the cron run). A failure here only waits for the next run.
        if ($available === false && ($response['released'] ?? 0) > 0) {
            foreach ($response['sources']['operate'] ?? [] as $sourceKey) {
                try {
                    $this->distribute((string) $sourceKey);
                } catch (\Throwable) {
                }
            }
        }
        return $response;
    }

    // ---- System distribution (bin/confirmare-distribute.php) -------------------------------------------------------

    /**
     * Returns to the pool every pending assignment whose assignee can no longer work on it (scope removed, account or
     * department inactive, application removed), then assigns unassigned Confirmare orders to available operators (no
     * opt-in: an active identity with `operate` is available until put on leave), one at a time, fewest open assignments
     * first, due date first then FIFO, never blocked by the balance target. Suspended sources are held. Each assignment
     * is its own short transaction. `dryRun` reads only.
     *
     * @return array{released: int, assigned: int, waiting: array<string, int>, operators: array<string, int>}
     */
    public function distribute(?string $source = null, bool $dryRun = false, int $maxAssignments = 500): array
    {
        $released = $dryRun ? count($this->ineligibleAssignments()) : $this->reclaim();
        $assigned = 0;
        $held = [];
        while (!$dryRun && $assigned < $maxAssignments) {
            $progress = false;
            foreach ($this->waitingSources($source) as $sourceKey => $waiting) {
                // A suspended source holds its orders: nothing is distributed until it is resumed.
                if ($this->held($sourceKey)) {
                    $held[$sourceKey] = true;
                    continue;
                }
                // Least loaded first (below the balance target first, then overflow): the queue never blocks.
                $target = $this->scopes->distributionTargets($sourceKey)[0] ?? null;
                if ($target !== null && $this->assignOne($sourceKey, $target['employee_uuid'])) {
                    $assigned++;
                    $progress = true;
                }
            }
            if (!$progress) {
                break;
            }
        }
        $operators = [];
        $overTarget = 0;
        foreach (['trendhome', 'outletperdele'] as $sourceKey) {
            if ($source !== null && $sourceKey !== $source) {
                continue;
            }
            $targets = $this->scopes->distributionTargets($sourceKey);
            $operators[$sourceKey] = count($targets);
            $overTarget += count(array_filter($targets, static fn (array $t): bool => $t['open'] > self::TARGET_OPEN_PER_OPERATOR));
            if ($this->held($sourceKey)) {
                $held[$sourceKey] = true;
            }
        }
        $alerts = $dryRun ? count($this->inactiveAssignments()) : $this->raiseAlerts();
        return ['released' => $released, 'assigned' => $assigned, 'waiting' => $this->waitingSources($source), 'operators' => $operators,
            'held' => array_keys($held), 'overTarget' => $overTarget, 'openAlerts' => $alerts];
    }

    /**
     * Open supervisor alerts of the supervised sources: assignments without any change for INACTIVITY_ALERT_MINUTES.
     * Inactivity alone never reassigns an order (owner rule); the supervisor decides.
     *
     * @return array<string, mixed>
     */
    public function alerts(EmployeeIdentity $actor): array
    {
        $this->requireActor($actor);
        $supervise = $this->scopes->sources($actor, ConfirmareScopePolicy::SUPERVISE);
        if ($supervise === []) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'Only a Confirmare supervisor sees the alerts.');
        }
        $parameters = [];
        $where = ConfirmareScopePolicy::condition($supervise, 'a.source_key', $parameters);
        $statement = $this->pdo->prepare("SELECT a.global_order_id, a.source_key, a.kind, a.raised_at, a.confirmation_version, e.employee_uuid, e.display_name, c.assigned_at, o.order_number,
                (SELECT MAX(k.recorded_at) FROM order_confirmation_contacts k WHERE k.order_uuid = a.order_uuid) AS last_contact_at
            FROM confirmare_alerts a INNER JOIN employees e ON e.employee_uuid = a.employee_uuid INNER JOIN order_confirmations c ON c.order_uuid = a.order_uuid
            INNER JOIN operational_orders o ON o.order_uuid = a.order_uuid
            WHERE a.resolved_at IS NULL AND {$where} ORDER BY a.raised_at, a.global_order_id LIMIT " . self::POOL_LIMIT);
        $statement->execute($parameters);
        return ['items' => array_map(static fn (array $row): array => [
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'sourceKey' => (string) $row['source_key'],
            'kind' => (string) $row['kind'],
            'raisedAt' => self::iso((string) $row['raised_at']),
            'assignee' => ['employeeId' => (string) $row['employee_uuid'], 'displayName' => (string) $row['display_name']],
            'assignedAt' => $row['assigned_at'] === null ? null : self::iso((string) $row['assigned_at']),
            'confirmationVersion' => (int) $row['confirmation_version'],
            // A recorded call attempt is shown, never counted as progress: the alert rule itself is unchanged.
            'lastContactAt' => $row['last_contact_at'] === null ? null : self::iso((string) $row['last_contact_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'inactivityMinutes' => self::INACTIVITY_ALERT_MINUTES];
    }

    /**
     * Supervisor view of the team (API 2.39.0): every identity holding `operate` for a supervised source, whether it
     * can work now, its availability (leave) and its open assignments, plus what waits unassigned per source. Read-only.
     * The distribution itself stays on the server (distribute, next); nothing here assigns.
     *
     * @return array<string, mixed>
     */
    public function team(EmployeeIdentity $actor): array
    {
        $this->requireActor($actor);
        $supervise = $this->scopes->sources($actor, ConfirmareScopePolicy::SUPERVISE);
        if ($supervise === []) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'Only a Confirmare supervisor sees the team.');
        }
        $parameters = [];
        $where = ConfirmareScopePolicy::condition($supervise, 's.source_key', $parameters);
        $statement = $this->pdo->prepare(
            "SELECT e.employee_uuid, e.display_name, s.source_key, CASE WHEN " . ConfirmareScopePolicy::workingIdentitySql('e') . " THEN 1 ELSE 0 END AS can_work,
                    COALESCE(st.available, 1) AS available, st.available_changed_at, st.last_assigned_at,
                    (SELECT COUNT(*) FROM order_confirmations c WHERE c.assigned_employee_uuid = e.employee_uuid AND c.status = 'pending') AS open_count,
                    (SELECT MIN(c.assigned_at) FROM order_confirmations c WHERE c.assigned_employee_uuid = e.employee_uuid AND c.status = 'pending') AS oldest_assigned_at
             FROM employee_confirmare_scopes s
             INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
             LEFT JOIN confirmare_operator_state st ON st.employee_uuid = e.employee_uuid
             WHERE s.capability = 'operate' AND {$where}
             ORDER BY e.display_name, e.employee_uuid, s.source_key",
        );
        $statement->execute($parameters);
        $people = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string) $row['employee_uuid'];
            $people[$id] ??= [
                'employeeId' => $id,
                'displayName' => (string) $row['display_name'],
                'sources' => [],
                'canWork' => (int) $row['can_work'] === 1,
                'available' => (int) $row['available'] === 1,
                'availableChangedAt' => $row['available_changed_at'] === null ? null : self::iso((string) $row['available_changed_at']),
                'lastAssignedAt' => $row['last_assigned_at'] === null ? null : self::iso((string) $row['last_assigned_at']),
                'open' => (int) $row['open_count'],
                'oldestAssignedAt' => $row['oldest_assigned_at'] === null ? null : self::iso((string) $row['oldest_assigned_at']),
            ];
            $people[$id]['sources'][] = (string) $row['source_key'];
        }
        $waiting = [];
        $undetermined = [];
        foreach ($supervise ?? ['outletperdele', 'trendhome'] as $sourceKey) {
            $count = $this->pdo->prepare("SELECT SUM(o.workflow_cohort = 'confirmare' AND c.assigned_employee_uuid IS NULL), SUM(o.workflow_cohort <> 'confirmare' OR o.workflow_cohort IS NULL)
                FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.status = 'pending' AND c.source_key = ?");
            $count->execute([$sourceKey]);
            [$unassigned, $unknown] = $count->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $waiting[$sourceKey] = (int) $unassigned;
            $undetermined[$sourceKey] = (int) $unknown;
        }
        return [
            'operators' => array_values($people),
            'unassigned' => $waiting,
            'undetermined' => $undetermined,
            'held' => array_values(array_filter(array_keys($waiting), fn (string $sourceKey): bool => $this->held($sourceKey))),
            'targetOpenPerOperator' => self::TARGET_OPEN_PER_OPERATOR,
            'inactivityMinutes' => self::INACTIVITY_ALERT_MINUTES,
        ];
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
                if ((int) $state['available'] !== 1) {
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

    /** Whether the source is suspended (its orders are held, not distributed). */
    private function held(string $sourceKey): bool
    {
        return \Arasya\Operations\Production\WorkflowCohort::heartbeatState($this->pdo, $sourceKey, $this->clock->now())['state'] === \Arasya\Operations\Production\WorkflowCohort::STATE_SUSPENDED;
    }

    /** @return list<array<string, mixed>> pending assignments without any change for INACTIVITY_ALERT_MINUTES */
    private function inactiveAssignments(): array
    {
        $statement = $this->pdo->prepare("SELECT order_uuid, global_order_id, source_key, assigned_employee_uuid, version FROM order_confirmations
            WHERE status = 'pending' AND assigned_employee_uuid IS NOT NULL AND updated_at < ? ORDER BY order_uuid");
        $statement->execute([$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('-' . self::INACTIVITY_ALERT_MINUTES . ' minutes')->format('Y-m-d H:i:s.u')]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Resolves alerts whose assignment moved on, raises one alert per inactive assignment. @return int open alerts */
    private function raiseAlerts(): int
    {
        $now = $this->now();
        $this->pdo->prepare("UPDATE confirmare_alerts a INNER JOIN order_confirmations c ON c.order_uuid = a.order_uuid SET a.resolved_at = ?
            WHERE a.resolved_at IS NULL AND (c.version <> a.confirmation_version OR c.status <> 'pending' OR c.assigned_employee_uuid IS NULL)")->execute([$now]);
        $insert = $this->pdo->prepare('INSERT INTO confirmare_alerts (alert_uuid, order_uuid, global_order_id, source_key, kind, employee_uuid, confirmation_version, raised_at)
            SELECT ?, ?, ?, ?, \'inactive_assignment\', ?, ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM confirmare_alerts WHERE order_uuid = ? AND kind = \'inactive_assignment\' AND confirmation_version = ?)');
        foreach ($this->inactiveAssignments() as $row) {
            $insert->execute([Uuid::v4(), $row['order_uuid'], $row['global_order_id'], $row['source_key'], $row['assigned_employee_uuid'], (int) $row['version'], $now, $row['order_uuid'], (int) $row['version']]);
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM confirmare_alerts WHERE resolved_at IS NULL')->fetchColumn();
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
             ORDER BY " . self::PRIORITY_ORDER . ' LIMIT 5',
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
        $this->pdo->prepare('INSERT INTO confirmare_operator_state (employee_uuid, available) VALUES (?, 1) ON DUPLICATE KEY UPDATE employee_uuid = employee_uuid')->execute([$employeeUuid]);
        $statement = $this->pdo->prepare('SELECT employee_uuid, available, last_assigned_at FROM confirmare_operator_state WHERE employee_uuid = ? FOR UPDATE');
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

    private function financeViewer(EmployeeIdentity $actor, string $sourceKey): bool
    {
        try {
            return (new FinanceScopePolicy($this->pdo))->allows($actor, FinanceScopePolicy::VIEW, $sourceKey);
        } catch (\PDOException) {
            return false;
        }
    }

    /** The production handoff state of an order for this viewer (API 2.42.0). @return array<string, mixed> */
    private function handoff(EmployeeIdentity $actor, string $orderUuid, string $source): array
    {
        $scoped = (new DocumentScopePolicy($this->pdo))->allows($actor, DocumentScopePolicy::OPERATE, $source);
        $active = $actor->isOperationallyActive() && !$actor->mustChangePassword;
        $generate = $active && $scoped && $this->authorization->can($actor, DocumentService::GENERATE);
        $print = $active && $scoped && ($generate || $this->authorization->can($actor, DocumentService::REPRINT));
        return (new ConfirmareHandoff($this->pdo, $this->modes))->view($orderUuid, $generate, $print);
    }

    /**
     * The production handoff list (API 2.42.0): approved Confirmare orders whose production is cleared now
     * (ConfirmationGate::CLEARED_SQL), still at stage 1 and open, with no document or an active one, of the sources in the
     * actor's production document `operate` scope. Who: an active identity with the document generate permission; no
     * Confirmare scope is needed or used, so a document employee is never part of the Confirmare distribution. Each row is
     * the order's identity and its next step only (`ready`, `print`, `awaiting_cutting`): no customer, assignee, call or
     * money data.
     *
     * @return array{items: list<array<string, mixed>>, limit: int}
     */
    public function handoffList(EmployeeIdentity $actor, ?string $source): array
    {
        $this->requireActor($actor);
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword || !$this->authorization->can($actor, DocumentService::GENERATE)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să generezi documente de producție.');
        }
        if ($source !== null && preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'source is invalid.');
        }
        $parameters = [];
        $where = "c.status = 'approved' AND " . ConfirmationGate::CLEARED_SQL . " = 1 AND o.production_stage_id = 'waiting' AND o.operational_status <> 'unavailable'
            AND o.production_completed_at IS NULL AND o.document_status IN ('none', 'active') AND "
            . DocumentScopePolicy::condition((new DocumentScopePolicy($this->pdo))->sources($actor, DocumentScopePolicy::OPERATE), 'o.source_key', $parameters);
        if ($source !== null) {
            $where .= ' AND o.source_key = ?';
            $parameters[] = $source;
        }
        $statement = $this->pdo->prepare("SELECT o.global_order_id, o.order_number, o.source_key, o.source_due_date, o.source_submitted_at, c.opened_at, c.decided_at, o.document_status,
                (SELECT COUNT(*) FROM production_document_prints p WHERE p.revision_uuid = o.active_document_revision_uuid) AS prints
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
            WHERE {$where} ORDER BY " . self::PRIORITY_ORDER . ' LIMIT ' . self::POOL_LIMIT);
        $statement->execute($parameters);
        return ['items' => array_map(static fn (array $row): array => [
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'sourceKey' => (string) $row['source_key'],
            'dueDate' => $row['source_due_date'] === null ? null : (string) $row['source_due_date'],
            'submittedAt' => $row['source_submitted_at'] === null ? null : self::iso((string) $row['source_submitted_at']),
            'approvedAt' => $row['decided_at'] === null ? null : self::iso((string) $row['decided_at']),
            'step' => $row['document_status'] === 'none' ? 'ready' : ((int) $row['prints'] === 0 ? 'print' : 'awaiting_cutting'),
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'limit' => self::POOL_LIMIT];
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
        $statement = $this->pdo->prepare('SELECT available, available_changed_at, last_assigned_at FROM confirmare_operator_state WHERE employee_uuid = ?');
        $statement->execute([$employeeUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ['available' => 1, 'available_changed_at' => null, 'last_assigned_at' => null];
        return [
            'employeeId' => $employeeUuid,
            'available' => (int) $row['available'] === 1,
            'changedAt' => $row['available_changed_at'] === null ? null : self::iso((string) $row['available_changed_at']),
            'lastAssignedAt' => $row['last_assigned_at'] === null ? null : self::iso((string) $row['last_assigned_at']),
            'open' => $this->openCount($employeeUuid),
            'targetOpen' => self::TARGET_OPEN_PER_OPERATOR,
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
                       o.source_commerce_status_label, o.source_changed_at, o.last_source_seen_at, o.freshness_status, o.document_context,
                       o.operational_status, o.payment_method, o.advance_status, o.source_due_date, o.source_pricing_fingerprint, o.source_financial_state, o.source_financial_basis,
                       o.source_financial_pricing, o.source_financial_attention, o.source_contact_phone, c.production_cleared,
                       c.status, c.version, c.opened_at, c.decided_at, c.decision_reason_code, c.assigned_employee_uuid, c.assigned_at, c.assignment_kind,
                       ae.display_name AS assignee_name, de.display_name AS decider_name, c.decided_by_employee_uuid, c.call_confirmed_at,
                       k.outcome AS contact_outcome, k.recorded_at AS contact_at, k.review_fingerprint AS contact_review, k.confirmation_version AS contact_version, k.attempt_number AS contact_attempts,
                       (SELECT MAX(r.version_after) FROM order_confirmation_events r WHERE r.order_uuid = c.order_uuid AND r.action = \'reopened\') AS reopened_version
                FROM order_confirmations c
                INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
                LEFT JOIN employees ae ON ae.employee_uuid = c.assigned_employee_uuid
                LEFT JOIN employees de ON de.employee_uuid = c.decided_by_employee_uuid
                LEFT JOIN order_confirmation_contacts k ON k.order_uuid = c.order_uuid
                    AND k.attempt_number = (SELECT MAX(km.attempt_number) FROM order_confirmation_contacts km WHERE km.order_uuid = c.order_uuid)';
    }

    /**
     * The latest recorded contact attempt when it is `confirmed`, on the given (current) review and after the last
     * reopening; null otherwise. The approval takes the call from it (API 2.39.0).
     *
     * @return array<string, mixed>|null
     */
    private function recordedCall(string $orderUuid, string $currentFingerprint): ?array
    {
        $statement = $this->pdo->prepare('SELECT outcome, employee_uuid, review_fingerprint, confirmation_version, recorded_at FROM order_confirmation_contacts WHERE order_uuid = ? ORDER BY attempt_number DESC LIMIT 1');
        $statement->execute([$orderUuid]);
        $latest = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($latest) || $latest['outcome'] !== 'confirmed' || !hash_equals((string) $latest['review_fingerprint'], $currentFingerprint)
            || (int) $latest['confirmation_version'] < $this->reopenedVersion($orderUuid)) {
            return null;
        }
        return $latest;
    }

    /**
     * Whether the shop holds a callable customer telephone number for the order. API 2.40.0: the shop's own fact
     * (order.contact.phone, evaluated on its real billing and delivery telephone fields) decides whenever it was
     * stated, also when it says no although a masked delivery phone exists. Only an order from an older shop connector
     * that never stated it falls back to the masked phone of the delivery context.
     */
    private function contactAvailable(string $orderUuid): bool
    {
        $statement = $this->pdo->prepare('SELECT document_context, source_contact_phone FROM operational_orders WHERE order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        if ($row['source_contact_phone'] !== null) {
            return (int) $row['source_contact_phone'] === 1;
        }
        $context = is_string($row['document_context']) ? json_decode($row['document_context'], true) : null;
        return is_array($context) && is_string($context['phoneMasked'] ?? null) && $context['phoneMasked'] !== '';
    }

    /** The version a supervisor last reopened the order at (0: never). Contact attempts before it no longer count. */
    private function reopenedVersion(string $orderUuid): int
    {
        $statement = $this->pdo->prepare("SELECT COALESCE(MAX(version_after), 0) FROM order_confirmation_events WHERE order_uuid = ? AND action = 'reopened'");
        $statement->execute([$orderUuid]);
        return (int) $statement->fetchColumn();
    }

    /** Every recorded contact attempt of the order, newest first (codes, names and times only). @return list<array<string, mixed>> */
    private function contacts(string $orderUuid, string $currentFingerprint): array
    {
        $statement = $this->pdo->prepare('SELECT k.*, e.display_name FROM order_confirmation_contacts k INNER JOIN employees e ON e.employee_uuid = k.employee_uuid WHERE k.order_uuid = ? ORDER BY k.attempt_number DESC LIMIT 100');
        $statement->execute([$orderUuid]);
        $reopened = $this->reopenedVersion($orderUuid);
        return array_map(fn (array $row): array => $this->presentContact($row, $currentFingerprint, $reopened), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function presentContact(array $row, string $currentFingerprint, int $reopenedVersion): array
    {
        $name = $row['display_name'] ?? null;
        if ($name === null) {
            $lookup = $this->pdo->prepare('SELECT display_name FROM employees WHERE employee_uuid = ?');
            $lookup->execute([(string) $row['employee_uuid']]);
            $name = $lookup->fetchColumn();
        }
        return [
            'attempt' => (int) $row['attempt_number'],
            'outcome' => (string) $row['outcome'],
            'by' => $name === false || $name === null ? null : (string) $name,
            'at' => self::iso((string) $row['recorded_at']),
            // The attempt is about the order as Arasya holds it now, and after the last reopening.
            'current' => hash_equals((string) $row['review_fingerprint'], $currentFingerprint) && (int) $row['confirmation_version'] >= $reopenedVersion,
        ];
    }

    /**
     * The current cart lines as the source sent them (no price: Arasya holds no amount). `confirmed` is true for the
     * lines of the recorded approval. @return list<array<string, mixed>>
     */
    private function lines(string $orderUuid): array
    {
        $approved = $this->pdo->prepare("SELECT confirmed_line_ids FROM order_confirmations WHERE order_uuid = ? AND status = 'approved'");
        $approved->execute([$orderUuid]);
        $confirmedRaw = $approved->fetchColumn();
        $confirmed = is_string($confirmedRaw) ? json_decode($confirmedRaw, true) : [];
        $confirmed = is_array($confirmed) ? array_map('strval', $confirmed) : [];
        $statement = $this->pdo->prepare('SELECT source_item_id, line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity, production_context
            FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $statement->execute([$orderUuid]);
        $number = static fn (mixed $value): ?float => $value === null ? null : (float) $value;
        return array_map(static function (array $row) use ($confirmed, $number): array {
            $context = $row['production_context'] === null ? [] : json_decode((string) $row['production_context'], true);
            $context = is_array($context) ? $context : [];
            $options = [];
            foreach ((array) ($context['options'] ?? []) as $option) {
                if (is_array($option) && is_string($option['label'] ?? null) && is_string($option['value'] ?? null)) {
                    $options[] = ['label' => $option['label'], 'value' => $option['value']];
                }
            }
            return [
                'lineId' => (string) $row['source_item_id'],
                'lineNumber' => (int) $row['line_number'],
                'name' => (string) $row['name'],
                'productCode' => $row['product_code'] === null ? null : (string) $row['product_code'],
                'variant' => $row['variant'] === null ? null : (string) $row['variant'],
                'color' => $row['color'] === null ? null : (string) $row['color'],
                'width' => $number($row['width_value']),
                'height' => $number($row['height_value']),
                'unit' => $row['measurement_unit'] === null ? null : (string) $row['measurement_unit'],
                'meters' => $number($row['meters']),
                'quantity' => (int) $row['quantity'],
                'options' => $options,
                'notes' => is_string($context['notes'] ?? null) ? $context['notes'] : null,
                'confirmed' => in_array((string) $row['source_item_id'], $confirmed, true),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The customer block of the detail. Only the assigned operator and a supervisor of the source see it, and only what
     * Arasya already holds for the delivery documents: recipient, company, address lines and the MASKED phone. Arasya
     * never stores the full telephone number (DeliveryContext), so `phoneAvailable` stays false: the full number needs
     * the separately approved source contact access (Phase 4). The queue never carries any of this.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function customer(EmployeeIdentity $actor, array $row): array
    {
        $assignee = $row['assigned_employee_uuid'] === null ? null : (string) $row['assigned_employee_uuid'];
        $visible = $assignee === $actor->employeeUuid || $this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, (string) $row['source_key']);
        if (!$visible) {
            return ['visible' => false, 'phoneAvailable' => false];
        }
        $context = $row['document_context'] === null ? null : json_decode((string) $row['document_context'], true);
        $context = is_array($context) ? $context : [];
        return [
            'visible' => true,
            'name' => is_string($context['name'] ?? null) ? $context['name'] : null,
            'company' => is_string($context['company'] ?? null) ? $context['company'] : null,
            'addressLines' => array_values(array_filter((array) ($context['addressLines'] ?? []), 'is_string')),
            'phoneMasked' => is_string($context['phoneMasked'] ?? null) ? $context['phoneMasked'] : null,
            'phoneAvailable' => false,
        ];
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
            // The advance payment gate state (legacy view) and the Confirmare financial clearance the source stated.
            'payment' => ['state' => PaymentGate::state($row['payment_method'], $row['advance_status'])],
            // `open` (API 2.39.0): the production financial condition holds now (ConfirmationClearance::financialOpen).
            // `attention` (API 2.40.0): the total changed after a payment; a financial decision is needed (never clears).
            'financial' => ['state' => $row['source_financial_state'] ?? 'unknown', 'basis' => $row['source_financial_basis'], 'attention' => $row['source_financial_attention'] ?? null, 'open' => ConfirmationClearance::financialOpen(
                $row['source_financial_state'] === null ? null : (string) $row['source_financial_state'],
                $row['source_financial_basis'] === null ? null : (string) $row['source_financial_basis'],
                $row['source_financial_pricing'] === null ? null : (string) $row['source_financial_pricing'],
                $row['source_pricing_fingerprint'] === null ? null : (string) $row['source_pricing_fingerprint'],
                $row['payment_method'] === null ? null : (string) $row['payment_method'],
            )],
            'pricingAvailable' => $row['source_pricing_fingerprint'] !== null,
            'dueDate' => $row['source_due_date'] === null ? null : (string) $row['source_due_date'],
            'productionCleared' => (int) $row['production_cleared'] === 1,
            // The latest recorded contact attempt (API 2.39.0): outcome code and time only. `current`: about the order
            // as Arasya holds it now and after the last reopening.
            'contact' => $row['contact_outcome'] === null ? null : [
                'outcome' => (string) $row['contact_outcome'],
                'at' => self::iso((string) $row['contact_at']),
                'attempts' => (int) $row['contact_attempts'],
                'current' => (int) $row['contact_version'] >= (int) ($row['reopened_version'] ?? 0)
                    && hash_equals((string) $row['contact_review'], ConfirmationClearance::fingerprint($this->pdo, (string) $row['order_uuid'])),
            ],
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
