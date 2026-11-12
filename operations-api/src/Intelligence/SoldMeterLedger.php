<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Brings the insert-only sold-meter ledger (migration 053) in line with the sold-meter contract of every commercial line.
 *
 * For each line and metric the target is the contract value (MeterContract), or zero while the order is cancelled at the
 * source, excluded (K7) or the line is gone. The ledger already holds a signed sum; only the difference is written, as a
 * new entry. So re-syncs, repeated runs and the later backfill never count anything twice, a cancellation is a dated
 * negative entry and a reactivation or correction a dated new one. Canonical tables are only read. One run at a time
 * (named lock); each order in its own short transaction. `dryRun` computes the same entries and writes nothing.
 */
final readonly class SoldMeterLedger
{
    private const LOCK = 'arasya_sold_meter_ledger';
    private const METRICS = ['sold_fabric' => 'fabricUnits', 'sold_rail' => 'railUnits'];

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** @return array{orders: int, entries: int, byReason: array<string, int>, netUnits: array<string, int>, dryRun: bool} */
    public function sync(bool $dryRun, int $batch = 50, ?string $onlyOrderUuid = null): array
    {
        if ($batch < 1 || $batch > 500 || $this->pdo->inTransaction()) {
            throw new LogicException('Invalid sold-meter ledger context');
        }
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([self::LOCK]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another sold-meter ledger run is in progress.');
        }
        $report = ['orders' => 0, 'entries' => 0, 'byReason' => [], 'netUnits' => ['sold_fabric' => 0, 'sold_rail' => 0], 'dryRun' => $dryRun];
        try {
            $cursor = '';
            do {
                if ($onlyOrderUuid !== null) {
                    $ids = [$onlyOrderUuid];
                } else {
                    $s = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid > ? ORDER BY order_uuid LIMIT ' . $batch);
                    $s->execute([$cursor]);
                    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                }
                foreach ($ids as $id) {
                    $this->pdo->beginTransaction();
                    try {
                        foreach ($this->order((string) $id, $dryRun) as $entry) {
                            $report['entries']++;
                            $report['byReason'][$entry['reason']] = ($report['byReason'][$entry['reason']] ?? 0) + 1;
                            $report['netUnits'][$entry['metric']] += $entry['units'];
                        }
                        $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
                    } catch (Throwable $error) {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                        throw $error;
                    }
                    $cursor = (string) $id;
                    $report['orders']++;
                }
            } while ($onlyOrderUuid === null && count($ids) === $batch);
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
        }
        ksort($report['byReason']);
        return $report;
    }

    /** @return list<array{metric: string, units: int, reason: string}> */
    private function order(string $orderUuid, bool $dryRun): array
    {
        // The canonical order row is locked like every projection; the ledger never writes to it.
        $s = $this->pdo->prepare('SELECT o.order_uuid, o.source_key, o.operational_status, o.version, o.created_at, o.source_submitted_at, o.source_reported_unavailable_at,
                (SELECT 1 FROM production_meter_exclusions x WHERE x.order_uuid = o.order_uuid) AS excluded
            FROM operational_orders o WHERE o.order_uuid = ? FOR UPDATE');
        $s->execute([$orderUuid]);
        $order = $s->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new LogicException('Canonical order missing');
        }
        $lines = MeterLines::forOrders($this->pdo, [$orderUuid])[$orderUuid] ?? [];
        $cancelled = $order['operational_status'] === 'unavailable';
        $excluded = $order['excluded'] !== null;
        $s = $this->pdo->prepare('SELECT metric, item_uuid, SUM(delta_meters) AS total, COUNT(*) AS entries FROM production_meter_ledger WHERE order_uuid = ? GROUP BY metric, item_uuid');
        $s->execute([$orderUuid]);
        $held = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $held[$row['metric']][$row['item_uuid']] = ['units' => self::signedUnits((string) $row['total']), 'entries' => (int) $row['entries']];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $saleAt = (string) ($order['source_submitted_at'] ?? $order['created_at']);
        $entries = [];
        $targets = [];
        foreach ($lines as $line) {
            foreach (self::METRICS as $metric => $field) {
                $value = $line['contract'][$field];
                $targets[$metric][$line['item_uuid']] = ['units' => ($cancelled || $excluded || $value === null) ? 0 : $value, 'line' => $line];
            }
        }
        // Lines that left the order keep their ledger history and are brought to zero.
        foreach ($held as $metric => $items) {
            foreach ($items as $itemUuid => $_) {
                $targets[$metric][$itemUuid] ??= ['units' => 0, 'line' => null];
            }
        }
        $insert = $this->pdo->prepare('INSERT INTO production_meter_ledger (entry_uuid, metric, order_uuid, item_uuid, source_key, product_code_snapshot, variant_snapshot,
                delta_meters, reason, cause_key, formula_version, occurred_at, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($targets as $metric => $items) {
            foreach ($items as $itemUuid => $target) {
                $current = $held[$metric][$itemUuid] ?? ['units' => 0, 'entries' => 0];
                $delta = $target['units'] - $current['units'];
                if ($delta === 0) {
                    continue;
                }
                $line = $target['line'];
                [$reason, $at] = match (true) {
                    $line === null => ['line_removed', $now],
                    $excluded => ['exclusion', $now],
                    $cancelled => ['cancellation', (string) ($order['source_reported_unavailable_at'] ?? $now)],
                    $current['entries'] === 0 => ['sale', $saleAt],
                    $current['units'] === 0 => ['reactivation', $now],
                    default => ['correction', $now],
                };
                // The cause names the exact transition (from -> to at this ledger length), so a concurrent or repeated run
                // that computed the same entry is stopped by the unique key instead of writing it twice.
                $cause = hash('sha256', implode('|', [$metric, $itemUuid, $current['units'], $target['units'], $current['entries']]));
                if (!$dryRun) {
                    $insert->execute([Uuid::v4(), $metric, $orderUuid, $itemUuid, $order['source_key'], $line['product_code'] ?? null, $line['variant'] ?? null,
                        MeterContract::meters($delta), $reason, $cause, MeterContract::VERSION, $at, $now]);
                }
                $entries[] = ['metric' => $metric, 'units' => $delta, 'reason' => $reason];
            }
        }
        return $entries;
    }

    private static function signedUnits(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $units = MeterContract::units(ltrim($value, '-')) ?? 0;
        return $negative ? -$units : $units;
    }
}
