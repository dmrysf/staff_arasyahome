<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Analytics\DateRange;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Production Intelligence V2 reads (API 2.70.0). Read only: nothing here writes a canonical table.
 *
 * Meter definitions (owner decisions K1-K8, 2026-11-12):
 * - sold: the insert-only sold-meter ledger, by entry date (sale, cancellation, correction), fabric and rail apart (K5);
 * - cut: the order's measured line fabric at its first cutting completion. The evidence is always `source_line`: these
 *   are the sold meters of the lines, never a meterage measured at the cutting table (K1). A later cutting completion of
 *   the same order is a recut, reported apart and never added to sold or completed meters;
 * - processed: per employee and stage, one stage completion credits the order's measured fabric once; stages are never
 *   added together;
 * - completed: only an Arasya production completion (K8: a legacy order without one is not completed);
 * - shipped / delivered: not reported in this release (K6: only where the source holds evidence, added later).
 * Trendyol fabric is unknown (K2) and every unknown line is counted as unknown, never as zero.
 */
final readonly class ProductionIntelligenceService
{
    /** K3: the main analysis starts on 2026-10-04 (Europe/Bucharest), the first Arasya production event. */
    public const ANALYSIS_START = '2026-10-04';
    public const SOURCES = ['trendhome', 'outletperdele', 'trendyol', 'b2b'];
    private const MAX_ROWS = 500;

    public function __construct(private PDO $pdo, private ProductionIntelligencePolicy $policy, private Clock $clock)
    {
    }

    /** @param array<string, string> $query */
    public function report(EmployeeIdentity $actor, string $section, array $query, string $requestId): array
    {
        $this->policy->require($actor);
        [$range, $source] = $this->filters($query);
        $bucket = $query['bucket'] ?? 'day';
        if ($section === 'series' && !in_array($bucket, ['day', 'week', 'month', 'year'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Invalid series bucket.');
        }
        if ($section !== 'series' && isset($query['bucket'])) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Unsupported query parameter.');
        }
        $body = $this->snapshot(function () use ($section, $range, $source, $bucket): array {
            $sold = $this->sold($range, $source);
            $events = $this->events($range, $source, null);
            return match ($section) {
                'summary' => $this->summary($sold, $events, $range, $source),
                'products' => $this->products($sold, $events),
                'employees' => $this->employees($events),
                'series' => $this->series($sold, $events, $bucket),
                default => throw new ApiException(404, 'NOT_FOUND', 'Production intelligence route not found.'),
            };
        });
        (new IamAuditLogger($this->pdo))->record($actor, 'production_intelligence.read', 'production_intelligence', $section, 'Production Intelligence',
            ['from' => $query['from'] ?? null, 'to' => $query['to'] ?? null, 'period' => $query['period'] ?? null, 'source' => $source, 'bucket' => $section === 'series' ? $bucket : null],
            $requestId, $this->clock->now()->format('Y-m-d H:i:s.u'));
        return ['range' => $range->metadata() + ['analysisStart' => self::ANALYSIS_START], 'source' => $source, 'contractVersion' => MeterContract::VERSION] + $body;
    }

    /** The signed-in employee's own stage work. The employee comes from the session only, never from the request. */
    public function mine(EmployeeIdentity $actor, array $query): array
    {
        [$range] = $this->filters(array_intersect_key($query, ['period' => 1, 'from' => 1, 'to' => 1]));
        $body = $this->snapshot(function () use ($range, $actor): array {
            $events = $this->events($range, null, $actor->employeeUuid);
            $stages = $this->employees($events)['items'];
            $items = [];
            foreach (array_slice(array_reverse($events), 0, 200) as $event) {
                $items[] = ['orderNumber' => $event['order_number'], 'source' => $event['source_key'], 'stageId' => $event['from_stage_id'], 'kind' => $event['kind'],
                    'occurredAt' => self::iso($event['occurred_at']), 'fabricMeters' => $event['fabric']['measuredLines'] > 0 ? MeterContract::meters($event['fabric']['fabricUnits']) : null,
                    'unknownLines' => $event['fabric']['unknownLines'], 'evidence' => 'source_line'];
            }
            return ['stages' => array_map(static function (array $row): array { unset($row['employeeId'], $row['employeeName']); return $row; }, $stages), 'items' => $items];
        });
        return ['range' => $range->metadata(), 'contractVersion' => MeterContract::VERSION] + $body;
    }

    /** @return array{0: DateRange, 1: string|null} */
    private function filters(array $query): array
    {
        try {
            $range = DateRange::from($query, $this->clock->now());
        } catch (InvalidArgumentException) {
            throw new ApiException(400, 'INVALID_DATE_RANGE', 'Invalid date range.');
        }
        $start = (new DateTimeImmutable(self::ANALYSIS_START, new DateTimeZone('Europe/Bucharest')))->setTimezone(new DateTimeZone('UTC'));
        if ($range->fromUtc < $start) {
            throw new ApiException(400, 'BEFORE_ANALYSIS_START', 'The analysis starts on ' . self::ANALYSIS_START . '.');
        }
        $source = $query['source'] ?? null;
        if ($source !== null && !in_array($source, self::SOURCES, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Unknown source.');
        }
        return [$range, $source];
    }

    /** One consistent read snapshot for the whole report. */
    private function snapshot(callable $read): array
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Report requires an independent snapshot');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $body = $read();
            $this->pdo->commit();
            return $body;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> sold ledger entries in the range (excluded orders carry no entry after their exclusion entry) */
    private function sold(DateRange $range, ?string $source): array
    {
        $sql = 'SELECT l.metric, l.source_key, l.product_code_snapshot, l.variant_snapshot, l.delta_meters, l.reason, l.occurred_at, l.order_uuid, l.item_uuid
            FROM production_meter_ledger l WHERE l.occurred_at >= ? AND l.occurred_at < ?';
        $params = [$range->fromUtc->format('Y-m-d H:i:s'), $range->toUtc->format('Y-m-d H:i:s')];
        if ($source !== null) {
            $sql .= ' AND l.source_key = ?';
            $params[] = $source;
        }
        $s = $this->pdo->prepare($sql . ' ORDER BY l.occurred_at, l.entry_uuid');
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stage and production completions in the range with the order's measured fabric, the kind of work and whether the
     * order is excluded (excluded orders are dropped).
     * @return list<array<string, mixed>>
     */
    private function events(DateRange $range, ?string $source, ?string $employeeUuid): array
    {
        $sql = "SELECT e.event_id, e.order_uuid, e.employee_uuid, e.action, e.from_stage_id, e.to_stage_id, e.production_version_after, e.occurred_at,
                o.source_key, o.order_number, o.service_route, emp.display_name
            FROM order_activity_events e
            JOIN operational_orders o ON o.order_uuid = e.order_uuid
            JOIN employees emp ON emp.employee_uuid = e.employee_uuid
            WHERE e.action IN ('stage_completed', 'production_completed') AND e.occurred_at >= ? AND e.occurred_at < ?";
        $params = [$range->fromUtc->format('Y-m-d H:i:s'), $range->toUtc->format('Y-m-d H:i:s')];
        if ($source !== null) {
            $sql .= ' AND o.source_key = ?';
            $params[] = $source;
        }
        if ($employeeUuid !== null) {
            $sql .= ' AND e.employee_uuid = ?';
            $params[] = $employeeUuid;
        }
        $s = $this->pdo->prepare($sql . ' ORDER BY e.occurred_at, e.event_id');
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $orders = array_values(array_unique(array_column($rows, 'order_uuid')));
        $lines = MeterLines::forOrders($this->pdo, $orders);
        // The first cutting completion of an order (over all time) is its cut; any later one is a recut.
        $firstCut = [];
        foreach (array_chunk($orders, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $c = $this->pdo->prepare("SELECT e.order_uuid, MIN(e.production_version_after) FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid
                WHERE e.order_uuid IN ({$marks}) AND e.action = 'stage_completed' AND e.from_stage_id = 'material-preparation'
                  AND (e.to_stage_id = 'workshop-receiving' OR (e.to_stage_id = 'delivery' AND o.service_route = 'CUT_ONLY')) GROUP BY e.order_uuid");
            $c->execute($chunk);
            foreach ($c->fetchAll(PDO::FETCH_NUM) as [$order, $version]) {
                $firstCut[$order] = (int) $version;
            }
        }
        $events = [];
        foreach ($rows as $row) {
            $fabric = MeterLines::orderFabric($lines[$row['order_uuid']] ?? []);
            if ($fabric['excluded']) {
                continue;
            }
            $isCut = $row['action'] === 'stage_completed' && $row['from_stage_id'] === 'material-preparation'
                && ($row['to_stage_id'] === 'workshop-receiving' || ($row['to_stage_id'] === 'delivery' && $row['service_route'] === 'CUT_ONLY'));
            $kind = match (true) {
                $row['action'] === 'production_completed' => 'completed',
                $isCut && ($firstCut[$row['order_uuid']] ?? null) === (int) $row['production_version_after'] => 'cut',
                $isCut => 'recut',
                default => 'stage',
            };
            $events[] = $row + ['fabric' => $fabric, 'kind' => $kind];
        }
        return $events;
    }

    private function summary(array $sold, array $events, DateRange $range, ?string $source): array
    {
        $totals = ['sold_fabric' => 0, 'sold_rail' => 0];
        $reasons = [];
        foreach ($sold as $entry) {
            $units = self::signed((string) $entry['delta_meters']);
            $totals[$entry['metric']] += $units;
            $reasons[$entry['reason']] = ($reasons[$entry['reason']] ?? 0) + 1;
        }
        $metric = static fn (): array => ['orders' => 0, 'fabricUnits' => 0, 'ordersWithUnknownLines' => 0, 'unknownLines' => 0];
        $by = ['cut' => $metric(), 'recut' => $metric(), 'completed' => $metric()];
        $stageCompletions = 0;
        foreach ($events as $event) {
            if ($event['kind'] === 'stage' || $event['kind'] === 'cut' || $event['kind'] === 'recut') {
                $stageCompletions++;
            }
            if (!isset($by[$event['kind']])) {
                continue;
            }
            $by[$event['kind']]['orders']++;
            $by[$event['kind']]['fabricUnits'] += $event['fabric']['fabricUnits'];
            $by[$event['kind']]['unknownLines'] += $event['fabric']['unknownLines'];
            $by[$event['kind']]['ordersWithUnknownLines'] += $event['fabric']['unknownLines'] > 0 ? 1 : 0;
        }
        $out = static fn (array $m): array => ['orders' => $m['orders'], 'fabricMeters' => MeterContract::meters($m['fabricUnits']),
            'ordersWithUnknownLines' => $m['ordersWithUnknownLines'], 'unknownLines' => $m['unknownLines']];
        return [
            'sold' => ['fabricMeters' => MeterContract::meters($totals['sold_fabric']), 'railMeters' => MeterContract::meters($totals['sold_rail']), 'entriesByReason' => $reasons,
                'syncedThrough' => $this->ledgerWatermark()],
            'cut' => $out($by['cut']) + ['evidence' => 'source_line', 'measuredCutMeters' => null],
            'recut' => $out($by['recut']) + ['evidence' => 'source_line'],
            'processed' => ['stageCompletions' => $stageCompletions],
            'completed' => $out($by['completed']),
            'shipped' => ['available' => false],
            'delivered' => ['available' => false],
            'coverage' => $this->coverage($range, $source),
        ];
    }

    /** The watermark of the automatic ledger run (UTC, ISO-8601), or null while it has never run. */
    private function ledgerWatermark(): ?string
    {
        $at = $this->pdo->query("SELECT watermark_at FROM production_meter_ledger_cursor WHERE cursor_key = 'sold_meters'")->fetchColumn();
        return $at === false || $at === null ? null : (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /** Lines of the orders created in the range by contract status: how much of the sold meterage is known at all. */
    private function coverage(DateRange $range, ?string $source): array
    {
        $sql = 'SELECT order_uuid FROM operational_orders WHERE COALESCE(source_submitted_at, created_at) >= ? AND COALESCE(source_submitted_at, created_at) < ?';
        $params = [$range->fromUtc->format('Y-m-d H:i:s'), $range->toUtc->format('Y-m-d H:i:s')];
        if ($source !== null) {
            $sql .= ' AND source_key = ?';
            $params[] = $source;
        }
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        $counts = [];
        foreach (MeterLines::forOrders($this->pdo, $s->fetchAll(PDO::FETCH_COLUMN)) as $lines) {
            foreach ($lines as $line) {
                $counts[$line['contract']['status']] = ($counts[$line['contract']['status']] ?? 0) + 1;
            }
        }
        ksort($counts);
        return ['linesByStatus' => $counts];
    }

    private function products(array $sold, array $events): array
    {
        $rows = [];
        $key = static fn (string $source, ?string $code, ?string $variant): string => $source . "\x1f" . ($code ?? '') . "\x1f" . ($variant ?? '');
        $row = static fn (string $source, ?string $code, ?string $variant): array => ['source' => $source, 'productCode' => $code, 'variant' => $variant,
            'soldFabricUnits' => 0, 'soldRailUnits' => 0, 'cutFabricUnits' => 0, 'completedFabricUnits' => 0, 'unknownLines' => 0];
        foreach ($sold as $entry) {
            $k = $key($entry['source_key'], $entry['product_code_snapshot'], $entry['variant_snapshot']);
            $rows[$k] ??= $row($entry['source_key'], $entry['product_code_snapshot'], $entry['variant_snapshot']);
            $rows[$k][$entry['metric'] === 'sold_fabric' ? 'soldFabricUnits' : 'soldRailUnits'] += self::signed((string) $entry['delta_meters']);
        }
        $lines = MeterLines::forOrders($this->pdo, array_values(array_unique(array_column(array_filter($events, static fn ($e) => $e['kind'] === 'cut' || $e['kind'] === 'completed'), 'order_uuid'))));
        foreach ($events as $event) {
            if ($event['kind'] !== 'cut' && $event['kind'] !== 'completed') {
                continue;
            }
            foreach ($lines[$event['order_uuid']] ?? [] as $line) {
                $k = $key($event['source_key'], $line['product_code'], $line['variant']);
                $rows[$k] ??= $row($event['source_key'], $line['product_code'], $line['variant']);
                if ($line['contract']['fabricUnits'] !== null) {
                    $rows[$k][$event['kind'] === 'cut' ? 'cutFabricUnits' : 'completedFabricUnits'] += $line['contract']['fabricUnits'];
                } elseif (in_array($line['contract']['status'], [MeterContract::UNKNOWN, MeterContract::UNKNOWN_BASIS, MeterContract::UNKNOWN_MARKETPLACE, MeterContract::CONFLICT], true)) {
                    $rows[$k]['unknownLines']++;
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['soldFabricUnits'], $b['cutFabricUnits'], $a['source'], (string) $a['productCode'], (string) $a['variant']]
            <=> [$a['soldFabricUnits'], $a['cutFabricUnits'], $b['source'], (string) $b['productCode'], (string) $b['variant']]);
        $total = count($rows);
        $items = array_map(static fn (array $r): array => ['source' => $r['source'], 'productCode' => $r['productCode'], 'variant' => $r['variant'],
            'soldFabricMeters' => MeterContract::meters($r['soldFabricUnits']), 'soldRailMeters' => MeterContract::meters($r['soldRailUnits']),
            'cutFabricMeters' => MeterContract::meters($r['cutFabricUnits']), 'completedFabricMeters' => MeterContract::meters($r['completedFabricUnits']),
            'unknownLines' => $r['unknownLines']], array_slice($rows, 0, self::MAX_ROWS));
        return ['items' => $items, 'total' => $total, 'truncated' => $total > self::MAX_ROWS];
    }

    private function employees(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $k = $event['employee_uuid'] . "\x1f" . $event['from_stage_id'];
            $rows[$k] ??= ['employeeId' => $event['employee_uuid'], 'employeeName' => $event['display_name'], 'stageId' => $event['from_stage_id'],
                'completions' => 0, 'cuts' => 0, 'recuts' => 0, 'fabricUnits' => 0, 'completionsWithUnknownLines' => 0, 'completionsWithoutMeters' => 0];
            $r = &$rows[$k];
            $r['completions']++;
            $r['cuts'] += $event['kind'] === 'cut' ? 1 : 0;
            $r['recuts'] += $event['kind'] === 'recut' ? 1 : 0;
            $r['fabricUnits'] += $event['fabric']['fabricUnits'];
            $r['completionsWithUnknownLines'] += $event['fabric']['unknownLines'] > 0 ? 1 : 0;
            $r['completionsWithoutMeters'] += $event['fabric']['measuredLines'] === 0 ? 1 : 0;
            unset($r);
        }
        ksort($rows);
        return ['items' => array_values(array_map(static function (array $r): array {
            $r['fabricMeters'] = MeterContract::meters($r['fabricUnits']);
            $r['evidence'] = 'source_line';
            unset($r['fabricUnits']);
            return $r;
        }, $rows))];
    }

    private function series(array $sold, array $events, string $bucket): array
    {
        $zone = new DateTimeZone('Europe/Bucharest');
        $label = static function (string $utc) use ($zone, $bucket): string {
            $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone);
            return match ($bucket) { 'day' => $local->format('Y-m-d'), 'week' => $local->format('o-\WW'), 'month' => $local->format('Y-m'), 'year' => $local->format('Y') };
        };
        $points = [];
        $point = ['soldFabricUnits' => 0, 'soldRailUnits' => 0, 'cutFabricUnits' => 0, 'completedFabricUnits' => 0, 'cutOrders' => 0, 'completedOrders' => 0];
        foreach ($sold as $entry) {
            $p = $label((string) $entry['occurred_at']);
            $points[$p] ??= $point;
            $points[$p][$entry['metric'] === 'sold_fabric' ? 'soldFabricUnits' : 'soldRailUnits'] += self::signed((string) $entry['delta_meters']);
        }
        foreach ($events as $event) {
            if ($event['kind'] !== 'cut' && $event['kind'] !== 'completed') {
                continue;
            }
            $p = $label((string) $event['occurred_at']);
            $points[$p] ??= $point;
            $points[$p][$event['kind'] . 'FabricUnits'] += $event['fabric']['fabricUnits'];
            $points[$p][$event['kind'] . 'Orders']++;
        }
        ksort($points);
        $items = [];
        foreach ($points as $period => $p) {
            $items[] = ['period' => $period, 'soldFabricMeters' => MeterContract::meters($p['soldFabricUnits']), 'soldRailMeters' => MeterContract::meters($p['soldRailUnits']),
                'cutFabricMeters' => MeterContract::meters($p['cutFabricUnits']), 'completedFabricMeters' => MeterContract::meters($p['completedFabricUnits']),
                'cutOrders' => $p['cutOrders'], 'completedOrders' => $p['completedOrders']];
        }
        return ['bucket' => $bucket, 'items' => $items];
    }

    private static function signed(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $units = MeterContract::units(ltrim($value, '-')) ?? 0;
        return $negative ? -$units : $units;
    }

    private static function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
