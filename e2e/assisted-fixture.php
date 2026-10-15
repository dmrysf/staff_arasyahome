<?php
declare(strict_types=1);
// Frontend-only synthetic scenario for assisted PERDEA checks (API 2.34.0): one PERDEA line and one DRAPERII line in the
// test cohorts of piece_routing and perdea_assisted_checkpoints. Dedicated E2E test database only.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Production\FeatureActivation;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__) . '/operations-api/bootstrap.php';
require dirname(__DIR__) . '/operations-api/tests/OperationsTestSupport.php';
$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) throw new RuntimeException('Dedicated E2E test database required.');
$config = T::config($dbName, ['http://127.0.0.1:4174', 'http://127.0.0.1:4175', T::ORIGIN]);
$pdo = Connection::create($config);
$kernel = (new Container($config, $pdo))->kernel();
$number = '78037' . (string) random_int(1000, 9999);
$lines = [
 ['id' => 780370001, 'line' => 1, 'name' => 'PERDEA ASISTATĂ', 'sku' => 'P-AS', 'color' => 'Crem', 'width' => 250, 'height' => 260, 'unit' => 'cm', 'meters' => 4, 'quantity' => 1],
 ['id' => 780370002, 'line' => 2, 'name' => 'DRAPERII ASISTATĂ', 'sku' => 'D-AS', 'color' => 'Alb', 'quantity' => 1],
];
$r = T::ingest($kernel, 'trendhome', T::sourceOrder($number, 'assist-' . $number, gmdate('Y-m-d\TH:i:s\Z', time() - 600), T::stage('material-preparation'), 'processing', 'active', $lines));
if (($r['body']['outcome'] ?? null) !== 'applied') throw new RuntimeException('Synthetic ingestion failed: '.json_encode($r));
$id = 'trendhome:' . $number;
$uuid = (string) $pdo->query('SELECT order_uuid FROM operational_orders WHERE global_order_id = ' . $pdo->quote($id))->fetchColumn();
$features = new FeatureActivation($pdo);
foreach ([FeatureActivation::PIECE_ROUTING, FeatureActivation::PERDEA_ASSISTED_CHECKPOINTS] as $feature) $features->setCohortMember($feature, $uuid, true, 'Nova frontend QA', 'Synthetic browser scenario', gmdate('Y-m-d H:i:s.000000'));
echo json_encode(['order' => $id, 'number' => $number, 'qr' => $r['body']['qr']], JSON_THROW_ON_ERROR), "\n";
