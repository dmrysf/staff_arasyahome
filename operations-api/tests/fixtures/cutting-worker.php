<?php
declare(strict_types=1);
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';
$job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!str_contains(strtolower($job['db']), 'test')) throw new RuntimeException('Disposable test database required.');
$kernel = T::kernel(T::config($job['db']));
while (microtime(true) < $job['start']) usleep(500);
$r = T::call($kernel, $job['method'] ?? 'POST', $job['path'], $job['body'], $job['headers'], $job['cookie'] ?? null);
// Never print pairing credentials, cookies, auth/session objects or audit bodies.
echo json_encode(['status' => $r['status'], 'code' => $r['body']['error']['code'] ?? null,
    'version' => $r['body']['version'] ?? $r['body']['productionVersion'] ?? null], JSON_THROW_ON_ERROR), "\n";
