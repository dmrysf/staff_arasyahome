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
}
