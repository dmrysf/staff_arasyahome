<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * Per-source production authority cutover mode (ARASYA_SOURCE_AUTHORITY_<SOURCE>), independent of the
 * source's ingestion mode. The default and the fallback for any unknown value is LEGACY.
 *
 * - LEGACY: today's behaviour. New orders keep source authority, no explicit takeover is offered, Staff
 *   claims behave as before.
 * - OBSERVE: nothing is blocked. Managers may take single orders over explicitly; a Staff claim of a
 *   source-managed order is still allowed but recorded as an observation.
 * - ENFORCE: genuinely new orders of the source enter at the initial canonical stage with Operations
 *   authority; source-managed orders cannot be claimed or reassigned in Arasya until a manager takes
 *   them over with an explicitly selected stage.
 */
enum ProductionAuthorityMode: string
{
    case Legacy = 'legacy';
    case Observe = 'observe';
    case Enforce = 'enforce';

    public function allowsTakeover(): bool
    {
        return $this !== self::Legacy;
    }
}
