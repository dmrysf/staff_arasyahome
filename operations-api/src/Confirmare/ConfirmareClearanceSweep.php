<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use PDO;
use Throwable;

/**
 * Stale production clearance (API 2.77.4, owner 2026-11-24, blocked orders TH 65485 and 65773). The clearance flag
 * (order_confirmations.production_cleared) is recomputed by every decision and every shop snapshot. When finance verified
 * the advance, the shop's `cleared` snapshot could arrive while the Arasya finance action was still unsettled
 * (ConfirmareFinance::actionOpen), so the recomputation kept production closed; settling the action later recomputed
 * nothing and the paid order waited for ever.
 *
 * Run by the Confirmare cycle worker every 5 minutes: each approved, not yet cleared order whose shop money state is
 * `cleared` is recomputed under its row lock with ConfirmationClearance::refresh (every condition is checked again; it
 * only opens production when ALL hold, and never closes or changes anything else).
 */
final class ConfirmareClearanceSweep
{
    public const SOURCES = ['trendhome', 'outletperdele'];

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /** @return array{seen: int, cleared: int} */
    public function run(int $limit = 200): array
    {
        $marks = implode(', ', array_fill(0, count(self::SOURCES), '?'));
        $statement = $this->pdo->prepare("SELECT o.order_uuid FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE o.source_key IN ({$marks}) AND o.workflow_cohort = ? AND c.status = 'approved' AND c.production_cleared = 0
              AND o.source_financial_state = 'cleared' AND o.cancellation_state IS NULL AND o.operational_status <> 'unavailable'
            ORDER BY c.decided_at LIMIT " . max(1, min(1000, $limit)));
        $statement->execute([...self::SOURCES, WorkflowCohort::CONFIRMARE]);
        $counts = ['seen' => 0, 'cleared' => 0];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $orderUuid) {
            $counts['seen']++;
            try {
                $this->pdo->beginTransaction();
                $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
                $lock->execute([(string) $orderUuid]);
                if ($lock->fetchColumn() !== false && ConfirmationClearance::refresh($this->pdo, (string) $orderUuid, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'))) {
                    $counts['cleared']++;
                }
                $this->pdo->commit();
            } catch (Throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
        }
        return $counts;
    }
}
