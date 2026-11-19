<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use RuntimeException;

/**
 * Dependency-free HTTPS download of a Trendyol label PDF (PHP streams). It fails closed:
 * - only HTTPS and only the hosts in TrendyolLabelClient::LABEL_HOSTS or LABEL_REDIRECT_HOSTS (re-checked here, so no
 *   caller can widen it);
 * - no redirect is followed here: a 3xx answer is returned with its Location, and only TrendyolLabelClient decides
 *   whether one validated second request is made;
 * - the request headers are fixed below: no auth header and no seller secret is ever sent to any document host;
 * - certificate and host name verification; a bounded timeout; at most $maxBytes + 1 bytes are read;
 * - with a TrendyolHostResolver (API 2.76.1), a host the system resolver cannot resolve is reached by the address the
 *   resolver found; the certificate is still verified against the original host, which is also the Host header.
 */
final readonly class StreamTrendyolLabelDocumentFetcher implements TrendyolLabelDocumentFetcher
{
    public function __construct(private int $timeoutSeconds = 15, private ?TrendyolHostResolver $resolver = null)
    {
    }

    public function fetch(string $url, int $maxBytes): array
    {
        if (!TrendyolLabelClient::isAllowedLabelUrl($url) && !TrendyolLabelClient::isAllowedRedirectUrl($url)) {
            throw new RuntimeException('TRENDYOL_LABEL_URL_REFUSED');
        }
        $http = [
            'method' => 'GET',
            'header' => "Accept: application/pdf\r\nUser-Agent: Arasya-AWB-Station",
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ];
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $route = $this->resolver?->route($url);
        if ($route !== null) {
            $http['header'] .= "\r\nHost: " . $route['host'];
            $ssl['peer_name'] = $route['host'];
        }
        $context = stream_context_create(['http' => $http, 'ssl' => $ssl]);
        error_clear_last();
        $body = @file_get_contents($route['url'] ?? $url, false, $context, 0, $maxBytes + 1);
        if ($body === false) {
            // The label URL is never logged (it expires and is signed): only the host and the kind of failure.
            error_log(sprintf('TRENDYOL_LABEL_DOWNLOAD_FAILED host=%s route=%s kind=%s', (string) parse_url($url, PHP_URL_HOST), $route === null ? 'system' : 'fallback',
                TrendyolHostResolver::failureKind(error_get_last())));
            throw new RuntimeException('TRENDYOL_LABEL_DOWNLOAD_FAILED');
        }
        return self::response($http_response_header ?? [], $body);
    }

    /**
     * The status, content type and Location of the last response in the raw header lines (PHP's $http_response_header).
     *
     * @param list<string> $lines
     * @return array{status: int, body: string, contentType: string|null, location: string|null}
     */
    public static function response(array $lines, string $body): array
    {
        $status = 0;
        $contentType = null;
        $location = null;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $contentType = null;
                $location = null;
            } elseif (preg_match('#^Content-Type:\s*([^;\s]+)#i', $line, $match) === 1) {
                $contentType = strtolower($match[1]);
            } elseif (preg_match('#^Location:\s*(\S+)\s*$#i', $line, $match) === 1) {
                $location = $match[1];
            }
        }
        return ['status' => $status, 'body' => $body, 'contentType' => $contentType, 'location' => $location];
    }
}
