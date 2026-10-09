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
// Every Trendyol URL the code can build is the read-only Order V2 package listing: no status update, shipment,
// tracking number, invoice, cancellation, split or claim endpoint exists anywhere in the Operations API.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/operations-api/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    $source = (string) file_get_contents($file->getPathname());
    $checks++;
    if (preg_match_all('#/integration/[A-Za-z0-9/{}%_.-]+#', $source, $paths) > 0 && array_unique($paths[0]) !== ['/integration/order/sellers/%s/v2/orders']) {
        $fail(substr($file->getPathname(), strlen($root) + 1) . ' builds a Trendyol path other than the read-only Order V2 listing: ' . implode(', ', array_unique($paths[0])));
    }
    $checks++;
    if (preg_match('#shipment-packages|update-tracking|send-invoice|invoice-link|/claims|split-shipment#i', $source) === 1) {
        $fail(substr($file->getPathname(), strlen($root) + 1) . ' references a Trendyol write endpoint.');
    }
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

// ---- Production mutations never write commerce or source state ---------------------------------
// Staff transitions and supervisor owner interventions may change production columns only. The
// commerce status, the source event identity and the projection belong to inbound ingestion.
$commerceColumns = '/\b(source_commerce_status_code|source_commerce_status_label|source_event_id|source_changed_at|last_source_seen_at|projection_hash|operational_status|accepted_at)\s*=|\b(INSERT\s+INTO|UPDATE)\s+(order_sources|order_projection_receipts|operational_order_items)\b/i';
foreach (['operations-api/src/Order/OrderOperationsService.php', 'operations-api/src/Management/OrderOwnershipService.php'] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $checks++;
    if (preg_match_all('/(UPDATE\s+\w+\s+SET.*?WHERE|INSERT\s+INTO\s+\w+)/is', $source, $writes) < 1) {
        $fail("{$relative} was expected to contain production writes.");
    }
    foreach ($writes[0] as $write) {
        $checks++;
        if (preg_match($commerceColumns, $write) === 1) {
            $fail("{$relative} writes commerce or source state: " . preg_replace('/\s+/', ' ', mb_substr($write, 0, 120)));
        }
    }
}

// ---- The patterns themselves still catch writeback (and spare the legitimate inbound event post) --
$selfTest = [
    ['$order->update_status(\'completed\');', $forbiddenConnector, true],
    ['wc_update_order(array(\'status\' => \'shipped\'));', $forbiddenConnector, true],
    ['$order->set_status(\'processing\'); $order->save();', $forbiddenConnector, true],
    ['curl_init(\'https://trendhome.ro/wp-json/wc/v3/orders/1\');', $forbiddenApi, true],
    ['wp_remote_request(\'https://trendhome.ro/wp-json/wc/v3/orders/1\', [\'method\' => \'PUT\']);', $forbiddenApi, true],
    ['$client = new GuzzleHttp\\Client();', $forbiddenApi, true],
    ['$order->get_status(); $order->get_items();', $forbiddenConnector, false],
];
foreach ($selfTest as [$snippet, $patterns, $mustMatch]) {
    $checks++;
    $matched = false;
    foreach (array_keys($patterns) as $pattern) {
        $matched = $matched || preg_match($pattern, $snippet) === 1;
    }
    if ($matched !== $mustMatch) {
        $fail('Guard self-test: pattern set ' . ($mustMatch ? 'missed' : 'falsely flagged') . " {$snippet}");
    }
}
$checks++;
if (preg_match($commerceColumns, "UPDATE operational_orders SET source_commerce_status_code = 'completed' WHERE") !== 1
    || preg_match($commerceColumns, 'UPDATE operational_orders SET production_owner_employee_uuid = :owner WHERE') === 1) {
    $fail('Guard self-test: the commerce column pattern is broken.');
}
foreach (['wp_remote_post( $this->endpoint . \'/integrations/sources/trendhome/orders\'' => false, 'wp_remote_post( \'https://trendhome.ro/wp-json/wc/v3/orders/1\'' => true] as $call => $mustFail) {
    $checks++;
    preg_match('/wp_remote_(\w+)\s*\(([^,]+)/', $call, $parts);
    $flagged = $parts[1] !== 'post' || !str_contains($parts[2], '/integrations/sources/');
    if ($flagged !== $mustFail) {
        $fail("Guard self-test: connector HTTP rule misjudged {$call}");
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL inbound-only source integration guard:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS inbound-only source integration guard ({$checks} checks): no source writeback path exists.\n");
