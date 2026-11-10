<?php
declare(strict_types=1);
// One HTTP call in its own process against a kernel with Finance collections enabled (race tests of API 2.68.0).
use Arasya\Operations\Config\Config;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';
[, $db, $method, $path, $body, $key, $cookie, $csrf, $start] = $argv;
$kernel = T::kernel(new Config(...[...get_object_vars(T::config($db)), 'financeCollections' => 'enabled']));
while (microtime(true) < (float) $start) usleep(500);
$r = T::call($kernel, $method, $path, json_decode($body, true, 32, JSON_THROW_ON_ERROR), ['x-csrf-token' => $csrf, 'idempotency-key' => $key], $cookie);
echo json_encode(['status' => $r['status'], 'error' => $r['body']['error']['code'] ?? null, 'movementId' => $r['body']['movementId'] ?? null], JSON_THROW_ON_ERROR);
