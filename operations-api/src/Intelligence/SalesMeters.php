<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use PDO;

/**
 * Ordered / sold meters for the CEO (API 2.76.0). Read only. One sale identity per order, never two:
 * - Trendhome / Outlet Perdele orders in Arasya: production_meter_ledger (sold-meter contract v1, at the order's own date);
 * - Trendhome / Outlet Perdele orders that never reached Arasya: the shop's own statement (ShopSourcedSales), apart as
 *   `outsideArasya`; a shop order that exists in Arasya is counted only by the ledger;
 * - B2B: b2b_sold_meter_ledger by commercial order, at its finalization (B2bSoldMeters). Its canonical production order
 *   never books sold meters, and the B2B test cohort books none.
 * Trendyol has no fabric meters (K2); its panel width is reported apart by the caller and never added to fabric.
 *
 * Per source and metric (fabric and rail apart, K5): `ordered` (sale, reactivation and correction entries), `cancelled`
 * (the meters a cancellation took back, a positive number), `adjusted` (exclusions and removed lines), `net` (all
 * entries), `verified` (net meters whose payment is proven in full), and the net by payment class. Unknown lines are
 * counted by the caller; nothing here turns an unknown into zero.
 */
final class SalesMeters
{
    public const SHOP_SOURCES = ['trendhome', 'outletperdele'];
    public const FABRIC_SOURCES = ['trendhome', 'outletperdele', 'b2b'];

    /**
     * Arasya's own sold entries in [from, to) (UTC): the canonical ledger and the B2B commercial ledger.
     * @return list<array<string, mixed>>
     */
    public static function arasyaEntries(PDO $pdo, string $fromUtc, string $toUtc, ?string $source): array
    {
        $entries = [];
        if ($source !== 'b2b') {
            $sql = 'SELECT l.metric, l.source_key, l.product_code_snapshot, l.variant_snapshot, l.delta_meters, l.reason, l.occurred_at, l.order_uuid, l.item_uuid, ' . PaymentEvidence::COLUMNS . '
                FROM production_meter_ledger l LEFT JOIN operational_orders o ON o.order_uuid = l.order_uuid WHERE l.occurred_at >= ? AND l.occurred_at < ?';
            $params = [$fromUtc, $toUtc];
            if ($source !== null) {
                $sql .= ' AND l.source_key = ?';
                $params[] = $source;
            }
            $s = $pdo->prepare($sql . ' ORDER BY l.occurred_at, l.entry_uuid');
            $s->execute($params);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entries[] = $row + ['origin' => 'arasya', 'order_key' => $row['order_uuid'], 'payment' => PaymentEvidence::classify($row)];
            }
        }
        if (($source === null || $source === 'b2b') && B2bSoldMeters::available($pdo)) {
            $s = $pdo->prepare("SELECT l.metric, 'b2b' AS source_key, l.product_code_snapshot, l.variant_snapshot, l.delta_meters, l.reason, l.occurred_at, l.b2b_order_uuid, l.line_uuid AS item_uuid
                FROM b2b_sold_meter_ledger l WHERE l.occurred_at >= ? AND l.occurred_at < ? ORDER BY l.occurred_at, l.entry_uuid");
            $s->execute([$fromUtc, $toUtc]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $classes = B2bPaymentEvidence::classes($pdo, array_column($rows, 'b2b_order_uuid'));
            foreach ($rows as $row) {
                $entries[] = $row + ['order_uuid' => null, 'origin' => 'arasya', 'order_key' => 'b2b:' . $row['b2b_order_uuid'], 'payment' => $classes[$row['b2b_order_uuid']] ?? PaymentEvidence::UNKNOWN];
            }
        }
        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries Arasya entries plus the outside-Arasya entries (ShopSourcedSales::report)
     * @return array<string, mixed>
     */
    public static function aggregate(array $entries, ?string $source): array
    {
        $empty = static fn (): array => ['ordered' => 0, 'cancelled' => 0, 'adjusted' => 0, 'net' => 0, 'verified' => 0];
        $sources = [];
        foreach ($source === null ? self::FABRIC_SOURCES : array_intersect(self::FABRIC_SOURCES, [$source]) as $key) {
            $sources[$key] = ['fabric' => $empty(), 'rail' => $empty(), 'arasya' => ['fabric' => 0, 'rail' => 0], 'outside' => ['fabric' => 0, 'rail' => 0, 'verifiedFabric' => 0, 'openFabric' => 0],
                'orders' => [], 'payment' => []];
        }
        foreach ($entries as $entry) {
            $key = (string) $entry['source_key'];
            if (!isset($sources[$key])) {
                continue;
            }
            $units = self::signed((string) $entry['delta_meters']);
            $metric = $entry['metric'] === 'sold_rail' ? 'rail' : 'fabric';
            $bucket = &$sources[$key][$metric];
            match ((string) $entry['reason']) {
                'cancellation' => $bucket['cancelled'] -= $units,
                'exclusion', 'line_removed' => $bucket['adjusted'] += $units,
                default => $bucket['ordered'] += $units,
            };
            $bucket['net'] += $units;
            if (PaymentEvidence::verifiedSale((string) $entry['payment'])) {
                $bucket['verified'] += $units;
            }
            unset($bucket);
            if ($entry['origin'] === ShopSourcedSales::ORIGIN) {
                $sources[$key]['outside'][$metric] += $units;
                if ($metric === 'fabric') {
                    $sources[$key]['outside'][$entry['reason'] === ShopSourcedSales::REASON ? 'verifiedFabric' : 'openFabric'] += $units;
                }
            } else {
                $sources[$key]['arasya'][$metric] += $units;
            }
            $sources[$key]['orders'][(string) $entry['order_key']] = true;
            $class = (string) $entry['payment'];
            $sources[$key]['payment'][$class] ??= ['fabric' => 0, 'rail' => 0, 'orders' => []];
            $sources[$key]['payment'][$class][$metric] += $units;
            $sources[$key]['payment'][$class]['orders'][(string) $entry['order_key']] = true;
        }
        $m = static fn (int $u): string => MeterContract::meters($u);
        $out = [];
        $total = ['fabric' => $empty(), 'rail' => $empty(), 'orders' => 0];
        $payment = [];
        foreach ($sources as $key => $row) {
            foreach (['fabric', 'rail'] as $metric) {
                foreach ($row[$metric] as $k => $v) {
                    $total[$metric][$k] += $v;
                }
            }
            $total['orders'] += count($row['orders']);
            $byPayment = [];
            foreach (PaymentEvidence::CLASSES as $class) {
                $p = $row['payment'][$class] ?? ['fabric' => 0, 'rail' => 0, 'orders' => []];
                $byPayment[$class] = ['fabricMeters' => $m($p['fabric']), 'railMeters' => $m($p['rail']), 'orders' => count($p['orders'])];
                $payment[$class] ??= ['fabric' => 0, 'rail' => 0, 'orders' => 0];
                $payment[$class]['fabric'] += $p['fabric'];
                $payment[$class]['rail'] += $p['rail'];
                $payment[$class]['orders'] += count($p['orders']);
            }
            $out[$key] = [
                'fabric' => array_map($m, $row['fabric']),
                'rail' => array_map($m, $row['rail']),
                'arasya' => ['fabricMeters' => $m($row['arasya']['fabric']), 'railMeters' => $m($row['arasya']['rail'])],
                'outsideArasya' => in_array($key, self::SHOP_SOURCES, true) ? ['fabricMeters' => $m($row['outside']['fabric']), 'railMeters' => $m($row['outside']['rail']),
                    'verifiedFabricMeters' => $m($row['outside']['verifiedFabric']), 'openFabricMeters' => $m($row['outside']['openFabric'])] : null,
                'orders' => count($row['orders']),
                'byPayment' => $byPayment,
            ];
        }
        $byPayment = [];
        foreach (PaymentEvidence::CLASSES as $class) {
            $p = $payment[$class] ?? ['fabric' => 0, 'rail' => 0, 'orders' => 0];
            $byPayment[$class] = ['fabricMeters' => $m($p['fabric']), 'railMeters' => $m($p['rail']), 'orders' => $p['orders']];
        }
        return [
            'bySource' => $out,
            'total' => ['fabric' => array_map($m, $total['fabric']), 'rail' => array_map($m, $total['rail']), 'orders' => $total['orders'], 'byPayment' => $byPayment,
                'sources' => array_keys($out)],
            'rules' => [
                'fabricTotal' => 'Trendhome + Outlet Perdele + B2B fabric meters (sold-meter contract); rail and Trendyol panel width are apart and never added',
                'b2b' => 'a finalized B2B order sells its entered line meters at its finalization; drafts never; a commercial cancellation is deducted; production never sells again; the B2B test cohort is excluded',
                'verified' => 'payment proven in full (shop: verified online payment, outside Arasya: completed with a recorded payment; B2B: the receivable fully allocated in the B2B current account)',
                'cashOnDelivery' => 'an order with only its advance proven is advance_verified, never paid',
            ],
        ];
    }

    private static function signed(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $units = MeterContract::units(ltrim($value, '-')) ?? 0;
        return $negative ? -$units : $units;
    }
}
