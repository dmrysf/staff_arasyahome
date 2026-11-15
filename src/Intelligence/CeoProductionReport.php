<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Analytics\DateRange;
use Arasya\Operations\Production\WorkflowCohort;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * CEO production management reports (API 2.73.0, owner request 2026-11-15): `overview`, `completions` and `skus`.
 * Read only; ProductionIntelligenceService authorizes (Root and the canonical CEO only), opens one consistent snapshot
 * and audits the read.
 *
 * Definitions, every date in Europe/Bucharest:
 * - received: orders whose source submission (or Arasya creation) falls in the range. Excluded orders (K7) never count.
 * - worked: orders with at least one action of a real employee in the range (production, Confirmare, finance, invoice,
 *   shop operation, cancellation control, QR, Trendyol release or preparation). System actions never count.
 * - completed: an order's FIRST Arasya production completion (`production_completed`) in the range. A later completion
 *   of the same order (it went back to production) is a recompletion: listed apart, never counted again, so no line's
 *   meters are counted twice. Completion is never mixed with the intermediate stage completions of employees, which
 *   are reported apart. A completion stays a completion when the order is cancelled later (counted and flagged).
 * - processed: an order's first production stage completion in Arasya in the range (it entered production work).
 * - TH / OP / B2B meters: the sold-meter contract of the line (`source_line` evidence, priced L x quantity; rail apart,
 *   K5). They are never a meterage measured at the cutting table and never physical fabric consumption (K1).
 * - Trendyol panel width: TrendyolPanelWidth (verified lines only). Never fabric, never added to TH / OP meters.
 * - in production / waiting / delayed / stopped: the current state at `generatedAt`, independent of the range, for
 *   orders of the Arasya flow (the established WordPress-managed shop flow is counted apart as `legacyActive`).
 *   Delayed = still active and its current stage was entered more than DELAY_HOURS ago (no promised date exists in the
 *   data, so the stage age is the only honest measure).
 * Unknown meters are counted as unknown lines and quantities, never as zero.
 */
final readonly class CeoProductionReport
{
    public const DELAY_HOURS = 48;
    public const REFRESH_SECONDS = 300;
    public const STATUSES = ['all', 'in_progress', 'completed', 'cancelled'];
    /** Source statuses of an order returned after delivery: Trendyol, WooCommerce. */
    private const RETURNED_STATUSES = ['UnDeliveredAndReturned', 'refunded'];
    private const MAX_SKUS = 1000;
    private const ZONE = 'Europe/Bucharest';

    public function __construct(private PDO $pdo, private DateTimeImmutable $now)
    {
    }

    // ------------------------------------------------------------------------------------------------ overview

    public function overview(DateRange $range, ?string $source): array
    {
        $catalog = new ProductCatalog($this->pdo);
        $received = $this->received($range, $source);
        $completions = $this->completions($range, $source);
        $lines = CeoLines::forOrders($this->pdo, $catalog, [...array_keys($received['orders']), ...array_keys($completions['first'])]);
        $receivedBy = self::bySource();
        foreach ($received['orders'] as $order => $row) {
            $receivedBy[$row['source_key']] ??= ['orders' => 0, 'units' => 0, 'cancelled' => 0];
            $receivedBy[$row['source_key']]['orders']++;
            $receivedBy[$row['source_key']]['cancelled'] += $row['operational_status'] === 'unavailable' ? 1 : 0;
            $receivedBy[$row['source_key']]['units'] += array_sum(array_column($lines[$order] ?? [], 'quantity'));
        }
        $completed = $this->bag();
        $completedBy = [];
        $quality = $this->qualityBag();
        $later = ['cancelled' => $this->bag(), 'returned' => $this->bag()];
        foreach ($completions['first'] as $order => $event) {
            $completedBy[$event['source_key']] ??= $this->bag();
            foreach ($lines[$order] ?? [] as $line) {
                $this->add($completed, $order, $line);
                if ($event['later'] !== null) {
                    $this->add($later[$event['later']], $order, $line);
                }
                $this->add($completedBy[$event['source_key']], $order, $line);
                $this->quality($quality, $line);
            }
        }
        $worked = $this->worked($range, $source);
        return [
            'generatedAt' => self::iso($this->now->format('Y-m-d H:i:s.u')),
            'lastActivityAt' => $this->lastActivity(),
            'refresh' => ['suggestedSeconds' => self::REFRESH_SECONDS],
            'received' => ['orders' => count($received['orders']), 'units' => array_sum(array_column($receivedBy, 'units')),
                'cancelled' => array_sum(array_column($receivedBy, 'cancelled')), 'bySource' => self::trimSources($receivedBy, $source)],
            'worked' => $worked,
            'completed' => $this->bagOut($completed) + ['recompletions' => $completions['recompletions'], 'cancelledAfterCompletion' => $completions['cancelledAfter'],
                'afterCompletion' => ['cancelled' => $this->bagOut($later['cancelled']), 'returned' => $this->bagOut($later['returned'])],
                'bySource' => array_map(fn (array $b): array => $this->bagOut($b), self::orderSources($completedBy))],
            'current' => $this->current($source),
            'quality' => $this->qualityOut($quality),
            'ledger' => $this->ledger($range, $source),
            'definitions' => ['completed' => 'first production completion in the range', 'delayHours' => self::DELAY_HOURS,
                'panelRule' => TrendyolPanelWidth::RULE, 'meterEvidence' => 'source_line', 'categoryCatalog' => $catalog->available()],
        ];
    }

    // --------------------------------------------------------------------------------------------- completions

    public function completionsReport(DateRange $range, ?string $source, string $bucket): array
    {
        $catalog = new ProductCatalog($this->pdo);
        $completions = $this->completions($range, $source);
        $lines = CeoLines::forOrders($this->pdo, $catalog, array_keys($completions['first']));
        $total = $this->bag(); $bySource = []; $byCategory = []; $bySku = []; $byEmployee = []; $points = []; $quality = $this->qualityBag();
        $later = ['cancelled' => $this->bag(), 'returned' => $this->bag()]; $net = $this->bag();
        foreach ($completions['first'] as $order => $event) {
            $s = $event['source_key'];
            $bySource[$s] ??= $this->bag();
            $period = $this->label($event['occurred_at'], $bucket);
            $points[$period] ??= ['all' => $this->bag()];
            $points[$period][$s] ??= $this->bag();
            $byEmployee[$event['employee_uuid']] ??= ['employeeId' => $event['employee_uuid'], 'employeeName' => $event['display_name'], 'bag' => $this->bag()];
            foreach ($lines[$order] ?? [] as $line) {
                $this->add($total, $order, $line);
                $event['later'] === null ? $this->add($net, $order, $line) : $this->add($later[$event['later']], $order, $line);
                $this->add($bySource[$s], $order, $line);
                $this->add($points[$period]['all'], $order, $line);
                $this->add($points[$period][$s], $order, $line);
                $this->add($byEmployee[$event['employee_uuid']]['bag'], $order, $line);
                $this->quality($quality, $line);
                $ck = $line['category'] . "\x1f" . $s;
                $byCategory[$ck] ??= ['category' => $line['category'], 'source' => $s, 'bag' => $this->bag(), 'sources' => []];
                $this->add($byCategory[$ck]['bag'], $order, $line);
                $byCategory[$ck]['sources'][$line['categorySource']] = true;
                $sk = $s . "\x1f" . ($line['product_code'] ?? '');
                $bySku[$sk] ??= $this->skuRow($line);
                $this->skuName($bySku[$sk], $line);
                $this->add($bySku[$sk]['bag'], $order, $line);
                $bySku[$sk]['evidence'][$line['evidence']] = ($bySku[$sk]['evidence'][$line['evidence']] ?? 0) + 1;
            }
        }
        ksort($points);
        $series = [];
        foreach ($points as $period => $p) {
            $series[] = ['period' => $period] + $this->bagOut($p['all']) + ['bySource' => array_map(fn (array $b): array => $this->bagOut($b), self::orderSources(array_diff_key($p, ['all' => 1])))];
        }
        $categories = array_values(array_map(fn (array $c): array => ['category' => $c['category'], 'source' => $c['source'], 'categorySources' => array_keys($c['sources'])] + $this->bagOut($c['bag']), $byCategory));
        usort($categories, static fn (array $a, array $b): int => [$b['orders'], $a['source'], $a['category']] <=> [$a['orders'], $b['source'], $b['category']]);
        $skus = $this->skuOut($bySku);
        $employees = array_values(array_map(fn (array $e): array => ['employeeId' => $e['employeeId'], 'employeeName' => $e['employeeName']] + $this->bagOut($e['bag']), $byEmployee));
        usort($employees, static fn (array $a, array $b): int => [$b['orders'], $a['employeeName']] <=> [$a['orders'], $b['employeeName']]);
        return [
            'bucket' => $bucket,
            'totals' => $this->bagOut($total) + ['recompletions' => $completions['recompletions'], 'cancelledAfterCompletion' => $completions['cancelledAfter']],
            'afterCompletion' => ['cancelled' => $this->bagOut($later['cancelled']), 'returned' => $this->bagOut($later['returned']), 'net' => $this->bagOut($net),
                'rule' => 'state at report time; a completion stays counted, net leaves out orders cancelled or returned afterwards'],
            'bySource' => array_map(fn (array $b): array => $this->bagOut($b), self::orderSources($bySource)),
            'byCategory' => $categories,
            'bySku' => $skus,
            'completionCredits' => $employees,
            'stageWork' => $this->stageWork($range, $source),
            'series' => $series,
            'quality' => $this->qualityOut($quality),
            'definitions' => ['completed' => 'first production completion in the range', 'stageWork' => 'intermediate stage completions, never added to completions',
                'panelRule' => TrendyolPanelWidth::RULE, 'meterEvidence' => 'source_line', 'categoryCatalog' => $catalog->available()],
        ];
    }

    // ---------------------------------------------------------------------------------------------------- skus

    /** @param array{category: ?string, code: ?string, variant: ?string, status: string, sku: ?string} $filters */
    public function skus(DateRange $range, ?string $source, array $filters): array
    {
        $catalog = new ProductCatalog($this->pdo);
        $received = $this->received($range, $source)['orders'];
        $completions = $this->completions($range, $source)['first'];
        $processed = $this->processed($range, $source);
        $status = $this->orderStatus([...array_keys($received), ...array_keys($completions), ...array_keys($processed)]);
        $lines = CeoLines::forOrders($this->pdo, $catalog, array_keys($status));
        $match = function (array $line, string $order) use ($filters, $status): bool {
            if ($filters['status'] !== 'all' && ($status[$order] ?? null) !== $filters['status']) {
                return false;
            }
            if ($filters['category'] !== null && $line['category'] !== $filters['category']) {
                return false;
            }
            if ($filters['sku'] !== null && mb_strtolower((string) $line['product_code']) !== mb_strtolower($filters['sku'])) {
                return false;
            }
            if ($filters['code'] !== null && !str_contains(mb_strtolower((string) $line['product_code'] . ' ' . $line['name']), mb_strtolower($filters['code']))) {
                return false;
            }
            return $filters['variant'] === null || str_contains(mb_strtolower((string) $line['variant']), mb_strtolower($filters['variant']));
        };
        $rows = []; $categories = []; $days = []; $months = [];
        $sets = ['received' => $received, 'processed' => $processed, 'completed' => $completions];
        foreach ($sets as $population => $orders) {
            foreach ($orders as $order => $event) {
                foreach ($lines[$order] ?? [] as $line) {
                    if ($line['excluded']) {
                        continue;
                    }
                    $categories[$line['category']] = true;
                    if (!$match($line, $order)) {
                        continue;
                    }
                    $k = $line['source_key'] . "\x1f" . ($line['product_code'] ?? '');
                    $rows[$k] ??= $this->skuRow($line) + ['received' => $this->bag(), 'processed' => $this->bag()];
                    $this->skuName($rows[$k], $line);
                    $this->add($rows[$k][$population === 'completed' ? 'bag' : $population], $order, $line);
                    $rows[$k]['variants'][(string) $line['variant']] = true;
                    if ($population !== 'received') {
                        $rows[$k]['evidence'][$line['evidence']] = ($rows[$k]['evidence'][$line['evidence']] ?? 0) + 1;
                    }
                    if ($filters['sku'] !== null) {
                        $at = $population === 'received' ? $event['submitted_at'] : $event['occurred_at'];
                        $day = $this->label($at, 'day');
                        $month = $this->label($at, 'month');
                        $days[$day] ??= ['received' => $this->bag(), 'processed' => $this->bag(), 'completed' => $this->bag()];
                        $months[$month] ??= ['received' => $this->bag(), 'processed' => $this->bag(), 'completed' => $this->bag()];
                        $this->add($days[$day][$population], $order, $line);
                        $this->add($months[$month][$population], $order, $line);
                    }
                }
            }
        }
        $items = [];
        foreach ($rows as $row) {
            $r = $this->bagOut($row['received']);
            $p = $this->bagOut($row['processed']);
            $c = $this->bagOut($row['bag']);
            $items[] = ['source' => $row['source'], 'productCode' => $row['productCode'], 'name' => $row['name'], 'category' => $row['category'], 'categorySource' => $row['categorySource'],
                'variants' => count($row['variants'] ?? []),
                'orders' => $r['orders'], 'units' => $r['units'], 'lines' => $r['lines'],
                'processedOrders' => $p['orders'], 'processedFabricMeters' => $p['fabricMeters'], 'processedRailMeters' => $p['railMeters'], 'processedPanelWidthMeters' => $p['panelWidthMeters'],
                'completedOrders' => $c['orders'], 'completedUnits' => $c['units'], 'completedFabricMeters' => $c['fabricMeters'], 'completedRailMeters' => $c['railMeters'], 'completedPanelWidthMeters' => $c['panelWidthMeters'],
                'unknownLines' => $r['unknownLines'] + $p['unknownLines'] + $c['unknownLines'], 'unknownUnits' => $r['unknownUnits'] + $p['unknownUnits'] + $c['unknownUnits'],
                'unknownByPopulation' => ['received' => $r['unknownLines'], 'processed' => $p['unknownLines'], 'completed' => $c['unknownLines']],
                'evidence' => $row['evidence'] ?? [],
                '_sort' => [MeterContract::units($p['fabricMeters']) + MeterContract::units($p['panelWidthMeters']), MeterContract::units($c['fabricMeters']) + MeterContract::units($c['panelWidthMeters']), $r['units']]];
        }
        usort($items, static fn (array $a, array $b): int => [...$b['_sort'], $a['source'], (string) $a['productCode']] <=> [...$a['_sort'], $b['source'], (string) $b['productCode']]);
        $total = count($items);
        $items = array_map(static function (array $i): array { unset($i['_sort']); return $i; }, array_slice($items, 0, self::MAX_SKUS));
        $categoryList = array_keys($categories);
        sort($categoryList);
        $out = ['filters' => $filters, 'items' => $items, 'total' => $total, 'truncated' => $total > self::MAX_SKUS, 'categories' => $categoryList,
            'definitions' => ['orders' => 'orders received in the range (order date)', 'processed' => 'first production stage completion in the range',
                'completed' => 'first production completion in the range', 'status' => 'the order state at report time', 'panelRule' => TrendyolPanelWidth::RULE,
                'categoryCatalog' => $catalog->available()]];
        if ($filters['sku'] !== null) {
            $series = function (array $points): array {
                ksort($points);
                $result = [];
                foreach ($points as $period => $p) {
                    $result[] = ['period' => $period, 'received' => $this->bagOut($p['received']), 'processed' => $this->bagOut($p['processed']), 'completed' => $this->bagOut($p['completed'])];
                }
                return $result;
            };
            $out['detail'] = ['day' => $series($days), 'month' => $series($months)];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ readers

    /** @return array{orders: array<string, array{source_key: string, operational_status: string, submitted_at: string}>} */
    private function received(DateRange $range, ?string $source): array
    {
        $sql = "SELECT o.order_uuid, o.source_key, o.operational_status, COALESCE(o.source_submitted_at, o.created_at) AS submitted_at FROM operational_orders o
            LEFT JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid
            WHERE x.order_uuid IS NULL AND COALESCE(o.source_submitted_at, o.created_at) >= ? AND COALESCE(o.source_submitted_at, o.created_at) < ?";
        $s = $this->pdo->prepare($sql . ($source !== null ? ' AND o.source_key = ?' : ''));
        $s->execute([...$this->bounds($range), ...($source !== null ? [$source] : [])]);
        $orders = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders[$row['order_uuid']] = $row;
        }
        return ['orders' => $orders];
    }

    /**
     * First production completion per order in the range, recompletions apart.
     * @return array{first: array<string, array<string, mixed>>, recompletions: array{orders: int, events: int}, cancelledAfter: int}
     */
    private function completions(DateRange $range, ?string $source): array
    {
        $sql = "SELECT DISTINCT e.order_uuid FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid
            LEFT JOIN production_meter_exclusions x ON x.order_uuid = e.order_uuid
            WHERE e.action = 'production_completed' AND e.occurred_at >= ? AND e.occurred_at < ? AND x.order_uuid IS NULL";
        $s = $this->pdo->prepare($sql . ($source !== null ? ' AND o.source_key = ?' : ''));
        $s->execute([...$this->bounds($range), ...($source !== null ? [$source] : [])]);
        $orders = $s->fetchAll(PDO::FETCH_COLUMN);
        [$from, $to] = $this->bounds($range);
        $first = []; $reOrders = []; $reEvents = 0; $cancelled = 0;
        foreach (array_chunk($orders, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $e = $this->pdo->prepare("SELECT e.event_id, e.order_uuid, e.employee_uuid, e.occurred_at, o.source_key, o.operational_status, o.source_commerce_status_code, emp.display_name
                FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid JOIN employees emp ON emp.employee_uuid = e.employee_uuid
                WHERE e.action = 'production_completed' AND e.order_uuid IN ({$marks}) ORDER BY e.order_uuid, e.occurred_at, e.event_id");
            $e->execute($chunk);
            $seen = [];
            foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isFirst = !isset($seen[$row['order_uuid']]);
                $seen[$row['order_uuid']] = true;
                $inRange = $row['occurred_at'] >= $from && $row['occurred_at'] < $to;
                if (!$inRange) {
                    continue;
                }
                if ($isFirst) {
                    $first[$row['order_uuid']] = $row + ['later' => self::laterState($row)];
                    $cancelled += $first[$row['order_uuid']]['later'] === 'cancelled' ? 1 : 0;
                } else {
                    $reOrders[$row['order_uuid']] = true;
                    $reEvents++;
                }
            }
        }
        return ['first' => $first, 'recompletions' => ['orders' => count($reOrders), 'events' => $reEvents], 'cancelledAfter' => $cancelled];
    }

    /**
     * What happened to a completed order afterwards, at report time: `returned` (Trendyol UnDeliveredAndReturned, web shop
     * refunded), `cancelled` (withdrawn by its source, Trendyol Cancelled) or null. The completion itself stays counted.
     */
    private static function laterState(array $row): ?string
    {
        $commerce = (string) ($row['source_commerce_status_code'] ?? '');
        return match (true) {
            in_array($commerce, self::RETURNED_STATUSES, true) => 'returned',
            $row['operational_status'] === 'unavailable' || $commerce === 'Cancelled' => 'cancelled',
            default => null,
        };
    }

    /** Orders whose first production stage completion (they entered production work) is in the range. @return array<string, array<string, mixed>> */
    private function processed(DateRange $range, ?string $source): array
    {
        $sql = "SELECT DISTINCT e.order_uuid FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid
            WHERE e.action = 'stage_completed' AND e.occurred_at >= ? AND e.occurred_at < ?";
        $s = $this->pdo->prepare($sql . ($source !== null ? ' AND o.source_key = ?' : ''));
        $s->execute([...$this->bounds($range), ...($source !== null ? [$source] : [])]);
        [$from, $to] = $this->bounds($range);
        $result = [];
        foreach (array_chunk($s->fetchAll(PDO::FETCH_COLUMN), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $f = $this->pdo->prepare("SELECT order_uuid, MIN(occurred_at) AS occurred_at FROM order_activity_events WHERE action = 'stage_completed' AND order_uuid IN ({$marks}) GROUP BY order_uuid");
            $f->execute($chunk);
            foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['occurred_at'] >= $from && $row['occurred_at'] < $to) {
                    $result[$row['order_uuid']] = $row;
                }
            }
        }
        return $result;
    }

    /** @param list<string> $orders @return array<string, string> order => in_progress|completed|cancelled */
    private function orderStatus(array $orders): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($orders)), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->pdo->prepare("SELECT order_uuid, production_completed_at, operational_status FROM operational_orders WHERE order_uuid IN ({$marks})");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['order_uuid']] = match (true) {
                    $row['production_completed_at'] !== null => 'completed',
                    $row['operational_status'] === 'unavailable' => 'cancelled',
                    default => 'in_progress',
                };
            }
        }
        return $result;
    }

    /** Distinct orders with an action of a real employee in the range, in total, per source and per area. */
    private function worked(DateRange $range, ?string $source): array
    {
        $parts = [
            'production' => 'SELECT order_uuid AS order_uuid, occurred_at AS acted_at, employee_uuid AS actor_uuid FROM order_activity_events',
            'confirmare' => "SELECT order_uuid AS order_uuid, occurred_at AS acted_at, actor_employee_uuid AS actor_uuid FROM order_confirmation_events WHERE actor_kind = 'employee'",
            'finance' => 'SELECT order_uuid AS order_uuid, requested_at AS acted_at, employee_uuid AS actor_uuid FROM order_finance_actions',
            'invoice' => 'SELECT order_uuid AS order_uuid, requested_at AS acted_at, employee_uuid AS actor_uuid FROM order_invoice_actions',
            'shop_operation' => 'SELECT order_uuid AS order_uuid, requested_at AS acted_at, employee_uuid AS actor_uuid FROM order_source_operations',
            'cancellation' => 'SELECT order_uuid AS order_uuid, occurred_at AS acted_at, actor_employee_uuid AS actor_uuid FROM order_cancellation_events',
            'qr' => 'SELECT order_uuid AS order_uuid, occurred_at AS acted_at, actor_employee_uuid AS actor_uuid FROM production_qr_events',
            'trendyol' => 'SELECT released_order_uuid AS order_uuid, released_at AS acted_at, released_by_employee_uuid AS actor_uuid FROM trendyol_packages WHERE released_order_uuid IS NOT NULL
                UNION ALL SELECT p.released_order_uuid, l.prepared_at, l.prepared_by_employee_uuid FROM trendyol_package_lines l JOIN trendyol_packages p ON p.package_id = l.package_id WHERE p.released_order_uuid IS NOT NULL',
        ];
        [$from, $to] = $this->bounds($range);
        $orders = []; $byArea = []; $bySource = self::bySource();
        foreach ($parts as $area => $inner) {
            $s = $this->pdo->prepare("SELECT DISTINCT a.order_uuid, o.source_key FROM ({$inner}) a
                JOIN operational_orders o ON o.order_uuid = a.order_uuid JOIN employees emp ON emp.employee_uuid = a.actor_uuid
                LEFT JOIN production_meter_exclusions x ON x.order_uuid = a.order_uuid
                WHERE a.acted_at >= ? AND a.acted_at < ? AND x.order_uuid IS NULL" . ($source !== null ? ' AND o.source_key = ?' : ''));
            $s->execute([$from, $to, ...($source !== null ? [$source] : [])]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $byArea[$area] = count($rows);
            foreach ($rows as $row) {
                $orders[$row['order_uuid']] = $row['source_key'];
            }
        }
        foreach ($orders as $sourceKey) {
            $bySource[$sourceKey] ??= ['orders' => 0, 'units' => 0, 'cancelled' => 0];
            $bySource[$sourceKey]['orders']++;
        }
        return ['orders' => count($orders), 'bySource' => array_map(static fn (array $r): int => $r['orders'], self::trimSources($bySource, $source)), 'byArea' => $byArea];
    }

    /** The current state of the Arasya flow, independent of the range. */
    private function current(?string $source): array
    {
        $legacy = WorkflowCohort::legacyShopSql($this->pdo);
        $scope = ($source !== null ? ' AND o.source_key = :source' : '');
        $params = ($source !== null ? ['source' => $source] : []);
        $base = "FROM operational_orders o LEFT JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid
            WHERE o.production_completed_at IS NULL AND o.operational_status <> 'unavailable' AND x.order_uuid IS NULL" . $scope;
        $delayAt = $this->now->modify('-' . self::DELAY_HOURS . ' hours')->format('Y-m-d H:i:s.u');
        $s = $this->pdo->prepare("SELECT o.source_key, o.production_stage_id,
                (o.production_stopped_at IS NOT NULL) AS is_stopped, (COALESCE(o.production_changed_at, o.created_at) < :delay) AS is_delayed, ({$legacy}) AS is_legacy, COUNT(*) AS n
            {$base} GROUP BY 1, 2, 3, 4, 5");
        $s->execute($params + ['delay' => $delayAt]);
        $out = ['inProduction' => 0, 'waiting' => 0, 'delayed' => 0, 'delayedWaiting' => 0, 'delayedInProduction' => 0, 'stopped' => 0, 'legacyActive' => 0,
            'bySource' => [], 'byStage' => [], 'delayedByStage' => []];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = (int) $row['n'];
            if ((int) $row['is_legacy'] === 1) {
                $out['legacyActive'] += $n;
                continue;
            }
            if ((int) $row['is_stopped'] === 1) {
                $out['stopped'] += $n;
                continue;
            }
            $waiting = $row['production_stage_id'] === 'waiting';
            $key = $waiting ? 'waiting' : 'inProduction';
            $out[$key] += $n;
            $out['bySource'][$row['source_key']] ??= ['inProduction' => 0, 'waiting' => 0, 'delayed' => 0];
            $out['bySource'][$row['source_key']][$key] += $n;
            $out['byStage'][$row['production_stage_id']] = ($out['byStage'][$row['production_stage_id']] ?? 0) + $n;
            if ((int) $row['is_delayed'] === 1) {
                $out['delayed'] += $n;
                $out[$waiting ? 'delayedWaiting' : 'delayedInProduction'] += $n;
                $out['bySource'][$row['source_key']]['delayed'] += $n;
                $out['delayedByStage'][$row['production_stage_id']] = ($out['delayedByStage'][$row['production_stage_id']] ?? 0) + $n;
            }
        }
        $order = array_flip(array_keys(\Arasya\Operations\Production\CanonicalProductionWorkflowContract::STAGES));
        foreach (['byStage', 'delayedByStage'] as $k) {
            uksort($out[$k], static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99) ?: strcmp($a, $b));
        }
        $out['bySource'] = self::orderSources($out['bySource']);
        return $out + ['asOf' => self::iso($this->now->format('Y-m-d H:i:s.u')), 'delayHours' => self::DELAY_HOURS];
    }

    /** Employees' intermediate stage completions in the range, per employee and stage. Never added to completions. */
    private function stageWork(DateRange $range, ?string $source): array
    {
        $s = $this->pdo->prepare("SELECT e.employee_uuid, emp.display_name, e.from_stage_id, COUNT(*) AS completions, COUNT(DISTINCT e.order_uuid) AS orders
            FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid JOIN employees emp ON emp.employee_uuid = e.employee_uuid
            LEFT JOIN production_meter_exclusions x ON x.order_uuid = e.order_uuid
            WHERE e.action = 'stage_completed' AND e.occurred_at >= ? AND e.occurred_at < ? AND x.order_uuid IS NULL" . ($source !== null ? ' AND o.source_key = ?' : '') . '
            GROUP BY e.employee_uuid, emp.display_name, e.from_stage_id ORDER BY emp.display_name, e.from_stage_id');
        $s->execute([...$this->bounds($range), ...($source !== null ? [$source] : [])]);
        return array_map(static fn (array $r): array => ['employeeId' => $r['employee_uuid'], 'employeeName' => $r['display_name'], 'stageId' => $r['from_stage_id'],
            'completions' => (int) $r['completions'], 'orders' => (int) $r['orders']], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Ledger-dependent figures: unavailable (never zero) while the ledger holds no entry. */
    private function ledger(DateRange $range, ?string $source): array
    {
        $entries = (int) $this->pdo->query('SELECT COUNT(*) FROM production_meter_ledger')->fetchColumn();
        $at = $this->pdo->query("SELECT watermark_at FROM production_meter_ledger_cursor WHERE cursor_key = 'sold_meters'")->fetchColumn();
        $syncedThrough = $at === false || $at === null ? null : self::iso((string) $at);
        $state = $syncedThrough !== null ? 'automatic' : ($entries > 0 ? 'manual' : 'empty');
        if ($state === 'empty') {
            return ['state' => 'empty', 'available' => false, 'syncedThrough' => null, 'ordered' => null, 'verifiedSale' => null];
        }
        $s = $this->pdo->prepare('SELECT l.metric, l.delta_meters, ' . PaymentEvidence::COLUMNS . ' FROM production_meter_ledger l LEFT JOIN operational_orders o ON o.order_uuid = l.order_uuid
            WHERE l.occurred_at >= ? AND l.occurred_at < ?' . ($source !== null ? ' AND l.source_key = ?' : ''));
        $s->execute([...$this->bounds($range), ...($source !== null ? [$source] : [])]);
        $ordered = ['sold_fabric' => 0, 'sold_rail' => 0]; $verified = $ordered;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $v = (string) $row['delta_meters'];
            $units = str_starts_with($v, '-') ? -(MeterContract::units(substr($v, 1)) ?? 0) : (MeterContract::units($v) ?? 0);
            $ordered[$row['metric']] += $units;
            if (PaymentEvidence::verifiedSale(PaymentEvidence::classify($row))) {
                $verified[$row['metric']] += $units;
            }
        }
        $m = static fn (array $u): array => ['fabricMeters' => MeterContract::meters($u['sold_fabric']), 'railMeters' => MeterContract::meters($u['sold_rail'])];
        return ['state' => $state, 'available' => true, 'syncedThrough' => $syncedThrough, 'ordered' => $m($ordered), 'verifiedSale' => $m($verified)];
    }

    private function lastActivity(): ?string
    {
        $at = $this->pdo->query('SELECT GREATEST(COALESCE((SELECT MAX(occurred_at) FROM order_activity_events), \'1970-01-01\'), COALESCE((SELECT MAX(created_at) FROM operational_orders), \'1970-01-01\'))')->fetchColumn();
        return $at === false || str_starts_with((string) $at, '1970') ? null : self::iso((string) $at);
    }

    // ------------------------------------------------------------------------------------------- accumulation

    private function bag(): array
    {
        return ['orders' => [], 'lines' => 0, 'units' => 0, 'fabric' => 0, 'rail' => 0, 'panel' => 0, 'measuredLines' => 0, 'notMeteredLines' => 0,
            'unknownLines' => 0, 'unknownUnits' => 0, 'panelLines' => 0, 'panelUnknownLines' => 0, 'panelUnknownUnits' => 0];
    }

    /** Adds one line once (the caller passes each line of each order once per population). */
    private function add(array &$bag, string $order, array $line): void
    {
        if ($line['excluded']) {
            return;
        }
        $bag['orders'][$order] = true;
        $bag['lines']++;
        $bag['units'] += $line['quantity'];
        if ($line['source_key'] === 'trendyol') {
            if ($line['panel'] !== null && $line['panel']['status'] === TrendyolPanelWidth::VERIFIED) {
                $bag['panel'] += $line['panel']['widthUnits'];
                $bag['panelLines']++;
            } else {
                $bag['panelUnknownLines']++;
                $bag['panelUnknownUnits'] += $line['quantity'];
            }
            return;
        }
        $c = $line['contract'];
        if ($c['fabricUnits'] !== null || $c['railUnits'] !== null) {
            $bag['fabric'] += $c['fabricUnits'] ?? 0;
            $bag['rail'] += $c['railUnits'] ?? 0;
            $bag['measuredLines']++;
        } elseif ($c['status'] === MeterContract::NOT_METERED) {
            $bag['notMeteredLines']++;
        } elseif (in_array($c['status'], CeoLines::UNKNOWN_FABRIC, true)) {
            $bag['unknownLines']++;
            $bag['unknownUnits'] += $line['quantity'];
        }
    }

    private function bagOut(array $bag): array
    {
        return ['orders' => count($bag['orders']), 'lines' => $bag['lines'], 'units' => $bag['units'],
            'fabricMeters' => MeterContract::meters($bag['fabric']), 'railMeters' => MeterContract::meters($bag['rail']), 'measuredLines' => $bag['measuredLines'],
            'notMeteredLines' => $bag['notMeteredLines'], 'unknownLines' => $bag['unknownLines'], 'unknownUnits' => $bag['unknownUnits'],
            'panelWidthMeters' => MeterContract::meters($bag['panel']), 'panelLines' => $bag['panelLines'], 'panelUnknownLines' => $bag['panelUnknownLines'], 'panelUnknownUnits' => $bag['panelUnknownUnits']];
    }

    private function qualityBag(): array
    {
        return ['shopLines' => [], 'panel' => [], 'categoryKnown' => 0, 'categoryUnknown' => 0, 'evidence' => []];
    }

    private function quality(array &$q, array $line): void
    {
        if ($line['excluded']) {
            return;
        }
        if ($line['source_key'] === 'trendyol') {
            $status = $line['panel']['status'] ?? TrendyolPanelWidth::NO_MEASURE;
            $q['panel'][$status] = ($q['panel'][$status] ?? 0) + 1;
        } else {
            $q['shopLines'][$line['contract']['status']] = ($q['shopLines'][$line['contract']['status']] ?? 0) + 1;
        }
        $q[$line['category'] === ProductCatalog::UNKNOWN ? 'categoryUnknown' : 'categoryKnown']++;
        $q['evidence'][$line['evidence']] = ($q['evidence'][$line['evidence']] ?? 0) + 1;
    }

    private function qualityOut(array $q): array
    {
        ksort($q['shopLines']); ksort($q['panel']); ksort($q['evidence']);
        return ['meterLinesByStatus' => $q['shopLines'], 'panelLinesByStatus' => $q['panel'], 'categoryKnownLines' => $q['categoryKnown'],
            'categoryUnknownLines' => $q['categoryUnknown'], 'linesByEvidence' => $q['evidence']];
    }

    private function skuRow(array $line): array
    {
        return ['source' => $line['source_key'], 'productCode' => $line['product_code'], 'name' => $line['catalogName'] ?? $line['name'], 'names' => [],
            'category' => $line['category'], 'categorySource' => $line['categorySource'], 'bag' => $this->bag(), 'evidence' => [], 'variants' => []];
    }

    /** The most frequent line title names a code without a catalog name. */
    private function skuName(array &$row, array $line): void
    {
        if ($line['catalogName'] !== null) {
            $row['name'] = $line['catalogName'];
            return;
        }
        $row['names'][$line['name']] = ($row['names'][$line['name']] ?? 0) + 1;
        arsort($row['names']);
        $row['name'] = (string) array_key_first($row['names']);
    }

    private function skuOut(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $b = $this->bagOut($row['bag']);
            $items[] = ['source' => $row['source'], 'productCode' => $row['productCode'], 'name' => $row['name'], 'category' => $row['category'], 'categorySource' => $row['categorySource'],
                'evidence' => $row['evidence']] + $b;
        }
        usort($items, static fn (array $a, array $b): int => [MeterContract::units($b['fabricMeters']) + MeterContract::units($b['panelWidthMeters']), $b['units'], $a['source'], (string) $a['productCode']]
            <=> [MeterContract::units($a['fabricMeters']) + MeterContract::units($a['panelWidthMeters']), $a['units'], $b['source'], (string) $b['productCode']]);
        $total = count($items);
        return ['items' => array_slice($items, 0, self::MAX_SKUS), 'total' => $total, 'truncated' => $total > self::MAX_SKUS];
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @return array{0: string, 1: string} */
    private function bounds(DateRange $range): array
    {
        return [$range->fromUtc->format('Y-m-d H:i:s'), $range->toUtc->format('Y-m-d H:i:s')];
    }

    private function label(string $utc, string $bucket): string
    {
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::ZONE));
        return match ($bucket) { 'week' => $local->format('o-\WW'), 'month' => $local->format('Y-m'), default => $local->format('Y-m-d') };
    }

    private static function bySource(): array
    {
        $row = ['orders' => 0, 'units' => 0, 'cancelled' => 0];
        return array_fill_keys(ProductionIntelligenceService::SOURCES, $row);
    }

    private static function trimSources(array $bySource, ?string $source): array
    {
        return $source === null ? $bySource : array_intersect_key($bySource, [$source => 1]);
    }

    /** @template T @param array<string, T> $rows @return array<string, T> in the canonical source order */
    private static function orderSources(array $rows): array
    {
        $order = array_flip(ProductionIntelligenceService::SOURCES);
        uksort($rows, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99) ?: strcmp($a, $b));
        return $rows;
    }

    private static function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
