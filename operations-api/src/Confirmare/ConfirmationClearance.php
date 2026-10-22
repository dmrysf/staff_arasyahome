<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use PDO;

/**
 * Production clearance of a Confirmare order (API 2.38.0). Owner rule: `approved` alone never opens production. The
 * first document, the first QR use and every production start stay closed until ALL of these hold:
 *
 * - approval:  the Confirmare decision is `approved`;
 * - call:      the operator recorded the confirmed telephone call with the customer;
 * - lines:     every line of the cart was confirmed one by one (the confirmed line ids equal the current lines);
 * - price:     the source sent its pricing fingerprint (order.pricing) and the approval was given on it;
 * - sync:      the order Arasya holds now is the one that was reviewed: the review fingerprint (every line's identity,
 *              product, measurements and quantity, plus the pricing fingerprint) is unchanged since the approval;
 * - payment:   the financial condition is met, whatever the advance payment gate mode: an online payment the source
 *              verified, or a COD order whose advance is confirmed or not required. Unknown or waiting stays closed.
 *
 * The result is stored as order_confirmations.production_cleared and recomputed, under the order row lock, by every
 * decision and by every source snapshot that changes the order (cart, price, payment). ConfirmationGate reads only that
 * flag, so a later change in the shop closes production again until a new review.
 */
final class ConfirmationClearance
{
    public const CONDITIONS = ['approval', 'call', 'lines', 'price', 'sync', 'payment'];

    private function __construct()
    {
    }

    /** The review fingerprint of an order as Arasya holds it now (hex sha256). */
    public static function fingerprint(PDO $pdo, string $orderUuid): string
    {
        $items = $pdo->prepare('SELECT source_item_id, line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $items->execute([$orderUuid]);
        $pricing = $pdo->prepare('SELECT source_pricing_fingerprint FROM operational_orders WHERE order_uuid = ?');
        $pricing->execute([$orderUuid]);
        $data = ['lines' => array_map(static fn (array $row): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row), $items->fetchAll(PDO::FETCH_ASSOC)), 'pricing' => $pricing->fetchColumn() ?: null];
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return list<string> the current line ids of the order, by line number */
    public static function lineIds(PDO $pdo, string $orderUuid): array
    {
        $statement = $pdo->prepare('SELECT source_item_id FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $statement->execute([$orderUuid]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The conditions not met now (empty: cleared). Reads the rows the caller locked.
     *
     * @return list<string>
     */
    public static function missing(PDO $pdo, string $orderUuid): array
    {
        $statement = $pdo->prepare('SELECT c.status, c.call_confirmed_at, c.confirmed_line_ids, c.approved_review_fingerprint, o.source_pricing_fingerprint, o.payment_method, o.advance_status
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return self::CONDITIONS;
        }
        $missing = [];
        if ($row['status'] !== 'approved') {
            $missing[] = 'approval';
        }
        if ($row['call_confirmed_at'] === null) {
            $missing[] = 'call';
        }
        $confirmed = $row['confirmed_line_ids'] === null ? null : json_decode((string) $row['confirmed_line_ids'], true);
        $current = self::lineIds($pdo, $orderUuid);
        if (!is_array($confirmed) || $current === [] || self::sorted($confirmed) !== self::sorted($current)) {
            $missing[] = 'lines';
        }
        if ($row['source_pricing_fingerprint'] === null) {
            $missing[] = 'price';
        }
        if ($row['approved_review_fingerprint'] === null || !hash_equals((string) $row['approved_review_fingerprint'], self::fingerprint($pdo, $orderUuid))) {
            $missing[] = 'sync';
        }
        if (!self::paymentOpen($row['payment_method'] === null ? null : (string) $row['payment_method'], $row['advance_status'] === null ? null : (string) $row['advance_status'])) {
            $missing[] = 'payment';
        }
        return $missing;
    }

    /** Recomputes and stores production_cleared for an order whose row the caller locked. No Confirmare row: nothing. */
    public static function refresh(PDO $pdo, string $orderUuid, string $nowSql): bool
    {
        $exists = $pdo->prepare('SELECT production_cleared FROM order_confirmations WHERE order_uuid = ?');
        $exists->execute([$orderUuid]);
        $current = $exists->fetchColumn();
        if ($current === false) {
            return false;
        }
        $cleared = self::missing($pdo, $orderUuid) === [];
        if ($cleared !== ((int) $current === 1)) {
            $pdo->prepare('UPDATE order_confirmations SET production_cleared = ?, cleared_at = ? WHERE order_uuid = ?')
                ->execute([$cleared ? 1 : 0, $cleared ? $nowSql : null, $orderUuid]);
        }
        return $cleared;
    }

    /** The financial condition of a Confirmare order, independent of the payment gate mode. */
    public static function paymentOpen(?string $method, ?string $advanceStatus): bool
    {
        return $method === 'online' || ($method === 'cod' && in_array($advanceStatus, ['not_required', 'confirmed'], true));
    }

    /** @param list<mixed> $values @return list<string> */
    private static function sorted(array $values): array
    {
        $values = array_map('strval', $values);
        sort($values, SORT_STRING);
        return $values;
    }
}
