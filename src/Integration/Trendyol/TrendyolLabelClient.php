<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Config\TrendyolCredentials;
use JsonException;
use RuntimeException;

/**
 * Narrow, read-only adapter for the official Trendyol shipping label (API 2.65.0, Trendyol AWB station).
 *
 * Trendyol documents the common label service for "Trendyol Agreement Cargo Carriers" (Trendyol pays the shipment), with
 * FANCOURIER listed for RO (developers.trendyol.com, v3.0 "Getting Common Label (getCommonLabel)", updated 2026-10-04):
 *
 *   GET {base}/integration/sellers/{sellerId}/common-label/query?id={cargoTrackingNumber}
 *   200 {"data": [{"format": "PDF", "label": "https://cdn.dsmcdn.com/....pdf"}]}
 *   400 the cargo tracking number is wrong; 401 / 404 the API information is wrong
 *
 * The service is a GET: it reads a label Trendyol already produced for the package's existing tracking number. Nothing is
 * created, no shipment, status, tracking number or package is changed, and no other courier is ever called. The
 * label document is then downloaded from the URL Trendyol returned, only from LABEL_HOSTS, without credentials, bounded
 * in size, and checked to be a PDF by its content (never by its file name).
 *
 * Observed on the real seller account (2026-11-09, FANEX/RO): the label URL is on apigw.trendyol.com and answers one
 * HTTP 302 to a presigned (X-Amz-*) URL on s3.trendyol.com, which serves a one-page 105 x 148 mm (A6) PDF 1.4. So the
 * download allows exactly one 302, only to an HTTPS URL on LABEL_REDIRECT_HOSTS, validated here before it is requested
 * (never followed automatically by the transport). A second redirect, any other 3xx, an unexpected host or scheme is
 * refused. No request to a label or redirect host ever carries credentials: the fetcher has no way to send them.
 *
 * Label and presigned URLs expire, so a URL is never stored, logged or returned to a browser: every print asks Trendyol
 * again. Credentials never leave the server and never appear in an error.
 */
final readonly class TrendyolLabelClient
{
    /** Hosts a label URL from Trendyol may point at: the documented CDN and the gateway seen on the real account. */
    public const LABEL_HOSTS = ['cdn.dsmcdn.com', 'apigw.trendyol.com'];
    /** The only host one 302 from a label host may lead to (presigned Trendyol S3). Never a label URL by itself. */
    public const LABEL_REDIRECT_HOSTS = ['s3.trendyol.com'];
    /** Redirects followed for one label document. */
    public const MAX_LABEL_REDIRECTS = 1;
    public const MAX_LABEL_BYTES = 5 * 1024 * 1024;
    /** More labels than this for one tracking number is treated as a malformed answer. */
    public const MAX_LABELS = 20;

    public function __construct(
        private TrendyolCredentials $credentials,
        private TrendyolTransport $transport,
        private TrendyolLabelDocumentFetcher $documents,
        private ?string $storeFrontCode = null,
    ) {
    }

    /**
     * The official labels of one cargo tracking number, in Trendyol's order (one per parcel).
     *
     * @return list<array{format: string, url: string}>
     */
    public function labels(string $cargoTrackingNumber): array
    {
        if (!self::isTrackingNumber($cargoTrackingNumber)) {
            throw new RuntimeException('TRENDYOL_LABEL_NOT_READY');
        }
        $url = sprintf('%s/integration/sellers/%s/common-label/query?%s', $this->credentials->baseUrl, rawurlencode($this->credentials->sellerId),
            http_build_query(['id' => $cargoTrackingNumber]));
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->credentials->apiKey . ':' . $this->credentials->apiSecret),
            'User-Agent' => $this->credentials->sellerId . ' - SelfIntegration',
            'Accept' => 'application/json',
        ];
        if ($this->storeFrontCode !== null && $this->storeFrontCode !== '') {
            $headers['storeFrontCode'] = $this->storeFrontCode;
        }
        $response = $this->transport->get($url, $headers);
        match (true) {
            $response['status'] === 200 => null,
            $response['status'] === 400, $response['status'] === 404 => throw new RuntimeException('TRENDYOL_LABEL_NOT_AVAILABLE'),
            $response['status'] === 401, $response['status'] === 403 => throw new RuntimeException('TRENDYOL_AUTH_FAILED'),
            $response['status'] === 429 => throw new RuntimeException('TRENDYOL_RATE_LIMITED'),
            default => throw new RuntimeException('TRENDYOL_UNAVAILABLE'),
        };
        try {
            $decoded = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        if (!is_array($data) || !array_is_list($data) || count($data) > self::MAX_LABELS) {
            throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
        }
        if ($data === []) {
            throw new RuntimeException('TRENDYOL_LABEL_NOT_AVAILABLE');
        }
        $labels = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['format'] ?? null) || !is_string($item['label'] ?? null)) {
                throw new RuntimeException('TRENDYOL_MALFORMED_RESPONSE');
            }
            if (strtoupper(trim($item['format'])) !== 'PDF') {
                continue;
            }
            if (!self::isAllowedLabelUrl($item['label'])) {
                throw new RuntimeException('TRENDYOL_LABEL_URL_REFUSED');
            }
            $labels[] = ['format' => 'PDF', 'url' => $item['label']];
        }
        if ($labels === []) {
            throw new RuntimeException('TRENDYOL_LABEL_FORMAT_UNSUPPORTED');
        }
        return $labels;
    }

    /** Downloads and validates one label document returned by labels(). Returns the PDF bytes. */
    public function document(string $url): string
    {
        if (!self::isAllowedLabelUrl($url)) {
            throw new RuntimeException('TRENDYOL_LABEL_URL_REFUSED');
        }
        $response = $this->documents->fetch($url, self::MAX_LABEL_BYTES);
        $redirects = 0;
        while ($response['status'] >= 300 && $response['status'] < 400) {
            // Exactly one 302, to a validated presigned URL; nothing else is ever requested.
            $location = $response['location'] ?? null;
            if ($response['status'] !== 302 || ++$redirects > self::MAX_LABEL_REDIRECTS || !is_string($location) || !self::isAllowedRedirectUrl($location)) {
                throw new RuntimeException('TRENDYOL_LABEL_REDIRECT_REFUSED');
            }
            $response = $this->documents->fetch($location, self::MAX_LABEL_BYTES);
        }
        $status = $response['status'];
        if (in_array($status, [403, 404, 410], true)) {
            throw new RuntimeException('TRENDYOL_LABEL_EXPIRED');
        }
        if ($status !== 200) {
            throw new RuntimeException('TRENDYOL_LABEL_DOWNLOAD_FAILED');
        }
        if (strlen($response['body']) > self::MAX_LABEL_BYTES) {
            throw new RuntimeException('TRENDYOL_LABEL_TOO_LARGE');
        }
        $type = $response['contentType'];
        if ($type !== null && !in_array($type, ['application/pdf', 'application/octet-stream', 'binary/octet-stream'], true)) {
            throw new RuntimeException('TRENDYOL_LABEL_INVALID');
        }
        if (!self::looksLikePdf($response['body'])) {
            throw new RuntimeException('TRENDYOL_LABEL_INVALID');
        }
        return $response['body'];
    }

    /** A complete PDF by content: the header, a plausible size and the end-of-file marker near the end. */
    public static function looksLikePdf(string $bytes): bool
    {
        if (strlen($bytes) < 64 || preg_match('/^%PDF-[12]\.[0-9]/', $bytes) !== 1) {
            return false;
        }
        return str_contains(substr($bytes, -2048), '%%EOF');
    }

    /** The exact rule for a label URL: HTTPS, a LABEL_HOSTS host, default port, no credentials, no path traversal. */
    public static function isAllowedLabelUrl(string $url): bool
    {
        return self::isSafeUrl($url, self::LABEL_HOSTS, 2048);
    }

    /** The exact rule for the one redirect target: the same, with a LABEL_REDIRECT_HOSTS host (a presigned URL is long). */
    public static function isAllowedRedirectUrl(string $url): bool
    {
        return self::isSafeUrl($url, self::LABEL_REDIRECT_HOSTS, 4096);
    }

    /** @param list<string> $hosts */
    private static function isSafeUrl(string $url, array $hosts, int $maxLength): bool
    {
        if ($url === '' || strlen($url) > $maxLength || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if (!in_array($host, $hosts, true) || !str_starts_with($path, '/') || str_contains($path, '/../') || str_contains($path, '/./') || str_ends_with($path, '/..')) {
            return false;
        }
        return !str_contains(strtolower(rawurldecode($path)), '..');
    }

    /** Trendyol cargo tracking numbers are digits (sometimes letters); nothing else reaches the query string. */
    public static function isTrackingNumber(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]{6,40}$/D', $value) === 1;
    }
}
