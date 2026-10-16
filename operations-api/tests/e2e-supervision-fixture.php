<?php

declare(strict_types=1);

// Creates the synthetic orders of the tailoring supervision E2E spec in the already prepared E2E database
// (run by that spec, after the earlier suites): cut by one cutter and carried through tailoring by one tailor
// to stage 7 (side-hem), stage 5 (material-straightening), stage 3 and stage 8 (ironing, the EX-000001 shape of the
// universal scanner spec), all through the real API commands.
// Prints the order ids and their QR payloads as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) {
    fwrite(STDERR, "ARASYA_E2E_DB_NAME must be a dedicated database whose name contains both 'e2e' and 'test'.\n");
    exit(2);
}
$origin = (string) (getenv('ARASYA_E2E_ORIGIN') ?: 'http://127.0.0.1:4174');
$config = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])));
$pdo = Connection::create($config);
$kernel = (new Container($config, $pdo))->kernel();
$password = 'e2e passphrase 2026';
$changedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
$step = static function (string $user, string $id, string $action) use ($kernel, $password, $origin, $pdo): void {
    $session = T::login($kernel, $user, $password);
    $version = (int) $pdo->query('SELECT production_version FROM operational_orders WHERE global_order_id = ' . $pdo->quote($id))->fetchColumn();
    $input = $action === 'claim' ? T::cuttingClaim($pdo, $id, $session['employeeUuid'], $version) : ['expectedVersion' => $version];
    $r = T::call($kernel, 'POST', '/orders/' . rawurlencode($id) . '/' . $action, $input, ['origin' => $origin, 'x-csrf-token' => $session['csrf'], 'idempotency-key' => 'e2e-sup-' . bin2hex(random_bytes(8))], $session['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException('E2E supervision step failed: ' . json_encode($r['body']));
    }
};
$out = ['orders' => [], 'qr' => []];
foreach (['stage7' => ['75001', 'trendhome', 'side-hem'], 'stage5' => ['75002', 'outletperdele', 'material-straightening'], 'stage3' => ['75003', 'trendhome', 'workshop-receiving'], 'stage8' => ['75004', 'trendhome', 'ironing']] as $label => [$number, $source, $target]) {
    $lines = [];
    foreach ([[1, 'Voal Alb', 5], [2, 'Draperie Gri', 7]] as [$n, $name, $meters]) {
        $lines[] = ['id' => (int) $number * 10 + $n, 'line' => $n, 'name' => $name, 'sku' => "SV-{$n}", 'color' => 'Gri', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => $meters, 'quantity' => 1];
    }
    $r = T::ingest($kernel, $source, T::sourceOrder($number, "e2e-sup-{$number}", $changedAt, T::stage('material-preparation'), 'processing', 'active', $lines));
    if (($r['body']['outcome'] ?? null) !== 'applied') {
        throw new RuntimeException('E2E supervision order failed: ' . json_encode($r['body']));
    }
    $id = "{$source}:{$number}";
    $out['orders'][$label] = $id;
    $out['qr'][$number] = $r['body']['qr'];
    $step('sup.cutter.e2e', $id, 'claim');
    $step('sup.cutter.e2e', $id, 'transition');
    while (true) {
        $step('sup.tailor.e2e', $id, 'claim');
        if ($pdo->query('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ' . $pdo->quote($id))->fetchColumn() === $target) {
            break;
        }
        $step('sup.tailor.e2e', $id, 'transition');
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
