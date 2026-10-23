<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

interface TrendyolTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers): array;

    /**
     * The single write verb (API 2.36.0): used only by TrendyolCancellationApi for the documented unsupplied notice.
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function put(string $url, array $headers, string $body): array;
}
