<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

/**
 * Delivery states of one outbound cancellation (order_cancellation_sync_operations.state, migration 034) and the only
 * allowed transitions. The conceptual names of the Phase 2 brief map as follows:
 *
 *   PENDING                          queued, awaiting_pickup (a source connector must pull it)
 *   PROCESSING                       processing (leased by exactly one worker)
 *   AWAITING_PROVIDER_CONFIRMATION   awaiting_confirmation (accepted or reported, not yet seen cancelled)
 *   CONFIRMED                        confirmed (the provider itself shows the order cancelled)
 *   REJECTED_BY_PROVIDER             rejected
 *   TEMPORARY_FAILURE                retry_scheduled; uncertain (the answer was lost: observed by status reads only, never
 *                                    sent again by the worker)
 *   PERMANENT_FAILURE                failed
 *   MANUAL_REVIEW_REQUIRED           manual_review (also: the provider has no supported call)
 *   (not in the brief)               withdrawn (cancelled before it reached the provider), closed (a person closed it)
 *   NOT_REQUIRED                     no operation exists
 */
final class SyncState
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const AWAITING_PICKUP = 'awaiting_pickup';
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const RETRY_SCHEDULED = 'retry_scheduled';
    public const UNCERTAIN = 'uncertain';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';
    public const MANUAL_REVIEW = 'manual_review';
    public const WITHDRAWN = 'withdrawn';
    public const CLOSED = 'closed';

    /** States without an open slot (open_order_uuid NULL). */
    public const TERMINAL = [self::CONFIRMED, self::REJECTED, self::FAILED, self::WITHDRAWN, self::CLOSED];
    /** Open states in which the provider may still act or the outcome is still unknown: a release waits for them. */
    public const OPEN = [self::QUEUED, self::PROCESSING, self::AWAITING_PICKUP, self::AWAITING_CONFIRMATION, self::RETRY_SCHEDULED, self::UNCERTAIN, self::MANUAL_REVIEW];

    private const TRANSITIONS = [
        self::QUEUED => [self::PROCESSING, self::AWAITING_PICKUP, self::MANUAL_REVIEW, self::WITHDRAWN, self::CONFIRMED],
        self::PROCESSING => [self::AWAITING_CONFIRMATION, self::CONFIRMED, self::REJECTED, self::FAILED, self::RETRY_SCHEDULED, self::UNCERTAIN, self::MANUAL_REVIEW],
        self::AWAITING_PICKUP => [self::AWAITING_CONFIRMATION, self::REJECTED, self::FAILED, self::MANUAL_REVIEW, self::WITHDRAWN, self::CONFIRMED],
        self::AWAITING_CONFIRMATION => [self::PROCESSING, self::CONFIRMED, self::MANUAL_REVIEW],
        self::RETRY_SCHEDULED => [self::PROCESSING, self::WITHDRAWN, self::CONFIRMED, self::MANUAL_REVIEW, self::AWAITING_PICKUP],
        self::UNCERTAIN => [self::PROCESSING, self::CONFIRMED, self::MANUAL_REVIEW],
        // manual_review -> uncertain is a person's read-only recheck of a possibly delivered notice (one more status read).
        self::MANUAL_REVIEW => [self::RETRY_SCHEDULED, self::CLOSED, self::CONFIRMED, self::UNCERTAIN],
        self::REJECTED => [self::RETRY_SCHEDULED, self::CONFIRMED],
        self::FAILED => [self::RETRY_SCHEDULED, self::CONFIRMED],
        self::CONFIRMED => [],
        self::WITHDRAWN => [],
        self::CLOSED => [],
    ];

    private function __construct()
    {
    }

    public static function allowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /** The short state a worker in Staff sees: waiting for the channel, needs a person, or confirmed. */
    public static function staffView(?string $state): ?string
    {
        return match ($state) {
            null, self::WITHDRAWN, self::CLOSED => null,
            self::CONFIRMED => 'confirmed',
            self::MANUAL_REVIEW, self::FAILED, self::REJECTED => 'attention',
            default => 'awaiting_confirmation',
        };
    }
}
