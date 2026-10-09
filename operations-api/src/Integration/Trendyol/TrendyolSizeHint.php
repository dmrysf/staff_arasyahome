<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

/**
 * Reads a "width x height" suggestion (centimetres) from Trendyol's free-text size or product name, for
 * example "300x260", "140 x 260 cm" or "140 cm X 260 cm". A suggestion only: it is shown to the employee and
 * never stored as a production measurement until an authorized person confirms the values.
 */
final class TrendyolSizeHint
{
    private function __construct()
    {
    }

    /** @return array{width: string, height: string}|null */
    public static function suggest(?string ...$texts): ?array
    {
        foreach ($texts as $text) {
            if ($text === null) {
                continue;
            }
            if (preg_match('/(?<![\d.,])(\d{2,4}(?:[.,]\d{1,2})?)\s*(?:cm)?\s*[xX×*]\s*(\d{2,4}(?:[.,]\d{1,2})?)(?![\d.,])/u', $text, $match) === 1) {
                $width = str_replace(',', '.', $match[1]);
                $height = str_replace(',', '.', $match[2]);
                if ((float) $width > 0 && (float) $height > 0) {
                    return ['width' => $width, 'height' => $height];
                }
            }
        }
        return null;
    }
}
