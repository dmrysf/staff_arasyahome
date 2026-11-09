<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use RuntimeException;

/**
 * Dependency-free HTTPS download of a Trendyol label PDF (PHP streams). It fails closed:
 * - only HTTPS and only the hosts in TrendyolLabelClient::LABEL_HOSTS (re-checked here, so no caller can widen it);
 * - no redirect is followed (a 3xx answer is an error, never a second request);
 * - no auth header and no seller secret is ever sent to the document host;
 * - certificate and host name verification; a bounded timeout; at most $maxBytes + 1 bytes are read.
 */
final readonly class StreamTrendyolLabelDocumentFetcher implements TrendyolLabelDocumentFetcher
{
    public function __construct(private int $timeoutSeconds = 15)
    {
    }

    public function fetch(string $url, int $maxBytes): array
    {
        if (!TrendyolLabelClient::isAllowedLabelUrl($url)) {
            throw new RuntimeException('TRENDYOL_LABEL_URL_REFUSED');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/pdf\r\nUser-Agent: Arasya-AWB-Station",
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if ($body === false) {
            throw new RuntimeException('TRENDYOL_LABEL_DOWNLOAD_FAILED');
        }
        $status = 0;
        $contentType = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (preg_match('#^Content-Type:\s*([^;\s]+)#i', $line, $match) === 1) {
                $contentType = strtolower($match[1]);
            }
        }
        return ['status' => $status, 'body' => $body, 'contentType' => $contentType];
    }
}
