<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Finance\Import\ImportNormalizer;
use PDO;

/**
 * Finds possible duplicates of a company candidate among canonical B2B companies and other verification cases.
 * Strength: exact (same country and normalized tax identifier), strong (same VAT or registration number, same
 * normalized name in the same country, same legacy customer code), weak (same normalized name only, same phone).
 * Every query is indexed or bounded by LIMIT. Nothing is ever merged: the result only feeds a human decision.
 */
final readonly class DuplicateFinder
{
    private const RANK = ['weak' => 1, 'strong' => 2, 'exact' => 3];
    private const LIMIT = 25;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $v normalized candidate (ImportNormalizer keys)
     * @return list<array{companyId: ?string, caseId: ?string, strength: string, indicators: list<string>}>
     */
    public function find(array $v, ?string $ownCaseUuid = null): array
    {
        $found = [];
        $add = static function (string $kind, string $id, string $strength, string $indicator) use (&$found): void {
            $key = $kind . ':' . $id;
            if (!isset($found[$key])) {
                $found[$key] = ['companyId' => $kind === 'company' ? $id : null, 'caseId' => $kind === 'case' ? $id : null, 'strength' => $strength, 'indicators' => []];
            } elseif (self::RANK[$strength] > self::RANK[$found[$key]['strength']]) {
                $found[$key]['strength'] = $strength;
            }
            if (!in_array($indicator, $found[$key]['indicators'], true)) {
                $found[$key]['indicators'][] = $indicator;
            }
        };
        $country = $v['countryCode'] ?? null;
        $tax = $v['taxNormalized'] ?? null;
        $nameKey = $v['nameKey'] ?? null;

        // Canonical B2B companies.
        if ($country !== null && $tax !== null) {
            foreach ($this->column('SELECT company_uuid FROM b2b_companies WHERE country_code = ? AND tax_identifier_normalized = ? LIMIT 2', [$country, $tax]) as $id) {
                $add('company', $id, 'exact', 'tax_id');
            }
        }
        foreach (['vatNormalized' => 'vat_number', 'registrationNormalized' => 'registration_number'] as $field => $column) {
            if (($v[$field] ?? null) !== null) {
                $sql = "SELECT company_uuid FROM b2b_companies WHERE {$column} IS NOT NULL
                    AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '.', ''), '-', ''), '/', ''), '_', '')) = ? LIMIT " . self::LIMIT;
                foreach ($this->column($sql, [$v[$field]]) as $id) {
                    $add('company', $id, 'strong', $field === 'vatNormalized' ? 'vat' : 'registration');
                }
            }
        }
        if ($nameKey !== null) {
            $first = explode(' ', $nameKey)[0];
            $s = $this->pdo->prepare('SELECT company_uuid, legal_name, display_name, country_code FROM b2b_companies WHERE legal_name LIKE ? OR display_name LIKE ? LIMIT 60');
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $first) . '%';
            $s->execute([$like, $like]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $keys = array_filter([ImportNormalizer::nameKey((string) $row['legal_name']), $row['display_name'] === null ? null : ImportNormalizer::nameKey((string) $row['display_name'])]);
                if (in_array($nameKey, $keys, true)) {
                    $sameCountry = $country !== null && $country === $row['country_code'];
                    $add('company', (string) $row['company_uuid'], $sameCountry ? 'strong' : 'weak', $sameCountry ? 'name_country' : 'name');
                }
            }
        }

        // Other verification cases (rejected cases are not candidates).
        $own = $ownCaseUuid ?? '';
        if ($country !== null && $tax !== null) {
            foreach ($this->column("SELECT case_uuid FROM finance_company_cases WHERE country_code = ? AND tax_normalized = ? AND case_uuid <> ? AND status <> 'REJECTED' LIMIT " . self::LIMIT, [$country, $tax, $own]) as $id) {
                $add('case', $id, 'exact', 'tax_id');
            }
        }
        foreach (['vatNormalized' => 'vat_normalized', 'registrationNormalized' => 'registration_normalized', 'legacyCustomerCode' => 'legacy_customer_code'] as $field => $column) {
            if (($v[$field] ?? null) !== null) {
                foreach ($this->column("SELECT case_uuid FROM finance_company_cases WHERE {$column} = ? AND case_uuid <> ? AND status <> 'REJECTED' LIMIT " . self::LIMIT, [$v[$field], $own]) as $id) {
                    $add('case', $id, 'strong', match ($field) { 'vatNormalized' => 'vat', 'registrationNormalized' => 'registration', default => 'legacy_code' });
                }
            }
        }
        if ($nameKey !== null) {
            $s = $this->pdo->prepare("SELECT case_uuid, country_code FROM finance_company_cases WHERE name_key = ? AND case_uuid <> ? AND status <> 'REJECTED' LIMIT " . self::LIMIT);
            $s->execute([$nameKey, $own]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sameCountry = $country !== null && $country === $row['country_code'];
                $add('case', (string) $row['case_uuid'], $sameCountry ? 'strong' : 'weak', $sameCountry ? 'name_country' : 'name');
            }
        }
        if (($v['phoneKey'] ?? null) !== null) {
            foreach ($this->column("SELECT case_uuid FROM finance_company_cases WHERE phone_key = ? AND case_uuid <> ? AND status <> 'REJECTED' LIMIT " . self::LIMIT, [$v['phoneKey'], $own]) as $id) {
                $add('case', $id, 'weak', 'phone');
            }
        }
        $out = array_values($found);
        usort($out, static fn (array $a, array $b): int => self::RANK[$b['strength']] <=> self::RANK[$a['strength']]);
        return $out;
    }

    /** The strongest strength in a result, or 'none'. @param list<array{strength: string}> $matches */
    public static function strongest(array $matches): string
    {
        $best = 'none';
        foreach ($matches as $match) {
            if ($best === 'none' || self::RANK[$match['strength']] > self::RANK[$best]) {
                $best = $match['strength'];
            }
        }
        return $best;
    }

    /** @return list<string> */
    private function column(string $sql, array $params): array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
}
