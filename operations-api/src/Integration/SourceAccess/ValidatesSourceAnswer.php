<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

/**
 * Strict field validators shared by the shop answers (SourceOrderDetail, SourceOrderEdit). Every failure is
 * SOURCE_RESPONSE_INVALID: an answer is shown whole or not at all.
 */
trait ValidatesSourceAnswer
{
    private const MONEY = '/^-?[0-9]{1,12}\.[0-9]{0,4}$/D';
    private const FINGERPRINT = '/^sha256:[0-9a-f]{64}$/D';

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value, int $max, callable $map): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw self::invalid();
        }
        return array_map(static fn (mixed $entry): array => $map(self::map($entry)), $value);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid();
        }
        return $value;
    }

    private static function text(mixed $value, int $max, bool $empty = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || (!$empty && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid();
        }
        return $value;
    }

    private static function pattern(mixed $value, string $pattern): string
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw self::invalid();
        }
        return $value;
    }

    /** @param list<string> $choices */
    private static function choice(mixed $value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw self::invalid();
        }
        return $value;
    }

    private static function money(mixed $value): string
    {
        return self::pattern($value, self::MONEY);
    }

    private static function bool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw self::invalid();
        }
        return $value;
    }

    private static function positive(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 10000) {
            throw self::invalid();
        }
        return $value;
    }

    private static function instant(mixed $value): string
    {
        return self::pattern($value, '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D');
    }

    private static function optionalInstant(mixed $value): ?string
    {
        return $value === null ? null : self::instant($value);
    }

    private static function invalid(): SourceAccessException
    {
        return new SourceAccessException('SOURCE_RESPONSE_INVALID');
    }
}
