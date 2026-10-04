<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

/**
 * Normalizes the human-readable order code used for exact manual lookup.
 * Only exact indexed matches are supported; wildcards are never interpreted.
 */
final class OrderLookupCode
{
    private function __construct()
    {
    }

    public static function fromOrderNumber(string $orderNumber): string
    {
        return mb_strtoupper(ltrim(trim($orderNumber), '#'));
    }

    /** Returns null when the operator input is not a supported lookup code. */
    public static function normalizeInput(string $input): ?string
    {
        $value = trim($input);
        if ($value === '' || strlen($value) > 128) {
            return null;
        }
        $value = ltrim($value, '#');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}$/D', $value) !== 1) {
            return null;
        }
        return strtoupper($value);
    }
}
