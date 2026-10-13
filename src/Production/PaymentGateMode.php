<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * Per-source advance payment gate (ARASYA_SOURCE_PAYMENT_GATE_<SOURCE>), independent of every authority mode.
 * The default and the fallback for any unknown value is OFF, so a deployment without the setting behaves
 * exactly like before.
 *
 * - OFF: the source's payment facts are stored when sent, but nothing reads them.
 * - OBSERVE: Staff and Dashboard show the gate state of each order; no production action is refused.
 * - ENFORCE: an order of the source cannot get its first production document and cannot leave stage 1
 *   (claim, stage completion, cutting intake, reassignment) while its gate is closed. Orders already past
 *   stage 1 keep working; a later reversal is reported, never rewound.
 */
enum PaymentGateMode: string
{
    case Off = 'off';
    case Observe = 'observe';
    case Enforce = 'enforce';

    public function isVisible(): bool
    {
        return $this !== self::Off;
    }
}
