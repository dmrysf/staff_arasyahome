<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use RuntimeException;

/**
 * Test-only Order V2 stand-in for disposable environments (Config refuses ARASYA_TRENDYOL_FIXTURE_FILE in
 * production). It answers GET requests from a JSON file read on every call, so a test can change the marketplace
 * between requests: {"packages": [...raw packages...]}, optionally {"status": 429}, {"body": "raw"} or {"fail": true}.
 */
final readonly class FixtureTrendyolTransport implements TrendyolTransport
{
    public function __construct(private string $file)
    {
    }

    public function get(string $url, array $headers): array
    {
        $fixture = json_decode((string) @file_get_contents($this->file), true);
        if (!is_array($fixture) || ($fixture['fail'] ?? false) === true) {
            throw new RuntimeException('TRENDYOL_REQUEST_FAILED');
        }
        if (isset($fixture['status']) || isset($fixture['body'])) {
            return ['status' => (int) ($fixture['status'] ?? 200), 'body' => (string) ($fixture['body'] ?? '')];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $packages = array_values(array_filter(is_array($fixture['packages'] ?? null) ? $fixture['packages'] : [], static function (mixed $package) use ($query): bool {
            if (!is_array($package)) {
                return false;
            }
            if (isset($query['shipmentPackageIds'])) {
                return in_array((string) ($package['shipmentPackageId'] ?? ''), explode(',', (string) $query['shipmentPackageIds']), true);
            }
            $modified = (int) ($package['lastModifiedDate'] ?? 0);
            return $modified >= (int) ($query['startDate'] ?? 0) && $modified <= (int) ($query['endDate'] ?? PHP_INT_MAX);
        }));
        $size = max(1, (int) ($query['size'] ?? 200));
        $page = (int) ($query['page'] ?? 0);
        return ['status' => 200, 'body' => json_encode([
            'content' => array_slice($packages, $page * $size, $size),
            'totalPages' => (int) ceil(count($packages) / $size),
            'totalElements' => count($packages),
            'page' => $page,
            'size' => $size,
        ], JSON_THROW_ON_ERROR)];
    }
}
