<?php

declare(strict_types=1);

// Dual-store operations E2E fixture (API 2.46.0), run by the operations spec after the invoice spec, in the already
// prepared E2E database. Makes sure migration 043 is in this TEST database (it is idempotent), creates one generic TEST
// invoice manager of Trend Home (the AWB of the shop's orders, the health counts), moves the invoiced TEST order 91020 to
// stage 12 (quality control) and gives it a TEST shop operations state (a card order paid in full: nothing to collect).
// The TEST shop (tests/e2e-source-shop.php) simulates FAN Courier: no AWB is ever made anywhere.
// Prints usernames and the order as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) {
    fwrite(STDERR, "ARASYA_E2E_DB_NAME must be a dedicated database whose name contains both 'e2e' and 'test'.\n");
    exit(2);
}
$origin = (string) (getenv('ARASYA_E2E_ORIGIN') ?: 'http://127.0.0.1:4174');
$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on'];
$config = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])), ['trendhome' => $full + ['paymentGate' => 'enforce'], 'outletperdele' => $full + ['paymentGate' => 'off']]);
$pdo = Connection::create($config);
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/043_confirmare_dual_store.sql'); // idempotent
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$call = static function (array $who, string $method, string $path, ?array $body = null) use ($kernel, $origin): array {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'ops-e2e-' . bin2hex(random_bytes(8))], $who['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E operations call {$method} {$path} failed: " . json_encode($r['body']));
    }
    return $r['body'];
};
// A generic TEST invoice manager of Trend Home: makes the shop's AWBs and watches its health; never a Confirmare operator.
$manager = $container->employeeAdmin()->create('Manager Magazin', 'ops.mgr.e2e', null, 'pregatire-material', 'employee', $password, [], 'e2e');
$call($root, 'PUT', "/management/employees/{$manager->employeeUuid}/applications", ['applications' => ['staff']]);
$call($root, 'PUT', "/management/employees/{$manager->employeeUuid}/invoice-scopes", ['issue' => [], 'manage' => ['trendhome']]);

$global = 'trendhome:91020';
$issued = (int) $pdo->query("SELECT COUNT(*) FROM order_invoice_actions i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = '{$global}' AND i.action IN ('issue', 'reissue') AND i.state = 'applied' AND i.result = 'issued'")->fetchColumn();
$tasks = (int) $pdo->query("SELECT COUNT(*) FROM invoice_efactura_tasks WHERE global_order_id = '{$global}'")->fetchColumn();
if ($issued !== 1 || $tasks !== 1) {
    throw new RuntimeException("E2E operations: the invoice spec must have issued the final invoice of {$global} (issued {$issued}, e-Factura tasks {$tasks}).");
}
// TEST ONLY: the factory moved the order to stage 12 (the factory's own stages are tested elsewhere).
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'quality-control', production_changed_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?")->execute([$global]);
$file = (string) getenv('ARASYA_E2E_SHOP_FILE');
$shop = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
$shop['orders']['91020']['ops'] = ['status' => 'processing', 'owed' => '0.00', 'invoice' => true];
file_put_contents($file, json_encode($shop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['password' => $password, 'manager' => 'ops.mgr.e2e', 'issuer' => 'inv.docs.e2e', 'order' => $global, 'number' => '91020'], JSON_UNESCAPED_UNICODE), "\n";
