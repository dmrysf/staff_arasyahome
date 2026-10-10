<?php

declare(strict_types=1);

// E2E helper: records one new Trendyol package (ordered now, after the baseline) into the disposable E2E database
// through the real intake store, exactly as a server-side sync run would. No HTTP, no production. Test databases only.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';

$packageId = (int) ($argv[1] ?? 0);
$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if ($packageId < 1 || preg_match('/e2e/', $dbName) !== 1 || preg_match('/test/', $dbName) !== 1) {
    fwrite(STDERR, "Usage: e2e-trendyol-import.php <packageId> with a dedicated e2e test database.\n");
    exit(2);
}
$config = T::config($dbName);
$pdo = Connection::create($config);
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); } };
$baseline = (string) $pdo->query("SELECT baseline_at FROM trendyol_intake_state WHERE state_id = 1 AND status = 'active'")->fetchColumn();
if ($baseline === '') {
    fwrite(STDERR, "The E2E intake is not active.\n");
    exit(1);
}
$baselineMs = intdiv((int) (new DateTimeImmutable($baseline, new DateTimeZone('UTC')))->format('Uu'), 1000);
$nowMs = intdiv((int) $clock->now()->format('Uu'), 1000);
$store = new TrendyolIntakeStore($pdo, (new Container($config, $pdo))->projectionWriter(), $clock);
echo $store->record(TrendyolPackage::fromApi([
    'shipmentPackageId' => $packageId, 'orderNumber' => 'TY' . $packageId, 'orderDate' => $nowMs, 'lastModifiedDate' => $nowMs,
    'shipmentPackageStatus' => 'Created', 'shipmentAddress' => ['fullName' => 'TEST Client Trendyol', 'address1' => 'Str. Test 9', 'city' => 'Iași', 'phone' => '0744111222'],
    'lines' => [['lineId' => $packageId * 10 + 1, 'quantity' => 1, 'productName' => 'Perdea tul alb 300x260', 'stockCode' => 'TY-PT-300', 'productSize' => '300x260', 'productColor' => 'Alb']],
]), $baselineMs), "\n";
