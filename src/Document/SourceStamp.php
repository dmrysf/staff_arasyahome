<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

/**
 * Visible source/channel stamp on the workshop document. Presentation only: no business rule reads
 * these strings, and they are not part of the document fingerprint. An unknown future source degrades
 * to its registered display name (or key) in capitals.
 */
final class SourceStamp
{
    private const LABELS = [
        'trendhome' => 'TRENDHOME.RO',
        'outletperdele' => 'OUTLETPERDELE.RO',
        'trendyol' => 'TRENDYOL',
        'b2b' => 'ARASYA HOME B2B',
    ];

    private function __construct()
    {
    }

    public static function label(string $sourceKey, ?string $displayName = null): string
    {
        return self::LABELS[$sourceKey] ?? mb_strtoupper(trim($displayName ?? '') !== '' ? trim((string) $displayName) : $sourceKey);
    }
}
