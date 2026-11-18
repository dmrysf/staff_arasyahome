<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Data coverage of the meter reports per source, over all time (API 2.76.0, section `coverage`). Read only.
 *
 * For each source: the oldest and newest order, the orders held, how many lines carry a verified meter and how many are
 * unknown, the exclusions, the cancellations, how many entered Arasya production, and the calendar months without any
 * order between the first and the last (a gap is shown as a gap, never as zero sales). Plus the B2B test cohort audit,
 * the ledger state and the category catalog state.
 *
 * Web shops: orders in Arasya (canonical) plus the orders the shop export holds that never reached Arasya
 * (shop_sourced_meter_lines); the export is a snapshot, so `outsideArasya.lastImportAt` says how fresh it is.
 */
final readonly class MeterCoverage
{
    private const ZONE = 'Europe/Bucharest';

    public function __construct(private PDO $pdo, private DateTimeImmutable $now)
    {
    }

    public function report(): array
    {
        return ['coverage' => [
            'generatedAt' => $this->now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'productionStart' => ProductionIntelligenceService::ANALYSIS_START,
            'historyFloor' => ProductionIntelligenceService::HISTORY_FLOOR,
            'sources' => ['trendhome' => $this->shop('trendhome'), 'outletperdele' => $this->shop('outletperdele'), 'trendyol' => $this->trendyol(), 'b2b' => $this->b2b()],
            'b2bTestCohort' => $this->cohort(),
            'ledger' => $this->ledger(),
            'categories' => $this->pdo->query('SELECT source_key, COUNT(*) AS products, MAX(imported_at) AS last_change FROM product_catalog_categories GROUP BY source_key ORDER BY source_key')->fetchAll(PDO::FETCH_ASSOC),
        ]];
    }

    private function shop(string $source): array
    {
        $canonical = $this->canonical($source);
        $lines = $this->contractCounts($source);
        $s = $this->pdo->prepare("SELECT COUNT(DISTINCT s.source_order_id) AS orders, COUNT(*) AS line_count, MIN(s.ordered_at) AS first_at, MAX(s.ordered_at) AS last_at, MAX(s.updated_at) AS last_import,
                COUNT(DISTINCT CASE WHEN s.sale_verified = 1 THEN s.source_order_id END) AS verified_orders,
                COUNT(DISTINCT CASE WHEN s.shop_status IN ('cancelled', 'failed', 'refunded', 'trash', 'pending', 'checkout-draft', 'draft') THEN s.source_order_id END) AS no_sale_orders,
                COALESCE(SUM(s.basis_kind IN ('fabric_linear', 'rail_linear') AND s.priced_length IS NOT NULL), 0) AS measured_lines,
                COALESCE(SUM(s.basis_kind = 'unit'), 0) AS unit_lines,
                COALESCE(SUM(s.basis_kind = 'unknown' OR (s.basis_kind IN ('fabric_linear', 'rail_linear') AND s.priced_length IS NULL)), 0) AS unknown_lines,
                COALESCE(SUM(s.refunded_quantity > 0), 0) AS refunded_lines
            FROM shop_sourced_meter_lines s WHERE s.source_key = ? AND NOT EXISTS (SELECT 1 FROM operational_orders o WHERE o.global_order_id = s.global_order_id)");
        $s->execute([$source]);
        $outside = $s->fetch(PDO::FETCH_ASSOC);
        $s = $this->pdo->prepare("SELECT DATE_FORMAT(s.ordered_at, '%Y-%m-%d %H:00:00') AS h, COUNT(DISTINCT s.source_order_id) AS n FROM shop_sourced_meter_lines s
            WHERE s.source_key = ? AND NOT EXISTS (SELECT 1 FROM operational_orders o WHERE o.global_order_id = s.global_order_id) GROUP BY 1");
        $s->execute([$source]);
        $months = $this->mergeMonths($canonical['months'], self::bucharestMonths($s->fetchAll(PDO::FETCH_KEY_PAIR)));
        $measured = $lines['measured_source'] + (int) $outside['measured_lines'];
        $unknown = $lines['unknown'] + $lines['unknown_basis'] + $lines['conflict'] + (int) $outside['unknown_lines'];
        return [
            'firstOrderAt' => self::iso(self::min($canonical['first_at'], $outside['first_at'])),
            'lastOrderAt' => self::iso(self::max($canonical['last_at'], $outside['last_at'])),
            'orders' => $canonical['orders'] + (int) $outside['orders'],
            'meteredLines' => $measured,
            'notMeteredLines' => $lines['not_metered'] + (int) $outside['unit_lines'],
            'unknownLines' => $unknown,
            'coveragePercent' => self::percent($measured, $measured + $unknown),
            'arasya' => ['orders' => $canonical['orders'], 'firstOrderAt' => self::iso($canonical['first_at']), 'lastOrderAt' => self::iso($canonical['last_at']),
                'cancelled' => $canonical['cancelled'], 'excluded' => $canonical['excluded'], 'enteredProduction' => $canonical['entered'], 'notInProduction' => $canonical['orders'] - $canonical['entered'],
                'completed' => $canonical['completed'], 'linesByStatus' => $lines],
            'outsideArasya' => ['orders' => (int) $outside['orders'], 'lines' => (int) $outside['line_count'], 'firstOrderAt' => self::iso($outside['first_at']), 'lastOrderAt' => self::iso($outside['last_at']),
                'lastImportAt' => self::iso($outside['last_import']), 'verifiedSaleOrders' => (int) $outside['verified_orders'], 'noSaleOrders' => (int) $outside['no_sale_orders'],
                'refundedLines' => (int) $outside['refunded_lines'], 'meteredLines' => (int) $outside['measured_lines'], 'notMeteredLines' => (int) $outside['unit_lines'], 'unknownLines' => (int) $outside['unknown_lines'],
                'productionEvidence' => false],
            'months' => $months['items'],
            'gapMonths' => $months['gaps'],
            'futureDatedOrders' => $months['futureDatedOrders'],
        ];
    }

    private function trendyol(): array
    {
        $canonical = $this->canonical('trendyol');
        $s = $this->pdo->query("SELECT o.order_uuid FROM operational_orders o WHERE o.source_key = 'trendyol'");
        $panel = [];
        foreach (array_chunk($s->fetchAll(PDO::FETCH_COLUMN), 500) as $chunk) {
            foreach (CeoLines::forOrders($this->pdo, new ProductCatalog($this->pdo), $chunk) as $lines) {
                foreach ($lines as $line) {
                    $status = $line['excluded'] ? MeterContract::EXCLUDED : ($line['panel']['status'] ?? TrendyolPanelWidth::NO_MEASURE);
                    $panel[$status] = ($panel[$status] ?? 0) + 1;
                }
            }
        }
        ksort($panel);
        $verified = $panel[TrendyolPanelWidth::VERIFIED] ?? 0;
        $all = array_sum($panel) - ($panel[MeterContract::EXCLUDED] ?? 0);
        $months = $this->mergeMonths($canonical['months'], []);
        return [
            'firstOrderAt' => self::iso($canonical['first_at']), 'lastOrderAt' => self::iso($canonical['last_at']), 'orders' => $canonical['orders'],
            'cancelled' => $canonical['cancelled'], 'excluded' => $canonical['excluded'], 'enteredProduction' => $canonical['entered'], 'notInProduction' => $canonical['orders'] - $canonical['entered'],
            'completed' => $canonical['completed'],
            'fabricMeters' => null, 'fabricRule' => 'Trendyol states panel sizes only; fabric meters are unknown and never estimated (K2)',
            'panelLinesByStatus' => $panel, 'panelVerifiedLines' => $verified, 'panelCoveragePercent' => self::percent($verified, $all),
            'months' => $months['items'], 'gapMonths' => $months['gaps'], 'futureDatedOrders' => $months['futureDatedOrders'],
        ];
    }

    private function b2b(): array
    {
        $cohort = B2bSoldMeters::available($this->pdo);
        $join = $cohort ? 'LEFT JOIN b2b_meter_test_cohort c ON c.b2b_order_uuid = b.order_uuid' : '';
        $isTest = $cohort ? 'c.b2b_order_uuid IS NOT NULL' : '0';
        $row = $this->pdo->query("SELECT COUNT(*) AS orders, MIN(b.created_at) AS first_at, MAX(b.created_at) AS last_at,
                COALESCE(SUM({$isTest}), 0) AS test_orders,
                COALESCE(SUM(NOT ({$isTest}) AND b.status = 'finalized'), 0) AS real_finalized,
                COALESCE(SUM(NOT ({$isTest}) AND b.status = 'cancelled'), 0) AS real_cancelled,
                COALESCE(SUM(NOT ({$isTest}) AND b.status = 'draft'), 0) AS real_draft,
                COALESCE(SUM(EXISTS (SELECT 1 FROM b2b_production_handoffs h WHERE h.b2b_order_uuid = b.order_uuid)), 0) AS handed_off
            FROM b2b_orders b {$join}")->fetch(PDO::FETCH_ASSOC);
        $lines = ['measured_source' => 0, 'not_metered' => 0, 'unknown' => 0];
        foreach ($this->pdo->query("SELECT l.item_type, l.meters FROM b2b_order_lines l JOIN b2b_orders b ON b.order_uuid = l.order_uuid {$join} WHERE b.status = 'finalized' AND NOT ({$isTest})") as $line) {
            $lines[B2bSoldMeters::line((string) $line['item_type'], $line['meters'])['status']]++;
        }
        $months = $this->pdo->query("SELECT DATE_FORMAT(b.created_at, '%Y-%m-%d %H:00:00') AS h, COUNT(*) AS n FROM b2b_orders b {$join} WHERE NOT ({$isTest}) GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
        $months = self::bucharestMonths($months);
        $m = $this->mergeMonths($months, []);
        return [
            'firstOrderAt' => self::iso($row['first_at']), 'lastOrderAt' => self::iso($row['last_at']), 'orders' => (int) $row['orders'],
            'testOrders' => (int) $row['test_orders'], 'realOrders' => (int) $row['orders'] - (int) $row['test_orders'],
            'realFinalized' => (int) $row['real_finalized'], 'realCancelled' => (int) $row['real_cancelled'], 'realDraft' => (int) $row['real_draft'], 'handedOff' => (int) $row['handed_off'],
            'realLinesByStatus' => $lines, 'coveragePercent' => self::percent($lines['measured_source'], $lines['measured_source'] + $lines['unknown']),
            'saleRule' => 'finalized = sold (line meters entered as the line total); draft never; commercial cancellation deducted; test cohort excluded',
            'months' => $m['items'], 'gapMonths' => $m['gaps'], 'futureDatedOrders' => $m['futureDatedOrders'],
        ];
    }

    private function cohort(): array
    {
        if (!B2bSoldMeters::available($this->pdo)) {
            return ['available' => false, 'orders' => 0, 'items' => []];
        }
        $rows = $this->pdo->query("SELECT c.cohort_key, c.order_code_snapshot, c.status_snapshot, b.status AS status_now, c.entered_meters_snapshot, c.decided_by_label, c.decided_at,
                o.global_order_id AS canonical, (x.order_uuid IS NOT NULL) AS canonical_excluded,
                (SELECT COALESCE(SUM(l.delta_meters), 0) FROM b2b_sold_meter_ledger l WHERE l.b2b_order_uuid = c.b2b_order_uuid) AS ledger_net
            FROM b2b_meter_test_cohort c JOIN b2b_orders b ON b.order_uuid = c.b2b_order_uuid
            LEFT JOIN operational_orders o ON o.source_key = 'b2b' AND o.source_order_id = c.b2b_order_uuid
            LEFT JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid
            ORDER BY c.decided_at, c.order_code_snapshot")->fetchAll(PDO::FETCH_ASSOC);
        $units = 0; $canonical = 0; $excluded = 0;
        $items = [];
        foreach ($rows as $r) {
            $units += MeterContract::units($r['entered_meters_snapshot']) ?? 0;
            $canonical += $r['canonical'] !== null ? 1 : 0;
            $excluded += (int) $r['canonical_excluded'];
            $items[] = ['cohort' => $r['cohort_key'], 'orderCode' => $r['order_code_snapshot'], 'statusAtDecision' => $r['status_snapshot'], 'statusNow' => $r['status_now'],
                'enteredMeters' => (string) $r['entered_meters_snapshot'], 'canonicalOrder' => $r['canonical'], 'canonicalExcluded' => (int) $r['canonical_excluded'] === 1,
                'soldMetersBooked' => (string) $r['ledger_net'], 'decidedBy' => $r['decided_by_label'], 'decidedAt' => self::iso($r['decided_at'])];
        }
        return ['available' => true, 'orders' => count($rows), 'enteredMeters' => MeterContract::meters($units), 'canonicalOrders' => $canonical, 'canonicalExcluded' => $excluded, 'items' => $items,
            'rule' => 'entered meters = the sum of the lines\' entered meters (line totals, never multiplied by quantity; other lines carry none)'];
    }

    private function ledger(): array
    {
        $cursor = $this->pdo->query("SELECT watermark_at, last_run_at, last_orders, last_entries FROM production_meter_ledger_cursor WHERE cursor_key = 'sold_meters'")->fetch(PDO::FETCH_ASSOC) ?: null;
        return [
            'canonicalEntries' => (int) $this->pdo->query('SELECT COUNT(*) FROM production_meter_ledger')->fetchColumn(),
            'b2bEntries' => B2bSoldMeters::available($this->pdo) ? (int) $this->pdo->query('SELECT COUNT(*) FROM b2b_sold_meter_ledger')->fetchColumn() : null,
            'exclusions' => (int) $this->pdo->query('SELECT COUNT(*) FROM production_meter_exclusions')->fetchColumn(),
            'automatic' => $cursor !== null,
            'syncedThrough' => $cursor !== null ? self::iso($cursor['watermark_at']) : null,
            'lastRunAt' => $cursor !== null ? self::iso($cursor['last_run_at']) : null,
            'lastRunOrders' => $cursor !== null ? (int) $cursor['last_orders'] : null,
            'lastRunEntries' => $cursor !== null ? (int) $cursor['last_entries'] : null,
        ];
    }

    /** @return array{orders: int, first_at: ?string, last_at: ?string, cancelled: int, excluded: int, entered: int, completed: int, months: array<string, int>} */
    private function canonical(string $source): array
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) AS orders, MIN(COALESCE(o.source_submitted_at, o.created_at)) AS first_at, MAX(COALESCE(o.source_submitted_at, o.created_at)) AS last_at,
                COALESCE(SUM(o.operational_status = 'unavailable'), 0) AS cancelled, COALESCE(SUM(x.order_uuid IS NOT NULL), 0) AS excluded,
                COALESCE(SUM(o.production_completed_at IS NOT NULL), 0) AS completed,
                COALESCE(SUM(EXISTS (SELECT 1 FROM order_activity_events e WHERE e.order_uuid = o.order_uuid AND e.action = 'stage_completed')), 0) AS entered
            FROM operational_orders o LEFT JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid WHERE o.source_key = ?");
        $s->execute([$source]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $s = $this->pdo->prepare("SELECT DATE_FORMAT(COALESCE(o.source_submitted_at, o.created_at), '%Y-%m-%d %H:00:00') AS h, COUNT(*) AS n
            FROM operational_orders o LEFT JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid WHERE o.source_key = ? AND x.order_uuid IS NULL GROUP BY 1");
        $s->execute([$source]);
        return ['orders' => (int) $row['orders'], 'first_at' => $row['first_at'], 'last_at' => $row['last_at'], 'cancelled' => (int) $row['cancelled'], 'excluded' => (int) $row['excluded'],
            'entered' => (int) $row['entered'], 'completed' => (int) $row['completed'], 'months' => self::bucharestMonths($s->fetchAll(PDO::FETCH_KEY_PAIR))];
    }

    /** @return array<string, int> line counts by sold-meter contract status of a source's canonical orders */
    private function contractCounts(string $source): array
    {
        $counts = array_fill_keys([MeterContract::MEASURED, MeterContract::NOT_METERED, MeterContract::UNKNOWN, MeterContract::UNKNOWN_BASIS, MeterContract::CONFLICT, MeterContract::EXCLUDED], 0);
        $s = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE source_key = ?');
        $s->execute([$source]);
        foreach (array_chunk($s->fetchAll(PDO::FETCH_COLUMN), 500) as $chunk) {
            foreach (MeterLines::forOrders($this->pdo, $chunk) as $lines) {
                foreach ($lines as $line) {
                    $counts[$line['contract']['status']] = ($counts[$line['contract']['status']] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }

    /** UTC hour buckets => Europe/Bucharest calendar months (exact, daylight saving included). @param array<string, int|string> $hours @return array<string, int> */
    private static function bucharestMonths(array $hours): array
    {
        $zone = new DateTimeZone(self::ZONE); $utc = new DateTimeZone('UTC');
        $months = [];
        foreach ($hours as $hour => $n) {
            $month = (new DateTimeImmutable((string) $hour, $utc))->setTimezone($zone)->format('Y-m');
            $months[$month] = ($months[$month] ?? 0) + (int) $n;
        }
        return $months;
    }

    /**
     * Month counts (Europe/Bucharest) merged from two sources, with the months between
     * the first and the last that hold no order.
     * @param array<string, int|string> $a @param array<string, int|string> $b
     * @return array{items: list<array{month: string, orders: int}>, gaps: list<string>, futureDatedOrders: int}
     */
    private function mergeMonths(array $a, array $b): array
    {
        $all = [];
        foreach ([$a, $b] as $set) {
            foreach ($set as $month => $n) {
                $all[(string) $month] = ($all[(string) $month] ?? 0) + (int) $n;
            }
        }
        if ($all === []) {
            return ['items' => [], 'gaps' => [], 'futureDatedOrders' => 0];
        }
        ksort($all);
        // Months after the current one hold no real order (a future date is a data anomaly): they are counted apart and never
        // expand the month list.
        $current = $this->now->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m');
        $future = 0;
        foreach ($all as $month => $n) {
            if ($month > $current) {
                $future += $n;
                unset($all[$month]);
            }
        }
        if ($all === []) {
            return ['items' => [], 'gaps' => [], 'futureDatedOrders' => $future];
        }
        $first = new DateTimeImmutable(array_key_first($all) . '-01');
        $last = new DateTimeImmutable(array_key_last($all) . '-01');
        $items = []; $gaps = [];
        for ($m = $first; $m <= $last; $m = $m->modify('+1 month')) {
            $key = $m->format('Y-m');
            $items[] = ['month' => $key, 'orders' => $all[$key] ?? 0];
            if (!isset($all[$key])) {
                $gaps[] = $key;
            }
        }
        return ['items' => $items, 'gaps' => $gaps, 'futureDatedOrders' => $future];
    }

    private static function min(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    private static function max(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    private static function percent(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : number_format(100 * $part / $whole, 1, '.', '');
    }

    private static function iso(mixed $utc): ?string
    {
        return $utc === null ? null : (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
