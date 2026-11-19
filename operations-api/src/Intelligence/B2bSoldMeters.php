<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * B2B sold meters (API 2.76.0, migration 057, owner decision 2026-11-19). Commercial sale and production are different
 * facts: a B2B order sells its meters when it is finalized, whether or not it is ever handed off to production.
 *
 * Contract of one commercial line (b2b_order_lines):
 * - `meters` is the whole line's meters as the employee entered it. It is never multiplied by `quantity`.
 * - `item_type = other` is not metered; a line without meters (or zero) is unknown, never zero.
 * Per line the ledger target is those meters while the order is `finalized` and outside the test cohort, else zero. Only
 * the difference to what b2b_sold_meter_ledger already holds is written, as a new dated entry: the sale at the
 * finalization, a cancellation at the cancellation, a cohort decision as an exclusion. Draft orders never sell.
 *
 * The canonical production order of a handed-off B2B order is never a second sale: SoldMeterLedger keeps its target at zero.
 * A cohort order's canonical order is kept out of every production metric by a production_meter_exclusions row; this class
 * adds that row when it is missing (a handoff after the decision included), with an IAM audit event.
 *
 * Runs only inside SoldMeterLedger's named lock, each order in the caller's short transaction. The B2B current account is
 * never read for meters and never written.
 */
final class B2bSoldMeters
{
    public const METRIC = 'sold_fabric';
    public const EXCLUSION_LABEL = 'B2B test cohort (owner decision 2026-11-19)';

    /** @return array{status: string, fabricUnits: int|null} */
    public static function line(string $itemType, mixed $meters): array
    {
        if ($itemType === 'other') {
            return ['status' => MeterContract::NOT_METERED, 'fabricUnits' => null];
        }
        $units = MeterContract::units($meters);
        return $units === null || $units === 0 ? ['status' => MeterContract::UNKNOWN, 'fabricUnits' => null] : ['status' => MeterContract::MEASURED, 'fabricUnits' => $units];
    }

    /** True once migration 057 is applied (an API deploy before its migration must not fail the ledger run). */
    public static function available(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM b2b_sold_meter_ledger LIMIT 0');
            return true;
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1146) {
                return false;
            }
            throw $error;
        }
    }

    /** Commercial orders that can hold or need sold entries, in uuid order. @return list<string> */
    public static function orderIds(PDO $pdo, string $after, int $batch): array
    {
        $s = $pdo->prepare("SELECT order_uuid FROM b2b_orders WHERE order_uuid > ? AND (status <> 'draft' OR order_uuid IN (SELECT b2b_order_uuid FROM b2b_sold_meter_ledger))
            ORDER BY order_uuid LIMIT " . $batch);
        $s->execute([$after]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Commercial orders changed since the instant (order row, cohort decision). @return list<string> */
    public static function changedSince(PDO $pdo, string $since): array
    {
        $s = $pdo->prepare('SELECT order_uuid FROM b2b_orders WHERE updated_at >= ? UNION SELECT b2b_order_uuid FROM b2b_meter_test_cohort WHERE decided_at >= ? ORDER BY 1');
        $s->execute([$since, $since]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The difference entries of one commercial order (written unless dry run). Caller holds the lock and the transaction.
     * @return list<array{metric: string, units: int, reason: string}>
     */
    public static function order(PDO $pdo, string $orderUuid, string $now, bool $dryRun): array
    {
        $s = $pdo->prepare('SELECT b.order_uuid, b.status, b.created_at, b.finalized_at, b.cancelled_at, (c.b2b_order_uuid IS NOT NULL) AS in_cohort
            FROM b2b_orders b LEFT JOIN b2b_meter_test_cohort c ON c.b2b_order_uuid = b.order_uuid WHERE b.order_uuid = ?');
        $s->execute([$orderUuid]);
        $order = $s->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new \LogicException('B2B order missing');
        }
        $selling = $order['status'] === 'finalized' && (int) $order['in_cohort'] === 0;
        $s = $pdo->prepare('SELECT line_uuid, item_type, meters, product_code, variant_snapshot FROM b2b_order_lines WHERE order_uuid = ? ORDER BY line_number');
        $s->execute([$orderUuid]);
        $targets = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $contract = self::line((string) $line['item_type'], $line['meters']);
            $targets[$line['line_uuid']] = ['units' => $selling && $contract['fabricUnits'] !== null ? $contract['fabricUnits'] : 0, 'line' => $line];
        }
        $s = $pdo->prepare('SELECT line_uuid, SUM(delta_meters) AS total, COUNT(*) AS entries FROM b2b_sold_meter_ledger WHERE b2b_order_uuid = ? AND metric = ? GROUP BY line_uuid');
        $s->execute([$orderUuid, self::METRIC]);
        $held = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $held[$row['line_uuid']] = ['units' => self::signedUnits((string) $row['total']), 'entries' => (int) $row['entries']];
            $targets[$row['line_uuid']] ??= ['units' => 0, 'line' => null];
        }
        $insert = $pdo->prepare('INSERT INTO b2b_sold_meter_ledger (entry_uuid, metric, b2b_order_uuid, line_uuid, product_code_snapshot, variant_snapshot, delta_meters, reason, cause_key,
                formula_version, occurred_at, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $entries = [];
        foreach ($targets as $lineUuid => $target) {
            $current = $held[$lineUuid] ?? ['units' => 0, 'entries' => 0];
            $delta = $target['units'] - $current['units'];
            if ($delta === 0) {
                continue;
            }
            $line = $target['line'];
            [$reason, $at] = match (true) {
                $line === null => ['line_removed', $now],
                (int) $order['in_cohort'] === 1 => ['exclusion', $now],
                $order['status'] === 'cancelled' => ['cancellation', (string) ($order['cancelled_at'] ?? $now)],
                $current['entries'] === 0 => ['sale', (string) ($order['finalized_at'] ?? $now)],
                $current['units'] === 0 => ['reactivation', $now],
                default => ['correction', $now],
            };
            $cause = hash('sha256', implode('|', ['b2b', self::METRIC, $lineUuid, $current['units'], $target['units'], $current['entries']]));
            if (!$dryRun) {
                $insert->execute([Uuid::v4(), self::METRIC, $orderUuid, $lineUuid, $line['product_code'] ?? null, $line['variant_snapshot'] ?? null,
                    MeterContract::meters($delta), $reason, $cause, MeterContract::VERSION, $at, $now]);
            }
            $entries[] = ['metric' => self::METRIC, 'units' => $delta, 'reason' => $reason];
        }
        return $entries;
    }

    /**
     * Adds the missing production_meter_exclusions rows for the canonical orders of cohort orders (handed off before or
     * after the decision). Returns the canonical order uuids it excluded (or would exclude, dry run).
     * @return list<string>
     */
    public static function excludeCohortCanonicals(PDO $pdo, string $now, bool $dryRun): array
    {
        $s = $pdo->query("SELECT o.order_uuid, o.global_order_id, c.cohort_key FROM b2b_meter_test_cohort c
            JOIN operational_orders o ON o.source_key = 'b2b' AND o.source_order_id = c.b2b_order_uuid
            LEFT JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid WHERE x.order_uuid IS NULL ORDER BY o.order_uuid");
        $missing = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($dryRun || $missing === []) {
            return array_map(static fn (array $r): string => (string) $r['order_uuid'], $missing);
        }
        $insert = $pdo->prepare("INSERT IGNORE INTO production_meter_exclusions (order_uuid, reason, note, decided_by_label, decided_at) VALUES (?, 'test_order', ?, ?, ?)");
        $done = [];
        foreach ($missing as $row) {
            $insert->execute([$row['order_uuid'], 'B2B test cohort ' . $row['cohort_key'], self::EXCLUSION_LABEL, $now]);
            if ($insert->rowCount() === 1) {
                (new IamAuditLogger($pdo))->record(null, 'production_intelligence.order_excluded', 'operational_order', (string) $row['order_uuid'], (string) $row['global_order_id'],
                    ['reason' => 'test_order', 'decidedBy' => self::EXCLUSION_LABEL, 'cohort' => $row['cohort_key']], 'meter-ledger-' . bin2hex(random_bytes(6)), $now);
                $done[] = (string) $row['order_uuid'];
            }
        }
        return $done;
    }

    private static function signedUnits(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $units = MeterContract::units(ltrim($value, '-')) ?? 0;
        return $negative ? -$units : $units;
    }
}
