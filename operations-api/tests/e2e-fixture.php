<?php

declare(strict_types=1);

// Builds a deterministic, disposable database for the real-API Chromium E2E suite and
// prints the fixture (credentials, order numbers, QR payloads) as JSON. It refuses any
// database whose name does not contain "e2e" and "test"; it never touches production.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) {
    fwrite(STDERR, "ARASYA_E2E_DB_NAME must be a dedicated database whose name contains both 'e2e' and 'test'.\n");
    exit(2);
}
$host = (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('ARASYA_TEST_DB_PORT') ?: 3306);
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$origin = (string) (getenv('ARASYA_E2E_ORIGIN') ?: 'http://127.0.0.1:4174');
$config = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])));
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$admin = $container->employeeAdmin();
$password = 'e2e passphrase 2026';
$admin->create('Ana Popescu', 'ana.e2e', 'E2E-ANA', 'pregatire-material', 'employee', $password, ['material-preparation'], 'e2e');
$admin->create('Bogdan Ionescu', 'bogdan.e2e', 'E2E-BOB', 'pregatire-material', 'employee', $password, ['material-preparation'], 'e2e');
$admin->create('Mihai Stan', 'mihai.e2e', 'E2E-MIH', 'pregatire-material', 'employee', $password, ['workshop-receiving'], 'e2e');
// Central IAM: a Dashboard-only identity and an identity that still has a temporary password.
$dora = $admin->create('Dora Manager', 'dora.e2e', 'E2E-DOR', 'pregatire-material', 'employee', $password, [], 'e2e');
$pdo->prepare("DELETE FROM employee_application_access WHERE employee_uuid = :id")->execute(['id' => $dora->employeeUuid]);
$pdo->prepare("INSERT INTO employee_application_access (employee_uuid, application_key, granted_at) VALUES (:id, 'dashboard', UTC_TIMESTAMP(6))")->execute(['id' => $dora->employeeUuid]);
$temporary = $admin->create('Teodor Nou', 'teodor.e2e', 'E2E-TEO', 'pregatire-material', 'employee', $password, ['material-preparation'], 'e2e');
$pdo->prepare('UPDATE employees SET must_change_password = 1 WHERE employee_uuid = :id')->execute(['id' => $temporary->employeeUuid]);

$qr = [];
$changedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
foreach (['70001' => 'trendhome', '70002' => 'trendhome', '70003' => 'outletperdele', '70004' => 'outletperdele'] as $number => $source) {
    $response = T::ingest($kernel, $source, T::sourceOrder((string) $number, "e2e-{$number}-1", $changedAt, T::stage('material-preparation'), 'processing', 'active', [[
        'id' => (int) $number * 10, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => "DV-{$number}", 'color' => 'Bej',
        'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1,
    ]]));
    if (($response['body']['outcome'] ?? null) !== 'applied') {
        throw new RuntimeException('E2E source ingestion failed: ' . json_encode($response['body']));
    }
    $qr[$number] = $response['body']['qr'];
}
// Another employee already works on 70003 so Ana sees a blocked action.
$bobSession = T::login($kernel, 'bogdan.e2e', $password);
$claim = T::call($kernel, 'POST', '/orders/' . rawurlencode('outletperdele:70003') . '/claim', ['expectedVersion' => 1], ['origin' => $origin, 'x-csrf-token' => $bobSession['csrf'], 'idempotency-key' => 'e2e-fixture-claim-70003'], $bobSession['cookie']);
if ($claim['status'] !== 200) {
    throw new RuntimeException('E2E fixture claim failed: ' . json_encode($claim['body']));
}

echo json_encode([
    'password' => $password,
    'users' => ['ana' => 'ana.e2e', 'bogdan' => 'bogdan.e2e', 'mihai' => 'mihai.e2e', 'dashboardOnly' => 'dora.e2e', 'temporary' => 'teodor.e2e'],
    'orders' => ['flow' => '70001', 'qr' => '70002', 'claimedByOther' => '70003', 'conflict' => '70004'],
    'qr' => $qr,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
