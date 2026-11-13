<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

/**
 * Sold-meter contract v1 of one commercial line (Production Intelligence V2, owner decisions 2026-11-12).
 *
 * A meter is only ever read from a stated source fact, never derived from a missing field, a product title, an option
 * text or a pleat ratio:
 * - Trendhome / Outletperdele: the shop states the line's pricing basis (order.items[].meterBasis). wc-kalkulator prices a
 *   curtain line as L x (price per metre + labour), so a `fabric_linear` line sold `pricedLength x quantity` metres of
 *   fabric. `rail_linear` (rails, tape) is kept apart and never counted as fabric (K5). `unit` lines carry no meter.
 *   The stated length must agree with the printed width (metres) when both exist; a disagreement is `conflict`, not a value.
 * - Trendyol: the marketplace states panel sizes only, so the fabric meterage is unknown (K2); no estimate is made.
 * - B2B: the whole-line `meters` an employee entered on the order line.
 * - An excluded order (K7) carries no meter at all.
 *
 * Values are integer millimetres (units of 0.001 m) so sums never drift. Unknown is null, never zero.
 */
final class MeterContract
{
    public const VERSION = 1;
    public const MEASURED = 'measured_source';
    public const NOT_METERED = 'not_metered';
    public const UNKNOWN_BASIS = 'unknown_basis';
    public const UNKNOWN = 'unknown';
    public const UNKNOWN_MARKETPLACE = 'unknown_marketplace';
    public const CONFLICT = 'conflict';
    public const EXCLUDED = 'excluded';
    public const BASIS_KINDS = ['fabric_linear', 'rail_linear', 'unit', 'unknown'];

    /**
     * @param array{source_key: string, quantity: int|string, width_value: string|null, measurement_unit: string|null,
     *              meters: string|null, context_kind: string|null, basis_kind: string|null, priced_length: string|null,
     *              excluded?: bool} $line
     * @return array{status: string, fabricUnits: int|null, railUnits: int|null}
     */
    public static function line(array $line): array
    {
        $result = static fn (string $status, ?int $fabric = null, ?int $rail = null): array => ['status' => $status, 'fabricUnits' => $fabric, 'railUnits' => $rail];
        if (($line['excluded'] ?? false) === true) {
            return $result(self::EXCLUDED);
        }
        $quantity = (int) $line['quantity'];
        if ($quantity < 1) {
            return $result(self::UNKNOWN);
        }
        switch ($line['source_key']) {
            case 'trendyol':
                return $result(self::UNKNOWN_MARKETPLACE);
            case 'b2b':
                if ($line['context_kind'] === 'other') {
                    return $result(self::NOT_METERED);
                }
                $meters = self::units($line['meters']);
                return $meters === null || $meters === 0 ? $result(self::UNKNOWN) : $result(self::MEASURED, $meters);
            case 'trendhome':
            case 'outletperdele':
                $kind = $line['basis_kind'];
                if ($kind === null) {
                    return $result(self::UNKNOWN_BASIS);
                }
                if ($kind === 'unit') {
                    return $result(self::NOT_METERED);
                }
                if ($kind !== 'fabric_linear' && $kind !== 'rail_linear') {
                    return $result(self::UNKNOWN);
                }
                $length = self::units($line['priced_length']);
                if ($length === null || $length === 0) {
                    return $result(self::UNKNOWN);
                }
                // The printed width is the same L in metres; a different value means the two facts disagree.
                $width = self::units($line['width_value']);
                if ($width !== null && $line['measurement_unit'] === 'm' && $width !== $length) {
                    return $result(self::CONFLICT);
                }
                $total = $length * $quantity;
                return $kind === 'fabric_linear' ? $result(self::MEASURED, $total) : $result(self::MEASURED, null, $total);
            default:
                return $result(self::UNKNOWN);
        }
    }

    /** A stored DECIMAL as integer millimetres; null when absent or not a plain non-negative decimal. */
    public static function units(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,3})\d*)?$/D', $value, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

    public static function meters(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        return $sign . intdiv($units, 1000) . '.' . str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT);
    }
}
