<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

/**
 * Evidence gate for Romanian production titles: for real order lines, puts the order API's `productName` next to
 * the seller's approved catalogue title of the same barcode, read once with `Accept-Language: ro` and once with
 * `en`. It decides nothing and writes nothing. A line is `matched` only when exactly one catalogue variant carries
 * its barcode in both languages and both answers name the same content and variant; anything else is reported as
 * `missing` or `ambiguous`, never guessed. Titles are compared byte for byte (no normalization, no translation).
 *
 * The report holds product data only: no credentials, no customer name, address or phone.
 */
final readonly class TrendyolTitleEvidence
{
    public function __construct(private TrendyolClient $orders, private TrendyolCatalogueClient $catalogue)
    {
    }

    /**
     * @param list<int> $packageIds
     * @return array{storeFrontCode: string, lines: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function forPackages(array $packageIds, string $storeFrontCode): array
    {
        $lines = [];
        foreach ($this->orders->packagesByIds($packageIds) as $raw) {
            $package = TrendyolPackage::fromApi($raw);
            foreach ($package->lines as $line) {
                $lines[] = ['packageId' => $package->packageId, 'lineId' => $line['lineId'], 'barcode' => $line['barcode'], 'productCode' => $line['productCode'],
                    'productSize' => $line['productSize'], 'productColor' => $line['productColor'], 'orderTitle' => $line['productName']];
            }
        }
        return $this->compare($lines, $storeFrontCode);
    }

    /**
     * @param list<array{packageId: int|null, lineId: int|null, barcode: string|null, productCode: string|null, productSize: string|null, productColor: string|null, orderTitle: string|null}> $lines
     * @return array{storeFrontCode: string, lines: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function compare(array $lines, string $storeFrontCode): array
    {
        $barcodes = array_values(array_unique(array_filter(array_column($lines, 'barcode'), static fn ($barcode): bool => is_string($barcode) && $barcode !== '')));
        $byLanguage = [];
        foreach (TrendyolCatalogueClient::LANGUAGES as $language) {
            $byLanguage[$language] = [];
            foreach (array_chunk($barcodes, TrendyolCatalogueClient::MAX_BARCODES) as $chunk) {
                foreach ($this->catalogue->approvedByBarcodes($chunk, $storeFrontCode, $language) as $content) {
                    foreach ($content['variants'] as $variant) {
                        $barcode = is_array($variant) ? ($variant['barcode'] ?? null) : null;
                        if (is_string($barcode) && in_array($barcode, $chunk, true)) {
                            $byLanguage[$language][$barcode][] = [
                                'title' => is_string($content['title'] ?? null) ? $content['title'] : null,
                                'contentId' => $content['contentId'] ?? null,
                                'productMainId' => is_string($content['productMainId'] ?? null) ? $content['productMainId'] : null,
                                'variantId' => $variant['variantId'] ?? null,
                                'stockCode' => is_string($variant['stockCode'] ?? null) ? $variant['stockCode'] : null,
                            ];
                        }
                    }
                }
            }
        }
        $summary = ['lines' => 0, 'matched' => 0, 'missing' => 0, 'ambiguous' => 0, 'romanianDiffersFromOrder' => 0, 'romanianDiffersFromEnglish' => 0];
        $report = [];
        foreach ($lines as $line) {
            $summary['lines']++;
            $barcode = $line['barcode'];
            $ro = is_string($barcode) ? ($byLanguage['ro'][$barcode] ?? []) : [];
            $en = is_string($barcode) ? ($byLanguage['en'][$barcode] ?? []) : [];
            $status = match (true) {
                count($ro) === 0 || count($en) === 0 => 'missing',
                count($ro) > 1 || count($en) > 1 || $ro[0]['contentId'] !== $en[0]['contentId'] || $ro[0]['variantId'] !== $en[0]['variantId'] || !is_string($ro[0]['title']) || $ro[0]['title'] === '' => 'ambiguous',
                default => 'matched',
            };
            $summary[$status]++;
            $entry = $line + ['status' => $status, 'catalogue' => null];
            if ($status === 'matched') {
                $entry['catalogue'] = ['titleRo' => $ro[0]['title'], 'titleEn' => $en[0]['title'], 'contentId' => $ro[0]['contentId'], 'productMainId' => $ro[0]['productMainId'],
                    'variantId' => $ro[0]['variantId'], 'stockCode' => $ro[0]['stockCode']];
                $entry['romanianDiffersFromOrder'] = $ro[0]['title'] !== $line['orderTitle'];
                $entry['romanianDiffersFromEnglish'] = $ro[0]['title'] !== $en[0]['title'];
                $entry['orderTitleEqualsEnglish'] = $en[0]['title'] === $line['orderTitle'];
                $summary['romanianDiffersFromOrder'] += $entry['romanianDiffersFromOrder'] ? 1 : 0;
                $summary['romanianDiffersFromEnglish'] += $entry['romanianDiffersFromEnglish'] ? 1 : 0;
            } else {
                $entry['candidates'] = ['ro' => count($ro), 'en' => count($en)];
            }
            $report[] = $entry;
        }
        return ['storeFrontCode' => $storeFrontCode, 'lines' => $report, 'summary' => $summary];
    }
}
