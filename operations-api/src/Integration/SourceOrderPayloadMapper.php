<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Document\DeliveryContext;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Maps the versioned first-party source order contract (schemaVersion 1) to a
 * normalized SourceOrderSnapshot. Every field is validated strictly; unknown
 * production stages are never guessed from labels or commerce status.
 */
final class SourceOrderPayloadMapper
{
    private const ROOT_KEYS = ['schemaVersion', 'eventId', 'changedAt', 'order', 'production'];
    private const ORDER_KEYS = ['id', 'number', 'status', 'availability', 'notes', 'acceptedAt', 'items', 'delivery'];
    private const ITEM_KEYS = ['id', 'line', 'name', 'sku', 'variant', 'color', 'width', 'height', 'unit', 'meters', 'quantity', 'options'];
    private const MAX_OPTIONS = 20;
    private const MAX_ITEMS = 200;

    /** @param array<string, mixed> $payload */
    public static function map(string $sourceKey, array $payload): SourceOrderSnapshot
    {
        self::allowKeys($payload, self::ROOT_KEYS, ['schemaVersion', 'eventId', 'changedAt', 'order']);
        if ($payload['schemaVersion'] !== 1) {
            throw new ApiException(422, 'SOURCE_SCHEMA_UNSUPPORTED', 'Only source schema version 1 is supported.');
        }
        $order = self::object($payload['order'], 'order');
        self::allowKeys($order, self::ORDER_KEYS, ['id', 'number', 'availability', 'items']);

        $stageId = null;
        if (array_key_exists('production', $payload) && $payload['production'] !== null) {
            $production = self::object($payload['production'], 'production');
            self::allowKeys($production, ['workflowKey', 'workflowVersion', 'stageId', 'stageLabel'], ['workflowKey', 'workflowVersion', 'stageId']);
            if ($production['workflowKey'] !== 'curtain-production' || $production['workflowVersion'] !== 1 || !is_string($production['stageId'])) {
                throw new ApiException(422, 'SOURCE_STAGE_UNKNOWN', 'The source production stage is not part of curtain-production@1.');
            }
            $stageId = $production['stageId'];
        }

        $availability = $order['availability'];
        $operationalStatus = match ($availability) {
            'active' => 'in_progress',
            'cancelled' => 'unavailable',
            default => throw self::invalid('order.availability'),
        };

        $status = null;
        if (array_key_exists('status', $order) && $order['status'] !== null) {
            $status = self::object($order['status'], 'order.status');
            self::allowKeys($status, ['code', 'label'], ['code']);
        }

        $rawItems = $order['items'];
        if (!is_array($rawItems) || !array_is_list($rawItems) || count($rawItems) > self::MAX_ITEMS) {
            throw self::invalid('order.items');
        }
        $items = [];
        foreach ($rawItems as $index => $rawItem) {
            $item = self::object($rawItem, "order.items[{$index}]");
            self::allowKeys($item, self::ITEM_KEYS, ['id', 'line', 'name', 'quantity']);
            try {
                $items[] = new OperationalOrderItem(
                    Uuid::v4(),
                    self::identifier($item['id'], "order.items[{$index}].id"),
                    self::positiveInt($item['line'], "order.items[{$index}].line"),
                    self::string($item['name'], "order.items[{$index}].name"),
                    self::optionalString($item['sku'] ?? null, "order.items[{$index}].sku"),
                    self::optionalString($item['variant'] ?? null, "order.items[{$index}].variant"),
                    self::optionalString($item['color'] ?? null, "order.items[{$index}].color"),
                    self::optionalNumber($item['width'] ?? null, "order.items[{$index}].width"),
                    self::optionalNumber($item['height'] ?? null, "order.items[{$index}].height"),
                    self::optionalString($item['unit'] ?? null, "order.items[{$index}].unit"),
                    self::optionalNumber($item['meters'] ?? null, "order.items[{$index}].meters"),
                    self::positiveInt($item['quantity'], "order.items[{$index}].quantity"),
                    self::options($item['options'] ?? null, "order.items[{$index}].options"),
                );
            } catch (InvalidArgumentException) {
                throw self::invalid("order.items[{$index}]");
            }
        }

        try {
            return new SourceOrderSnapshot(
                $sourceKey,
                self::identifier($order['id'], 'order.id'),
                self::string($payload['eventId'], 'eventId'),
                1,
                self::timestamp($payload['changedAt'], 'changedAt'),
                self::string($order['number'], 'order.number'),
                $stageId,
                $status === null ? null : self::string($status['code'], 'order.status.code'),
                $status === null ? null : self::optionalString($status['label'] ?? null, 'order.status.label'),
                self::optionalString($order['notes'] ?? null, 'order.notes'),
                $operationalStatus,
                array_key_exists('acceptedAt', $order) && $order['acceptedAt'] !== null ? self::timestamp($order['acceptedAt'], 'order.acceptedAt') : null,
                $items,
                self::delivery($order['delivery'] ?? null),
            );
        } catch (InvalidArgumentException) {
            throw self::invalid('order');
        }
    }

    /**
     * Optional printable delivery identity. Email is not part of the contract and is rejected; the
     * phone is masked before it is stored.
     *
     * @return array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null
     */
    private static function delivery(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        try {
            return DeliveryContext::fromSource(self::object($value, 'order.delivery'));
        } catch (InvalidArgumentException) {
            throw self::invalid('order.delivery');
        }
    }

    /**
     * Optional source manufacturing options exactly as the source states them (for example
     * "Confecționare: 2 bucăți"). Nothing is inferred when they are absent.
     *
     * @return array{options: list<array{label: string, value: string}>}|null
     */
    private static function options(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_OPTIONS) {
            throw self::invalid($field);
        }
        $options = [];
        foreach ($value as $index => $option) {
            $option = self::object($option, "{$field}[{$index}]");
            self::allowKeys($option, ['label', 'value'], ['label', 'value']);
            $label = self::string($option['label'], "{$field}[{$index}].label");
            $text = self::string($option['value'], "{$field}[{$index}].value");
            if (mb_strlen($label) > 80 || mb_strlen($text) > 200) {
                throw self::invalid("{$field}[{$index}]");
            }
            $options[] = ['label' => $label, 'value' => $text];
        }
        return $options === [] ? null : ['options' => $options];
    }

    /** @param array<string, mixed> $value @param list<string> $allowed @param list<string> $required */
    private static function allowKeys(array $value, array $allowed, array $required): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw self::invalid((string) $key);
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw self::invalid($key);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($field);
        }
        return $value;
    }

    private static function string(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw self::invalid($field);
        }
        return trim($value);
    }

    private static function optionalString(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::string($value, $field);
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        $string = self::string($value, $field);
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $string) !== 1) {
            throw self::invalid($field);
        }
        return $string;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000_000) {
            throw self::invalid($field);
        }
        return $value;
    }

    private static function optionalNumber(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1_000_000) {
            throw self::invalid($field);
        }
        return round((float) $value, 3);
    }

    private static function timestamp(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw self::invalid($field);
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw self::invalid($field);
        }
    }

    private static function invalid(string $field): ApiException
    {
        return new ApiException(422, 'SOURCE_PAYLOAD_INVALID', "Source payload field is invalid: {$field}.");
    }
}
