<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

/**
 * Validates the shop's order detail (YD SOFT `OrderDetail`) and copies ONLY the known fields into the shape Staff
 * shows. Anything unknown is dropped, anything malformed rejects the whole answer (SOURCE_RESPONSE_INVALID): the
 * operator never sees half-trusted data. The result is relayed to the browser once and never stored, logged or cached
 * by Operations (it holds the customer's telephone numbers and the order amounts).
 *
 * API 2.51.0: a line's catalog picture (YD SOFT 2.24.0) is relayed only as an https address on the shop's own host (or
 * one of its subdomains); any other address is dropped to null so a picture can never point the operator's browser
 * elsewhere. A missing `image` (an older shop) is null.
 */
final class SourceOrderDetail
{
    use ValidatesSourceAnswer;

    private const MAX_LINES = 200;
    private const MAX_DETAILS = 40;
    private const MAX_SMALL = 20;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function from(array $data, string $sourceOrderId, string $orderNumber, ?string $shopHost = null): array
    {
        if (($data['protocol'] ?? null) !== 1) {
            throw self::invalid();
        }
        $order = self::map($data['order'] ?? null);
        if (self::text($order['id'] ?? null, 20) !== $sourceOrderId || self::text($order['number'] ?? null, 64) !== $orderNumber) {
            // An answer about another order is never shown.
            throw self::invalid();
        }
        $status = self::map($order['status'] ?? null);
        $contact = self::map($data['contact'] ?? null);
        $payment = self::map($data['payment'] ?? null);
        $financial = self::map($payment['financial'] ?? null);
        $advance = $payment['advance'] ?? null;
        return [
            'readAt' => self::instant($data['readAt'] ?? null),
            'order' => [
                'id' => $sourceOrderId,
                'number' => $orderNumber,
                'status' => ['code' => self::text($status['code'] ?? null, 40), 'label' => self::text($status['label'] ?? null, 100)],
                'createdAt' => self::optionalInstant($order['createdAt'] ?? null),
                'modifiedAt' => self::optionalInstant($order['modifiedAt'] ?? null),
                'storage' => self::choice($order['storage'] ?? null, ['hpos', 'posts']),
                'currency' => self::pattern($order['currency'] ?? null, '/^[A-Z]{3}$/D'),
                'pricesIncludeTax' => self::bool($order['pricesIncludeTax'] ?? null),
            ],
            'version' => self::pattern($data['version'] ?? null, self::FINGERPRINT),
            'pricingFingerprint' => ($data['pricingFingerprint'] ?? null) === null ? null : self::pattern($data['pricingFingerprint'], self::FINGERPRINT),
            'contact' => [
                'phoneAvailable' => self::bool($contact['phoneAvailable'] ?? null),
                'billing' => self::person($contact['billing'] ?? null),
                'shipping' => self::person($contact['shipping'] ?? null),
            ],
            'lines' => self::lines($data['lines'] ?? null, $shopHost),
            'fees' => self::list($data['fees'] ?? null, self::MAX_SMALL, static fn (array $fee): array => [
                'name' => self::text($fee['name'] ?? null, 200),
                'total' => self::money($fee['total'] ?? null),
                'tax' => self::money($fee['tax'] ?? null),
            ]),
            'coupons' => self::list($data['coupons'] ?? null, self::MAX_SMALL, static fn (array $coupon): array => [
                'code' => self::text($coupon['code'] ?? null, 100),
                'discount' => self::money($coupon['discount'] ?? null),
                'discountTax' => self::money($coupon['discountTax'] ?? null),
            ]),
            'shipping' => self::list($data['shipping'] ?? null, self::MAX_SMALL, static fn (array $line): array => [
                'id' => self::pattern($line['id'] ?? null, '/^[0-9]{1,20}$/D'),
                'methodId' => self::text($line['methodId'] ?? null, 100, true),
                'instanceId' => self::text($line['instanceId'] ?? null, 20, true),
                'title' => self::text($line['title'] ?? null, 200, true),
                'total' => self::money($line['total'] ?? null),
                'tax' => self::money($line['tax'] ?? null),
            ]),
            'totals' => self::totals($data['totals'] ?? null),
            'payment' => [
                'method' => self::text($payment['method'] ?? null, 100, true),
                'methodTitle' => self::text($payment['methodTitle'] ?? null, 200, true),
                'cod' => self::bool($payment['cod'] ?? null),
                'paidRecorded' => self::bool($payment['paidRecorded'] ?? null),
                'financial' => [
                    'state' => self::choice($financial['state'] ?? null, ['cleared', 'waiting', 'partial', 'unknown']),
                    'basis' => ($financial['basis'] ?? null) === null ? null : self::choice($financial['basis'], ['online_payment', 'zero_total', 'advance_collected']),
                ],
                'advance' => $advance === null ? null : self::advance($advance),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function lines(mixed $value, ?string $shopHost): array
    {
        return self::list($value, self::MAX_LINES, static function (array $line) use ($shopHost): array {
            $quantity = $line['quantity'] ?? null;
            if (!(is_int($quantity) || is_float($quantity)) || $quantity <= 0 || $quantity > 100000) {
                throw self::invalid();
            }
            return [
                'id' => self::pattern($line['id'] ?? null, '/^[0-9]{1,20}$/D'),
                'line' => self::positive($line['line'] ?? null),
                'productId' => self::pattern($line['productId'] ?? null, '/^[0-9]{1,20}$/D'),
                'variationId' => self::pattern($line['variationId'] ?? null, '/^[0-9]{1,20}$/D'),
                'name' => self::text($line['name'] ?? null, 300),
                'sku' => self::text($line['sku'] ?? null, 100, true),
                'width' => self::measure($line['width'] ?? null),
                'height' => self::measure($line['height'] ?? null),
                'unit' => ($line['unit'] ?? null) === null ? null : self::choice($line['unit'], ['m']),
                'details' => self::list($line['details'] ?? null, self::MAX_DETAILS, static fn (array $detail): array => [
                    'label' => self::text($detail['label'] ?? null, 200),
                    'value' => self::text($detail['value'] ?? null, 500),
                ]),
                'quantity' => $quantity,
                'unitPrice' => self::money($line['unitPrice'] ?? null),
                'subtotal' => self::money($line['subtotal'] ?? null),
                'discount' => self::money($line['discount'] ?? null),
                'tax' => self::money($line['tax'] ?? null),
                'total' => self::money($line['total'] ?? null),
                'calculator' => self::bool($line['calculator'] ?? null),
                'image' => self::image($line['image'] ?? null, $shopHost),
            ];
        });
    }

    /** @return array{url: string, width: int, height: int, kind: string}|null */
    private static function image(mixed $value, ?string $shopHost): ?array
    {
        if ($value === null) {
            return null;
        }
        $image = self::map($value);
        $url = self::text($image['url'] ?? null, 1000);
        $width = $image['width'] ?? null;
        $height = $image['height'] ?? null;
        if (!is_int($width) || !is_int($height) || $width < 0 || $height < 0 || $width > 20000 || $height > 20000) {
            throw self::invalid();
        }
        $kind = self::choice($image['kind'] ?? null, ['variation', 'product']);
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        // The shop's domain without a leading "www.": the domain itself and its subdomains (www, a media host) qualify.
        $domain = $shopHost === null ? '' : (string) preg_replace('/^www\./', '', strtolower($shopHost));
        $onShop = $domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !$onShop
            || preg_match('/[\s"\'<>\\\\]/', $url) === 1) {
            return null;
        }
        return ['url' => $url, 'width' => $width, 'height' => $height, 'kind' => $kind];
    }

    /** @return array<string, mixed> */
    private static function person(mixed $value): array
    {
        $person = self::map($value);
        $lines = $person['addressLines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > 6) {
            throw self::invalid();
        }
        return [
            'name' => self::text($person['name'] ?? null, 200, true),
            'company' => self::text($person['company'] ?? null, 200, true),
            'phone' => self::phone($person['phone'] ?? null),
            'addressLines' => array_map(static fn (mixed $line): string => self::text($line, 300), $lines),
        ];
    }

    /** @return array<string, string> */
    private static function totals(mixed $value): array
    {
        $totals = self::map($value);
        $out = [];
        foreach (['subtotal', 'discount', 'discountTax', 'shipping', 'shippingTax', 'fees', 'tax', 'total', 'refunded'] as $key) {
            $out[$key] = self::money($totals[$key] ?? null);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function advance(mixed $value): array
    {
        $advance = self::map($value);
        $percentage = $advance['percentage'] ?? null;
        if (!(is_int($percentage) || is_float($percentage)) || $percentage < 0 || $percentage > 100) {
            throw self::invalid();
        }
        return [
            'percentage' => $percentage,
            'required' => ($advance['required'] ?? null) === null ? null : self::money($advance['required']),
            'collected' => ($advance['collected'] ?? null) === null ? null : self::money($advance['collected']),
        ];
    }

    private static function phone(mixed $value): string
    {
        $phone = self::text($value, 40, true);
        if ($phone !== '' && preg_match('/^[0-9 +().\/-]{1,40}$/D', $phone) !== 1) {
            throw self::invalid();
        }
        return $phone;
    }

    private static function measure(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!(is_int($value) || is_float($value)) || $value <= 0 || $value > 1000) {
            throw self::invalid();
        }
        return (float) $value;
    }
}
