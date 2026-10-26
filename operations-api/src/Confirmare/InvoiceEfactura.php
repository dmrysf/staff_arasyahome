<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * The e-Factura follow-up of each final SMART invoice (owner decision K-04, API 2.46.0, migration 043): a task for the
 * shop employee who issued the invoice (and the invoice managers of the shop), due 5 working days after the invoice
 * (Romania since 2026-01-01, OUG 89/2025: 5 working days, B2B and B2C; weekends and legal holidays do not count).
 *
 * SmartBill sends the invoice to ANAF itself; its API gives no e-Factura status, and a PDF e-mailed to the customer is not
 * an ANAF acceptance. So nothing here is ever set automatically: the employee checks the invoice in SmartBill Cloud
 * (Facturi emise › e-Factura) or SPV and records what they saw:
 *   open → sent (transmitted, ANAF answer pending) | accepted (ANAF validated) | rejected (ANAF rejected: fix it in SmartBill)
 *   sent → accepted | rejected;  rejected → sent | resolved (corrected and accepted);  accepted and resolved are final.
 * Overdue: open or rejected after the due instant.
 */
final class InvoiceEfactura
{
    public const WORKING_DAYS = 5;
    public const STATES = ['open', 'sent', 'accepted', 'rejected', 'resolved'];
    private const TRANSITIONS = ['open' => ['sent', 'accepted', 'rejected'], 'sent' => ['accepted', 'rejected'], 'rejected' => ['sent', 'resolved'], 'accepted' => [], 'resolved' => []];
    /** Romanian legal holidays (Codul muncii art. 139; Orthodox Easter and Pentecost by year) that fall on working days matter. */
    private const HOLIDAYS = [
        '2026-01-01', '2026-01-02', '2026-01-06', '2026-01-07', '2026-01-24', '2026-04-10', '2026-04-12', '2026-04-13', '2026-05-01', '2026-05-31', '2026-06-01', '2026-08-15',
        '2026-11-30', '2026-12-01', '2026-12-25', '2026-12-26',
        '2027-01-01', '2027-01-02', '2027-01-06', '2027-01-07', '2027-01-24', '2027-04-30', '2027-05-01', '2027-05-02', '2027-05-03', '2027-06-01', '2027-06-20', '2027-06-21',
        '2027-08-15', '2027-11-30', '2027-12-01', '2027-12-25', '2027-12-26',
    ];

    public function __construct(private PDO $pdo, private Clock $clock, private InvoiceScopePolicy $invoices)
    {
    }

    public function installed(): bool
    {
        try {
            $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'invoice_efactura_tasks'");
            return $statement !== false && (int) $statement->fetchColumn() === 1;
        } catch (PDOException) {
            return false;
        }
    }

    /** The end of the 5th working day after the invoice day, in Bucharest time, as a UTC instant. */
    public static function due(DateTimeImmutable $issuedAt): DateTimeImmutable
    {
        $day = $issuedAt->setTimezone(new DateTimeZone('Europe/Bucharest'))->setTime(0, 0);
        $left = self::WORKING_DAYS;
        while ($left > 0) {
            $day = $day->modify('+1 day');
            if ((int) $day->format('N') < 6 && !in_array($day->format('Y-m-d'), self::HOLIDAYS, true)) {
                $left--;
            }
        }
        return $day->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));
    }

    /** Called when Operations records an applied final invoice (issue or re-issue). Never throws. */
    public function onInvoiceIssued(string $actionUuid, string $orderUuid, string $globalOrderId, string $sourceKey, string $issuerUuid): void
    {
        if (!$this->installed()) {
            return;
        }
        try {
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $this->pdo->prepare('INSERT INTO invoice_efactura_tasks (task_uuid, order_uuid, global_order_id, source_key, invoice_action_uuid, issuer_employee_uuid, issued_at, due_at, state, version, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')->execute([Uuid::v4(), $orderUuid, $globalOrderId, $sourceKey, $actionUuid, $issuerUuid, $now->format('Y-m-d H:i:s.u'),
                self::due($now)->format('Y-m-d H:i:s.u'), 'open', $now->format('Y-m-d H:i:s.u')]);
        } catch (PDOException) {
            // Already recorded for this invoice action (unique), or the table is being created: the queue shows it later.
        }
    }

    /** @return array<string, mixed> the tasks of the actor (issuer) or of the managed shops */
    public function queue(EmployeeIdentity $actor, ?string $source, ?string $state): array
    {
        if (!$this->installed()) {
            throw new ApiException(409, 'CONFIRMARE_CYCLE_SCHEMA_MISSING', 'Migration 043 is not applied yet.');
        }
        if ($state !== null && $state !== 'attention' && !in_array($state, self::STATES, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'state must be attention or one of ' . implode(', ', self::STATES) . '.');
        }
        $managed = array_values(array_filter(['trendhome', 'outletperdele'], fn (string $s): bool => ($source === null || $source === $s) && $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $s)));
        $issuer = array_values(array_filter(['trendhome', 'outletperdele'], fn (string $s): bool => ($source === null || $source === $s) && $this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $s)));
        if (($managed === [] && $issuer === []) || $actor->isRoot) {
            return ['items' => [], 'overdue' => 0, 'managed' => []];
        }
        $where = [];
        $params = [];
        if ($managed !== []) {
            $where[] = 't.source_key IN (' . implode(', ', array_fill(0, count($managed), '?')) . ')';
            array_push($params, ...$managed);
        }
        if ($issuer !== []) {
            $where[] = '(t.issuer_employee_uuid = ? AND t.source_key IN (' . implode(', ', array_fill(0, count($issuer), '?')) . '))';
            $params[] = $actor->employeeUuid;
            array_push($params, ...$issuer);
        }
        $filter = $state === null ? '' : ($state === 'attention' ? " AND t.state IN ('open', 'sent', 'rejected')" : ' AND t.state = ?');
        if ($state !== null && $state !== 'attention') {
            $params[] = $state;
        }
        $statement = $this->pdo->prepare('SELECT t.*, o.order_number, e.display_name AS issuer_name FROM invoice_efactura_tasks t INNER JOIN operational_orders o ON o.order_uuid = t.order_uuid
            INNER JOIN employees e ON e.employee_uuid = t.issuer_employee_uuid WHERE (' . implode(' OR ', $where) . ')' . $filter . ' ORDER BY t.due_at, t.task_uuid LIMIT 300');
        $statement->execute($params);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $items = array_map(fn (array $row): array => $this->present($row, $now), $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'overdue' => count(array_filter($items, static fn (array $i): bool => $i['overdue'])), 'managed' => $managed];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function record(EmployeeIdentity $actor, string $taskUuid, array $input): array
    {
        if (preg_match('/^[0-9a-f-]{36}$/D', $taskUuid) !== 1) {
            throw new ApiException(404, 'EFACTURA_TASK_NOT_FOUND', 'No such e-Factura task.');
        }
        if (array_diff(array_keys($input), ['outcome', 'expectedVersion']) !== [] || !is_string($input['outcome'] ?? null) || !is_int($input['expectedVersion'] ?? null)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'outcome and expectedVersion are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM invoice_efactura_tasks WHERE task_uuid = ? FOR UPDATE');
            $statement->execute([$taskUuid]);
            $task = $statement->fetch(PDO::FETCH_ASSOC);
            $source = is_array($task) ? (string) $task['source_key'] : '';
            $mayRecord = is_array($task) && !$actor->isRoot && ($this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source)
                || ($task['issuer_employee_uuid'] === $actor->employeeUuid && $this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $source)));
            if (!$mayRecord) {
                throw new ApiException(404, 'EFACTURA_TASK_NOT_FOUND', 'No such e-Factura task.');
            }
            if ((int) $task['version'] !== $input['expectedVersion']) {
                throw new ApiException(409, 'EFACTURA_TASK_CHANGED', 'The task changed. Reload it.', ['currentVersion' => (int) $task['version']]);
            }
            if (!in_array($input['outcome'], self::TRANSITIONS[(string) $task['state']] ?? [], true)) {
                throw new ApiException(409, 'EFACTURA_TRANSITION_REFUSED', 'This outcome does not follow the current state.', ['state' => (string) $task['state']]);
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare('UPDATE invoice_efactura_tasks SET state = ?, outcome_code = ?, recorded_by_employee_uuid = ?, recorded_at = ?, version = version + 1 WHERE task_uuid = ? AND version = ?')
                ->execute([$input['outcome'], 'checked_' . $input['outcome'], $actor->employeeUuid, $now, $taskUuid, $input['expectedVersion']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $after = $this->pdo->prepare('SELECT t.*, o.order_number, e.display_name AS issuer_name FROM invoice_efactura_tasks t INNER JOIN operational_orders o ON o.order_uuid = t.order_uuid
            INNER JOIN employees e ON e.employee_uuid = t.issuer_employee_uuid WHERE t.task_uuid = ?');
        $after->execute([$taskUuid]);
        return ['task' => $this->present($after->fetch(PDO::FETCH_ASSOC) ?: [], $now)];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row, string $now): array
    {
        $iso = static fn (?string $v): ?string => $v === null ? null : (new DateTimeImmutable($v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        return [
            'id' => (string) $row['task_uuid'],
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'source' => (string) $row['source_key'],
            'issuer' => (string) $row['issuer_name'],
            'issuedAt' => $iso((string) $row['issued_at']),
            'dueAt' => $iso((string) $row['due_at']),
            'state' => (string) $row['state'],
            'version' => (int) $row['version'],
            'recordedAt' => $iso($row['recorded_at'] === null ? null : (string) $row['recorded_at']),
            'overdue' => in_array($row['state'], ['open', 'rejected'], true) && (string) $row['due_at'] < $now,
            'next' => self::TRANSITIONS[(string) $row['state']] ?? [],
        ];
    }
}
