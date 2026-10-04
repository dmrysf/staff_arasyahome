<?php
/**
 * Plugin Name: Arasya Operations Connector
 * Description: Pushes WooCommerce orders to Arasya Operations as signed, idempotent source events (schema v1). Staff never contacts this site.
 * Version: 2.2.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 *
 * Configuration (wp-config.php, never in the database or Git):
 *   define('ARASYA_OPERATIONS_URL', 'https://api.arasyahome.ro');
 *   define('ARASYA_OPERATIONS_SOURCE', 'trendhome');            // or 'outletperdele'
 *   define('ARASYA_OPERATIONS_SECRET', '<same 64-hex secret as ARASYA_SOURCE_SECRET_* in Operations>');
 *
 * Commerce status is sent as commerce data only. This connector never sends a
 * production stage: Arasya Operations owns production. No customer name, address,
 * phone, e-mail or customer note is sent; production notes come only from the
 * `_arasya_production_notes` order meta (or the `arasya_operations_production_notes` filter).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const ARASYA_OPS_SEND_HOOK = 'arasya_operations_send_order';
const ARASYA_OPS_HEARTBEAT_HOOK = 'arasya_operations_heartbeat';
const ARASYA_OPS_MAX_ATTEMPTS = 12;

/** @return array{url: string, source: string, secret: string}|null */
function arasya_ops_config(): ?array
{
    if (!defined('ARASYA_OPERATIONS_URL') || !defined('ARASYA_OPERATIONS_SOURCE') || !defined('ARASYA_OPERATIONS_SECRET')) {
        return null;
    }
    $url = rtrim((string) ARASYA_OPERATIONS_URL, '/');
    $source = (string) ARASYA_OPERATIONS_SOURCE;
    $secret = (string) ARASYA_OPERATIONS_SECRET;
    if (!str_starts_with($url, 'https://') || preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1 || strlen($secret) < 32) {
        return null;
    }
    return ['url' => $url, 'source' => $source, 'secret' => $secret];
}

/** Microsecond UTC timestamp in the Operations contract format. */
function arasya_ops_now(): string
{
    $now = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)), new DateTimeZone('UTC'));
    return ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

/**
 * Builds the schema v1 payload. Site-specific curtain measurements can be supplied
 * with the `arasya_operations_item_measurements` filter.
 *
 * @param object $order WC_Order-compatible object
 * @return array<string, mixed>
 */
function arasya_ops_build_payload(object $order, string $changedAt): array
{
    $status = (string) $order->get_status();
    $items = [];
    $line = 0;
    foreach ($order->get_items() as $itemId => $item) {
        $line++;
        $product = method_exists($item, 'get_product') ? $item->get_product() : null;
        $measurements = [
            'width' => arasya_ops_number($item->get_meta('_arasya_width', true)),
            'height' => arasya_ops_number($item->get_meta('_arasya_height', true)),
            'unit' => in_array($item->get_meta('_arasya_unit', true), ['mm', 'cm', 'm'], true) ? $item->get_meta('_arasya_unit', true) : null,
            'meters' => arasya_ops_number($item->get_meta('_arasya_meters', true)),
        ];
        if (function_exists('apply_filters')) {
            $measurements = (array) apply_filters('arasya_operations_item_measurements', $measurements, $item, $order);
        }
        $variant = null;
        if ($product !== null && method_exists($product, 'is_type') && $product->is_type('variation') && function_exists('wc_get_formatted_variation')) {
            $variant = wp_strip_all_tags((string) wc_get_formatted_variation($product, true, false, false)) ?: null;
        }
        $sku = $product !== null && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
        $items[] = array_filter([
            'id' => (int) $itemId,
            'line' => $line,
            'name' => mb_substr((string) $item->get_name(), 0, 255),
            'sku' => $sku === '' ? null : mb_substr($sku, 0, 120),
            'variant' => $variant === null ? null : mb_substr($variant, 0, 160),
            'width' => $measurements['width'] ?? null,
            'height' => $measurements['height'] ?? null,
            'unit' => $measurements['unit'] ?? null,
            'meters' => $measurements['meters'] ?? null,
            'quantity' => max(1, (int) $item->get_quantity()),
        ], static fn (mixed $value): bool => $value !== null);
    }
    $created = $order->get_date_created();
    // Only explicit production instructions are forwarded; customer notes may contain personal data.
    $notes = trim((string) $order->get_meta('_arasya_production_notes', true));
    if (function_exists('apply_filters')) {
        $notes = trim((string) apply_filters('arasya_operations_production_notes', $notes, $order));
    }
    return [
        'schemaVersion' => 1,
        'eventId' => 'wc-' . $order->get_id() . '-' . preg_replace('/\D/', '', $changedAt),
        'changedAt' => $changedAt,
        'order' => array_filter([
            'id' => (int) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => ['code' => $status, 'label' => function_exists('wc_get_order_status_name') ? (string) wc_get_order_status_name($status) : $status],
            'availability' => in_array($status, ['cancelled', 'refunded', 'failed', 'trash'], true) ? 'cancelled' : 'active',
            'notes' => $notes === '' ? null : mb_substr($notes, 0, 4000),
            'acceptedAt' => $created === null ? null : $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'items' => $items,
        ], static fn (mixed $value): bool => $value !== null),
    ];
}

function arasya_ops_number(mixed $value): ?float
{
    if ($value === '' || $value === null || !is_numeric(str_replace(',', '.', (string) $value))) {
        return null;
    }
    $number = round((float) str_replace(',', '.', (string) $value), 3);
    return $number >= 0 ? $number : null;
}

/** @return array{timestamp: string, signature: string} */
function arasya_ops_sign(string $secret, string $body, ?int $timestamp = null): array
{
    $timestamp = (string) ($timestamp ?? time());
    return ['timestamp' => $timestamp, 'signature' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret)];
}

/** @return int|null HTTP status, or null for a transport failure */
function arasya_ops_post(string $route, array $payload): ?int
{
    $config = arasya_ops_config();
    if ($config === null) {
        return null;
    }
    $body = (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signed = arasya_ops_sign($config['secret'], $body);
    $response = wp_remote_post("{$config['url']}/integrations/sources/{$config['source']}/{$route}", [
        'timeout' => 15,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Arasya-Timestamp' => $signed['timestamp'],
            'X-Arasya-Signature' => $signed['signature'],
        ],
        'body' => $body,
    ]);
    return is_wp_error($response) ? null : (int) wp_remote_retrieve_response_code($response);
}

function arasya_ops_should_send(object $order): bool
{
    $statuses = function_exists('apply_filters')
        ? (array) apply_filters('arasya_operations_send_statuses', ['processing', 'on-hold', 'completed', 'cancelled', 'refunded'])
        : ['processing', 'on-hold', 'completed', 'cancelled', 'refunded'];
    return in_array($order->get_status(), $statuses, true);
}

function arasya_ops_enqueue(int $orderId, int $attempt = 0, int $delay = 0): void
{
    if (function_exists('as_schedule_single_action')) {
        $args = ['order_id' => $orderId, 'attempt' => $attempt];
        // Several WooCommerce save hooks fire per change; one pending send already carries the newest state.
        if ($attempt === 0 && function_exists('as_has_scheduled_action') && as_has_scheduled_action(ARASYA_OPS_SEND_HOOK, $args, 'arasya-operations')) {
            return;
        }
        as_schedule_single_action(time() + $delay, ARASYA_OPS_SEND_HOOK, ['order_id' => $orderId, 'attempt' => $attempt], 'arasya-operations');
        return;
    }
    wp_schedule_single_event(time() + $delay, ARASYA_OPS_SEND_HOOK, [$orderId, $attempt]);
}

function arasya_ops_send_order(int $orderId, int $attempt = 0): void
{
    $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
    if (!$order || !arasya_ops_should_send($order)) {
        return;
    }
    // The payload is built at send time so a retry always carries the newest order state.
    $status = arasya_ops_post('orders', arasya_ops_build_payload($order, arasya_ops_now()));
    if ($status !== null && $status >= 200 && $status < 300) {
        return;
    }
    $permanent = $status !== null && $status >= 400 && $status < 500 && !in_array($status, [401, 408, 409, 429], true);
    if ($permanent || $attempt + 1 >= ARASYA_OPS_MAX_ATTEMPTS) {
        error_log(sprintf('[arasya-operations] order %d not delivered (status %s, attempt %d)', $orderId, $status === null ? 'transport' : (string) $status, $attempt + 1));
        return;
    }
    arasya_ops_enqueue($orderId, $attempt + 1, min(3600, 30 * (2 ** $attempt)));
}

function arasya_ops_send_heartbeat(): void
{
    arasya_ops_post('heartbeat', ['sentAt' => arasya_ops_now()]);
}

if (function_exists('add_action')) {
    $queue = static function (int $orderId): void {
        if (arasya_ops_config() !== null) {
            arasya_ops_enqueue($orderId);
        }
    };
    add_action('woocommerce_new_order', $queue, 20, 1);
    add_action('woocommerce_update_order', $queue, 20, 1);
    add_action('woocommerce_order_status_changed', $queue, 20, 1);
    add_action(ARASYA_OPS_SEND_HOOK, static function (mixed $orderId, mixed $attempt = 0): void {
        arasya_ops_send_order((int) $orderId, (int) $attempt);
    }, 10, 2);
    add_filter('cron_schedules', static function (array $schedules): array {
        $schedules['arasya_five_minutes'] = ['interval' => 300, 'display' => 'Arasya Operations heartbeat'];
        return $schedules;
    });
    add_action(ARASYA_OPS_HEARTBEAT_HOOK, 'arasya_ops_send_heartbeat');
    add_action('init', static function (): void {
        if (arasya_ops_config() !== null && !wp_next_scheduled(ARASYA_OPS_HEARTBEAT_HOOK)) {
            wp_schedule_event(time() + 60, 'arasya_five_minutes', ARASYA_OPS_HEARTBEAT_HOOK);
        }
    });
}
