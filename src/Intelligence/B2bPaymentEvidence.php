<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use PDO;

/**
 * The payment class of B2B sold meters (API 2.76.0), read from the B2B current account. Read only: the account is never
 * written and no money is inferred.
 *
 * - `paid`: the order's receivable exists, is not reversed, and active (not released) allocations cover it in full;
 * - `partial`: some active allocation, less than the receivable;
 * - `unverified`: a receivable without any active allocation;
 * - `reversed`: the receivable was reversed;
 * - `unknown`: no receivable at all (no proof either way). Never treated as paid and never as zero.
 */
final class B2bPaymentEvidence
{
    /** @param list<string> $orderUuids @return array<string, string> b2b order uuid => PaymentEvidence class */
    public static function classes(PDO $pdo, array $orderUuids): array
    {
        $result = array_fill_keys(array_values(array_unique($orderUuids)), PaymentEvidence::UNKNOWN);
        foreach (array_chunk(array_keys($result), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $s = $pdo->prepare("SELECT m.receivable_order_uuid AS order_uuid, m.amount,
                    (SELECT COUNT(*) FROM b2b_account_movements r WHERE r.reversed_movement_uuid = m.movement_uuid) AS reversed,
                    (SELECT COALESCE(SUM(a.amount), 0) FROM b2b_account_allocations a LEFT JOIN b2b_account_allocation_releases x ON x.allocation_uuid = a.allocation_uuid
                        WHERE a.receivable_movement_uuid = m.movement_uuid AND x.allocation_uuid IS NULL) AS allocated
                FROM b2b_account_movements m WHERE m.movement_type = 'order_receivable' AND m.receivable_order_uuid IN ({$marks})");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $amount = self::cents((string) $row['amount']);
                $allocated = self::cents((string) $row['allocated']);
                $result[$row['order_uuid']] = match (true) {
                    (int) $row['reversed'] > 0 => PaymentEvidence::REVERSED,
                    $amount > 0 && $allocated >= $amount => PaymentEvidence::PAID,
                    $allocated > 0 => PaymentEvidence::PARTIAL,
                    default => PaymentEvidence::UNVERIFIED,
                };
            }
        }
        return $result;
    }

    private static function cents(string $value): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?/', $value, $m) !== 1) {
            return 0;
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }
}
