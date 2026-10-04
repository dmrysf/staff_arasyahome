<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use RuntimeException;

/** Dependency-free HTTPS transport (PHP streams) with certificate verification and a bounded timeout. */
final readonly class StreamTrendyolTransport implements TrendyolTransport
{
    public function __construct(private int $timeoutSeconds = 20)
    {
    }

    public function get(string $url, array $headers): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Trendyol requests require HTTPS.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'header' => implode("\r\n", $headerLines), 'timeout' => $this->timeoutSeconds, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, 8 * 1024 * 1024);
        if ($body === false) {
            throw new RuntimeException('Trendyol request failed.');
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
