<?php

declare(strict_types=1);

// Race worker of the cancellation suite (test databases only). Waits until a shared start instant, then performs one
// command in its own process and connection and prints a one-line JSON result.
//
//   http     <db> <start> http <method> <path> <json body> <idempotency key> <cookie> <csrf> [origin]
//   ingest   <db> <start> ingest <source> <json payload>
//   trendyol <db> <start> trendyol <json package>

use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';

[, $db, $start, $mode] = $argv;
$origin = $mode === 'http' ? ($argv[10] ?? null) : null;
$config = $origin === null ? T::config($db) : T::config($db, [T::ORIGIN, $origin]);
$container = new \Arasya\Operations\Application\Container($config);
$kernel = $container->kernel();
while (microtime(true) < (float) $start) {
    usleep(300);
}
try {
    if ($mode === 'http') {
        [, , , , $method, $path, $body, $key, $cookie, $csrf] = $argv;
        $r = T::call($kernel, $method, $path, json_decode($body, true, 32, JSON_THROW_ON_ERROR), ['x-csrf-token' => $csrf, 'idempotency-key' => $key] + ($origin === null ? [] : ['origin' => $origin]), $cookie);
        echo json_encode(['status' => $r['status'], 'error' => $r['body']['error']['code'] ?? null], JSON_THROW_ON_ERROR);
    } elseif ($mode === 'ingest') {
        $r = T::ingest($kernel, $argv[4], json_decode($argv[5], true, 32, JSON_THROW_ON_ERROR));
        echo json_encode(['status' => $r['status'], 'error' => $r['body']['error']['code'] ?? null, 'outcome' => $r['body']['outcome'] ?? null], JSON_THROW_ON_ERROR);
    } else {
        $store = new TrendyolIntakeStore($container->pdo(), $container->projectionWriter(), $container->clock());
        $outcome = $store->record(TrendyolPackage::fromApi(json_decode($argv[4], true, 32, JSON_THROW_ON_ERROR)), 0);
        echo json_encode(['status' => 200, 'error' => null, 'outcome' => $outcome], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    echo json_encode(['status' => 0, 'error' => get_class($error)], JSON_THROW_ON_ERROR);
}
