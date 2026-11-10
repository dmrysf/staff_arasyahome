<?php

declare(strict_types=1);

namespace Arasya\Operations\Config;

/**
 * Pre-activation check of the Finance configuration (API 2.63.0).
 *
 * Before 2.63.0 ConfigLoader dropped every ARASYA_FINANCE_* key, so a value already written in the private configuration
 * had no effect. From 2.63.0 it takes effect. A deployment must never turn on a value that nobody reviewed, so the
 * expected state until a separately authorized provisioning step is:
 *
 * - ARASYA_FINANCE_OPENING_BALANCE_POSTING absent or exactly `disabled`;
 * - ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS absent or empty (nobody makes an effective colour or limit decision);
 * - ARASYA_FINANCE_CEO_EMPLOYEE_UUID absent or empty (nobody decides a CEO exception);
 * - ARASYA_B2B_FINANCE_GATE absent or `off` (no finance decision at B2B finalization; API 2.67.0);
 * - ARASYA_B2B_FINANCE_RELEASE_GATE absent or `off` (no finance release check at B2B production submission; absent
 *   inherits ARASYA_B2B_FINANCE_GATE).
 *
 * Any other well-formed value blocks the deployment until an authorized person records ARASYA_FINANCE_CONFIG_ACK, the
 * SHA-256 fingerprint of the exact reviewed values. Changing any value later makes that acknowledgement stale, and the
 * next deployment is blocked again. A malformed value always blocks: the API would refuse to start with it.
 *
 * The result names keys and states only. It never contains a configured value.
 */
final class FinanceConfigGuard
{
    public const POSTING = 'ARASYA_FINANCE_OPENING_BALANCE_POSTING';
    public const CEO = 'ARASYA_FINANCE_CEO_EMPLOYEE_UUID';
    public const AUTHORITY = 'ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS';
    public const ACK = 'ARASYA_FINANCE_CONFIG_ACK';
    public const B2B_GATE = 'ARASYA_B2B_FINANCE_GATE';
    public const B2B_RELEASE_GATE = 'ARASYA_B2B_FINANCE_RELEASE_GATE';
    public const FINANCE_ORIGIN = 'https://finance.arasyahome.ro';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /**
     * @param array<string, string> $values the output of ConfigLoader::load()
     * @return array{ok: bool, defaults: bool, fingerprint: string, checks: list<array{state: string, check: string}>}
     */
    public static function inspect(array $values): array
    {
        $checks = [];
        $add = static function (string $state, string $check) use (&$checks): void {
            $checks[] = ['state' => $state, 'check' => $check];
        };

        $postingSet = array_key_exists(self::POSTING, $values);
        $posting = trim($values[self::POSTING] ?? '');
        $ceo = strtolower(trim($values[self::CEO] ?? ''));
        // Parsed exactly as Config does: comma-separated, trimmed, empty entries ignored, lower case.
        $authority = array_values(array_filter(array_map('trim', explode(',', strtolower(trim($values[self::AUTHORITY] ?? '')))), static fn (string $uuid): bool => $uuid !== ''));

        $malformed = false;
        if ($postingSet && !in_array($posting, ['disabled', 'enabled'], true)) {
            // Config would treat it as off, but nobody can tell what the operator meant. Write `disabled` or remove it.
            $add('FAIL', 'finance_opening_balance_posting_unrecognized');
            $malformed = true;
        } else {
            $add($posting === 'enabled' ? 'WARN' : 'OK', 'finance_opening_balance_posting_' . ($posting === 'enabled' ? 'enabled' : 'disabled'));
        }

        $invalidAuthority = array_filter($authority, static fn (string $uuid): bool => preg_match(self::UUID, $uuid) !== 1);
        if ($invalidAuthority !== []) {
            $add('FAIL', 'finance_risk_limit_authority_invalid_entries_' . count($invalidAuthority));
            $malformed = true;
        } elseif (count(array_unique($authority)) !== count($authority)) {
            $add('FAIL', 'finance_risk_limit_authority_duplicate_entries');
            $malformed = true;
        } else {
            $add($authority === [] ? 'OK' : 'WARN', 'finance_risk_limit_authority_count_' . count($authority));
        }

        if ($ceo !== '' && preg_match(self::UUID, $ceo) !== 1) {
            $add('FAIL', 'finance_ceo_employee_invalid');
            $malformed = true;
        } else {
            $add($ceo === '' ? 'OK' : 'WARN', 'finance_ceo_employee_' . ($ceo === '' ? 'unset' : 'set'));
        }

        $gateSet = array_key_exists(self::B2B_GATE, $values);
        $gate = trim($values[self::B2B_GATE] ?? '');
        $gateValid = !$gateSet || in_array($gate, ['off', 'shadow', 'enforce'], true);
        if (!$gateValid) {
            // The API refuses B2B finalization with 503 while this value stands; write `off`, `shadow` or `enforce`, or remove it.
            $add('FAIL', 'b2b_finance_gate_unrecognized');
            $malformed = true;
        } else {
            $add(in_array($gate, ['shadow', 'enforce'], true) ? 'WARN' : 'OK', 'b2b_finance_gate_' . (in_array($gate, ['shadow', 'enforce'], true) ? $gate : 'off'));
        }

        // The release check at production submission. Absent inherits the finalization gate; a set value must be exact.
        $releaseSet = array_key_exists(self::B2B_RELEASE_GATE, $values);
        $release = trim($values[self::B2B_RELEASE_GATE] ?? '');
        $effectiveRelease = $releaseSet ? $release : ($gateValid && $gateSet ? $gate : 'off');
        if ($releaseSet && !in_array($release, ['off', 'shadow', 'enforce'], true)) {
            // The API refuses B2B production submission with 503 while this value stands.
            $add('FAIL', 'b2b_finance_release_gate_unrecognized');
            $malformed = true;
        } else {
            $add(in_array($effectiveRelease, ['shadow', 'enforce'], true) ? 'WARN' : 'OK', 'b2b_finance_release_gate_' . (in_array($effectiveRelease, ['shadow', 'enforce'], true) ? $effectiveRelease : 'off') . ($releaseSet ? '' : '_inherited'));
        }

        $defaults = $posting !== 'enabled' && $authority === [] && $ceo === '' && !in_array($gate, ['shadow', 'enforce'], true) && !in_array($effectiveRelease, ['shadow', 'enforce'], true);
        $sorted = $authority;
        sort($sorted);
        $fingerprint = hash('sha256', implode("\n", ['arasya-finance-config-v3', $postingSet ? $posting : '', implode(',', $sorted), $ceo, $gateSet ? $gate : '', $releaseSet ? $release : '']));
        $ack = strtolower(trim($values[self::ACK] ?? ''));

        if (!$malformed && !$defaults) {
            if ($ack === '') {
                $add('FAIL', 'finance_config_not_acknowledged');
            } elseif (!hash_equals($fingerprint, $ack)) {
                $add('FAIL', 'finance_config_acknowledgement_stale');
            } else {
                $add('OK', 'finance_config_acknowledged');
            }
        } elseif (!$malformed) {
            $add('OK', 'finance_config_defaults');
        }

        foreach (self::origins($values) as $line) {
            $add($line['state'], $line['check']);
        }

        $ok = !in_array('FAIL', array_column($checks, 'state'), true);
        return ['ok' => $ok, 'defaults' => $defaults, 'fingerprint' => $fingerprint, 'checks' => $checks];
    }

    /**
     * The exact browser origin list (ARASYA_ALLOWED_ORIGINS) as the Finance frontend needs it. The API compares the
     * Origin header byte for byte, so an entry with a path, a trailing slash, a port, credentials, upper case or a
     * wildcard never matches any browser: the application behind it silently fails every write. Config only checks
     * the scheme at boot; this check runs before activation, so a wrong entry blocks the deployment instead of the
     * application. It is not part of the fingerprint: listing the Finance origin is not a Finance activation (it
     * opens no data; access still needs the Finance application grant and finance.access).
     * Reports `finance_origin_listed` or `finance_origin_absent`; never prints an origin.
     * @param array<string, string> $values
     * @return list<array{state: string, check: string}>
     */
    private static function origins(array $values): array
    {
        if (!array_key_exists('ARASYA_ALLOWED_ORIGINS', $values)) {
            return [];
        }
        $production = strtolower(trim($values['ARASYA_APP_ENV'] ?? 'production')) === 'production';
        $entries = array_values(array_filter(array_map('trim', explode(',', $values['ARASYA_ALLOWED_ORIGINS'])), static fn (string $item): bool => $item !== ''));
        $pattern = $production ? '/^https:\/\/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/D'
            : '/^https?:\/\/(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\[::1\])(?::[0-9]{1,5})?$/D';
        $lines = [];
        $malformed = array_filter($entries, static fn (string $origin): bool => preg_match($pattern, $origin) !== 1);
        if ($malformed !== []) {
            $lines[] = ['state' => 'FAIL', 'check' => 'allowed_origins_malformed_entries_' . count($malformed)];
        }
        // A near miss of the Finance origin (another scheme, a www. prefix, another domain) is refused by name.
        $finance = array_filter($entries, static fn (string $origin): bool => preg_match('/^[a-z]+:\/\/(?:[^\/]*\.)?finance\./i', $origin) === 1);
        if (array_diff($finance, [self::FINANCE_ORIGIN]) !== []) {
            $lines[] = ['state' => 'FAIL', 'check' => 'finance_origin_unexpected'];
        } else {
            $lines[] = ['state' => 'OK', 'check' => in_array(self::FINANCE_ORIGIN, $entries, true) ? 'finance_origin_listed' : 'finance_origin_absent'];
        }
        return $lines;
    }
}
