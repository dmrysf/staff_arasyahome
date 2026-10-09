<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Config\TrendyolCredentials;
use JsonException;
use RuntimeException;

/**
 * Read-only Trendyol Seller API client for shipment packages (Order V2, `GET .../v2/orders`; the unversioned
 * endpoint is retired by Trendyol on 2026-10-15). It only ever sends GET requests; nothing is written to the
 * marketplace. Credentials never leave the server and never appear in an error.
 *
 * Trendyol limits: at most 200 packages per page, pages 0-49 (10,000 packages per filter), a window of at
 * most two weeks between startDate and endDate.
 */
final readonly class TrendyolClient
{
    public const PAGE_SIZE = 200;
    public const MAX_PAGES = 50;
    public const MAX_WINDOW_SECONDS = 14 * 86400;

    public function __construct(private TrendyolCredentials $credentials, private TrendyolTransport $transport)
    {
    }

    /** @return array{content: list<array<string, mixed>>, totalPages: int, totalElements: int|null} */
    public function packages(int $startMillis, int $endMillis, int $page): array
    {
        if ($endMillis < $startMillis || $endMillis - $startMillis > self::MAX_WINDOW_SECONDS * 1000 || $page < 0 || $page >= self::MAX_PAGES) {
            throw new RuntimeException('TRENDYOL_WINDOW_INVALID');
        }
        $query = http_build_query([
            'startDate' => $startMillis,
            'endDate' => $endMillis,
            'page' => $page,
            'size' => self::PAGE_SIZE,
            'orderByField' => 'PackageLastModifiedDate',
            'orderByDirection' => 'ASC',
        ]);
        $url = sprintf('%s/integration/order/sellers/%s/v2/orders?%s', $this->credentials->baseUrl, rawurlencode($this->credentials->sellerId), $query);
        $response = $this->transport->get($url, [
            'Authorization' => 'Basic ' . base64_encode($this->credentials->apiKey . ':' . $this->credentials->apiSecret),
            'User-Agent' => $this->credentials->sellerId . ' - SelfIntegration',
            'Accept' => 'application/json',
        ]);
        match (true) {
            $response['status'] === 200 => null,
            $response['status'] === 401 || $response['status'] === 403 => throw new RuntimeException('TRENDYOL_AUTH_FAILED'),
            $response['status'] === 426 => throw new RuntimeException('TRENDYOL_UPGRADE_REQUIRED'),
            $response['status'] === 429 => throw new RuntimeException('TRENDYOL_RATE_LIMITED'),
            default => throw new RuntimeException('TRENDYOL_UNAVAILABLE'),
        };
        try {
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        if (!is_array($decoded) || !is_array($decoded['content'] ?? null) || !array_is_list($decoded['content']) || !is_int($decoded['totalPages'] ?? null) || $decoded['totalPages'] < 0) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        return [
            'content' => $decoded['content'],
            'totalPages' => $decoded['totalPages'],
            'totalElements' => is_int($decoded['totalElements'] ?? null) ? $decoded['totalElements'] : null,
        ];
    }
}
