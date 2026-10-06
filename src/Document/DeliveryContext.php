<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use InvalidArgumentException;

/**
 * Printable delivery identity of an order: recipient, optional company, delivery address lines and a
 * masked phone. It is the only customer data the workshop document carries.
 *
 * The phone is masked here, before storage: only the first two digits survive ("07** *** ***"). A raw
 * phone number and an email address are never accepted into or kept in this context.
 */
final class DeliveryContext
{
    private const KEYS = ['name', 'company', 'street', 'city', 'county', 'postalCode', 'country', 'phone'];

    private function __construct()
    {
    }

    /**
     * Normalizes a source delivery object. Unknown keys (an email among them) are rejected.
     *
     * @param array<string, mixed> $input
     * @return array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null
     */
    public static function fromSource(array $input): ?array
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Unsupported delivery field: {$key}");
            }
        }
        $street = self::text($input['street'] ?? null, 300);
        $postalCity = trim(implode(' ', array_filter([self::text($input['postalCode'] ?? null, 20), self::text($input['city'] ?? null, 120)])));
        return self::build(
            self::text($input['name'] ?? null, 160),
            self::text($input['company'] ?? null, 200),
            array_values(array_filter([$street, $postalCity === '' ? null : $postalCity, self::text($input['county'] ?? null, 120), self::text($input['country'] ?? null, 80)])),
            self::maskPhone($input['phone'] ?? null),
        );
    }

    /**
     * @param list<string> $addressLines
     * @return array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null
     */
    public static function build(?string $name, ?string $company, array $addressLines, ?string $phoneMasked): ?array
    {
        $context = ['name' => $name, 'company' => $company, 'addressLines' => array_slice($addressLines, 0, 5), 'phoneMasked' => $phoneMasked];
        return $name === null && $company === null && $addressLines === [] && $phoneMasked === null ? null : $context;
    }

    /** Keeps only the first two digits: "0722 123 456" becomes "07** *** ***". */
    public static function maskPhone(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (str_starts_with($digits, '0040')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '40') && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }
        $length = strlen($digits);
        if ($length < 6 || $length > 15) {
            return null;
        }
        if ($length === 10) {
            return substr($digits, 0, 2) . '** *** ***';
        }
        $masked = substr($digits, 0, 2) . str_repeat('*', $length - 2);
        return trim(implode(' ', str_split($masked, 3)));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid delivery text.');
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
