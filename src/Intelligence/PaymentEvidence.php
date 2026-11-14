<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

/**
 * The payment class of an order's meters in Production Intelligence (API 2.72.0, owner decision 2026-11-14). Read only.
 *
 * The CEO reports keep apart: meters ordered, meters of a payment-verified sale, production, shipped and delivered. The
 * ledger counts ordered meters (every valid order that is not cancelled or excluded, cash on delivery included). Whether
 * those meters are also a verified sale is decided here, from the payment evidence the source already sent with the order
 * (`source_financial_state` / `source_financial_basis`, YD SOFT ConfirmareFinancial, and `payment_reversed_at`). Nothing
 * here changes an order, a payment, Confirmare or production rule.
 *
 * - `paid`: the shop proved the whole payment (`cleared` by a verified online payment). The only class counted as a
 *   verified sale.
 * - `advance_verified`: cash on delivery, the advance is proven (`cleared` by `advance_collected`), the rest is collected at
 *   delivery and Arasya holds no evidence of that collection. Never a verified sale; reported apart.
 * - `partial`: some money is proven, less than the advance.
 * - `no_charge`: the order total is zero (`cleared` by `zero_total`); no sale money exists.
 * - `unverified`: the source states no proven money yet (`waiting`).
 * - `reversed`: a recorded payment was reversed later.
 * - `unknown`: the source sent no payment state (orders before the payment snapshot, other sources) or an inconsistent
 *   one. Never treated as zero and never as paid.
 *
 * The class is the order's state at the time of the report, applied to the meters at their ledger date.
 */
final class PaymentEvidence
{
    public const PAID = 'paid';
    public const ADVANCE_VERIFIED = 'advance_verified';
    public const PARTIAL = 'partial';
    public const NO_CHARGE = 'no_charge';
    public const UNVERIFIED = 'unverified';
    public const REVERSED = 'reversed';
    public const UNKNOWN = 'unknown';
    public const CLASSES = [self::PAID, self::ADVANCE_VERIFIED, self::PARTIAL, self::NO_CHARGE, self::UNVERIFIED, self::REVERSED, self::UNKNOWN];

    /** The SQL columns of operational_orders `o` that classify() reads. */
    public const COLUMNS = 'o.source_financial_state AS pay_state, o.source_financial_basis AS pay_basis, o.payment_reversed_at AS pay_reversed_at';

    /** @param array{pay_state?: ?string, pay_basis?: ?string, pay_reversed_at?: ?string} $row */
    public static function classify(array $row): string
    {
        if (($row['pay_reversed_at'] ?? null) !== null) {
            return self::REVERSED;
        }
        $state = $row['pay_state'] ?? null;
        $basis = $row['pay_basis'] ?? null;
        return match (true) {
            $state === 'cleared' && $basis === 'online_payment' => self::PAID,
            $state === 'cleared' && $basis === 'advance_collected' => self::ADVANCE_VERIFIED,
            $state === 'cleared' && $basis === 'zero_total' => self::NO_CHARGE,
            $state === 'partial' => self::PARTIAL,
            $state === 'waiting' => self::UNVERIFIED,
            default => self::UNKNOWN,
        };
    }

    public static function verifiedSale(string $class): bool
    {
        return $class === self::PAID;
    }
}
