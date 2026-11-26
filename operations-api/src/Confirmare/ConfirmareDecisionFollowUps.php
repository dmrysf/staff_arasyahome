<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Cancellation\Sync\CancellationSyncService;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * What the system does after an operator's decision (API 2.82.0, Operation Simplification V1, owner decision 2026-11-26).
 * The operator presses one button and moves on; the work that talks to the shop runs here, in the worker, never in the
 * operator's request.
 *
 * Green (`confirmed`), after a short grace period (ConfirmareService::UNDO_SECONDS) in which the operator may undo:
 *   1. accept_total:   the customer's acceptance of the CURRENT total is recorded in the shop (the shop's `total_accept`,
 *                      the record the advance proforma and the final invoice need), as the operator who decided, through
 *                      the existing proforma relay (ConfirmareInvoice::proforma: recorded in order_invoice_actions, a lost
 *                      answer is settled by its key). It is sent only while the order Arasya holds is still the one the
 *                      customer confirmed (the approved review fingerprint, no unsettled shop change); otherwise nothing
 *                      is sent and the follow-up closes as `order_changed` (production stays closed: the approval is stale).
 *                      A verified advance below the standard advance never needs the operator (owner rule: any verified
 *                      positive amount is the customer's advance), so the decision `accept_received` is sent with it.
 *   2. start_proforma: an unpaid cash-on-delivery order gets its advance proforma through the existing proforma cycle
 *                      (ConfirmareCycles::startForConfirmed: issue, e-mail, reminders, expiry; exactly once, an existing
 *                      proforma is re-used and never issued twice). A paid, online or zero-total order needs none.
 * Red (`refused`), after the grace period:
 *   request_cancel:    the order's open proforma cycle is closed (no reminder to a customer who refused) and the order's
 *                      cancellation is asked through the existing path (CancellationSyncService::requestForCustomerRefusal),
 *                      which refuses whenever money was received or is being verified, an invoice exists or production
 *                      started: those orders go to a person, never to an automatic cancellation.
 *
 * One active follow-up per order (unique active row). Each run claims a row with a conditional UPDATE, so two workers never
 * act on the same order. A business refusal closes the row with its code; a shop that does not answer leaves it waiting
 * for the next run (WAIT_MINUTES), at most MAX_ATTEMPTS times.
 */
final class ConfirmareDecisionFollowUps
{
    public const MAX_ATTEMPTS = 12;
    public const WAIT_MINUTES = 10;
    public const CLAIM_SECONDS = 300;
    /** Shop refusals that may clear by themselves (another action of the order is still settling, the shop is busy). */
    private const TRANSIENT = ['invoice_action_open', 'order_busy', 'operation_in_progress', 'smartbill_unreachable', 'rate_limited', 'finance_open'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConfirmareInvoice $invoice,
        private readonly ConfirmareCycles $cycles,
        private readonly CancellationSyncService $cancellation,
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
     */
    public static function open(PDO $pdo, array $order, string $kind, string $employeeUuid, int $decisionVersion, string $nowSql, int $graceSeconds): void
    {
        $pdo->prepare("UPDATE confirmare_decision_followups SET state = 'cancelled', active_order_uuid = NULL, closed_at = ?, closed_reason = 'superseded', updated_at = ? WHERE active_order_uuid = ?")
            ->execute([$nowSql, $nowSql, (string) $order['order_uuid']]);
        $due = (new DateTimeImmutable($nowSql, new DateTimeZone('UTC')))->modify('+' . max(0, $graceSeconds) . ' seconds')->format('Y-m-d H:i:s.u');
        $pdo->prepare('INSERT INTO confirmare_decision_followups (followup_uuid, order_uuid, active_order_uuid, global_order_id, source_key, kind, employee_uuid, decision_version, state, step,
                due_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            Uuid::v4(), (string) $order['order_uuid'], (string) $order['order_uuid'], (string) $order['global_order_id'], (string) $order['source_key'], $kind, $employeeUuid,
            $decisionVersion, 'pending', $kind === 'confirmed' ? 'accept_total' : 'request_cancel', $due, $nowSql, $nowSql,
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
            'dueAt' => (new DateTimeImmutable((string) $row['due_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')];
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
        $claim = $this->pdo->prepare("UPDATE confirmare_decision_followups SET claim_token = ?, claimed_at = ? WHERE followup_uuid = ? AND state IN ('pending', 'waiting') AND (claim_token IS NULL OR claimed_at < ?)");
        $claim->execute([$token, $now->format('Y-m-d H:i:s.u'), $followupUuid, $now->modify('-' . self::CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s.u')]);
        if ($claim->rowCount() !== 1) {
            return 'skipped';
        }
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
            if ($row !== null && in_array($row['state'], ['pending', 'waiting'], true)) {
                $this->wait($row, 'internal_error');
            }
            return 'waiting';
        } finally {
            $this->pdo->prepare('UPDATE confirmare_decision_followups SET claim_token = NULL WHERE followup_uuid = ? AND claim_token = ?')->execute([$followupUuid, $token]);
        }
    }

    /** @param array<string, mixed> $row @return string continue|done|waiting|failed */
    private function step(array $row, string $requestId): string
    {
        $order = $this->order((string) $row['order_uuid']);
        $expected = $row['kind'] === 'confirmed' ? 'approved' : 'rejected';
        if ($order === null || $order['status'] !== $expected || (int) $order['version'] < (int) $row['decision_version']
            || ($order['status'] === 'approved' && $order['decided_by_employee_uuid'] !== $row['employee_uuid'])) {
            return $this->close($row, 'cancelled', 'decision_changed');
        }
        $rid = $requestId . '-' . substr((string) $row['followup_uuid'], 0, 8);
        if ($row['step'] === 'request_cancel') {
            $this->cycles->closeForRefusal((string) $row['order_uuid']);
            $key = 'osv1-refused-' . str_replace('-', '', (string) $row['followup_uuid']);
            $this->mark($row, ['step_key' => $key, 'attempts' => (int) $row['attempts'] + 1]);
            $answer = $this->cancellation->requestForCustomerRefusal((string) $row['order_uuid'], (string) $row['employee_uuid'], $rid, $key);
            if (in_array($answer['state'], self::TRANSIENT, true)) {
                return $this->wait($row, $answer['state']);
            }
            // requested, source_cancelled, or a refusal a person handles (money_received, invoice_exists, production_started).
            return $this->close($row, 'done', $answer['state']);
        }
        $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid((string) $row['employee_uuid']);
        if ($employee === null || !$employee->isOperationallyActive()) {
            return $this->close($row, 'failed', 'operator_inactive');
        }
        $globalId = (string) $row['global_order_id'];
        if ($row['step'] === 'accept_total') {
            if ($order['approved_review_fingerprint'] === null || ConfirmareSourceEdits::changePending($this->pdo, (string) $row['order_uuid'])
                || !hash_equals((string) $order['approved_review_fingerprint'], ConfirmationClearance::fingerprint($this->pdo, (string) $row['order_uuid']))) {
                return $this->close($row, 'failed', 'order_changed');
            }
            try {
                $read = $this->invoice->read($employee, $globalId, $rid);
            } catch (ApiException $e) {
                return $e->status === 429 ? $this->wait($row, 'rate_limited') : $this->close($row, 'failed', self::code($e->errorCode));
            }
            if (($read['available'] ?? false) !== true) {
                // No invoicing for this shop: nothing to record there; the decision itself stands.
                return ($read['reason'] ?? '') === 'not_configured' ? $this->next($row, 'start_proforma') : $this->wait($row, self::code((string) ($read['reason'] ?? 'shop_unreachable')));
            }
            $summary = $read['summary'];
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
            $started = $this->cycles->startForConfirmed((string) $row['order_uuid'], (string) $row['employee_uuid'], $rid);
            if (in_array($started['reason'] ?? null, self::TRANSIENT, true)) {
                return $this->wait($row, (string) $started['reason']);
            }
            return $this->close($row, 'done', self::code((string) ($started['reason'] ?? $started['state'] ?? 'started')));
        }
        return $this->close($row, 'done', 'finished');
    }

    /** @param array<string, mixed> $row */
    private function next(array $row, string $step): string
    {
        $this->mark($row, ['step' => $step, 'step_key' => null, 'last_error' => null, 'state' => 'pending']);
        return 'continue';
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
        $this->pdo->prepare('UPDATE confirmare_decision_followups SET state = ?, active_order_uuid = NULL, closed_at = ?, closed_reason = ?, step = ?, updated_at = ? WHERE followup_uuid = ? AND active_order_uuid IS NOT NULL')
            ->execute([$state, $now, self::code($reason), $state === 'done' ? 'finished' : (string) $row['step'], $now, $row['followup_uuid']]);
        return $state === 'cancelled' ? 'skipped' : $state;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $changes */
    private function mark(array $row, array $changes): void
    {
        $changes['updated_at'] = $this->now();
        $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($changes)));
        $this->pdo->prepare("UPDATE confirmare_decision_followups SET {$sets} WHERE followup_uuid = ? AND active_order_uuid IS NOT NULL")->execute([...array_values($changes), $row['followup_uuid']]);
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
        $statement = $this->pdo->prepare('SELECT c.status, c.version, c.decided_by_employee_uuid, c.approved_review_fingerprint FROM order_confirmations c WHERE c.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function code(string $value): string
    {
        $code = substr((string) preg_replace('/[^a-z_]/', '', strtolower($value)), 0, 40);
        return strlen($code) >= 2 ? $code : 'unknown';
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
