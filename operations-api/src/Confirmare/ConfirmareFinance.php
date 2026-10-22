<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Integration\SourceAccess\SourceFinance;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Confirmare finance (API 2.41.0, Phase 5): the money of a Confirmare order, as its shop records it.
 *
 * WooCommerce stays the authority for prices, payments, refunds and balances. Operations stores no amount, no bank
 * reference and no payment detail: it relays an authorized employee's request to the shop's YD SOFT adapter through the
 * signed source access protocol and keeps codes and hashes only (order_finance_reads, order_finance_actions).
 *
 * - read:   the financial summary, read from the shop at that moment (current and original total, verified online
 *           payment, cash advance, verified bank transfers, refunds, the COD advance, an outstanding additional payment,
 *           a refund liability, the money state, the shop's finance record), plus what this employee may do next. For a
 *           finance employee of the source (any finance scope), or for the order's Confirmare assignee or a supervisor
 *           of the source (to tell the customer; they never act).
 * - act:    submit_transfer, decide_transfer, acknowledge_liability, submit_refund, decide_refund. Record needs the
 *           `record` scope, decisions the `approve` scope, and the decider is never the employee who recorded it (four
 *           eyes, checked here and by the shop). A refund needs the final manufacturing stage completed (the order reached
 *           stage 14 `delivery` or was handed over; owner rule: no refund before production ends, courier handover is
 *           independent). Each action names the summary revision it was based on; the shop refuses it when anything
 *           changed (`revision_changed`). One action per order at a time; a lost answer is reconciled with the shop's
 *           stored answer of the same Idempotency-Key, never replayed blindly.
 * - queue:  the finance tasks of the employee's sources, derived from the money state the shop sends with every order
 *           event (classes, never amounts) and from the open actions.
 *
 * While a finance action of an order is pending or unknown, production cannot start for it (ConfirmationClearance).
 */
final readonly class ConfirmareFinance
{
    public const CALLS_PER_MINUTE = 30;
    /** An action the shop has no record of after this long was never received (the shop's lock waits 10 s at most). */
    public const RECONCILE_AFTER_SECONDS = 120;
    public const ROLE_FINANCE = 'finance';
    /** Stage 14: the order left the final manufacturing stage (13, packing). Courier handover is independent of it. */
    public const REFUND_ELIGIBLE_STAGE = 'delivery';
    /** Finance tasks, most urgent first; every order is listed under its first matching task. */
    public const TASKS = [
        'reconciliation_required', 'source_unavailable', 'refund_processing', 'transfer_verification', 'refund_eligible',
        'refund_liability', 'additional_payment', 'advance_partial', 'advance_waiting', 'advance_verified',
    ];
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const AMOUNT_PATTERN = '/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D';
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 .\/-]{2,63}$/D';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    /** Failures where the shop certainly did not run the action. */
    private const NOT_SENT = ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'];
    /** The fields each action takes besides expectedRevision. */
    private const FIELDS = [
        'submit_transfer' => ['purpose', 'amount', 'reference', 'valueDate'],
        'decide_transfer' => ['entryId', 'decision', 'reasonCode'],
        'acknowledge_liability' => [],
        'submit_refund' => ['amount', 'reference', 'valueDate'],
        'decide_refund' => ['entryId', 'decision', 'reasonCode'],
    ];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private FinanceScopePolicy $finance,
        private ConfirmareScopePolicy $scopes,
        private SourceAccessRegistry $registry,
        private SourceAccessClient $client,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
        #[\SensitiveParameter] private string $hmacKey,
    ) {
    }

    /**
     * What the actor may do with the money of this order (shown with the Confirmare detail; never throws).
     *
     * @return array{configured: bool, read: bool, record: bool, approve: bool}
     */
    public function capabilities(EmployeeIdentity $actor, string $globalOrderId): array
    {
        try {
            $row = $this->row($this->globalId($globalOrderId));
        } catch (ApiException|PDOException) {
            $row = null;
        }
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        $configured = $target !== null && $target->allowsFinanceRead();
        if ($row === null || !$configured || $row['workflow_cohort'] !== 'confirmare') {
            return ['configured' => $configured, 'read' => false, 'record' => false, 'approve' => false];
        }
        try {
            $source = (string) $row['source_key'];
            $record = $target->allowsFinanceRecord();
            return [
                'configured' => true,
                'read' => $this->role($actor, $row) !== null,
                'record' => $record && $this->finance->allows($actor, FinanceScopePolicy::RECORD, $source),
                'approve' => $record && $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source),
            ];
        } catch (PDOException) {
            return ['configured' => true, 'read' => false, 'record' => false, 'approve' => false];
        }
    }

    /** @return array<string, mixed> the summary and what the actor may do next */
    public function read(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        ['row' => $row, 'role' => $role, 'globalId' => $globalId] = $this->context($actor, $globalOrderId, FinanceScopePolicy::VIEW);
        $this->rateLimit($actor);
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsFinanceRead()) {
            $this->audit($row, $actor, $role, 'not_configured', $requestId);
            return ['available' => false, 'reason' => 'not_configured'];
        }
        try {
            $summary = SourceFinance::summary($this->client->call($target, SourceAccessClient::ROUTE_FINANCE, $this->payload($row, $actor, $role, $requestId)), (string) $row['source_order_id'], (string) $row['order_number']);
        } catch (SourceAccessException $e) {
            $this->audit($row, $actor, $role, strtolower($e->codeName), $requestId);
            return ['available' => false, 'reason' => strtolower($e->codeName)];
        }
        // The permission again, after the shop answered: a revoked scope or a reassignment during the read wins.
        $after = $this->row($globalId);
        if ($after === null || $this->role($actor, $after) !== $role) {
            $this->audit($row, $actor, $role, 'revoked_during_read', $requestId);
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'Your access to this order changed while the shop was read.');
        }
        if (!$this->audit($row, $actor, $role, 'ok', $requestId, $summary['revision'])) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        return ['available' => true, 'role' => $role] + $this->present($actor, $after, $target, $summary);
    }

    /**
     * Sends one finance action to the shop (Idempotency-Key header). A retried request with the same key returns the
     * recorded outcome; with another request it is refused.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> {state: applied, entryId, summary...} or {state: unknown}
     */
    public function act(EmployeeIdentity $actor, string $globalOrderId, string $action, array $input, string $key, string $requestId): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (!isset(self::FIELDS[$action])) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $fields = $this->fields($action, $input);
        $capability = str_starts_with($action, 'submit_') ? FinanceScopePolicy::RECORD : FinanceScopePolicy::APPROVE;
        ['row' => $row, 'role' => $role, 'globalId' => $globalId] = $this->context($actor, $globalOrderId, $capability);
        if ($role !== self::ROLE_FINANCE) {
            throw self::denied();
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsFinanceRecord()) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance actions are not enabled for this shop.');
        }
        $this->rateLimit($actor);
        $hmac = hash_hmac('sha256', json_encode([$globalId, $action, $fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->hmacKey, true);
        $existing = $this->action($actor->employeeUuid, $key);
        if ($existing !== null) {
            if ($existing['order_uuid'] !== $row['order_uuid'] || !hash_equals((string) $existing['request_hmac'], $hmac)) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->settled($existing, $row, $target, $actor, $requestId);
        }
        if (isset($fields['entryId']) && $this->recordedBy((string) $row['order_uuid'], (string) $fields['entryId']) === $actor->employeeUuid) {
            // Four eyes (the shop checks it again on its own record).
            throw new ApiException(403, 'CONFIRMARE_FINANCE_SAME_EMPLOYEE', 'Another employee must verify what you recorded.');
        }
        $refund = in_array($action, ['submit_refund', 'decide_refund'], true);
        $actionUuid = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT production_stage_id, operational_status, source_reported_unavailable_at FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([(string) $row['order_uuid']]);
            $order = $lock->fetch(PDO::FETCH_ASSOC);
            // The scope again, under the order lock: a revoked scope is either fully before or fully after the action.
            if (!is_array($order) || !$this->finance->allows($actor, $capability, (string) $row['source_key'], true)) {
                throw self::denied();
            }
            if ($refund && !self::refundEligible((string) $order['production_stage_id'], (string) $order['operational_status'], $order['source_reported_unavailable_at'] !== null)) {
                throw new ApiException(409, 'CONFIRMARE_FINANCE_REFUND_NOT_ELIGIBLE', 'A refund is possible only after the final manufacturing stage is complete.');
            }
            if (self::actionOpen($this->pdo, (string) $row['order_uuid'])) {
                throw self::openError();
            }
            $this->pdo->prepare('INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, decision, reason_code, purpose, source_entry_id,
                    idempotency_key, request_hmac, revision_before, state, request_id, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $actionUuid, (string) $row['order_uuid'], $globalId, (string) $row['source_key'], $actor->employeeUuid, $action, $fields['decision'] ?? null,
                $fields['reasonCode'] ?? null, $fields['purpose'] ?? null, $fields['entryId'] ?? null, $key, $hmac, $fields['expectedRevision'], 'pending', $this->requestId($requestId), $this->now(),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && $this->action($actor->employeeUuid, $key) !== null) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key is already in use.');
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The action could not be recorded, so it was not sent. Try again.');
        }
        $body = $this->payload($row, $actor, $role, $requestId) + ['action' => $action, 'idempotencyKey' => $key] + $fields + ($refund ? ['productionComplete' => true] : []);
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_FINANCE_ACTION, $body);
        } catch (SourceAccessException $e) {
            if (in_array($e->codeName, self::NOT_SENT, true)) {
                $this->complete($actionUuid, 'rejected', strtolower($e->codeName));
                throw self::unavailable($e);
            }
            $this->complete($actionUuid, 'unknown', null);
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        return $this->conclude($actionUuid, $answer, $action, $row, $target, $actor, $key);
    }

    /**
     * Settles a pending or unknown action with the shop's stored answer of its Idempotency-Key. A finance employee with
     * `approve` may settle another employee's action of the source.
     *
     * @return array<string, mixed>
     */
    public function reconcile(EmployeeIdentity $actor, string $globalOrderId, mixed $key, string $requestId): array
    {
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_FINANCE_INVALID', 'idempotencyKey is required.', ['field' => 'idempotencyKey']);
        }
        ['row' => $row, 'role' => $role] = $this->context($actor, $globalOrderId, FinanceScopePolicy::VIEW);
        if ($role !== self::ROLE_FINANCE) {
            throw self::denied();
        }
        $approver = $this->finance->allows($actor, FinanceScopePolicy::APPROVE, (string) $row['source_key']);
        $statement = $this->pdo->prepare('SELECT * FROM order_finance_actions WHERE order_uuid = ? AND idempotency_key = ? AND (employee_uuid = ? OR ?) ORDER BY requested_at LIMIT 1');
        $statement->execute([(string) $row['order_uuid'], $key, $actor->employeeUuid, $approver ? 1 : 0]);
        $found = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            throw new ApiException(404, 'CONFIRMARE_FINANCE_ACTION_NOT_FOUND', 'No such finance action of this order was found.');
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsFinanceRead()) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance is not enabled for this shop.');
        }
        $this->rateLimit($actor);
        return $this->settled($found, $row, $target, $actor, $requestId);
    }

    /**
     * The finance tasks of the actor's sources (classes only; amounts are read per order).
     *
     * @return array{tasks: array<string, int>, orders: list<array<string, mixed>>, sources: list<string>}
     */
    public function queue(EmployeeIdentity $actor, ?string $task, ?string $source): array
    {
        $this->authorization->requireApplication($actor, $actor->hasApplication('staff') ? 'staff' : 'dashboard');
        if ($task !== null && !in_array($task, self::TASKS, true)) {
            throw new ApiException(422, 'CONFIRMARE_FINANCE_INVALID', 'Unknown finance task.', ['field' => 'task']);
        }
        $sources = $this->finance->sources($actor, FinanceScopePolicy::VIEW);
        if ($source !== null) {
            $sources = in_array($source, $sources, true) ? [$source] : [];
        }
        $tasks = array_fill_keys(self::TASKS, 0);
        if ($sources === []) {
            return ['tasks' => $tasks, 'orders' => [], 'sources' => []];
        }
        $parameters = [];
        $condition = ConfirmareScopePolicy::condition($sources, 'o.source_key', $parameters);
        $statement = $this->pdo->prepare("SELECT o.global_order_id, o.source_key, o.order_number, o.payment_method, o.production_stage_id, o.operational_status, o.freshness_status, o.source_reported_unavailable_at,
                o.source_financial_state, o.source_financial_basis, o.source_financial_attention, o.source_due_date, o.source_changed_at, c.status AS confirmation_status,
                (SELECT COUNT(*) FROM order_finance_actions a WHERE a.order_uuid = o.order_uuid AND a.state = 'unknown') AS unknown_actions,
                (SELECT COUNT(*) FROM order_finance_actions a WHERE a.order_uuid = o.order_uuid AND a.state = 'pending' AND a.requested_at < ?) AS stale_actions
            FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE o.workflow_cohort = 'confirmare' AND {$condition}
              AND (o.source_financial_attention IS NOT NULL OR o.source_financial_state IS NULL OR o.source_financial_state <> 'cleared' OR o.freshness_status <> 'fresh'
                   OR (o.payment_method = 'cod' AND o.production_stage_id = 'waiting')
                   OR EXISTS (SELECT 1 FROM order_finance_actions a WHERE a.order_uuid = o.order_uuid AND a.state IN ('pending', 'unknown')))
            ORDER BY o.source_changed_at, o.global_order_id LIMIT 500");
        $statement->execute([$this->sql($this->clock->now()->modify('-' . self::RECONCILE_AFTER_SECONDS . ' seconds')), ...$parameters]);
        $orders = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $category = self::task($row);
            if ($category === null) {
                continue;
            }
            $tasks[$category]++;
            if ($task !== null && $task !== $category) {
                continue;
            }
            $orders[] = [
                'globalOrderId' => (string) $row['global_order_id'],
                'source' => (string) $row['source_key'],
                'orderNumber' => (string) $row['order_number'],
                'task' => $category,
                'financial' => ['state' => $row['source_financial_state'], 'basis' => $row['source_financial_basis'], 'attention' => $row['source_financial_attention']],
                'paymentMethod' => $row['payment_method'],
                'stageId' => (string) $row['production_stage_id'],
                'confirmation' => (string) $row['confirmation_status'],
                'dueDate' => $row['source_due_date'] === null ? null : (string) $row['source_due_date'],
                'sourceChangedAt' => $row['source_changed_at'] === null ? null : self::iso((string) $row['source_changed_at']),
            ];
        }
        return ['tasks' => $tasks, 'orders' => $orders, 'sources' => $sources];
    }

    /** Whether a finance action of the order is not settled yet (blocks production: ConfirmationClearance). */
    public static function actionOpen(PDO $pdo, string $orderUuid): bool
    {
        try {
            $statement = $pdo->prepare("SELECT 1 FROM order_finance_actions WHERE order_uuid = ? AND state IN ('pending', 'unknown') LIMIT 1");
            $statement->execute([$orderUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException $e) {
            // Before migration 039 no finance action can exist; any other failure keeps production closed.
            return !str_contains($e->getMessage(), 'order_finance_actions') || !in_array((string) $e->getCode(), ['42S02'], true);
        }
    }

    /**
     * Owner rule: no refund before the final manufacturing stage is complete; courier handover is independent. An order
     * the shop cancelled (also one kept in production because cutting had started) is never eligible: no refund policy
     * for cancellations exists yet.
     */
    public static function refundEligible(string $stageId, string $operationalStatus, bool $cancelledInSource = false): bool
    {
        return !$cancelledInSource && ($operationalStatus === 'handed_over' || ($stageId === self::REFUND_ELIGIBLE_STAGE && $operationalStatus === 'in_progress'));
    }

    /** @param array<string, mixed> $row the queue row */
    private static function task(array $row): ?string
    {
        $attention = $row['source_financial_attention'];
        $state = $row['source_financial_state'];
        $eligible = self::refundEligible((string) $row['production_stage_id'], (string) $row['operational_status'], $row['source_reported_unavailable_at'] !== null);
        return match (true) {
            $attention === 'reconciliation_required' || (int) $row['unknown_actions'] > 0 || (int) $row['stale_actions'] > 0 => 'reconciliation_required',
            $state === null || $state === 'unknown' || $row['freshness_status'] !== 'fresh' => 'source_unavailable',
            $attention === 'refund_pending' => 'refund_processing',
            $attention === 'transfer_pending' => 'transfer_verification',
            $attention === 'refund_liability' && $eligible => 'refund_eligible',
            $attention === 'refund_due' || $attention === 'refund_liability' => 'refund_liability',
            $attention === 'additional_payment_due' => 'additional_payment',
            $row['payment_method'] === 'cod' && $state === 'partial' => 'advance_partial',
            $row['payment_method'] === 'cod' && $state === 'waiting' => 'advance_waiting',
            $row['payment_method'] === 'cod' && $state === 'cleared' && $row['source_financial_basis'] === 'advance_collected' && $row['production_stage_id'] === 'waiting' => 'advance_verified',
            default => null,
        };
    }

    /**
     * The summary with what this employee may do now and the Arasya audit of the order's finance actions.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function present(EmployeeIdentity $actor, array $row, SourceAccessTarget $target, array $summary): array
    {
        $source = (string) $row['source_key'];
        $order = $this->pdo->prepare('SELECT production_stage_id, operational_status, source_reported_unavailable_at FROM operational_orders WHERE order_uuid = ?');
        $order->execute([(string) $row['order_uuid']]);
        $production = $order->fetch(PDO::FETCH_ASSOC) ?: ['production_stage_id' => '', 'operational_status' => '', 'source_reported_unavailable_at' => null];
        $eligible = self::refundEligible((string) $production['production_stage_id'], (string) $production['operational_status'], $production['source_reported_unavailable_at'] !== null);
        $open = self::actionOpen($this->pdo, (string) $row['order_uuid']);
        $recordable = $target->allowsFinanceRecord() && $summary['capabilities']['record'] && !$open;
        $record = $recordable && $this->finance->allows($actor, FinanceScopePolicy::RECORD, $source);
        $approve = $recordable && $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source);
        $attention = $summary['financial']['attention'];
        $state = $summary['financial']['state'];
        $pending = static fn (string $kind): array => array_values(array_filter($summary['entries'], static fn (array $entry): bool => $entry['kind'] === $kind && $entry['state'] === 'submitted'));
        $othersPending = static fn (string $kind): bool => array_filter($pending($kind), static fn (array $entry): bool => $entry['submittedBy'] !== $actor->employeeUuid) !== [];
        $advanceOpen = $summary['order']['cod'] && in_array($state, ['waiting', 'partial'], true) && $summary['advance'] !== null && (float) ($summary['advance']['outstanding'] ?? 0) > 0;
        $refunds = $summary['capabilities']['refunds'] === 'manual';
        $actions = array_keys(array_filter([
            'submit_transfer' => $record && $pending('transfer_in') === [] && ($attention === 'additional_payment_due' || ($advanceOpen && $attention === null)),
            'decide_transfer' => $approve && $othersPending('transfer_in'),
            'acknowledge_liability' => $approve && $attention === 'refund_due',
            'submit_refund' => $record && $refunds && $eligible && $attention === 'refund_liability',
            'decide_refund' => $approve && $refunds && $eligible && $othersPending('refund_out'),
        ]));
        $next = match (true) {
            $open => 'reconcile_action',
            $attention === 'reconciliation_required' => 'reconcile_source',
            $state === 'unknown' => 'source_unknown',
            $attention === 'transfer_pending' => 'verify_transfer',
            $attention === 'additional_payment_due' => 'collect_additional_payment',
            $attention === 'refund_due' => 'acknowledge_refund',
            $attention === 'refund_pending' => 'verify_refund',
            $attention === 'refund_liability' && !$eligible => 'refund_after_production',
            $attention === 'refund_liability' => 'record_refund',
            $advanceOpen => 'collect_advance',
            $state !== 'cleared' => 'wait_online_payment',
            default => 'none',
        };
        $names = [];
        foreach ($summary['entries'] as $entry) {
            $names[] = $entry['submittedBy'];
            if ($entry['decidedBy'] !== null) {
                $names[] = $entry['decidedBy'];
            }
        }
        $timeline = $this->timeline((string) $row['order_uuid']);
        foreach ($timeline as $item) {
            $names[] = $item['employeeId'];
        }
        return [
            'summary' => $summary,
            'production' => ['stageId' => (string) $production['production_stage_id'], 'handedOver' => $production['operational_status'] === 'handed_over', 'refundEligible' => $eligible],
            'allowedActions' => $actions,
            'nextAction' => $next,
            'actionOpen' => $open,
            'timeline' => $timeline,
            // A JSON object also when empty (an empty PHP array would encode as a list).
            'employees' => (object) $this->names(array_values(array_unique($names))),
            'me' => $actor->employeeUuid,
        ];
    }

    /**
     * The finance actions of the order as Arasya recorded them (codes only), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT employee_uuid, action, decision, reason_code, purpose, source_entry_id, idempotency_key, state, outcome, requested_at, completed_at
            FROM order_finance_actions WHERE order_uuid = ? ORDER BY requested_at, action_uuid LIMIT 200');
        $statement->execute([$orderUuid]);
        return array_map(static fn (array $row): array => [
            'employeeId' => (string) $row['employee_uuid'],
            'action' => (string) $row['action'],
            'decision' => $row['decision'],
            'reasonCode' => $row['reason_code'],
            'purpose' => $row['purpose'],
            'entryId' => $row['source_entry_id'],
            'idempotencyKey' => (string) $row['idempotency_key'],
            'state' => (string) $row['state'],
            'outcome' => $row['outcome'],
            'requestedAt' => self::iso((string) $row['requested_at']),
            'completedAt' => $row['completed_at'] === null ? null : self::iso((string) $row['completed_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<string> $uuids
     * @return array<string, string> employee id => display name (Arasya employees only)
     */
    private function names(array $uuids): array
    {
        $uuids = array_values(array_filter($uuids, static fn (string $id): bool => preg_match(self::UUID_PATTERN, $id) === 1));
        if ($uuids === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT employee_uuid, display_name FROM employees WHERE employee_uuid IN (' . implode(', ', array_fill(0, count($uuids), '?')) . ')');
        $statement->execute($uuids);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $employee) {
            $names[(string) $employee['employee_uuid']] = (string) $employee['display_name'];
        }
        return $names;
    }

    /**
     * The validated fields of an action (amounts and references are only relayed, never stored here).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fields(string $action, array $input): array
    {
        $allowed = [...self::FIELDS[$action], 'expectedRevision'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        $invalid = static fn (string $field): ApiException => new ApiException(422, 'CONFIRMARE_FINANCE_INVALID', 'The finance action is not valid.', ['field' => $field]);
        $revision = $input['expectedRevision'] ?? null;
        if (!is_string($revision) || preg_match('/^sha256:[0-9a-f]{64}$/D', $revision) !== 1) {
            throw $invalid('expectedRevision');
        }
        $fields = ['expectedRevision' => $revision];
        if (in_array($action, ['submit_transfer', 'submit_refund'], true)) {
            if ($action === 'submit_transfer') {
                if (!in_array($input['purpose'] ?? null, SourceFinance::PURPOSES, true)) {
                    throw $invalid('purpose');
                }
                $fields['purpose'] = $input['purpose'];
            }
            $amount = $input['amount'] ?? null;
            if (!is_string($amount) || preg_match(self::AMOUNT_PATTERN, $amount) !== 1 || (float) $amount <= 0) {
                throw $invalid('amount');
            }
            $reference = is_string($input['reference'] ?? null) ? trim($input['reference']) : null;
            if ($reference === null || preg_match(self::REFERENCE_PATTERN, $reference) !== 1 || preg_match('/[A-Za-z0-9]{3}/', $reference) !== 1) {
                throw $invalid('reference');
            }
            $date = $input['valueDate'] ?? null;
            if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw $invalid('valueDate');
            }
            $fields += ['amount' => $amount, 'reference' => $reference, 'valueDate' => $date];
        }
        if (str_starts_with($action, 'decide_')) {
            $entry = $input['entryId'] ?? null;
            if (!is_string($entry) || preg_match(self::UUID_PATTERN, $entry) !== 1) {
                throw $invalid('entryId');
            }
            $decision = $input['decision'] ?? null;
            if (!in_array($decision, ['verify', 'reject'], true)) {
                throw $invalid('decision');
            }
            $fields += ['entryId' => $entry, 'decision' => $decision];
            if ($decision === 'reject') {
                if (!in_array($input['reasonCode'] ?? null, SourceFinance::REJECT_REASONS, true)) {
                    throw $invalid('reasonCode');
                }
                $fields['reasonCode'] = $input['reasonCode'];
            } elseif (array_key_exists('reasonCode', $input)) {
                throw $invalid('reasonCode');
            }
        }
        return $fields;
    }

    /**
     * Who the actor is for this order's money (finance, assignee or supervisor), or a refusal.
     *
     * @return array{row: array<string, mixed>, role: string, globalId: string}
     */
    private function context(EmployeeIdentity $actor, string $globalOrderId, string $capability): array
    {
        $this->authorization->requireApplication($actor, $actor->hasApplication('staff') ? 'staff' : 'dashboard');
        $globalId = $this->globalId($globalOrderId);
        $row = $this->row($globalId);
        $source = $row === null ? null : (string) $row['source_key'];
        if ($row === null || (!$this->finance->allows($actor, FinanceScopePolicy::VIEW, $source) && !$this->scopes->sees($actor, $source))) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        if ($row['workflow_cohort'] !== 'confirmare') {
            // Older orders keep their existing workflow; their money is never handled through Confirmare.
            throw new ApiException(409, 'CONFIRMARE_SOURCE_NOT_ELIGIBLE', 'This order is not part of the Confirmare workflow.');
        }
        $role = $this->finance->allows($actor, $capability, $source) ? self::ROLE_FINANCE : ($capability === FinanceScopePolicy::VIEW ? $this->role($actor, $row) : null);
        if ($role === null) {
            throw self::denied();
        }
        return ['row' => $row, 'role' => $role, 'globalId' => $globalId];
    }

    /** @param array<string, mixed> $row the actor's read role now (finance first), or null */
    private function role(EmployeeIdentity $actor, array $row): ?string
    {
        $source = (string) $row['source_key'];
        if ($this->finance->allows($actor, FinanceScopePolicy::VIEW, $source)) {
            return self::ROLE_FINANCE;
        }
        if ($this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)) {
            return ConfirmareSourceAccess::ROLE_SUPERVISOR;
        }
        if ($row['status'] === 'pending' && $row['assigned_employee_uuid'] === $actor->employeeUuid && $this->scopes->allows($actor, ConfirmareScopePolicy::OPERATE, $source)) {
            return ConfirmareSourceAccess::ROLE_ASSIGNEE;
        }
        return null;
    }

    /**
     * The recorded outcome of an action; a pending or unknown one is asked again from the shop.
     *
     * @param array<string, mixed> $found
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function settled(array $found, array $row, SourceAccessTarget $target, EmployeeIdentity $actor, string $requestId): array
    {
        $key = (string) $found['idempotency_key'];
        if ($found['state'] === 'applied') {
            // The amounts are never stored here: Staff reads the summary again.
            return ['state' => 'applied', 'idempotencyKey' => $key, 'entryId' => (string) $found['source_entry_id']];
        }
        if ($found['state'] === 'rejected') {
            throw self::refused(['code' => (string) $found['outcome'], 'revision' => null]);
        }
        $input = ['idempotencyKey' => $key];
        if ($found['employee_uuid'] !== $actor->employeeUuid) {
            $input['actionEmployeeId'] = (string) $found['employee_uuid'];
        }
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_FINANCE_STATUS, $this->payload($row, $actor, self::ROLE_FINANCE, $requestId) + $input);
            if ($answer['status'] !== 200) {
                throw new SourceAccessException('SOURCE_ERROR');
            }
            $status = SourceFinance::status($answer['body']);
        } catch (SourceAccessException) {
            return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => false];
        }
        if ($status['state'] === 'absent') {
            $age = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $found['requested_at'], new \DateTimeZone('UTC')))->getTimestamp();
            if ($age < self::RECONCILE_AFTER_SECONDS) {
                return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => true];
            }
            $this->complete((string) $found['action_uuid'], 'rejected', 'not_received');
            throw self::refused(['code' => 'not_received', 'revision' => null]);
        }
        return $this->conclude((string) $found['action_uuid'], ['status' => (int) $status['status'], 'body' => $status['result']], (string) $found['action'], $row, $target, $actor, $key);
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $answer
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function conclude(string $actionUuid, array $answer, string $action, array $row, SourceAccessTarget $target, EmployeeIdentity $actor, string $key): array
    {
        if ($answer['status'] === 200) {
            try {
                $applied = SourceFinance::applied($answer['body'], $action, (string) $row['source_order_id'], (string) $row['order_number']);
            } catch (SourceAccessException) {
                // The shop says it applied something we cannot read: reconcile, never guess.
                $this->complete($actionUuid, 'unknown', null);
                return ['state' => 'unknown', 'idempotencyKey' => $key];
            }
            $this->complete($actionUuid, 'applied', 'applied', $applied['entryId'], $applied['summary']['revision']);
            $after = $this->row((string) $row['global_order_id']) ?? $row;
            return ['state' => 'applied', 'idempotencyKey' => $key, 'entryId' => $applied['entryId']] + $this->present($actor, $after, $target, $applied['summary']);
        }
        if ($answer['status'] >= 400 && $answer['status'] < 500) {
            try {
                $refusal = SourceFinance::refusal($answer['body']);
            } catch (SourceAccessException) {
                $refusal = null;
            }
            if ($refusal !== null) {
                $this->complete($actionUuid, 'rejected', $refusal['code']);
                throw self::refused($refusal);
            }
        }
        // A 5xx (the shop may have committed before failing to answer) or an unknown code: reconcile.
        $this->complete($actionUuid, 'unknown', null);
        return ['state' => 'unknown', 'idempotencyKey' => $key];
    }

    private function complete(string $actionUuid, string $state, ?string $outcome, ?string $entryId = null, ?string $revisionAfter = null): void
    {
        $done = in_array($state, ['applied', 'rejected'], true);
        $this->pdo->prepare("UPDATE order_finance_actions SET state = ?, outcome = ?, source_entry_id = COALESCE(?, source_entry_id), revision_after = ?, completed_at = ?
            WHERE action_uuid = ? AND state IN ('pending', 'unknown')")->execute([
            $state,
            $outcome === null ? null : substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40),
            $entryId,
            $revisionAfter,
            $done ? $this->now() : null,
            $actionUuid,
        ]);
    }

    /** The employee who recorded the shop entry through Arasya (an applied submit), or null. */
    private function recordedBy(string $orderUuid, string $entryId): ?string
    {
        $statement = $this->pdo->prepare("SELECT employee_uuid FROM order_finance_actions WHERE order_uuid = ? AND source_entry_id = ? AND action IN ('submit_transfer', 'submit_refund') AND state = 'applied' LIMIT 1");
        $statement->execute([$orderUuid, $entryId]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private function audit(array $row, EmployeeIdentity $actor, string $role, string $outcome, string $requestId, ?string $revision = null): bool
    {
        try {
            $this->pdo->prepare('INSERT INTO order_finance_reads (read_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, outcome, finance_revision, request_id, read_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Uuid::v4(), (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], $actor->employeeUuid, $role,
                substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40), $outcome === 'ok' ? $revision : null, $this->requestId($requestId), $this->now(),
            ]);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(array $row, EmployeeIdentity $actor, string $role, string $requestId): array
    {
        return [
            'orderId' => (string) $row['source_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'actor' => ['employeeId' => $actor->employeeUuid, 'role' => $role],
            'requestId' => $this->requestId($requestId),
        ];
    }

    /** @return array<string, mixed>|null */
    private function row(string $globalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, c.status, c.assigned_employee_uuid
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function action(string $employeeUuid, string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_finance_actions WHERE employee_uuid = ? AND idempotency_key = ?');
        $statement->execute([$employeeUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function rateLimit(EmployeeIdentity $actor): void
    {
        if (!$this->rateLimiter->hit('confirmare-finance', $actor->employeeUuid, self::CALLS_PER_MINUTE, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many shop requests. Try again in a minute.');
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

    /** @param array{code: string, revision: ?string} $refusal */
    private static function refused(array $refusal): ApiException
    {
        return new ApiException(409, 'CONFIRMARE_FINANCE_REFUSED', 'The shop refused the finance action; nothing was recorded.', ['reason' => $refusal['code'], 'revision' => $refusal['revision']]);
    }

    private static function unavailable(SourceAccessException $e): ApiException
    {
        return new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was recorded.', ['reason' => strtolower($e->codeName)]);
    }

    private static function denied(): ApiException
    {
        return new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance permission for this action on this order.');
    }

    private static function openError(): ApiException
    {
        return new ApiException(409, 'CONFIRMARE_FINANCE_ACTION_PENDING', 'A finance action of this order is not settled yet. Check it first.');
    }

    private function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private function now(): string
    {
        return $this->sql($this->clock->now());
    }

    private function sql(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
