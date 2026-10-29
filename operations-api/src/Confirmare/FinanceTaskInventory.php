<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * The finance task inventory (API 2.50.0, owner decision 2026-10-29): points the existing open finance work of the covered
 * orders (FinanceTaskScope) at tasks assigned to a named employee, so that no finance scope needs a source-wide view.
 *
 * - second_check: a bank transfer recorded in the shop through Arasya (order_finance_actions `submit_transfer`, applied,
 *   with its shop entry id) that no second employee decided yet and that the shop still reports as `transfer_pending`.
 *   The task is assigned to its recorder and names the least loaded other employee with `approve` as its checker. The
 *   transfer is never recorded again and never decided here.
 * - exception: an exceptional money state the shop reported for a Confirmare order (refund, additional payment,
 *   reconciliation) or a full card refund requested in Confirmare and not verified yet. Assigned to the least loaded
 *   employee with `record`, with the least loaded other employee with `approve` named as its checker (four eyes for
 *   what is recorded for it); both work it in the order's money panel. Resolved (`resolved`, system) when the state
 *   clears.
 *
 * Nothing is created from a guess: a transfer the shop waits on without an Arasya record of it (no entry id), an order
 * outside the scope or without submission evidence, an order outside Confirmare with a money attention, or an order that
 * already has an open task is reported as skipped with its reason, for a manual review. The inventory never writes the
 * shop, never changes a finance action, an order, a proforma, an invoice, an AWB, a production state or a cohort, and
 * never deletes or rewrites an earlier decision. Idempotent: a second run creates nothing new (one task per shop entry,
 * one open task per order). `plan()` only reads; `apply()` runs each change in its own short transaction with the order
 * locked and an audit event (actor: system).
 */
final readonly class FinanceTaskInventory
{
    public const LIMIT = 500;

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * Read-only reconciliation of the covered orders per source: scope, cohort, money state and open finance work.
     * Counts only (no customer data).
     *
     * @param list<string> $sources
     * @return array<string, array<string, mixed>>
     */
    public function report(array $sources): array
    {
        $from = FinanceTaskScope::fromSql();
        $report = [];
        foreach ($sources as $source) {
            $one = function (string $sql, array $parameters = []) use ($source): int {
                $statement = $this->pdo->prepare($sql);
                $statement->execute([$source, ...$parameters]);
                return (int) $statement->fetchColumn();
            };
            $group = function (string $expression, string $where = '') use ($source, $from): array {
                $statement = $this->pdo->prepare("SELECT {$expression} AS k, COUNT(*) AS n FROM operational_orders o WHERE o.source_key = ? AND o.source_submitted_at >= ? {$where} GROUP BY k ORDER BY k");
                $statement->execute([$source, $from]);
                $rows = [];
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $rows[(string) $row['k']] = (int) $row['n'];
                }
                return $rows;
            };
            $in = 'o.source_key = ? AND o.source_submitted_at >= ?';
            $report[$source] = [
                'orders' => $one('SELECT COUNT(*) FROM operational_orders o WHERE o.source_key = ?'),
                'inScope' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in}", [$from]),
                'beforeScope' => $one('SELECT COUNT(*) FROM operational_orders o WHERE o.source_key = ? AND o.source_submitted_at < ?', [$from]),
                // No submission evidence: never counted in or out silently.
                'undetermined' => $one('SELECT COUNT(*) FROM operational_orders o WHERE o.source_key = ? AND o.source_submitted_at IS NULL AND o.created_at >= ?', [$from]),
                'undeterminedBeforeAcceptance' => $one('SELECT COUNT(*) FROM operational_orders o WHERE o.source_key = ? AND o.source_submitted_at IS NULL AND o.created_at < ?', [$from]),
                // Submitted before the boundary, first accepted in Arasya at or after it: reported apart, workflow unchanged.
                'olderArrivedLater' => $one('SELECT COUNT(*) FROM operational_orders o WHERE o.source_key = ? AND o.source_submitted_at < ? AND o.created_at >= ?', [$from, $from]),
                'cohort' => $group("COALESCE(o.workflow_cohort, 'NULL')"),
                'cohortReason' => $group("CONCAT(COALESCE(o.workflow_cohort, 'NULL'), '/', COALESCE(o.workflow_cohort_reason, 'NULL'))"),
                'submissionEvidence' => $group("COALESCE(o.source_submission_evidence, 'NULL')"),
                'paymentMethod' => $group("COALESCE(o.payment_method, 'unknown')"),
                'financialState' => $group("COALESCE(o.source_financial_state, 'NULL')"),
                'attention' => $group("COALESCE(o.source_financial_attention, 'none')"),
                'closed' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND (o.operational_status = 'unavailable' OR o.source_reported_unavailable_at IS NOT NULL OR o.cancellation_state = 'held'
                    OR o.source_commerce_status_code IN ('cancelled', 'refunded', 'failed', 'trash'))", [$from]),
                'completed' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND (o.source_commerce_status_code = 'completed' OR o.production_completed_at IS NOT NULL)", [$from]),
                'financing' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND o.workflow_cohort_reason = 'financing_legacy'", [$from]),
                'paymentVerified' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND o.source_financial_state = 'cleared'", [$from]),
                'paymentUnknown' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND (o.payment_method IS NULL OR o.source_financial_state IS NULL OR o.source_financial_state = 'unknown')", [$from]),
                'codAdvanceWaiting' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND o.payment_method = 'cod' AND o.source_financial_state IN ('waiting', 'partial')", [$from]),
                'openFinanceActions' => $one("SELECT COUNT(*) FROM order_finance_actions a INNER JOIN operational_orders o ON o.order_uuid = a.order_uuid WHERE {$in} AND a.state IN ('pending', 'unknown')", [$from]),
                'transfersAwaitingSecondCheck' => $one("SELECT COUNT(*) FROM order_finance_actions a INNER JOIN operational_orders o ON o.order_uuid = a.order_uuid WHERE {$in}
                    AND a.action = 'submit_transfer' AND a.state = 'applied' AND a.source_entry_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM order_finance_actions d WHERE d.order_uuid = a.order_uuid AND d.action = 'decide_transfer' AND d.state = 'applied' AND d.source_entry_id = a.source_entry_id)", [$from]),
                'openTasks' => $one("SELECT COUNT(*) FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE {$in} AND r.open_order_uuid IS NOT NULL", [$from]),
                // Nothing for finance to do: money cleared, no attention, no open action, no open task.
                'noFinanceAction' => $one("SELECT COUNT(*) FROM operational_orders o WHERE {$in} AND o.source_financial_state = 'cleared' AND o.source_financial_attention IS NULL
                    AND NOT EXISTS (SELECT 1 FROM order_finance_actions a WHERE a.order_uuid = o.order_uuid AND a.state IN ('pending', 'unknown'))
                    AND NOT EXISTS (SELECT 1 FROM finance_verification_requests r WHERE r.order_uuid = o.order_uuid AND r.open_order_uuid IS NOT NULL)", [$from]),
            ];
        }
        return $report;
    }

    /**
     * What a run would do (read only).
     *
     * @param list<string> $sources
     * @return array{create: list<array<string, mixed>>, resolve: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function plan(array $sources): array
    {
        $create = [];
        $resolve = [];
        $skipped = [];
        if ($sources === []) {
            return ['create' => [], 'resolve' => [], 'skipped' => []];
        }
        $parameters = [];
        $condition = ConfirmareScopePolicy::condition($sources, 'o.source_key', $parameters);

        // 1. Transfers recorded through Arasya and waiting for their second employee.
        $statement = $this->pdo->prepare("SELECT a.order_uuid, a.source_entry_id, a.employee_uuid, o.global_order_id, o.source_key, o.order_number, o.source_submitted_at, o.source_financial_attention
            FROM order_finance_actions a INNER JOIN operational_orders o ON o.order_uuid = a.order_uuid
            WHERE {$condition} AND a.action = 'submit_transfer' AND a.state = 'applied' AND a.source_entry_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM order_finance_actions d WHERE d.order_uuid = a.order_uuid AND d.action = 'decide_transfer' AND d.state = 'applied' AND d.source_entry_id = a.source_entry_id)
              AND NOT EXISTS (SELECT 1 FROM finance_verification_requests r WHERE r.order_uuid = a.order_uuid AND r.source_entry_id = a.source_entry_id)
            ORDER BY a.requested_at, a.order_uuid LIMIT " . self::LIMIT);
        $statement->execute($parameters);
        $entries = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[(string) $row['order_uuid']] = true;
            $item = self::item($row, 'second_check', 'transfer_pending');
            $reason = $this->blocked($row);
            if ($reason === null && $row['source_financial_attention'] !== 'transfer_pending') {
                // The shop no longer waits on it (decided there directly?): a person looks at it, nothing is guessed.
                $reason = 'shop_not_waiting';
            }
            if ($reason !== null) {
                $skipped[] = $item + ['reason' => $reason];
                continue;
            }
            $create[] = $item + ['entryId' => (string) $row['source_entry_id'], 'recorder' => (string) $row['employee_uuid'],
                'checker' => FinanceVerificationRequests::leastLoadedChecker($this->pdo, (string) $row['source_key'], (string) $row['employee_uuid'])];
        }

        // 2. Exceptional money states and card refunds to verify.
        $exceptional = array_values(array_diff(FinanceTaskAccess::EXCEPTIONAL_ATTENTION, ['transfer_pending']));
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.source_key, o.order_number, o.source_submitted_at, o.workflow_cohort, o.source_financial_attention
            FROM operational_orders o WHERE {$condition} AND (o.source_financial_attention IS NOT NULL
               OR EXISTS (SELECT 1 FROM order_source_operations r WHERE r.order_uuid = o.order_uuid AND r.kind = 'card_refund_full' AND r.state = 'applied'))
            ORDER BY o.source_changed_at, o.order_uuid LIMIT " . self::LIMIT);
        try {
            $statement->execute($parameters);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $attention = $row['source_financial_attention'];
            $code = is_string($attention) && in_array($attention, $exceptional, true) ? $attention
                : (FinanceTaskAccess::cardRefundOpen($this->pdo, (string) $row['order_uuid']) ? 'card_refund_verify' : null);
            if ($attention === 'transfer_pending' && !isset($entries[(string) $row['order_uuid']]) && !$this->tracked((string) $row['order_uuid'])) {
                // The shop waits on a transfer Arasya has no record of (no entry id): never a task from a guess.
                $skipped[] = self::item($row, 'second_check', 'transfer_pending') + ['reason' => 'evidence_missing'];
                continue;
            }
            if ($code === null) {
                continue;
            }
            $item = self::item($row, 'exception', $code);
            $reason = $this->blocked($row) ?? ($row['workflow_cohort'] !== 'confirmare' ? 'outside_confirmare' : null);
            if ($reason !== null) {
                $skipped[] = $item + ['reason' => $reason];
                continue;
            }
            $assignee = FinanceVerificationRequests::leastLoadedFinance($this->pdo, (string) $row['source_key'], 'four_eyes');
            // The approver of what the assignee records for it (a refund, a transfer): another named employee.
            $create[] = $item + ['assignee' => $assignee, 'checker' => FinanceVerificationRequests::leastLoadedChecker($this->pdo, (string) $row['source_key'], $assignee)];
        }

        // 3. Exception tasks whose state cleared.
        $statement = $this->pdo->prepare("SELECT r.request_uuid, r.task_code, o.order_uuid, o.global_order_id, o.source_key, o.order_number, o.source_financial_attention
            FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
            WHERE {$condition} AND r.purpose = 'exception' AND r.state IN ('requested', 'assigned') AND r.source_entry_id IS NULL AND r.finance_idempotency_key IS NULL
            ORDER BY r.requested_at LIMIT " . self::LIMIT);
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->stillExceptional($row)) {
                continue;
            }
            $resolve[] = ['requestId' => (string) $row['request_uuid'], 'globalOrderId' => (string) $row['global_order_id'], 'source' => (string) $row['source_key'],
                'orderNumber' => (string) $row['order_number'], 'taskCode' => $row['task_code']];
        }
        return ['create' => $create, 'resolve' => $resolve, 'skipped' => $skipped];
    }

    /**
     * Applies a plan made now (each change re-checked under the order's lock). Returns what was done.
     *
     * @param list<string> $sources
     * @return array{created: int, resolved: int, skipped: list<array<string, mixed>>, plan: array<string, mixed>}
     */
    public function apply(array $sources, string $runId): array
    {
        $plan = $this->plan($sources);
        $created = 0;
        $resolved = 0;
        $skipped = $plan['skipped'];
        $requestId = 'inventory-' . preg_replace('/[^A-Za-z0-9-]/', '', $runId);
        foreach ($plan['create'] as $item) {
            try {
                $this->pdo->beginTransaction();
                $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
                $lock->execute([$item['orderUuid']]);
                $open = $this->pdo->prepare('SELECT 1 FROM finance_verification_requests WHERE open_order_uuid = ?');
                $open->execute([$item['orderUuid']]);
                if ($open->fetchColumn() !== false) {
                    $this->pdo->rollBack();
                    $skipped[] = self::public($item) + ['reason' => 'open_task_exists'];
                    continue;
                }
                $now = $this->now();
                $uuid = $item['purpose'] === 'second_check'
                    ? FinanceVerificationRequests::openSecondCheck($this->pdo, $item['orderUuid'], $item['entryId'], $item['recorder'], $now, $requestId, null, 'inventoried')
                    : $this->openException($item, $now, $requestId);
                $this->pdo->commit();
                if ($uuid === null) {
                    $skipped[] = self::public($item) + ['reason' => 'already_tracked'];
                } else {
                    $created++;
                }
            } catch (Throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $skipped[] = self::public($item) + ['reason' => 'not_recorded'];
            }
        }
        foreach ($plan['resolve'] as $item) {
            try {
                $this->pdo->beginTransaction();
                $statement = $this->pdo->prepare('SELECT r.*, o.source_financial_attention, o.order_uuid AS o_uuid FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE r.request_uuid = ? FOR UPDATE');
                $statement->execute([$item['requestId']]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !in_array($row['state'], ['requested', 'assigned'], true) || $row['source_entry_id'] !== null || $row['finance_idempotency_key'] !== null || $this->stillExceptional($row)) {
                    $this->pdo->rollBack();
                    continue;
                }
                $now = $this->now();
                $version = (int) $row['version'] + 1;
                $this->pdo->prepare("UPDATE finance_verification_requests SET state = 'resolved', version = ?, open_order_uuid = NULL, outcome = 'state_cleared', updated_at = ? WHERE request_uuid = ?")
                    ->execute([$version, $now, $item['requestId']]);
                $this->event((string) $item['requestId'], $version, 'resolved', (string) $row['state'], 'resolved', $row['assigned_employee_uuid'], 'state_cleared', $row['task_code'], $requestId, $now);
                $this->pdo->commit();
                $resolved++;
            } catch (Throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
        }
        return ['created' => $created, 'resolved' => $resolved, 'skipped' => array_map(self::public(...), $skipped), 'plan' => self::publicPlan($plan)];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> the plan without internal ids */
    public static function publicPlan(array $plan): array
    {
        return ['create' => array_map(self::public(...), $plan['create']), 'resolve' => $plan['resolve'], 'skipped' => array_map(self::public(...), $plan['skipped'])];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> order and codes only (no customer data, no entry id) */
    private static function public(array $item): array
    {
        unset($item['orderUuid'], $item['entryId']);
        return $item;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function item(array $row, string $purpose, string $code): array
    {
        return ['purpose' => $purpose, 'taskCode' => $code, 'orderUuid' => (string) $row['order_uuid'], 'globalOrderId' => (string) $row['global_order_id'],
            'source' => (string) $row['source_key'], 'orderNumber' => (string) $row['order_number'],
            'scope' => FinanceTaskScope::of($row['source_submitted_at'] === null ? null : (string) $row['source_submitted_at'])];
    }

    /** @param array<string, mixed> $row why no task may be created for this order now, or null */
    private function blocked(array $row): ?string
    {
        $scope = FinanceTaskScope::of($row['source_submitted_at'] === null ? null : (string) $row['source_submitted_at']);
        if ($scope === FinanceTaskScope::BEFORE_SCOPE) {
            return 'out_of_scope';
        }
        if ($scope === FinanceTaskScope::UNDETERMINED) {
            return 'scope_undetermined';
        }
        $open = $this->pdo->prepare('SELECT 1 FROM finance_verification_requests WHERE open_order_uuid = ?');
        $open->execute([(string) $row['order_uuid']]);
        return $open->fetchColumn() !== false ? 'open_task_exists' : null;
    }

    private function tracked(string $orderUuid): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM finance_verification_requests WHERE open_order_uuid = ?');
        $statement->execute([$orderUuid]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    private function stillExceptional(array $row): bool
    {
        $attention = $row['source_financial_attention'];
        if (is_string($attention) && $attention !== 'transfer_pending' && in_array($attention, FinanceTaskAccess::EXCEPTIONAL_ATTENTION, true)) {
            return true;
        }
        return FinanceTaskAccess::cardRefundOpen($this->pdo, (string) ($row['order_uuid'] ?? $row['o_uuid']));
    }

    /** @param array<string, mixed> $item */
    private function openException(array $item, string $now, string $requestId): ?string
    {
        $uuid = Uuid::v4();
        $assignee = $item['assignee'] ?? null;
        $state = $assignee === null ? 'requested' : 'assigned';
        $statement = $this->pdo->prepare("INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
                requested_by_employee_uuid, requested_at, request_idempotency_key, inventory_key, task_code, ledger, policy, checker_employee_uuid, updated_at)
            VALUES (?, ?, ?, ?, 'exception', ?, 1, ?, ?, ?, NULL, ?, NULL, ?, ?, 'shop', 'four_eyes', ?, ?)");
        $statement->execute([$uuid, $item['orderUuid'], $item['globalOrderId'], $item['source'], $state, $item['orderUuid'], $assignee, $assignee === null ? null : $now, $now,
            'exception:' . $item['orderUuid'] . ':' . $item['taskCode'] . ':' . str_replace(['-', ' ', ':', '.'], '', $now), $item['taskCode'], $item['checker'] ?? null, $now]);
        $this->event($uuid, 1, 'inventoried', null, $state, $assignee, null, (string) $item['taskCode'], $requestId, $now);
        return $uuid;
    }

    private function event(string $requestUuid, int $version, string $action, ?string $previous, string $state, ?string $assignee, ?string $outcome, ?string $reasonCode, string $requestId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO finance_verification_request_events (event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state, new_state, assigned_employee_uuid, outcome, reason_code, policy, idempotency_key, request_id, occurred_at)
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)')->execute([Uuid::v4(), $requestUuid, $version, $action, $previous, $state, $assignee, $outcome, $reasonCode !== null ? substr($reasonCode, 0, 40) : null, substr($requestId, 0, 100), $now]);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
