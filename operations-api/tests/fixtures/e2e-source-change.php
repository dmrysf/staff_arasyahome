<?php

declare(strict_types=1);

// E2E helper: applies one signed Trendhome source change (new line meters) to an order of the
// disposable E2E database, exactly as the source integration would. Test databases only.

use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';

[, $number, $meters, $minute] = $argv;
$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/e2e/', $dbName) !== 1 || preg_match('/test/', $dbName) !== 1) {
    fwrite(STDERR, "A dedicated e2e test database is required.\n");
    exit(2);
}
$kernel = T::kernel(T::config($dbName));
$response = T::ingest($kernel, 'trendhome', T::e2eDocumentOrder($number, (float) $meters, (int) $minute));
if (($response['body']['outcome'] ?? null) !== 'applied') {
    fwrite(STDERR, 'Source change failed: ' . json_encode($response['body']) . "\n");
    exit(1);
}
echo "applied\n";
