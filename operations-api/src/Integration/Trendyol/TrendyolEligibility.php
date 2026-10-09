<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

/**
 * Which Trendyol packages may become intake work. Pure rules, no I/O.
 *
 * A package seen for the first time becomes intake work only when it was ordered after the activation
 * baseline and its marketplace status is a new, unshipped order. Everything else is ignored once and for all
 * (historical protection): shipped, delivered, cancelled, returned or split packages never enter the factory
 * automatically. Payment-pending packages (Awaiting, Verified) are not decided yet: Trendyol asks sellers not
 * to act on them, and they come back with a new modification time when they become Created.
 *
 * Trendyol documents `orderDate` as GMT+3 wall time, so the epoch value may be the real instant or the real instant
 * plus three hours. Only a package ordered before the baseline under both readings is historical and ignored for good.
 * A package dated within three hours after the baseline is ambiguous: it may be a new order, or an order placed up
 * to three hours before activation. It is never ignored (a new order must not be lost); it becomes intake work with
 * the near-activation flag, so the approver checks the Seller Panel before releasing it. Nothing reaches production
 * without that explicit approval.
 */
final class TrendyolEligibility
{
    public const ELIGIBLE = 'eligible';
    public const DEFERRED = 'deferred';
    public const IGNORED_HISTORICAL = 'historical';
    public const IGNORED_STATUS = 'status_not_eligible';
    public const IGNORED_ORDER_DATE_MISSING = 'order_date_missing';

    /** New, unshipped orders: the seller may already be picking or invoicing them in the Seller Panel. */
    public const NEW_ORDER_STATUSES = ['Created', 'Picking', 'Invoiced'];
    /** Payment confirmation pending: no action until Trendyol moves the package to Created. */
    public const PAYMENT_PENDING_STATUSES = ['Awaiting', 'Verified'];
    /** A package that is pending intake work stops being workable in these statuses. */
    public const WITHDRAWN_STATUSES = ['Cancelled', 'UnSupplied', 'Returned', 'UnPacked'];
    /** A released production order becomes unavailable (cancelled at the source) in these statuses. */
    public const CANCELLED_STATUSES = ['Cancelled', 'UnSupplied'];

    /** Width of the GMT+3 ambiguity after the baseline. */
    public const ORDER_DATE_SKEW_MILLIS = 3 * 3600 * 1000;

    private function __construct()
    {
    }

    public static function classify(TrendyolPackage $package, int $baselineMillis): string
    {
        if ($package->orderDateMillis === null) {
            return self::IGNORED_ORDER_DATE_MISSING;
        }
        if ($package->orderDateMillis < $baselineMillis) {
            return self::IGNORED_HISTORICAL;
        }
        if (in_array($package->status, self::PAYMENT_PENDING_STATUSES, true)) {
            return self::DEFERRED;
        }
        return in_array($package->status, self::NEW_ORDER_STATUSES, true) ? self::ELIGIBLE : self::IGNORED_STATUS;
    }

    /** Whether the order date falls in the GMT+3 ambiguity after the baseline (it may predate activation). */
    public static function nearActivation(?int $orderDateMillis, int $baselineMillis): bool
    {
        return $orderDateMillis !== null && $orderDateMillis >= $baselineMillis && $orderDateMillis < $baselineMillis + self::ORDER_DATE_SKEW_MILLIS;
    }

    /** Whether an approved package may still be released into production in this marketplace status. */
    public static function releasable(string $status): bool
    {
        return in_array($status, self::NEW_ORDER_STATUSES, true);
    }
}
