<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Cancellation\Sync\CancellationSyncService;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * What the system does after an operator's decision (API 2.82.0, Operation Simplification V1, owner decision 2026-11-26,
 * owner correction 2026-11-26). The operator presses one button and moves on; the work that talks to the shop runs here,
 * in the worker, never in the operator's request.
 *
 * Green (`confirmed`), after the undo period (ConfirmareService::UNDO_SECONDS). Nothing that is hard to take back happens
 * before this step is done: the paid routing waits for it (ConfirmareInvoice::routePaid).
 *   1. accept_total:   the customer's acceptance of the total is recorded in the shop (the shop's `total_accept`) as the
 *                      deciding operator, through the existing proforma relay. Sent only while the order Arasya holds is
 *                      still the one the customer confirmed (approved review fingerprint, no unsettled shop change) AND the
 *                      shop's own total, read now, is the total the screen showed (`approved_total`). Otherwise nothing is
 *                      sent and the order goes back to the operator (ConfirmareService::reopenChangedAfterConfirmation):
 *                      the customer must hear the new order.
 *   2. start_proforma: an unpaid cash-on-delivery order gets its advance proforma through the existing proforma cycle
 *                      (exactly once). The follow-up is done only once the cycle reports the proforma e-mailed (or no
 *                      proforma is needed: paid, online, a cycle already running).
 * Red (`refused`), after the undo period:
 *   request_cancel:    the order's proforma cycle stops e-mailing; a proforma issued in SmartBill is cancelled through the
 *                      cycle's safe path (ConfirmareCycles::refuse: money checked again by the shop, finance tasks and
 *                      holds wait, a bank check must cover the refusal, an unknown outcome is never sent again).
 *   proforma_cancel:   waits for that cycle; then the order's cancellation is asked (requestForCustomerRefusal).
 *
 * Outcomes. `done` only when the real work happened (the shop recorded the acceptance and the proforma was e-mailed or not
 * needed; the proforma was cancelled or never existed and the cancellation was requested). `failed` means a person must
 * look: money arrived, an invoice exists, production started, SmartBill did not confirm, the deciding operator's account
 * is no longer active, the attempts ran out. Supervisors see these (`attention`) and may retry them safely (`retry`): the
 * same checks run again, as the retrying supervisor. `cancelled` is a decision that changed (undo, a new decision, the
 * order given back to the operator).
 *
 * One active follow-up per order (unique active row). A run claims a row with a conditional UPDATE and writes only while
 * it holds that claim (`claim_token`), so an old worker whose claim expired never overwrites a newer one. Each step that
 * leaves Arasya has its own idempotency key; a lost answer is asked again with the same key.
 */
final class ConfirmareDecisionFollowUps
{
    public const MAX_ATTEMPTS = 12;
    public const WAIT_MINUTES = 10;
    public const CLAIM_SECONDS = 300;
    /** Shop refusals that may clear by themselves (another action of the order is still settling, the shop is busy). */
    private const TRANSIENT = ['invoice_action_open', 'order_busy', 'operation_in_progress', 'smartbill_unreachable', 'rate_limited', 'finance_open', 'sync_open', 'storage_unavailable', 'schema_missing'];
    /** Cancellation answers after which the refused order really is being cancelled. */
    private const CANCEL_DONE = ['requested', 'source_cancelled'];
    /** Proforma cycle endings after which the refused order's own cancellation may be asked. */
    private const CYCLE_CANCELLED = ['customer_refused', 'proforma_cancelled', 'proforma_missing', 'order_closed'];
    /** Proforma cycle states in which the advance proforma was really e-mailed. */
    private const CYCLE_SENT = ['running', 'waiting_bank_check', 'waiting_finance', 'cancel_pending', 'cancel_unknown', 'order_cancel_pending'];
    /** start_proforma answers that mean no proforma is needed (or one runs already). */
    private const PROFORMA_NOT_NEEDED = ['advance_not_required', 'advance_already_collected', 'cycle_exists'];

    private ?string $token = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConfirmareInvoice $invoice,
        private readonly ConfirmareCycles $cycles,
        private readonly CancellationSyncService $cancellation,
        private readonly ?ConfirmareService $confirmare = null,
    ) {
    }

    public static function installed(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM confirmare_decision_followups LIMIT 0');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Opens the follow-up of a decision in the caller's transaction (the order row is locked). An earlier active follow-up
     * of the order is closed as `superseded` first.
     *
     * @param array<string, mixed> $order
     * @param string|null $approvedTotal green: the shop total the operator showed the customer
     */
    public static function open(PDO $pdo, array $order, string $kind, string $employeeUuid, int $decisionVersion, string $nowSql, int $graceSeconds, ?string $approvedTotal = null): void
    {
        $pdo->prepare("UPDATE confirmare_decision_followups SET state = 'cancelled', active_order_uuid = NULL, closed_at = ?, closed_reason = 'superseded', updated_at = ? WHERE active_order_uuid = ?")
            ->execute([$nowSql, $nowSql, (string) $order['order_uuid']]);
        $due = (new DateTimeImmutable($nowSql, new DateTimeZone('UTC')))->modify('+' . max(0, $graceSeconds) . ' seconds')->format('Y-m-d H:i:s.u');
        $pdo->prepare('INSERT INTO confirmare_decision_followups (followup_uuid, order_uuid, active_order_uuid, global_order_id, source_key, kind, employee_uuid, decision_version, state, step,
                due_at, created_at, updated_at, approved_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            Uuid::v4(), (string) $order['order_uuid'], (string) $order['order_uuid'], (string) $order['global_order_id'], (string) $order['source_key'], $kind, $employeeUuid,
            $decisionVersion, 'pending', $kind === 'confirmed' ? 'accept_total' : 'request_cancel', $due, $nowSql, $nowSql, $kind === 'confirmed' ? $approvedTotal : null,
        ]);
    }

    /**
     * Cancels the active follow-up of the decision the order holds now when nothing of it was sent yet (the operator's undo).
     * False when there is none, it belongs to another decision, or a step already left Arasya.
     */
    public static function cancelUntouched(PDO $pdo, string $orderUuid, int $currentVersion, string $nowSql): bool
    {
        $statement = $pdo->prepare("UPDATE confirmare_decision_followups SET state = 'cancelled', active_order_uuid = NULL, closed_at = ?, closed_reason = 'operator_undo', updated_at = ?
            WHERE active_order_uuid = ? AND decision_version = ? AND attempts = 0 AND step_key IS NULL AND claim_token IS NULL AND state = 'pending'");
        $statement->execute([$nowSql, $nowSql, $orderUuid, $currentVersion]);
        return $statement->rowCount() === 1;
    }

    /** The order's follow-up as the desk shows it (latest one), or null. @return array<string, mixed>|null */
    public static function view(PDO $pdo, string $orderUuid): ?array
    {
        try {
            $statement = $pdo->prepare('SELECT kind, state, step, last_error, due_at, closed_reason, created_at FROM confirmare_decision_followups WHERE order_uuid = ? ORDER BY created_at DESC LIMIT 1');
            $statement->execute([$orderUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        return ['kind' => (string) $row['kind'], 'state' => (string) $row['state'], 'step' => (string) $row['step'], 'error' => $row['last_error'], 'closedReason' => $row['closed_reason'],
            'dueAt' => self::iso((string) $row['due_at'])];
    }

    /**
     * The follow-ups a supervisor of these sources must look at: those that need a person (`failed`) in the last 30 days and
     * those still waiting after several attempts. Newest first. Never the operator's list.
     *
     * @param list<string>|null $sources null: every source (root)
     * @return list<array<string, mixed>>
     */
    public function attention(?array $sources, int $limit = 200): array
    {
        if ($sources === [] || !self::installed($this->pdo)) {
            return [];
        }
        $sources ??= array_map('strval', $this->pdo->query('SELECT DISTINCT source_key FROM confirmare_decision_followups')->fetchAll(PDO::FETCH_COLUMN));
        if ($sources === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($sources), '?'));
        $since = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('-30 days')->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare("SELECT f.followup_uuid, f.global_order_id, f.source_key, f.kind, f.state, f.step, f.attempts, f.last_error, f.closed_reason, f.created_at, f.updated_at,
                f.retried_at, e.display_name AS decided_by, r.display_name AS retried_by
            FROM confirmare_decision_followups f INNER JOIN employees e ON e.employee_uuid = f.employee_uuid LEFT JOIN employees r ON r.employee_uuid = f.retried_by_employee_uuid
            WHERE f.source_key IN ({$marks}) AND ((f.state = 'failed' AND f.closed_at >= ?) OR (f.state = 'waiting' AND f.attempts >= 3))
              AND NOT EXISTS (SELECT 1 FROM confirmare_decision_followups n WHERE n.order_uuid = f.order_uuid AND n.created_at > f.created_at)
            ORDER BY f.updated_at DESC LIMIT " . max(1, min(500, $limit)));
        $statement->execute([...$sources, $since]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['followup_uuid'], 'globalOrderId' => (string) $row['global_order_id'], 'orderNumber' => substr((string) strrchr((string) $row['global_order_id'], ':'), 1),
            'sourceKey' => (string) $row['source_key'], 'kind' => (string) $row['kind'], 'state' => (string) $row['state'], 'step' => (string) $row['step'], 'attempts' => (int) $row['attempts'],
            'reason' => $row['state'] === 'failed' ? $row['closed_reason'] : $row['last_error'], 'decidedBy' => (string) $row['decided_by'],
            'retriedBy' => $row['retried_by'] === null ? null : (string) $row['retried_by'], 'retriedAt' => $row['retried_at'] === null ? null : self::iso((string) $row['retried_at']),
            'createdAt' => self::iso((string) $row['created_at']), 'updatedAt' => self::iso((string) $row['updated_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The follow-ups a Confirmare supervisor (or root) must look at, for their sources. @return list<array<string, mixed>> */
    public function attentionFor(EmployeeIdentity $actor): array
    {
        $sources = (new ConfirmareScopePolicy($this->pdo))->sources($actor, ConfirmareScopePolicy::SUPERVISE);
        if ($sources === []) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'Only a Confirmare supervisor sees the follow-ups.');
        }
        return $this->attention($sources);
    }

    /**
     * A supervisor's safe retry of a follow-up that needed a person, once the cause is gone (the money question answered,
     * SmartBill reachable again, another operator active). The same follow-up resumes at the step where it stopped, as the
     * supervisor, with fresh attempts; every check of that step runs again. Only the latest follow-up of an order whose
     * decision still stands, and only one active follow-up per order (a concurrent retry is refused).
     *
     * @return array<string, mixed> the follow-up as `attention` shows it
     */
    public function retry(EmployeeIdentity $actor, string $followupUuid): array
    {
        $scopes = new ConfirmareScopePolicy($this->pdo);
        $row = $this->row($followupUuid);
        if ($row === null) {
            throw new ApiException(404, 'FOLLOWUP_NOT_FOUND', 'The follow-up does not exist.');
        }
        if (!$scopes->allows($actor, ConfirmareScopePolicy::SUPERVISE, (string) $row['source_key'], true)) {
            throw new ApiException(403, 'CONFIRMARE_SCOPE_DENIED', 'Only a Confirmare supervisor of this shop retries a follow-up.');
        }
        if ($row['state'] !== 'failed' && !($row['state'] === 'waiting' && (int) $row['attempts'] >= 3)) {
            throw new ApiException(409, 'FOLLOWUP_NOT_RETRYABLE', 'Only a follow-up that needs a person can be retried.');
        }
        $order = $this->order((string) $row['order_uuid']);
        $expected = $row['kind'] === 'confirmed' ? 'approved' : 'rejected';
        if ($order === null || $order['status'] !== $expected || (int) $order['version'] < (int) $row['decision_version']) {
            throw new ApiException(409, 'FOLLOWUP_DECISION_CHANGED', 'The decision of this order changed; nothing is retried.');
        }
        $newer = $this->pdo->prepare('SELECT COUNT(*) FROM confirmare_decision_followups WHERE order_uuid = ? AND created_at > ?');
        $newer->execute([(string) $row['order_uuid'], (string) $row['created_at']]);
        if ((int) $newer->fetchColumn() > 0) {
            throw new ApiException(409, 'FOLLOWUP_BUSY', 'The follow-up is no longer the latest one of this order.');
        }
        $now = $this->now();
        try {
            // The unique active index refuses a second active follow-up of the order (a concurrent retry or decision).
            $statement = $this->pdo->prepare("UPDATE confirmare_decision_followups SET state = 'pending', active_order_uuid = order_uuid, closed_at = NULL, closed_reason = NULL, last_error = NULL,
                    attempts = 0, step_key = NULL, due_at = ?, retried_by_employee_uuid = ?, retried_at = ?, updated_at = ?
                WHERE followup_uuid = ? AND (state = 'failed' OR (state = 'waiting' AND attempts >= 3)) AND claim_token IS NULL");
            $statement->execute([$now, $actor->employeeUuid, $now, $now, $followupUuid]);
        } catch (PDOException) {
            throw new ApiException(409, 'FOLLOWUP_BUSY', 'Another follow-up of this order is active.');
        }
        if ($statement->rowCount() !== 1) {
            throw new ApiException(409, 'FOLLOWUP_BUSY', 'The follow-up is running or is no longer the latest one.');
        }
        return ['id' => $followupUuid, 'state' => 'pending', 'step' => (string) $row['step'], 'retriedAt' => self::iso($now)];
    }

    /** @return array{seen: int, done: int, waiting: int, failed: int, skipped: int} */
    public function run(int $limit = 50, string $requestId = 'confirmare-followups'): array
    {
        $result = ['seen' => 0, 'done' => 0, 'waiting' => 0, 'failed' => 0, 'skipped' => 0];
        if (!self::installed($this->pdo)) {
            return $result;
        }
        $due = $this->pdo->prepare("SELECT followup_uuid FROM confirmare_decision_followups WHERE state IN ('pending', 'waiting') AND due_at <= ? ORDER BY due_at, followup_uuid LIMIT " . max(1, min(200, $limit)));
        $due->execute([$this->now()]);
        foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
            $result['seen']++;
            $outcome = $this->advance((string) $uuid, $requestId);
            $result[$outcome] = ($result[$outcome] ?? 0) + 1;
        }
        return $result;
    }

    /** One follow-up, as far as it goes now. @return string done|waiting|failed|skipped */
    public function advance(string $followupUuid, string $requestId): string
    {
        $token = Uuid::v4();
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $claim = $this->pdo->prepare("UPDATE confirmare_decision_followups SET claim_token = ?, claimed_at = ? WHERE followup_uuid = ? AND state IN ('pending', 'waiting') AND due_at <= ? AND (claim_token IS NULL OR claimed_at < ?)");
        $claim->execute([$token, $now->format('Y-m-d H:i:s.u'), $followupUuid, $now->format('Y-m-d H:i:s.u'), $now->modify('-' . self::CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s.u')]);
        if ($claim->rowCount() !== 1) {
            return 'skipped';
        }
        $this->token = $token;
        try {
            for ($pass = 0; $pass < 3; $pass++) {
                $row = $this->row($followupUuid);
                if ($row === null || !in_array($row['state'], ['pending', 'waiting'], true) || $row['claim_token'] !== $token) {
                    return $row !== null && $row['state'] === 'done' ? 'done' : ($row !== null && $row['state'] === 'failed' ? 'failed' : 'skipped');
                }
                $next = $this->step($row, $requestId);
                if ($next !== 'continue') {
                    return $next;
                }
            }
            return 'waiting';
        } catch (Throwable $error) {
            error_log('Arasya Confirmare follow-up ' . $followupUuid . ': ' . get_class($error));
            $row = $this->row($followupUuid);
            if ($row !== null && in_array($row['state'], ['pending', 'waiting'], true) && $row['claim_token'] === $token) {
                $this->wait($row, 'internal_error');
            }
            return 'waiting';
        } finally {
            $this->pdo->prepare('UPDATE confirmare_decision_followups SET claim_token = NULL WHERE followup_uuid = ? AND claim_token = ?')->execute([$followupUuid, $token]);
            $this->token = null;
        }
    }

    /** @param array<string, mixed> $row @return string continue|done|waiting|failed|skipped */
    private function step(array $row, string $requestId): string
    {
        $orderUuid = (string) $row['order_uuid'];
        $order = $this->order($orderUuid);
        $expected = $row['kind'] === 'confirmed' ? 'approved' : 'rejected';
        if ($order === null || $order['status'] !== $expected || (int) $order['version'] < (int) $row['decision_version']
            || ($order['status'] === 'approved' && $order['decided_by_employee_uuid'] !== $row['employee_uuid'])) {
            return $this->close($row, 'cancelled', 'decision_changed');
        }
        $rid = $requestId . '-' . substr((string) $row['followup_uuid'], 0, 8);
        // The steps run as the deciding operator, or as the supervisor who retried the follow-up.
        $actorUuid = (string) ($row['retried_by_employee_uuid'] ?? $row['employee_uuid']);
        if ($row['step'] === 'request_cancel') {
            $refusedAt = (string) ($order['decided_at'] ?? $row['created_at']);
            $cycle = $this->cycles->refuse($orderUuid, $refusedAt, $actorUuid);
            if ($cycle === 'busy') {
                return $this->wait($row, 'proforma_busy');
            }
            if ($cycle === 'refused') {
                return $this->next($row, 'proforma_cancel');
            }
            return $this->requestCancel($row, $actorUuid, $rid);
        }
        if ($row['step'] === 'proforma_cancel') {
            $latest = $this->cycles->latestCycle($orderUuid);
            if ($latest !== null && $latest['active_order_uuid'] !== null) {
                // The cycle works towards the cancellation (a bank check, the money, SmartBill): no attempt is used up.
                return $this->hold($row, 'cycle_' . (string) $latest['state']);
            }
            $ending = $latest === null ? 'customer_refused' : (string) $latest['closed_reason'];
            if ($latest !== null && $latest['state'] === 'paid') {
                return $this->close($row, 'failed', 'money_received');
            }
            if (!in_array($ending, self::CYCLE_CANCELLED, true)) {
                // SmartBill did not confirm the cancellation, the proforma was replaced, the shop refused: a person decides.
                return $this->close($row, 'failed', $ending);
            }
            return $this->requestCancel($row, $actorUuid, $rid);
        }
        $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid($actorUuid);
        if ($employee === null || !$employee->isOperationallyActive()) {
            return $this->close($row, 'failed', 'operator_inactive');
        }
        $globalId = (string) $row['global_order_id'];
        if ($row['step'] === 'accept_total') {
            if (ConfirmareSourceEdits::changePending($this->pdo, $orderUuid)) {
                return $this->wait($row, 'shop_change_pending');
            }
            if ($order['approved_review_fingerprint'] === null || !hash_equals((string) $order['approved_review_fingerprint'], ConfirmationClearance::fingerprint($this->pdo, $orderUuid))) {
                return $this->giveBack($row, 'order_changed', $rid);
            }
            try {
                $read = $this->invoice->read($employee, $globalId, $rid);
            } catch (ApiException $e) {
                return $e->status === 429 || $e->status >= 500 ? $this->wait($row, $e->status === 429 ? 'rate_limited' : 'shop_unreachable') : $this->close($row, 'failed', self::code($e->errorCode));
            }
            if (($read['available'] ?? false) !== true) {
                // No invoicing for this shop: nothing to record there; the decision itself stands.
                return ($read['reason'] ?? '') === 'not_configured' ? $this->next($row, 'start_proforma') : $this->wait($row, self::code((string) ($read['reason'] ?? 'shop_unreachable')));
            }
            $summary = $read['summary'];
            // The shop's own total, read now, must be the total the customer confirmed (a change in WooCommerce that did not
            // reach Arasya yet is caught here): otherwise nothing is accepted and the order goes back to the operator.
            $shopTotal = $summary['order']['total'] ?? null;
            if ($row['approved_total'] !== null && is_string($shopTotal) && ConfirmareService::bani($shopTotal) !== ConfirmareService::bani((string) $row['approved_total'])) {
                return $this->giveBack($row, 'total_changed', $rid);
            }
            $acceptance = $summary['proforma']['totalAcceptance'] ?? null;
            if (!is_array($acceptance) || $acceptance['state'] === 'accepted') {
                return $this->next($row, 'start_proforma');
            }
            $key = (string) ($row['step_key'] ?? '') !== '' ? (string) $row['step_key'] : 'osv1-accept-' . str_replace('-', '', (string) $row['followup_uuid']) . '-' . ((int) $row['attempts'] + 1);
            $input = ['expectedRevision' => (string) $summary['revision'], 'declaration' => true];
            if (($acceptance['advanceDecisionRequired'] ?? false) === true) {
                $input += ['advanceDecision' => 'accept_received', 'confirmLow' => true, 'note' => 'Regula avansului (2026-11-25): orice sumă verificată este avansul clientului.'];
            }
            $this->mark($row, ['step_key' => $key, 'attempts' => (int) $row['attempts'] + 1]);
            try {
                $answer = $this->invoice->proforma($employee, $globalId, 'total_accept', $input, $key, $rid);
            } catch (ApiException $e) {
                $reason = self::code((string) ($e->details['reason'] ?? $e->errorCode));
                if ($reason === 'total_already_accepted') {
                    return $this->next($row, 'start_proforma');
                }
                // A refusal is stored by the shop under its key: the next attempt reads again and sends a new key.
                $this->mark($row, ['step_key' => null]);
                if ($e->status === 429 || $e->status >= 500 || in_array($reason, [...self::TRANSIENT, 'revision_changed'], true)) {
                    return $this->wait($row, $e->status === 429 ? 'rate_limited' : $reason);
                }
                return $this->close($row, 'failed', $reason);
            }
            if (($answer['state'] ?? null) === 'applied') {
                return $this->next($row, 'start_proforma');
            }
            // An unknown answer: the same key is asked again on the next pass (ConfirmareInvoice settles it from the shop).
            return $this->wait($row, 'accept_unknown');
        }
        if ($row['step'] === 'start_proforma') {
            $cycle = $this->cycles->latestCycle($orderUuid);
            if ($cycle === null || ($cycle['active_order_uuid'] === null && (string) $row['step_key'] === '')) {
                $this->mark($row, ['step_key' => 'osv1-proforma-' . str_replace('-', '', (string) $row['followup_uuid'])]);
                $started = $this->cycles->startForConfirmed($orderUuid, $actorUuid, $rid);
                $reason = (string) ($started['reason'] ?? '');
                if ($started['state'] !== 'started') {
                    if (in_array($reason, self::TRANSIENT, true)) {
                        $this->mark($row, ['step_key' => null]);
                        return $this->wait($row, $reason);
                    }
                    if (in_array($reason, self::PROFORMA_NOT_NEEDED, true)) {
                        return $this->close($row, 'done', $reason);
                    }
                    // order_closed, confirmare_not_approved, invoice_disabled, order_unavailable: no proforma goes out.
                    return $this->close($row, $reason === 'confirmare_not_approved' ? 'cancelled' : 'failed', $reason !== '' ? $reason : 'proforma_not_started');
                }
                $cycle = $this->cycles->latestCycle($orderUuid);
            }
            if ($cycle === null) {
                return $this->wait($row, 'proforma_pending');
            }
            if ($cycle['active_order_uuid'] === null) {
                return in_array($cycle['state'], ['paid'], true) ? $this->close($row, 'done', 'advance_received') : $this->close($row, 'failed', 'proforma_' . self::code((string) $cycle['closed_reason']));
            }
            if (in_array($cycle['state'], self::CYCLE_SENT, true)) {
                return $this->close($row, 'done', 'proforma_sent');
            }
            // issue_pending or mail_pending: the cycle worker issues and e-mails it; a lost answer is settled there.
            return $this->wait($row, 'proforma_' . self::code((string) ($cycle['last_error'] ?? $cycle['state'])));
        }
        return $this->close($row, 'done', 'finished');
    }

    /** The refused order's own cancellation (nothing paid, nothing invoiced, production not started). @param array<string, mixed> $row */
    private function requestCancel(array $row, string $actorUuid, string $rid): string
    {
        $key = 'osv1-refused-' . str_replace('-', '', (string) $row['followup_uuid']);
        $this->mark($row, ['step_key' => $key, 'attempts' => (int) $row['attempts'] + 1]);
        $answer = $this->cancellation->requestForCustomerRefusal((string) $row['order_uuid'], $actorUuid, $rid, $key);
        if (in_array($answer['state'], self::TRANSIENT, true)) {
            return $this->wait($row, $answer['state']);
        }
        // Done only when the cancellation really is requested; money_received, finance_hold, invoice_exists,
        // production_started or not_held go to a person.
        return $this->close($row, in_array($answer['state'], self::CANCEL_DONE, true) ? 'done' : 'failed', $answer['state']);
    }

    /** The order changed after the customer's confirmation: back to the operator, or a person when that is not possible. @param array<string, mixed> $row */
    private function giveBack(array $row, string $reason, string $rid): string
    {
        if ($this->confirmare !== null && $this->confirmare->reopenChangedAfterConfirmation((string) $row['order_uuid'], $rid)) {
            return $this->close($row, 'cancelled', $reason);
        }
        return $this->close($row, 'failed', $reason);
    }

    /** @param array<string, mixed> $row */
    private function next(array $row, string $step): string
    {
        $this->mark($row, ['step' => $step, 'step_key' => null, 'last_error' => null, 'state' => 'pending']);
        return 'continue';
    }

    /** Waits for something outside the follow-up (the proforma cycle) without using up an attempt. @param array<string, mixed> $row */
    private function hold(array $row, string $reason): string
    {
        $due = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('+' . self::WAIT_MINUTES . ' minutes')->format('Y-m-d H:i:s.u');
        $this->mark($row, ['state' => 'waiting', 'last_error' => self::code($reason), 'due_at' => $due]);
        return 'waiting';
    }

    /** @param array<string, mixed> $row */
    private function wait(array $row, string $reason): string
    {
        $attempts = (int) ($this->row((string) $row['followup_uuid'])['attempts'] ?? $row['attempts']) + 1;
        if ($attempts > self::MAX_ATTEMPTS) {
            return $this->close($row, 'failed', self::code($reason));
        }
        $due = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('+' . self::WAIT_MINUTES . ' minutes')->format('Y-m-d H:i:s.u');
        $this->mark($row, ['state' => 'waiting', 'last_error' => self::code($reason), 'due_at' => $due, 'attempts' => $attempts]);
        return 'waiting';
    }

    /** @param array<string, mixed> $row */
    private function close(array $row, string $state, string $reason): string
    {
        $now = $this->now();
        $this->pdo->prepare('UPDATE confirmare_decision_followups SET state = ?, active_order_uuid = NULL, closed_at = ?, closed_reason = ?, step = ?, updated_at = ? WHERE followup_uuid = ? AND active_order_uuid IS NOT NULL'
            . ($this->token !== null ? ' AND claim_token = ?' : ''))
            ->execute([$state, $now, self::code($reason), $state === 'done' ? 'finished' : (string) $row['step'], $now, $row['followup_uuid'], ...($this->token !== null ? [$this->token] : [])]);
        return $state === 'cancelled' ? 'skipped' : $state;
    }

    /** Writes only while this worker holds the claim (an expired claim never overwrites a newer worker's state). @param array<string, mixed> $row @param array<string, mixed> $changes */
    private function mark(array $row, array $changes): void
    {
        $changes['updated_at'] = $this->now();
        $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($changes)));
        $this->pdo->prepare("UPDATE confirmare_decision_followups SET {$sets} WHERE followup_uuid = ? AND active_order_uuid IS NOT NULL" . ($this->token !== null ? ' AND claim_token = ?' : ''))
            ->execute([...array_values($changes), $row['followup_uuid'], ...($this->token !== null ? [$this->token] : [])]);
    }

    /** @return array<string, mixed>|null */
    private function row(string $uuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM confirmare_decision_followups WHERE followup_uuid = ?');
        $statement->execute([$uuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function order(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT c.status, c.version, c.decided_at, c.decided_by_employee_uuid, c.approved_review_fingerprint FROM order_confirmations c WHERE c.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function code(string $value): string
    {
        $code = substr((string) preg_replace('/[^a-z_]/', '', strtolower($value)), 0, 40);
        return strlen($code) >= 2 ? $code : 'unknown';
    }

    private static function iso(string $sql): string
    {
        return (new DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
