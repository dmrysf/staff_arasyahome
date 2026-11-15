<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

/**
 * Trendyol "total panel width" of one commercial line (API 2.73.0, owner decision 2026-11-15). Read only.
 *
 * Trendyol states panel sizes only, never fabric (K2), so this is a separate metric: the finished product width,
 * `panel width (cm) / 100 x line quantity`. It is NOT fabric consumption, never a cut meterage and never added to the
 * priced L meters of Trendhome / Outlet Perdele or to any fabric total.
 *
 * The rule applies only to a line whose measurement is proven:
 * - the production line states the panel in centimetres with a positive width and height;
 * - every other source that states the size agrees exactly: the variant text ("140x260"), the Trendyol size
 *   (`product_size`) and the size the Trendyol employee prepared (`prepared_width_cm` x `prepared_height_cm`);
 * - the quantity relation holds: the released marketplace line exists and its quantity equals the production line's.
 * Anything else is unknown, never zero: `no_measure`, `conflict`, `invalid_measure`, `unlinked` or `quantity_mismatch`.
 *
 * Values are integer millimetres (0.001 m) so sums never drift. Unknown is null.
 */
final class TrendyolPanelWidth
{
    public const VERIFIED = 'verified';
    public const NO_MEASURE = 'no_measure';
    public const CONFLICT = 'conflict';
    public const INVALID = 'invalid_measure';
    public const UNLINKED = 'unlinked';
    public const QUANTITY_MISMATCH = 'quantity_mismatch';
    public const STATUSES = [self::VERIFIED, self::NO_MEASURE, self::CONFLICT, self::INVALID, self::UNLINKED, self::QUANTITY_MISMATCH];
    public const RULE = 'panel width (cm) / 100 x line quantity; only lines whose production size, variant, Trendyol size and prepared size agree and whose marketplace quantity equals the line quantity; not fabric consumption';

    /**
     * @param array{width_value: string|null, height_value: string|null, measurement_unit: string|null, quantity: int|string,
     *              variant: string|null, ty_linked: bool, ty_quantity: int|string|null, ty_size: string|null,
     *              ty_prepared_width: string|null, ty_prepared_height: string|null} $line
     * @return array{status: string, widthUnits: int|null, panelCm: array{0: int, 1: int}|null}
     */
    public static function line(array $line): array
    {
        $result = static fn (string $status, ?int $units = null, ?array $panel = null): array => ['status' => $status, 'widthUnits' => $units, 'panelCm' => $panel];
        $item = ($line['measurement_unit'] ?? null) === 'cm' ? self::pair($line['width_value'] ?? null, $line['height_value'] ?? null) : null;
        if ($item === null) {
            return $result(self::NO_MEASURE);
        }
        $others = [self::dims($line['variant'] ?? null), self::dims($line['ty_size'] ?? null), self::pair($line['ty_prepared_width'] ?? null, $line['ty_prepared_height'] ?? null)];
        foreach ($others as $other) {
            if ($other !== null && $other !== $item) {
                return $result(self::CONFLICT);
            }
        }
        if ($item[0] <= 0 || $item[1] <= 0) {
            return $result(self::INVALID);
        }
        if (($line['ty_linked'] ?? false) !== true) {
            return $result(self::UNLINKED);
        }
        $quantity = (int) $line['quantity'];
        if ($quantity < 1 || $line['ty_quantity'] === null || (int) $line['ty_quantity'] !== $quantity) {
            return $result(self::QUANTITY_MISMATCH);
        }
        // Centimetres are kept in thousandths (pair()), so cm/100 x qty in millimetres of a metre is width_thousandths/100 x qty.
        return $result(self::VERIFIED, intdiv($item[0] * $quantity, 100), [$item[0], $item[1]]);
    }

    /** "140x260", "140 X 260", "140,5×260" -> thousandths of a centimetre; null when the text states no size. */
    public static function dims(?string $text): ?array
    {
        if ($text === null || preg_match('/^\s*(\d{1,6}(?:[.,]\d{1,3})?)\s*[xX×]\s*(\d{1,6}(?:[.,]\d{1,3})?)\s*$/Du', $text, $m) !== 1) {
            return null;
        }
        return self::pair(str_replace(',', '.', $m[1]), str_replace(',', '.', $m[2]));
    }

    /** Two stored decimals as thousandths; null when either is absent or malformed. */
    private static function pair(mixed $width, mixed $height): ?array
    {
        $w = MeterContract::units($width);
        $h = MeterContract::units($height);
        return $w === null || $h === null ? null : [$w, $h];
    }
}
