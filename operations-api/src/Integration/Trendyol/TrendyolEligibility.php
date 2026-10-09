<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

/**
 * Which Trendyol packages may become intake work, and which may be released into production. Pure rules, no I/O.
 *
 * Timestamps: the real Order V2 API returns `orderDate` and `lastModifiedDate` as real UTC epoch milliseconds,
 * and filters `startDate`/`endDate` on the package modification time with the same UTC epochs (verified on the
 * live account on 2026-10-09 against the Seller Panel). Every comparison here is therefore a plain epoch
 * comparison: a package ordered before the activation baseline is historical and is ignored for good.
 *
 * Marketplace statuses are grouped in classes. A package ordered after the baseline is stored in the intake
 * inbox when its class is new, payment pending or review (unknown statuses included), so an unfamiliar status
 * can never lose a new order. Only the new class can be released into production, always through the explicit
 * Staff approval. Packages first seen already shipped, delivered, returned, cancelled or split are ignored once.
 */
final class TrendyolEligibility
{
    // Decisions for a package seen for the first time.
    public const ELIGIBLE = 'eligible';
    public const PAYMENT_PENDING = 'payment_pending';
    public const REVIEW = 'review';
    public const IGNORED_HISTORICAL = 'historical';
    public const IGNORED_STATUS = 'status_not_eligible';
    public const IGNORED_ORDER_DATE_MISSING = 'order_date_missing';

    // Marketplace status classes.
    public const CLASS_NEW = 'new';
    public const CLASS_PAYMENT_PENDING = 'payment_pending';
    public const CLASS_REVIEW = 'review';
    public const CLASS_FULFILMENT = 'fulfilment';
    public const CLASS_RETURNED = 'returned';
    public const CLASS_CANCELLED = 'cancelled';
    public const CLASS_SPLIT = 'split';

    /** Every status the code knows. Any other status is classified as review (never ignored, never releasable). */
    public const STATUS_CLASSES = [
        'Created' => self::CLASS_NEW,
        'Picking' => self::CLASS_NEW,
        'Invoiced' => self::CLASS_NEW,
        'Awaiting' => self::CLASS_PAYMENT_PENDING,
        'Verified' => self::CLASS_PAYMENT_PENDING,
        // Seen on the live account as a transient package state before Shipped: prepared for the courier.
        // Not proven to still need manufacturing, so it is visible for review but never releasable.
        'ReadyToShip' => self::CLASS_REVIEW,
        'Shipped' => self::CLASS_FULFILMENT,
        'Delivered' => self::CLASS_FULFILMENT,
        'AtCollectionPoint' => self::CLASS_FULFILMENT,
        'UnDelivered' => self::CLASS_FULFILMENT,
        'Returned' => self::CLASS_RETURNED,
        // Live: shipmentPackageStatus=UnDeliveredAndReturned while the top-level status is Returned.
        'UnDeliveredAndReturned' => self::CLASS_RETURNED,
        'Cancelled' => self::CLASS_CANCELLED,
        'UnSupplied' => self::CLASS_CANCELLED,
        'UnPacked' => self::CLASS_SPLIT,
    ];

    /** A manual-duplication warning for packages ordered within five minutes after activation (not a timezone rule). */
    public const NEAR_ACTIVATION_MILLIS = 5 * 60 * 1000;

    private function __construct()
    {
    }

    public static function statusClass(string $status): string
    {
        return self::STATUS_CLASSES[$status] ?? self::CLASS_REVIEW;
    }

    public static function knownStatus(string $status): bool
    {
        return isset(self::STATUS_CLASSES[$status]);
    }

    public static function classify(TrendyolPackage $package, int $baselineMillis): string
    {
        if ($package->orderDateMillis === null) {
            return self::IGNORED_ORDER_DATE_MISSING;
        }
        if ($package->orderDateMillis < $baselineMillis) {
            return self::IGNORED_HISTORICAL;
        }
        return match (self::statusClass($package->status)) {
            self::CLASS_NEW => self::ELIGIBLE,
            self::CLASS_PAYMENT_PENDING => self::PAYMENT_PENDING,
            self::CLASS_REVIEW => self::REVIEW,
            default => self::IGNORED_STATUS,
        };
    }

    /** Whether a first-seen decision stores the package in the intake inbox. */
    public static function stored(string $decision): bool
    {
        return in_array($decision, [self::ELIGIBLE, self::PAYMENT_PENDING, self::REVIEW], true);
    }

    /** Whether an approved package may be released into production in this marketplace status. */
    public static function releasable(string $status): bool
    {
        return self::statusClass($status) === self::CLASS_NEW;
    }

    /** A pending package in this status leaves the work list as cancelled or split at the marketplace. */
    public static function withdrawn(string $status): bool
    {
        return in_array(self::statusClass($status), [self::CLASS_CANCELLED, self::CLASS_SPLIT], true);
    }

    /** A released production order becomes unavailable (cancelled at the source) in this status. */
    public static function cancelled(string $status): bool
    {
        return self::statusClass($status) === self::CLASS_CANCELLED;
    }

    /** Ordered within five minutes after activation: the team may already have handled it manually. */
    public static function nearActivation(?int $orderDateMillis, int $baselineMillis): bool
    {
        return $orderDateMillis !== null && $orderDateMillis >= $baselineMillis && $orderDateMillis < $baselineMillis + self::NEAR_ACTIVATION_MILLIS;
    }
}
