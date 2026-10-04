<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Config\TrendyolCredentials;
use JsonException;
use RuntimeException;

/** Read-only Trendyol Seller API client for shipment packages. Credentials never leave the server. */
final readonly class TrendyolClient
{
    public const PAGE_SIZE = 200;

    public function __construct(private TrendyolCredentials $credentials, private TrendyolTransport $transport)
    {
    }

    /** @return array{content: list<array<string, mixed>>, totalPages: int} */
    public function packages(int $startMillis, int $endMillis, int $page): array
    {
        $query = http_build_query([
            'startDate' => $startMillis,
            'endDate' => $endMillis,
            'page' => $page,
            'size' => self::PAGE_SIZE,
            'orderByField' => 'PackageLastModifiedDate',
            'orderByDirection' => 'ASC',
        ]);
        $url = sprintf('%s/integration/order/sellers/%s/orders?%s', $this->credentials->baseUrl, rawurlencode($this->credentials->sellerId), $query);
        $response = $this->transport->get($url, [
            'Authorization' => 'Basic ' . base64_encode($this->credentials->apiKey . ':' . $this->credentials->apiSecret),
            'User-Agent' => $this->credentials->sellerId . ' - SelfIntegration',
            'Accept' => 'application/json',
        ]);
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new RuntimeException('TRENDYOL_AUTH_FAILED');
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('TRENDYOL_UNAVAILABLE');
        }
        try {
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        if (!is_array($decoded) || !is_array($decoded['content'] ?? null) || !array_is_list($decoded['content']) || !is_int($decoded['totalPages'] ?? null)) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        return ['content' => $decoded['content'], 'totalPages' => $decoded['totalPages']];
    }
}
