<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * Per-source production document (PDF ticket) authority cutover mode (ARASYA_SOURCE_DOCUMENT_AUTHORITY_<SOURCE>),
 * independent of the ingestion mode, the production authority mode and the QR authority mode. The default and
 * the fallback for any unknown value is LEGACY. ENFORCE is only accepted while the source's QR authority is
 * ENFORCE (a document prints the order's production QR); any other combination is reported and runs as OBSERVE.
 *
 * - LEGACY: the source keeps its own production ticket for every order and never obtains an Arasya document.
 *   Arasya documents stay the internal Staff tool of 2.16; the signed contract only answers state read-only.
 * - OBSERVE: Arasya documents of the source's operations orders may be generated and printed in Staff; the
 *   source still prints its own ticket and only records what Arasya holds. Orders the source still manages
 *   itself (production authority `source`) keep the source ticket: Staff cannot create a competing document.
 * - ENFORCE: for operations orders the Arasya document revision is the only production ticket. The source
 *   never renders its own: it obtains the active Arasya revision through the signed contract, which may
 *   create revision 1 (never a later revision) and records each print.
 *
 * Revisions after the first always need the central request and approval workflow, in every mode.
 */
enum DocumentAuthorityMode: string
{
    case Legacy = 'legacy';
    case Observe = 'observe';
    case Enforce = 'enforce';

    /** Whether Arasya documents may exist for the source's operations orders. */
    public function isActive(): bool
    {
        return $this !== self::Legacy;
    }
}
