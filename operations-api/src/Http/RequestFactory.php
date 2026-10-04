<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use Arasya\Operations\Config\Config;
use Arasya\Operations\Support\Uuid;

final readonly class RequestFactory
{
    public function __construct(private Config $config)
    {
    }

    public function fromGlobals(): Request
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $requestId = $headers['x-request-id'] ?? '';
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $requestId) !== 1) {
            $requestId = Uuid::v4();
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return new Request(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: rtrim($path, '/') ?: '/',
            headers: $headers,
            cookies: array_filter($_COOKIE, 'is_string'),
            // Reading one byte beyond the largest accepted body cap (signed source
            // ingestion) prevents large chunked requests from being buffered in
            // application memory. Each route enforces its own smaller limit.
            body: (string) file_get_contents('php://input', false, null, 0, 262_145),
            ipAddress: $this->resolveIp($headers),
            userAgent: substr($headers['user-agent'] ?? 'unknown', 0, 512),
            requestId: $requestId,
            query: $_GET,
        );
    }

    /** @param array<string, string> $headers */
    private function resolveIp(array $headers): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->config->trustProxy || !in_array($remote, $this->config->trustedProxies, true)) {
            return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
        }
        $forwarded = trim(explode(',', $headers['x-forwarded-for'] ?? '')[0]);
        return filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : $remote;
    }
}
