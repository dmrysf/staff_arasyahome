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
    private const LIMIT = 300;

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
        $summary = $this->summary($target, $row, $actor->employeeUuid, $role, $requestId);
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
                return $this->operatorView($actor, $row) + ['created' => false];
            }
            $policy = $ledger === self::LEDGER_SHOP && $target->allowsSingleVerification() && ($summary['capabilities']['verification'] ?? 'four_eyes') === 'single' ? 'single' : 'four_eyes';
            $assignee = $this->leastLoaded((string) $row['source_key'], $policy);
            $state = $assignee === null ? 'requested' : 'assigned';
            $this->pdo->prepare('INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
                    requested_by_employee_uuid, requested_at, request_idempotency_key, ledger, proforma_series, proforma_number, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $uuid, (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], 'advance', $state, (string) $row['order_uuid'], $assignee, $assignee === null ? null : $now,
                $actor->employeeUuid, $now, $key, $ledger, $proforma['series'] === '' ? null : $proforma['series'], $proforma['number'], $now,
            ]);
            $this->event($uuid, 1, 'requested', $actor->employeeUuid, null, $state, $assignee, null, null, null, $key, $requestId, $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && ($this->openFor((string) $row['order_uuid']) !== null || $this->byRequesterKey($actor->employeeUuid, $key) !== null)) {
                // A concurrent request of the same order (or the same key) won: answer with it.
                return $this->operatorView($actor, $row) + ['created' => false];
            }
            throw new ApiException(503, 'FINANCE_REQUEST_UNAVAILABLE', 'The request could not be recorded. Try again.');
        }
        return $this->operatorView($actor, $row) + ['created' => true];
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
        // named checker of. A scope alone lists nothing of the source.
        $statement = $this->pdo->prepare("SELECT r.*, o.order_number FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
            WHERE (r.assigned_employee_uuid = ? AND (r.open_order_uuid IS NOT NULL OR r.updated_at >= ?)) OR (r.decided_by_employee_uuid = ? AND r.updated_at >= ?)
               OR (r.checker_employee_uuid = ? AND (r.open_order_uuid IS NOT NULL OR r.updated_at >= ?))
            ORDER BY r.requested_at, r.request_uuid LIMIT " . self::LIMIT);
        $statement->execute([$actor->employeeUuid, $since, $actor->employeeUuid, $since, $actor->employeeUuid, $since]);
        $items = [];
        $counts = ['todo' => 0, 'clarification' => 0, 'done' => 0];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((string) $row['source_key'], $sources, true)) {
                continue;
            }
            $item = $this->item($row, $actor);
            $counts[$item['category']]++;
            $items[] = $item;
        }
        return ['items' => $items, 'counts' => $counts, 'sources' => $sources, 'limit' => self::LIMIT];
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
            $this->rateLimit($actor);
            $live = $this->live($target, $row, $request, $actor, $requestId);
        }
        return ['request' => $this->item($request + ['order_number' => $row['order_number'] ?? ''], $actor), 'live' => $live] + $this->allowed($actor, $request, $live, $target);
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
        $allowed = ['expectedVersion', 'outcome', 'expectedRevision', 'amount', 'reference', 'valueDate', 'paymentReference', 'currency'];
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
            throw new ApiException(409, 'FINANCE_REQUEST_STALE', 'The money of the order changed. Open the task again.', ['revision' => $summary['revision']]);
        }
        $proforma = $summary['advance']['proforma'] ?? null;
        if ($proforma === null || $proforma['number'] !== $request['proforma_number'] || ($proforma['series'] === '' ? null : $proforma['series']) !== $request['proforma_series'] || ($summary['advance']['proformaCancelled'] ?? false)) {
            throw new ApiException(409, 'FINANCE_REQUEST_PROFORMA_CHANGED', 'The advance proforma of this request changed or was cancelled. Ask the operator.');
        }
        if ($evidence['currency'] !== $summary['currency']) {
            throw new ApiException(422, 'FINANCE_REQUEST_CURRENCY', 'The payment is in another currency than the order.', ['field' => 'currency']);
        }
        $expectedReference = $proforma['reference'] ?? $summary['advance']['paymentReference'] ?? null;
        if ($expectedReference === null || !hash_equals((string) $expectedReference, $evidence['paymentReference'])) {
            throw new ApiException(422, 'FINANCE_REQUEST_PAYMENT_REFERENCE', 'The payment reference on the statement is not the one of this proforma. Ask for a clarification.', ['field' => 'paymentReference']);
        }
        if ($manual) {
            // An order outside Confirmare: the shop's finance record is not written and the order is not moved. The
            // payment found on the statement is handed to the old workflow; it is never money received here.
            if (!$this->finance->allows($actor, FinanceScopePolicy::RECORD, (string) $row['source_key'])) {
                throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance permission for this action on this order.');
            }
            $hmac = hash_hmac('sha256', json_encode([$request['request_uuid'], $evidence['amount'], $evidence['reference'], $evidence['valueDate'], $evidence['paymentReference'], $evidence['currency']], JSON_THROW_ON_ERROR), $this->hmacKey);
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
        $action = $single ? 'verify_transfer' : 'submit_transfer';
        $policy = $single ? 'single' : 'four_eyes';
        $financeKey = 'fvr-' . str_replace('-', '', (string) $request['request_uuid']) . '-v' . ((int) $request['version'] + 1);
        $hmac = hash_hmac('sha256', json_encode([$request['request_uuid'], $evidence['amount'], $evidence['reference'], $evidence['valueDate'], $evidence['paymentReference'], $evidence['currency']], JSON_THROW_ON_ERROR), $this->hmacKey);
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
            + ($single ? ['paymentReference' => $evidence['paymentReference'], 'currency' => $evidence['currency'], 'bankStatementConfirmed' => true] : []);
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
        $category = match (true) {
            in_array($row['state'], self::CLOSED_STATES, true) => 'done',
            $row['state'] === 'needs_clarification' => 'clarification',
            default => 'todo',
        };
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
        ];
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
    private function live(SourceAccessTarget $target, array $row, array $request, EmployeeIdentity $actor, string $requestId): array
    {
        $summary = $this->summary($target, $row, $actor->employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId, $failure);
        if ($summary === null) {
            $this->audit($row, $actor, $failure ?? 'source_unavailable', $requestId);
            return ['available' => false, 'reason' => $failure ?? 'source_unavailable'];
        }
        if (!$this->audit($row, $actor, 'ok', $requestId, $summary['revision'])) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        $bank = null;
        if ($target->allowsInvoiceRead()) {
            try {
                $invoice = SourceInvoice::summary($this->client->call($target, SourceAccessClient::ROUTE_INVOICE, $this->payload($row, $actor->employeeUuid, ConfirmareFinance::ROLE_FINANCE, $requestId)), (string) $row['source_order_id'], (string) $row['order_number']);
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
        return [
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
            'settledElsewhere' => $settled && in_array($request['state'], ['requested', 'assigned', 'checking'], true) && $request['source_entry_id'] === null,
        ];
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
        $available = ($live['available'] ?? false) === true && ($manual || ($live['recordEnabled'] ?? false) === true) && !($live['proformaChanged'] ?? true);
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
                || ($this->concludesAlone($actor, $request) && ($live['policy'] ?? 'four_eyes') === 'single')) && ($live['available'] ?? false) === true,
            'reconcile' => $request['state'] === 'exception' && $request['finance_idempotency_key'] !== null,
        ], 'me' => $actor->employeeUuid, 'singleVerification' => $single && $target !== null];
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
            return false;
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
            if ($row['status'] !== 'approved') {
                throw new ApiException(409, 'FINANCE_REQUEST_NOT_APPROVED', 'Finance verifies the advance of an approved order.');
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
        $paymentReference = is_string($input['paymentReference'] ?? null) ? strtoupper(trim($input['paymentReference'])) : null;
        if ($paymentReference === null || preg_match(SourceInvoice::PAYMENT_REFERENCE_PATTERN, $paymentReference) !== 1) {
            throw $invalid('paymentReference');
        }
        $currency = $input['currency'] ?? null;
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw $invalid('currency');
        }
        return ['expectedRevision' => $revision, 'amount' => $amount, 'reference' => $reference, 'valueDate' => $date, 'paymentReference' => $paymentReference, 'currency' => $currency];
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

    /** @param array<string, mixed> $row */
    private function audit(array $row, EmployeeIdentity $actor, string $outcome, string $requestId, ?string $revision = null): bool
    {
        try {
            $this->pdo->prepare('INSERT INTO order_finance_reads (read_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, outcome, finance_revision, request_id, read_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Uuid::v4(), (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], $actor->employeeUuid, ConfirmareFinance::ROLE_FINANCE,
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
                o.cancellation_state, o.workflow_cohort_reason, c.status, c.assigned_employee_uuid, c.decided_by_employee_uuid
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
