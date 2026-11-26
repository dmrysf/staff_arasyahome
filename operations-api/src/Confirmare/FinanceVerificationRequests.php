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
use Arasya\Operations\Integration\SourceAccess\SourceInvoice;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Finance tasks (API 2.50.0, migration 044): the explicit finance work of one order, assigned to one finance employee.
 *
 *   operator -> request -> assigned finance employee -> verified | not_received | needs_clarification -> operator
 *
 * - Scope (owner decision 2026-10-29, FinanceTaskScope): every Trend Home and Outlet Perdele order first submitted at
 *   or after 2026-10-22 00:00 Europe/Bucharest, whatever its workflow cohort. Covering an order never means a task for
 *   it: a task exists only when an operator asks for one or the task inventory points one at an existing finance record.
 * - An operator of the order asks for a verification of its advance proforma: in Confirmare the order's assignee or the
 *   employee who approved it (Confirmare `operate`), or a supervisor of the source, once the order is approved; for an
 *   order outside Confirmare (legacy, NULL, undetermined: no Confirmare assignee exists) an operator or supervisor of the
 *   source. The task goes to one finance employee of the source (the least loaded who holds `record` or `verify`;
 *   `verify` first under the `single` policy). An order has at most one open task; asking again returns the open one.
 * - The finance employee sees only the tasks assigned to them (inbox), opens one (a minimized view read from the shop
 *   now: the proforma, the advance, the money already verified, the bank account the proforma names (masked) and the
 *   bank transfers of the order; never the customer, the products or the order's history) and decides with an explicit
 *   confirmation:
 *     verified:  the bank transfer found on the statement (amount, bank reference, value date, the payment reference
 *                the statement shows, the currency).
 *                - `shop` ledger (a Confirmare order): under the `single` policy (Operations and the shop both
 *                  `single`, the employee holds `verify`) the shop records and verifies it in one step
 *                  (`verify_transfer`); under `four_eyes` (the default) the shop records it (`submit_transfer`) and the
 *                  task waits for its named second employee with `approve` (`checker_employee_uuid`, never the
 *                  recorder); nothing is money received before that.
 *                - `manual` ledger (an order outside Confirmare): the shop's finance record is not written for it and
 *                  the order is never moved into Confirmare. The task ends in `requires_manual_review`: the payment
 *                  was found on the statement, the old workflow records it; the advance is NOT marked as received.
 *     not_received / needs_clarification: codes only, nothing is sent to the shop.
 * - Second checks of transfers recorded outside a task and exceptional money states become tasks of a named employee
 *   through FinanceTaskInventory (dry-run first; never from a guess).
 * - A finance supervisor observes the tasks of the employees they supervise (oversight); supervision never acts.
 *
 * Every command names the version it saw and carries an Idempotency-Key. The money actions go through ConfirmareFinance
 * (its order lock, revision check, idempotency, reconciliation and production block); their applied answers settle the
 * task (onFinanceApplied, also when the same entry is decided from the order's finance panel). A lost answer leaves
 * the task in `exception` (`outcome_unknown`) until it is reconciled with the shop's stored answer: never verified on
 * a guess. Nothing here issues a document, creates an AWB, starts production, changes the Confirmare approval, moves an
 * order between workflows or reopens a closed order.
 */
final readonly class FinanceVerificationRequests
{
    public const STATES = ['requested', 'assigned', 'checking', 'verified', 'not_received', 'needs_clarification', 'exception', 'cancelled', 'requires_manual_review', 'resolved'];
    public const OPEN_STATES = ['requested', 'assigned', 'checking', 'needs_clarification', 'exception'];
    public const CLOSED_STATES = ['verified', 'not_received', 'cancelled', 'requires_manual_review', 'resolved'];
    public const PURPOSES = ['advance', 'second_check', 'exception'];
    /** The shop's finance record holds the transfer (Confirmare), or the order keeps its old workflow (manual). */
    public const LEDGER_SHOP = 'shop';
    public const LEDGER_MANUAL = 'manual';
    /** Commercial states of the shop in which an order is closed: no new finance task, nothing reopened. */
    private const CLOSED_COMMERCE = ['cancelled', 'refunded', 'failed', 'completed', 'trash'];
    public const NOT_RECEIVED_REASONS = ['not_found', 'amount_mismatch', 'wrong_reference', 'duplicate'];
    public const CLARIFICATION_REASONS = ['reference_missing', 'amount_unclear', 'payer_unclear', 'other'];
    public const CANCEL_REASONS = ['customer_withdrew', 'already_settled', 'requested_by_mistake'];
    /** The shop's rejection codes of a second check, mapped from the employee's reason. */
    private const SHOP_REJECT = ['not_found' => 'not_received', 'amount_mismatch' => 'amount_mismatch', 'wrong_reference' => 'wrong_order', 'duplicate' => 'duplicate'];
    public const CALLS_PER_MINUTE = 60;
    /** How often the operator's screen asks while finance works on the request (seconds); null: no need to ask. */
    public const POLL_ACTIVE_SECONDS = 5;
    public const POLL_WAITING_SECONDS = 10;
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const AMOUNT_PATTERN = '/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D';
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 .\/-]{2,63}$/D';
    private const DONE_DAYS = 30;
    /**
     * API 2.74.2: the inbox lists the open tasks first, under their own limit, and the closed ones (the last DONE_DAYS)
     * after them under a separate limit, the most recent first. One shared limit over both, oldest first, let 30 days
     * of closed tasks push every task opened after them out of the list: a paid advance never reached finance and the
     * proforma reminders went to customers who had paid.
     */
    private const OPEN_LIMIT = 1000;
    /** The supervisor's oversight list (unchanged). */
    private const LIMIT = 300;
    private const DONE_LIMIT = 100;
    /**
     * API 2.77.9 (owner 2026-11-25, Contabilitate desk): the advances the employee found not received and that no newer
     * request replaced (Așteaptă plata), listed apart from the 100 latest closed tasks so none drops out of sight.
     */
    private const WAITING_LIMIT = 300;
    /** A stored advance amount older than this is read again from the shop by bin/finance-task-amounts.php. */
    /** API 2.81.0: 120 (was 30): the list amount and the display copy are kept by task reads and shop changes too. */
    private const AMOUNT_FRESH_MINUTES = 120;
    /** API 2.81.0: one amounts run stops after this many seconds (the next run continues; runs never overlap). */
    public const AMOUNT_RUN_SECONDS = 240;
    /**
     * API 2.81.0 (P0 2026-11-25): a task's display copy (finance_task_amounts.display_json) opens the task without a shop
     * call while it is younger than this and the shop reported no change of the order since it was read
     * (operational_orders.source_changed_at). Every decision still reads the shop and checks its revision.
     */
    public const DISPLAY_FRESH_MINUTES = 180;
    /** API 2.81.0: when the shop does not answer, a copy up to this old is shown with a warning (nothing can be decided on it). */
    public const DISPLAY_STALE_HOURS = 24;
    /**
     * API 2.80.0 (owner 2026-11-25): a payment not found today is looked for again the next morning at this hour
     * (Europe/Bucharest), never several times a day (bin/finance-payment-tracking.php).
     */
    public const RECHECK_HOUR = 8;
    /** API 2.80.0: one page of the finance employee's closed tasks (history with search). */
    private const HISTORY_PAGE = 50;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private FinanceScopePolicy $finance,
        private ConfirmareScopePolicy $scopes,
        private ConfirmareFinance $money,
        private SourceAccessRegistry $registry,
        private SourceAccessClient $client,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
        #[\SensitiveParameter] private string $hmacKey,
        // API 2.82.0: the automatic next-morning recheck of a payment not found. Retired only with Operation Simplification V1
        // switched on (ARASYA_CONFIRMARE_DECISIONS=enabled: the finance employee opens the order again herself, checkOrder);
        // switched off, the API keeps the 2.81.0 behaviour.
        private bool $autoRecheck = true,
    ) {
    }

    // ---- Operator ---------------------------------------------------------------------------------------------------

    /**
     * The operator's view of the order's request (a database read only: the screen asks it every few seconds while
     * finance works on the request).
     *
     * @return array<string, mixed>
     */
    public function forOrder(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireStaff($actor);
        $row = $this->order($this->globalId($globalOrderId));
        if ($row === null || !$this->operates($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        return $this->operatorView($actor, $row);
    }

    /**
     * Asks finance to verify the advance payment of the order (explicit command). Returns the open request when one
     * exists already (a second tab, a refresh or another operator never creates a second one).
     *
     * @param array<string, mixed> $input {purpose: advance}
     * @return array<string, mixed>
     */
    public function request(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->requireKey($key);
        if (array_diff(array_keys($input), ['purpose']) !== [] || ($input['purpose'] ?? 'advance') !== 'advance') {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'Only an advance payment can be sent to finance.', ['field' => 'purpose']);
        }
        $this->requireStaff($actor);
        $row = $this->order($this->globalId($globalOrderId));
        if ($row === null || !$this->operates($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $replay = $this->byRequesterKey($actor->employeeUuid, $key);
        if ($replay !== null) {
            if ($replay['order_uuid'] !== $row['order_uuid']) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->operatorView($actor, $this->order((string) $row['global_order_id']) ?? $row) + ['created' => false];
        }
        if ($this->openFor((string) $row['order_uuid']) !== null) {
            return $this->operatorView($actor, $row) + ['created' => false];
        }
        $this->requireRequestable($row);
        $ledger = self::ledgerOf($row);
        $target = $this->registry->find((string) $row['source_key']);
        // A Confirmare order's transfer is recorded in the shop (record); an older order's task only reads the shop.
        if ($target === null || ($ledger === self::LEDGER_SHOP ? !$target->allowsFinanceRecord() : !$target->allowsFinanceRead())) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance actions are not enabled for this shop.');
        }
        $this->rateLimit($actor);
        // The proforma and the advance as the shop holds them now (the operator's own read role).
        $role = $this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, (string) $row['source_key']) ? ConfirmareSourceAccess::ROLE_SUPERVISOR : ConfirmareSourceAccess::ROLE_ASSIGNEE;
        $created = $this->create($actor->employeeUuid, $row, $target, $ledger, $role, $key, $requestId, null);
        return $this->operatorView($actor, $this->order((string) $row['global_order_id']) ?? $row) + ['created' => $created === 'created'];
    }

    /**
     * Opens the advance task of an order (the operator's request, or API 2.80.0 the system's payment tracking): reads the
     * shop's proforma and advance, refuses what has nothing to verify, and inserts the request with its `requested` event
     * under the order lock (one open request per order, uq_finance_verification_requests_open).
     *
     * @return string `created` or `exists` (an open request of the order, or the same key, already exists)
     */
    private function create(string $requesterUuid, array $row, SourceAccessTarget $target, string $ledger, string $role, string $key, string $requestId, ?string $reasonCode, ?string $assignTo = null): string
    {
        $summary = $this->summary($target, $row, $requesterUuid, $role, $requestId, $failure);
        if ($reasonCode !== null) {
            // API 2.80.0: the system's payment tracking reads as root; every such read is audited like a finance read.
            $this->auditAs($row, $requesterUuid, $summary === null ? ($failure ?? 'source_unavailable') : 'ok', $requestId, $summary['revision'] ?? null);
        }
        if ($summary === null) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was sent to finance.');
        }
        if (($summary['capabilities']['ledger'] ?? self::LEDGER_SHOP) !== $ledger) {
            // Arasya and the shop disagree on where this order's payments are recorded: a person looks at it first.
            throw new ApiException(409, 'FINANCE_REQUEST_LEDGER_MISMATCH', 'The shop and Arasya disagree on this order\'s workflow. Nothing was sent to finance.');
        }
        $advance = $summary['advance'];
        $proforma = $advance['proforma'] ?? null;
        if (!$summary['order']['cod'] || $advance === null || $proforma === null || ($advance['proformaCancelled'] ?? false)) {
            throw new ApiException(409, 'FINANCE_REQUEST_NO_PROFORMA', 'The order has no valid advance proforma to verify a payment against.');
        }
        if ($summary['financial']['attention'] !== null || ($ledger === self::LEDGER_SHOP && $summary['financial']['state'] === 'unknown')) {
            throw new ApiException(409, 'FINANCE_REQUEST_ATTENTION', 'The money of the order needs another finance decision first.', ['attention' => $summary['financial']['attention']]);
        }
        // An older order's money state may be unknown to the shop (its old workflow kept no advance record): finance
        // looks for the payment, but nothing is ever assumed received. A covered advance is never verified again.
        $outstanding = $advance['outstanding'] ?? null;
        if ($summary['financial']['state'] === 'cleared' || ($ledger === self::LEDGER_SHOP ? (float) ($outstanding ?? 0) <= 0 : ($outstanding !== null && (float) $outstanding <= 0))) {
            throw new ApiException(409, 'FINANCE_REQUEST_NOTHING_DUE', 'The advance is already covered.');
        }
        $now = $this->now();
        $uuid = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([(string) $row['order_uuid']]);
            if ($this->openFor((string) $row['order_uuid'], true) !== null) {
                $this->pdo->rollBack();
                return 'exists';
            }
            $policy = $ledger === self::LEDGER_SHOP && $target->allowsSingleVerification() && ($summary['capabilities']['verification'] ?? 'four_eyes') === 'single' ? 'single' : 'four_eyes';
            $assignee = $assignTo ?? $this->leastLoaded((string) $row['source_key'], $policy);
            $state = $assignee === null ? 'requested' : 'assigned';
            $this->pdo->prepare('INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
                    requested_by_employee_uuid, requested_at, request_idempotency_key, ledger, proforma_series, proforma_number, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $uuid, (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], 'advance', $state, (string) $row['order_uuid'], $assignee, $assignee === null ? null : $now,
                $requesterUuid, $now, $key, $ledger, $proforma['series'] === '' ? null : $proforma['series'], $proforma['number'], $now,
            ]);
            $this->event($uuid, 1, 'requested', $requesterUuid, null, $state, $assignee, null, $reasonCode, null, $key, $requestId, $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && ($this->openFor((string) $row['order_uuid']) !== null || $this->byRequesterKey($requesterUuid, $key) !== null)) {
                // A concurrent request of the same order (or the same key) won: answer with it.
                return 'exists';
            }
            throw new ApiException(503, 'FINANCE_REQUEST_UNAVAILABLE', 'The request could not be recorded. Try again.');
        }
        return 'created';
    }

    /**
     * The operator answers a clarification (after talking to the customer): the request goes back to its finance
     * employee (or the least loaded one when that employee no longer holds the scope).
     *
     * @param array<string, mixed> $input {expectedVersion}
     * @return array<string, mixed>
     */
    public function resubmit(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        return $this->operatorCommand($actor, $globalOrderId, $input, ['expectedVersion'], $key, $requestId, function (array $request, string $now) use ($actor, $key, $requestId): void {
            if ($request['state'] !== 'needs_clarification') {
                throw self::stateError($request);
            }
            $assignee = $request['assigned_employee_uuid'];
            if ($assignee === null || !$this->eligible((string) $assignee, (string) $request['source_key'])) {
                $assignee = $this->leastLoaded((string) $request['source_key'], $this->policyOf((string) $request['source_key']));
            }
            $this->transition($request, $assignee === null ? 'requested' : 'assigned', 'resubmitted', $actor->employeeUuid, null, null, $key, $requestId, $now, ['assigned_employee_uuid' => $assignee, 'assigned_at' => $assignee === null ? null : $now]);
        });
    }

    /**
     * The operator withdraws the request (the customer withdrew, the advance was settled otherwise, a mistake). Not
     * while a bank transfer of it is recorded in the shop or its answer is unknown.
     *
     * @param array<string, mixed> $input {expectedVersion, reasonCode}
     * @return array<string, mixed>
     */
    public function cancel(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        return $this->operatorCommand($actor, $globalOrderId, $input, ['expectedVersion', 'reasonCode'], $key, $requestId, function (array $request, string $now) use ($actor, $input, $key, $requestId): void {
            if (!in_array($input['reasonCode'] ?? null, self::CANCEL_REASONS, true)) {
                throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'reasonCode is not valid.', ['field' => 'reasonCode']);
            }
            if (!in_array($request['state'], ['requested', 'assigned', 'needs_clarification'], true) || $request['finance_idempotency_key'] !== null || $request['source_entry_id'] !== null) {
                throw self::stateError($request);
            }
            $this->transition($request, 'cancelled', 'cancelled', $actor->employeeUuid, null, (string) $input['reasonCode'], $key, $requestId, $now, ['reason_code' => $input['reasonCode']]);
        });
    }

    // ---- Finance employee -------------------------------------------------------------------------------------------

    /**
     * API 2.82.0 (Operation Simplification V1, owner decision 2026-11-26): the finance employee finds any order of her shops
     * by order number or proforma number, with or without a task (an operator may simply tell her the customer paid).
     * At most LOOKUP_LIMIT rows: the order, its shop, the latest proforma Arasya knows, the latest task and its state.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function lookup(EmployeeIdentity $actor, mixed $query): array
    {
        $this->requireStaff($actor);
        $sources = $this->financeSources($actor);
        if ($sources === []) {
            throw new ApiException(403, 'FINANCE_SCOPE_DENIED', 'You have no finance scope.');
        }
        $q = is_string($query) ? trim($query) : '';
        if (preg_match('/^[A-Za-z]{0,10}[\s-]*0*([0-9]{1,12})$/D', $q, $m) !== 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Search by an order number or a proforma number.', ['field' => 'q']);
        }
        $digits = $m[1];
        $in = implode(', ', array_fill(0, count($sources), '?'));
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.payment_method, o.source_financial_state,
                r.request_uuid, r.state, r.proforma_series, r.proforma_number, r.assigned_employee_uuid, e.display_name AS assignee
            FROM operational_orders o
            LEFT JOIN finance_verification_requests r ON r.request_uuid = (SELECT r2.request_uuid FROM finance_verification_requests r2 WHERE r2.order_uuid = o.order_uuid ORDER BY r2.requested_at DESC LIMIT 1)
            LEFT JOIN employees e ON e.employee_uuid = r.assigned_employee_uuid
            WHERE o.source_key IN ({$in}) AND o.workflow_cohort = 'confirmare' AND (o.order_number = ? OR EXISTS (SELECT 1 FROM finance_verification_requests p
                WHERE p.order_uuid = o.order_uuid AND p.proforma_number IS NOT NULL AND TRIM(LEADING '0' FROM p.proforma_number) = ?))
            ORDER BY o.created_at DESC LIMIT " . self::LOOKUP_LIMIT);
        $statement->execute([...$sources, $digits, ltrim($digits, '0') === '' ? '0' : ltrim($digits, '0')]);
        return ['items' => array_map(static fn (array $row): array => [
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'sourceKey' => (string) $row['source_key'],
            'cod' => $row['payment_method'] === 'cod',
            'financialState' => $row['source_financial_state'],
            'proforma' => $row['proforma_number'] === null ? null : ['series' => $row['proforma_series'], 'number' => (string) $row['proforma_number']],
            'task' => $row['request_uuid'] === null ? null : ['id' => (string) $row['request_uuid'], 'state' => (string) $row['state'], 'assignee' => $row['assignee'],
                'mine' => $row['assigned_employee_uuid'] === $actor->employeeUuid],
        ], $statement->fetchAll(PDO::FETCH_ASSOC))];
    }

    public const LOOKUP_LIMIT = 20;

    /**
     * API 2.82.0: the finance employee starts (or continues) the payment check of an order herself, without an operator's
     * request and whenever she wants, also minutes after her "NU". Answers the task to open:
     * - an open task of the order assigned to her: that one;
     * - an open task not yet being checked by a colleague (requested, assigned): it moves to her (audited);
     * - a colleague is checking it right now: refused (FINANCE_REQUEST_BUSY), nothing is overwritten;
     * - no open task: a new one assigned to her, after the same shop read as every task (a valid advance proforma, something
     *   due; a covered order is refused). Earlier tasks and their decisions stay as they are (the history is kept).
     *
     * @param array<string, mixed> $input {globalOrderId}
     * @return array{requestId: string, created: bool}
     */
    public function checkOrder(EmployeeIdentity $actor, array $input, string $key, string $requestId): array
    {
        $this->requireStaff($actor);
        $this->requireKey($key);
        if (array_diff(array_keys($input), ['globalOrderId']) !== [] || !is_string($input['globalOrderId'] ?? null)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'globalOrderId is required.');
        }
        $row = $this->order($this->globalId((string) $input['globalOrderId']));
        if ($row === null || !in_array((string) $row['source_key'], $this->financeSources($actor), true)) {
            throw new ApiException(404, 'FINANCE_REQUEST_NOT_FOUND', 'No order was found.');
        }
        $open = $this->openFor((string) $row['order_uuid']);
        if ($open !== null) {
            if ($open['assigned_employee_uuid'] === $actor->employeeUuid) {
                return ['requestId' => (string) $open['request_uuid'], 'created' => false];
            }
            if ($open['state'] === 'checking' || $open['finance_idempotency_key'] !== null || $open['source_entry_id'] !== null || !in_array($open['state'], ['requested', 'assigned', 'needs_clarification'], true)) {
                throw new ApiException(409, 'FINANCE_REQUEST_BUSY', 'A colleague is checking this payment right now.');
            }
            $moved = false;
            $this->locked((string) $open['request_uuid'], function (array $locked) use ($actor, $key, $requestId, &$moved): void {
                if (!in_array($locked['state'], ['requested', 'assigned', 'needs_clarification'], true) || $locked['finance_idempotency_key'] !== null || $locked['source_entry_id'] !== null) {
                    return;
                }
                $this->transition($locked, 'assigned', 'reassigned', $actor->employeeUuid, null, 'finance_self_check', $key, $requestId, $this->now(),
                    ['assigned_employee_uuid' => $actor->employeeUuid, 'assigned_at' => $this->now()]);
                $moved = true;
            });
            if ($moved !== true) {
                throw new ApiException(409, 'FINANCE_REQUEST_BUSY', 'A colleague is checking this payment right now.');
            }
            return ['requestId' => (string) $open['request_uuid'], 'created' => false];
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsFinanceRecord()) {
            throw new ApiException(409, 'FINANCE_DISABLED', 'Finance is not enabled for this shop.');
        }
        $this->requireRequestable($row);
        $created = $this->create($actor->employeeUuid, $row, $target, self::LEDGER_SHOP, ConfirmareFinance::ROLE_FINANCE, $key, $requestId, 'finance_self_check', $actor->employeeUuid);
        $request = $this->openFor((string) $row['order_uuid']);
        if ($request === null) {
            throw new ApiException(503, 'FINANCE_REQUEST_UNAVAILABLE', 'The task could not be opened. Try again.');
        }
        return ['requestId' => (string) $request['request_uuid'], 'created' => $created === 'created'];
    }

    /** @return list<string> the shops in which the actor records or verifies payments */
    private function financeSources(EmployeeIdentity $actor): array
    {
        $statement = $this->pdo->prepare("SELECT DISTINCT source_key FROM employee_finance_scopes WHERE employee_uuid = ? AND capability IN ('record', 'verify') ORDER BY source_key");
        $statement->execute([$actor->employeeUuid]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }


    /**
     * The finance employee's own tasks: the tasks assigned to them and the second checks they are the named checker of
     * (four eyes). Nothing else of any order, whatever the employee's scopes.
     *
     * @return array<string, mixed>
     */
    public function inbox(EmployeeIdentity $actor): array
    {
        $this->requireStaff($actor);
        $scopes = $this->financeScopes($actor);
        $sources = array_values(array_unique(array_merge(...array_values($scopes))));
        if ($sources === []) {
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance scope.');
        }
        $this->rebalance($scopes);
        $since = $this->sql($this->clock->now()->modify('-' . self::DONE_DAYS . ' days'));
        // Only what is assigned to this employee by name: their tasks, what they decided, the second checks they are the
        // named checker of, in the employee's sources. A scope alone lists nothing of the source. The same rows as
        // before 2.74.2, split in two: an open request has open_order_uuid set and a closed one has none
        // (CHECK chk_finance_verification_requests_open).
        $e = $actor->employeeUuid;
        $in = 'r.source_key IN (' . implode(', ', array_fill(0, count($sources), '?')) . ')';
        $openWhere = "{$in} AND r.open_order_uuid IS NOT NULL AND (r.assigned_employee_uuid = ? OR r.checker_employee_uuid = ? OR (r.decided_by_employee_uuid = ? AND r.updated_at >= ?))";
        $openArgs = [...$sources, $e, $e, $e, $since];
        $doneWhere = "{$in} AND r.open_order_uuid IS NULL AND r.updated_at >= ? AND (r.assigned_employee_uuid = ? OR r.decided_by_employee_uuid = ? OR r.checker_employee_uuid = ?)";
        $doneArgs = [...$sources, $since, $e, $e, $e];
        $from = 'FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid';
        // API 2.77.9: the advance amount the shop last showed for the task (finance_task_amounts, written by this
        // employee's own task reads and by bin/finance-task-amounts.php). One join, never a shop call per row.
        $select = 'SELECT r.*, o.order_number, COALESCE(o.source_submitted_at, o.created_at) AS ordered_at, a.currency AS amount_currency, a.required_amount, a.remaining_amount, a.read_at AS amount_read_at';
        $fromAmounts = "{$from} LEFT JOIN finance_task_amounts a ON a.request_uuid = r.request_uuid";
        $open = $this->pdo->prepare("{$select} {$fromAmounts} WHERE {$openWhere} ORDER BY r.requested_at, r.request_uuid LIMIT " . (self::OPEN_LIMIT + 1));
        $open->execute($openArgs);
        $openRows = $open->fetchAll(PDO::FETCH_ASSOC);
        $done = $this->pdo->prepare("{$select} {$fromAmounts} WHERE {$doneWhere} ORDER BY r.updated_at DESC, r.request_uuid LIMIT " . (self::DONE_LIMIT + 1));
        $done->execute($doneArgs);
        $doneRows = $done->fetchAll(PDO::FETCH_ASSOC);
        // Așteaptă plata: a closed `not_received` advance of this employee that no later request of the order replaced.
        $waitingWhere = "{$doneWhere} AND r.state = 'not_received' AND r.purpose = 'advance'
            AND NOT EXISTS (SELECT 1 FROM finance_verification_requests n WHERE n.order_uuid = r.order_uuid AND n.requested_at > r.requested_at)";
        $waiting = $this->pdo->prepare("{$select} {$fromAmounts} WHERE {$waitingWhere} ORDER BY r.requested_at, r.request_uuid LIMIT " . (self::WAITING_LIMIT + 1));
        $waiting->execute($doneArgs);
        $waitingRows = $waiting->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($openRows) > self::OPEN_LIMIT || count($doneRows) > self::DONE_LIMIT || count($waitingRows) > self::WAITING_LIMIT;
        $waitingIds = array_fill_keys(array_map(static fn (array $row): string => (string) $row['request_uuid'], array_slice($waitingRows, 0, self::WAITING_LIMIT)), true);
        $items = [];
        $seen = [];
        foreach ([...array_slice($openRows, 0, self::OPEN_LIMIT), ...array_slice($doneRows, 0, self::DONE_LIMIT), ...array_slice($waitingRows, 0, self::WAITING_LIMIT)] as $row) {
            $id = (string) $row['request_uuid'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $items[] = $this->item($row + ['awaiting_payment' => isset($waitingIds[$id])], $actor);
        }
        // The counts are of every request of the inbox, also when the list itself is cut at a limit.
        $counts = ['todo' => 0, 'clarification' => 0, 'done' => 0];
        $count = $this->pdo->prepare("SELECT r.state, COUNT(*) AS n {$from} WHERE ({$openWhere}) OR ({$doneWhere}) GROUP BY r.state");
        $count->execute([...$openArgs, ...$doneArgs]);
        foreach ($count->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[self::category((string) $row['state'])] += (int) $row['n'];
        }
        $waitingCount = $this->pdo->prepare("SELECT COUNT(*) {$from} WHERE {$waitingWhere}");
        $waitingCount->execute($doneArgs);
        $counts['waiting'] = (int) $waitingCount->fetchColumn();
        // Bugün tamamlanan: the tasks this employee decided since 00:00 Bucharest (verified, not received, a clarification).
        $today = $this->sql($this->clock->now()->setTimezone(new DateTimeZone('Europe/Bucharest'))->setTime(0, 0));
        $todayCount = $this->pdo->prepare("SELECT COUNT(DISTINCT e.request_uuid) FROM finance_verification_request_events e INNER JOIN finance_verification_requests r ON r.request_uuid = e.request_uuid
            WHERE {$in} AND e.actor_employee_uuid = ? AND e.occurred_at >= ? AND e.new_state IN ('verified', 'not_received', 'needs_clarification', 'requires_manual_review')");
        $todayCount->execute([...$sources, $e, $today]);
        $counts['today'] = (int) $todayCount->fetchColumn();
        // `limit` keeps the Staff footnote (items.length >= limit) true exactly when something was left out.
        return ['items' => $items, 'counts' => $counts, 'sources' => $sources, 'limit' => $truncated ? count($items) : count($items) + 1, 'truncated' => $truncated];
    }

    /**
     * One task (Verificarea încasării): the request and the money the shop holds now, minimized. Audited.
     *
     * @return array<string, mixed>
     */
    public function task(EmployeeIdentity $actor, string $requestUuid, string $requestId): array
    {
        $this->requireStaff($actor);
        $request = $this->reachable($actor, $requestUuid);
        $row = $this->orderByUuid((string) $request['order_uuid']);
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        $live = ['available' => false, 'reason' => 'not_configured'];
        if ($row !== null && $target !== null && $target->allowsFinanceRead()) {
            // API 2.81.0 (P0 2026-11-25): an open advance check opens from the display copy when the shop reported no
            // change since it was read (no shop call: the shop's slowness no longer holds the screen). Audited as
            // `cache_ok`. Everything else, and a task without a valid copy, is read live as before.
            $cached = $this->cachedLive($target, $row, $request, false);
            if ($cached !== null) {
                $this->audit($row, $actor, 'cache_ok', $requestId);
                $live = $cached;
            } else {
                $this->rateLimit($actor);
                $live = $this->live($target, $row, $request, $actor->employeeUuid, $requestId);
                if (!$live['available']) {
                    // The shop did not answer: the last copy is shown with its age, marked stale. A stale copy enables no
                    // command by itself: every decision reads the shop again (and is refused while it does not answer).
                    $live = $this->cachedLive($target, $row, $request, true, (string) ($live['reason'] ?? 'source_unavailable')) ?? $live;
                }
            }
        }
        return ['request' => $this->item($request + ['order_number' => $row['order_number'] ?? ''], $actor), 'live' => $live, 'progress' => $this->progress((string) $request['order_uuid'])]
            + $this->allowed($actor, $request, $live, $target);
    }

    /**
     * API 2.80.0 (owner 2026-11-25, "where is the order"): where the order stands for the finance employee, from Arasya's
     * records only (no shop call): the operator's approval and call, the money, production clearance and what is missing,
     * the shop employee it is routed to, the production stage. No customer data, no amount.
     *
     * @return array<string, mixed>
     */
    private function progress(string $orderUuid): array
    {
        $statement = $this->pdo->prepare("SELECT o.production_stage_id, o.source_financial_state, o.cancellation_state, o.operational_status, o.source_reported_unavailable_at, o.source_commerce_status_code,
                c.status, c.call_confirmed_at, c.production_cleared, c.assigned_employee_uuid, c.decided_by_employee_uuid, a.employee_uuid AS routed_to, a.assigned_at AS routed_at
            FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid LEFT JOIN order_shop_assignments a ON a.active_order_uuid = o.order_uuid WHERE o.order_uuid = ?");
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['known' => false];
        }
        $names = $this->names(array_values(array_filter([$row['routed_to'], $row['assigned_employee_uuid'], $row['decided_by_employee_uuid']], 'is_string')));
        $person = static fn (?string $id): ?array => $id === null ? null : ['displayName' => $names[$id] ?? ''];
        return [
            'known' => true,
            'approval' => $row['status'],
            'callConfirmed' => $row['call_confirmed_at'] !== null,
            'paymentCleared' => $row['source_financial_state'] === 'cleared',
            'productionCleared' => (bool) $row['production_cleared'],
            'missing' => $row['status'] === null ? [] : ConfirmationClearance::missing($this->pdo, $orderUuid),
            'operator' => $person($row['decided_by_employee_uuid'] ?? $row['assigned_employee_uuid']),
            'routedTo' => $person($row['routed_to']),
            'routedAt' => $row['routed_at'] === null ? null : self::iso((string) $row['routed_at']),
            'stage' => $row['production_stage_id'],
            'cancelled' => $row['cancellation_state'] !== null || $row['operational_status'] === 'unavailable' || $row['source_reported_unavailable_at'] !== null
                || in_array($row['source_commerce_status_code'], ['cancelled', 'refunded', 'failed', 'trash'], true),
        ];
    }

    /**
     * The finance employee starts checking the bank statement for this request (assigned -> checking).
     *
     * @param array<string, mixed> $input {expectedVersion}
     * @return array<string, mixed>
     */
    public function start(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        return $this->financeCommand($actor, $requestUuid, $input, ['expectedVersion'], $key, function (array $request, string $now) use ($actor, $key, $requestId): void {
            if ($request['state'] !== 'assigned' || $request['assigned_employee_uuid'] !== $actor->employeeUuid) {
                throw self::stateError($request);
            }
            $this->transition($request, 'checking', 'checking_started', $actor->employeeUuid, null, null, $key, $requestId, $now);
        });
    }

    /**
     * The finance employee's decision (explicitly confirmed in Staff).
     *
     * @param array<string, mixed> $input {expectedVersion, outcome: verified|not_received|needs_clarification, expectedRevision?,
     *                                    amount?, reference?, valueDate?, paymentReference?, currency?, reasonCode?}
     * @return array<string, mixed>
     */
    public function decide(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        $this->requireKey($key);
        $outcome = $input['outcome'] ?? null;
        if ($outcome === 'not_received' || $outcome === 'needs_clarification') {
            $reasons = $outcome === 'not_received' ? self::NOT_RECEIVED_REASONS : self::CLARIFICATION_REASONS;
            // API 2.82.0 (Operation Simplification V1): "NU" needs no reason; the payment was not found at this moment.
            if ($outcome === 'not_received' && !array_key_exists('reasonCode', $input)) {
                $input['reasonCode'] = 'not_found';
            }
            return $this->financeCommand($actor, $requestUuid, $input, ['expectedVersion', 'outcome', 'reasonCode'], $key, function (array $request, string $now) use ($actor, $outcome, $reasons, $input, $key, $requestId): void {
                if (!in_array($input['reasonCode'] ?? null, $reasons, true)) {
                    throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'reasonCode is not valid.', ['field' => 'reasonCode']);
                }
                if ($request['purpose'] !== 'advance' || !in_array($request['state'], ['assigned', 'checking', 'exception'], true) || $request['assigned_employee_uuid'] !== $actor->employeeUuid
                    || $request['source_entry_id'] !== null || $request['finance_idempotency_key'] !== null) {
                    // A transfer recorded in the shop is decided by the second check; an unknown answer is reconciled.
                    throw self::stateError($request);
                }
                $extra = ['reason_code' => $input['reasonCode']];
                if ($outcome === 'not_received') {
                    $extra += ['outcome' => 'not_received', 'decided_by_employee_uuid' => $actor->employeeUuid, 'decided_at' => $now];
                }
                $this->transition($request, $outcome, $outcome, $actor->employeeUuid, $outcome === 'not_received' ? 'not_received' : null, (string) $input['reasonCode'], $key, $requestId, $now, $extra);
            });
        }
        if ($outcome !== 'verified') {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'outcome must be verified, not_received or needs_clarification.', ['field' => 'outcome']);
        }
        $allowed = ['expectedVersion', 'outcome', 'expectedRevision', 'amount', 'reference', 'valueDate', 'paymentReference', 'currency', 'matchBasis', 'payerName', 'matchNote'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        $evidence = $this->evidence($input);
        $this->requireStaff($actor);
        $request = $this->reachable($actor, $requestUuid);
        $replay = $this->eventByKey($actor->employeeUuid, $key);
        if ($replay !== null) {
            if ($replay['request_uuid'] !== $request['request_uuid']) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->afterDecision($actor, $request, $requestId);
        }
        if ($request['purpose'] !== 'advance' || !in_array($request['state'], ['assigned', 'checking'], true) || $request['assigned_employee_uuid'] !== $actor->employeeUuid
            || $request['source_entry_id'] !== null || $request['finance_idempotency_key'] !== null) {
            throw self::stateError($request);
        }
        $this->requireVersion($request, $input['expectedVersion'] ?? null);
        $row = $this->orderByUuid((string) $request['order_uuid']);
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        $manual = $request['ledger'] === self::LEDGER_MANUAL;
        if ($row === null || $target === null || ($manual ? !$target->allowsFinanceRead() : !$target->allowsFinanceRecord())) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance actions are not enabled for this shop.');
        }
        if ($row['closed']) {
            throw new ApiException(409, 'FINANCE_REQUEST_ORDER_CLOSED', 'The order was cancelled or closed in the shop.');
        }
        $this->rateLimit($actor);
        // The shop's money now: the proforma must be the requested one, the currency and the payment reference its own.
        $summary = $this->summary($target, $row, $actor->employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId);
        if ($summary === null) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was recorded.');
        }
        if (!hash_equals($summary['revision'], $evidence['expectedRevision'])) {
            // API 2.81.0: the task was shown from older figures: its display copy is dropped, so it opens live again.
            self::forgetDisplay($this->pdo, (string) $request['order_uuid']);
            throw new ApiException(409, 'FINANCE_REQUEST_STALE', 'The money of the order changed. Open the task again.', ['revision' => $summary['revision']]);
        }
        $proforma = $summary['advance']['proforma'] ?? null;
        if ($proforma === null || $proforma['number'] !== $request['proforma_number'] || ($proforma['series'] === '' ? null : $proforma['series']) !== $request['proforma_series'] || ($summary['advance']['proformaCancelled'] ?? false)) {
            self::forgetDisplay($this->pdo, (string) $request['order_uuid']);
            throw new ApiException(409, 'FINANCE_REQUEST_PROFORMA_CHANGED', 'The advance proforma of this request changed or was cancelled. Ask the operator.');
        }
        if ($evidence['currency'] !== $summary['currency']) {
            throw new ApiException(422, 'FINANCE_REQUEST_CURRENCY', 'The payment is in another currency than the order.', ['field' => 'currency']);
        }
        $expectedReference = $proforma['reference'] ?? $summary['advance']['paymentReference'] ?? null;
        $byReference = $evidence['matchBasis'] === null || $evidence['matchBasis'] === 'proforma_reference';
        if ($byReference && ($expectedReference === null || !hash_equals((string) $expectedReference, (string) $evidence['paymentReference']))) {
            throw new ApiException(422, 'FINANCE_REQUEST_PAYMENT_REFERENCE', 'The payment reference on the statement is not the one of this proforma. Name the payer instead.', ['field' => 'paymentReference']);
        }
        if (!$byReference && !$manual && !($summary['capabilities']['match'] ?? false)) {
            // A shop before YD SOFT 2.32.0 only knows the proforma reference: nothing is sent.
            throw new ApiException(409, 'FINANCE_REQUEST_MATCH_UNSUPPORTED', 'The shop does not accept a payment matched by the payer yet. Nothing was recorded.');
        }
        if ($manual) {
            // An order outside Confirmare: the shop's finance record is not written and the order is not moved. The
            // payment found on the statement is handed to the old workflow; it is never money received here.
            if (!$this->finance->allows($actor, FinanceScopePolicy::RECORD, (string) $row['source_key'])) {
                throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance permission for this action on this order.');
            }
            $hmac = hash_hmac('sha256', json_encode([$request['request_uuid'], $evidence['amount'], $evidence['reference'], $evidence['valueDate'], $evidence['paymentReference'], $evidence['currency'], $evidence['matchBasis'], $evidence['payerName'], $evidence['matchNote']], JSON_THROW_ON_ERROR), $this->hmacKey);
            $this->command($actor, $request, ['expectedVersion' => $input['expectedVersion']], $key, function (array $locked, string $now) use ($actor, $hmac, $key, $requestId): void {
                if ($locked['state'] !== 'assigned' && $locked['state'] !== 'checking') {
                    throw self::stateError($locked);
                }
                $this->transition($locked, 'requires_manual_review', 'found_manual_review', $actor->employeeUuid, 'found_manual', 'legacy_workflow', $key, $requestId, $now,
                    ['outcome' => 'found_manual', 'reason_code' => 'legacy_workflow', 'decided_by_employee_uuid' => $actor->employeeUuid, 'decided_at' => $now, 'evidence_hmac' => $hmac]);
            });
            return $this->afterDecision($actor, $this->requestRow((string) $request['request_uuid']) ?? $request, $requestId);
        }
        $single = $target->allowsSingleVerification() && ($summary['capabilities']['verification'] ?? 'four_eyes') === 'single'
            && $this->finance->allows($actor, FinanceScopePolicy::VERIFY, (string) $row['source_key']);
        if (!$single && !$this->finance->allows($actor, FinanceScopePolicy::RECORD, (string) $row['source_key'])) {
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance permission for this action on this order.');
        }
        if (!$single && !$byReference) {
            // The four-eyes record carries no payer: a payer match is a single verification only.
            throw new ApiException(409, 'FINANCE_REQUEST_MATCH_UNSUPPORTED', 'A payment matched by the payer is verified under the single policy only. Nothing was recorded.');
        }
        $action = $single ? 'verify_transfer' : 'submit_transfer';
        $policy = $single ? 'single' : 'four_eyes';
        $financeKey = 'fvr-' . str_replace('-', '', (string) $request['request_uuid']) . '-v' . ((int) $request['version'] + 1);
        $hmac = hash_hmac('sha256', json_encode([$request['request_uuid'], $evidence['amount'], $evidence['reference'], $evidence['valueDate'], $evidence['paymentReference'], $evidence['currency'], $evidence['matchBasis'], $evidence['payerName'], $evidence['matchNote']], JSON_THROW_ON_ERROR), $this->hmacKey);
        // 1. The request records what is being sent (a lost answer is then reconciled with this key).
        $now = $this->now();
        $this->locked((string) $request['request_uuid'], function (array $locked) use ($request, $actor, $policy, $financeKey, $hmac, $key, $requestId, $now): void {
            if ((int) $locked['version'] !== (int) $request['version'] || $locked['finance_idempotency_key'] !== null) {
                throw new ApiException(409, 'FINANCE_REQUEST_STALE', 'The request changed. Open it again.', ['version' => (int) $locked['version']]);
            }
            $this->transition($locked, 'checking', 'decision_sent', $actor->employeeUuid, null, null, $key, $requestId, $now,
                ['policy' => $policy, 'finance_idempotency_key' => $financeKey, 'evidence_hmac' => $hmac], $policy);
        });
        // 2. The shop applies it (ConfirmareFinance: order lock, revision, idempotency, one action per order).
        $fields = ['expectedRevision' => $evidence['expectedRevision'], 'purpose' => 'advance', 'amount' => $evidence['amount'], 'reference' => $evidence['reference'], 'valueDate' => $evidence['valueDate']]
            + ($single ? array_filter(['paymentReference' => $evidence['paymentReference'], 'currency' => $evidence['currency'], 'bankStatementConfirmed' => true,
                'matchBasis' => $evidence['matchBasis'], 'payerName' => $evidence['payerName'], 'matchNote' => $evidence['matchNote']], static fn (mixed $v): bool => $v !== null) : []);
        try {
            $answer = $this->money->act($actor, (string) $row['global_order_id'], $action, $fields, $financeKey, $requestId);
        } catch (ApiException $e) {
            // Refused (nothing recorded in the shop): the request is back in `checking` without the key.
            $this->locked((string) $request['request_uuid'], function (array $locked) use ($actor, $e, $requestId): void {
                if ($locked['state'] === 'checking' && $locked['source_entry_id'] === null) {
                    $reason = is_string($e->details['reason'] ?? null) ? (string) $e->details['reason'] : strtolower($e->errorCode);
                    $this->transition($locked, 'checking', 'decision_refused', $actor->employeeUuid, null, substr(preg_replace('/[^a-z_]/', '', $reason) ?? 'refused', 0, 40), null, $requestId, $this->now(),
                        ['finance_idempotency_key' => null, 'evidence_hmac' => null]);
                }
            });
            throw $e;
        }
        if (($answer['state'] ?? null) !== 'applied') {
            $this->markUnknown((string) $request['request_uuid'], $actor->employeeUuid, $requestId);
        }
        return $this->afterDecision($actor, $this->requestRow((string) $request['request_uuid']) ?? $request, $requestId);
    }

    /**
     * API 2.80.0 (owner 2026-11-25, "Adaugă o plată"): a later bank transfer of the same order, recorded and verified by the
     * finance employee who verified the task's advance, without sending anything back to the operator. Relayed to the shop
     * as `verify_transfer` (ConfirmareFinance: order lock, revision, idempotency per key, lost answers reconciled); the shop
     * refuses more than the order still costs and a bank reference already used.
     *
     * @param array<string, mixed> $input {expectedRevision, amount, reference, valueDate, currency, bankStatementConfirmed: true,
     *                                    matchBasis, payerName?, matchNote?, paymentReference?}
     * @return array<string, mixed>
     */
    public function addPayment(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        return $this->followUp($actor, $requestUuid, $input, $key, $requestId, 'verify_transfer');
    }

    /**
     * API 2.80.0 (owner 2026-11-25, "Corectează plata"): the finance employee corrects the advance transfer they verified
     * (amount, bank reference or date) with a reason, before production starts and before a final invoice or an AWB exists
     * (both checked again: Arasya the stage, the shop the documents). The old entry is kept as corrected.
     *
     * @param array<string, mixed> $input {expectedRevision, entryId, amount, reference, valueDate, currency, reasonNote,
     *                                    bankStatementConfirmed: true, matchBasis?, payerName?, matchNote?}
     * @return array<string, mixed>
     */
    public function correctPayment(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        return $this->followUp($actor, $requestUuid, $input, $key, $requestId, 'correct_transfer');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function followUp(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId, string $action): array
    {
        $this->requireKey($key);
        $this->requireStaff($actor);
        $request = $this->reachable($actor, $requestUuid);
        if (!$this->paymentFollowUp($actor, $request)) {
            throw self::stateError($request);
        }
        $fields = ['expectedRevision', 'amount', 'reference', 'valueDate', 'currency', 'bankStatementConfirmed', 'matchBasis', 'payerName', 'matchNote']
            + ($action === 'verify_transfer' ? [9 => 'paymentReference'] : [9 => 'entryId', 10 => 'reasonNote']);
        if (array_diff(array_keys($input), $fields) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $fields) . ' are accepted.');
        }
        if ($action === 'verify_transfer' && ($input['matchBasis'] ?? null) === null) {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'State how the payment was matched to the order.', ['field' => 'matchBasis']);
        }
        $row = $this->orderByUuid((string) $request['order_uuid']);
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        if ($row === null || $target === null || !$target->allowsFinanceRecord()) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance actions are not enabled for this shop.');
        }
        if ($row['closed']) {
            throw new ApiException(409, 'FINANCE_REQUEST_ORDER_CLOSED', 'The order was cancelled or closed in the shop.');
        }
        $answer = $this->money->act($actor, (string) $row['global_order_id'], $action, $action === 'verify_transfer' ? $input + ['purpose' => 'advance'] : $input, $key, $requestId);
        if (($answer['state'] ?? null) === 'applied') {
            $this->noteFollowUp($request, $actor, $action === 'verify_transfer' ? 'payment_added' : 'payment_corrected', $key, $requestId);
        }
        $fresh = $this->requestRow((string) $request['request_uuid']) ?? $request;
        return ['state' => (string) ($answer['state'] ?? 'unknown'), 'idempotencyKey' => $key, 'request' => $this->item($fresh + ['order_number' => $row['order_number']], $actor)];
    }

    /** The task's own trace of a follow-up payment (once per idempotency key; the money action itself is audited). */
    private function noteFollowUp(array $request, EmployeeIdentity $actor, string $what, string $key, string $requestId): void
    {
        $eventKey = 'fu-' . substr(hash('sha256', $what . '|' . $key), 0, 40);
        if ($this->eventByKey($actor->employeeUuid, $eventKey) !== null) {
            return;
        }
        try {
            $this->locked((string) $request['request_uuid'], function (array $locked) use ($actor, $what, $eventKey, $requestId): void {
                $now = $this->now();
                $version = (int) $locked['version'] + 1;
                $this->pdo->prepare('UPDATE finance_verification_requests SET version = ?, updated_at = ? WHERE request_uuid = ?')->execute([$version, $now, (string) $locked['request_uuid']]);
                $this->event((string) $locked['request_uuid'], $version, $what, $actor->employeeUuid, (string) $locked['state'], (string) $locked['state'], $locked['assigned_employee_uuid'], null, null, $locked['policy'], $eventKey, $requestId, $now);
            });
        } catch (ApiException) {
            // The money action is recorded and audited in order_finance_actions; a lost task trace never undoes it.
        }
    }

    /**
     * API 2.80.0 (owner 2026-11-25): the finance employee's closed tasks beyond the inbox's latest 100, newest first, with a
     * search by order or proforma number and a cursor (`before`: updatedAt|id of the last row shown). Only what this
     * employee decided or was assigned, in their sources. A database read only.
     *
     * @param array<string, mixed> $query {q?, before?, source?}
     * @return array{items: list<array<string, mixed>>, next: string|null}
     */
    public function history(EmployeeIdentity $actor, array $query): array
    {
        $this->requireStaff($actor);
        $scopes = $this->financeScopes($actor);
        $sources = array_values(array_unique(array_merge(...array_values($scopes))));
        $source = is_string($query['source'] ?? null) && $query['source'] !== '' ? (string) $query['source'] : null;
        if ($source !== null) {
            $sources = array_values(array_intersect($sources, [$source]));
        }
        if ($sources === []) {
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance scope.');
        }
        $e = $actor->employeeUuid;
        $where = 'r.source_key IN (' . implode(', ', array_fill(0, count($sources), '?')) . ') AND r.open_order_uuid IS NULL AND (r.decided_by_employee_uuid = ? OR r.assigned_employee_uuid = ?)';
        $args = [...$sources, $e, $e];
        $q = is_string($query['q'] ?? null) ? strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $query['q'])) : '';
        if (strlen($q) > 40) {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'The search is too long.', ['field' => 'q']);
        }
        if ($q !== '') {
            $where .= " AND (o.order_number LIKE ? OR UPPER(REPLACE(CONCAT(COALESCE(r.proforma_series, ''), r.proforma_number), ' ', '')) LIKE ? OR r.proforma_number LIKE ?)";
            array_push($args, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        $before = is_string($query['before'] ?? null) ? (string) $query['before'] : '';
        if ($before !== '') {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z)\|([0-9a-f-]{36})$/D', $before, $m) !== 1) {
                throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'The page cursor is not valid.', ['field' => 'before']);
            }
            $at = $this->sql(new DateTimeImmutable($m[1]));
            $where .= ' AND (r.updated_at < ? OR (r.updated_at = ? AND r.request_uuid > ?))';
            array_push($args, $at, $at, $m[2]);
        }
        $statement = $this->pdo->prepare('SELECT r.*, o.order_number, COALESCE(o.source_submitted_at, o.created_at) AS ordered_at, a.currency AS amount_currency, a.required_amount, a.remaining_amount, a.read_at AS amount_read_at
            FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid LEFT JOIN finance_task_amounts a ON a.request_uuid = r.request_uuid
            WHERE ' . $where . ' ORDER BY r.updated_at DESC, r.request_uuid LIMIT ' . (self::HISTORY_PAGE + 1));
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::HISTORY_PAGE;
        $rows = array_slice($rows, 0, self::HISTORY_PAGE);
        $items = array_map(fn (array $row): array => $this->item($row, $actor), $rows);
        $last = $rows === [] ? null : end($rows);
        // The cursor keeps the microseconds of updated_at, so rows of the same second are never skipped.
        $cursor = is_array($last) ? (new DateTimeImmutable((string) $last['updated_at'], new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z') . '|' . $last['request_uuid'] : null;
        return ['items' => $items, 'next' => $more ? $cursor : null];
    }

    /**
     * The second check of a transfer recorded under the four-eyes policy (another employee with `approve`).
     *
     * @param array<string, mixed> $input {expectedVersion, expectedRevision, decision: verify|reject, reasonCode?}
     * @return array<string, mixed>
     */
    public function secondCheck(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        $this->requireKey($key);
        $allowed = ['expectedVersion', 'expectedRevision', 'decision', 'reasonCode'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        $decision = $input['decision'] ?? null;
        if (!in_array($decision, ['verify', 'reject'], true)) {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'decision must be verify or reject.', ['field' => 'decision']);
        }
        if ($decision === 'reject' ? !isset(self::SHOP_REJECT[$input['reasonCode'] ?? '']) : array_key_exists('reasonCode', $input)) {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'reasonCode is not valid.', ['field' => 'reasonCode']);
        }
        $this->requireStaff($actor);
        $request = $this->reachable($actor, $requestUuid);
        $sent = $this->pdo->prepare('SELECT 1 FROM order_finance_actions WHERE employee_uuid = ? AND idempotency_key = ? AND order_uuid = ?');
        $sent->execute([$actor->employeeUuid, $key, (string) $request['order_uuid']]);
        if ($sent->fetchColumn() !== false) {
            // The same decision again (a double click, a lost answer): the stored state, nothing is sent twice.
            return $this->afterDecision($actor, $request, $requestId);
        }
        // Owner 2026-10-29: under the single policy the employee who recorded the transfer (holding `verify`) concludes it.
        $alone = $this->concludesAlone($actor, $request);
        if (!$alone && ($request['state'] !== 'checking' || $request['policy'] !== 'four_eyes' || $request['source_entry_id'] === null || $request['recorded_by_employee_uuid'] === $actor->employeeUuid
            || $request['checker_employee_uuid'] !== $actor->employeeUuid || !$this->finance->allows($actor, FinanceScopePolicy::APPROVE, (string) $request['source_key']))) {
            if ($request['recorded_by_employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(403, 'CONFIRMARE_FINANCE_SAME_EMPLOYEE', 'Another employee must verify what you recorded.');
            }
            throw self::stateError($request);
        }
        $this->requireVersion($request, $input['expectedVersion'] ?? null);
        $fields = ['expectedRevision' => $input['expectedRevision'] ?? null, 'entryId' => (string) $request['source_entry_id'], 'decision' => $decision]
            + ($decision === 'reject' ? ['reasonCode' => self::SHOP_REJECT[(string) $input['reasonCode']]] : [])
            + ($alone ? ['singleVerification' => true] : []);
        $answer = $this->money->act($actor, (string) $request['global_order_id'], 'decide_transfer', $fields, $key, $requestId);
        if (($answer['state'] ?? null) !== 'applied') {
            $this->markUnknown((string) $request['request_uuid'], $actor->employeeUuid, $requestId);
        }
        return $this->afterDecision($actor, $this->requestRow((string) $request['request_uuid']) ?? $request, $requestId);
    }

    /**
     * Settles a decision whose answer was lost, with the shop's stored answer of its key (never a new action).
     *
     * @return array<string, mixed>
     */
    public function reconcile(EmployeeIdentity $actor, string $requestUuid, string $requestId): array
    {
        $this->requireStaff($actor);
        $request = $this->reachable($actor, $requestUuid);
        $action = $this->pendingAction((string) $request['order_uuid']);
        if ($action === null) {
            throw new ApiException(409, 'FINANCE_REQUEST_NOTHING_TO_RECONCILE', 'No unsettled finance action belongs to this request.');
        }
        try {
            $result = $this->money->reconcile($actor, (string) $request['global_order_id'], (string) $action['idempotency_key'], $requestId);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'CONFIRMARE_FINANCE_REFUSED') {
                throw $e;
            }
            // The shop never applied it (refused or never received): back to where the decision was, without the key.
            $this->locked((string) $request['request_uuid'], function (array $locked) use ($actor, $e, $requestId): void {
                if ($locked['state'] !== 'exception') {
                    return;
                }
                $recorded = $locked['source_entry_id'] !== null && $locked['policy'] === 'four_eyes';
                $reason = is_string($e->details['reason'] ?? null) ? (string) $e->details['reason'] : 'refused';
                $this->transition($locked, 'checking', 'reconciled_not_applied', $actor->employeeUuid, null, substr(preg_replace('/[^a-z_]/', '', $reason) ?? 'refused', 0, 40), null, $requestId, $this->now(),
                    $recorded ? ['reason_code' => null] : ['reason_code' => null, 'finance_idempotency_key' => null, 'evidence_hmac' => null, 'policy' => null]);
            });
            return $this->afterDecision($actor, $this->requestRow((string) $request['request_uuid']) ?? $request, $requestId);
        }
        if (($result['state'] ?? null) === 'applied') {
            // The applied answer may come from the stored record (no summary): the shop's money state decides the outcome.
            $row = $this->orderByUuid((string) $request['order_uuid']);
            $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
            $summary = $target === null ? null : $this->summary($target, $row, $actor->employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId);
            if ($summary !== null) {
                self::onFinanceApplied($this->pdo, $this->clock, (string) $request['order_uuid'], (string) $action['action'], (string) $action['idempotency_key'], (string) $action['employee_uuid'],
                    (string) ($result['entryId'] ?? ''), $action['decision'] === null ? null : (string) $action['decision'], $action['reason_code'] === null ? null : (string) $action['reason_code'], $summary['financial']['state'], $requestId);
            }
        }
        return $this->afterDecision($actor, $this->requestRow((string) $request['request_uuid']) ?? $request, $requestId);
    }

    // ---- Finance supervisor -----------------------------------------------------------------------------------------

    /**
     * Activitatea contabilității: the requests of the supervised employees (observation only, no order data).
     *
     * @param array<string, string|null> $filters employee, source, state (pending|checking|verified|not_received|attention), from, to (YYYY-MM-DD)
     * @return array<string, mixed>
     */
    public function oversight(EmployeeIdentity $actor, array $filters): array
    {
        $this->requireStaff($actor);
        $supervised = $this->finance->supervised($actor->employeeUuid);
        if ($actor->isRoot || $supervised === []) {
            throw new ApiException(403, 'FINANCE_OVERSIGHT_DENIED', 'You supervise no finance employee.');
        }
        $parameters = [];
        $people = ConfirmareScopePolicy::condition($supervised, 'r.assigned_employee_uuid', $parameters) . ' OR ' . ConfirmareScopePolicy::condition($supervised, 'r.decided_by_employee_uuid', $parameters)
            . ' OR ' . ConfirmareScopePolicy::condition($supervised, 'r.recorded_by_employee_uuid', $parameters) . ' OR ' . ConfirmareScopePolicy::condition($supervised, 'r.checker_employee_uuid', $parameters);
        $where = "({$people})";
        $employee = $filters['employee'] ?? null;
        if ($employee !== null) {
            if (!in_array($employee, $supervised, true)) {
                throw new ApiException(403, 'FINANCE_OVERSIGHT_DENIED', 'You do not supervise this employee.');
            }
            $where .= ' AND (r.assigned_employee_uuid = ? OR r.decided_by_employee_uuid = ? OR r.recorded_by_employee_uuid = ? OR r.checker_employee_uuid = ?)';
            array_push($parameters, $employee, $employee, $employee, $employee);
        }
        $source = $filters['source'] ?? null;
        if ($source !== null) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1) {
                throw new ApiException(400, 'INVALID_REQUEST', 'source is invalid.');
            }
            $where .= ' AND r.source_key = ?';
            $parameters[] = $source;
        }
        $groups = ['pending' => ['requested', 'assigned'], 'checking' => ['checking'], 'verified' => ['verified'], 'not_received' => ['not_received'], 'attention' => ['exception', 'needs_clarification', 'requires_manual_review']];
        $state = $filters['state'] ?? null;
        if ($state !== null) {
            if (!isset($groups[$state])) {
                throw new ApiException(400, 'INVALID_REQUEST', 'state is invalid.');
            }
            $where .= ' AND r.state IN (' . implode(', ', array_fill(0, count($groups[$state]), '?')) . ')';
            array_push($parameters, ...$groups[$state]);
        }
        foreach (['from' => '>=', 'to' => '<'] as $name => $operator) {
            $day = $filters[$name] ?? null;
            if ($day === null) {
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new ApiException(400, 'INVALID_REQUEST', "{$name} must be a date (YYYY-MM-DD).");
            }
            $instant = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'));
            $where .= " AND r.updated_at {$operator} ?";
            $parameters[] = $this->sql($name === 'to' ? $instant->modify('+1 day') : $instant);
        }
        $statement = $this->pdo->prepare("SELECT r.*, o.order_number FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
            WHERE {$where} ORDER BY r.updated_at DESC, r.request_uuid LIMIT " . self::LIMIT);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $counts = array_fill_keys(array_keys($groups), 0);
        $items = [];
        foreach ($rows as $row) {
            foreach ($groups as $group => $states) {
                if (in_array($row['state'], $states, true)) {
                    $counts[$group]++;
                }
            }
            $items[] = $this->oversightItem($row);
        }
        return ['items' => $items, 'counts' => $counts, 'employees' => array_map(fn (string $id): array => ['id' => $id, 'displayName' => $this->names([$id])[$id] ?? ''], $supervised), 'limit' => self::LIMIT];
    }

    /**
     * One request of a supervised employee: its finance audit trail and the bank transfers of the order the shop holds
     * (amount, bank reference, value date, who recorded and decided). Audited. Read only.
     *
     * @return array<string, mixed>
     */
    public function oversightDetail(EmployeeIdentity $actor, string $requestUuid, string $requestId): array
    {
        $this->requireStaff($actor);
        $supervised = $this->finance->supervised($actor->employeeUuid);
        $request = preg_match(self::UUID_PATTERN, $requestUuid) === 1 ? $this->requestRow($requestUuid) : null;
        if ($actor->isRoot || $request === null || array_intersect($supervised, array_filter([$request['assigned_employee_uuid'], $request['decided_by_employee_uuid'], $request['recorded_by_employee_uuid'], $request['checker_employee_uuid']])) === []) {
            throw new ApiException(404, 'FINANCE_REQUEST_NOT_FOUND', 'No such finance request was found.');
        }
        $row = $this->orderByUuid((string) $request['order_uuid']);
        $transfers = null;
        $currency = null;
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        if ($row !== null && $target !== null && $target->allowsFinanceRead() && $this->financeScopeOn($actor, (string) $row['source_key'])) {
            $this->rateLimit($actor);
            $summary = $this->summary($target, $row, $actor->employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId);
            $this->audit($row, $actor, $summary === null ? 'source_unavailable' : 'ok', $requestId, $summary['revision'] ?? null);
            $transfers = $summary === null ? null : $this->transfers($summary);
            $currency = $summary['currency'] ?? null;
        }
        $events = $this->pdo->prepare('SELECT ev.*, e.display_name FROM finance_verification_request_events ev LEFT JOIN employees e ON e.employee_uuid = ev.actor_employee_uuid WHERE ev.request_uuid = ? ORDER BY ev.version_after');
        $events->execute([(string) $request['request_uuid']]);
        return [
            'request' => $this->oversightItem($request + ['order_number' => $row['order_number'] ?? '']),
            'events' => array_map(static fn (array $event): array => [
                'version' => (int) $event['version_after'],
                'action' => (string) $event['action'],
                'actor' => $event['actor_employee_uuid'] === null ? null : ['id' => (string) $event['actor_employee_uuid'], 'displayName' => (string) $event['display_name']],
                'previousState' => $event['previous_state'],
                'state' => (string) $event['new_state'],
                'outcome' => $event['outcome'],
                'reasonCode' => $event['reason_code'],
                'policy' => $event['policy'],
                'at' => self::iso((string) $event['occurred_at']),
            ], $events->fetchAll(PDO::FETCH_ASSOC)),
            'transfers' => $transfers,
            'currency' => $currency,
        ];
    }

    // ---- Settlement of applied money actions --------------------------------------------------------------------------

    /**
     * The request side of an applied finance action (ConfirmareFinance calls it after the shop applied one; reconcile
     * too). Idempotent: a request already settled is left alone; an action that belongs to no request changes nothing.
     */
    /**
     * API 2.57.0 (incident TH 64871, 2026-11-04): a new advance proforma issued for the order (a replacement after a
     * changed total) moves its open advance request to that proforma. Before, the request stayed on the cancelled one and
     * the finance employee could only answer "the proforma changed". Only a request nothing was recorded for yet
     * (requested, assigned or waiting for a clarification) follows; its state and assignee are kept, its version rises
     * (open screens reload) and one `proforma_replaced` event names the new document.
     */
    public static function onProformaReplaced(PDO $pdo, string $orderUuid, ?string $series, string $number, string $actorUuid, string $requestId, string $now): void
    {
        $series = $series === '' ? null : $series;
        $statement = $pdo->prepare("SELECT request_uuid, version, state, assigned_employee_uuid FROM finance_verification_requests WHERE order_uuid = ? AND purpose = 'advance' AND open_order_uuid IS NOT NULL
            AND finance_idempotency_key IS NULL AND source_entry_id IS NULL AND state IN ('requested', 'assigned', 'needs_clarification') AND NOT (proforma_number <=> ? AND proforma_series <=> ?) FOR UPDATE");
        $statement->execute([$orderUuid, $number, $series]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $request) {
            $version = (int) $request['version'] + 1;
            $pdo->prepare('UPDATE finance_verification_requests SET proforma_series = ?, proforma_number = ?, version = ?, updated_at = ? WHERE request_uuid = ? AND version = ?')
                ->execute([$series, $number, $version, $now, $request['request_uuid'], (int) $request['version']]);
            $pdo->prepare('INSERT INTO finance_verification_request_events (event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state, new_state, assigned_employee_uuid, outcome, reason_code, policy, idempotency_key, request_id, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?)')->execute([Uuid::v4(), $request['request_uuid'], $version, 'proforma_replaced', $actorUuid, $request['state'], $request['state'], $request['assigned_employee_uuid'], self::cleanRequestId($requestId), $now]);
        }
    }

    public static function onFinanceApplied(PDO $pdo, Clock $clock, string $orderUuid, string $action, string $key, string $actorUuid, string $entryId, ?string $decision, ?string $reasonCode, string $financialState, string $requestId): void
    {
        if (!in_array($action, ['verify_transfer', 'submit_transfer', 'decide_transfer'], true) || preg_match(self::UUID_PATTERN, $entryId) !== 1) {
            return;
        }
        $now = $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        try {
            $pdo->beginTransaction();
            $statement = $action === 'decide_transfer'
                ? $pdo->prepare("SELECT * FROM finance_verification_requests WHERE order_uuid = ? AND source_entry_id = ? AND state IN ('checking', 'exception') FOR UPDATE")
                : $pdo->prepare("SELECT * FROM finance_verification_requests WHERE order_uuid = ? AND finance_idempotency_key = ? AND state IN ('checking', 'exception') FOR UPDATE");
            $statement->execute([$orderUuid, $action === 'decide_transfer' ? $entryId : $key]);
            $request = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request) && $action === 'submit_transfer') {
                // Recorded outside an advance task (an exception task's money panel): the order's open task carries its
                // second check, or a second-check task is opened for it, so the transfer is never left without a named
                // second employee.
                $open = $pdo->prepare("SELECT * FROM finance_verification_requests WHERE open_order_uuid = ? AND source_entry_id IS NULL AND finance_idempotency_key IS NULL FOR UPDATE");
                $open->execute([$orderUuid]);
                $request = $open->fetch(PDO::FETCH_ASSOC);
                $busy = $pdo->prepare('SELECT 1 FROM finance_verification_requests WHERE open_order_uuid = ?');
                $busy->execute([$orderUuid]);
                if (!is_array($request) && $busy->fetchColumn() === false) {
                    self::openSecondCheck($pdo, $orderUuid, $entryId, $actorUuid, $now, $requestId);
                }
            }
            if (!is_array($request)) {
                $pdo->commit();
                return;
            }
            $outcome = $financialState === 'cleared' ? 'verified_full' : 'verified_partial';
            $set = match (true) {
                $action === 'verify_transfer' => ['state' => 'verified', 'outcome' => $outcome, 'source_entry_id' => $entryId, 'recorded_by_employee_uuid' => $actorUuid, 'decided_by_employee_uuid' => $actorUuid, 'decided_at' => $now, 'policy' => 'single', 'reason_code' => null, 'event' => 'verified'],
                $action === 'submit_transfer' => ['state' => 'checking', 'source_entry_id' => $entryId, 'recorded_by_employee_uuid' => $actorUuid, 'policy' => 'four_eyes', 'reason_code' => null,
                    'assigned_employee_uuid' => $request['assigned_employee_uuid'] ?? $actorUuid,
                    // The task's named approver stays (an exception task names one); never the recorder.
                    'checker_employee_uuid' => $request['checker_employee_uuid'] !== null && $request['checker_employee_uuid'] !== $actorUuid ? $request['checker_employee_uuid'] : self::leastLoadedChecker($pdo, (string) $request['source_key'], $actorUuid),
                    'event' => 'recorded_for_second_check'],
                $decision === 'verify' => ['state' => 'verified', 'outcome' => $outcome, 'decided_by_employee_uuid' => $actorUuid, 'decided_at' => $now, 'reason_code' => null,
                    // The recorder concluding alone (single policy) is named as such, never as a second employee.
                    'policy' => $request['recorded_by_employee_uuid'] === $actorUuid ? 'single' : $request['policy'], 'event' => $request['recorded_by_employee_uuid'] === $actorUuid ? 'verified_single' : 'second_check_verified'],
                default => ['state' => 'not_received', 'outcome' => 'not_received', 'decided_by_employee_uuid' => $actorUuid, 'decided_at' => $now, 'reason_code' => $reasonCode,
                    'policy' => $request['recorded_by_employee_uuid'] === $actorUuid ? 'single' : $request['policy'], 'event' => 'second_check_rejected'],
            };
            $event = $set['event'];
            unset($set['event']);
            $set['open_order_uuid'] = in_array($set['state'], self::CLOSED_STATES, true) ? null : (string) $request['order_uuid'];
            $set['version'] = (int) $request['version'] + 1;
            $set['updated_at'] = $now;
            $columns = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($set)));
            $pdo->prepare("UPDATE finance_verification_requests SET {$columns} WHERE request_uuid = ?")->execute([...array_values($set), (string) $request['request_uuid']]);
            $pdo->prepare('INSERT INTO finance_verification_request_events (event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state, new_state, assigned_employee_uuid, outcome, reason_code, policy, idempotency_key, request_id, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)')->execute([
                Uuid::v4(), (string) $request['request_uuid'], $set['version'], $event, $actorUuid, (string) $request['state'], $set['state'], $set['assigned_employee_uuid'] ?? $request['assigned_employee_uuid'], $set['outcome'] ?? null, $set['reason_code'] ?? null,
                $set['policy'] ?? $request['policy'], self::cleanRequestId($requestId), $now,
            ]);
            $pdo->commit();
        } catch (PDOException) {
            // Before migration 044, or a failure: the shop holds the money; the request is settled by reconciliation.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * Opens the second-check task of a transfer recorded outside any task (the caller holds the transaction): assigned to
     * its recorder, checked by the least loaded other employee with `approve`. Idempotent per shop entry.
     */
    public static function openSecondCheck(PDO $pdo, string $orderUuid, string $entryId, string $recorderUuid, string $now, string $requestId, ?string $actorUuid = null, string $action = 'second_check_opened'): ?string
    {
        $order = $pdo->prepare('SELECT global_order_id, source_key FROM operational_orders WHERE order_uuid = ?');
        $order->execute([$orderUuid]);
        $row = $order->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $uuid = Uuid::v4();
        $checker = self::leastLoadedChecker($pdo, (string) $row['source_key'], $recorderUuid);
        $inserted = $pdo->prepare("INSERT IGNORE INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
                requested_by_employee_uuid, requested_at, request_idempotency_key, inventory_key, task_code, ledger, policy, recorded_by_employee_uuid, checker_employee_uuid, source_entry_id, updated_at)
            VALUES (?, ?, ?, ?, 'second_check', 'checking', 1, ?, ?, ?, NULL, ?, NULL, ?, 'transfer_pending', 'shop', 'four_eyes', ?, ?, ?, ?)");
        $inserted->execute([$uuid, $orderUuid, (string) $row['global_order_id'], (string) $row['source_key'], $orderUuid, $recorderUuid, $now, $now, 'second_check:' . $entryId, $recorderUuid, $checker, $entryId, $now]);
        if ($inserted->rowCount() !== 1) {
            return null;
        }
        $pdo->prepare('INSERT INTO finance_verification_request_events (event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state, new_state, assigned_employee_uuid, outcome, reason_code, policy, idempotency_key, request_id, occurred_at)
            VALUES (?, ?, 1, ?, ?, NULL, ?, ?, NULL, ?, ?, NULL, ?, ?)')->execute([Uuid::v4(), $uuid, $action, $actorUuid, 'checking', $recorderUuid, 'transfer_pending', 'four_eyes', self::cleanRequestId($requestId), $now]);
        return $uuid;
    }

    /** The least loaded active employee holding `approve` on the source who is not the recorder, or null. */
    public static function leastLoadedChecker(PDO $pdo, string $source, ?string $recorderUuid): ?string
    {
        $statement = $pdo->prepare("SELECT s.employee_uuid,
                (SELECT COUNT(*) FROM finance_verification_requests r WHERE r.checker_employee_uuid = s.employee_uuid AND r.open_order_uuid IS NOT NULL) AS open_checks
            FROM employee_finance_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
            INNER JOIN employee_application_access a ON a.employee_uuid = e.employee_uuid AND a.application_key = 'staff'
            WHERE s.source_key = ? AND s.capability = 'approve' AND e.status = 'active' AND e.must_change_password = 0 AND s.employee_uuid <> ?
              AND e.employee_uuid NOT IN (SELECT employee_uuid FROM system_root_identity)
            ORDER BY open_checks, s.employee_uuid LIMIT 1");
        $statement->execute([$source, $recorderUuid ?? '']);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    // ---- Views ------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $row the order @return array<string, mixed> */
    private function operatorView(EmployeeIdentity $actor, array $row): array
    {
        $request = $this->latestFor((string) $row['order_uuid']);
        $state = $request['state'] ?? null;
        $pending = in_array($state, ['requested', 'assigned', 'checking', 'exception'], true);
        $financial = [
            'state' => $row['source_financial_state'] ?? 'unknown',
            'attention' => $row['source_financial_attention'],
            'open' => ConfirmationClearance::financialOpen(
                $row['source_financial_state'] === null ? null : (string) $row['source_financial_state'],
                $row['source_financial_basis'] === null ? null : (string) $row['source_financial_basis'],
                $row['source_financial_pricing'] === null ? null : (string) $row['source_financial_pricing'],
                $row['source_pricing_fingerprint'] === null ? null : (string) $row['source_pricing_fingerprint'],
                $row['payment_method'] === null ? null : (string) $row['payment_method'],
            ),
        ];
        // Verified by finance, but the shop's money state has not reached Arasya yet: keep asking, a little slower.
        $syncing = $state === 'verified' && $request['outcome'] === 'verified_full' && !$financial['open'];
        $scope = FinanceTaskScope::ofRow($row);
        $requestable = $scope === FinanceTaskScope::IN_SCOPE && !$row['closed'];
        return [
            'request' => $request === null ? null : [
                'id' => (string) $request['request_uuid'],
                'state' => (string) $request['state'],
                'version' => (int) $request['version'],
                'outcome' => $request['outcome'],
                'reasonCode' => $request['reason_code'],
                'purpose' => (string) $request['purpose'],
                'ledger' => (string) $request['ledger'],
                'awaitingSecondCheck' => $request['state'] === 'checking' && $request['source_entry_id'] !== null && $request['policy'] === 'four_eyes',
                'assignee' => $request['assigned_employee_uuid'] === null ? null : ($this->names([(string) $request['assigned_employee_uuid']])[(string) $request['assigned_employee_uuid']] ?? null),
                'proforma' => self::proformaOf($request),
                'requestedAt' => self::iso((string) $request['requested_at']),
                'updatedAt' => self::iso((string) $request['updated_at']),
                'decidedAt' => $request['decided_at'] === null ? null : self::iso((string) $request['decided_at']),
            ],
            'financial' => $financial,
            // Covered orders only (FinanceTaskScope); the ledger says whether the shop's finance record holds the payment.
            'scope' => $scope,
            'ledger' => self::ledgerOf($row),
            'canRequest' => $requestable && ($state === null || in_array($state, self::CLOSED_STATES, true)),
            'canResubmit' => $state === 'needs_clarification' && $request['purpose'] === 'advance',
            'canCancel' => in_array($state, ['requested', 'assigned', 'needs_clarification'], true) && $request['purpose'] === 'advance' && $request['finance_idempotency_key'] === null && $request['source_entry_id'] === null,
            'pollSeconds' => $pending || $syncing ? ($state === 'checking' || $syncing ? self::POLL_ACTIVE_SECONDS : self::POLL_WAITING_SECONDS) : null,
        ];
    }

    /**
     * Whether the actor concludes this recorded transfer alone (owner decision 2026-10-29, single policy): the task is theirs,
     * they recorded it, they hold `verify`, and Operations allows single verification for the source (the shop checks its
     * own policy again). Four eyes stays the default everywhere else.
     *
     * @param array<string, mixed> $request
     */
    private function concludesAlone(EmployeeIdentity $actor, array $request): bool
    {
        if ($request['state'] !== 'checking' || $request['source_entry_id'] === null || $request['recorded_by_employee_uuid'] !== $actor->employeeUuid
            || $request['assigned_employee_uuid'] !== $actor->employeeUuid || !$this->finance->allows($actor, FinanceScopePolicy::VERIFY, (string) $request['source_key'])) {
            return false;
        }
        $target = $this->registry->find((string) $request['source_key']);
        return $target !== null && $target->allowsSingleVerification();
    }

    /** @param array<string, mixed> $row a request with order_number @return array<string, mixed> the inbox item */
    private function item(array $row, EmployeeIdentity $actor): array
    {
        $secondCheck = $row['state'] === 'checking' && $row['policy'] === 'four_eyes' && $row['source_entry_id'] !== null;
        $mine = $row['assigned_employee_uuid'] === $actor->employeeUuid;
        $kind = match (true) {
            $secondCheck && ($row['checker_employee_uuid'] === $actor->employeeUuid || $this->concludesAlone($actor, $row)) => 'second_check',
            $secondCheck => 'awaiting_second_check',
            $row['purpose'] === 'exception' => 'exception',
            default => 'verify',
        };
        $category = self::category((string) $row['state']);
        return [
            'id' => (string) $row['request_uuid'],
            'source' => (string) $row['source_key'],
            'orderNumber' => (string) $row['order_number'],
            'purpose' => (string) $row['purpose'],
            'taskCode' => $row['task_code'],
            'ledger' => (string) $row['ledger'],
            'proforma' => self::proformaOf($row),
            'state' => (string) $row['state'],
            'version' => (int) $row['version'],
            'category' => $category,
            'kind' => $kind,
            'assignedToMe' => $mine,
            'outcome' => $row['outcome'],
            'reasonCode' => $row['reason_code'],
            'policy' => $row['policy'],
            'requestedAt' => self::iso((string) $row['requested_at']),
            'updatedAt' => self::iso((string) $row['updated_at']),
            'decidedAt' => $row['decided_at'] === null ? null : self::iso((string) $row['decided_at']),
            // API 2.80.0: the employee's own decision (Finalizate azi), and for a payment not found the next check.
            'decidedByMe' => $row['decided_by_employee_uuid'] === $actor->employeeUuid,
            'recheckAt' => $this->autoRecheck && $row['state'] === 'not_received' && $row['decided_at'] !== null ? self::iso($this->sql(self::recheckAfter((string) $row['decided_at']))) : null,
        ] + (array_key_exists('amount_read_at', $row) ? [
            // API 2.77.9: the advance the shop last showed (null: never read yet). The task screen reads it again live.
            'advance' => $row['amount_read_at'] === null ? null : [
                'currency' => (string) $row['amount_currency'],
                'required' => $row['required_amount'] === null ? null : number_format((float) $row['required_amount'], 2, '.', ''),
                'remaining' => $row['remaining_amount'] === null ? null : number_format((float) $row['remaining_amount'], 2, '.', ''),
                'readAt' => self::iso((string) $row['amount_read_at']),
            ],
            'awaitingPayment' => (bool) ($row['awaiting_payment'] ?? false),
            // When the customer placed the order (the shop's submission time), for the list's order date.
            'orderedAt' => ($row['ordered_at'] ?? null) === null ? null : self::iso((string) $row['ordered_at']),
        ] : []);
    }

    /**
     * API 2.82.0 (Operation Simplification V1): the customer refused the order (the operator's red decision). The order's open
     * finance tasks that hold no money step are withdrawn (`cancelled`, reason `customer_withdrew`, audited as the operator's),
     * so nobody keeps looking for a payment that will not come. A task in the middle of a check, or one that already sent a
     * finance action to the shop, is never touched: the answer is false and the cancellation waits for it.
     * The caller holds the order row lock.
     */
    public static function withdrawForRefusal(PDO $pdo, string $orderUuid, string $actorUuid, string $now, string $requestId): bool
    {
        $statement = $pdo->prepare('SELECT * FROM finance_verification_requests WHERE open_order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $open = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($open as $request) {
            if (!in_array($request['state'], ['requested', 'assigned', 'needs_clarification'], true) || $request['finance_idempotency_key'] !== null || $request['source_entry_id'] !== null) {
                return false;
            }
        }
        foreach ($open as $request) {
            $version = (int) $request['version'] + 1;
            $pdo->prepare("UPDATE finance_verification_requests SET state = 'cancelled', version = ?, updated_at = ?, open_order_uuid = NULL, reason_code = 'customer_withdrew' WHERE request_uuid = ? AND version = ?")
                ->execute([$version, $now, (string) $request['request_uuid'], (int) $request['version']]);
            $pdo->prepare('INSERT INTO finance_verification_request_events (event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state, new_state, assigned_employee_uuid, outcome, reason_code, policy, idempotency_key, request_id, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Uuid::v4(), (string) $request['request_uuid'], $version, 'cancelled', $actorUuid, (string) $request['state'], 'cancelled',
                    $request['assigned_employee_uuid'], null, 'customer_withdrew', $request['policy'], null, self::cleanRequestId($requestId), $now]);
        }
        return true;
    }

    /** API 2.80.0: when a payment not found at $decidedSql (UTC) is looked for again: the next day, RECHECK_HOUR Bucharest. */
    public static function recheckAfter(string $decidedSql): DateTimeImmutable
    {
        $local = (new DateTimeImmutable($decidedSql, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'));
        return $local->modify('+1 day')->setTime(self::RECHECK_HOUR, 0)->setTimezone(new DateTimeZone('UTC'));
    }

    /** The inbox tab of a request state. */
    private static function category(string $state): string
    {
        return match (true) {
            in_array($state, self::CLOSED_STATES, true) => 'done',
            $state === 'needs_clarification' => 'clarification',
            default => 'todo',
        };
    }

    /** @param array<string, mixed> $row a request with order_number @return array<string, mixed> */
    private function oversightItem(array $row): array
    {
        $names = $this->names(array_values(array_filter([$row['assigned_employee_uuid'], $row['decided_by_employee_uuid'], $row['recorded_by_employee_uuid'], $row['checker_employee_uuid']], 'is_string')));
        $person = static fn (?string $id): ?array => $id === null ? null : ['id' => $id, 'displayName' => $names[$id] ?? ''];
        return [
            'id' => (string) $row['request_uuid'],
            'source' => (string) $row['source_key'],
            'orderNumber' => (string) $row['order_number'],
            'purpose' => (string) $row['purpose'],
            'taskCode' => $row['task_code'],
            'ledger' => (string) $row['ledger'],
            'proforma' => self::proformaOf($row),
            'state' => (string) $row['state'],
            'outcome' => $row['outcome'],
            'reasonCode' => $row['reason_code'],
            'policy' => $row['policy'],
            'assignee' => $person($row['assigned_employee_uuid']),
            'recordedBy' => $person($row['recorded_by_employee_uuid']),
            'checker' => $person($row['checker_employee_uuid']),
            'decidedBy' => $person($row['decided_by_employee_uuid']),
            'requestedAt' => self::iso((string) $row['requested_at']),
            'updatedAt' => self::iso((string) $row['updated_at']),
            'decidedAt' => $row['decided_at'] === null ? null : self::iso((string) $row['decided_at']),
        ];
    }

    /**
     * The money of the order for the task screen: the shop's finance summary and the proforma's bank account, minimized.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function live(SourceAccessTarget $target, array $row, array $request, string $employeeUuid, string $requestId): array
    {
        $summary = $this->summary($target, $row, $employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId, $failure);
        if ($summary === null) {
            $this->auditAs($row, $employeeUuid, $failure ?? 'source_unavailable', $requestId);
            return ['available' => false, 'reason' => $failure ?? 'source_unavailable'];
        }
        if (!$this->auditAs($row, $employeeUuid, 'ok', $requestId, $summary['revision'])) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        $this->rememberAmount((string) $request['request_uuid'], $summary);
        $bank = null;
        // API 2.81.0: the bank account of the proforma is the shop's own and does not change with the money: it is taken
        // from the display copy of the same proforma, so opening a task asks the shop once instead of twice.
        $known = $this->displayRow((string) $request['request_uuid']);
        $knownLive = $known === null ? null : self::decodeDisplay($known['display_json'] ?? null);
        $sameProforma = $knownLive !== null && is_array($knownLive['bank'] ?? null) && ($knownLive['proforma']['number'] ?? null) === ($summary['advance']['proforma']['number'] ?? false);
        if ($sameProforma) {
            $bank = $knownLive['bank'];
        } elseif ($target->allowsInvoiceRead()) {
            try {
                $invoice = SourceInvoice::summary($this->client->call($target, SourceAccessClient::ROUTE_INVOICE, $this->payload($row, $employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId)), (string) $row['source_order_id'], (string) $row['order_number']);
                $transfer = $invoice['proforma']['transfer'] ?? null;
                // The account is shown to recognise it, never to copy it: the IBAN leaves the API masked.
                $bank = $transfer === null ? null : ['beneficiary' => $transfer['beneficiary'], 'bank' => $transfer['bank'], 'ibanMasked' => self::maskIban((string) $transfer['iban']), 'bic' => $transfer['bic']];
            } catch (SourceAccessException) {
                $bank = null;
            }
        }
        $advance = $summary['advance'];
        $proforma = $advance['proforma'] ?? null;
        $settled = ConfirmationClearance::financialOpen(
            $row['source_financial_state'] === null ? null : (string) $row['source_financial_state'],
            $row['source_financial_basis'] === null ? null : (string) $row['source_financial_basis'],
            $row['source_financial_pricing'] === null ? null : (string) $row['source_financial_pricing'],
            $row['source_pricing_fingerprint'] === null ? null : (string) $row['source_pricing_fingerprint'],
            $row['payment_method'] === null ? null : (string) $row['payment_method'],
        );
        $live = [
            'available' => true,
            'revision' => $summary['revision'],
            'readAt' => $summary['readAt'],
            'currency' => $summary['currency'],
            'proforma' => $proforma === null ? null : ['series' => $proforma['series'], 'number' => $proforma['number'], 'amount' => $proforma['amount'], 'currency' => $proforma['currency']],
            'proformaChanged' => $request['proforma_number'] !== null && ($proforma === null || $proforma['number'] !== $request['proforma_number'] || ($proforma['series'] === '' ? null : $proforma['series']) !== $request['proforma_series'] || ($advance['proformaCancelled'] ?? false)),
            'ledger' => (string) $request['ledger'],
            'advance' => $advance === null ? null : [
                'percentage' => $advance['percentage'],
                'required' => $advance['required'],
                'verified' => $advance['collected'],
                'remaining' => $advance['outstanding'],
                'accepted' => $advance['accepted'] ?? false,
            ],
            'paymentReference' => $proforma['reference'] ?? $advance['paymentReference'] ?? null,
            'bank' => $bank,
            'financial' => ['state' => $summary['financial']['state'], 'attention' => $summary['financial']['attention']],
            'policy' => $target->allowsSingleVerification() && ($summary['capabilities']['verification'] ?? 'four_eyes') === 'single' ? 'single' : 'four_eyes',
            'recordEnabled' => $target->allowsFinanceRecord() && $summary['capabilities']['record'],
            'transfers' => $this->transfers($summary),
            // API 2.80.0 (owner 2026-11-25): the one money calculation of the shop (total, received, cash on delivery,
            // excess), so the employee sees the new cash on delivery as they type; and what the shop accepts.
            'money' => ($summary['position'] ?? null) === null ? null : [
                'known' => $summary['position']['known'],
                'total' => $summary['position']['total'],
                'advanceRequired' => $summary['position']['advanceRequired'],
                'received' => $summary['position']['net'],
                'remaining' => $summary['position']['remaining'],
                'excess' => $summary['position']['excess'],
            ],
            'capabilities' => ['match' => $summary['capabilities']['match'] ?? false, 'correction' => $summary['capabilities']['correction'] ?? false],
            'cod' => ($summary['cod'] ?? null) === null ? null : ['state' => $summary['cod']['state'], 'awb' => $summary['cod']['awb'] !== null],
            'settledElsewhere' => $settled && in_array($request['state'], ['requested', 'assigned', 'checking'], true) && $request['source_entry_id'] === null,
        ];
        $this->rememberDisplay((string) $request['request_uuid'], $live, $summary);
        return $live + ['source' => 'live', 'stale' => false];
    }

    /**
     * API 2.81.0: the display copy of a task as the shop last showed it, or null. `$stale` false: only a copy younger
     * than DISPLAY_FRESH_MINUTES with no shop change of the order since (an open advance check only). `$stale` true (the
     * shop did not answer): any copy up to DISPLAY_STALE_HOURS, marked stale with the reason. What depends on Arasya's
     * own records (the proforma of the request, money settled elsewhere, the shop's configuration) is computed now.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function cachedLive(SourceAccessTarget $target, array $row, array $request, bool $stale, ?string $reason = null): ?array
    {
        if (!$stale && !($request['purpose'] === 'advance' && in_array($request['state'], ['assigned', 'checking'], true) && $request['source_entry_id'] === null && $request['finance_idempotency_key'] === null)) {
            return null;
        }
        $copy = $this->displayRow((string) $request['request_uuid']);
        $live = $copy === null ? null : self::decodeDisplay($copy['display_json'] ?? null);
        if ($copy === null || $live === null || $copy['display_read_at'] === null) {
            return null;
        }
        $readAt = new DateTimeImmutable((string) $copy['display_read_at'], new DateTimeZone('UTC'));
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        if ($stale) {
            if ($readAt < $now->modify('-' . self::DISPLAY_STALE_HOURS . ' hours')) {
                return null;
            }
        } else {
            $changed = $row['source_changed_at'] ?? null;
            if ($readAt < $now->modify('-' . self::DISPLAY_FRESH_MINUTES . ' minutes') || ($changed !== null && new DateTimeImmutable((string) $changed, new DateTimeZone('UTC')) > $readAt)) {
                return null;
            }
        }
        $proforma = $live['proforma'] ?? null;
        $settled = ConfirmationClearance::financialOpen(
            $row['source_financial_state'] === null ? null : (string) $row['source_financial_state'],
            $row['source_financial_basis'] === null ? null : (string) $row['source_financial_basis'],
            $row['source_financial_pricing'] === null ? null : (string) $row['source_financial_pricing'],
            $row['source_pricing_fingerprint'] === null ? null : (string) $row['source_pricing_fingerprint'],
            $row['payment_method'] === null ? null : (string) $row['payment_method'],
        );
        $live['proformaChanged'] = $request['proforma_number'] !== null && (!is_array($proforma) || $proforma['number'] !== $request['proforma_number']
            || (($proforma['series'] ?? '') === '' ? null : $proforma['series']) !== $request['proforma_series'] || ($live['proformaCancelled'] ?? false));
        $live['settledElsewhere'] = $settled && in_array($request['state'], ['requested', 'assigned', 'checking'], true) && $request['source_entry_id'] === null;
        $live['policy'] = ($live['shopPolicy'] ?? 'four_eyes') === 'single' && $target->allowsSingleVerification() ? 'single' : 'four_eyes';
        $live['recordEnabled'] = ($live['shopRecord'] ?? false) === true && $target->allowsFinanceRecord();
        unset($live['proformaCancelled'], $live['shopPolicy'], $live['shopRecord']);
        // Only an ordinary task opens from the copy: a changed or cancelled proforma, money settled elsewhere or a shop
        // attention is always read live (it is shown from the copy only while the shop does not answer).
        if (!$stale && ($live['proformaChanged'] || $live['settledElsewhere'] || ($live['financial']['attention'] ?? null) !== null)) {
            return null;
        }
        return $live + ['source' => 'cache', 'stale' => $stale] + ($stale ? ['staleReason' => $reason ?? 'source_unavailable'] : []);
    }

    /**
     * API 2.81.0: keeps the task's live view as the display copy. Never a task whose order has a bank transfer (the copy
     * would hold the transfer's reference and payer): such a task is always read live, and an older copy is removed.
     *
     * @param array<string, mixed> $live
     * @param array<string, mixed> $summary
     */
    private function rememberDisplay(string $requestUuid, array $live, array $summary): void
    {
        $copy = null;
        if (($live['transfers'] ?? []) === []) {
            $keep = $live;
            unset($keep['source'], $keep['stale']);
            $keep['proformaCancelled'] = (bool) ($summary['advance']['proformaCancelled'] ?? false);
            // The shop's own answers, so what depends on Operations' configuration is computed again when shown.
            $keep['shopPolicy'] = ($summary['capabilities']['verification'] ?? 'four_eyes') === 'single' ? 'single' : 'four_eyes';
            $keep['shopRecord'] = ($summary['capabilities']['record'] ?? false) === true;
            try {
                $copy = json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $copy = null;
            }
        }
        $amount = static fn (mixed $value): ?string => is_string($value) && preg_match('/^-?(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/', $value) === 1 ? $value : null;
        $money = $live['money'] ?? null;
        $proforma = $live['proforma'] ?? null;
        try {
            $this->pdo->prepare('UPDATE finance_task_amounts SET display_json = ?, display_read_at = ?, order_total = ?, received_amount = ?, cod_remaining = ?, financial_state = ?, proforma_series = ?, proforma_number = ?
                WHERE request_uuid = ?')->execute([
                $copy, $copy === null ? null : $this->now(),
                $amount($money['total'] ?? null), $amount($money['received'] ?? null), $amount($money['remaining'] ?? null),
                is_string($live['financial']['state'] ?? null) ? substr((string) $live['financial']['state'], 0, 40) : null,
                is_array($proforma) && is_string($proforma['series'] ?? null) ? substr((string) $proforma['series'], 0, 20) : null,
                is_array($proforma) && is_string($proforma['number'] ?? null) ? substr((string) $proforma['number'], 0, 40) : null,
                $requestUuid,
            ]);
        } catch (PDOException) {
            // An older schema (before 062) or a lost connection: the task is read live next time.
        }
    }

    /** @return array<string, mixed>|null the display copy row of a task (null before migration 062 or when none) */
    private function displayRow(string $requestUuid): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT display_json, display_read_at FROM finance_task_amounts WHERE request_uuid = ?');
            $statement->execute([$requestUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function decodeDisplay(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($value) && ($value['available'] ?? false) === true ? $value : null;
    }

    /**
     * API 2.81.0: forgets the display copies of an order's tasks: called before any finance action is sent to the shop
     * (ConfirmareFinance::act), so a task is read live after its money may have changed. Never fails the caller.
     */
    public static function forgetDisplay(PDO $pdo, string $orderUuid): void
    {
        try {
            $pdo->prepare('UPDATE finance_task_amounts a INNER JOIN finance_verification_requests r ON r.request_uuid = a.request_uuid SET a.display_json = NULL, a.display_read_at = NULL WHERE r.order_uuid = ?')
                ->execute([$orderUuid]);
        } catch (PDOException) {
            // Before migration 062 there is no copy to forget.
        }
    }

    /** API 2.81.0: forgetDisplay() by the shop's own order id (a bank transfer exception resolved onto an order). */
    public static function forgetDisplayBySource(PDO $pdo, string $sourceKey, string $sourceOrderId): void
    {
        try {
            $pdo->prepare('UPDATE finance_task_amounts a INNER JOIN finance_verification_requests r ON r.request_uuid = a.request_uuid INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
                SET a.display_json = NULL, a.display_read_at = NULL WHERE o.source_key = ? AND o.source_order_id = ?')->execute([$sourceKey, $sourceOrderId]);
        } catch (PDOException) {
            // Before migration 062 there is no copy to forget.
        }
    }

    /** @param array<string, mixed> $summary @return list<array<string, mixed>> the bank transfers of the order (no other entry) */
    private function transfers(array $summary): array
    {
        $entries = array_values(array_filter($summary['entries'], static fn (array $entry): bool => $entry['kind'] === 'transfer_in'));
        $names = [];
        foreach ($entries as $entry) {
            $names[] = $entry['submittedBy'];
            if ($entry['decidedBy'] !== null) {
                $names[] = $entry['decidedBy'];
            }
        }
        $names = $this->names(array_values(array_unique($names)));
        return array_map(static fn (array $entry): array => [
            'id' => $entry['id'],
            'purpose' => $entry['purpose'],
            'amount' => $entry['amount'],
            'reference' => $entry['reference'],
            'valueDate' => $entry['valueDate'],
            'method' => $entry['method'],
            'state' => $entry['state'],
            'decisionCode' => $entry['decisionCode'],
            'flags' => $entry['flags'],
            // API 2.80.0 (YD SOFT 2.32.0): who paid, how the transfer was matched, the entry a correction replaced.
            'payer' => $entry['payer'] ?? null,
            'matchBasis' => $entry['matchBasis'] ?? null,
            'matchNote' => $entry['matchNote'] ?? null,
            'corrects' => $entry['corrects'] ?? null,
            'submittedBy' => ['id' => $entry['submittedBy'], 'displayName' => $names[$entry['submittedBy']] ?? null],
            'submittedAt' => $entry['submittedAt'],
            'decidedBy' => $entry['decidedBy'] === null ? null : ['id' => $entry['decidedBy'], 'displayName' => $names[$entry['decidedBy']] ?? null],
            'decidedAt' => $entry['decidedAt'],
        ], $entries);
    }

    /**
     * What the actor may do with the task now (presentation; every command checks again).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $live
     * @return array{allowed: array<string, bool>, me: string}
     */
    private function allowed(EmployeeIdentity $actor, array $request, array $live, ?SourceAccessTarget $target): array
    {
        $source = (string) $request['source_key'];
        $mine = $request['assigned_employee_uuid'] === $actor->employeeUuid;
        $clean = $request['source_entry_id'] === null && $request['finance_idempotency_key'] === null;
        $manual = $request['ledger'] === self::LEDGER_MANUAL;
        $advance = $request['purpose'] === 'advance';
        // A manual-ledger task only reads the shop; a shop-ledger task needs the shop's finance record open for it.
        // API 2.81.0: a stale copy (shown while the shop does not answer) enables no command.
        $available = ($live['available'] ?? false) === true && ($live['stale'] ?? false) !== true && ($manual || ($live['recordEnabled'] ?? false) === true) && !($live['proformaChanged'] ?? true);
        $single = !$manual && ($live['policy'] ?? 'four_eyes') === 'single' && $this->finance->allows($actor, FinanceScopePolicy::VERIFY, $source);
        $canMoney = $single || $this->finance->allows($actor, FinanceScopePolicy::RECORD, $source);
        $decidable = $advance && $mine && $clean && in_array($request['state'], ['assigned', 'checking'], true);
        return ['allowed' => [
            'start' => $advance && $mine && $request['state'] === 'assigned',
            'verify' => $decidable && $available && $canMoney && ($live['financial']['attention'] ?? null) === null,
            'notReceived' => $advance && $mine && $clean && in_array($request['state'], ['assigned', 'checking', 'exception'], true),
            'clarify' => $advance && $mine && $clean && in_array($request['state'], ['assigned', 'checking', 'exception'], true),
            'secondCheck' => (($request['state'] === 'checking' && $request['policy'] === 'four_eyes' && $request['source_entry_id'] !== null && $request['checker_employee_uuid'] === $actor->employeeUuid
                && $request['recorded_by_employee_uuid'] !== $actor->employeeUuid && $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source))
                || ($this->concludesAlone($actor, $request) && ($live['policy'] ?? 'four_eyes') === 'single')) && ($live['available'] ?? false) === true && ($live['stale'] ?? false) !== true,
            'reconcile' => $request['state'] === 'exception' && $request['finance_idempotency_key'] !== null,
            // API 2.80.0 (owner 2026-11-25): on a verified advance the employee decided, a later transfer of the same order
            // (Adaugă o plată) and the correction of their own transfer (Corectează plata). The shop checks both again.
            'addPayment' => $this->paymentFollowUp($actor, $request) && $available && $single && ($live['capabilities']['match'] ?? false)
                && ($live['financial']['attention'] ?? null) === null && (float) ($live['money']['remaining'] ?? 0) > 0,
            'correctPayment' => $this->paymentFollowUp($actor, $request) && $available && $single && ($live['capabilities']['correction'] ?? false)
                && ($live['cod']['awb'] ?? true) === false && array_filter($live['transfers'] ?? [], static fn (array $t): bool => $t['state'] === 'verified' && $t['purpose'] === 'advance'
                    && $t['method'] === 'bank_transfer' && ($t['submittedBy']['id'] ?? null) === $actor->employeeUuid && in_array('single_verification', $t['flags'], true)) !== [],
        ], 'me' => $actor->employeeUuid, 'singleVerification' => $single && $target !== null];
    }

    /** API 2.80.0: a verified shop-ledger advance task this employee decided (or was assigned), whose order is still open. */
    private function paymentFollowUp(EmployeeIdentity $actor, array $request): bool
    {
        return $request['purpose'] === 'advance' && $request['ledger'] === self::LEDGER_SHOP && $request['state'] === 'verified'
            && in_array($actor->employeeUuid, [$request['decided_by_employee_uuid'], $request['assigned_employee_uuid']], true);
    }

    /** @param array<string, mixed> $request @return array<string, mixed> the answer of a decision: the task closes, a receipt shows */
    private function afterDecision(EmployeeIdentity $actor, array $request, string $requestId): array
    {
        $row = $this->orderByUuid((string) $request['order_uuid']);
        $item = $this->item($request + ['order_number' => $row['order_number'] ?? ''], $actor);
        return ['request' => $item, 'receipt' => [
            'state' => $item['state'],
            'outcome' => $item['outcome'],
            'reasonCode' => $item['reasonCode'],
            'policy' => $item['policy'],
            'awaitingSecondCheck' => $item['kind'] === 'awaiting_second_check' || $item['kind'] === 'second_check',
            'unknown' => $request['state'] === 'exception' && $request['finance_idempotency_key'] !== null,
            'at' => $item['updatedAt'],
        ]];
    }

    // ---- Commands -----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function operatorCommand(EmployeeIdentity $actor, string $globalOrderId, array $input, array $fields, string $key, string $requestId, callable $change): array
    {
        $this->requireKey($key);
        if (array_diff(array_keys($input), $fields) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $fields) . ' are accepted.');
        }
        $this->requireStaff($actor);
        $row = $this->order($this->globalId($globalOrderId));
        if ($row === null || !$this->operates($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $request = $this->latestFor((string) $row['order_uuid']);
        if ($request === null) {
            throw new ApiException(404, 'FINANCE_REQUEST_NOT_FOUND', 'No finance request of this order was found.');
        }
        $this->command($actor, $request, $input, $key, $change);
        return $this->operatorView($actor, $this->order((string) $row['global_order_id']) ?? $row);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function financeCommand(EmployeeIdentity $actor, string $requestUuid, array $input, array $fields, string $key, callable $change): array
    {
        $this->requireKey($key);
        if (array_diff(array_keys($input), $fields) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $fields) . ' are accepted.');
        }
        $this->requireStaff($actor);
        $request = $this->reachable($actor, $requestUuid);
        $this->command($actor, $request, $input, $key, $change);
        return $this->afterDecision($actor, $this->requestRow((string) $request['request_uuid']) ?? $request, 'none');
    }

    /** One version-checked change in one transaction; a retried key answers with the current state. */
    private function command(EmployeeIdentity $actor, array $request, array $input, string $key, callable $change): void
    {
        $replay = $this->eventByKey($actor->employeeUuid, $key);
        if ($replay !== null) {
            if ($replay['request_uuid'] !== $request['request_uuid']) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return;
        }
        $this->requireVersion($request, $input['expectedVersion'] ?? null);
        $this->locked((string) $request['request_uuid'], function (array $locked) use ($request, $change): void {
            if ((int) $locked['version'] !== (int) $request['version']) {
                throw new ApiException(409, 'FINANCE_REQUEST_STALE', 'The request changed. Open it again.', ['version' => (int) $locked['version']]);
            }
            $change($locked, $this->now());
        });
    }

    /** Runs $change on the request locked FOR UPDATE inside one transaction. */
    private function locked(string $requestUuid, callable $change): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM finance_verification_requests WHERE request_uuid = ? FOR UPDATE');
            $statement->execute([$requestUuid]);
            $locked = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new ApiException(404, 'FINANCE_REQUEST_NOT_FOUND', 'No such finance request was found.');
            }
            $change($locked);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && str_contains($e->getMessage(), 'uq_finance_verification_request_events_key')) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key is already in use.');
            }
            throw new ApiException(503, 'FINANCE_REQUEST_UNAVAILABLE', 'The change could not be recorded. Try again.');
        }
    }

    /**
     * @param array<string, mixed> $request the locked row
     * @param array<string, mixed> $extra columns to set besides the state
     */
    private function transition(array $request, string $state, string $action, ?string $actorUuid, ?string $outcome, ?string $reasonCode, ?string $key, string $requestId, string $now, array $extra = [], ?string $policy = null): void
    {
        $set = ['state' => $state, 'version' => (int) $request['version'] + 1, 'updated_at' => $now, 'open_order_uuid' => in_array($state, self::CLOSED_STATES, true) ? null : (string) $request['order_uuid']] + $extra;
        if ($state === 'requested') {
            $set['assigned_employee_uuid'] = null;
            $set['assigned_at'] = null;
        }
        $columns = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($set)));
        $this->pdo->prepare("UPDATE finance_verification_requests SET {$columns} WHERE request_uuid = ?")->execute([...array_values($set), (string) $request['request_uuid']]);
        $this->event((string) $request['request_uuid'], $set['version'], $action, $actorUuid, (string) $request['state'], $state, array_key_exists('assigned_employee_uuid', $set) ? $set['assigned_employee_uuid'] : $request['assigned_employee_uuid'],
            $outcome, $reasonCode, $policy ?? $request['policy'], $key, $requestId, $now);
    }

    private function event(string $requestUuid, int $version, string $action, ?string $actorUuid, ?string $previous, string $state, ?string $assignee, ?string $outcome, ?string $reasonCode, ?string $policy, ?string $key, string $requestId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO finance_verification_request_events (event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state, new_state, assigned_employee_uuid, outcome, reason_code, policy, idempotency_key, request_id, occurred_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Uuid::v4(), $requestUuid, $version, $action, $actorUuid, $previous, $state, $assignee, $outcome, $reasonCode, $policy, $key, self::cleanRequestId($requestId), $now]);
    }

    private function markUnknown(string $requestUuid, string $actorUuid, string $requestId): void
    {
        $this->locked($requestUuid, function (array $locked) use ($actorUuid, $requestId): void {
            // Still checking: the applied answer (onFinanceApplied) did not arrive, so the shop's outcome is not known.
            if ($locked['state'] === 'checking') {
                $this->transition($locked, 'exception', 'outcome_unknown', $actorUuid, null, 'outcome_unknown', null, $requestId, $this->now(), ['reason_code' => 'outcome_unknown']);
            }
        });
    }

    /**
     * Requests whose finance employee no longer holds a scope of the source go back to the pool; unassigned requests of
     * the actor's sources get the least loaded eligible employee. Deterministic, audited as system events.
     *
     * @param array<string, list<string>> $scopes the actor's finance scopes
     */
    private function rebalance(array $scopes): void
    {
        $this->rebalanceChecks($scopes[FinanceScopePolicy::APPROVE]);
        $sources = array_values(array_unique([...$scopes[FinanceScopePolicy::RECORD], ...$scopes[FinanceScopePolicy::VERIFY]]));
        if ($sources === []) {
            return;
        }
        $parameters = [];
        $condition = ConfirmareScopePolicy::condition($sources, 'source_key', $parameters);
        $statement = $this->pdo->prepare("SELECT request_uuid, assigned_employee_uuid, source_key FROM finance_verification_requests WHERE state IN ('requested', 'assigned') AND purpose IN ('advance', 'exception') AND {$condition} LIMIT 100");
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if ($candidate['assigned_employee_uuid'] !== null && $this->eligible((string) $candidate['assigned_employee_uuid'], (string) $candidate['source_key'])) {
                continue;
            }
            try {
                $this->locked((string) $candidate['request_uuid'], function (array $locked): void {
                    if (!in_array($locked['state'], ['requested', 'assigned'], true) || ($locked['assigned_employee_uuid'] !== null && $this->eligible((string) $locked['assigned_employee_uuid'], (string) $locked['source_key']))) {
                        return;
                    }
                    $assignee = $this->leastLoaded((string) $locked['source_key'], $this->policyOf((string) $locked['source_key']));
                    if ($assignee === $locked['assigned_employee_uuid']) {
                        return;
                    }
                    $now = $this->now();
                    $this->transition($locked, $assignee === null ? 'requested' : 'assigned', $assignee === null ? 'unassigned' : 'assigned', null, null, null, null, 'system', $now,
                        ['assigned_employee_uuid' => $assignee, 'assigned_at' => $assignee === null ? null : $now]);
                });
            } catch (ApiException) {
                continue;
            }
        }
    }

    /**
     * Second checks without a named checker who can still do them (none was eligible, or the checker lost `approve` or
     * the account) get the least loaded other employee with `approve`. Audited as system events.
     *
     * @param list<string> $sources the actor's approve sources
     */
    private function rebalanceChecks(array $sources): void
    {
        if ($sources === []) {
            return;
        }
        $parameters = [];
        $condition = ConfirmareScopePolicy::condition($sources, 'source_key', $parameters);
        $statement = $this->pdo->prepare("SELECT request_uuid, checker_employee_uuid, recorded_by_employee_uuid, assigned_employee_uuid, source_key FROM finance_verification_requests
            WHERE ((state IN ('checking', 'exception') AND policy = 'four_eyes' AND source_entry_id IS NOT NULL) OR (purpose = 'exception' AND open_order_uuid IS NOT NULL)) AND {$condition} LIMIT 100");
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if ($candidate['checker_employee_uuid'] !== null && $this->checks((string) $candidate['checker_employee_uuid'], (string) $candidate['source_key'])) {
                continue;
            }
            try {
                $this->locked((string) $candidate['request_uuid'], function (array $locked): void {
                    if ($locked['checker_employee_uuid'] !== null && $this->checks((string) $locked['checker_employee_uuid'], (string) $locked['source_key'])) {
                        return;
                    }
                    $checker = self::leastLoadedChecker($this->pdo, (string) $locked['source_key'], $locked['recorded_by_employee_uuid'] === null ? ($locked['assigned_employee_uuid'] === null ? null : (string) $locked['assigned_employee_uuid']) : (string) $locked['recorded_by_employee_uuid']);
                    if ($checker === $locked['checker_employee_uuid']) {
                        return;
                    }
                    $this->transition($locked, (string) $locked['state'], $checker === null ? 'checker_unassigned' : 'checker_assigned', null, null, null, null, 'system', $this->now(), ['checker_employee_uuid' => $checker]);
                });
            } catch (ApiException) {
                continue;
            }
        }
    }

    private function checks(string $employeeUuid, string $source): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM employee_finance_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
            INNER JOIN employee_application_access a ON a.employee_uuid = e.employee_uuid AND a.application_key = 'staff'
            WHERE s.employee_uuid = ? AND s.source_key = ? AND s.capability = 'approve' AND e.status = 'active' AND e.must_change_password = 0 LIMIT 1");
        $statement->execute([$employeeUuid, $source]);
        return $statement->fetchColumn() !== false;
    }

    private function leastLoaded(string $source, string $policy): ?string
    {
        return self::leastLoadedFinance($this->pdo, $source, $policy);
    }

    /** The least loaded eligible finance employee of the source (verify first under `single`), or null. */
    public static function leastLoadedFinance(PDO $pdo, string $source, string $policy): ?string
    {
        $statement = $pdo->prepare("SELECT s.employee_uuid, MAX(s.capability = 'verify') AS verifies,
                (SELECT COUNT(*) FROM finance_verification_requests r WHERE r.assigned_employee_uuid = s.employee_uuid AND r.open_order_uuid IS NOT NULL) AS open_requests
            FROM employee_finance_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
            INNER JOIN employee_application_access a ON a.employee_uuid = e.employee_uuid AND a.application_key = 'staff'
            WHERE s.source_key = ? AND s.capability IN ('record', 'verify') AND e.status = 'active' AND e.must_change_password = 0
              AND e.employee_uuid NOT IN (SELECT employee_uuid FROM system_root_identity)
            GROUP BY s.employee_uuid ORDER BY " . ($policy === 'single' ? 'verifies DESC, ' : '') . 'open_requests, s.employee_uuid LIMIT 1');
        $statement->execute([$source]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function eligible(string $employeeUuid, string $source): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM employee_finance_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
            INNER JOIN employee_application_access a ON a.employee_uuid = e.employee_uuid AND a.application_key = 'staff'
            WHERE s.employee_uuid = ? AND s.source_key = ? AND s.capability IN ('record', 'verify') AND e.status = 'active' AND e.must_change_password = 0 LIMIT 1");
        $statement->execute([$employeeUuid, $source]);
        return $statement->fetchColumn() !== false;
    }

    private function policyOf(string $source): string
    {
        return $this->registry->find($source)?->allowsSingleVerification() === true ? 'single' : 'four_eyes';
    }

    // ---- Access -------------------------------------------------------------------------------------------------------

    /**
     * The task, if this finance employee reaches it now: assigned to them, recorded or decided by them, or waiting for the
     * second check they are the named checker of. Anything else is "not found" (an unassigned or another employee's id,
     * or a guessed order, reveals nothing).
     *
     * @return array<string, mixed>
     */
    private function reachable(EmployeeIdentity $actor, string $requestUuid): array
    {
        $request = preg_match(self::UUID_PATTERN, $requestUuid) === 1 ? $this->requestRow($requestUuid) : null;
        $source = $request === null ? null : (string) $request['source_key'];
        $reaches = $request !== null && !$actor->isRoot && $this->financeScopeOn($actor, $source) && (
            $request['assigned_employee_uuid'] === $actor->employeeUuid
            || $request['recorded_by_employee_uuid'] === $actor->employeeUuid
            || $request['decided_by_employee_uuid'] === $actor->employeeUuid
            || ($request['open_order_uuid'] !== null && $request['checker_employee_uuid'] === $actor->employeeUuid
                && $request['recorded_by_employee_uuid'] !== $actor->employeeUuid && $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source)));
        if (!$reaches) {
            throw new ApiException(404, 'FINANCE_REQUEST_NOT_FOUND', 'No such finance request was found.');
        }
        return $request;
    }

    /**
     * Whether the actor operates the order's finance request: in Confirmare its assignee or approver with `operate`, or a
     * supervisor of the source; outside Confirmare (legacy, NULL, undetermined: there is no Confirmare assignee) an
     * operator or supervisor of the source. Never root, never a finance scope.
     *
     * @param array<string, mixed> $row
     */
    private function operates(EmployeeIdentity $actor, array $row): bool
    {
        if ($actor->isRoot) {
            // API 2.57.0: the root intervention desk withdraws or resends a request like a supervisor of the source.
            return $this->finance->rootActs($actor);
        }
        $source = (string) $row['source_key'];
        if ($this->scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)) {
            return true;
        }
        if ($row['workflow_cohort'] !== 'confirmare') {
            return $this->scopes->allows($actor, ConfirmareScopePolicy::OPERATE, $source);
        }
        return in_array($actor->employeeUuid, [$row['assigned_employee_uuid'], $row['decided_by_employee_uuid']], true) && $this->scopes->allows($actor, ConfirmareScopePolicy::OPERATE, $source);
    }

    /** @param array<string, mixed> $row */
    private function requireRequestable(array $row): void
    {
        $scope = FinanceTaskScope::ofRow($row);
        if ($scope === FinanceTaskScope::BEFORE_SCOPE) {
            throw new ApiException(409, 'FINANCE_REQUEST_OUT_OF_SCOPE', 'The order was submitted before 22 October 2026: it keeps its existing workflow.');
        }
        if ($scope === FinanceTaskScope::UNDETERMINED) {
            throw new ApiException(409, 'FINANCE_REQUEST_SCOPE_UNDETERMINED', 'The order has no submission evidence: it needs a manual review first.');
        }
        if ($row['closed']) {
            throw new ApiException(409, 'FINANCE_REQUEST_ORDER_CLOSED', 'The order was cancelled or closed in the shop.');
        }
        if ($row['workflow_cohort_reason'] === 'financing_legacy') {
            // A consumer credit (TBI) order keeps its financing flow (owner K-12): no advance to verify.
            throw new ApiException(409, 'FINANCE_REQUEST_FINANCING', 'A consumer credit order keeps its financing flow.');
        }
        if ($row['workflow_cohort'] === 'confirmare') {
            // API 2.80.0 (owner 2026-11-25): the operator and finance work in parallel. Finance verifies the money of any
            // order the operator has not rejected; production still needs both the approval and the money
            // (ConfirmationClearance), so nothing reaches production on the money alone.
            if (!in_array($row['status'], ['pending', 'approved'], true)) {
                throw new ApiException(409, 'FINANCE_REQUEST_NOT_APPROVED', 'Finance verifies the advance of an order that was not rejected.');
            }
            if ($row['payment_method'] !== 'cod') {
                throw new ApiException(409, 'FINANCE_REQUEST_NOT_COD', 'Only a cash-on-delivery order pays an advance by bank transfer.');
            }
        } elseif ($row['payment_method'] === 'online') {
            // An older order: its payment method may be unknown here; the shop's summary decides, an online one never.
            throw new ApiException(409, 'FINANCE_REQUEST_NOT_COD', 'Only a cash-on-delivery order pays an advance by bank transfer.');
        }
        if ($row['source_financial_attention'] === 'transfer_pending') {
            throw new ApiException(409, 'FINANCE_REQUEST_TRANSFER_PENDING', 'A bank transfer of this order is already recorded and waits for its verification.');
        }
        if ($row['workflow_cohort'] === 'confirmare' ? !in_array($row['source_financial_state'], ['waiting', 'partial'], true) : $row['source_financial_state'] === 'cleared') {
            throw new ApiException(409, 'FINANCE_REQUEST_NOTHING_DUE', 'The advance of this order is not waiting for a payment.');
        }
    }

    /** @param array<string, mixed> $row the order: a Confirmare order's money is recorded in the shop, any other one's manually */
    private static function ledgerOf(array $row): string
    {
        return $row['workflow_cohort'] === 'confirmare' ? self::LEDGER_SHOP : self::LEDGER_MANUAL;
    }

    /** @param array<string, mixed> $row a task @return array{series: ?string, number: string}|null */
    private static function proformaOf(array $row): ?array
    {
        return $row['proforma_number'] === null ? null : ['series' => $row['proforma_series'], 'number' => (string) $row['proforma_number']];
    }

    private function requireStaff(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'staff');
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword) {
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'Your account cannot work on finance tasks now.');
        }
    }

    /** @return array<string, list<string>> */
    private function financeScopes(EmployeeIdentity $actor): array
    {
        if ($actor->isRoot) {
            return [FinanceScopePolicy::VIEW => [], FinanceScopePolicy::RECORD => [], FinanceScopePolicy::APPROVE => [], FinanceScopePolicy::VERIFY => []];
        }
        return $this->finance->scopesOf($actor->employeeUuid);
    }

    private function financeScopeOn(EmployeeIdentity $actor, ?string $source): bool
    {
        return $source !== null && in_array($source, $this->finance->sources($actor, FinanceScopePolicy::VIEW), true);
    }

    // ---- Validation ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array{expectedRevision: string, amount: string, reference: string, valueDate: string, paymentReference: string, currency: string}
     */
    private function evidence(array $input): array
    {
        $invalid = static fn (string $field): ApiException => new ApiException(422, 'FINANCE_REQUEST_INVALID', 'The bank evidence is not valid.', ['field' => $field]);
        $revision = $input['expectedRevision'] ?? null;
        if (!is_string($revision) || preg_match('/^sha256:[0-9a-f]{64}$/D', $revision) !== 1) {
            throw $invalid('expectedRevision');
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
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')) > $this->clock->now()->modify('+1 day')) {
            throw $invalid('valueDate');
        }
        $currency = $input['currency'] ?? null;
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw $invalid('currency');
        }
        // API 2.80.0 (owner 2026-11-25): the statement need not show the proforma's payment reference. Without matchBasis
        // (an older Staff) it must, exactly as before; with another basis the payer (and the evidence) is named.
        $match = ConfirmareFinance::match($input, $invalid, true);
        $paymentReference = isset($match['paymentReference']) ? strtoupper((string) $match['paymentReference']) : null;
        $basis = $match['matchBasis'] ?? null;
        if (($basis === null || $basis === 'proforma_reference') && ($paymentReference === null || preg_match(SourceInvoice::PAYMENT_REFERENCE_PATTERN, $paymentReference) !== 1)) {
            throw $invalid('paymentReference');
        }
        return ['expectedRevision' => $revision, 'amount' => $amount, 'reference' => $reference, 'valueDate' => $date, 'paymentReference' => $paymentReference, 'currency' => $currency,
            'matchBasis' => $basis, 'payerName' => $match['payerName'] ?? null, 'matchNote' => $match['matchNote'] ?? null];
    }

    private function requireVersion(array $request, mixed $expected): void
    {
        if (!is_int($expected) || $expected < 1) {
            throw new ApiException(422, 'FINANCE_REQUEST_INVALID', 'expectedVersion is required.', ['field' => 'expectedVersion']);
        }
        if ($expected !== (int) $request['version']) {
            throw new ApiException(409, 'FINANCE_REQUEST_STALE', 'The request changed. Open it again.', ['version' => (int) $request['version']]);
        }
    }

    private function requireKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
    }

    /** @param array<string, mixed> $request */
    private static function stateError(array $request): ApiException
    {
        return new ApiException(409, 'FINANCE_REQUEST_STATE', 'The request is not in a state that allows this.', ['state' => (string) $request['state']]);
    }

    // ---- Reads ----------------------------------------------------------------------------------------------------------

    /**
     * The shop's finance summary, or null when the shop cannot be read (the failure code in $failure).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function summary(SourceAccessTarget $target, array $row, string $employeeUuid, string $role, string $requestId, ?string &$failure = null): ?array
    {
        try {
            return SourceFinance::summary($this->client->call($target, SourceAccessClient::ROUTE_FINANCE, $this->payload($row, $employeeUuid, $role, $requestId)), (string) $row['source_order_id'], (string) $row['order_number']);
        } catch (SourceAccessException $e) {
            $failure = strtolower($e->codeName);
            return null;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function payload(array $row, string $employeeUuid, string $role, string $requestId): array
    {
        return ['orderId' => (string) $row['source_order_id'], 'orderNumber' => (string) $row['order_number'], 'actor' => ['employeeId' => $employeeUuid, 'role' => $role], 'requestId' => self::cleanRequestId($requestId)];
    }

    /** "RO49 •••• •••• 0000": the country, the check digits and the last four characters only. */
    private static function maskIban(string $iban): string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
        return strlen($compact) < 10 ? '••••' : substr($compact, 0, 4) . ' •••• •••• ' . substr($compact, -4);
    }

    /**
     * API 2.77.9: keeps the advance amount of one task as the shop showed it, for the inbox list (never a decision input:
     * every decision reads the shop again and checks its revision). A failed write changes nothing else.
     *
     * @param array<string, mixed> $summary SourceFinance::summary
     */
    private function rememberAmount(string $requestUuid, array $summary): void
    {
        $advance = $summary['advance'] ?? null;
        $amount = static fn (mixed $value): ?string => is_string($value) && preg_match('/^-?(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/', $value) === 1 ? $value : null;
        $currency = is_string($summary['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', (string) $summary['currency']) === 1 ? (string) $summary['currency'] : null;
        if ($currency === null) {
            return;
        }
        $now = $this->now();
        try {
            $this->pdo->prepare('INSERT INTO finance_task_amounts (request_uuid, currency, required_amount, remaining_amount, verified_amount, percentage, finance_revision, read_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE currency = VALUES(currency), required_amount = VALUES(required_amount), remaining_amount = VALUES(remaining_amount), verified_amount = VALUES(verified_amount),
                    percentage = VALUES(percentage), finance_revision = VALUES(finance_revision), read_at = VALUES(read_at), updated_at = VALUES(updated_at)')->execute([
                $requestUuid, $currency, $amount($advance['required'] ?? null), $amount($advance['outstanding'] ?? null), $amount($advance['collected'] ?? null),
                is_int($advance['percentage'] ?? null) ? $advance['percentage'] : null, is_string($summary['revision'] ?? null) && strlen((string) $summary['revision']) <= 71 ? $summary['revision'] : null, $now, $now,
            ]);
        } catch (PDOException) {
            // The list then shows the amount as not read yet; the task screen still reads it live.
        }
    }

    /**
     * API 2.77.9 (bin/finance-task-amounts.php, cron): reads from the shop the advance of the open advance tasks whose
     * stored amount is missing or older than AMOUNT_FRESH_MINUTES, oldest task first, one shop call at a time with a
     * pause, at most $max per run. A read only (never a decision); each read is audited in order_finance_reads under
     * the given employee (the root identity), so the list never asks the shop per row.
     *
     * @return array{read: int, failed: int, skipped: int, left: int}
     */
    public function refreshAmounts(string $employeeUuid, int $max, int $pauseMs, string $requestId): array
    {
        $stale = $this->sql($this->clock->now()->modify('-' . self::AMOUNT_FRESH_MINUTES . ' minutes'));
        $where = "r.open_order_uuid IS NOT NULL AND r.purpose = 'advance' AND (a.request_uuid IS NULL OR a.read_at < ?)";
        $list = $this->pdo->prepare("SELECT r.* FROM finance_verification_requests r LEFT JOIN finance_task_amounts a ON a.request_uuid = r.request_uuid WHERE {$where}
            ORDER BY a.read_at IS NOT NULL, r.requested_at, r.request_uuid LIMIT " . max(1, $max));
        $list->execute([$stale]);
        $result = ['read' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 0];
        $first = true;
        $started = microtime(true);
        $failedHosts = [];
        foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $request) {
            if (microtime(true) - $started > self::AMOUNT_RUN_SECONDS) {
                break;
            }
            $row = $this->orderByUuid((string) $request['order_uuid']);
            $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
            if ($row === null || $target === null || !$target->allowsFinanceRead()) {
                $result['skipped']++;
                continue;
            }
            // API 2.81.0: a shop that failed three reads in this run is not asked again in it (the other shop goes on).
            if (($failedHosts[(string) $row['source_key']] ?? 0) >= 3) {
                $result['skipped']++;
                continue;
            }
            if (!$first && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $first = false;
            // API 2.81.0: the same read as opening the task (audited, amount and display copy kept), so the next opening
            // of an unchanged task needs no shop call.
            $live = $this->live($target, $row, $request, $employeeUuid, $requestId);
            if (!$live['available']) {
                $failedHosts[(string) $row['source_key']] = ($failedHosts[(string) $row['source_key']] ?? 0) + 1;
                $result['failed']++;
                continue;
            }
            $failedHosts[(string) $row['source_key']] = 0;
            $result['read']++;
        }
        $left = $this->pdo->prepare("SELECT COUNT(*) FROM finance_verification_requests r LEFT JOIN finance_task_amounts a ON a.request_uuid = r.request_uuid WHERE {$where}");
        $left->execute([$stale]);
        $result['left'] = (int) $left->fetchColumn();
        return $result;
    }

    /**
     * API 2.80.0 (owner 2026-11-25): the system's payment tracking, run by bin/finance-payment-tracking.php under the root
     * identity (every shop read audited in order_finance_reads as root). Two kinds of Trend Home and Outlet Perdele
     * cash-on-delivery Confirmare orders whose money is still waiting:
     * - recheck: the latest advance task closed as not received, and its next check (the next morning, RECHECK_HOUR
     *   Bucharest) has come: a new task, so the payment is looked for again once a day, never several times;
     * - tracking: a proforma was really sent (the operator's send or the proforma cycle) and no finance task ever existed:
     *   finance follows the payment without the operator sending the order to it.
     * Only through the same creation as the operator's request (one open task per order, the shop's proforma and advance
     * read first; a covered, cancelled or unknown order is refused). `$apply` false: counts only, no shop call, no write.
     *
     * @return array{recheck: int, tracking: int, created: int, exists: int, skipped: array<string, int>, left: int}
     */
    public function track(string $rootUuid, int $max, int $pauseMs, string $requestId, bool $apply): array
    {
        $open = "o.workflow_cohort = 'confirmare' AND o.source_key IN ('trendhome', 'outletperdele') AND o.source_financial_state IN ('waiting', 'partial')
            AND o.cancellation_state IS NULL AND o.operational_status <> 'unavailable' AND o.source_reported_unavailable_at IS NULL
            AND (o.source_commerce_status_code IS NULL OR o.source_commerce_status_code NOT IN ('cancelled', 'refunded', 'failed', 'completed', 'trash'))
            AND c.status IN ('pending', 'approved')";
        $recheck = $this->pdo->prepare("SELECT r.request_uuid, r.order_uuid, r.decided_at FROM finance_verification_requests r
                INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE r.purpose = 'advance' AND r.state = 'not_received' AND r.ledger = 'shop' AND r.decided_at < ? AND {$open}
              AND NOT EXISTS (SELECT 1 FROM finance_verification_requests n WHERE n.order_uuid = r.order_uuid AND n.requested_at > r.requested_at)
            ORDER BY r.decided_at, r.request_uuid LIMIT 500");
        $recheck->execute([$this->sql($this->clock->now()->modify('-6 hours'))]);
        $now = $this->clock->now();
        $candidates = [];
        foreach ($recheck->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // API 2.82.0 (owner decision 2026-11-26): with Operation Simplification V1 switched on there is no automatic
            // next-morning recheck; the finance employee opens the order again whenever she wants (checkOrder).
            if ($this->autoRecheck && self::recheckAfter((string) $row['decided_at']) <= $now) {
                $candidates[] = ['order' => (string) $row['order_uuid'], 'key' => 'auto-recheck-' . str_replace('-', '', (string) $row['request_uuid']), 'reason' => 'auto_recheck'];
            }
        }
        $rechecks = count($candidates);
        $tracking = $this->pdo->prepare("SELECT o.order_uuid FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE {$open} AND o.payment_method = 'cod'
              AND NOT EXISTS (SELECT 1 FROM finance_verification_requests r WHERE r.order_uuid = o.order_uuid)
              AND (EXISTS (SELECT 1 FROM order_invoice_actions i WHERE i.order_uuid = o.order_uuid AND i.action = 'proforma_send_mail' AND i.state = 'applied')
                OR EXISTS (SELECT 1 FROM confirmare_proforma_cycles p WHERE p.order_uuid = o.order_uuid AND p.sent_at IS NOT NULL))
            ORDER BY o.created_at, o.order_uuid LIMIT 500");
        $tracking->execute();
        foreach ($tracking->fetchAll(PDO::FETCH_COLUMN) as $orderUuid) {
            $candidates[] = ['order' => (string) $orderUuid, 'key' => 'auto-track-' . str_replace('-', '', (string) $orderUuid), 'reason' => 'auto_tracking'];
        }
        $result = ['recheck' => $rechecks, 'tracking' => count($candidates) - $rechecks, 'created' => 0, 'exists' => 0, 'skipped' => [], 'left' => max(0, count($candidates) - $max)];
        $skip = static function (array &$result, string $code): void { $result['skipped'][$code] = ($result['skipped'][$code] ?? 0) + 1; };
        $first = true;
        foreach (array_slice($candidates, 0, max(1, $max)) as $candidate) {
            $row = $this->orderByUuid($candidate['order']);
            $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
            if ($row === null || $target === null || !$target->allowsFinanceRecord()) {
                $skip($result, 'finance_disabled');
                continue;
            }
            if ($this->openFor((string) $row['order_uuid']) !== null || $this->byRequesterKey($rootUuid, $candidate['key']) !== null) {
                $result['exists']++;
                continue;
            }
            try {
                $this->requireRequestable($row);
            } catch (ApiException $e) {
                $skip($result, strtolower($e->errorCode));
                continue;
            }
            if (!$apply) {
                continue;
            }
            if (!$first && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $first = false;
            try {
                $created = $this->create($rootUuid, $row, $target, self::LEDGER_SHOP, ConfirmareFinance::ROLE_FINANCE, $candidate['key'], $requestId, $candidate['reason']);
                $result[$created === 'created' ? 'created' : 'exists']++;
            } catch (ApiException $e) {
                $skip($result, strtolower($e->errorCode));
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function audit(array $row, EmployeeIdentity $actor, string $outcome, string $requestId, ?string $revision = null): bool
    {
        return $this->auditAs($row, $actor->employeeUuid, $outcome, $requestId, $revision);
    }

    /** @param array<string, mixed> $row */
    private function auditAs(array $row, string $employeeUuid, string $outcome, string $requestId, ?string $revision = null): bool
    {
        try {
            $this->pdo->prepare('INSERT INTO order_finance_reads (read_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, outcome, finance_revision, request_id, read_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Uuid::v4(), (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], $employeeUuid, ConfirmareFinance::ROLE_FINANCE,
                substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40), $outcome === 'ok' ? $revision : null, self::cleanRequestId($requestId), $this->now(),
            ]);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array<string, mixed>|null the order with its Confirmare state and money columns */
    private function order(string $globalId): ?array
    {
        return $this->orderWhere('o.global_order_id = ?', $globalId);
    }

    /** @return array<string, mixed>|null */
    private function orderByUuid(string $orderUuid): ?array
    {
        return $this->orderWhere('o.order_uuid = ?', $orderUuid);
    }

    /** @return array<string, mixed>|null */
    private function orderWhere(string $where, string $value): ?array
    {
        // Any order of a source (API 2.50.0): an order outside Confirmare has no confirmation row and keeps its workflow.
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, o.payment_method, o.operational_status, o.source_reported_unavailable_at,
                o.source_financial_state, o.source_financial_basis, o.source_financial_attention, o.source_financial_pricing, o.source_pricing_fingerprint, o.source_submitted_at, o.created_at, o.source_commerce_status_code,
                o.cancellation_state, o.workflow_cohort_reason, o.source_changed_at, c.status, c.assigned_employee_uuid, c.decided_by_employee_uuid
            FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE {$where}");
        $statement->execute([$value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        // Closed for finance tasks: cancelled, unavailable, or finished in the shop (nothing is reopened).
        $row['closed'] = $row['operational_status'] === 'unavailable' || $row['source_reported_unavailable_at'] !== null
            || in_array($row['source_commerce_status_code'], self::CLOSED_COMMERCE, true) || $row['status'] === 'rejected'
            || $row['cancellation_state'] === 'held';
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function requestRow(string $requestUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM finance_verification_requests WHERE request_uuid = ?');
        $statement->execute([$requestUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null the open request of the order */
    private function openFor(string $orderUuid, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM finance_verification_requests WHERE open_order_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null the open request, else the latest one */
    private function latestFor(string $orderUuid): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT * FROM finance_verification_requests WHERE order_uuid = ? ORDER BY (open_order_uuid IS NOT NULL) DESC, requested_at DESC, request_uuid LIMIT 1');
            $statement->execute([$orderUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function byRequesterKey(string $employeeUuid, string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM finance_verification_requests WHERE requested_by_employee_uuid = ? AND request_idempotency_key = ?');
        $statement->execute([$employeeUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function eventByKey(string $employeeUuid, string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM finance_verification_request_events WHERE actor_employee_uuid = ? AND idempotency_key = ?');
        $statement->execute([$employeeUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null the order's unsettled finance action (its key, action and author) */
    private function pendingAction(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare("SELECT action, decision, reason_code, idempotency_key, employee_uuid FROM order_finance_actions WHERE order_uuid = ? AND state IN ('pending', 'unknown') ORDER BY requested_at LIMIT 1");
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
        // Settled in the meantime (another tab or the order's finance panel): the latest applied action of a request key.
        $statement = $this->pdo->prepare("SELECT a.action, a.decision, a.reason_code, a.idempotency_key, a.employee_uuid FROM order_finance_actions a
            INNER JOIN finance_verification_requests r ON r.order_uuid = a.order_uuid AND (r.finance_idempotency_key = a.idempotency_key OR r.source_entry_id = a.source_entry_id)
            WHERE a.order_uuid = ? AND a.state = 'applied' AND r.state = 'exception' ORDER BY a.completed_at DESC LIMIT 1");
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string> $uuids
     * @return array<string, string>
     */
    private function names(array $uuids): array
    {
        $uuids = array_values(array_filter(array_unique($uuids), static fn (string $id): bool => preg_match(self::UUID_PATTERN, $id) === 1));
        if ($uuids === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT employee_uuid, display_name FROM employees WHERE employee_uuid IN (' . implode(', ', array_fill(0, count($uuids), '?')) . ')');
        $statement->execute($uuids);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(string) $row['employee_uuid']] = (string) $row['display_name'];
        }
        return $names;
    }

    private function rateLimit(EmployeeIdentity $actor): void
    {
        if (!$this->rateLimiter->hit('finance-requests', $actor->employeeUuid, self::CALLS_PER_MINUTE, 60)) {
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

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function now(): string
    {
        return $this->sql($this->clock->now());
    }

    private function sql(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function cleanRequestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private static function iso(string $sql): string
    {
        return (new DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
