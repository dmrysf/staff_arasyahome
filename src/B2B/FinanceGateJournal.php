<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Support\Uuid;
use PDO;
use Throwable;

/**
 * Insert-only journal of evaluated B2B finance decisions (b2b_finance_gate_decisions, migration 050). Mode off writes
 * nothing. An ALLOWED or WOULD_BLOCK row commits with the order action; a BLOCKED row is written after the refused
 * transaction rolled back, so the refusal stays auditable. This class never updates or deletes a row.
 */
final class FinanceGateJournal
{
    public const RECORDED = ['ALLOWED', 'ALLOWED_BY_CEO_EXCEPTION', 'WOULD_BLOCK', 'BLOCKED'];

    /** @param array<string, mixed> $snapshot a FinanceGate decision */
    public static function record(PDO $pdo, string $orderId, string $companyId, array $snapshot, EmployeeIdentity $actor, string $request): void
    {
        if (!in_array($snapshot['outcome'] ?? null, self::RECORDED, true)) {
            return;
        }
        $pdo->prepare('INSERT INTO b2b_finance_gate_decisions(decision_uuid,order_uuid,company_uuid,phase,mode,outcome,reasons_json,snapshot_json,actor_employee_uuid,actor_name,request_id,decided_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([Uuid::v4(), $orderId, $companyId, $snapshot['phase'], $snapshot['mode'], $snapshot['outcome'],
                OrderStore::json(array_values((array) ($snapshot['reasons'] ?? []))), OrderStore::json($snapshot), $actor->employeeUuid, mb_substr($actor->displayName, 0, 190),
                mb_substr($request, 0, 100), $snapshot['decidedAt']]);
    }

    /**
     * One page of the journal, newest first, for the Finance read (GET /finance/b2b/gate-decisions). The caller checks
     * the permission and validates the filters. Reason codes, outcome and what changed since finalization only: limit,
     * exposure and order amounts of the stored snapshot are never returned.
     * @param array{0: string, 1: string}|null $after (decided_at, decision_uuid) of the last row of the previous page
     * @return array{items: list<array<string, mixed>>, summary: list<array<string, mixed>>, next: array{0: string, 1: string}|null}
     */
    public static function page(PDO $pdo, ?string $outcome, ?string $phase, ?array $after, int $size = 100): array
    {
        $where = [];
        $params = [];
        if ($outcome !== null) {
            $where[] = 'd.outcome = ?';
            $params[] = $outcome;
        }
        if ($phase !== null) {
            $where[] = 'd.phase = ?';
            $params[] = $phase;
        }
        $summary = $pdo->prepare('SELECT d.phase, d.outcome, COUNT(*) AS n FROM b2b_finance_gate_decisions d'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' GROUP BY d.phase, d.outcome ORDER BY d.phase, d.outcome');
        $summary->execute($params);
        if ($after !== null) {
            $where[] = '(d.decided_at < ? OR (d.decided_at = ? AND d.decision_uuid < ?))';
            array_push($params, $after[0], $after[0], $after[1]);
        }
        $s = $pdo->prepare('SELECT d.decision_uuid, d.phase, d.mode, d.outcome, d.reasons_json, d.snapshot_json, d.actor_name, d.request_id, d.decided_at,
                o.order_uuid, o.order_code, c.company_uuid, c.company_code, c.legal_name
            FROM b2b_finance_gate_decisions d JOIN b2b_orders o ON o.order_uuid = d.order_uuid JOIN b2b_companies c ON c.company_uuid = d.company_uuid'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY d.decided_at DESC, d.decision_uuid DESC LIMIT ' . ($size + 1));
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $size) {
            $next = [(string) $rows[$size - 1]['decided_at'], (string) $rows[$size - 1]['decision_uuid']];
            $rows = array_slice($rows, 0, $size);
        }
        return [
            'items' => array_map(static function (array $r): array {
                $snapshot = json_decode((string) $r['snapshot_json'], true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                return ['id' => $r['decision_uuid'], 'orderId' => $r['order_uuid'], 'orderCode' => $r['order_code'], 'companyId' => $r['company_uuid'],
                    'companyCode' => $r['company_code'], 'companyName' => $r['legal_name'], 'phase' => $r['phase'], 'mode' => $r['mode'], 'outcome' => $r['outcome'],
                    'reasons' => array_values((array) json_decode((string) $r['reasons_json'], true)), 'orderType' => $snapshot['orderType'] ?? null,
                    'changedSinceFinalization' => array_values((array) ($snapshot['changedSinceFinalization'] ?? [])),
                    'grandfathered' => isset($snapshot['grandfathered']) ? (bool) $snapshot['grandfathered'] : null,
                    'exceptionId' => $snapshot['exception']['id'] ?? null, 'actor' => $r['actor_name'], 'requestId' => $r['request_id'], 'decidedAt' => (string) $r['decided_at']];
            }, $rows),
            'summary' => array_map(static fn (array $r): array => ['phase' => $r['phase'], 'outcome' => $r['outcome'], 'count' => (int) $r['n']], $summary->fetchAll(PDO::FETCH_ASSOC)),
            'next' => $next,
        ];
    }

    /**
     * Records a refusal outside the rolled-back transaction. A journal failure never turns the 409 into another error:
     * the order is unchanged either way, and the refusal is still returned to the caller.
     * @param array<string, mixed> $snapshot
     */
    public static function recordRefusal(PDO $pdo, string $orderId, string $companyId, array $snapshot, EmployeeIdentity $actor, string $request): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::record($pdo, $orderId, $companyId, $snapshot, $actor, $request);
        } catch (Throwable $e) {
            error_log('b2b_finance_gate_refusal_not_journaled ' . $e::class);
        }
    }
}
