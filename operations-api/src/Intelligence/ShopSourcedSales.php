<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use PDO;

/**
 * "Arasya Dışı / Mağaza Kaynaklı" (owner decision 2026-11-13): web-shop orders the shop holds but that never reached
 * Arasya. Their lines come only from the shop export (bin/meter-facts-import.php) and live in `shop_sourced_meter_lines`.
 *
 * - They are never canonical orders: no order, item, QR, document, Confirmare state or activity exists for them.
 * - They never enter production metrics (cut, recut, processed, completed): Arasya holds no production evidence for them.
 * - Only a verified sale counts as sold meters: the shop order is `completed` and the shop recorded its payment.
 * - API 2.72.0 (owner decision 2026-11-14): an order that is still open at the shop (processing, shipped, being verified,
 *   completed without a recorded payment, ...) is reported as ordered meters whose payment is not verified, never as zero
 *   and never as a sale. A cancelled, failed, refunded, trashed or unpaid checkout order never carries meters.
 * - Sold meters follow contract v1 for a web-shop line: `fabric_linear` / `rail_linear` length x (quantity - refunded
 *   quantity), apart by metric (K5); `unit` carries no meter; `unknown` is counted as unknown, never zero.
 * - A line whose order exists in Arasya (matched by its global order id) is never counted here: Arasya's own ledger counts
 *   that order, so nothing is counted twice.
 * - They are dated at the shop order's creation, the same sale date Arasya uses for a web-shop order.
 */
final class ShopSourcedSales
{
    public const ORIGIN = 'outside_arasya';
    public const VERIFIED_STATUS = 'completed';
    public const REASON = 'outside_arasya_sale';
    public const REASON_OPEN = 'outside_arasya_order';
    /** Shop statuses that are no sale at all: they never carry ordered meters. */
    public const NO_SALE_STATUSES = ['cancelled', 'failed', 'refunded', 'trash', 'pending', 'checkout-draft', 'draft'];

    public static function verified(string $status, ?string $paidAt): bool
    {
        return $status === self::VERIFIED_STATUS && $paidAt !== null;
    }

    /**
     * Sold units of one stored line (integer millimetres), or why it carries none.
     * @param array{basis_kind: string, priced_length: string|null, quantity: int|string, refunded_quantity: int|string} $line
     * @return array{status: string, fabricUnits: int|null, railUnits: int|null}
     */
    public static function line(array $line): array
    {
        $result = static fn (string $status, ?int $fabric = null, ?int $rail = null): array => ['status' => $status, 'fabricUnits' => $fabric, 'railUnits' => $rail];
        $net = (int) $line['quantity'] - (int) $line['refunded_quantity'];
        if ($line['basis_kind'] === 'unit') {
            return $result(MeterContract::NOT_METERED);
        }
        if ($line['basis_kind'] !== 'fabric_linear' && $line['basis_kind'] !== 'rail_linear') {
            return $result(MeterContract::UNKNOWN);
        }
        $length = MeterContract::units($line['priced_length']);
        if ($length === null || $length === 0) {
            return $result(MeterContract::UNKNOWN);
        }
        if ($net < 1) {
            return $result(MeterContract::NOT_METERED);
        }
        $total = $length * $net;
        return $line['basis_kind'] === 'fabric_linear' ? $result(MeterContract::MEASURED, $total) : $result(MeterContract::MEASURED, null, $total);
    }

    /**
     * The lines stored for shop orders that are still outside Arasya, created in [from, to) (UTC).
     * @return list<array<string, mixed>>
     */
    public static function lines(PDO $pdo, string $fromUtc, string $toUtc, ?string $source): array
    {
        if ($source !== null && !in_array($source, MeterFactImport::SOURCES, true)) {
            return [];
        }
        $sql = 'SELECT s.source_key, s.source_order_id, s.source_item_id, s.shop_status, s.sale_verified, s.ordered_at, s.basis_kind, s.priced_length,
                s.quantity, s.refunded_quantity, s.product_code_snapshot, s.variant_snapshot
            FROM shop_sourced_meter_lines s
            WHERE s.ordered_at >= ? AND s.ordered_at < ?
              AND NOT EXISTS (SELECT 1 FROM operational_orders o WHERE o.global_order_id = s.global_order_id)';
        $params = [$fromUtc, $toUtc];
        if ($source !== null) {
            $sql .= ' AND s.source_key = ?';
            $params[] = $source;
        }
        $s = $pdo->prepare($sql . ' ORDER BY s.ordered_at, s.source_key, s.source_order_id, s.source_item_id');
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ordered entries of the given lines, shaped like ledger entries so every report sums them the same way: a verified sale
     * carries the payment class `paid`, an order still open at the shop `unverified`; plus the classification of every
     * order and line.
     * @param list<array<string, mixed>> $lines
     * @return array{entries: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function report(array $lines): array
    {
        $entries = [];
        $verifiedOrders = []; $otherOrders = []; $statusOrders = []; $openOrders = [];
        $units = ['sold_fabric' => 0, 'sold_rail' => 0];
        $open = ['sold_fabric' => 0, 'sold_rail' => 0];
        $counted = 0; $unknown = 0; $openUnknown = 0;
        foreach ($lines as $row) {
            $order = $row['source_key'] . ':' . $row['source_order_id'];
            $statusOrders[$row['shop_status']][$order] = true;
            $verified = (int) $row['sale_verified'] === 1;
            if (!$verified) {
                $otherOrders[$order] = true;
                if (in_array($row['shop_status'], self::NO_SALE_STATUSES, true)) {
                    continue;
                }
                $openOrders[$order] = true;
            } else {
                $verifiedOrders[$order] = true;
            }
            $contract = self::line($row);
            if ($contract['status'] === MeterContract::UNKNOWN) {
                $verified ? $unknown++ : $openUnknown++;
                continue;
            }
            foreach (['sold_fabric' => $contract['fabricUnits'], 'sold_rail' => $contract['railUnits']] as $metric => $value) {
                if ($value === null || $value === 0) {
                    continue;
                }
                if ($verified) {
                    $counted++;
                    $units[$metric] += $value;
                } else {
                    $open[$metric] += $value;
                }
                $entries[] = ['metric' => $metric, 'source_key' => $row['source_key'], 'product_code_snapshot' => $row['product_code_snapshot'],
                    'variant_snapshot' => $row['variant_snapshot'], 'delta_meters' => MeterContract::meters($value), 'reason' => $verified ? self::REASON : self::REASON_OPEN,
                    'occurred_at' => $row['ordered_at'], 'origin' => self::ORIGIN, 'order_key' => $order,
                    'payment' => $verified ? PaymentEvidence::PAID : PaymentEvidence::UNVERIFIED];
            }
        }
        $byStatus = array_map('count', $statusOrders);
        ksort($byStatus);
        return ['entries' => $entries, 'summary' => [
            'fabricMeters' => MeterContract::meters($units['sold_fabric']),
            'railMeters' => MeterContract::meters($units['sold_rail']),
            'verifiedSaleOrders' => count($verifiedOrders),
            'meteredLines' => $counted,
            'unknownLines' => $unknown,
            'notVerifiedOrders' => count($otherOrders),
            'openOrders' => count($openOrders),
            'openFabricMeters' => MeterContract::meters($open['sold_fabric']),
            'openRailMeters' => MeterContract::meters($open['sold_rail']),
            'openUnknownLines' => $openUnknown,
            'ordersByShopStatus' => $byStatus,
            'saleRule' => 'shop status completed with a recorded payment',
            'openRule' => 'open at the shop, payment not verified; cancelled, failed, refunded, trashed and unpaid checkouts carry no meters',
            'productionEvidence' => false,
        ]];
    }
}
