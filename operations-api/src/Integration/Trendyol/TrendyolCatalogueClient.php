<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Config\TrendyolCredentials;
use JsonException;
use RuntimeException;

/**
 * Read-only reader of the seller's approved catalogue (`GET .../products/approved`, Trendyol "Product Filter -
 * Approved Product v2"). Documented by Trendyol: the `storeFrontCode` header is required and `Accept-Language`
 * selects the listing language (`ro` for the RO storefront, `en` for English). The order API documents no language
 * header, which is why order lines can carry a title in another language than the seller's Romanian listing.
 *
 * It only sends GET requests. It is used by the title evidence check (bin/trendyol-title-evidence.php) to prove,
 * on real products, whether the Romanian seller title is returned before any import relies on it.
 */
final readonly class TrendyolCatalogueClient
{
    /** Trendyol's documented maximum for the `barcodes` filter. */
    public const MAX_BARCODES = 50;
    public const LANGUAGES = ['ro', 'en'];

    public function __construct(private TrendyolCredentials $credentials, private TrendyolTransport $transport)
    {
    }

    /**
     * Approved catalogue contents holding the given barcodes, in one listing language.
     *
     * @param list<string> $barcodes
     * @return list<array<string, mixed>>
     */
    public function approvedByBarcodes(array $barcodes, string $storeFrontCode, string $language): array
    {
        if ($barcodes === [] || count($barcodes) > self::MAX_BARCODES || count(array_unique($barcodes)) !== count($barcodes)) {
            throw new RuntimeException('TRENDYOL_CATALOGUE_REQUEST_INVALID');
        }
        foreach ($barcodes as $barcode) {
            if (!is_string($barcode) || preg_match('/^[\x21-\x7E]{1,64}$/D', $barcode) !== 1 || str_contains($barcode, ',')) {
                throw new RuntimeException('TRENDYOL_CATALOGUE_REQUEST_INVALID');
            }
        }
        if (preg_match('/^[A-Z]{2,5}$/D', $storeFrontCode) !== 1 || !in_array($language, self::LANGUAGES, true)) {
            throw new RuntimeException('TRENDYOL_CATALOGUE_REQUEST_INVALID');
        }
        $url = sprintf('%s/integration/product/sellers/%s/products/approved?%s', $this->credentials->baseUrl, rawurlencode($this->credentials->sellerId),
            http_build_query(['barcodes' => implode(',', $barcodes), 'page' => 0, 'size' => 100]));
        $response = $this->transport->get($url, [
            'Authorization' => 'Basic ' . base64_encode($this->credentials->apiKey . ':' . $this->credentials->apiSecret),
            'User-Agent' => $this->credentials->sellerId . ' - SelfIntegration',
            'Accept' => 'application/json',
            'storeFrontCode' => $storeFrontCode,
            'Accept-Language' => $language,
        ]);
        match (true) {
            $response['status'] === 200 => null,
            $response['status'] === 401 || $response['status'] === 403 => throw new RuntimeException('TRENDYOL_AUTH_FAILED'),
            $response['status'] === 429 => throw new RuntimeException('TRENDYOL_RATE_LIMITED'),
            $response['status'] >= 400 && $response['status'] < 500 => throw new RuntimeException('TRENDYOL_CATALOGUE_REFUSED'),
            default => throw new RuntimeException('TRENDYOL_UNAVAILABLE'),
        };
        try {
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        if (!is_array($decoded) || !is_array($decoded['content'] ?? null) || !array_is_list($decoded['content'])) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        foreach ($decoded['content'] as $content) {
            if (!is_array($content) || !is_array($content['variants'] ?? null)) {
                throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
            }
        }
        return $decoded['content'];
    }
}
