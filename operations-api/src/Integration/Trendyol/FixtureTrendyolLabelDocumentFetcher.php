<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use RuntimeException;

/**
 * Test-only label document host (Config refuses ARASYA_TRENDYOL_FIXTURE_FILE in production). It reads the same fixture
 * file as FixtureTrendyolTransport: "documents" maps a label URL to {"status": 200, "contentType": "application/pdf",
 * "base64": "..."} (or "text" for a raw body, or {"fail": true}); an unknown URL answers 404. The host allowlist is
 * enforced exactly like production. Every call is appended to "documentCalls" so a test can count downloads.
 */
final readonly class FixtureTrendyolLabelDocumentFetcher implements TrendyolLabelDocumentFetcher
{
    public function __construct(private string $file)
    {
    }

    public function fetch(string $url, int $maxBytes): array
    {
        if (!TrendyolLabelClient::isAllowedLabelUrl($url)) {
            throw new RuntimeException('TRENDYOL_LABEL_URL_REFUSED');
        }
        $fixture = json_decode((string) @file_get_contents($this->file), true);
        if (!is_array($fixture)) {
            throw new RuntimeException('TRENDYOL_LABEL_DOWNLOAD_FAILED');
        }
        $fixture['documentCalls'][] = $url;
        file_put_contents($this->file, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $document = $fixture['documents'][$url] ?? null;
        if (!is_array($document)) {
            return ['status' => 404, 'body' => '', 'contentType' => 'text/plain'];
        }
        if (($document['fail'] ?? false) === true) {
            throw new RuntimeException('TRENDYOL_LABEL_DOWNLOAD_FAILED');
        }
        $body = isset($document['base64']) ? (string) base64_decode((string) $document['base64'], true) : (string) ($document['text'] ?? '');
        return ['status' => (int) ($document['status'] ?? 200), 'body' => substr($body, 0, $maxBytes + 1), 'contentType' => isset($document['contentType']) ? (string) $document['contentType'] : null];
    }
}
