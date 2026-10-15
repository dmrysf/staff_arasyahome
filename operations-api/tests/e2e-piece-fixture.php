<?php

declare(strict_types=1);

// Creates the synthetic two-curtain order of the per-piece rework E2E spec in the already prepared E2E database (run by
// that spec): the business shape of Trendyol order 11709027615 (one line, 12754-21, Crem, 250 x 260 cm, no source meters,
// quantity 2), cut by the cutter and carried by the tailor to ironing (stage 8), all through the real API commands. Enables
// per-piece reports (the controlled activation an operator performs after deployment). Prints the order id and its QR
// payload as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Production\WorkUnits;
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
$step = static function (string $user, string $id, string $action) use ($kernel, $password, $origin, $pdo): void {
    $session = T::login($kernel, $user, $password);
    $version = (int) $pdo->query('SELECT production_version FROM operational_orders WHERE global_order_id = ' . $pdo->quote($id))->fetchColumn();
    $input = $action === 'claim' ? T::cuttingClaim($pdo, $id, $session['employeeUuid'], $version) : ['expectedVersion' => $version];
    $r = T::call($kernel, 'POST', '/orders/' . rawurlencode($id) . '/' . $action, $input, ['origin' => $origin, 'x-csrf-token' => $session['csrf'], 'idempotency-key' => 'e2e-pc-' . bin2hex(random_bytes(8))], $session['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException('E2E piece step failed: ' . json_encode($r['body']));
    }
};
$number = '76027615';
$source = 'trendhome';
$line = ['id' => 760276151, 'line' => 1, 'name' => 'Draperie de Textura Nisip Turcesc Ivory', 'sku' => '12754-21', 'color' => 'Crem', 'width' => 250, 'height' => 260, 'unit' => 'cm', 'quantity' => 2];
$r = T::ingest($kernel, $source, T::sourceOrder($number, "e2e-pc-{$number}", gmdate('Y-m-d\TH:i:s\Z', time() - 600), T::stage('material-preparation'), 'processing', 'active', [$line]));
if (($r['body']['outcome'] ?? null) !== 'applied') {
    throw new RuntimeException('E2E piece order failed: ' . json_encode($r['body']));
}
$id = "{$source}:{$number}";
$step('sup.cutter.e2e', $id, 'claim');
$step('sup.cutter.e2e', $id, 'transition');
while (true) {
    $step('sup.tailor.e2e', $id, 'claim');
    if ($pdo->query('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ' . $pdo->quote($id))->fetchColumn() === 'ironing') {
        break;
    }
    $step('sup.tailor.e2e', $id, 'transition');
}
$pdo->beginTransaction();
(new WorkUnits($pdo))->setPartialRework(true, 'E2E per-piece rework', gmdate('Y-m-d H:i:s.000000'));
$pdo->commit();
echo json_encode(['order' => $id, 'number' => $number, 'qr' => $r['body']['qr']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
