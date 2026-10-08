<?php

declare(strict_types=1);

// Bounded production QR reconciliation of one source's operations orders (QR authority observe/enforce).
// Dry run by default. --apply keeps every existing active QR (recorded as reused) and issues one only for
// an operations order that has none. Source-managed orders are never touched. An order with more than one
// active QR is reported as an anomaly and nothing is repaired automatically (exit code 3).
// Prints one JSON line per order with a non-reversible QR hint, never a QR payload or customer data.

use Arasya\Operations\Http\ApiException;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'orders:', 'limit:', 'apply']);
$source = $options['source'] ?? null;
if (!is_string($source) || preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1) {
    fwrite(STDERR, "Usage: php bin/qr-reconcile.php --source=<source> [--orders=<id,id,...>] [--limit=50] [--apply]\n");
    exit(2);
}
$orders = null;
if (isset($options['orders'])) {
    $orders = array_values(array_filter(array_map('trim', explode(',', (string) $options['orders'])), static fn (string $id): bool => $id !== ''));
    foreach ($orders as $id) {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $id) !== 1) {
            fwrite(STDERR, "Invalid order id.\n");
            exit(2);
        }
    }
}
$limit = isset($options['limit']) ? (int) $options['limit'] : 50;
$apply = array_key_exists('apply', $options);
try {
    $report = $container->productionQrService()->reconcile($source, $orders, $limit, $apply, 'qr-reconcile-' . gmdate('Ymd\THis\Z'));
} catch (ApiException $error) {
    fwrite(STDERR, $error->errorCode . "\n");
    exit(1);
}
$anomalies = 0;
foreach ($report['results'] as $result) {
    $anomalies += str_starts_with($result['result'], 'anomaly') ? 1 : 0;
    fwrite(STDOUT, json_encode(['source' => $report['source'], 'mode' => $report['mode'], 'apply' => $apply] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}
$counts = array_count_values(array_column($report['results'], 'result'));
fwrite(STDOUT, json_encode(['summary' => $counts, 'orders' => count($report['results']), 'apply' => $apply], JSON_THROW_ON_ERROR) . "\n");
exit($anomalies > 0 ? 3 : 0);
