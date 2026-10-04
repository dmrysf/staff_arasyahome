<?php
/**
 * Plugin Name: Arasya Operations Connector – WC Kalkulator mapping
 * Description: Maps WC Kalkulator curtain fields (L / H / manopera / bucăți) to the Arasya Operations connector filters.
 * Version: 2.2.0
 * Requires PHP: 8.1
 *
 * Install next to arasya-operations-connector.php as a must-use plugin on a WooCommerce site
 * whose curtain calculator is WC Kalkulator. It only reads existing order item meta; it never
 * writes to orders. Verified on Trendhome, whose fieldsets store:
 *   _wck_fields = { lungimea: "<L in m>", inaltime: "<H in m>", manopera: "<id>:<label>", buc: "<id>:<label>" }
 *   _wck_stock_reduction_multiplier = fabric meters per unit (WooCommerce reduces stock by quantity × multiplier)
 * Another site with different field names overrides the `arasya_operations_wck_field_map` filter.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @return array{width: string, height: string, unit: string, labor: string, pieces: string} */
function arasya_wck_field_map(): array
{
    $default = ['width' => 'lungimea', 'height' => 'inaltime', 'unit' => 'm', 'labor' => 'manopera', 'pieces' => 'buc'];
    if (!function_exists('apply_filters')) {
        return $default;
    }
    $map = (array) apply_filters('arasya_operations_wck_field_map', $default);
    return array_map('strval', array_replace($default, array_intersect_key($map, $default)));
}

/** @return array<string, mixed> The calculator values of one order item, or [] when it has none. */
function arasya_wck_fields(object $item): array
{
    $fields = $item->get_meta('_wck_fields', true);
    if (is_string($fields) && $fields !== '') {
        $fields = json_decode($fields, true);
    }
    return is_array($fields) ? $fields : [];
}

/** Turns a WC Kalkulator select value such as "18:Manopera Rejansa 10 cm" into its label. */
function arasya_wck_option_label(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }
    $label = trim((string) preg_replace('/^\d+:/', '', trim((string) $value)));
    return $label === '' ? null : $label;
}

/**
 * Fills the measurements the generic connector could not read. Explicit `_arasya_*` meta always wins.
 *
 * @param array<string, mixed> $measurements
 * @return array<string, mixed>
 */
function arasya_wck_item_measurements(array $measurements, object $item): array
{
    $fields = arasya_wck_fields($item);
    if ($fields === []) {
        return $measurements;
    }
    $map = arasya_wck_field_map();
    $width = arasya_ops_number($fields[$map['width']] ?? null);
    $height = arasya_ops_number($fields[$map['height']] ?? null);
    if (($measurements['width'] ?? null) === null && ($measurements['height'] ?? null) === null && $width !== null && $height !== null) {
        $measurements['width'] = $width;
        $measurements['height'] = $height;
        $measurements['unit'] = in_array($map['unit'], ['mm', 'cm', 'm'], true) ? $map['unit'] : null;
    }
    if (($measurements['meters'] ?? null) === null) {
        $perUnit = arasya_ops_number($item->get_meta('_wck_stock_reduction_multiplier', true));
        if ($perUnit !== null && $perUnit > 0) {
            $measurements['meters'] = round($perUnit * max(1, (int) $item->get_quantity()), 3);
        }
    }
    return $measurements;
}

/**
 * Adds one production line per calculator item (workmanship and number of panels) after any
 * explicit `_arasya_production_notes`. No customer-entered text is read.
 */
function arasya_wck_production_notes(string $notes, object $order): string
{
    $map = arasya_wck_field_map();
    $lines = [];
    $line = 0;
    foreach ($order->get_items() as $item) {
        $line++;
        $fields = arasya_wck_fields($item);
        if ($fields === []) {
            continue;
        }
        $parts = array_values(array_filter([
            arasya_wck_option_label($fields[$map['labor']] ?? null),
            arasya_wck_option_label($fields[$map['pieces']] ?? null),
        ]));
        if ($parts === []) {
            continue;
        }
        $product = method_exists($item, 'get_product') ? $item->get_product() : null;
        $sku = $product !== null && method_exists($product, 'get_sku') ? trim((string) $product->get_sku()) : '';
        $lines[] = sprintf('Linia %d%s: %s', $line, $sku === '' ? '' : " ({$sku})", implode(', ', $parts));
    }
    return trim(implode("\n", array_filter([trim($notes), implode("\n", $lines)])));
}

if (function_exists('add_filter')) {
    add_filter('arasya_operations_item_measurements', static fn (array $measurements, object $item): array => arasya_wck_item_measurements($measurements, $item), 10, 2);
    add_filter('arasya_operations_production_notes', static fn (mixed $notes, object $order): string => arasya_wck_production_notes((string) $notes, $order), 10, 2);
}
