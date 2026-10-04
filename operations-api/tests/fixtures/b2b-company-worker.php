<?php

declare(strict_types=1);

// Race worker for mysql-b2b-companies-integration.php: sends one B2B company mutation at an agreed start
// instant and prints the HTTP outcome as JSON.

use Arasya\Operations\Tests\OperationsTestSupport;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';

[, $dbName, $origin, $method, $path, $body, $idempotencyKey, $cookie, $csrf, $startAt] = $argv;
$kernel = OperationsTestSupport::kernel(OperationsTestSupport::config($dbName, [OperationsTestSupport::ORIGIN, $origin]));
while (microtime(true) < (float) $startAt) {
    usleep(500);
}
$response = OperationsTestSupport::call(
    $kernel,
    $method,
    $path,
    json_decode($body, true, 16, JSON_THROW_ON_ERROR),
    ['origin' => $origin, 'x-csrf-token' => $csrf, 'idempotency-key' => $idempotencyKey],
    $cookie,
);
echo json_encode([
    'status' => $response['status'],
    'code' => $response['body']['error']['code'] ?? null,
    'companyId' => $response['body']['companyId'] ?? null,
    'contactId' => $response['body']['contactId'] ?? null,
    'companyCode' => $response['body']['company']['code'] ?? null,
], JSON_THROW_ON_ERROR), "\n";
