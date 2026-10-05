<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

/**
 * Exact current-account money: decimal strings with two places, handled as integer cents.
 * Never a float. 9 999 999 999.99 is the largest single amount, so any realistic sum stays far inside int64.
 */
final class AccountMoney
{
    public const MAX_CENTS = 999_999_999_999;

    /** Parses a positive request amount ("12", "12.3", "12.30"); null when it is not a valid positive amount. */
    public static function parsePositive(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/D', $value, $m) !== 1) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $cents > 0 && $cents <= self::MAX_CENTS ? $cents : null;
    }

    /** Cents from a database DECIMAL(16,2) string, which may be negative (sums and balances). */
    public static function fromDecimal(string|int|null $value): int
    {
        $value = (string) ($value ?? '0');
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $value, $m) !== 1) {
            throw new \UnexpectedValueException('Invalid decimal amount.');
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
