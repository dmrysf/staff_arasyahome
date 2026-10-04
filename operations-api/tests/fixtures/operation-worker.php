<?php

declare(strict_types=1);

// Race worker for mysql-operations-integration.php: performs one claim or
// transition at an agreed start instant and prints the HTTP outcome as JSON.

use Arasya\Operations\Tests\OperationsTestSupport;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';

[, $dbName, $operation, $orderId, $expectedVersion, $idempotencyKey, $cookie, $csrf, $startAt] = $argv;
$kernel = OperationsTestSupport::kernel(OperationsTestSupport::config($dbName));
while (microtime(true) < (float) $startAt) {
    usleep(500);
}
$response = OperationsTestSupport::call(
    $kernel,
    'POST',
    '/orders/' . rawurlencode($orderId) . '/' . $operation,
    ['expectedVersion' => (int) $expectedVersion],
    ['x-csrf-token' => $csrf, 'idempotency-key' => $idempotencyKey],
    $cookie,
);
echo json_encode([
    'status' => $response['status'],
    'code' => $response['body']['error']['code'] ?? null,
    'productionVersion' => $response['body']['productionVersion'] ?? null,
    'stage' => $response['body']['productionStageId'] ?? null,
], JSON_THROW_ON_ERROR), "\n";
