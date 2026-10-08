<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * Per-source production QR authority cutover mode (ARASYA_SOURCE_QR_AUTHORITY_<SOURCE>), independent of
 * the ingestion mode and of the production authority mode. The default and the fallback for any unknown
 * value is LEGACY. ENFORCE is only accepted while the source's production authority is ENFORCE; any
 * other combination is reported and runs as OBSERVE.
 *
 * Arasya always issues the canonical intake QR of every order (as before 2.19.0). The mode decides
 * whether that QR is the production identity the source must use:
 * - LEGACY: the source keeps its own production QR behaviour; Arasya hands no QR to the source and
 *   offers no QR rotation for the source's orders.
 * - OBSERVE: Arasya hands the active QR of its own (operations) orders to the source, which still
 *   prints its own code and only records what it would replace. Managers may rotate a QR.
 * - ENFORCE: for operations orders the Arasya QR is the only production QR; the source must not print
 *   a competing production code.
 */
enum QrAuthorityMode: string
{
    case Legacy = 'legacy';
    case Observe = 'observe';
    case Enforce = 'enforce';

    /** Whether the source receives the Arasya QR of its operations orders and managers may rotate it. */
    public function isActive(): bool
    {
        return $this !== self::Legacy;
    }
}
