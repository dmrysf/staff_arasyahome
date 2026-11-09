<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance;

use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Http\ApiException;

/**
 * Strict request validation for Finance bodies. Unknown fields fail; text is trimmed, control characters refused,
 * lengths bounded; money is a decimal string parsed to integer cents (never a float).
 */
final class FinanceInput
{
    public const EVIDENCE_KINDS = ['registry_extract', 'saga_ledger', 'smartbill_report', 'bank_statement', 'contract', 'correspondence', 'invoice', 'other'];
    public const EVIDENCE_SOURCES = ['MANUAL', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'EXISTING_B2B', 'BANK', 'REGISTRY', 'OTHER_LEGACY'];

    /** @param list<string> $allowed */
    public static function only(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Invalid request fields.', ['fields' => array_values($unknown)]);
        }
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    public static function uuid(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!self::isUuid($value)) {
            self::fail($field, 'invalid');
        }
        return $value;
    }

    public static function version(array $input, string $field = 'expectedVersion'): int
    {
        $value = $input[$field] ?? null;
        if (!is_int($value) || $value < 1 || $value > 2_000_000_000) {
            self::fail($field, 'invalid');
        }
        return $value;
    }

    public static function text(array $input, string $field, int $max, bool $required = false, bool $multiline = false): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return $required ? self::fail($field, 'required') : null;
        }
        if (!is_string($input[$field])) {
            self::fail($field, 'invalid');
        }
        $value = trim($input[$field]);
        if ($value === '') {
            return $required ? self::fail($field, 'required') : null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            self::fail($field, 'encoding');
        }
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        if (preg_match($pattern, $value) === 1) {
            self::fail($field, 'control_characters');
        }
        if (mb_strlen($value) > $max) {
            self::fail($field, 'too_long');
        }
        return $value;
    }

    public static function reason(array $input, string $field = 'reason', bool $required = true): ?string
    {
        $value = self::text($input, $field, 2000, $required, true);
        if ($value !== null && mb_strlen($value) < 3) {
            self::fail($field, 'too_short');
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public static function enum(array $input, string $field, array $allowed, bool $required = true): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::fail($field, 'invalid');
        }
        return $value;
    }

    public static function date(array $input, string $field, bool $required = true): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            self::fail($field, 'invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2099-12-31') {
            self::fail($field, 'invalid');
        }
        return $value;
    }

    /** Positive amount in cents, or zero when $allowZero. */
    public static function money(array $input, string $field, bool $allowZero = false): int
    {
        $value = $input[$field] ?? null;
        if ($allowZero && ($value === '0' || $value === '0.00' || $value === '0.0')) {
            return 0;
        }
        $cents = AccountMoney::parsePositive($value);
        if ($cents === null) {
            self::fail($field, 'invalid');
        }
        return $cents;
    }

    public static function bool(array $input, string $field): bool
    {
        $value = $input[$field] ?? null;
        if (!is_bool($value)) {
            self::fail($field, 'invalid');
        }
        return $value;
    }

    /**
     * Evidence references: where a human inspected the source (document number, ledger page, report id). At most 20.
     * @return list<array{kind: string, sourceSystem: string, reference: string, documentDate: ?string, amount: ?string, currency: ?string, note: ?string}>
     */
    public static function evidence(array $input, string $field = 'evidence', bool $required = false): array
    {
        $value = $input[$field] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            self::fail($field, 'invalid');
        }
        if ($required && $value === []) {
            self::fail($field, 'required');
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!is_array($item) || array_is_list($item) && $item !== []) {
                self::fail("{$field}.{$i}", 'invalid');
            }
            self::only($item, ['kind', 'sourceSystem', 'reference', 'documentDate', 'amount', 'currency', 'note']);
            $amount = null;
            $currency = null;
            if (($item['amount'] ?? null) !== null) {
                $amount = AccountMoney::format(self::money($item, 'amount', true));
                $currency = self::enum($item, 'currency', ['RON', 'EUR']);
            } elseif (($item['currency'] ?? null) !== null) {
                self::fail("{$field}.{$i}.currency", 'without_amount');
            }
            $out[] = [
                'kind' => (string) self::enum($item, 'kind', self::EVIDENCE_KINDS),
                'sourceSystem' => (string) self::enum($item, 'sourceSystem', self::EVIDENCE_SOURCES),
                'reference' => (string) self::text($item, 'reference', 255, true),
                'documentDate' => self::date($item, 'documentDate', false),
                'amount' => $amount,
                'currency' => $currency,
                'note' => self::text($item, 'note', 500),
            ];
        }
        return $out;
    }

    public static function fail(string $field, string $reason): never
    {
        throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', ['fields' => [$field => $reason]]);
    }
}
