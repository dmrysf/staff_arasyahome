<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * Per-source customer tracking authority cutover mode (ARASYA_SOURCE_TRACKING_AUTHORITY_<SOURCE>), independent of
 * the ingestion mode and of the production, QR and document authority modes. The default and the fallback for any
 * unknown value is LEGACY. ENFORCE is only accepted while the source's production authority is ENFORCE (Arasya can
 * only be the customer-facing production truth of orders it owns); any other combination is reported and runs as
 * OBSERVE.
 *
 * - LEGACY: the source shows its own production progress to customers; the signed tracking answer is refused
 *   (TRACKING_CUTOVER_INACTIVE), so nothing about Arasya production leaves the API.
 * - OBSERVE: the source still shows its own progress and only compares it privately with the Arasya answer.
 * - ENFORCE: for operations orders the Arasya answer is the only customer-facing production progress. Orders the
 *   source still manages keep the source's own tracking.
 *
 * The tracking answer is read-only in every mode: it never claims, moves, completes or releases an order.
 */
enum TrackingAuthorityMode: string
{
    case Legacy = 'legacy';
    case Observe = 'observe';
    case Enforce = 'enforce';

    /** Whether the source may read the customer tracking answer of its operations orders. */
    public function isActive(): bool
    {
        return $this !== self::Legacy;
    }
}
