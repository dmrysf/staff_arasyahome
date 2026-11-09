<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Account;

use Arasya\Operations\B2B\CountryCodes;
use Arasya\Operations\Http\ApiException;

/**
 * Owner policy (Finance V0.3): the canonical current-account currency follows the verified registered country only.
 * Romania -> RON; every other country -> EUR, whatever its legal tender. Never derived from language, IP, nationality,
 * invoice language or an inconsistent billing address. Unknown country -> no currency (never a default).
 */
final class CurrencyPolicy
{
    public static function forCountry(?string $country): string
    {
        $code = $country === null ? null : CountryCodes::normalize($country);
        if ($code === null) {
            throw new ApiException(409, 'VERIFIED_COUNTRY_REQUIRED', 'A verified registered country is required to set the account currency.');
        }
        return $code === 'RO' ? 'RON' : 'EUR';
    }
}
