<?php

declare(strict_types=1);

namespace Arasya\Operations\Config;

use RuntimeException;

/**
 * Trendyol Seller API credentials. The adapter stays disabled until all three
 * required values are configured privately; partial configuration fails closed.
 */
final readonly class TrendyolCredentials
{
    public const DEFAULT_BASE_URL = 'https://apigw.trendyol.com';

    private function __construct(
        public string $sellerId,
        public string $apiKey,
        public string $apiSecret,
        public string $baseUrl,
    ) {
    }

    public static function fromValues(string $sellerId, string $apiKey, string $apiSecret, string $baseUrl): ?self
    {
        if ($sellerId === '' && $apiKey === '' && $apiSecret === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,20}$/D', $sellerId) !== 1 || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Trendyol configuration requires seller ID, API key and API secret together.');
        }
        $baseUrl = rtrim($baseUrl === '' ? self::DEFAULT_BASE_URL : $baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['query']) || isset($parts['user'])) {
            throw new RuntimeException('ARASYA_TRENDYOL_API_BASE_URL must be an HTTPS origin.');
        }
        return new self($sellerId, $apiKey, $apiSecret, $baseUrl);
    }
}
