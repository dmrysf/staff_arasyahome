<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Cancellation\Sync\CancellationSyncService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Integration\SourceAccess\SourceInvoice;
use Arasya\Operations\Integration\SourceOrderPayloadMapper;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * The Confirmare operating cycle of a cash-on-delivery order (owner master decisions 2026-10-25, API 2.46.0, migration
 * 043): the telephone attempts, the advance proforma sent after three unanswered calls, its reminders, the bank check
 * and the cancellation of a proforma nobody paid.
 *
 * Telephone (order_confirmation_contacts, append-only since 2.39.0):
 * - at most three `no_answer` attempts count in one cycle; a fourth is refused (CONFIRMARE_CALL_CYCLE_COMPLETE). The
 *   cycle restarts after a supervisor's reopening or an applied reactivation (`callBoundary`);
 * - each `no_answer` is one request for one real call: the operator picks the outcome and confirms the call happened
 *   for every call, and a repeated request (same idempotency key) returns the stored call instead of a second one. There
 *   is no waiting time between two calls (owner decision 2026-10-25, the earlier 10-minute gap is removed);
 * - the third `no_answer` of a cycle starts a proforma cycle (`afterNoAnswer`): the operator's own request issues the
 *   30 % advance proforma in SmartBill (the shop marks it `unconfirmed`: the customer did not confirm the order) and sends
 *   it by e-mail. A proforma is never a confirmation: production still needs the confirmed call and the money
 *   (ConfirmationClearance), whatever was paid.
 *
 * Proforma timer (confirmare_proforma_cycles): it starts when the proforma was really sent (the shop's answer `sent`),
 * whether the worker or the operator sent it. 24 h: first reminder; 72 h: second; 120 h: expiry. At expiry the proforma
 * is cancelled only when, at that moment, no money was received or is being verified (the shop checks it again under its
 * finance lock) AND a bank check of the shop covers the expiry instant (confirmare_bank_checks); otherwise the cycle
 * waits (`waiting_finance`, `waiting_bank_check`). After SmartBill confirmed the cancellation, the order's cancellation
 * in the shop is requested through the cancellation sync (CancellationSyncService::requestForExpiredProforma). An
 * unknown SmartBill outcome is never sent again: a manager resolves it in Staff and the cycle reads the result.
 * Proformas sent before Arasya knew them (the 22 October backfill) have no cycle: no timer, no mass cancellation.
 *
 * Exactly once: the worker claims a cycle with a conditional UPDATE (one worker wins, a stale claim expires after
 * CLAIM_SECONDS); each step sent to the shop has a deterministic idempotency key (one row per cycle and step), and a
 * step whose answer was lost is asked from the shop's stored answer before anything is sent again.
 */
final class ConfirmareCycles
{
    public const NO_ANSWER_PER_CYCLE = 3;
    public const REMINDER_1_HOURS = 24;
    public const REMINDER_2_HOURS = 72;
    public const EXPIRY_HOURS = 120;
    public const CLAIM_SECONDS = 300;
    /**
     * R3 (2026-10-26): a step SmartBill rate-limited (429) is tried again at most this many times, each after a fixed wait
     * that is never shorter than SmartBill's Retry-After: a Retry-After longer than the wait is not retried automatically
     * (the cycle closes as rate-limited; its operator may start it again). The wait is not stored (migration 043 keeps
     * `last_error` a plain code): it runs from the cycle's `updated_at`.
     */
    public const RATE_LIMIT_RETRIES = 3;
    public const RATE_LIMIT_WAIT_SECONDS = 300;
    /** R1 (2026-10-26): cycles one set of three unanswered calls may start (the first one and its retries). */
    public const CYCLES_PER_CALL_SET = 3;
    /**
     * R1/R2 (2026-10-26): why a closed cycle may be started again by its operator or a supervisor once the cause is fixed. Every
     * one is a DEFINITE answer (SmartBill or the shop refused before anything was made, or the e-mail was refused): the retry
     * reads the shop's proforma first, re-sends an existing proforma instead of issuing another, and stops on any unknown
     * outcome. Paid, cancelled, expired, replaced, missing or unknown cycles are never retried.
     */
    public const RETRYABLE_CLOSED = ['smartbill_refused', 'smartbill_precheck', 'smartbill_rate_limited', 'mail_refused', 'mail_rate_limited',
        'proforma_bank_missing', 'proforma_series_missing', 'proforma_series_not_approved', 'smartbill_company_not_approved', 'billing_email_invalid'];
    public const TABLES = ['confirmare_proforma_cycles', 'confirmare_cycle_steps', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations'];
    public const OPEN_STATES = ['issue_pending', 'mail_pending', 'running', 'waiting_bank_check', 'waiting_finance', 'cancel_pending', 'cancel_unknown', 'order_cancel_pending'];
    /** Shop refusals that mean money exists or is being checked: the cycle waits for finance, never cancels. */
    private const MONEY_CODES = ['advance_payment_received', 'payment_verifying', 'finance_attention', 'finance_unavailable', 'order_busy'];
    private const CLOSED_COMMERCE = ['cancelled', 'refunded', 'failed', 'trash'];
    private const STEPS = ['issue' => 'proforma_issue', 'mail' => 'proforma_send_mail', 'remind_1' => 'proforma_remind', 'remind_2' => 'proforma_remind', 'expire_cancel' => 'proforma_cancel'];

    private ?bool $installed = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly SourceAccessRegistry $registry,
        private readonly SourceAccessClient $client,
        private readonly ConfirmareScopePolicy $confirmare,
        private readonly FinanceScopePolicy $finance,
        private readonly InvoiceScopePolicy $invoices,
        private readonly ?CancellationSyncService $cancellations = null,
    ) {
    }

    public function installed(): bool
    {
        if ($this->installed === null) {
            try {
                $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('" . implode("', '", self::TABLES) . "')");
                $this->installed = $statement !== false && (int) $statement->fetchColumn() === count(self::TABLES);
            } catch (PDOException) {
                $this->installed = false;
            }
        }
        return $this->installed;
    }

    // ---- Telephone attempts ---------------------------------------------------------------------------------------------

    /**
     * The instant after which unanswered calls count for the current cycle: the last supervisor reopening or the last
     * applied reactivation (never earlier attempts).
     */
    public static function callBoundary(PDO $pdo, string $orderUuid): ?string
    {
        $statement = $pdo->prepare("SELECT MAX(occurred_at) FROM order_confirmation_events WHERE order_uuid = ? AND action = 'reopened'");
        $statement->execute([$orderUuid]);
        $boundary = $statement->fetchColumn();
        $boundary = is_string($boundary) ? $boundary : null;
        try {
            $statement = $pdo->prepare("SELECT MAX(completed_at) FROM confirmare_reactivations WHERE order_uuid = ? AND state = 'applied'");
            $statement->execute([$orderUuid]);
            $reactivated = $statement->fetchColumn();
            if (is_string($reactivated) && ($boundary === null || $reactivated > $boundary)) {
                $boundary = $reactivated;
            }
        } catch (PDOException) {
            // Migration 043 not applied yet: only the reopening counts.
        }
        return $boundary;
    }

    /** @return array{noAnswer: int, max: int, complete: bool, lastNoAnswerAt: ?string, boundary: ?string} */
    public static function callState(PDO $pdo, string $orderUuid): array
    {
        $boundary = self::callBoundary($pdo, $orderUuid);
        $statement = $pdo->prepare("SELECT COUNT(*) AS n, MAX(recorded_at) AS last FROM order_confirmation_contacts WHERE order_uuid = ? AND outcome = 'no_answer'" . ($boundary === null ? '' : ' AND recorded_at > ?'));
        $statement->execute($boundary === null ? [$orderUuid] : [$orderUuid, $boundary]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'last' => null];
        $count = (int) $row['n'];
        return ['noAnswer' => $count, 'max' => self::NO_ANSWER_PER_CYCLE, 'complete' => $count >= self::NO_ANSWER_PER_CYCLE, 'lastNoAnswerAt' => is_string($row['last']) ? $row['last'] : null, 'boundary' => $boundary];
    }

    /** Called by ConfirmareService::contact under the order row lock before an unanswered call is recorded. */
    public static function assertNoAnswerAllowed(PDO $pdo, string $orderUuid): void
    {
        $state = self::callState($pdo, $orderUuid);
        if ($state['complete']) {
            throw new ApiException(409, 'CONFIRMARE_CALL_CYCLE_COMPLETE', 'Trei apeluri fără răspuns sunt deja înregistrate în acest ciclu.', ['noAnswer' => $state['noAnswer'], 'max' => self::NO_ANSWER_PER_CYCLE]);
        }
    }

    /**
     * After the third unanswered call of a cycle: start the advance proforma of an eligible cash-on-delivery order and run
     * its first steps now (issue, e-mail) as the operator who recorded the call. Never throws for a business reason: the
     * call stays recorded and the answer says what happened.
     *
     * @return array<string, mixed>
     */
    public function afterNoAnswer(EmployeeIdentity $actor, string $orderUuid, string $requestId): array
    {
        if (!$this->installed()) {
            return ['state' => 'not_started', 'reason' => 'schema_missing'];
        }
        if (!self::callState($this->pdo, $orderUuid)['complete']) {
            return ['state' => 'not_started', 'reason' => 'calls_remaining'];
        }
        $this->pdo->beginTransaction();
        try {
            $order = $this->lockOrder($orderUuid);
            $reason = $order === null ? 'order_unavailable' : $this->noAnswerBlocker($actor, $order);
            if ($reason !== null) {
                $this->pdo->rollBack();
                return ['state' => 'not_started', 'reason' => $reason];
            }
            $cycle = $this->createCycle($order, 'no_answer', $actor->employeeUuid, 'issue_pending', null, null);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollBack();
            if ($error instanceof PDOException) {
                // Another request created the cycle first (unique active cycle): its state is answered.
                $active = $this->activeCycle($orderUuid);
                return $active === null ? ['state' => 'not_started', 'reason' => 'storage_unavailable'] : $this->present($active);
            }
            throw $error;
        }
        return $this->present($this->advance($cycle['cycle_uuid'], $requestId) ?? $cycle);
    }

    /** @param array<string, mixed> $order @return string|null why no proforma cycle starts */
    private function noAnswerBlocker(EmployeeIdentity $actor, array $order): ?string
    {
        if ($order['workflow_cohort'] !== WorkflowCohort::CONFIRMARE || $order['status'] !== 'pending') {
            return 'confirmare_not_pending';
        }
        if ($order['payment_method'] !== 'cod') {
            return 'advance_not_required';
        }
        if ($order['cancellation_state'] === 'held' || $order['operational_status'] === 'unavailable' || in_array((string) $order['source_commerce_status_code'], self::CLOSED_COMMERCE, true)) {
            return 'order_closed';
        }
        if ($order['source_financial_state'] === 'cleared') {
            return 'advance_already_collected';
        }
        $target = $this->registry->find((string) $order['source_key']);
        if ($target === null || !$target->allowsInvoiceActions() || !$this->invoices->installed()) {
            return 'invoice_disabled';
        }
        if (!$this->operates($actor, $order)) {
            return 'not_allowed_for_role';
        }
        if ($this->activeCycle((string) $order['order_uuid']) !== null) {
            return 'cycle_exists';
        }
        if (ConfirmareInvoice::actionOpen($this->pdo, (string) $order['order_uuid'])) {
            return 'invoice_action_open';
        }
        return null;
    }

    /**
     * The operator sent the proforma through Staff (ConfirmareInvoice, result `sent`): its timer starts now. The same
     * proforma sent again keeps its timer; another proforma closes the previous cycle (`proforma_replaced`).
     *
     * @param array<string, mixed> $summary the shop's invoice summary after the action
     */
    public function proformaSent(string $orderUuid, string $employeeUuid, array $summary): void
    {
        if (!$this->installed()) {
            return;
        }
        $document = $summary['proforma']['document'] ?? null;
        $hash = is_array($document) ? self::documentHash($document) : null;
        if ($hash === null) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $order = $this->lockOrder($orderUuid);
            if ($order === null) {
                $this->pdo->rollBack();
                return;
            }
            $active = $this->activeCycle($orderUuid, true);
            $now = $this->now();
            if ($active !== null && $active['document_hash'] === $hash) {
                if ($active['sent_at'] === null) {
                    $this->update($active, ['state' => 'running', 'last_error' => null] + $this->timer($now));
                }
                $this->pdo->commit();
                return;
            }
            if ($active !== null) {
                if (in_array($active['state'], ['cancel_pending', 'cancel_unknown', 'order_cancel_pending'], true)) {
                    // The expiry of the previous proforma is in progress: it finishes first (never two at once).
                    $this->pdo->commit();
                    return;
                }
                $this->close($active, 'closed', 'proforma_replaced');
            }
            $this->createCycle($order, 'operator', $employeeUuid, 'running', $hash, $now);
            $this->pdo->commit();
        } catch (Throwable) {
            $this->rollBack();
        }
    }

    // ---- Bank checks ----------------------------------------------------------------------------------------------------

    /**
     * A finance employee records that the bank statement of the shop's account was checked up to `coversUntil` (default:
     * now). Expired proformas of the shop whose expiry the check covers can then be cancelled.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordBankCheck(EmployeeIdentity $actor, mixed $source, array $input, string $key, string $requestId): array
    {
        $this->requireInstalled();
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D', $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (!is_string($source) || !in_array($source, ['trendhome', 'outletperdele'], true)) {
            throw new ApiException(400, 'UNKNOWN_SOURCE', 'source must be trendhome or outletperdele.');
        }
        if (array_diff(array_keys($input), ['source', 'coversUntil']) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only source and coversUntil are accepted.');
        }
        if (!$this->financeRecorder($actor, $source)) {
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'Only a finance employee of this shop records the bank check.');
        }
        // API 2.46.1: a bank check is a finance declaration of the shop. While finance is switched off for the shop (no
        // source access, or finance not `record`) it is refused before anything is written, and nobody is offered it.
        if (!$this->financeRecordEnabled($source)) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance actions are not enabled for this shop.');
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $covers = $now;
        if (isset($input['coversUntil'])) {
            try {
                $covers = is_string($input['coversUntil']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $input['coversUntil']) === 1
                    ? (new DateTimeImmutable($input['coversUntil']))->setTimezone(new DateTimeZone('UTC')) : throw new InvalidArgumentException();
            } catch (Throwable) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'coversUntil must be an ISO 8601 instant.', ['field' => 'coversUntil']);
            }
            if ($covers > $now || $covers < $now->modify('-7 days')) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'coversUntil must be in the last 7 days and not in the future.', ['field' => 'coversUntil']);
            }
        }
        $replay = $this->pdo->prepare('SELECT * FROM confirmare_bank_checks WHERE employee_uuid = ? AND idempotency_key = ?');
        $replay->execute([$actor->employeeUuid, $key]);
        $previous = $replay->fetch(PDO::FETCH_ASSOC);
        if (is_array($previous)) {
            if ($previous['source_key'] !== $source) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return ['check' => $this->presentCheck($previous)];
        }
        $uuid = Uuid::v4();
        $this->pdo->prepare('INSERT INTO confirmare_bank_checks (check_uuid, source_key, employee_uuid, covers_until, recorded_at, idempotency_key, request_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uuid, $source, $actor->employeeUuid, $covers->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), $key, self::requestId($requestId)]);
        $row = $this->pdo->prepare('SELECT * FROM confirmare_bank_checks WHERE check_uuid = ?');
        $row->execute([$uuid]);
        return ['check' => $this->presentCheck($row->fetch(PDO::FETCH_ASSOC) ?: [])];
    }

    /** @return array<string, mixed> the latest bank checks and the proformas waiting for one, of the actor's shops */
    public function bankChecks(EmployeeIdentity $actor, ?string $source): array
    {
        $this->requireInstalled();
        $sources = array_values(array_filter(['trendhome', 'outletperdele'], fn (string $s): bool => ($source === null || $source === $s) && ($this->financeViewer($actor, $s) || $this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $s))));
        if ($sources === []) {
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You see no bank check of this shop.');
        }
        $marks = implode(', ', array_fill(0, count($sources), '?'));
        $checks = $this->pdo->prepare("SELECT b.*, e.display_name FROM confirmare_bank_checks b INNER JOIN employees e ON e.employee_uuid = b.employee_uuid WHERE b.source_key IN ({$marks}) ORDER BY b.recorded_at DESC LIMIT 30");
        $checks->execute($sources);
        $waiting = $this->pdo->prepare("SELECT source_key, COUNT(*) FROM confirmare_proforma_cycles WHERE state = 'waiting_bank_check' AND source_key IN ({$marks}) GROUP BY source_key");
        $waiting->execute($sources);
        return [
            'items' => array_map(fn (array $row): array => $this->presentCheck($row), $checks->fetchAll(PDO::FETCH_ASSOC)),
            'waiting' => array_map('intval', $waiting->fetchAll(PDO::FETCH_KEY_PAIR)),
            'sources' => $sources,
            'canRecord' => array_values(array_filter($sources, fn (string $s): bool => $this->financeRecorder($actor, $s) && $this->financeRecordEnabled($s))),
        ];
    }

    // ---- Worker ---------------------------------------------------------------------------------------------------------

    /** @return array{seen: int, advanced: int, skipped: int} one pass over the open cycles (bin/confirmare-cycles.php) */
    public function run(int $limit = 100, string $requestId = 'confirmare-cycles'): array
    {
        if (!$this->installed()) {
            return ['seen' => 0, 'advanced' => 0, 'skipped' => 0];
        }
        $statement = $this->pdo->prepare('SELECT cycle_uuid FROM confirmare_proforma_cycles WHERE active_order_uuid IS NOT NULL ORDER BY updated_at, cycle_uuid LIMIT ' . max(1, min(500, $limit)));
        $statement->execute();
        $seen = $advanced = $skipped = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
            $seen++;
            $result = $this->advance((string) $uuid, $requestId);
            $result === null ? $skipped++ : $advanced++;
        }
        return ['seen' => $seen, 'advanced' => $advanced, 'skipped' => $skipped];
    }

    /**
     * Claims the cycle (one worker wins) and moves it as far as the shop's answers allow now.
     *
     * @return array<string, mixed>|null the cycle after the pass, null when another worker holds it
     */
    public function advance(string $cycleUuid, string $requestId): ?array
    {
        $token = Uuid::v4();
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $claim = $this->pdo->prepare('UPDATE confirmare_proforma_cycles SET claim_token = ?, claimed_at = ? WHERE cycle_uuid = ? AND active_order_uuid IS NOT NULL AND (claim_token IS NULL OR claimed_at < ?)');
        $claim->execute([$token, $now->format('Y-m-d H:i:s.u'), $cycleUuid, $now->modify('-' . self::CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s.u')]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        try {
            for ($pass = 0; $pass < 6; $pass++) {
                $cycle = $this->cycle($cycleUuid);
                if ($cycle === null || $cycle['active_order_uuid'] === null || $cycle['claim_token'] !== $token) {
                    break;
                }
                if (!$this->step($cycle, $requestId)) {
                    break;
                }
            }
        } catch (Throwable $error) {
            $cycle = $this->cycle($cycleUuid);
            if ($cycle !== null && $cycle['active_order_uuid'] !== null) {
                $this->update($cycle, ['last_error' => 'internal_error']);
            }
            error_log('Arasya Confirmare cycle ' . $cycleUuid . ': ' . get_class($error));
        } finally {
            $this->pdo->prepare('UPDATE confirmare_proforma_cycles SET claim_token = NULL, claimed_at = NULL WHERE cycle_uuid = ? AND claim_token = ?')->execute([$cycleUuid, $token]);
        }
        return $this->cycle($cycleUuid);
    }

    /** One state move. @param array<string, mixed> $cycle @return bool whether another move may follow at once */
    private function step(array $cycle, string $requestId): bool
    {
        $row = $this->orderRow((string) $cycle['order_uuid']);
        $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
        if ($row === null || $target === null || !$target->allowsInvoiceActions()) {
            $this->update($cycle, ['last_error' => 'invoice_disabled', 'attempts' => (int) $cycle['attempts'] + 1]);
            return false;
        }
        // R3 (2026-10-26): SmartBill asked to wait (429): nothing is sent to it before the wait is over.
        if ($cycle['last_error'] === 'rate_limited'
            && (new DateTimeImmutable((string) $cycle['updated_at'], new DateTimeZone('UTC')))->modify('+' . self::RATE_LIMIT_WAIT_SECONDS . ' seconds') > $this->clock->now()) {
            return false;
        }
        if ($cycle['state'] === 'order_cancel_pending') {
            return $this->requestOrderCancel($cycle, $requestId);
        }
        $summary = $this->summary($row, $target, $cycle, $requestId);
        if ($summary === null) {
            $this->update($cycle, ['last_error' => 'shop_unreachable', 'attempts' => (int) $cycle['attempts'] + 1]);
            return false;
        }
        $proforma = $summary['proforma'];
        if ($proforma === null) {
            $this->close($cycle, 'closed', 'proforma_unsupported');
            return false;
        }
        $state = (string) $cycle['state'];
        $document = $proforma['document'];
        $hash = is_array($document) ? self::documentHash($document) : null;
        if ($state === 'issue_pending') {
            if ($hash !== null && $document['outdated'] !== true) {
                $this->update($cycle, ['document_hash' => $hash, 'state' => 'mail_pending', 'last_error' => null]);
                return true;
            }
            if (in_array($proforma['state'], ['in_flight', 'unknown'], true)) {
                $this->update($cycle, ['last_error' => 'issue_unknown']);
                return false;
            }
            return $this->sendStep($cycle, $row, $target, 'issue', ['replaceOutdated' => $hash !== null, 'unconfirmed' => $cycle['origin'] === 'no_answer'], (string) $summary['revision'], $requestId);
        }
        if ($state === 'mail_pending') {
            if ($hash === null || $hash !== $cycle['document_hash']) {
                $this->close($cycle, 'closed', $hash === null ? 'proforma_missing' : 'proforma_replaced');
                return false;
            }
            if ($proforma['mail']['state'] === 'sent') {
                $this->update($cycle, ['state' => 'running', 'last_error' => null] + $this->timer($this->now()));
                return true;
            }
            if (in_array($proforma['mail']['state'], ['in_flight', 'unknown'], true)) {
                $this->update($cycle, ['last_error' => 'mail_unknown']);
                return false;
            }
            return $this->sendStep($cycle, $row, $target, 'mail', ['confirmResend' => false], (string) $summary['revision'], $requestId);
        }
        if ($state === 'cancel_unknown') {
            $cancelledOurs = $hash === null && self::cancelledHas($proforma, (string) $cycle['document_hash']);
            if ($proforma['cancel'] === null && $cancelledOurs) {
                $this->update($cycle, ['state' => 'order_cancel_pending', 'last_error' => null]);
                return true;
            }
            if ($proforma['cancel'] === null) {
                // A manager found the proforma not cancelled in SmartBill: a person decides (never a second automatic try).
                $this->close($cycle, 'closed', 'cancel_not_confirmed');
            }
            return false;
        }
        // running, waiting_finance, waiting_bank_check, cancel_pending
        if ($hash === null) {
            $this->close($cycle, 'closed', self::cancelledHas($proforma, (string) $cycle['document_hash']) ? 'proforma_cancelled' : 'proforma_missing');
            return false;
        }
        if ($hash !== $cycle['document_hash']) {
            $this->close($cycle, 'closed', 'proforma_replaced');
            return false;
        }
        if (in_array((string) $summary['order']['status'], self::CLOSED_COMMERCE, true)) {
            $this->close($cycle, 'closed', 'order_closed');
            return false;
        }
        $advance = $proforma['advance'];
        if ($summary['financial']['state'] === 'cleared' || $advance['state'] === 'paid' || $advance['accepted'] === true) {
            $this->close($cycle, 'paid', 'advance_received');
            return false;
        }
        if ($advance['verifying'] || $advance['state'] === 'partial' || $summary['financial']['pending'] || in_array($summary['financial']['attention'], SourceOrderPayloadMapper::FINANCIAL_ATTENTION_OPEN, true)) {
            if ($state !== 'waiting_finance') {
                $this->update($cycle, ['state' => 'waiting_finance', 'last_error' => null]);
            }
            return false;
        }
        if ($cycle['sent_at'] === null) {
            $this->update($cycle, ['state' => 'mail_pending']);
            return true;
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $sent = new DateTimeImmutable((string) $cycle['sent_at'], new DateTimeZone('UTC'));
        if ($state === 'cancel_pending') {
            return $this->sendStep($cycle, $row, $target, 'expire_cancel', ['reasonCode' => 'expired_unpaid', 'reasonNote' => '', 'confirmNoPayment' => true], (string) $summary['revision'], $requestId);
        }
        if ($state === 'waiting_finance') {
            $this->update($cycle, ['state' => 'running']);
            return true;
        }
        if ($cycle['reminder2_at'] === null && $now >= $sent->modify('+' . self::REMINDER_2_HOURS . ' hours') && $now < $sent->modify('+' . self::EXPIRY_HOURS . ' hours')) {
            if ($cycle['reminder1_at'] === null) {
                // The first reminder was not sent in time (worker stopped): it is skipped, never sent after the second.
                $this->skipStep($cycle, 'remind_1');
                $this->update($cycle, ['reminder1_at' => $this->now()]);
                $cycle = $this->cycle((string) $cycle['cycle_uuid']) ?? $cycle;
            }
            return $this->sendStep($cycle, $row, $target, 'remind_2', ['stage' => 2], (string) $summary['revision'], $requestId);
        }
        if ($cycle['reminder1_at'] === null && $now >= $sent->modify('+' . self::REMINDER_1_HOURS . ' hours') && $now < $sent->modify('+' . self::REMINDER_2_HOURS . ' hours')) {
            return $this->sendStep($cycle, $row, $target, 'remind_1', ['stage' => 1], (string) $summary['revision'], $requestId);
        }
        $expires = new DateTimeImmutable((string) $cycle['expires_at'], new DateTimeZone('UTC'));
        if ($now < $expires) {
            return false;
        }
        $check = $this->bankCheckCovering((string) $cycle['source_key'], $expires);
        if ($check === null) {
            if ($state !== 'waiting_bank_check') {
                $this->update($cycle, ['state' => 'waiting_bank_check', 'last_error' => null]);
            }
            return false;
        }
        $this->update($cycle, ['state' => 'cancel_pending', 'bank_check_uuid' => $check, 'last_error' => null]);
        return true;
    }

    /**
     * Sends one step to the shop with its deterministic idempotency key. A step whose earlier answer was lost is asked
     * from the shop's stored answer first; a step the shop never received is sent again with the same key.
     *
     * @param array<string, mixed> $cycle
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fields
     */
    private function sendStep(array $cycle, array $row, SourceAccessTarget $target, string $step, array $fields, string $revision, string $requestId): bool
    {
        $action = self::STEPS[$step];
        $existing = $this->stepRow((string) $cycle['cycle_uuid'], $step);
        // A new key for each new attempt (stored before sending): a refusal the shop stored under an earlier key never
        // answers a later attempt, and a lost answer is always asked under the key that was sent.
        $key = $existing === null ? self::stepKey((string) $cycle['cycle_uuid'], $step) . '-' . bin2hex(random_bytes(4)) : (string) $existing['idempotency_key'];
        if ($existing === null) {
            try {
                $this->pdo->prepare('INSERT INTO confirmare_cycle_steps (step_uuid, cycle_uuid, order_uuid, step, idempotency_key, state, attempts, requested_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)')
                    ->execute([Uuid::v4(), $cycle['cycle_uuid'], $cycle['order_uuid'], $step, $key, 'pending', $this->now()]);
            } catch (PDOException) {
                return false;
            }
            $existing = $this->stepRow((string) $cycle['cycle_uuid'], $step);
        } elseif (in_array($existing['state'], ['applied', 'rejected', 'skipped'], true)) {
            return $this->afterStep($cycle, $step, (string) $existing['state'], (string) $existing['outcome'], null);
        } else {
            // Pending or unknown: the shop's stored answer of the key decides whether it already ran.
            try {
                $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_INVOICE_STATUS, $this->payload($row, (string) $cycle['owner_employee_uuid'], $requestId) + ['idempotencyKey' => $key]);
                $status = $answer['status'] === 200 ? SourceInvoice::status($answer['body']) : null;
            } catch (SourceAccessException) {
                $status = null;
            }
            if ($status === null) {
                $this->update($cycle, ['last_error' => 'shop_unreachable', 'attempts' => (int) $cycle['attempts'] + 1]);
                return false;
            }
            if ($status['state'] !== 'absent') {
                if (!isset($status['status'])) {
                    return false;
                }
                return $this->conclude($cycle, $row, $step, $action, ['status' => (int) $status['status'], 'body' => $status['result']]);
            }
        }
        $this->pdo->prepare('UPDATE confirmare_cycle_steps SET attempts = attempts + 1 WHERE cycle_uuid = ? AND step = ?')->execute([$cycle['cycle_uuid'], $step]);
        $body = $this->payload($row, (string) $cycle['owner_employee_uuid'], $requestId) + ['action' => $action, 'expectedRevision' => $revision, 'idempotencyKey' => $key] + $fields;
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_PROFORMA_ACTION, $body);
        } catch (SourceAccessException $e) {
            if (in_array($e->codeName, ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'], true)) {
                $this->finishStep((string) $cycle['cycle_uuid'], $step, 'pending', null);
                $this->update($cycle, ['last_error' => strtolower($e->codeName)]);
                return false;
            }
            $this->finishStep((string) $cycle['cycle_uuid'], $step, 'unknown', null);
            $this->update($cycle, ['last_error' => 'step_unknown']);
            return false;
        }
        return $this->conclude($cycle, $row, $step, $action, $answer);
    }

    /**
     * @param array<string, mixed> $cycle
     * @param array<string, mixed> $row
     * @param array{status: int, body: array<string, mixed>} $answer
     */
    private function conclude(array $cycle, array $row, string $step, string $action, array $answer): bool
    {
        $cycleUuid = (string) $cycle['cycle_uuid'];
        if ($answer['status'] === 200 || $answer['status'] === 202) {
            try {
                $answered = SourceInvoice::answered($answer['body'], $action, (string) $row['source_order_id'], (string) $row['order_number']);
            } catch (SourceAccessException) {
                $this->finishStep($cycleUuid, $step, 'unknown', null);
                $this->update($cycle, ['last_error' => 'answer_unreadable']);
                return false;
            }
            if ($answer['status'] === 202) {
                $this->finishStep($cycleUuid, $step, 'unknown', $answered['result']);
                if ($step === 'expire_cancel') {
                    $this->update($cycle, ['state' => 'cancel_unknown', 'last_error' => 'cancel_unknown']);
                } else {
                    $this->update($cycle, ['last_error' => $step . '_unknown']);
                }
                return false;
            }
            $this->finishStep($cycleUuid, $step, 'applied', $answered['result']);
            if ($step === 'issue') {
                $document = $answered['summary']['proforma']['document'] ?? null;
                $hash = is_array($document) ? self::documentHash($document) : null;
                if ($hash === null) {
                    $this->update($cycle, ['last_error' => 'answer_unreadable']);
                    return false;
                }
                $cycle['document_hash'] = $hash;
            }
            return $this->afterStep($cycle, $step, 'applied', $answered['result'], $answered['summary']);
        }
        if ($answer['status'] >= 400 && $answer['status'] < 500) {
            try {
                $refusal = SourceInvoice::refusal($answer['body']);
            } catch (SourceAccessException) {
                $refusal = null;
            }
            if ($refusal !== null) {
                if (in_array($refusal['code'], ['smartbill_rate_limited', 'mail_rate_limited'], true) && (int) $cycle['attempts'] < self::RATE_LIMIT_RETRIES
                    && (int) ($refusal['retryAfter'] ?? 60) <= self::RATE_LIMIT_WAIT_SECONDS) {
                    // R3 (2026-10-26): SmartBill answered 429 to exactly one call (the shop counts the calls; more than one or no
                    // answer is `unknown`, never this code): nothing was made or sent. The same step is asked again under a
                    // new key after the wait, at most RATE_LIMIT_RETRIES times; then the cycle closes as rate-limited and
                    // its operator may start it again (retryForActor).
                    $this->forgetStep($cycleUuid, $step);
                    $this->update($cycle, ['last_error' => 'rate_limited', 'attempts' => (int) $cycle['attempts'] + 1]);
                    return false;
                }
                if (in_array($refusal['code'], ['revision_changed', 'order_busy', 'operation_in_progress'], true)) {
                    // Nothing ran: the step is tried again on the next pass, under a new key.
                    $this->forgetStep($cycleUuid, $step);
                    $this->update($cycle, ['last_error' => $refusal['code']]);
                    return false;
                }
                $this->finishStep($cycleUuid, $step, 'rejected', $refusal['code']);
                return $this->afterStep($cycle, $step, 'rejected', $refusal['code'], null);
            }
        }
        $this->finishStep($cycleUuid, $step, 'unknown', null);
        $this->update($cycle, ['last_error' => 'step_unknown']);
        return false;
    }

    /** @param array<string, mixed> $cycle @param array<string, mixed>|null $summary */
    private function afterStep(array $cycle, string $step, string $state, string $outcome, ?array $summary): bool
    {
        $now = $this->now();
        if ($step === 'issue') {
            if ($state === 'applied' && $outcome === 'issued') {
                $this->update($cycle, ['state' => 'mail_pending', 'document_hash' => $cycle['document_hash'], 'last_error' => null]);
                return true;
            }
            $this->close($cycle, 'closed', $state === 'rejected' ? $outcome : 'issue_' . $outcome);
            return false;
        }
        if ($step === 'mail') {
            if ($state === 'applied' && $outcome === 'sent') {
                $this->update($cycle, ['state' => 'running', 'last_error' => null] + $this->timer($now));
                return false;
            }
            $this->close($cycle, 'closed', $state === 'rejected' ? $outcome : 'mail_' . $outcome);
            return false;
        }
        if ($step === 'remind_1' || $step === 'remind_2') {
            // A refused reminder (no e-mail address, SmartBill refused) is recorded and never sent again.
            $this->update($cycle, [($step === 'remind_1' ? 'reminder1_at' : 'reminder2_at') => $now, 'last_error' => $state === 'rejected' ? $outcome : null]);
            return true;
        }
        // expire_cancel
        if ($state === 'applied' && $outcome === 'cancelled') {
            $this->update($cycle, ['state' => 'order_cancel_pending', 'last_error' => null]);
            return true;
        }
        if ($state === 'rejected' && in_array($outcome, self::MONEY_CODES, true)) {
            $this->update($cycle, ['state' => 'waiting_finance', 'last_error' => $outcome]);
            // Money appeared: a later expiry check sends a new cancellation (new key) only if it disappears again.
            $this->forgetStep((string) $cycle['cycle_uuid'], 'expire_cancel');
            return false;
        }
        $this->close($cycle, 'closed', $state === 'rejected' ? $outcome : 'cancel_' . $outcome);
        return false;
    }

    /** @param array<string, mixed> $cycle */
    private function requestOrderCancel(array $cycle, string $requestId): bool
    {
        if ($this->cancellations === null) {
            $this->update($cycle, ['last_error' => 'cancellation_unavailable']);
            return false;
        }
        try {
            $result = $this->cancellations->requestForExpiredProforma((string) $cycle['order_uuid'], (string) $cycle['owner_employee_uuid'], self::requestId($requestId), self::stepKey((string) $cycle['cycle_uuid'], 'order_cancel'));
        } catch (Throwable) {
            $this->update($cycle, ['last_error' => 'cancellation_failed', 'attempts' => (int) $cycle['attempts'] + 1]);
            return false;
        }
        if (in_array($result['state'], ['requested', 'source_cancelled'], true)) {
            $this->recordLocalStep($cycle, 'order_cancel', 'applied', $result['state']);
            $this->close($cycle, 'cancelled', 'proforma_expired');
            return false;
        }
        $this->update($cycle, ['last_error' => $result['state'], 'attempts' => (int) $cycle['attempts'] + 1]);
        return false;
    }

    // ---- Views ----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the call and proforma cycle of one order (Confirmare detail) */
    public function view(string $orderUuid): array
    {
        $calls = self::callState($this->pdo, $orderUuid);
        $out = ['calls' => ['noAnswer' => $calls['noAnswer'], 'max' => $calls['max'], 'complete' => $calls['complete'], 'since' => $calls['boundary'] === null ? null : self::iso($calls['boundary'])], 'proforma' => null, 'history' => []];
        if (!$this->installed()) {
            return $out;
        }
        $active = $this->activeCycle($orderUuid);
        $out['proforma'] = $active === null ? null : $this->present($active);
        $statement = $this->pdo->prepare('SELECT * FROM confirmare_proforma_cycles WHERE order_uuid = ? ORDER BY cycle_number DESC LIMIT 10');
        $statement->execute([$orderUuid]);
        $out['history'] = array_map(fn (array $row): array => $this->present($row), $statement->fetchAll(PDO::FETCH_ASSOC));
        return $out;
    }

    /** @return array<string, mixed> the order's cycle for the Confirmare detail (the caller already checked the access) */
    public function viewForActor(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $order = $this->orderByGlobal($this->globalId($globalOrderId));
        return $order === null ? ['calls' => null, 'proforma' => null, 'history' => []] : $this->view((string) $order['order_uuid']);
    }

    /** @return array<string, mixed> the call answer's `proformaCycle` */
    public function afterNoAnswerFor(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $order = $this->orderByGlobal($this->globalId($globalOrderId));
        return $order === null ? ['state' => 'not_started', 'reason' => 'order_unavailable'] : $this->afterNoAnswer($actor, (string) $order['order_uuid'], $requestId);
    }

    /** @return array<string, mixed> the order's cycle after one pass, run now by its operator or a supervisor */
    public function advanceForActor(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $this->requireInstalled();
        $order = $this->orderByGlobal($this->globalId($globalOrderId));
        if ($order === null || !$this->confirmare->sees($actor, (string) $order['source_key'])) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        if (!$this->operates($actor, $order)) {
            throw new ApiException(403, 'CONFIRMARE_DENIED', 'Only the operator of this order or a supervisor runs its proforma cycle.');
        }
        $active = $this->activeCycle((string) $order['order_uuid']);
        if ($active === null) {
            throw new ApiException(409, 'CONFIRMARE_CYCLE_NONE', 'This order has no open proforma cycle.');
        }
        $after = $this->advance((string) $active['cycle_uuid'], $requestId);
        if ($after === null) {
            throw new ApiException(409, 'CONFIRMARE_CYCLE_BUSY', 'The proforma cycle is being processed right now. Reload in a moment.');
        }
        return ['proformaCycle' => $this->present($after)];
    }

    /**
     * R1/R2 (2026-10-26): starts the advance proforma again after a cycle of the current call set closed with a DEFINITE refusal
     * (RETRYABLE_CLOSED), once the cause was fixed: no new telephone call is recorded and no fourth call is needed. Its
     * operator or a supervisor; the order must still qualify exactly as after the third call (noAnswerBlocker). The new
     * cycle starts at `issue_pending`, whose first move reads the shop's proforma: an existing proforma is e-mailed again
     * (never a second one), an unknown or in-flight one stops the cycle (issue_unknown). At most CYCLES_PER_CALL_SET cycles
     * per call set. The new cycle row is the audit record: origin `no_answer` (the same three calls; migration 043 allows
     * no other origin), a higher cycle number in the same call set, owner = who started it again, created_at.
     *
     * @return array<string, mixed>
     */
    public function retryForActor(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $this->requireInstalled();
        $found = $this->orderByGlobal($this->globalId($globalOrderId));
        if ($found === null || !$this->confirmare->sees($actor, (string) $found['source_key'])) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        if (!$this->operates($actor, $found)) {
            throw new ApiException(403, 'CONFIRMARE_DENIED', 'Only the operator of this order or a supervisor runs its proforma cycle.');
        }
        $orderUuid = (string) $found['order_uuid'];
        $refuse = static fn (string $reason): ApiException => new ApiException(409, 'CONFIRMARE_CYCLE_RETRY_REFUSED', 'The advance proforma cannot be started again now: ' . $reason . '.', ['reason' => $reason]);
        $this->pdo->beginTransaction();
        try {
            $order = $this->lockOrder($orderUuid);
            $reason = $order === null ? 'order_unavailable' : $this->noAnswerBlocker($actor, $order);
            if ($reason !== null) {
                throw $refuse($reason);
            }
            if (!self::callState($this->pdo, $orderUuid)['complete']) {
                throw $refuse('calls_remaining');
            }
            $boundary = self::callBoundary($this->pdo, $orderUuid);
            $statement = $this->pdo->prepare('SELECT state, closed_reason FROM confirmare_proforma_cycles WHERE order_uuid = ?' . ($boundary === null ? '' : ' AND created_at > ?') . ' ORDER BY cycle_number DESC');
            $statement->execute($boundary === null ? [$orderUuid] : [$orderUuid, $boundary]);
            $cycles = $statement->fetchAll(PDO::FETCH_ASSOC);
            $last = $cycles[0] ?? null;
            if ($last === null || $last['state'] !== 'closed' || !in_array((string) $last['closed_reason'], self::RETRYABLE_CLOSED, true)) {
                throw $refuse('nothing_to_retry');
            }
            if (count($cycles) >= self::CYCLES_PER_CALL_SET) {
                throw $refuse('retry_limit');
            }
            $cycle = $this->createCycle($order, 'no_answer', $actor->employeeUuid, 'issue_pending', null, null);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollBack();
            if ($error instanceof PDOException) {
                // Another request started a cycle first (unique active cycle): nothing is started twice.
                throw $refuse('cycle_exists');
            }
            throw $error;
        }
        return ['proformaCycle' => $this->present($this->advance((string) $cycle['cycle_uuid'], $requestId) ?? $cycle)];
    }

    /** @return array<string, mixed>|null */
    private function orderByGlobal(string $globalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.source_key, c.assigned_employee_uuid, c.decided_by_employee_uuid FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> the open cycles of the actor's shops that need a person (operators, supervisors, finance) */
    public function attention(EmployeeIdentity $actor, ?string $source): array
    {
        $this->requireInstalled();
        $sources = array_values(array_filter(['trendhome', 'outletperdele'], fn (string $s): bool => ($source === null || $source === $s)
            // API 2.50.0: the list of a shop's proforma cycles names its orders: Confirmare scopes only, never finance.
            && $this->confirmare->sees($actor, $s)));
        if ($sources === []) {
            return ['items' => [], 'sources' => []];
        }
        $marks = implode(', ', array_fill(0, count($sources), '?'));
        $statement = $this->pdo->prepare("SELECT c.*, o.order_number FROM confirmare_proforma_cycles c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
            WHERE c.active_order_uuid IS NOT NULL AND c.source_key IN ({$marks}) ORDER BY c.expires_at IS NULL, c.expires_at, c.created_at LIMIT 300");
        $statement->execute($sources);
        return ['items' => array_map(fn (array $row): array => ['orderNumber' => (string) $row['order_number'], 'globalOrderId' => (string) $row['global_order_id']] + $this->present($row), $statement->fetchAll(PDO::FETCH_ASSOC)), 'sources' => $sources];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $iso = static fn (mixed $value): ?string => is_string($value) ? self::iso($value) : null;
        $sent = is_string($row['sent_at'] ?? null) ? new DateTimeImmutable((string) $row['sent_at'], new DateTimeZone('UTC')) : null;
        return [
            'cycle' => (int) ($row['cycle_number'] ?? 0),
            'state' => (string) ($row['state'] ?? 'unknown'),
            'origin' => (string) ($row['origin'] ?? ''),
            'sentAt' => $iso($row['sent_at'] ?? null),
            'reminder1DueAt' => $sent === null ? null : self::iso($sent->modify('+' . self::REMINDER_1_HOURS . ' hours')->format('Y-m-d H:i:s.u')),
            'reminder1At' => $iso($row['reminder1_at'] ?? null),
            'reminder2DueAt' => $sent === null ? null : self::iso($sent->modify('+' . self::REMINDER_2_HOURS . ' hours')->format('Y-m-d H:i:s.u')),
            'reminder2At' => $iso($row['reminder2_at'] ?? null),
            'expiresAt' => $iso($row['expires_at'] ?? null),
            'bankCheck' => ($row['bank_check_uuid'] ?? null) !== null,
            'closedReason' => $row['closed_reason'] ?? null,
            'lastError' => $row['last_error'] ?? null,
            'closedAt' => $iso($row['closed_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentCheck(array $row): array
    {
        return ['source' => (string) ($row['source_key'] ?? ''), 'coversUntil' => self::iso((string) ($row['covers_until'] ?? '1970-01-01 00:00:00')), 'recordedAt' => self::iso((string) ($row['recorded_at'] ?? '1970-01-01 00:00:00')),
            'by' => isset($row['display_name']) ? (string) $row['display_name'] : null];
    }

    // ---- Storage --------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function createCycle(array $order, string $origin, string $owner, string $state, ?string $hash, ?string $sentAt): array
    {
        $number = $this->pdo->prepare('SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM confirmare_proforma_cycles WHERE order_uuid = ?');
        $number->execute([$order['order_uuid']]);
        $uuid = Uuid::v4();
        $now = $this->now();
        $timer = $sentAt === null ? ['sent_at' => null, 'expires_at' => null] : $this->timer($sentAt);
        $this->pdo->prepare('INSERT INTO confirmare_proforma_cycles (cycle_uuid, order_uuid, active_order_uuid, global_order_id, source_key, cycle_number, origin, owner_employee_uuid, document_hash, state,
                sent_at, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $uuid, $order['order_uuid'], $order['order_uuid'], $order['global_order_id'], $order['source_key'], (int) $number->fetchColumn(), $origin, $owner, $hash, $state,
            $timer['sent_at'], $timer['expires_at'], $now, $now,
        ]);
        return $this->cycle($uuid) ?? throw new \RuntimeException('Cycle was not created.');
    }

    /** @return array{sent_at: string, expires_at: string} */
    private function timer(string $sentAt): array
    {
        $sent = new DateTimeImmutable($sentAt, new DateTimeZone('UTC'));
        return ['sent_at' => $sent->format('Y-m-d H:i:s.u'), 'expires_at' => $sent->modify('+' . self::EXPIRY_HOURS . ' hours')->format('Y-m-d H:i:s.u')];
    }

    /** @param array<string, mixed> $cycle @param array<string, mixed> $changes */
    private function update(array $cycle, array $changes): void
    {
        $changes['updated_at'] = $this->now();
        $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($changes)));
        $this->pdo->prepare("UPDATE confirmare_proforma_cycles SET {$sets}, version = version + 1 WHERE cycle_uuid = ? AND active_order_uuid IS NOT NULL")
            ->execute([...array_values($changes), $cycle['cycle_uuid']]);
    }

    /** @param array<string, mixed> $cycle */
    private function close(array $cycle, string $state, string $reason): void
    {
        $now = $this->now();
        $this->pdo->prepare('UPDATE confirmare_proforma_cycles SET state = ?, active_order_uuid = NULL, closed_reason = ?, closed_at = ?, updated_at = ?, version = version + 1 WHERE cycle_uuid = ? AND active_order_uuid IS NOT NULL')
            ->execute([$state, substr(preg_replace('/[^a-z_]/', '', $reason) ?: 'closed', 0, 40), $now, $now, $cycle['cycle_uuid']]);
    }

    private function finishStep(string $cycleUuid, string $step, string $state, ?string $outcome): void
    {
        $done = in_array($state, ['applied', 'rejected', 'skipped'], true);
        $this->pdo->prepare('UPDATE confirmare_cycle_steps SET state = ?, outcome = ?, completed_at = ? WHERE cycle_uuid = ? AND step = ?')
            ->execute([$state, $outcome === null ? null : substr(preg_replace('/[^a-z_]/', '', $outcome) ?: 'unknown', 0, 40), $done ? $this->now() : null, $cycleUuid, $step]);
    }

    /** Removes a step that certainly did not run in SmartBill (the shop refused it before acting), so it can be tried again. */
    private function forgetStep(string $cycleUuid, string $step): void
    {
        $this->pdo->prepare("DELETE FROM confirmare_cycle_steps WHERE cycle_uuid = ? AND step = ? AND state <> 'applied'")->execute([$cycleUuid, $step]);
    }

    /** @param array<string, mixed> $cycle */
    private function skipStep(array $cycle, string $step): void
    {
        if ($this->stepRow((string) $cycle['cycle_uuid'], $step) !== null) {
            return;
        }
        $this->recordLocalStep($cycle, $step, 'skipped', 'not_due_in_time');
    }

    /** @param array<string, mixed> $cycle */
    private function recordLocalStep(array $cycle, string $step, string $state, string $outcome): void
    {
        try {
            $now = $this->now();
            $this->pdo->prepare('INSERT INTO confirmare_cycle_steps (step_uuid, cycle_uuid, order_uuid, step, idempotency_key, state, outcome, attempts, requested_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                ->execute([Uuid::v4(), $cycle['cycle_uuid'], $cycle['order_uuid'], $step, self::stepKey((string) $cycle['cycle_uuid'], $step), $state, $outcome, $now, $now]);
        } catch (PDOException) {
            // Already recorded by an earlier pass.
        }
    }

    /** @return array<string, mixed>|null */
    private function stepRow(string $cycleUuid, string $step): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM confirmare_cycle_steps WHERE cycle_uuid = ? AND step = ?');
        $statement->execute([$cycleUuid, $step]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function cycle(string $uuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM confirmare_proforma_cycles WHERE cycle_uuid = ?');
        $statement->execute([$uuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function activeCycle(string $orderUuid, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM confirmare_proforma_cycles WHERE active_order_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function bankCheckCovering(string $source, DateTimeImmutable $instant): ?string
    {
        $statement = $this->pdo->prepare('SELECT check_uuid FROM confirmare_bank_checks WHERE source_key = ? AND covers_until >= ? ORDER BY covers_until DESC LIMIT 1');
        $statement->execute([$source, $instant->format('Y-m-d H:i:s.u')]);
        $uuid = $statement->fetchColumn();
        return is_string($uuid) ? $uuid : null;
    }

    /** @return array<string, mixed>|null the order with its Confirmare row, locked */
    private function lockOrder(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, o.payment_method, o.cancellation_state,
                o.operational_status, o.source_commerce_status_code, o.source_financial_state, c.status, c.assigned_employee_uuid, c.decided_by_employee_uuid
            FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function orderRow(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key, source_order_id, order_number FROM operational_orders WHERE order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $cycle @return array<string, mixed>|null */
    private function summary(array $row, SourceAccessTarget $target, array $cycle, string $requestId): ?array
    {
        try {
            return SourceInvoice::summary($this->client->call($target, SourceAccessClient::ROUTE_INVOICE, $this->payload($row, (string) $cycle['owner_employee_uuid'], $requestId)), (string) $row['source_order_id'], (string) $row['order_number']);
        } catch (SourceAccessException) {
            return null;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function payload(array $row, string $employeeUuid, string $requestId): array
    {
        return ['orderId' => (string) $row['source_order_id'], 'orderNumber' => (string) $row['order_number'], 'actor' => ['employeeId' => $employeeUuid, 'role' => 'operator'], 'requestId' => self::requestId($requestId)];
    }

    /** @param array<string, mixed> $order */
    private function operates(EmployeeIdentity $actor, array $order): bool
    {
        if ($actor->isRoot || !$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('staff')) {
            return false;
        }
        $source = (string) $order['source_key'];
        return $this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)
            || (in_array($actor->employeeUuid, [$order['assigned_employee_uuid'] ?? null, $order['decided_by_employee_uuid'] ?? null], true) && $this->confirmare->allows($actor, ConfirmareScopePolicy::OPERATE, $source));
    }

    private function financeRecorder(EmployeeIdentity $actor, string $source): bool
    {
        return !$actor->isRoot && $actor->isOperationallyActive() && !$actor->mustChangePassword && $actor->hasApplication('staff')
            && ($this->finance->allows($actor, FinanceScopePolicy::RECORD, $source) || $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source));
    }

    private function financeRecordEnabled(string $source): bool
    {
        return $this->registry->find($source)?->allowsFinanceRecord() === true;
    }

    private function financeViewer(EmployeeIdentity $actor, string $source): bool
    {
        return !$actor->isRoot && $actor->isOperationallyActive() && $this->finance->sources($actor, FinanceScopePolicy::VIEW) !== []
            && ($this->finance->allows($actor, FinanceScopePolicy::VIEW, $source) || $this->financeRecorder($actor, $source));
    }

    private function requireInstalled(): void
    {
        if (!$this->installed()) {
            throw new ApiException(409, 'CONFIRMARE_CYCLE_SCHEMA_MISSING', 'Migration 043 is not applied yet.');
        }
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param array<string, mixed> $document the shop's proforma document */
    public static function documentHash(array $document): ?string
    {
        $series = $document['series'] ?? null;
        $number = $document['number'] ?? null;
        return is_string($series) && $series !== '' && is_string($number) && $number !== '' ? hash('sha256', $series . '|' . $number) : null;
    }

    /** @param array<string, mixed> $proforma */
    private static function cancelledHas(array $proforma, string $hash): bool
    {
        foreach ((array) ($proforma['cancelled'] ?? []) as $entry) {
            if (is_array($entry) && self::documentHash($entry) === $hash) {
                return true;
            }
        }
        return false;
    }

    public static function stepKey(string $cycleUuid, string $step): string
    {
        return 'cyc-' . str_replace('-', '', $cycleUuid) . '-' . str_replace('_', '-', $step);
    }

    public function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private static function iso(string $sql): string
    {
        return (new DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
