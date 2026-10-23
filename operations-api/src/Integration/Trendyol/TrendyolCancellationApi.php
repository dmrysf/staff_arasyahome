<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Config\TrendyolCredentials;

/**
 * The only Trendyol write call of the Operations API (API 2.36.0, Phase 2 cancellation synchronization): the seller's
 * "unsupplied" notice for every line of one shipment package, documented by Trendyol as Cancel Package / Tedarik
 * Edememe Bildirimi:
 *
 *   PUT {base}/integration/order/sellers/{sellerId}/shipment-packages/{packageId}/items/unsupplied
 *   header storeFrontCode; body {"lines": [{"lineId", "quantity"}], "reasonId"}
 *
 * Trendyol documents the reason codes 500-506 and that the cancelled package becomes "Cancelled" (a partial notice
 * splits the package; this client always names every line with its full quantity, so nothing is split). It does not
 * document the response codes, the allowed package statuses or idempotency, so the caller treats an accepted answer
 * as "awaiting confirmation" only, confirms by reading the package again, and never repeats a call whose outcome is unknown.
 *
 * It is reached only through TrendyolCancellationProvider, behind the ARASYA_CANCELLATION_SYNC server switch and the
 * disabled-by-default cancellation_sync_trendyol flag. Credentials never appear in an error or log.
 */
final readonly class TrendyolCancellationApi
{
    /** Trendyol's documented unsupplied reasons. A person chooses one; nothing chooses it automatically. */
    public const REASONS = [
        500 => 'Stoc epuizat',
        501 => 'Produs defect sau deteriorat',
        502 => 'Preț greșit',
        503 => 'Imagine, cod de bare sau cantitate greșită',
        504 => 'Eroare de integrare',
        505 => 'Cumpărare în cantitate mare',
        506 => 'Forță majoră',
    ];

    public function __construct(private TrendyolCredentials $credentials, private TrendyolTransport $transport, private string $storeFrontCode)
    {
    }

    /**
     * @param list<array{lineId: int, quantity: int}> $lines every line of the package with its full quantity
     * @return array{status: int} the HTTP status; a transport failure throws TRENDYOL_REQUEST_FAILED
     */
    public function reportUnsupplied(int $packageId, array $lines, int $reasonId): array
    {
        if ($packageId <= 0 || $lines === [] || !array_key_exists($reasonId, self::REASONS)) {
            throw new \InvalidArgumentException('TRENDYOL_CANCELLATION_INVALID');
        }
        $body = json_encode([
            'lines' => array_map(static fn (array $line): array => ['lineId' => $line['lineId'], 'quantity' => $line['quantity']], $lines),
            'reasonId' => $reasonId,
        ], JSON_THROW_ON_ERROR);
        $url = sprintf('%s/integration/order/sellers/%s/shipment-packages/%d/items/unsupplied', $this->credentials->baseUrl, rawurlencode($this->credentials->sellerId), $packageId);
        $response = $this->transport->put($url, [
            'Authorization' => 'Basic ' . base64_encode($this->credentials->apiKey . ':' . $this->credentials->apiSecret),
            'User-Agent' => $this->credentials->sellerId . ' - SelfIntegration',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'storeFrontCode' => $this->storeFrontCode,
        ], $body);
        return ['status' => $response['status']];
    }
}
