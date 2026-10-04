<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;

/**
 * Server-owned validation and normalization of B2B company, contact and address input.
 *
 * Text is trimmed and otherwise preserved as entered: legal names are never rewritten. Only identifiers are
 * canonicalized (country code upper-case, e-mail lower-case, tax identifier normalized for duplicate detection).
 * Failures are reported per field as stable reason codes (required, too_long, invalid), never echoing the value.
 */
final class CompanyInput
{
    public const COMPANY_FIELDS = ['legalName', 'displayName', 'countryCode', 'taxIdentifier', 'vatNumber', 'registrationNumber', 'website', 'internalNotes'];
    public const CONTACT_FIELDS = ['name', 'jobTitle', 'email', 'phone', 'isPrimary'];
    public const ADDRESS_FIELDS = ['type', 'label', 'countryCode', 'countyRegion', 'city', 'postalCode', 'addressLine1', 'addressLine2', 'isPrimary'];
    public const ADDRESS_TYPES = ['billing', 'delivery', 'office', 'other'];
    public const NOTES_MAX = 5000;

    /** Greece uses EL, not GR, as its EU VAT prefix. */
    private const VAT_PREFIX_ALIASES = ['GR' => ['GR', 'EL']];

    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(private readonly string $prefix)
    {
    }

    /**
     * Normalizes a fiscal identifier for duplicate detection: upper-case, without spaces, dots, dashes or
     * slashes, and without a leading VAT prefix of its own country (so RO12345678 and 12345678 are the same
     * Romanian company). Returns null when nothing usable remains.
     */
    public static function normalizeTaxIdentifier(string $value, string $countryCode): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[\s.\-\/]+/u', '', $value));
        foreach (self::VAT_PREFIX_ALIASES[$countryCode] ?? [$countryCode] as $prefix) {
            if (strlen($normalized) > strlen($prefix) && str_starts_with($normalized, $prefix) && ctype_digit($normalized[strlen($prefix)])) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }
        return preg_match('/^[A-Z0-9]{1,64}$/D', $normalized) === 1 ? $normalized : null;
    }

    /** Search text for tax identifiers: the same canonical form without country-prefix stripping. */
    public static function taxSearchTerm(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{legalName: string, displayName: ?string, countryCode: string, taxIdentifier: string, taxIdentifierNormalized: string, vatNumber: ?string, registrationNumber: ?string, website: ?string, internalNotes: ?string}
     */
    public static function company(array $input): array
    {
        $v = new self('');
        $company = $v->companyFields($input);
        $v->throwIfInvalid();
        return $company;
    }

    /**
     * A new company, optionally with a first contact and a first address in the same request.
     *
     * @param array<string, mixed> $input
     * @return array{company: array<string, mixed>, contact: ?array<string, mixed>, address: ?array<string, mixed>}
     */
    public static function creation(array $input): array
    {
        $v = new self('');
        $companyInput = array_intersect_key($input, array_flip(self::COMPANY_FIELDS));
        $company = $v->companyFields($companyInput);
        $contact = null;
        $address = null;
        if (array_key_exists('contact', $input) && $input['contact'] !== null) {
            $contact = is_array($input['contact']) && !array_is_list($input['contact']) && self::knownKeys($input['contact'], self::CONTACT_FIELDS)
                ? (new self('contact.'))->collectInto($v, fn (self $nested): array => $nested->contactFields($input['contact']))
                : $v->fail('contact', 'invalid');
        }
        if (array_key_exists('address', $input) && $input['address'] !== null) {
            $address = is_array($input['address']) && !array_is_list($input['address']) && self::knownKeys($input['address'], self::ADDRESS_FIELDS)
                ? (new self('address.'))->collectInto($v, fn (self $nested): array => $nested->addressFields($input['address']))
                : $v->fail('address', 'invalid');
        }
        $v->throwIfInvalid();
        return ['company' => $company, 'contact' => $contact, 'address' => $address];
    }

    /** @param array<string, mixed> $input @return array{name: string, jobTitle: ?string, email: ?string, phone: ?string, isPrimary: bool} */
    public static function contact(array $input): array
    {
        $v = new self('');
        $contact = $v->contactFields($input);
        $v->throwIfInvalid();
        return $contact;
    }

    /** @param array<string, mixed> $input @return array{type: string, label: ?string, countryCode: string, countyRegion: ?string, city: string, postalCode: ?string, addressLine1: string, addressLine2: ?string, isPrimary: bool} */
    public static function address(array $input): array
    {
        $v = new self('');
        $address = $v->addressFields($input);
        $v->throwIfInvalid();
        return $address;
    }

    /** Only documented keys; missing optional keys mean "empty". @param array<string, mixed> $input @param list<string> $keys */
    public static function knownKeys(array $input, array $keys): bool
    {
        return array_diff(array_keys($input), $keys) === [];
    }

    /** @param array<string, mixed> $input @param list<string> $keys */
    public static function exactKeys(array $input, array $keys): bool
    {
        $actual = array_keys($input);
        return array_diff($actual, $keys) === [] && array_diff($keys, $actual) === [];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function companyFields(array $input): array
    {
        $country = CountryCodes::normalize($input['countryCode'] ?? null);
        if ($country === null) {
            $this->fail('countryCode', ($input['countryCode'] ?? null) === null || $input['countryCode'] === '' ? 'required' : 'invalid');
        }
        $tax = $this->text($input, 'taxIdentifier', 64, true);
        $taxNormalized = null;
        if ($tax !== null) {
            if (preg_match('/^[\p{L}\p{N} .\-\/]+$/uD', $tax) !== 1) {
                $this->fail('taxIdentifier', 'invalid');
            } elseif ($country !== null) {
                $taxNormalized = self::normalizeTaxIdentifier($tax, $country) ?? $this->fail('taxIdentifier', 'invalid');
            }
        }
        $vat = $this->text($input, 'vatNumber', 64);
        if ($vat !== null && preg_match('/^[\p{L}\p{N} .\-\/]+$/uD', $vat) !== 1) {
            $this->fail('vatNumber', 'invalid');
        }
        return [
            'legalName' => $this->text($input, 'legalName', 255, true) ?? '',
            'displayName' => $this->text($input, 'displayName', 255),
            'countryCode' => $country ?? '',
            'taxIdentifier' => $tax ?? '',
            'taxIdentifierNormalized' => is_string($taxNormalized) ? $taxNormalized : '',
            'vatNumber' => $vat,
            'registrationNumber' => $this->text($input, 'registrationNumber', 64),
            'website' => $this->website($input),
            'internalNotes' => $this->text($input, 'internalNotes', self::NOTES_MAX, false, true),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function contactFields(array $input): array
    {
        $email = $this->text($input, 'email', 254);
        if ($email !== null) {
            $email = mb_strtolower($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->fail('email', 'invalid');
            }
        }
        $phone = $this->text($input, 'phone', 40);
        if ($phone !== null && (preg_match('/^\+?[0-9 ().\/-]+$/D', $phone) !== 1 || preg_match_all('/\d/', $phone) < 6)) {
            $this->fail('phone', 'invalid');
        }
        return [
            'name' => $this->text($input, 'name', 160, true) ?? '',
            'jobTitle' => $this->text($input, 'jobTitle', 120),
            'email' => $email,
            'phone' => $phone,
            'isPrimary' => $this->flag($input, 'isPrimary'),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function addressFields(array $input): array
    {
        $type = $input['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::ADDRESS_TYPES, true)) {
            $this->fail('type', $type === null || $type === '' ? 'required' : 'invalid');
        }
        $country = CountryCodes::normalize($input['countryCode'] ?? null);
        if ($country === null) {
            $this->fail('countryCode', ($input['countryCode'] ?? null) === null || $input['countryCode'] === '' ? 'required' : 'invalid');
        }
        $postal = $this->text($input, 'postalCode', 20);
        if ($postal !== null && preg_match('/^[\p{L}\p{N} \-]+$/uD', $postal) !== 1) {
            $this->fail('postalCode', 'invalid');
        }
        return [
            'type' => is_string($type) ? $type : '',
            'label' => $this->text($input, 'label', 120),
            'countryCode' => $country ?? '',
            'countyRegion' => $this->text($input, 'countyRegion', 120),
            'city' => $this->text($input, 'city', 120, true) ?? '',
            'postalCode' => $postal,
            'addressLine1' => $this->text($input, 'addressLine1', 255, true) ?? '',
            'addressLine2' => $this->text($input, 'addressLine2', 255),
            'isPrimary' => $this->flag($input, 'isPrimary'),
        ];
    }

    /**
     * Trimmed text, or null when absent or blank. Single-line fields reject control characters and line breaks;
     * multi-line fields (notes) accept line breaks and tabs.
     *
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $field, int $max, bool $required = false, bool $multiline = false): ?string
    {
        $value = $input[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            return $this->fail($field, 'invalid');
        }
        $value = $value === null ? '' : trim($value);
        if ($multiline) {
            $value = str_replace("\r\n", "\n", $value);
        }
        if ($value === '') {
            return $required ? $this->fail($field, 'required') : null;
        }
        if (mb_strlen($value) > $max) {
            return $this->fail($field, 'too_long');
        }
        $forbidden = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($forbidden, $value) === 1) {
            return $this->fail($field, 'invalid');
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private function website(array $input): ?string
    {
        $value = $this->text($input, 'website', 255);
        if ($value === null) {
            return null;
        }
        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1 ? $value : 'https://' . $value;
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/iD', $host) !== 1 || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 255) {
            return $this->fail('website', 'invalid');
        }
        return $url;
    }

    /** @param array<string, mixed> $input */
    private function flag(array $input, string $field): bool
    {
        $value = $input[$field] ?? false;
        if (!is_bool($value)) {
            $this->fail($field, 'invalid');
            return false;
        }
        return $value;
    }

    private function fail(string $field, string $reason): null
    {
        $this->errors[$this->prefix . $field] ??= $reason;
        return null;
    }

    /** Runs a nested validator and merges its field errors into this one. @param callable(self): array<string, mixed> $validate @return array<string, mixed> */
    private function collectInto(self $parent, callable $validate): array
    {
        $result = $validate($this);
        foreach ($this->errors as $field => $reason) {
            $parent->errors[$field] ??= $reason;
        }
        return $result;
    }

    private function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            ksort($this->errors);
            throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', ['fields' => $this->errors]);
        }
    }
}
