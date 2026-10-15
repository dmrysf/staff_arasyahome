<?php
declare(strict_types=1);
// Frontend-only synthetic scenario. Creates source facts, a test cohort and an actual test location registry entry.
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
$number = '78036' . (string) random_int(1000, 9999);
$lines = [
 ['id' => 780360001, 'line' => 1, 'name' => 'PERDEA TEST', 'sku' => 'P-QA', 'color' => 'Crem', 'width' => 250, 'height' => 260, 'unit' => 'cm', 'meters' => 5, 'quantity' => 1],
 ['id' => 780360002, 'line' => 2, 'name' => 'DRAPERII TEST', 'sku' => 'D-QA', 'color' => 'Alb', 'quantity' => 1],
];
$r = T::ingest($kernel, 'trendhome', T::sourceOrder($number, 'roi36-' . $number, gmdate('Y-m-d\TH:i:s\Z', time() - 600), T::stage('material-preparation'), 'processing', 'active', $lines));
if (($r['body']['outcome'] ?? null) !== 'applied') throw new RuntimeException('Synthetic ingestion failed: '.json_encode($r));
$id = 'trendhome:' . $number;
$uuid = (string) $pdo->query('SELECT order_uuid FROM operational_orders WHERE global_order_id = ' . $pdo->quote($id))->fetchColumn();
$features = new FeatureActivation($pdo);
foreach ([FeatureActivation::PARTIAL_REWORK, FeatureActivation::PIECE_ROUTING] as $feature) $features->setCohortMember($feature, $uuid, true, 'Nova frontend QA', 'Synthetic browser scenario', gmdate('Y-m-d H:i:s.000000'));
$pdo->exec("INSERT IGNORE INTO production_locations (location_id, label, kind, stage_id, status, created_by_operator, created_at) VALUES ('roi36-rail', 'Șina QA înregistrată', 'rail', 'ironing', 'active', 'Nova frontend QA', UTC_TIMESTAMP(6))");
echo json_encode(['order' => $id, 'number' => $number, 'qr' => $r['body']['qr']], JSON_THROW_ON_ERROR), "\n";
