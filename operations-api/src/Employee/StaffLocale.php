<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

/**
 * The Staff interface languages (migration 027). A stored NULL, or any value outside the list, reads as the default
 * Romanian, so an identity can never end up without a usable language. The language is presentation only.
 */
final class StaffLocale
{
    public const DEFAULT = 'ro';
    public const SUPPORTED = ['ro', 'tr', 'en', 'si'];

    public static function normalize(mixed $value): string
    {
        return is_string($value) && in_array($value, self::SUPPORTED, true) ? $value : self::DEFAULT;
    }

    public static function isSupported(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::SUPPORTED, true);
    }
}
