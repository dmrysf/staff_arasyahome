<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

/**
 * Downloads one official Trendyol label document (API 2.65.0). Only TrendyolLabelClient calls it, and only with a URL
 * that Trendyol returned and that passed the host allowlist. An implementation never sends credentials, never follows
 * a redirect and never reads more than $maxBytes + 1 bytes.
 */
interface TrendyolLabelDocumentFetcher
{
    /** @return array{status: int, body: string, contentType: string|null} */
    public function fetch(string $url, int $maxBytes): array;
}
