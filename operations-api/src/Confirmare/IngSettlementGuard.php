<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use PDO;

/**
 * The Operations half of the ING advance reconciliation (API 2.58.0, Phase 0, issue #114). READ-ONLY: it issues SELECTs
 * only, holds no lock, writes no row, no audit event, no finance task and no finance action. Nothing in this version
 * applies a bank credit anywhere (AUTO_APPLY_AVAILABLE is a constant false); this class only says, for the shop's shadow
 * proposals, whether Operations would refuse them, and proves that the existing human finance work stays as it is.
 *
 * verdicts(): for each proposed `globalOrderId` (from `wp yd-arasya ing preview` on the shop) the reasons Operations
 * refuses an automatic settlement. Any one is final:
 *   - order_unknown                       no such order;
 *   - source_not_supported                not Trend Home or Outlet Perdele;
 *   - outside_confirmare                  workflow cohort is not `confirmare` (legacy, withdrawn before 1 November, NULL);
 *   - order_unavailable                   cancelled or no longer available in the shop;
 *   - finance_task_exists                 ANY finance verification request of the order, in any purpose or state
 *                                         (advance, second_check, exception; assigned, checking, needs_clarification,
 *                                         decided, withdrawn...): the frozen human workflow, e.g. Cristina Nita's tasks;
 *   - finance_action_exists               ANY finance action recorded through Arasya for the order (a transfer, a
 *                                         decision, a refund, a liability), in any state, including `unknown`;
 *   - finance_attention_open              the shop reports a money attention for the order;
 *   - auto_apply_unavailable              always: this version cannot apply anything.
 * The future apply path (a separate, owner-approved phase) must check the same predicates again at commit time, under
 * the order lock, and refuse when any row appeared in between.
 *
 * snapshot(): an order-by-order digest of the human finance workflow (every finance verification request with its
 * state, version, assignee, checker and outcome; every request event; every finance action; every finance scope), so
 * that a before/after comparison proves the reconciliation changed nothing of it. Counts are grouped by purpose and
 * state; assignees appear only as the first 8 characters of their id.
 */
final readonly class IngSettlementGuard
{
    public const AUTO_APPLY_AVAILABLE = false;
    public const SOURCES = ['trendhome', 'outletperdele'];
    public const LIMIT = 500;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<string> $globalOrderIds
     * @return array{autoApplyAvailable: bool, verdicts: list<array{globalOrderId: string, eligibleForShadow: bool, refusals: list<string>}>}
     */
    public function verdicts(array $globalOrderIds): array
    {
        $ids = array_values(array_unique(array_filter($globalOrderIds, static fn (mixed $id): bool => is_string($id) && preg_match('/^[a-z0-9_-]{1,40}:[A-Za-z0-9_-]{1,64}$/D', $id) === 1)));
        $verdicts = [];
        foreach (array_slice($ids, 0, self::LIMIT) as $id) {
            $verdicts[] = $this->verdict($id);
        }
        return ['autoApplyAvailable' => self::AUTO_APPLY_AVAILABLE, 'verdicts' => $verdicts];
    }

    /** @return array{globalOrderId: string, eligibleForShadow: bool, refusals: list<string>} */
    private function verdict(string $globalOrderId): array
    {
        $order = $this->row('SELECT order_uuid, source_key, workflow_cohort, operational_status, source_financial_attention FROM operational_orders WHERE global_order_id = ?', [$globalOrderId]);
        if ($order === null) {
            return ['globalOrderId' => $globalOrderId, 'eligibleForShadow' => false, 'refusals' => ['order_unknown', 'auto_apply_unavailable']];
        }
        $refusals = [];
        if (!in_array($order['source_key'], self::SOURCES, true)) {
            $refusals[] = 'source_not_supported';
        }
        if ($order['workflow_cohort'] !== 'confirmare') {
            $refusals[] = 'outside_confirmare';
        }
        if ($order['operational_status'] === 'unavailable') {
            $refusals[] = 'order_unavailable';
        }
        if ($this->count('SELECT COUNT(*) FROM finance_verification_requests WHERE order_uuid = ?', [$order['order_uuid']]) > 0) {
            $refusals[] = 'finance_task_exists';
        }
        if ($this->count('SELECT COUNT(*) FROM order_finance_actions WHERE order_uuid = ?', [$order['order_uuid']]) > 0) {
            $refusals[] = 'finance_action_exists';
        }
        if ($order['source_financial_attention'] !== null && $order['source_financial_attention'] !== '') {
            $refusals[] = 'finance_attention_open';
        }
        $eligible = $refusals === [];
        $refusals[] = 'auto_apply_unavailable';
        return ['globalOrderId' => $globalOrderId, 'eligibleForShadow' => $eligible, 'refusals' => $refusals];
    }

    /** @return array{digest: string, requests: array<string, int>, assignees: array<string, int>, events: int, actions: array<string, int>, scopes: int} */
    public function snapshot(): array
    {
        $requests = $this->all('SELECT request_uuid, order_uuid, purpose, state, version, open_order_uuid, assigned_employee_uuid, checker_employee_uuid, recorded_by_employee_uuid, ledger, policy, proforma_series, proforma_number, source_entry_id, evidence_hmac, outcome, reason_code, decided_by_employee_uuid, decided_at, updated_at FROM finance_verification_requests ORDER BY request_uuid');
        $events = $this->all('SELECT event_uuid, request_uuid, version_after, action, actor_employee_uuid, previous_state FROM finance_verification_request_events ORDER BY event_uuid');
        $actions = $this->all('SELECT action_uuid, order_uuid, state FROM order_finance_actions ORDER BY action_uuid');
        $scopes = $this->all('SELECT employee_uuid, capability, source_key FROM employee_finance_scopes ORDER BY employee_uuid, capability, source_key');
        $byState = [];
        $byAssignee = [];
        foreach ($requests as $request) {
            $key = $request['purpose'] . '/' . $request['state'];
            $byState[$key] = ($byState[$key] ?? 0) + 1;
            $assignee = $request['assigned_employee_uuid'] === null ? 'none' : substr((string) $request['assigned_employee_uuid'], 0, 8);
            $byAssignee[$assignee] = ($byAssignee[$assignee] ?? 0) + 1;
        }
        $byAction = [];
        foreach ($actions as $action) {
            $byAction[$action['state']] = ($byAction[$action['state']] ?? 0) + 1;
        }
        ksort($byState);
        ksort($byAssignee);
        ksort($byAction);
        return [
            'digest' => 'sha256:' . hash('sha256', json_encode([$requests, $events, $actions, $scopes], JSON_THROW_ON_ERROR)),
            'requests' => $byState,
            'assignees' => $byAssignee,
            'events' => count($events),
            'actions' => $byAction,
            'scopes' => count($scopes),
        ];
    }

    /** @param list<mixed> $parameters @return array<string, mixed>|null */
    private function row(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<mixed> $parameters */
    private function count(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
