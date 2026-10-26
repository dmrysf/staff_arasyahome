<?php

declare(strict_types=1);

// Finance screen layout E2E fixture (Staff 2.36.1), run by the finance layout spec in the already prepared E2E database.
// Creates one generic TEST finance employee who records for both shops (view + record, like the live finance recorder):
// Trend Home reads the TEST shop (finance record), Outlet Perdele has no source access in the E2E API (finance switched
// off, the live state before the activation). Records nothing: the spec never presses a recording button.
// Prints the username as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
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
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$call = static function (string $method, string $path, array $body) use ($kernel, $origin, $root): void {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-layout-e2e-' . bin2hex(random_bytes(8))], $root['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E finance layout call {$method} {$path} failed: " . json_encode($r['body']));
    }
};
$username = 'fin.layout.e2e';
$existing = $pdo->prepare('SELECT employee_uuid FROM employees WHERE username_normalized = ?');
$existing->execute([$username]);
$id = $existing->fetchColumn();
if (!is_string($id)) {
    $id = $container->employeeAdmin()->create('Finante Layout', $username, null, 'pregatire-material', 'employee', $password, [], 'e2e')->employeeUuid;
}
$call('PUT', "/management/employees/{$id}/applications", ['applications' => ['staff']]);
$call('PUT', "/management/employees/{$id}/finance-scopes", ['view' => ['outletperdele', 'trendhome'], 'record' => ['outletperdele', 'trendhome'], 'approve' => []]);
echo json_encode(['password' => $password, 'finance' => $username], JSON_UNESCAPED_UNICODE), "\n";
