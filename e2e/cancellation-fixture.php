<?php
declare(strict_types=1);
// Frontend-only synthetic scenario for the cancellation control (API 2.35.0). `create` ingests one order at ironing;
// `cancel <number>` delivers the source cancellation of that order through the signed ingestion. Dedicated E2E test
// database only.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__) . '/operations-api/bootstrap.php';
require dirname(__DIR__) . '/operations-api/tests/OperationsTestSupport.php';
$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) throw new RuntimeException('Dedicated E2E test database required.');
$config = T::config($dbName, ['http://127.0.0.1:4174', 'http://127.0.0.1:4175', T::ORIGIN]);
$pdo = Connection::create($config);
$kernel = (new Container($config, $pdo))->kernel();
$lines = [['id' => 780390001, 'line' => 1, 'name' => 'DRAPERIE OPRITĂ', 'sku' => 'D-OP', 'color' => 'Gri', 'width' => 250, 'height' => 260, 'unit' => 'cm', 'meters' => 5, 'quantity' => 1]];
$mode = $argv[1] ?? 'create';
if ($mode === 'create') {
    $number = '78039' . (string) random_int(1000, 9999);
    $r = T::ingest($kernel, 'trendhome', T::sourceOrder($number, 'cancel-' . $number . '-1', gmdate('Y-m-d\TH:i:s\Z', time() - 600), T::stage('ironing'), 'processing', 'active', $lines, $number));
} else {
    $number = (string) ($argv[2] ?? '');
    $r = T::ingest($kernel, 'trendhome', T::sourceOrder($number, 'cancel-' . $number . '-2', gmdate('Y-m-d\TH:i:s\Z', time() - 60), null, 'cancelled', 'cancelled', $lines, $number));
}
if (($r['body']['outcome'] ?? null) !== 'applied') throw new RuntimeException('Synthetic ingestion failed: ' . json_encode($r));
echo json_encode(['order' => 'trendhome:' . $number, 'number' => $number], JSON_THROW_ON_ERROR), "\n";
