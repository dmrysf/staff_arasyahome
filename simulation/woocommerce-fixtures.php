<?php
// Minimal Woo read interface + filter dispatcher. All mapping decisions remain in the real plugins.
// This file never registers a source, sends HTTP, installs WordPress or mutates commerce.
declare(strict_types=1);
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!function_exists('apply_filters')) {
    function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
        return match ($name) {
            'arasya_operations_item_measurements' => arasya_wck_item_measurements($value, $args[0]),
            'arasya_operations_production_notes' => arasya_wck_production_notes((string) $value, $args[0]),
            default => $value,
        };
    }
}
if (!function_exists('wc_get_formatted_variation')) {
    function wc_get_formatted_variation(object $product, bool ...$flags): string { return $product->variant; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
}
require_once dirname(__DIR__) . '/integrations/woocommerce/arasya-operations-connector.php';
require_once dirname(__DIR__) . '/integrations/woocommerce/arasya-operations-wc-kalkulator.php';
