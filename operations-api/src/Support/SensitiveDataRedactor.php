<?php

declare(strict_types=1);

namespace Arasya\Operations\Support;

final class SensitiveDataRedactor
{
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 100;
    private const MAX_STRING_BYTES = 2048;

    private function __construct()
    {
    }

    /** @param array<array-key, mixed> $metadata @return array<array-key, mixed> */
    public static function sanitize(array $metadata): array
    {
        return self::sanitizeArray($metadata, 0);
    }

    /** @param array<array-key, mixed> $input @return array<array-key, mixed> */
    private static function sanitizeArray(array $input, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => true];
        }
        $sanitized = [];
        $count = 0;
        foreach ($input as $key => $value) {
            if ($count >= self::MAX_ITEMS) {
                $sanitized['_truncated'] = true;
                break;
            }
            if (is_string($key) && self::isSensitiveKey($key)) {
                continue;
            }
            $sanitized[$key] = self::sanitizeValue($value, $depth + 1);
            $count++;
        }
        return $sanitized;
    }

    private static function sanitizeValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return self::sanitizeArray($value, $depth);
        }
        if (is_object($value)) {
            return self::sanitizeArray(get_object_vars($value), $depth);
        }
        if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
            return substr($value, 0, self::MAX_STRING_BYTES) . '…';
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        return '[unsupported]';
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        return preg_match(
            '/password(?:_?hash)?|access[_-]?token|refresh[_-]?token|csrf|authorization|cookie|client[_-]?secret|api[_-]?key|(?:^|[_-])token(?:$|[_-])|(?:^|[_-])secret(?:$|[_-])/',
            $normalized,
        ) === 1;
    }
}
