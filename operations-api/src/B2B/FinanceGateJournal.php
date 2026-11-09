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
