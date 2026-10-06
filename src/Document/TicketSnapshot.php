<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use JsonException;
use PDO;
use RuntimeException;

/**
 * The exact, source-neutral content of a production ticket, read in one consistent database state.
 *
 * Only data that is printed on the workshop document enters the snapshot: order identity, source key,
 * delivery identity (recipient, address, masked phone), production notes and the production lines with
 * their manufacturing data and frozen project location. Prices, payment, balances, email, the current
 * stage and presentation labels never enter it, so a commercial-only change or a label translation
 * never makes a printed document stale.
 *
 * Source-specific normalization happens before: ingestion (or the B2B handoff) freezes the printable
 * delivery identity into the order's document context. The renderer never branches on source.
 *
 * The fingerprint is SHA-256 of the canonical JSON of schema 1. A later schema adds a new builder
 * version; revisions keep the schema they were generated with, so old fingerprints stay comparable.
 */
final readonly class TicketSnapshot
{
    public const SCHEMA = 1;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Builds the snapshot of an order. The caller holds the order row lock when the result is bound to
     * a revision, a request or a staleness decision.
     *
     * @return array<string, mixed>
     */
    public function build(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, source_key, source_order_id, order_number, order_lookup_code, production_notes, production_context, document_context FROM operational_orders WHERE order_uuid = ?');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            throw new RuntimeException('Canonical order is missing.');
        }
        $items = $this->pdo->prepare('SELECT line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity, production_context FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $items->execute([$orderUuid]);
        $lines = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $context = self::decode($item['production_context']);
            $lines[] = [
                'line' => (int) $item['line_number'],
                'code' => $item['product_code'] === null ? null : (string) $item['product_code'],
                'name' => (string) $item['name'],
                'kind' => is_string($context['kind'] ?? null) ? $context['kind'] : null,
                'variant' => $item['variant'] === null ? null : (string) $item['variant'],
                'color' => $item['color'] === null ? null : (string) $item['color'],
                'width' => self::decimal($item['width_value']),
                'height' => self::decimal($item['height_value']),
                'unit' => $item['measurement_unit'] === null ? null : (string) $item['measurement_unit'],
                'meters' => self::decimal($item['meters']),
                'quantity' => (int) $item['quantity'],
                'notes' => self::optionalText($context['notes'] ?? null),
                'productionNotes' => self::optionalText($context['productionNotes'] ?? null),
                'options' => self::options($context['options'] ?? null),
                'project' => self::project($context['project'] ?? null),
            ];
        }
        return [
            'schema' => self::SCHEMA,
            'order' => [
                'number' => (string) $order['order_number'],
                'lookupCode' => (string) $order['order_lookup_code'],
                'source' => (string) $order['source_key'],
            ],
            'customer' => $this->customer($order),
            'notes' => $order['production_notes'] === null ? null : (string) $order['production_notes'],
            'lines' => $lines,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', self::canonicalJson($snapshot), true);
    }

    /** @param array<string, mixed> $snapshot */
    public static function canonicalJson(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function fromJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored document snapshot is unreadable.');
        }
        return is_array($decoded) ? $decoded : throw new RuntimeException('Stored document snapshot is unreadable.');
    }

    /**
     * Human-readable production diff between two snapshots, only printed fields. Values are already
     * formatted for Romanian display; `field` is a stable key a client may translate.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<array{field: string, line: int|null, before: string|null, after: string|null}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        $add = static function (string $field, ?int $line, ?string $old, ?string $new) use (&$changes): void {
            if ($old !== $new) {
                $changes[] = ['field' => $field, 'line' => $line, 'before' => $old, 'after' => $new];
            }
        };
        $add('orderNumber', null, $before['order']['number'] ?? null, $after['order']['number'] ?? null);
        $oldCustomer = $before['customer'] ?? null;
        $newCustomer = $after['customer'] ?? null;
        foreach (['name' => 'customer.name', 'company' => 'customer.company', 'contact' => 'customer.contact', 'phoneMasked' => 'customer.phone'] as $key => $field) {
            $add($field, null, $oldCustomer[$key] ?? null, $newCustomer[$key] ?? null);
        }
        $add('customer.address', null, self::joinLines($oldCustomer['addressLines'] ?? []), self::joinLines($newCustomer['addressLines'] ?? []));
        $add('notes', null, $before['notes'] ?? null, $after['notes'] ?? null);

        $oldLines = array_column($before['lines'] ?? [], null, 'line');
        $newLines = array_column($after['lines'] ?? [], null, 'line');
        $numbers = array_unique([...array_keys($oldLines), ...array_keys($newLines)]);
        sort($numbers);
        foreach ($numbers as $number) {
            $old = $oldLines[$number] ?? null;
            $new = $newLines[$number] ?? null;
            if ($old === null || $new === null) {
                $add($old === null ? 'line.added' : 'line.removed', (int) $number, $old === null ? null : self::lineSummary($old), $new === null ? null : self::lineSummary($new));
                continue;
            }
            $add('line.code', (int) $number, $old['code'], $new['code']);
            $add('line.name', (int) $number, $old['name'], $new['name']);
            $add('line.kind', (int) $number, $old['kind'], $new['kind']);
            $add('line.variant', (int) $number, $old['variant'], $new['variant']);
            $add('line.color', (int) $number, $old['color'], $new['color']);
            $add('line.width', (int) $number, self::measure($old['width'], $old['unit']), self::measure($new['width'], $new['unit']));
            $add('line.height', (int) $number, self::measure($old['height'], $old['unit']), self::measure($new['height'], $new['unit']));
            $add('line.meters', (int) $number, self::measure($old['meters'], 'm'), self::measure($new['meters'], 'm'));
            $add('line.quantity', (int) $number, (string) $old['quantity'], (string) $new['quantity']);
            $add('line.notes', (int) $number, $old['notes'], $new['notes']);
            $add('line.productionNotes', (int) $number, $old['productionNotes'], $new['productionNotes']);
            $add('line.options', (int) $number, self::optionsText($old['options']), self::optionsText($new['options']));
            $add('line.location', (int) $number, self::locationText($old['project']), self::locationText($new['project']));
        }
        return $changes;
    }

    /**
     * Whole-line meters of lines whose manufacturing content changed or disappeared between snapshots.
     * Used only as objective "processed under an obsolete document" analytics, never as a fault.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{meters: string|null, lines: int}
     */
    public static function changedLineMeters(array $before, array $after): array
    {
        $newLines = array_column($after['lines'] ?? [], null, 'line');
        $units = 0;
        $known = false;
        $count = 0;
        foreach ($before['lines'] ?? [] as $old) {
            $new = $newLines[$old['line']] ?? null;
            $same = $new !== null;
            foreach (['code', 'variant', 'color', 'width', 'height', 'unit', 'meters', 'quantity', 'options'] as $key) {
                $same = $same && ($old[$key] ?? null) === ($new[$key] ?? null);
            }
            if ($same) {
                continue;
            }
            $count++;
            if ($old['meters'] !== null) {
                $known = true;
                $units += self::units((string) $old['meters']);
            }
        }
        return ['meters' => $known ? intdiv($units, 1000) . '.' . str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT) : null, 'lines' => $count];
    }

    /** "8.000" with unit "m" becomes "8 m"; "180.500" with "cm" becomes "180,5 cm". */
    public static function measure(?string $value, ?string $unit): ?string
    {
        if ($value === null) {
            return null;
        }
        [$int, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        return $int . ($fraction === '' ? '' : ',' . $fraction) . ($unit === null ? '' : ' ' . $unit);
    }

    /** @param array<string, mixed>|null $project */
    public static function locationText(?array $project): ?string
    {
        if ($project === null) {
            return null;
        }
        return implode(' › ', array_filter([
            $project['project']['code'] ?? null,
            $project['zone']['name'] ?? null,
            $project['room']['name'] ?? null,
            $project['opening']['name'] ?? null,
        ], static fn ($part): bool => is_string($part) && $part !== ''));
    }

    /** @param list<array{label: string, value: string}> $options */
    public static function optionsText(array $options): ?string
    {
        return $options === [] ? null : implode('; ', array_map(static fn (array $option): string => $option['label'] . ': ' . $option['value'], $options));
    }

    /**
     * Printable customer/delivery identity. Every source provides it as the order's frozen document
     * context; B2B handoffs made before this context existed are read through the B2B source adapter.
     *
     * @param array<string, mixed> $order
     */
    private function customer(array $order): ?array
    {
        $context = self::decode($order['document_context']);
        if ($context === [] && $order['source_key'] === 'b2b') {
            $context = \Arasya\Operations\B2B\ProductionDocumentIdentity::forOperationalOrder($this->pdo, (string) $order['order_uuid']) ?? [];
        }
        if ($context === []) {
            return null;
        }
        return [
            'name' => self::optionalText($context['name'] ?? null),
            'company' => self::optionalText($context['company'] ?? null),
            'contact' => self::optionalText($context['contact'] ?? null),
            'addressLines' => array_values(array_filter(is_array($context['addressLines'] ?? null) ? $context['addressLines'] : [], 'is_string')),
            'phoneMasked' => self::optionalText($context['phoneMasked'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true, 32);
        return is_array($decoded) ? $decoded : [];
    }

    /** Exact DECIMAL(12,3) text, never a float: "8" or "8.5" become "8.000" and "8.500". */
    public static function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = (string) $value;
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/D', $text, $m) !== 1) {
            throw new RuntimeException('Invalid stored measurement.');
        }
        return (ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0')) . '.' . substr(str_pad($m[2] ?? '', 3, '0'), 0, 3);
    }

    private static function units(string $decimal): int
    {
        [$int, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        return (int) $int * 1000 + (int) substr(str_pad($fraction, 3, '0'), 0, 3);
    }

    private static function optionalText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<array{label: string, value: string}> */
    private static function options(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $options = [];
        foreach ($value as $option) {
            if (is_array($option) && is_string($option['label'] ?? null) && is_string($option['value'] ?? null)) {
                $options[] = ['label' => $option['label'], 'value' => $option['value']];
            }
        }
        return $options;
    }

    /** @return array<string, mixed>|null */
    private static function project(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $pick = static function (mixed $source, array $keys): array {
            $out = [];
            foreach ($keys as $key) {
                $item = is_array($source) ? ($source[$key] ?? null) : null;
                $out[$key] = is_scalar($item) ? (is_string($item) ? $item : (string) $item) : null;
            }
            return $out;
        };
        return [
            'project' => $pick($value['project'] ?? null, ['id', 'code', 'name']),
            'zone' => $pick($value['zone'] ?? null, ['id', 'name', 'zoneType', 'level', 'building']),
            'room' => $pick($value['room'] ?? null, ['id', 'name']),
            'opening' => $pick($value['opening'] ?? null, ['id', 'name', 'openingType', 'width', 'height', 'mounting', 'railType']),
            'treatment' => $pick($value['treatment'] ?? null, ['id', 'treatmentType', 'panelLayout']),
        ];
    }

    /** @param list<string> $lines */
    private static function joinLines(array $lines): ?string
    {
        return $lines === [] ? null : implode(', ', $lines);
    }

    /** @param array<string, mixed> $line */
    private static function lineSummary(array $line): string
    {
        return implode(' · ', array_filter([
            $line['code'],
            $line['name'],
            $line['color'],
            $line['variant'],
            self::measure($line['meters'], 'm'),
            $line['quantity'] . ' buc.',
        ], static fn ($part): bool => $part !== null && $part !== ''));
    }
}
