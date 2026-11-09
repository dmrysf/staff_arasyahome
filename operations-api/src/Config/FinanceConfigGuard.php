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
 * - ARASYA_B2B_FINANCE_GATE absent or `off` (no finance decision at B2B finalization; API 2.64.0).
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
        if ($gateSet && !in_array($gate, ['off', 'shadow', 'enforce'], true)) {
            // Config would treat it as off; write `off`, `shadow` or `enforce`, or remove it.
            $add('FAIL', 'b2b_finance_gate_unrecognized');
            $malformed = true;
        } else {
            $add(in_array($gate, ['shadow', 'enforce'], true) ? 'WARN' : 'OK', 'b2b_finance_gate_' . (in_array($gate, ['shadow', 'enforce'], true) ? $gate : 'off'));
        }

        $defaults = $posting !== 'enabled' && $authority === [] && $ceo === '' && !in_array($gate, ['shadow', 'enforce'], true);
        $sorted = $authority;
        sort($sorted);
        $fingerprint = hash('sha256', implode("\n", ['arasya-finance-config-v2', $postingSet ? $posting : '', implode(',', $sorted), $ceo, $gateSet ? $gate : '']));
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

        $ok = !in_array('FAIL', array_column($checks, 'state'), true);
        return ['ok' => $ok, 'defaults' => $defaults, 'fingerprint' => $fingerprint, 'checks' => $checks];
    }
}
