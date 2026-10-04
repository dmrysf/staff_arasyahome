<?php

declare(strict_types=1);

// Source integrations are inbound-only. This guard fails the build if Operations gains any way to
// write back to a commerce source (HTTP clients, sockets, non-GET Trendyol calls) or if the
// WooCommerce connector gains any order mutation. A deliberate outbound integration must be a
// separately reviewed milestone that updates this guard explicitly.

$root = dirname(__DIR__, 2);
$failures = [];
$checks = 0;
$fail = static function (string $message) use (&$failures): void { $failures[] = $message; };

/** @return list<string> */
$phpFiles = static function (string $directory): array {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
};

// ---- Operations API: no outbound HTTP except the read-only Trendyol GET transport ---------------
$transport = $root . '/operations-api/src/Integration/Trendyol/StreamTrendyolTransport.php';
$forbiddenApi = [
    '/\bcurl_(init|exec|multi_\w+|setopt)\s*\(/' => 'cURL client',
    '/\b(fsockopen|pfsockopen|stream_socket_client)\s*\(/' => 'raw socket client',
    '/\bwp_remote_\w+\s*\(/' => 'WordPress HTTP client',
    '/\bwc_update_order\b|->update_status\s*\(|->set_status\s*\(/' => 'WooCommerce status mutation',
    '/GuzzleHttp|Symfony\\\\Component\\\\HttpClient/' => 'HTTP client library',
];
foreach ($phpFiles($root . '/operations-api/src') as $file) {
    $source = (string) file_get_contents($file);
    $relative = substr($file, strlen($root) + 1);
    foreach ($forbiddenApi as $pattern => $label) {
        $checks++;
        if (preg_match($pattern, $source) === 1) {
            $fail("{$relative} contains a {$label}.");
        }
    }
    $checks++;
    if ($file !== $transport && preg_match('/\bstream_context_create\s*\(|file_get_contents\s*\(\s*["\']https?:/', $source) === 1) {
        $fail("{$relative} opens an outbound HTTP stream; only StreamTrendyolTransport may, and only with GET.");
    }
}
$transportSource = (string) file_get_contents($transport);
$checks++;
if (preg_match_all("/'method'\s*=>\s*'([A-Z]+)'/", $transportSource, $methods) < 1 || array_unique($methods[1]) !== ['GET']) {
    $fail('StreamTrendyolTransport must only issue GET requests.');
}
$interface = (string) file_get_contents($root . '/operations-api/src/Integration/Trendyol/TrendyolTransport.php');
$checks++;
if (preg_match_all('/public function (\w+)\s*\(/', $interface, $functions) !== 1 || $functions[1] !== ['get']) {
    $fail('TrendyolTransport must expose only get().');
}

// ---- WooCommerce connector: reads the order, sends signed events, never mutates the order ------
$forbiddenConnector = [
    '/->set_status\s*\(|->update_status\s*\(|\bwc_update_order\b|\bwc_create_order\b/' => 'order status mutation',
    '/->save\s*\(|\bwp_update_post\b|\bwp_insert_post\b|\bupdate_post_meta\b|->update_meta_data\s*\(|->add_order_note\s*\(/' => 'order write',
    '/\bregister_rest_route\b|\bwp_ajax_/' => 'inbound endpoint Operations could call back',
    '/->get_(billing|shipping)_\w+\s*\(|->get_customer_note\s*\(/' => 'customer personal data read',
];
foreach ($phpFiles($root . '/integrations/woocommerce') as $file) {
    $source = (string) file_get_contents($file);
    $relative = substr($file, strlen($root) + 1);
    foreach ($forbiddenConnector as $pattern => $label) {
        $checks++;
        if (preg_match($pattern, $source) === 1) {
            $fail("{$relative} contains an {$label}.");
        }
    }
    $checks++;
    preg_match_all('/wp_remote_(\w+)\s*\(([^,]+)/', $source, $calls, PREG_SET_ORDER);
    foreach ($calls as [, $verb, $target]) {
        if (!in_array($verb, ['post', 'retrieve_response_code'], true) || ($verb === 'post' && !str_contains($target, '/integrations/sources/'))) {
            $fail("{$relative} makes an unexpected HTTP call wp_remote_{$verb}({$target}).");
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL inbound-only source integration guard:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS inbound-only source integration guard ({$checks} checks): no source writeback path exists.\n");
