<?php

declare(strict_types=1);

// Finance Smart Search V1 E2E fixture (Staff 2.62.0, API 2.85.0), run by the finance search spec in the already prepared E2E
// database. One generic TEST finance employee (view + record on both shops) and SYNTHETIC orders with SYNTHETIC customer
// names, proformas and advances (the owner's scenarios A-K: same family name, amount only, rounding, a sum of transfers,
// diacritics, a typing error, seven equal advances, the advance left, a payment already recorded, a bank description and an
// order without a proforma). The orders are copies of an E2E order with new ids; nothing is sent to any shop. Prints JSON.
// Refuses any database that is not an E2E test database. Running it again replaces its own rows only.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Support\Uuid;
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
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-search-e2e-' . bin2hex(random_bytes(8))], $root['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E finance search call {$method} {$path} failed: " . json_encode($r['body']));
    }
};
$username = 'fin.search.e2e';
$existing = $pdo->prepare('SELECT employee_uuid FROM employees WHERE username_normalized = ?');
$existing->execute([$username]);
$employee = $existing->fetchColumn();
if (!is_string($employee)) {
    $employee = $container->employeeAdmin()->create('Finante Cautare', $username, null, 'pregatire-material', 'employee', $password, [], 'e2e')->employeeUuid;
}
$call('PUT', "/management/employees/{$employee}/applications", ['applications' => ['staff']]);
$call('PUT', "/management/employees/{$employee}/finance-scopes", ['view' => ['outletperdele', 'trendhome'], 'record' => ['outletperdele', 'trendhome'], 'approve' => []]);

// Replace this fixture's own rows (a second run, after the spec opened a task), never anything else: every row of its orders
// and of their tasks, in this disposable E2E database only (checked above).
$ownOrders = $pdo->query("SELECT order_uuid FROM operational_orders WHERE global_order_id LIKE '%:fss-e2e-%'")->fetchAll(PDO::FETCH_COLUMN);
if ($ownOrders !== []) {
    $in = implode(', ', array_map(static fn (string $id): string => $pdo->quote($id), $ownOrders));
    $ownRequests = $pdo->query("SELECT request_uuid FROM finance_verification_requests WHERE order_uuid IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
    $references = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND ((REFERENCED_TABLE_NAME = 'operational_orders' AND REFERENCED_COLUMN_NAME = 'order_uuid') OR (REFERENCED_TABLE_NAME = 'finance_verification_requests' AND REFERENCED_COLUMN_NAME = 'request_uuid'))")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($references as $reference) {
        $ids = $reference['REFERENCED_TABLE_NAME'] === 'operational_orders' ? $in : implode(', ', array_map(static fn (string $id): string => $pdo->quote($id), $ownRequests ?: ['-']));
        $pdo->exec("DELETE FROM `{$reference['TABLE_NAME']}` WHERE `{$reference['COLUMN_NAME']}` IN ({$ids})");
    }
    $pdo->exec("DELETE FROM operational_orders WHERE order_uuid IN ({$in})");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$template = $pdo->query("SELECT * FROM operational_orders WHERE source_key = 'trendhome' AND global_order_id NOT LIKE '%:fss-e2e-%' ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($template)) {
    throw new RuntimeException('The E2E database holds no Trend Home order to copy.');
}
$columns = array_keys($template);
$insert = $pdo->prepare('INSERT INTO operational_orders (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
$task = $pdo->prepare("INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
        requested_by_employee_uuid, requested_at, request_idempotency_key, ledger, proforma_series, proforma_number, policy, outcome, decided_by_employee_uuid, decided_at, updated_at)
    VALUES (?, ?, ?, ?, 'advance', ?, 1, ?, ?, ?, ?, ?, ?, 'shop', 'ARS', ?, 'single', ?, ?, ?, ?)");
$amounts = $pdo->prepare("INSERT INTO finance_task_amounts (request_uuid, currency, required_amount, remaining_amount, verified_amount, percentage, read_at, updated_at, order_total, received_amount, cod_remaining)
    VALUES (?, 'RON', ?, ?, ?, 30, ?, ?, ?, ?, ?)");
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
// [number, shop, name, company, advance, advance left, verified, total, task: open|verified|none, hours waiting]
$rows = [
    ['88001', 'trendhome', 'Ali Demir', null, '600.00', '600.00', '0.00', '2000.00', 'open', 30],
    ['88002', 'trendhome', 'Maria Demir', null, '612.00', '612.00', '0.00', '2040.00', 'open', 29],
    ['88003', 'outletperdele', 'Andrei Pavel', null, '610.00', '610.00', '0.00', '2033.00', 'open', 28],
    ['88004', 'trendhome', 'Elena Marin', null, '174.28', '174.28', '0.00', '580.95', 'open', 27],
    ['88005', 'trendhome', 'Victor Lungu', null, '198.99', '198.99', '0.00', '663.30', 'open', 26],
    ['88006', 'outletperdele', 'Mariana Tăbăraș', null, '350.00', '350.00', '0.00', '1166.67', 'open', 25],
    ['88007', 'trendhome', 'Maria Popescu', null, '420.00', '420.00', '0.00', '1400.00', 'open', 24],
    ['88008', 'trendhome', 'Ioana Dobre', null, '54.88', '54.88', '0.00', '182.93', 'open', 23],
    ['88009', 'trendhome', 'Mihai Stan', null, '54.88', '54.88', '0.00', '182.93', 'open', 22],
    ['88010', 'outletperdele', 'Ana Toma', null, '54.88', '54.88', '0.00', '182.93', 'open', 21],
    ['88011', 'trendhome', 'Dan Vlad', null, '54.88', '54.88', '0.00', '182.93', 'open', 20],
    ['88012', 'trendhome', 'Ion Rusu', null, '54.88', '54.88', '0.00', '182.93', 'open', 19],
    ['88013', 'outletperdele', 'Gabriela Ene', null, '54.88', '54.88', '0.00', '182.93', 'open', 18],
    ['88014', 'trendhome', 'Florin Sima', null, '54.88', '54.88', '0.00', '182.93', 'open', 17],
    ['88015', 'trendhome', 'Radu Stan', null, '200.00', '50.00', '150.00', '666.67', 'open', 16],
    ['88016', 'trendhome', 'Alexandra-Gabriela Constantinescu-Popovici', null, '1250.50', '1250.50', '0.00', '4168.33', 'open', 15],
    ['88017', 'outletperdele', 'Ion Pop', 'SC Textile Design SRL', '730.00', '730.00', '0.00', '2433.33', 'open', 14],
    ['88018', 'trendhome', 'Sorin Popescu', null, '150.00', '0.00', '150.00', '500.00', 'verified', 40],
    ['88019', 'trendhome', 'Cosmin Vlad', null, null, null, null, null, 'none', 0],
];
$orders = [];
foreach ($rows as $index => [$number, $source, $name, $company, $advance, $left, $verified, $total, $state, $hours]) {
    $orderUuid = Uuid::v4();
    $global = "{$source}:fss-e2e-{$number}";
    $at = $now->modify("-{$hours} hours")->modify('-' . $index . ' minutes');
    $row = $template;
    $row['order_uuid'] = $orderUuid;
    $row['source_key'] = $source;
    $row['global_order_id'] = $global;
    $row['source_order_id'] = "fss-e2e-{$number}";
    $row['order_number'] = $number;
    $row['source_event_id'] = "fss-e2e-event-{$number}";
    $row['projection_hash'] = hash('sha256', $global, true);
    $row['workflow_cohort'] = 'confirmare';
    $row['document_context'] = json_encode(['name' => $name, 'company' => $company, 'addressLines' => ['Strada Test ' . ($index + 1)], 'phoneMasked' => null], JSON_UNESCAPED_UNICODE);
    foreach (['created_at', 'source_changed_at', 'last_source_seen_at', 'projected_at', 'updated_at', 'accepted_at', 'source_submitted_at'] as $column) {
        if (array_key_exists($column, $row)) {
            $row[$column] = $at->modify('-2 days')->format('Y-m-d H:i:s');
        }
    }
    foreach (['open_exception_uuid', 'active_document_revision_uuid'] as $column) {
        if (array_key_exists($column, $row)) {
            $row[$column] = null;
        }
    }
    $insert->execute(array_values($row));
    $orders[$number] = $global;
    if ($state === 'none') {
        continue;
    }
    $request = Uuid::v4();
    $atSql = $at->format('Y-m-d H:i:s.u');
    $open = $state === 'open';
    $task->execute([$request, $orderUuid, $global, $source, $open ? 'assigned' : 'verified', $open ? $orderUuid : null, $employee, $atSql, $employee, $atSql, "fss-e2e-{$number}",
        '0' . (930 + $index + 1), $open ? null : 'verified_full', $open ? null : $employee, $open ? null : $atSql, $atSql]);
    $amounts->execute([$request, $advance, $left, $verified, $atSql, $atSql, $total, $verified, number_format((float) $total - (float) $verified, 2, '.', '')]);
}
echo json_encode(['password' => $password, 'finance' => $username, 'orders' => $orders], JSON_UNESCAPED_UNICODE), "\n";
