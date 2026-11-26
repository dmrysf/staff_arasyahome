<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/**
 * The automatic final invoice of the Store Workspace (API 2.84.0, migration 065, owner approval 2026-11-26 "FINAL
 * AUTOMATION APPROVAL"). The shop employee presses "Pregătește și tipărește"; the final invoice follows on the server.
 *
 * 1. The request is durable: DocumentService::recordPrint writes it (onPrinted) in the SAME transaction as the employee's
 *    print record, only for a Trend Home / Outlet Perdele Confirmare order routed to that employee, who holds the invoice
 *    `issue` scope of the shop. No print, no request: nothing here invents a print or an invoice for an order nobody printed.
 * 2. It is processed on the server: right away by the employee's own call after the print (now(); the browser may close,
 *    PHP keeps running), and every minute by bin/confirmare-distribute.php (run()). A lost connection or a SmartBill timeout
 *    only delays it.
 * 3. The invoice is the system's: ConfirmareInvoice::issueOnBehalf as the root identity of the intervention desk (action
 *    row role `manager`, key `auto-invoice-<request>-<n>`), on behalf of the employee whose print asked for it
 *    (`trigger_employee_uuid` here). Every rule of a manual issue runs again under the order lock on every attempt: approved,
 *    customer check done (production cleared), handoff ready, money condition, no cancellation or stop, no open finance or
 *    invoice action, the production document active, still that employee's order, never the employee who confirmed it.
 * 4. Never two invoices: one request per order (unique); an invoice Arasya recorded or the shop reports (also one issued in
 *    WordPress or SmartBill directly) ends it as `exists`; an action whose answer was lost is never sent again (the
 *    lost-answers worker settles it with the same key); SmartBill's unknown outcome (shop 202) stops it for a manager;
 *    each new attempt has a new key only after the previous one was settled as refused; at most MAX_SENT attempts.
 *
 * States: waiting (tried again at next_check_at, backing off to 30 minutes), done (issued | exists), attention (a person
 * must look: smartbill_unknown, refused too often), stopped (no longer this employee's order, the order closed, 14 days).
 */
final class StoreAutoInvoice
{
    public const SOURCES = ['trendhome', 'outletperdele'];
    public const MAX_SENT = 3;
    public const MAX_AGE_DAYS = 14;
    public const CLAIM_SECONDS = 180;
    public const MAX_WAIT_SECONDS = 1800;
    /** An action of the order still settling is looked at again after this long (the lost-answers worker runs every 5 minutes). */
    public const OPEN_WAIT_SECONDS = 300;
    public const KEY_PREFIX = 'auto-invoice-';
    public const REQUEST_PREFIX = 'system-auto-invoice';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConfirmareInvoice $invoice,
        private readonly PdoEmployeeRepository $employees,
    ) {
    }

    public static function installed(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM order_invoice_auto_requests LIMIT 0');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Records the request inside the print transaction (the caller holds the order row lock). Statement errors other than
     * a missing table are not swallowed: the print then fails as a whole and the employee prints again with the same key.
     *
     * @param array<string, mixed> $order the locked operational_orders row (order_uuid, global_order_id, source_key, workflow_cohort)
     */
    public static function onPrinted(PDO $pdo, array $order, string $employeeUuid, string $printUuid, string $now): void
    {
        $source = (string) ($order['source_key'] ?? '');
        if (!in_array($source, self::SOURCES, true) || ($order['workflow_cohort'] ?? null) !== WorkflowCohort::CONFIRMARE || !self::installed($pdo)) {
            return;
        }
        $orderUuid = (string) $order['order_uuid'];
        if (ConfirmareInvoice::assignee($pdo, $orderUuid) !== $employeeUuid) {
            return;
        }
        $scope = $pdo->prepare("SELECT 1 FROM employee_invoice_scopes WHERE employee_uuid = ? AND capability = 'issue' AND source_key = ?");
        $scope->execute([$employeeUuid, $source]);
        if ($scope->fetchColumn() === false || ConfirmareInvoice::issued($pdo, $orderUuid)) {
            return;
        }
        // A waiting or stopped request follows the latest print of the routed employee; done and attention never change here
        // (an invoice exists, or a person must look first). `state` is assigned last: the other assignments read the old one.
        $pdo->prepare("INSERT INTO order_invoice_auto_requests (request_uuid, order_uuid, global_order_id, source_key, trigger_employee_uuid, trigger_print_uuid, triggered_at, state,
                sent_attempts, checks, next_check_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', 0, 0, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                trigger_employee_uuid = IF(state IN ('waiting', 'stopped'), VALUES(trigger_employee_uuid), trigger_employee_uuid),
                trigger_print_uuid = IF(state IN ('waiting', 'stopped'), VALUES(trigger_print_uuid), trigger_print_uuid),
                triggered_at = IF(state IN ('waiting', 'stopped'), VALUES(triggered_at), triggered_at),
                sent_attempts = IF(state = 'stopped', 0, sent_attempts),
                checks = IF(state IN ('waiting', 'stopped'), 0, checks),
                next_check_at = IF(state IN ('waiting', 'stopped'), VALUES(next_check_at), next_check_at),
                outcome = IF(state = 'stopped', NULL, outcome),
                completed_at = IF(state = 'stopped', NULL, completed_at),
                updated_at = VALUES(updated_at),
                state = IF(state = 'stopped', 'waiting', state)")
            ->execute([Uuid::v4(), $orderUuid, (string) $order['global_order_id'], $source, $employeeUuid, $printUuid, $now, $now, $now, $now]);
    }

    /**
     * The worker (bin/confirmare-distribute.php, every minute): the due requests, oldest first, one run at a time.
     *
     * @return array{seen: int, done: int, waiting: int, attention: int, stopped: int, busy: int}
     */
    public function run(int $limit = 10): array
    {
        $counts = ['seen' => 0, 'done' => 0, 'waiting' => 0, 'attention' => 0, 'stopped' => 0, 'busy' => 0];
        if (!self::installed($this->pdo) || (int) $this->pdo->query("SELECT GET_LOCK('arasya_store_auto_invoice', 0)")->fetchColumn() !== 1) {
            return $counts;
        }
        try {
            $statement = $this->pdo->prepare("SELECT * FROM order_invoice_auto_requests WHERE state = 'waiting' AND next_check_at <= ? AND (claim_until IS NULL OR claim_until < ?)
                ORDER BY next_check_at, request_uuid LIMIT " . max(1, min(50, $limit)));
            $now = $this->stamp();
            $statement->execute([$now, $now]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts['seen']++;
                $state = $this->process($row)['state'];
                $counts[isset($counts[$state]) ? $state : 'busy']++;
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('arasya_store_auto_invoice')");
        }
        return $counts;
    }

    /**
     * The employee's call right after the print (POST /confirmare/orders/{id}/invoice/auto): processes the order's request
     * now when it is due, and returns where it stands. Only the employee whose print asked for it; anyone else sees `none`.
     * The work continues on the server when the browser closes.
     *
     * @return array{state: string, outcome?: ?string, document?: string}
     */
    public function now(EmployeeIdentity $actor, string $globalOrderId): array
    {
        if (!self::installed($this->pdo)) {
            return ['state' => 'none'];
        }
        $row = $this->request($globalOrderId);
        if ($row === null || $row['trigger_employee_uuid'] !== $actor->employeeUuid || !$actor->isOperationallyActive()) {
            return ['state' => 'none'];
        }
        if ($row['state'] === 'waiting' && ($row['claim_until'] === null || $row['claim_until'] < $this->stamp())) {
            ignore_user_abort(true);
            // The first attempt does not wait for the backoff: the employee just printed.
            return $this->process($row);
        }
        return self::view($row);
    }

    /** @return array{state: string, outcome: ?string}|null the order's request for the store screens, or null */
    public static function stateOf(PDO $pdo, string $orderUuid): ?array
    {
        try {
            $statement = $pdo->prepare('SELECT state, outcome FROM order_invoice_auto_requests WHERE order_uuid = ?');
            $statement->execute([$orderUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? ['state' => (string) $row['state'], 'outcome' => $row['outcome'] === null ? null : (string) $row['outcome']] : null;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{state: string, outcome?: ?string, document?: string}
     */
    private function process(array $row): array
    {
        $token = bin2hex(random_bytes(16));
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $claim = $this->pdo->prepare("UPDATE order_invoice_auto_requests SET claim_token = ?, claim_until = ?, checks = checks + 1, updated_at = ?
            WHERE request_uuid = ? AND state = 'waiting' AND (claim_until IS NULL OR claim_until < ?)");
        $claim->execute([$token, $now->modify('+' . self::CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s.u'), $this->stamp(), $row['request_uuid'], $this->stamp()]);
        if ($claim->rowCount() !== 1) {
            return ['state' => 'busy'];
        }
        $row['checks'] = (int) $row['checks'] + 1;
        try {
            return $this->attempt($row, $token);
        } catch (Throwable) {
            return $this->wait($row, $token, 'internal_error');
        }
    }

    /**
     * @param array<string, mixed> $row the claimed request
     * @return array{state: string, outcome?: ?string, document?: string}
     */
    private function attempt(array $row, string $token): array
    {
        $orderUuid = (string) $row['order_uuid'];
        $age = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $row['triggered_at'], new \DateTimeZone('UTC')))->getTimestamp();
        if (ConfirmareInvoice::issued($this->pdo, $orderUuid)) {
            return $this->finish($row, $token, 'done', 'issued');
        }
        // The previous attempt first: an unsettled one is never sent again, a SmartBill-unknown one waits for a manager.
        $last = $this->lastAction($row);
        if ($last !== null && in_array($last['state'], ['pending', 'unknown'], true)) {
            return $age > self::MAX_AGE_DAYS * 86400 ? $this->finish($row, $token, 'attention', 'answer_lost') : $this->wait($row, $token, 'answer_open', self::OPEN_WAIT_SECONDS);
        }
        if ($last !== null && $last['state'] === 'applied') {
            return $last['result'] === 'issued' ? $this->finish($row, $token, 'done', 'issued') : $this->finish($row, $token, 'attention', 'smartbill_unknown');
        }
        if ($age > self::MAX_AGE_DAYS * 86400) {
            return $this->finish($row, $token, 'stopped', 'expired');
        }
        if ((int) $row['sent_attempts'] >= self::MAX_SENT) {
            return $this->finish($row, $token, 'attention', self::code('refused_' . (string) ($row['outcome'] ?? 'shop')));
        }
        $system = $this->system();
        if ($system === null) {
            return $this->wait($row, $token, 'system_unavailable');
        }
        $employee = $this->employees->findByUuid((string) $row['trigger_employee_uuid']);
        if ($employee === null || !$employee->isOperationallyActive() || $employee->mustChangePassword) {
            return $this->finish($row, $token, 'stopped', 'employee_inactive');
        }
        $key = self::KEY_PREFIX . str_replace('-', '', (string) $row['request_uuid']) . '-' . ((int) $row['sent_attempts'] + 1);
        $result = $this->invoice->issueOnBehalf($system, $employee, (string) $row['global_order_id'], $key, self::REQUEST_PREFIX . '-' . bin2hex(random_bytes(6)));
        $sent = $this->sentAction($system->employeeUuid, $key);
        if ($sent !== null) {
            $this->pdo->prepare('UPDATE order_invoice_auto_requests SET sent_attempts = sent_attempts + 1, last_action_uuid = ? WHERE request_uuid = ? AND claim_token = ?')
                ->execute([$sent, $row['request_uuid'], $token]);
            $row['sent_attempts'] = (int) $row['sent_attempts'] + 1;
        }
        return match ($result['state']) {
            'done' => $this->finish($row, $token, 'done', $result['outcome']) + (isset($result['document']) && $result['document'] !== '' ? ['document' => $result['document']] : []),
            'attention' => $this->finish($row, $token, 'attention', $result['outcome']),
            'stopped' => $this->finish($row, $token, 'stopped', $result['outcome']),
            'open' => $this->wait($row, $token, $result['outcome'], self::OPEN_WAIT_SECONDS),
            'refused' => (int) $row['sent_attempts'] >= self::MAX_SENT ? $this->finish($row, $token, 'attention', self::code('refused_' . $result['outcome'])) : $this->wait($row, $token, $result['outcome']),
            default => $this->wait($row, $token, $result['outcome']),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array{state: string, outcome: string}
     */
    private function wait(array $row, string $token, string $outcome, ?int $seconds = null): array
    {
        $delay = $seconds ?? min(self::MAX_WAIT_SECONDS, 60 * (2 ** min(10, max(0, (int) $row['checks'] - 1))));
        $next = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s.u');
        $this->pdo->prepare("UPDATE order_invoice_auto_requests SET outcome = ?, next_check_at = ?, claim_token = NULL, claim_until = NULL, updated_at = ?
            WHERE request_uuid = ? AND claim_token = ? AND state = 'waiting'")->execute([self::code($outcome), $next, $this->stamp(), $row['request_uuid'], $token]);
        return ['state' => 'waiting', 'outcome' => self::code($outcome)];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{state: string, outcome: string}
     */
    private function finish(array $row, string $token, string $state, string $outcome): array
    {
        $this->pdo->prepare("UPDATE order_invoice_auto_requests SET state = ?, outcome = ?, completed_at = ?, claim_token = NULL, claim_until = NULL, updated_at = ?
            WHERE request_uuid = ? AND claim_token = ? AND state = 'waiting'")->execute([$state, self::code($outcome), $this->stamp(), $this->stamp(), $row['request_uuid'], $token]);
        return ['state' => $state, 'outcome' => self::code($outcome)];
    }

    /** @param array<string, mixed> $row @return array{state: string, result: ?string}|null */
    private function lastAction(array $row): ?array
    {
        if ($row['last_action_uuid'] === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT state, result FROM order_invoice_actions WHERE action_uuid = ?');
        $statement->execute([(string) $row['last_action_uuid']]);
        $found = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($found) ? ['state' => (string) $found['state'], 'result' => $found['result'] === null ? null : (string) $found['result']] : null;
    }

    private function sentAction(string $systemUuid, string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT action_uuid FROM order_invoice_actions WHERE employee_uuid = ? AND idempotency_key = ?');
        $statement->execute([$systemUuid, $key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function system(): ?EmployeeIdentity
    {
        return self::systemIdentity($this->pdo, $this->employees);
    }

    /**
     * The system identity of the automatic invoice (root, used only through the intervention desk instance of
     * ConfirmareInvoice): active, password set. Also ConfirmareAwbReadiness.
     */
    public static function systemIdentity(PDO $pdo, PdoEmployeeRepository $employees): ?EmployeeIdentity
    {
        try {
            $uuid = $pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
        } catch (PDOException) {
            return null;
        }
        $root = is_string($uuid) ? $employees->findByUuid($uuid) : null;
        return $root !== null && $root->isRoot && $root->isOperationallyActive() && !$root->mustChangePassword ? $root : null;
    }

    /** @return array<string, mixed>|null */
    private function request(string $globalOrderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_invoice_auto_requests WHERE global_order_id = ?');
        $statement->execute([$globalOrderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @return array{state: string, outcome: ?string} */
    private static function view(array $row): array
    {
        return ['state' => (string) $row['state'], 'outcome' => $row['outcome'] === null ? null : (string) $row['outcome']];
    }

    private static function code(string $value): string
    {
        $code = substr(preg_replace('/[^a-z_]/', '', strtolower($value)) ?? '', 0, 40);
        return strlen($code) >= 2 ? $code : 'unknown';
    }

    private function stamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
