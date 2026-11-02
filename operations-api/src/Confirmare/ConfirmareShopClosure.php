<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * An order closed in the shop leaves the operator's queue (owner decision 2026-11-02, API 2.56.0).
 *
 * A pending Confirmare order whose shop status is completed, cancelled, refunded, failed or trash (or that Arasya holds
 * as unavailable) is no longer waiting for the operator: someone finished or stopped it in WordPress. Every distribution
 * run (bin/confirmare-distribute.php, each minute) closes it: confirmation `cancelled` without an assignee, open alerts
 * resolved, one system `released` event with reason `shop_closed`. Production stays closed (never approved).
 *
 * An order with a shop action still in flight (a finance, invoice, shop edit, shop operation, reactivation or proforma
 * step pending or unknown) is left alone until that action settles: its outcome is never cut off.
 *
 * Reopened in the shop (back to an open status): the order returns to the queue unassigned, one system `opened` event
 * with reason `shop_reopened`, and distribution hands it out again. Only orders closed by this rule are reopened (their
 * last event says `shop_closed`); orders withdrawn at the 1 November cutoff are `legacy` and never come back.
 */
final class ConfirmareShopClosure
{
    public const CLOSED_STATUSES = ['completed', 'cancelled', 'refunded', 'failed', 'trash'];
    public const REASON_CLOSED = 'shop_closed';
    public const REASON_REOPENED = 'shop_reopened';

    private const IN_FLIGHT = [
        "SELECT 1 FROM order_finance_actions x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown')",
        "SELECT 1 FROM order_invoice_actions x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown')",
        "SELECT 1 FROM order_confirmation_source_edits x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown')",
        "SELECT 1 FROM order_source_operations x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown')",
        "SELECT 1 FROM confirmare_reactivations x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown')",
        "SELECT 1 FROM confirmare_cycle_steps x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown')",
    ];

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /** @return array{closed: int, reopened: int} */
    public function sweep(?string $source, bool $dryRun): array
    {
        $closed = 0;
        $reopened = 0;
        foreach ($this->candidates($source, true) as $orderUuid) {
            if ($this->change($orderUuid, true, $dryRun)) $closed++;
        }
        foreach ($this->candidates($source, false) as $orderUuid) {
            if ($this->change($orderUuid, false, $dryRun)) $reopened++;
        }
        return ['closed' => $closed, 'reopened' => $reopened];
    }

    /** @return list<string> */
    private function candidates(?string $source, bool $close): array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE '
            . $this->condition($close) . ($source === null ? '' : ' AND c.source_key = ?') . ' ORDER BY c.opened_at LIMIT 500');
        $statement->execute($source === null ? [] : [$source]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function condition(bool $close): string
    {
        $closedShop = "(o.source_commerce_status_code IN ('" . implode("', '", self::CLOSED_STATUSES) . "') OR o.operational_status = 'unavailable')";
        if ($close) {
            $idle = implode(' AND ', array_map(static fn (string $sql): string => "NOT EXISTS ({$sql})", self::IN_FLIGHT));
            return "o.workflow_cohort = 'confirmare' AND c.status = 'pending' AND {$closedShop} AND {$idle}";
        }
        return "o.workflow_cohort = 'confirmare' AND c.status = 'cancelled' AND NOT {$closedShop}
            AND (SELECT e.reason_code FROM order_confirmation_events e WHERE e.order_uuid = c.order_uuid ORDER BY e.version_after DESC LIMIT 1) = '" . self::REASON_CLOSED . "'";
    }

    /** Re-checks the order under its row lock and changes it; false when it no longer qualifies (or on a dry run). */
    private function change(string $orderUuid, bool $close, bool $dryRun): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$orderUuid]);
            $statement = $this->pdo->prepare('SELECT c.global_order_id, c.source_key, c.version, c.assigned_employee_uuid FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
                WHERE c.order_uuid = ? AND ' . $this->condition($close) . ' FOR UPDATE');
            $statement->execute([$orderUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $dryRun) {
                $this->pdo->rollBack();
                return is_array($row);
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $version = (int) $row['version'] + 1;
            if ($close) {
                $this->pdo->prepare("UPDATE order_confirmations SET status = 'cancelled', version = ?, decided_at = ?, decided_by_employee_uuid = NULL,
                        assigned_employee_uuid = NULL, assigned_at = NULL, assignment_kind = NULL, updated_at = ? WHERE order_uuid = ?")
                    ->execute([$version, $now, $now, $orderUuid]);
                $this->pdo->prepare('UPDATE confirmare_alerts SET resolved_at = ? WHERE order_uuid = ? AND resolved_at IS NULL')->execute([$now, $orderUuid]);
            } else {
                $this->pdo->prepare("UPDATE order_confirmations SET status = 'pending', version = ?, decided_at = NULL, decided_by_employee_uuid = NULL, updated_at = ? WHERE order_uuid = ?")
                    ->execute([$version, $now, $orderUuid]);
            }
            $this->pdo->prepare("INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, previous_status, new_status,
                    previous_assignee_uuid, new_assignee_uuid, reason_code, version_after, request_id, idempotency_key, occurred_at)
                VALUES (?, ?, ?, ?, ?, 'system', NULL, ?, ?, ?, NULL, ?, ?, NULL, NULL, ?)")
                ->execute([Uuid::v4(), $orderUuid, $row['global_order_id'], $row['source_key'], $close ? 'released' : 'opened', $close ? 'pending' : 'cancelled', $close ? 'cancelled' : 'pending',
                    $close ? $row['assigned_employee_uuid'] : null, $close ? self::REASON_CLOSED : self::REASON_REOPENED, $version, $now]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
