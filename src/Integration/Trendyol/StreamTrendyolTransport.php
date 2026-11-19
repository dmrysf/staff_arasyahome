<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use RuntimeException;

/**
 * Dependency-free HTTPS transport (PHP streams) with certificate verification and a bounded timeout. With a
 * TrendyolHostResolver (API 2.76.1) a host the system resolver cannot resolve is reached by the address the resolver
 * found, with the certificate still verified against the original host name.
 */
final readonly class StreamTrendyolTransport implements TrendyolTransport
{
    public function __construct(private int $timeoutSeconds = 20, private ?TrendyolHostResolver $resolver = null)
    {
    }

    public function get(string $url, array $headers): array
    {
        return $this->send('GET', $url, $headers, null);
    }

    public function put(string $url, array $headers, string $body): array
    {
        // Only the documented unsupplied notice may be written (TrendyolCancellationApi); anything else fails closed.
        if (preg_match('#/integration/order/sellers/[0-9]+/shipment-packages/[0-9]+/items/unsupplied$#D', (string) parse_url($url, PHP_URL_PATH)) !== 1) {
            throw new RuntimeException('Only the Trendyol unsupplied notice may be written.');
        }
        return $this->send('PUT', $url, $headers, $body);
    }

    /** @param array<string, string> $headers @return array{status: int, body: string} */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Trendyol requests require HTTPS.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $route = $this->resolver?->route($url);
        if ($route !== null) {
            $headerLines[] = 'Host: ' . $route['host'];
            $ssl['peer_name'] = $route['host'];
        }
        $context = stream_context_create([
            'http' => ['method' => $method, 'header' => implode("\r\n", $headerLines), 'timeout' => $this->timeoutSeconds, 'ignore_errors' => true]
                + ($body === null ? [] : ['content' => $body]),
            'ssl' => $ssl,
        ]);
        error_clear_last();
        $body = @file_get_contents($route['url'] ?? $url, false, $context, 0, 8 * 1024 * 1024);
        if ($body === false) {
            // Only the host and the kind of failure are logged: never the URL, a query, a header or a credential.
            error_log(sprintf('TRENDYOL_REQUEST_FAILED host=%s route=%s kind=%s', (string) parse_url($url, PHP_URL_HOST), $route === null ? 'system' : 'fallback',
                TrendyolHostResolver::failureKind(error_get_last())));
            throw new RuntimeException('TRENDYOL_REQUEST_FAILED');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return ['status' => $status, 'body' => $body];
    }
}
