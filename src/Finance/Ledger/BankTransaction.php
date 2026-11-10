<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Ledger;

use Arasya\Operations\Finance\FinanceInput as In;

/**
 * The identity of one real money movement: provider (bank, card processor or CASH), the internal account alias, the
 * currency and the provider's immutable transaction id, plus the direction (IN for a customer payment, OUT for a refund
 * payout). Two entries with the same identity are the same money; the database keeps at most one active request per
 * identity. A free-text description is never an identity: it only feeds the possible-duplicate review.
 *
 * The account key is an alias such as ING-RON-01, never an IBAN: an IBAN-shaped value is refused so that full bank
 * account numbers are not stored in Finance requests.
 */
final class BankTransaction
{
    /** @return array{provider: string, accountKey: string, transactionId: string}|null */
    public static function parse(array $input, string $field, bool $required): ?array
    {
        $value = $input[$field] ?? null;
        if ($value === null) {
            return $required ? In::fail($field, 'required') : null;
        }
        if (!is_array($value) || array_is_list($value)) {
            In::fail($field, 'invalid');
        }
        In::only($value, ['provider', 'accountKey', 'transactionId']);
        $provider = strtoupper((string) In::text($value, 'provider', 24, true));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,23}$/D', $provider) !== 1) {
            In::fail($field . '.provider', 'invalid');
        }
        $account = strtoupper((string) In::text($value, 'accountKey', 32, true));
        if (self::looksLikeIban($account)) {
            In::fail($field . '.accountKey', 'iban_not_allowed');
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,31}$/D', $account) !== 1) {
            In::fail($field . '.accountKey', 'invalid');
        }
        $transaction = (string) In::text($value, 'transactionId', 120, true);
        if (self::normalized($transaction) === '' || strlen(self::normalized($transaction)) < 4) {
            In::fail($field . '.transactionId', 'invalid');
        }
        return ['provider' => $provider, 'accountKey' => $account, 'transactionId' => $transaction];
    }

    /** Normalized identity used by the unique index. */
    public static function key(string $direction, array $bank, string $currency): string
    {
        return $direction . '|' . $bank['provider'] . '|' . $bank['accountKey'] . '|' . $currency . '|' . self::normalized($bank['transactionId']);
    }

    /** Upper case, letters and digits only: "op 2026/11-0001" and "OP2026110001" are the same transaction id. */
    public static function normalized(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $value));
    }

    public static function looksLikeIban(string $value): bool
    {
        $compact = strtoupper((string) preg_replace('/[\s-]+/', '', $value));
        return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/D', $compact) === 1;
    }
}
