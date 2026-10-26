<?php

declare(strict_types=1);

// Rollback preflight with SmartBill invoicing (API 2.45.0, migrations 041 and 042) on a disposable database: which older
// release can still serve the data, and why. bin/check-rollback.php is read-only and runs in the CURRENT release. Covers:
// the new tables applied but empty, an advance proforma, the customer's acceptance of the total, an accepted smaller
// advance, an invoice action pending or unknown, a final invoice issued (finished history), an order routed to a shop
// employee, a finance verification pending, a partial deployment (a migration recorded without its table, a changed
// migration file) and the API deployed before its migrations ran. Every row is TEST ONLY and removed at the end.

use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL SmartBill rollback integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$config = T::config($dbName);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };

$cliEnvironment = ['HOME' => (string) getenv('HOME'),
    'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
    'ARASYA_SOURCE_SECRET_TRENDHOME' => T::TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active',
];
$rollback = static function (string $target) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/check-rollback.php', $target], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
};
$floor = static function () use ($rollback): ?string { $r = $rollback('0.0.0')['json']; return is_array($r) && array_key_exists('requiredFloor', $r) ? $r['requiredFloor'] : 'unverifiable'; };

// The suites before this one leave no Confirmare, finance or invoice state; other state may set a lower floor.
$base = $floor();
$check($base !== '2.46.0' && $base !== '2.45.0' && $base !== '2.42.0' && $base !== 'unverifiable', 'the shared test database starts without Confirmare, finance or invoice state: ' . var_export($base, true));
$check((string) $one("SELECT migration_name FROM schema_migrations WHERE migration_name = '041_smartbill_invoicing.sql'") !== '' && (string) $one("SELECT migration_name FROM schema_migrations WHERE migration_name = '042_confirmare_connector_readiness.sql'") !== '', 'migrations 041 and 042 are applied');

// ---- 1. Applied, empty: an older release ignores the new tables ------------------------------------------------------------
$empty = $rollback('2.34.2');
$check(($empty['json']['state']['unsettledInvoiceActions'] ?? null) === 0 && ($empty['json']['state']['ordersRoutedForInvoice'] ?? null) === 0 && ($empty['json']['state']['ordersWithOpenProformaWork'] ?? null) === 0
    && $empty['json']['requiredFloor'] === $base, 'empty 041/042 tables add no floor: ' . $empty['out']);

// TEST ONLY fixtures: one Trend Home order (signed ingestion) and one employee.
$suffix = substr(bin2hex(random_bytes(5)), 0, 8);
$container = new \Arasya\Operations\Application\Container($config, $pdo);
$employee = $container->employeeAdmin()->create('Test Rollback', "rb.{$suffix}", null, 'pregatire-material', 'employee', 'rollback test passphrase 2026', [], 'test-rb')->employeeUuid;
$router = $container->employeeAdmin()->create('Test Rollback Operator', "rb.op.{$suffix}", null, 'pregatire-material', 'employee', 'rollback test passphrase 2026', [], 'test-rb')->employeeUuid;
$numberBase = T::freeOrderBase($pdo, 970000, 979999, 10);
$ingested = T::ingest($container->kernel(), 'trendhome', T::sourceOrder((string) ($numberBase + 1), "rb-{$suffix}", gmdate('Y-m-d\TH:i:s') . '.000001Z'));
$check($ingested['status'] === 200 || $ingested['status'] === 201, 'a Trend Home order: ' . json_encode($ingested['body']));
$global = 'trendhome:' . ($numberBase + 1);
$orderUuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$global]);
$rev = 'sha256:' . str_repeat('a', 64);
$action = static function (string $kind, string $state, ?string $result = null, string $role = 'operator') use ($pdo, $orderUuid, $global, $employee, $rev): string {
    $uuid = Uuid::v4();
    $done = in_array($state, ['applied', 'rejected'], true);
    $pdo->prepare('INSERT INTO order_invoice_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, action, reason_code, idempotency_key, request_hmac, revision_before, state, outcome, result, request_id, requested_at, completed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?)')
        ->execute([$uuid, $orderUuid, $global, 'trendhome', $employee, $role, $kind, $kind === 'proforma_cancel' ? 'order_changed' : null, 'rb-' . bin2hex(random_bytes(8)), random_bytes(32), $rev, $state,
            $done ? 'applied' : null, $result, 'rb-test', $done ? gmdate('Y-m-d H:i:s.u') : null]);
    return $uuid;
};
$clear = static function () use ($pdo, $employee): void {
    $pdo->prepare('DELETE FROM order_invoice_actions WHERE employee_uuid = ?')->execute([$employee]);
    $pdo->prepare('DELETE FROM order_shop_assignments WHERE employee_uuid = ?')->execute([$employee]);
    $pdo->prepare('DELETE FROM order_finance_actions WHERE employee_uuid = ?')->execute([$employee]);
};
$expect = static function (string $label, string $wanted, string $refusedTarget, string $state) use ($rollback, $check): void {
    $r = $rollback($refusedTarget);
    $check($r['code'] === 3 && $r['json']['safe'] === false && $r['json']['requiredFloor'] === $wanted && ($r['json']['state'][$state] ?? 0) > 0, "{$label}: {$refusedTarget} refused, floor {$wanted}: " . $r['out']);
    $check($rollback($wanted)['code'] === 0, "{$label}: {$wanted} itself is a safe target");
};

// ---- 2. Unfinished SmartBill work needs 2.45.0 ------------------------------------------------------------------------------
$action('proforma_issue', 'applied', 'issued');
$expect('an advance proforma on an order not invoiced yet', '2.45.0', '2.44.0', 'ordersWithOpenProformaWork');
$check($rollback('2.42.0')['code'] === 3, 'the release before invoicing (2.42.0) is refused too');
$clear();
$action('total_accept', 'applied', 'accepted');
$expect('the customer\'s telephone acceptance of a new total', '2.45.0', '2.44.0', 'ordersWithOpenProformaWork');
$clear();
$action('advance_accept', 'applied', 'accepted');
$expect('an accepted smaller advance (partial advance)', '2.45.0', '2.44.0', 'ordersWithOpenProformaWork');
$clear();
$action('proforma_cancel', 'applied', 'cancelled');
$expect('a cancelled proforma, order not invoiced', '2.45.0', '2.44.0', 'ordersWithOpenProformaWork');
$clear();
$action('issue', 'pending', null, 'documents');
$expect('a final invoice action pending', '2.45.0', '2.44.0', 'unsettledInvoiceActions');
$clear();
$action('proforma_issue', 'unknown', null);
$expect('a proforma with an unknown SmartBill outcome', '2.45.0', '2.44.0', 'unsettledInvoiceActions');
$clear();
$pdo->prepare("INSERT INTO order_shop_assignments (assignment_uuid, order_uuid, global_order_id, source_key, employee_uuid, assigned_by_employee_uuid, assigned_role, idempotency_key, request_hmac, state, assigned_at)
    VALUES (?, ?, ?, 'trendhome', ?, ?, 'operator', ?, ?, 'active', UTC_TIMESTAMP(6))")->execute([Uuid::v4(), $orderUuid, $global, $employee, $router, 'rb-' . bin2hex(random_bytes(8)), random_bytes(32)]);
$expect('an order routed to a shop employee and not invoiced', '2.45.0', '2.44.0', 'ordersRoutedForInvoice');

// ---- 3. Finished history does not: the final invoice was issued; nothing is deleted ------------------------------------------
$action('proforma_issue', 'applied', 'issued');
$action('advance_accept', 'applied', 'accepted');
$action('issue', 'applied', 'issued', 'documents');
$after = $rollback('2.34.2');
$check($after['json']['requiredFloor'] === $base && $after['json']['state']['ordersWithOpenProformaWork'] === 0 && $after['json']['state']['ordersRoutedForInvoice'] === 0,
    'invoiced: the proforma, the accepted advance and the routing are history; no 2.45.0 floor: ' . $after['out']);
$check((int) $one('SELECT COUNT(*) FROM order_invoice_actions WHERE employee_uuid = ?', [$employee]) === 3 && (int) $one('SELECT COUNT(*) FROM order_shop_assignments WHERE employee_uuid = ?', [$employee]) === 1,
    'the preflight is read-only: every row stays');
$action('send_mail', 'unknown', null, 'documents');
$expect('even after the invoice, an e-mail with an unknown outcome', '2.45.0', '2.44.0', 'unsettledInvoiceActions');
$clear();

// ---- 4. A finance verification pending needs 2.42.0 (the production gate reads it) ---------------------------------------
$pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, decision, purpose, idempotency_key, request_hmac, revision_before, state, request_id, requested_at)
    VALUES (?, ?, ?, 'trendhome', ?, 'decide_transfer', 'verify', NULL, ?, ?, ?, 'pending', 'rb-test', UTC_TIMESTAMP(6))")->execute([Uuid::v4(), $orderUuid, $global, $employee, 'rb-' . bin2hex(random_bytes(8)), random_bytes(32), $rev]);
$finance = $rollback('2.41.0');
$check($finance['code'] === 3 && in_array($finance['json']['requiredFloor'], ['2.42.0'], true) && $finance['json']['state']['unsettledFinanceActions'] === 1, 'a pending finance verification: 2.41.0 refused, floor 2.42.0: ' . $finance['out']);
$clear();
$check($floor() === $base, 'back to the starting floor once the rows are gone');

// ---- 4b. API 2.46.0 (migration 043): a running proforma timer or an open e-Factura task needs 2.46.0 ------------------------
$cycleUuid = Uuid::v4();
$pdo->prepare("INSERT INTO confirmare_proforma_cycles (cycle_uuid, order_uuid, active_order_uuid, global_order_id, source_key, cycle_number, origin, owner_employee_uuid, state, sent_at, expires_at, created_at, updated_at)
    VALUES (?, ?, ?, ?, 'trendhome', 1, 'operator', ?, 'running', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 120 HOUR), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$cycleUuid, $orderUuid, $orderUuid, $global, $employee]);
$expect('a running advance proforma timer', '2.46.0', '2.45.0', 'openDualStoreWork');
$pdo->prepare("UPDATE confirmare_proforma_cycles SET state = 'cancelled', active_order_uuid = NULL, closed_reason = 'proforma_expired', closed_at = UTC_TIMESTAMP(6) WHERE cycle_uuid = ?")->execute([$cycleUuid]);
$pdo->prepare("INSERT INTO invoice_efactura_tasks (task_uuid, order_uuid, global_order_id, source_key, invoice_action_uuid, issuer_employee_uuid, issued_at, due_at, state, created_at)
    VALUES (?, ?, ?, 'trendhome', ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 7 DAY), 'open', UTC_TIMESTAMP(6))")->execute([Uuid::v4(), $orderUuid, $global, Uuid::v4(), $employee]);
$expect('an e-Factura follow-up still open', '2.46.0', '2.45.0', 'openDualStoreWork');
$pdo->exec("UPDATE invoice_efactura_tasks SET state = 'accepted', recorded_at = UTC_TIMESTAMP(6), recorded_by_employee_uuid = issuer_employee_uuid, outcome_code = 'checked_accepted'");
$check($floor() === $base, 'finished 043 history (a cancelled timer, an accepted e-Factura) adds no floor; the rows stay');
$pdo->exec('DELETE FROM invoice_efactura_tasks');
$pdo->exec('DELETE FROM confirmare_proforma_cycles');

// ---- 5. Partial deployment: unverifiable states are refused (exit 1), never guessed ---------------------------------------
$recorded041 = (string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '041_smartbill_invoicing.sql'");
$pdo->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '041_smartbill_invoicing.sql'")->execute([str_repeat('0', 64)]);
$changed = $rollback('2.34.2');
$check($changed['code'] === 1 && $changed['json'] === null && str_contains($changed['err'], 'could not be verified'), 'a recorded 041 that is not the known file: refused (exit 1)');
$pdo->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '041_smartbill_invoicing.sql'")->execute([$recorded041]);
$pdo->exec('RENAME TABLE source_connector_readiness TO source_connector_readiness_rb'); // TEST ONLY: 042 recorded, table gone
$missing = $rollback('2.34.2');
$pdo->exec('RENAME TABLE source_connector_readiness_rb TO source_connector_readiness');
$check($missing['code'] === 1 && str_contains($missing['err'], 'could not be verified'), 'a recorded 042 without its table: refused (exit 1)');
$row042 = $pdo->query("SELECT checksum, applied_at FROM schema_migrations WHERE migration_name = '042_confirmare_connector_readiness.sql'")->fetch(PDO::FETCH_ASSOC);
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '042_confirmare_connector_readiness.sql'"); // TEST ONLY: API 2.45.0 deployed, 042 not run yet
$early = $rollback('2.34.2');
$pdo->prepare("INSERT INTO schema_migrations (migration_name, checksum, applied_at) VALUES ('042_confirmare_connector_readiness.sql', ?, ?)")->execute([$row042['checksum'], $row042['applied_at']]);
$check(in_array($early['code'], [0, 3], true) && $early['json']['requiredFloor'] === $base, 'the API deployed before 042 ran: the preflight still answers from the data: ' . $early['out']);
$check($floor() === $base, 'restored');

// ---- 6. No customer data or amount in the answer ---------------------------------------------------------------------------
$check(preg_match('/\d+\.\d{2}(?![.\d])|@|lei|RON|' . preg_quote($global, '/') . '/i', $after['out']) !== 1, 'the preflight prints counts and versions only (no amount, order or customer): ' . $after['out']);

fwrite(STDOUT, "PASS MySQL SmartBill rollback integration: {$checks} checks (applied and empty tables, advance proforma, telephone acceptance, accepted smaller advance, cancelled proforma, invoice actions pending and unknown, routing, finished history kept, finance verification pending, changed or missing migrations refused, API before its migrations, read-only, no customer data).\n");
