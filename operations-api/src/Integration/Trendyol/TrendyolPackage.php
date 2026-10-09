<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Document\DeliveryContext;
use InvalidArgumentException;

/**
 * One Trendyol shipment package as read from the Seller API, reduced to what intake needs.
 *
 * Order V2 field names are read first (`shipmentPackageId`, `lines[].lineId`, `lines[].stockCode`); the older
 * names (`id`, `lines[].id`, `merchantSku`) are accepted as a fallback. Prices, payment, invoice data, the
 * invoice address, the customer email and identity numbers are never read. The delivery identity is reduced
 * to the printable form (masked phone). Cargo barcodes and tracking numbers are never read either: they belong
 * to Trendyol's courier and are never confused with an Arasya production QR.
 */
final readonly class TrendyolPackage
{
    /**
     * @param list<array{lineId: int, lineNumber: int, productName: string, stockCode: string|null, barcode: string|null, productSize: string|null, productColor: string|null, quantity: int, lineStatus: string|null}> $lines
     * @param array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null $delivery
     */
    private function __construct(
        public int $packageId,
        public string $orderNumber,
        public string $status,
        public ?int $orderDateMillis,
        public int $lastModifiedMillis,
        public ?int $channelId,
        public array $lines,
        public ?array $delivery,
    ) {
    }

    /** @param array<string, mixed> $package */
    public static function fromApi(array $package): self
    {
        $packageId = $package['shipmentPackageId'] ?? $package['id'] ?? null;
        $orderNumber = $package['orderNumber'] ?? null;
        $modified = $package['lastModifiedDate'] ?? null;
        $status = $package['shipmentPackageStatus'] ?? $package['status'] ?? null;
        $lines = $package['lines'] ?? null;
        if (!is_int($packageId) || $packageId < 1 || !(is_string($orderNumber) && trim($orderNumber) !== '' || is_int($orderNumber)) || !is_int($modified) || $modified < 0
            || !is_string($status) || preg_match('/^[A-Za-z]{1,40}$/D', $status) !== 1 || !is_array($lines) || !array_is_list($lines) || $lines === []) {
            throw new InvalidArgumentException('Trendyol package is malformed.');
        }
        $orderNumber = trim((string) $orderNumber);
        if (mb_strlen($orderNumber) > 120 || preg_match('/[[:cntrl:]]/', $orderNumber) === 1) {
            throw new InvalidArgumentException('Trendyol package is malformed.');
        }

        $parsed = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            $lineId = is_array($line) ? ($line['lineId'] ?? $line['id'] ?? null) : null;
            if (!is_array($line) || !is_int($lineId) || $lineId < 1 || isset($seen[$lineId]) || !is_string($line['productName'] ?? null) || trim($line['productName']) === ''
                || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 100000) {
                throw new InvalidArgumentException('Trendyol package line is malformed.');
            }
            $seen[$lineId] = true;
            $parsed[] = [
                'lineId' => $lineId,
                'lineNumber' => $index + 1,
                'productName' => mb_substr(trim($line['productName']), 0, 255),
                'stockCode' => self::optional($line['stockCode'] ?? $line['merchantSku'] ?? null, 120),
                'barcode' => self::optional($line['barcode'] ?? null, 120),
                'productSize' => self::optional($line['productSize'] ?? null, 160),
                'productColor' => self::optional($line['productColor'] ?? null, 160),
                'quantity' => $line['quantity'],
                'lineStatus' => self::optional($line['orderLineItemStatusName'] ?? null, 60),
            ];
        }

        $orderDate = $package['orderDate'] ?? null;
        $channel = $package['channelId'] ?? null;
        return new self(
            $packageId,
            $orderNumber,
            $status,
            is_int($orderDate) && $orderDate > 0 ? $orderDate : null,
            $modified,
            is_int($channel) ? $channel : null,
            $parsed,
            self::delivery($package['shipmentAddress'] ?? null),
        );
    }

    /** SHA-256 of the marketplace line content: a change means the prepared production data must be re-checked. */
    public function linesHash(): string
    {
        return hash('sha256', json_encode(array_map(static fn (array $line): array => [
            $line['lineId'], $line['lineNumber'], $line['productName'], $line['stockCode'], $line['barcode'], $line['productSize'], $line['productColor'], $line['quantity'],
        ], $this->lines), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * @return array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null
     */
    private static function delivery(mixed $address): ?array
    {
        if (!is_array($address)) {
            return null;
        }
        $name = self::optional($address['fullName'] ?? null, 160)
            ?? self::optional(trim(((string) ($address['firstName'] ?? '')) . ' ' . ((string) ($address['lastName'] ?? ''))), 160);
        $street = self::optional(trim(((string) ($address['address1'] ?? '')) . ' ' . ((string) ($address['address2'] ?? ''))), 300)
            ?? self::optional($address['fullAddress'] ?? null, 300);
        try {
            return DeliveryContext::fromSource(array_filter([
                'name' => $name,
                'company' => self::optional($address['company'] ?? null, 200),
                'street' => $street,
                'city' => self::optional($address['city'] ?? null, 120),
                'county' => self::optional($address['district'] ?? $address['countyName'] ?? null, 120),
                'postalCode' => self::optional($address['postalCode'] ?? null, 20),
                'country' => self::optional($address['countryCode'] ?? null, 80),
                'phone' => self::optional($address['phone'] ?? null, 40),
            ], static fn ($value): bool => $value !== null));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function optional(mixed $value, int $max): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return mb_substr(trim($value), 0, $max);
    }
}
