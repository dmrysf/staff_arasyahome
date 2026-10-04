<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Maps one Trendyol shipment package to a normalized snapshot.
 *
 * Trendyol package status is commerce data only. It never carries or infers an
 * Arasya production stage, so new packages enter production at `waiting`.
 */
final class TrendyolOrderMapper
{
    private const UNAVAILABLE_STATUSES = ['Cancelled', 'UnSupplied'];

    /** @param array<string, mixed> $package */
    public static function map(array $package): SourceOrderSnapshot
    {
        $packageId = $package['id'] ?? null;
        $orderNumber = $package['orderNumber'] ?? null;
        $modified = $package['lastModifiedDate'] ?? null;
        $status = $package['shipmentPackageStatus'] ?? $package['status'] ?? null;
        $lines = $package['lines'] ?? null;
        if (!is_int($packageId) || $packageId < 1 || !is_string($orderNumber) && !is_int($orderNumber) || !is_int($modified) || !is_string($status) || !is_array($lines) || !array_is_list($lines)) {
            throw new InvalidArgumentException('Trendyol package is malformed.');
        }

        $items = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line) || !is_int($line['id'] ?? null) || !is_string($line['productName'] ?? null) || !is_int($line['quantity'] ?? null)) {
                throw new InvalidArgumentException('Trendyol package line is malformed.');
            }
            $items[] = new OperationalOrderItem(
                Uuid::v4(),
                (string) $line['id'],
                $index + 1,
                mb_substr(trim($line['productName']), 0, 255),
                self::optional($line['merchantSku'] ?? $line['sku'] ?? null, 120),
                self::optional($line['productSize'] ?? null, 160),
                self::optional($line['productColor'] ?? null, 160),
                null,
                null,
                null,
                null,
                $line['quantity'],
            );
        }

        $orderDate = $package['orderDate'] ?? null;
        return new SourceOrderSnapshot(
            'trendyol',
            (string) $packageId,
            "package-{$packageId}-{$modified}",
            1,
            self::fromMillis($modified),
            (string) $orderNumber,
            null,
            mb_substr($status, 0, 100),
            mb_substr($status, 0, 160),
            null,
            in_array($status, self::UNAVAILABLE_STATUSES, true) ? 'unavailable' : 'in_progress',
            is_int($orderDate) ? self::fromMillis($orderDate) : null,
            $items,
        );
    }

    private static function optional(mixed $value, int $max): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return mb_substr(trim($value), 0, $max);
    }

    private static function fromMillis(int $millis): DateTimeImmutable
    {
        $seconds = intdiv($millis, 1000);
        $micro = ($millis % 1000) * 1000;
        return DateTimeImmutable::createFromFormat('U u', sprintf('%d %06d', $seconds, $micro), new DateTimeZone('UTC'))
            ?: throw new InvalidArgumentException('Invalid Trendyol timestamp.');
    }
}
