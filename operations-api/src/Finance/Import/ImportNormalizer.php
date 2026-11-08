<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Import;

use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\B2B\CompanyInput;
use Arasya\Operations\B2B\CountryCodes;

/**
 * Turns one mapped import row into a normalized company candidate plus issue codes. Normalization only canonicalizes
 * what is present (case, spacing, separators, recognizable country names); it never invents a value. A syntactically
 * valid tax identifier is reported as "format_ok", which is not an external verification.
 */
final class ImportNormalizer
{
    public const FIELDS = [
        'legalName', 'commercialName', 'countryCode', 'taxIdentifier', 'vatNumber', 'registrationNumber',
        'addressLine', 'city', 'postalCode', 'countyRegion', 'contactName', 'phone', 'email', 'website',
        'currency', 'legacyCustomerCode', 'historicalDebit', 'historicalCredit', 'lastTransactionDate', 'notes',
    ];

    /** Fields whose absence keeps a candidate "incomplete" (it still enters the review queue). */
    public const KEY_FIELDS = ['legalName', 'countryCode', 'taxIdentifier'];

    /** Header synonyms (normalized: lower-case ASCII letters and digits only), RO / TR / EN / SAGA / SmartBill. */
    private const SYNONYMS = [
        'legalName' => ['denumire', 'denumirefirma', 'denumirecompanie', 'numefirma', 'firma', 'companie', 'client', 'numeclient', 'denumireclient', 'partener', 'legalname', 'companyname', 'company', 'name', 'unvan', 'sirketunvani', 'firmaadi', 'firmaunvani', 'ticariunvan', 'musteri', 'musteriadi'],
        'commercialName' => ['numecomercial', 'denumirecomerciala', 'tradingname', 'commercialname', 'displayname', 'marka', 'ticariad', 'kisaad'],
        'countryCode' => ['tara', 'taracod', 'codtara', 'country', 'countrycode', 'ulke', 'ulkekodu', 'stat'],
        'taxIdentifier' => ['cui', 'cif', 'codfiscal', 'codunicinregistrare', 'cuicif', 'taxid', 'taxidentifier', 'taxnumber', 'fiscalcode', 'vergino', 'vergínumarasi', 'verginumarasi', 'vkn', 'vergikimlikno', 'steuernummer'],
        'vatNumber' => ['codtva', 'cuitva', 'vat', 'vatnumber', 'vatid', 'tvaintracomunitar', 'kdvno', 'ustid', 'ustidnr'],
        'registrationNumber' => ['nrregcom', 'nrregistrucomertului', 'registrucomert', 'regcom', 'nrorc', 'registrationnumber', 'companyregistration', 'ticaretsicilno', 'sicilno', 'mersisno'],
        'addressLine' => ['adresa', 'adresasediu', 'sediu', 'strada', 'address', 'addressline', 'street', 'adres', 'acikadres'],
        'city' => ['localitate', 'oras', 'municipiu', 'city', 'town', 'sehir', 'il', 'ilce'],
        'postalCode' => ['codpostal', 'postalcode', 'zip', 'zipcode', 'postakodu'],
        'countyRegion' => ['judet', 'judetul', 'county', 'region', 'state', 'province', 'bolge'],
        'contactName' => ['persoanacontact', 'contact', 'reprezentant', 'contactperson', 'contactname', 'yetkili', 'ilgilikisi'],
        'phone' => ['telefon', 'tel', 'mobil', 'phone', 'telephone', 'mobile', 'gsm', 'cep'],
        'email' => ['email', 'mail', 'adresaemail', 'eposta', 'emailadresi'],
        'website' => ['website', 'web', 'site', 'pagina', 'websitesi'],
        'currency' => ['moneda', 'valuta', 'currency', 'parabirimi', 'doviz'],
        'legacyCustomerCode' => ['codclient', 'cod', 'codpartener', 'customercode', 'clientcode', 'customerid', 'musterikodu', 'carikod', 'carikodu'],
        'historicalDebit' => ['sold', 'solddebitor', 'debit', 'debt', 'datorie', 'rest', 'restdeplata', 'receivable', 'borc', 'bakiye'],
        'historicalCredit' => ['soldcreditor', 'credit', 'avans', 'alacak'],
        'lastTransactionDate' => ['ultimatranzactie', 'dataultimeitranzactii', 'lasttransaction', 'lasttransactiondate', 'sonislem', 'sonislemtarihi'],
        'notes' => ['observatii', 'mentiuni', 'note', 'notes', 'comments', 'aciklama', 'notlar'],
    ];

    /** Country names seen in RO/TR/EN exports, normalized like headers, to ISO alpha-2. */
    private const COUNTRY_NAMES = [
        'romania' => 'RO', 'rou' => 'RO', 'romanya' => 'RO', 'germania' => 'DE', 'germany' => 'DE', 'deutschland' => 'DE', 'almanya' => 'DE', 'deu' => 'DE',
        'franta' => 'FR', 'france' => 'FR', 'fransa' => 'FR', 'fra' => 'FR', 'italia' => 'IT', 'italy' => 'IT', 'italya' => 'IT', 'ita' => 'IT',
        'turcia' => 'TR', 'turkey' => 'TR', 'turkiye' => 'TR', 'tur' => 'TR', 'bulgaria' => 'BG', 'bulgaristan' => 'BG', 'bgr' => 'BG',
        'marea britanie' => 'GB', 'mareabritanie' => 'GB', 'regatulunit' => 'GB', 'unitedkingdom' => 'GB', 'uk' => 'GB', 'ingiltere' => 'GB', 'birlesikkrallik' => 'GB', 'gbr' => 'GB',
        'sua' => 'US', 'usa' => 'US', 'unitedstates' => 'US', 'statelunite' => 'US', 'abd' => 'US', 'amerika' => 'US',
        'moldova' => 'MD', 'republicamoldova' => 'MD', 'ungaria' => 'HU', 'hungary' => 'HU', 'macaristan' => 'HU', 'austria' => 'AT', 'avusturya' => 'AT',
        'spania' => 'ES', 'spain' => 'ES', 'ispanya' => 'ES', 'olanda' => 'NL', 'netherlands' => 'NL', 'hollanda' => 'NL', 'belgia' => 'BE', 'belgium' => 'BE', 'belcika' => 'BE',
        'polonia' => 'PL', 'poland' => 'PL', 'polonya' => 'PL', 'grecia' => 'GR', 'greece' => 'GR', 'yunanistan' => 'GR', 'serbia' => 'RS', 'sirbistan' => 'RS',
        'ucraina' => 'UA', 'ukraine' => 'UA', 'ukrayna' => 'UA', 'elvetia' => 'CH', 'switzerland' => 'CH', 'isvicre' => 'CH', 'cehia' => 'CZ', 'czechia' => 'CZ', 'cekya' => 'CZ',
    ];

    private const LEGAL_FORMS = ['SC', 'SRL', 'SA', 'SRLD', 'PFA', 'II', 'IF', 'SNC', 'SCS', 'LTD', 'LIMITED', 'LLC', 'INC', 'CORP', 'CO', 'PLC', 'GMBH', 'AG', 'KG', 'OHG', 'UG',
        'SAS', 'SARL', 'EURL', 'SPA', 'SRLS', 'BV', 'NV', 'AS', 'AB', 'OY', 'EOOD', 'OOD', 'AD', 'KFT', 'ZRT', 'SPZOO', 'SRO', 'STI', 'TIC', 'SAN', 'VE', 'LTDSTI', 'AS'];

    /** @param list<string> $headers @return array<int, string> column index => suggested field */
    public static function suggestMapping(array $headers): array
    {
        $out = [];
        $used = [];
        foreach ($headers as $index => $header) {
            $key = self::key($header);
            foreach (self::SYNONYMS as $field => $synonyms) {
                if (!isset($used[$field]) && in_array($key, $synonyms, true)) {
                    $out[$index] = $field;
                    $used[$field] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<int, string> $mapping column index => field
     * @param list<string> $cells
     * @return array{values: array<string, mixed>, issues: array<string, string>, outcome: string, fingerprint: string}
     */
    public static function normalize(array $mapping, array $cells, string $sourceSystem): array
    {
        $raw = [];
        foreach ($mapping as $index => $field) {
            $value = trim((string) ($cells[$index] ?? ''));
            if ($value !== '' && !isset($raw[$field])) {
                $raw[$field] = $value;
            }
        }
        $issues = [];
        $v = array_fill_keys(self::FIELDS, null);
        $v['legalName'] = self::clip($raw['legalName'] ?? null, 255);
        $v['commercialName'] = self::clip($raw['commercialName'] ?? null, 255);
        if ($v['legalName'] === null && $v['commercialName'] !== null) {
            $issues['legalName'] = 'missing_used_commercial_name';
        }
        $country = null;
        if (isset($raw['countryCode'])) {
            $country = self::country($raw['countryCode']);
            if ($country === null) {
                $issues['countryCode'] = 'unrecognized';
            }
        }
        $v['countryCode'] = $country;
        if (isset($raw['taxIdentifier'])) {
            $v['taxIdentifier'] = self::clip($raw['taxIdentifier'], 64);
            if ($country === null) {
                $issues['taxIdentifier'] = 'country_required';
            } else {
                $normalized = CompanyInput::normalizeTaxIdentifier((string) $v['taxIdentifier'], $country);
                if ($normalized === null) {
                    $issues['taxIdentifier'] = 'format_invalid';
                } else {
                    $v['taxNormalized'] = $normalized;
                    if ($country === 'RO' && !self::validRomanianCui($normalized)) {
                        $issues['taxIdentifier'] = 'checksum_invalid';
                    }
                }
            }
        }
        $v['taxNormalized'] ??= null;
        $v['vatNumber'] = self::clip($raw['vatNumber'] ?? null, 64);
        $v['vatNormalized'] = $v['vatNumber'] === null ? null : (self::alnum($v['vatNumber']) ?: null);
        $v['registrationNumber'] = self::clip($raw['registrationNumber'] ?? null, 64);
        $v['registrationNormalized'] = $v['registrationNumber'] === null ? null : (self::alnum($v['registrationNumber']) ?: null);
        $v['addressLine'] = self::clip($raw['addressLine'] ?? null, 255);
        $v['city'] = self::clip($raw['city'] ?? null, 120);
        $v['postalCode'] = self::clip($raw['postalCode'] ?? null, 20);
        $v['countyRegion'] = self::clip($raw['countyRegion'] ?? null, 120);
        $v['contactName'] = self::clip($raw['contactName'] ?? null, 160);
        $v['phone'] = self::clip($raw['phone'] ?? null, 40);
        $v['phoneKey'] = self::phoneKey($v['phone']);
        if ($v['phone'] !== null && $v['phoneKey'] === null) {
            $issues['phone'] = 'format_invalid';
        }
        if (isset($raw['email'])) {
            $email = strtolower($raw['email']);
            if (strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $v['email'] = $email;
            } else {
                $issues['email'] = 'format_invalid';
            }
        }
        $v['website'] = self::clip($raw['website'] ?? null, 255);
        if (isset($raw['currency'])) {
            $v['currency'] = self::currency($raw['currency']);
            if ($v['currency'] === null) {
                $issues['currency'] = 'unrecognized';
            }
        }
        $v['legacyCustomerCode'] = self::clip($raw['legacyCustomerCode'] ?? null, 64);
        foreach (['historicalDebit', 'historicalCredit'] as $field) {
            if (isset($raw[$field])) {
                $cents = self::amount($raw[$field]);
                if ($cents === null) {
                    $issues[$field] = 'format_invalid';
                } elseif ($cents < 0) {
                    $issues[$field] = 'negative';
                } else {
                    $v[$field] = AccountMoney::format($cents);
                }
            }
        }
        if (($v['historicalDebit'] !== null || $v['historicalCredit'] !== null) && $v['currency'] === null) {
            $issues['currency'] = $issues['currency'] ?? 'required_for_amount';
        }
        if (isset($raw['lastTransactionDate'])) {
            $v['lastTransactionDate'] = self::date($raw['lastTransactionDate']);
            if ($v['lastTransactionDate'] === null) {
                $issues['lastTransactionDate'] = 'format_invalid';
            }
        }
        $v['notes'] = self::clip($raw['notes'] ?? null, 2000);
        $name = $v['legalName'] ?? $v['commercialName'];
        $v['nameKey'] = $name === null ? null : self::nameKey($name);
        foreach (self::KEY_FIELDS as $field) {
            $present = $field === 'taxIdentifier' ? $v['taxNormalized'] !== null : $v[$field] !== null;
            if (!$present && !isset($issues[$field])) {
                $issues[$field] = 'missing';
            }
        }
        if ($name === null || $v['nameKey'] === null) {
            $outcome = 'rejected';
        } else {
            $complete = $v['legalName'] !== null && $v['countryCode'] !== null && $v['taxNormalized'] !== null && !isset($issues['taxIdentifier']);
            $outcome = $complete ? 'ready' : 'incomplete';
        }
        $identity = $v;
        unset($identity['notes']);
        ksort($identity);
        $fingerprint = hash('sha256', $sourceSystem . '|' . json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
        return ['values' => $v, 'issues' => $issues, 'outcome' => $outcome, 'fingerprint' => $fingerprint];
    }

    /** Upper-case ASCII name without punctuation and legal-form words: "S.C. Ana Textil S.R.L." -> "ANA TEXTIL". */
    public static function nameKey(string $name): ?string
    {
        $ascii = self::ascii($name);
        $ascii = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $ascii));
        $ascii = (string) preg_replace('/\b([A-Z])\s(?=[A-Z]\b)/', '$1', $ascii);
        $words = array_values(array_filter(explode(' ', $ascii), static fn (string $w): bool => $w !== '' && !in_array($w, self::LEGAL_FORMS, true)));
        $key = implode(' ', $words);
        return strlen($key) >= 2 ? substr($key, 0, 255) : null;
    }

    public static function phoneKey(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $phone);
        return strlen($digits) >= 7 && strlen($digits) <= 18 ? substr($digits, -9) : null;
    }

    public static function country(string $value): ?string
    {
        $iso = CountryCodes::normalize($value);
        if ($iso !== null) {
            return $iso;
        }
        return self::COUNTRY_NAMES[self::key($value)] ?? null;
    }

    /** Romanian CUI/CIF control digit (key 753217532). */
    public static function validRomanianCui(string $cui): bool
    {
        if (preg_match('/^\d{2,10}$/D', $cui) !== 1) {
            return false;
        }
        $control = (int) substr($cui, -1);
        $body = str_pad(substr($cui, 0, -1), 9, '0', STR_PAD_LEFT);
        $key = '753217532';
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $body[$i] * (int) $key[$i];
        }
        $check = ($sum * 10) % 11;
        return ($check === 10 ? 0 : $check) === $control;
    }

    /** Exact cents from "1.234,56", "1,234.56", "1234.56", "1 234,5", "-12"; null when not a number. */
    public static function amount(string $value): ?int
    {
        $s = str_replace(["\u{00A0}", ' ', "'", 'RON', 'EUR', 'LEI', 'lei', '€'], '', trim($value));
        $negative = false;
        if (preg_match('/^\((.*)\)$/D', $s, $m) === 1) {
            $negative = true;
            $s = $m[1];
        }
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        }
        if ($s === '' || preg_match('/^[0-9.,]+$/D', $s) !== 1) {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        $decimal = null;
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false) {
            $decimal = preg_match('/,\d{1,2}$/D', $s) === 1 && substr_count($s, ',') === 1 ? ',' : null;
        } elseif ($lastDot !== false) {
            $decimal = preg_match('/\.\d{1,2}$/D', $s) === 1 && substr_count($s, '.') === 1 ? '.' : null;
        }
        if ($decimal === null) {
            $integer = str_replace([',', '.'], '', $s);
            $fraction = '';
        } else {
            $position = strrpos($s, $decimal);
            $integer = str_replace([',', '.'], '', substr($s, 0, (int) $position));
            $fraction = substr($s, (int) $position + 1);
            if (preg_match('/^\d{1,2}$/D', $fraction) !== 1) {
                return null;
            }
        }
        $integer = ltrim($integer, '0');
        if ($integer === '') {
            $integer = '0';
        }
        if (strlen($integer) > 10 || preg_match('/^\d+$/D', $integer) !== 1) {
            return null;
        }
        $cents = (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
        return $negative ? -$cents : $cents;
    }

    public static function date(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4,5}(\.\d+)?$/D', $value) === 1 && (float) $value > 20000 && (float) $value < 80000) {
            return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify('+' . (int) $value . ' days')->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!d.m.Y', '!d/m/Y', '!d-m-Y', '!Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, substr($value, 0, 10), new \DateTimeZone('UTC'));
            if ($date !== false && $date->format(substr($format, 1)) === substr($value, 0, 10)) {
                $iso = $date->format('Y-m-d');
                return $iso >= '1990-01-01' && $iso <= '2099-12-31' ? $iso : null;
            }
        }
        return null;
    }

    public static function currency(string $value): ?string
    {
        $key = strtoupper(trim($value));
        return match (true) {
            in_array($key, ['RON', 'LEI', 'LEU', 'ROL'], true) => 'RON',
            in_array($key, ['EUR', 'EURO', '€'], true) => 'EUR',
            preg_match('/^[A-Z]{3}$/D', $key) === 1 => $key,
            default => null,
        };
    }

    /** Lower-case ASCII letters and digits only (used for header and country-name matching). */
    public static function key(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', self::ascii($value)));
    }

    private static function alnum(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', self::ascii($value)));
    }

    private static function ascii(string $value): string
    {
        $map = ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
            'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ü' => 'u', 'Ü' => 'U', 'ä' => 'a', 'Ä' => 'A', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'á' => 'a', 'à' => 'a', 'ó' => 'o', 'ò' => 'o', 'ú' => 'u', 'í' => 'i', 'ñ' => 'n', 'Ñ' => 'N', 'ł' => 'l', 'Ł' => 'L', 'ć' => 'c', 'č' => 'c', 'š' => 's', 'ž' => 'z', 'ő' => 'o', 'ű' => 'u'];
        return strtr($value, $map);
    }

    private static function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
