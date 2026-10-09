<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only preview of what Trendyol returns and how intake would classify it. It holds no database
 * connection: it cannot create a production order, an intake row, a QR, a document or a cursor. It only sends
 * GET requests through TrendyolClient.
 *
 * The report never contains credentials, customer names, addresses, phone numbers or email: only package and
 * line identifiers, statuses, timestamps, product data, the measurement suggestion and the classification, plus
 * a field-presence summary that shows which Order V2 field names the account really returns.
 */
final readonly class TrendyolPreview
{
    public const MAX_PACKAGES = 2000;

    public function __construct(private TrendyolClient $client)
    {
    }

    /**
     * @return array{window: array{start: string, end: string}, baseline: string, pages: int, totalElements: int|null, truncated: bool,
     *   counts: array<string, int>, fields: array<string, int>, packages: list<array<string, mixed>>}
     */
    public function run(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $baseline): array
    {
        if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > TrendyolClient::MAX_WINDOW_SECONDS) {
            throw new RuntimeException('TRENDYOL_WINDOW_INVALID');
        }
        $utc = new DateTimeZone('UTC');
        $baselineMillis = $baseline->getTimestamp() * 1000;
        $packages = [];
        $counts = ['eligible' => 0, 'deferred' => 0, 'historical' => 0, 'status_not_eligible' => 0, 'order_date_missing' => 0, 'malformed' => 0];
        $fields = [];
        $page = 0;
        $totalElements = null;
        do {
            $result = $this->client->packages($start->getTimestamp() * 1000, $end->getTimestamp() * 1000, $page);
            $totalElements ??= $result['totalElements'];
            foreach ($result['content'] as $raw) {
                foreach (['shipmentPackageId', 'id', 'orderNumber', 'orderDate', 'lastModifiedDate', 'shipmentPackageStatus', 'status', 'channelId', 'shipmentAddress', 'lines'] as $field) {
                    if (is_array($raw) && array_key_exists($field, $raw)) {
                        $fields[$field] = ($fields[$field] ?? 0) + 1;
                    }
                }
                foreach (is_array($raw) && is_array($raw['lines'] ?? null) ? $raw['lines'] : [] as $line) {
                    foreach (['lineId', 'id', 'stockCode', 'merchantSku', 'barcode', 'productSize', 'productColor', 'orderLineItemStatusName'] as $field) {
                        if (is_array($line) && array_key_exists($field, $line)) {
                            $fields['lines.' . $field] = ($fields['lines.' . $field] ?? 0) + 1;
                        }
                    }
                }
                try {
                    $package = TrendyolPackage::fromApi(is_array($raw) ? $raw : []);
                } catch (InvalidArgumentException) {
                    $counts['malformed']++;
                    continue;
                }
                $decision = TrendyolEligibility::classify($package, $baselineMillis);
                $counts[$decision]++;
                if (count($packages) < self::MAX_PACKAGES) {
                    $packages[] = [
                        'packageId' => $package->packageId,
                        'orderNumber' => $package->orderNumber,
                        'status' => $package->status,
                        'orderDate' => $package->orderDateMillis === null ? null : self::iso($package->orderDateMillis, $utc),
                        'orderDateMillis' => $package->orderDateMillis,
                        'lastModified' => self::iso($package->lastModifiedMillis, $utc),
                        'channelId' => $package->channelId,
                        'hasDelivery' => $package->delivery !== null,
                        'classification' => $decision,
                        'lines' => array_map(static fn (array $line): array => [
                            'lineId' => $line['lineId'],
                            'productName' => $line['productName'],
                            'stockCode' => $line['stockCode'],
                            'productSize' => $line['productSize'],
                            'productColor' => $line['productColor'],
                            'quantity' => $line['quantity'],
                            'sizeSuggestion' => TrendyolSizeHint::suggest($line['productSize'], $line['productName']),
                        ], $package->lines),
                    ];
                }
            }
            $page++;
        } while ($page < $result['totalPages'] && $page < TrendyolClient::MAX_PAGES);

        ksort($fields);
        return [
            'window' => ['start' => $start->setTimezone($utc)->format(DATE_ATOM), 'end' => $end->setTimezone($utc)->format(DATE_ATOM)],
            'baseline' => $baseline->setTimezone($utc)->format(DATE_ATOM),
            'pages' => $page,
            'totalElements' => $totalElements,
            'truncated' => $page < $result['totalPages'],
            'counts' => $counts,
            'fields' => $fields,
            'packages' => $packages,
        ];
    }

    private static function iso(int $millis, DateTimeZone $utc): string
    {
        return (new DateTimeImmutable('@' . intdiv($millis, 1000)))->setTimezone($utc)->format(DATE_ATOM);
    }
}
