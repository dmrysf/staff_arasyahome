<?php

declare(strict_types=1);

// TEST-ONLY shop for the real-API E2E run (php -S router, loopback only, never deployed): the shop side of source access
// protocol v1 (SourceAccessFakeShop) over HTTP, answering from the fixture file the Confirmare E2E fixture writes
// (invented TEST customers). Nonces are kept in a file next to it, so a replayed request is refused across requests;
// Phase 4B edits change the fixture orders (TEST amounts) and keep the stored answers there.
//   ARASYA_E2E_SHOP_FILE  the fixture file {"orders": {...}}
//   ARASYA_E2E_SHOP_KEY   the access key (kid th-e2e) the test API signs with

use Arasya\Operations\Tests\SourceAccessFakeShop;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/SourceAccessFakeShop.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($path === '/health') {
    echo '{"ok":true}';
    return true;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !str_starts_with($path, '/wp-json/')) {
    http_response_code(404);
    echo '{"error":{"code":"not_found"}}';
    return true;
}
$file = (string) getenv('ARASYA_E2E_SHOP_FILE');
$key = (string) getenv('ARASYA_E2E_SHOP_KEY');
$lock = fopen($file . '.lock', 'c');
flock($lock, LOCK_EX);
try {
    $fixture = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $nonceFile = $file . '.nonces';
    $shop = new SourceAccessFakeShop('trendhome', 'th-e2e', $key, is_array($fixture['orders'] ?? null) ? $fixture['orders'] : []);
    $shop->nonces = is_file($nonceFile) ? (json_decode((string) file_get_contents($nonceFile), true) ?: []) : [];
    // Phase 4B: the shop's applied edits and stored answers persist between requests, like a real shop.
    $shop->edits = is_array($fixture['edits'] ?? null) ? $fixture['edits'] : [];
    $shop->products = is_array($fixture['products'] ?? null) ? $fixture['products'] : [];
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
        }
    }
    $answer = $shop->handle(substr($path, strlen('/wp-json')), $headers, (string) file_get_contents('php://input'));
    file_put_contents($nonceFile, json_encode($shop->nonces));
    if (is_array($fixture) && ($shop->orders !== ($fixture['orders'] ?? []) || $shop->edits !== ($fixture['edits'] ?? []))) {
        file_put_contents($file, json_encode(['orders' => $shop->orders, 'edits' => $shop->edits] + $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
} finally {
    flock($lock, LOCK_UN);
}
http_response_code($answer['status']);
foreach ($answer['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
echo $answer['body'];
return true;
