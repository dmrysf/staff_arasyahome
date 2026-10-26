<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation;

use Arasya\Operations\Http\ApiException;
use PDO;

/**
 * The one shared server-side cancellation guard (API 2.35.0, migration 033).
 *
 * Every command that would advance, complete, transfer, reassign, re-cut or otherwise authorize manufacturing work
 * calls assertOpen() inside its own transaction, after it locked the canonical order row. The guard re-reads the
 * order's stop projection with a locking read of that row, so it always sees the latest committed stop even when the
 * caller's transaction snapshot is older than the order lock. A stop committed first makes the command fail before
 * it writes anything; a command committed first is history, and the stop applies from the next command on.
 *
 * The guard does not replace any other gate (exceptions, documents, payment, piece gate). Owner rule 1: it also refuses
 * the Confirmare approval, the generation of a production ticket (R1 or a later revision, also from the shop's print
 * panel) and every print or reprint of the ticket. Read-only routes (the document, its PDF and history), the revision
 * request and decision, revoke, QR administration, physical custody records and the quality-case custody handovers stay
 * available; they never move production (see docs/order-cancellation-control.md, "Mutation inventory").
 */
final class ProductionStop
{
    /** blockedReason of order and track access decisions while production is stopped. */
    public const BLOCKED_REASON = 'production_stopped';
    public const ERROR_CODE = 'ORDER_PRODUCTION_STOPPED';

    private function __construct()
    {
    }

    /** Whether production of the order is frozen. Caller holds the order row lock in its transaction. */
    public static function isStopped(PDO $pdo, string $orderUuid): bool
    {
        return self::stopOf($pdo, $orderUuid) !== null;
    }

    /**
     * Refuses the command when production is stopped. Error contract: ORDER_UNAVAILABLE (unchanged since API 2.15) when
     * the source reports the order cancelled, ORDER_PRODUCTION_STOPPED for every other stop (a local stop, or a source
     * that reactivated the order before an authorized release). Both mean "no manufacturing until a release".
     */
    public static function assertOpen(PDO $pdo, string $orderUuid): void
    {
        $stop = self::stopOf($pdo, $orderUuid);
        if ($stop !== null) {
            throw $stop === 'unavailable' ? self::unavailable() : self::blocked();
        }
    }

    /** @return 'unavailable'|'stopped'|null */
    private static function stopOf(PDO $pdo, string $orderUuid): ?string
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('The production stop guard runs inside the command transaction.');
        }
        $statement = $pdo->prepare('SELECT production_stopped_at, operational_status FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['production_stopped_at'] === null) {
            return null;
        }
        return $row['operational_status'] === 'unavailable' ? 'unavailable' : 'stopped';
    }

    public static function unavailable(): ApiException
    {
        return new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.');
    }

    /** Stable error contract; it names no customer, source status or reason (those are read through authorized views). */
    public static function blocked(): ApiException
    {
        return new ApiException(409, self::ERROR_CODE, 'Producția acestei comenzi este oprită. Nu poți continua producția acestei comenzi.');
    }

    /**
     * A replayed (idempotent) response stored before the stop must not present manufacturing authorization that is no
     * longer valid: the committed result is returned unchanged, only the current action is replaced by the stop.
     * @param array<string, mixed> $order a serialized Staff order
     * @param array<string, mixed>|null $stop the current Staff stop view
     * @return array<string, mixed>
     */
    public static function overlayOrder(array $order, ?array $stop): array
    {
        if ($stop === null) {
            return $order;
        }
        unset($order['employeeAllowedAction']);
        $order['employeeActionBlockedReason'] = ($stop['sourceStatus'] ?? null) === 'cancelled' ? 'order_unavailable' : self::BLOCKED_REASON;
        $order['productionStop'] = $stop;
        if (isset($order['pieces']['tracks']) && is_array($order['pieces']['tracks'])) {
            foreach ($order['pieces']['tracks'] as &$track) {
                if (is_array($track)) {
                    $track['allowedAction'] = null;
                    $track['blockedReason'] = self::BLOCKED_REASON;
                }
            }
            unset($track);
        }
        return $order;
    }
}
