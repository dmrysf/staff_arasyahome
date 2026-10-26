<?php

declare(strict_types=1);

// Per-source Confirmare readiness (API 2.45.0, ConfirmareReadiness) on a disposable database, through the HTTP kernel and
// the operator CLI: the release-wide WORKFLOW_READY switch is gone, and ONE source is activated only when every required
// check passes, on the server: schema, permission and authorities, ingestion, operators, source access, finance with
// four eyes, the production document, invoicing, and the shop's signed readiness report (heartbeat connectorReadiness:
// YD SOFT 2.19.0 or later, fresh, its own modes, SmartBill and the accountant's decision for `enforce`). Trend Home is
// activated while Outlet Perdele stays off; Outlet Perdele's new orders stay in the established flow.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmareReadiness;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare readiness integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($now < WorkflowCohort::cutover()->modify('+1 hour')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare readiness integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-readiness-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return is_array($r['body']) ? $r['body'] : []; };

const RD_KEY = 'trendhome-access-key-test-0123456789abcdef-rdy';
$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
// Outlet Perdele: the same shop setup, but Confirmare is not permitted (ARASYA_SOURCE_CONFIRMARE_OUTLETPERDELE off).
$outlet = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $outlet]);
$access = [
    'trendhome' => ['mode' => 'edit', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . RD_KEY, 'finance' => 'record', 'invoice' => 'enforce'],
];
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => $access]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
foreach (['confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations', 'source_connector_readiness', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes', 'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes',
    'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations',
    'workflow_cohort_activations', 'workflow_cohort_arming', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM employee_document_scopes');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$registry = SourceRegistry::fromConfig($config);
$readinessOf = static fn (Config $c): ConfirmareReadiness => new ConfirmareReadiness($pdo, SourceAccessRegistry::fromConfig($c), $container->employeeRepository(), new AuthorizationService());
$readiness = $readinessOf($config);
$failed = static fn (string $source, ?ConfirmareReadiness $r = null) => ($r ?? $readiness)->evaluate($registry->find($source), new DateTimeImmutable('now', new DateTimeZone('UTC')))['failed'];
$detail = static function (string $name, string $source = 'trendhome') use ($readiness, $registry): mixed {
    foreach ($readiness->evaluate($registry->find($source), new DateTimeImmutable('now', new DateTimeZone('UTC')))['checks'] as $c) {
        if ($c['check'] === $name) return $c;
    }
    return null;
};
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "rdy.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-rdy-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Readiness root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Readiness root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'rdy-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$uuids = [];
$identity = static function (string $name, array $roles = []) use ($container, $asRoot, $ok, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', 'readiness test passphrase 2026', [], 'test-rdy');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
};
$docs = $roleIds['production-documents-operator'];
foreach (['rop' => [], 'rfin1' => [], 'rfin2' => [], 'rdocnoperm' => [], 'rdoc' => [$docs], 'rinv' => [$docs]] as $name => $roles) $identity($name, $roles);
$heartbeat = static fn (string $source, array $extra = []): array => T::ingest($kernel, $source, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')] + $extra, $source === 'trendhome' ? null : T::OUTLET_SECRET, null, 'heartbeat');
$report = static fn (array $over = []): array => ['connectorReadiness' => $over + ['version' => '2.19.0', 'access' => 'edit', 'finance' => 'record', 'invoice' => 'enforce', 'schemaReady' => true, 'invoiceAttested' => true, 'smartbillReady' => true]];

// ---- 1. Nothing set up: refused, with every failed check named; the TEST override never reaches the CLI ---------------
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'without a readiness service every activation is refused (fail closed)');
$f = $failed('trendhome');
$check($f === ['operators', 'finance_four_eyes', 'documents', 'invoicing', 'connector'], 'fresh: schema, permission, ingestion, source access and finance mode pass; the people and the shop report fail: ' . json_encode($f));
$activation = static fn (DateTimeImmutable $clock): WorkflowCohortActivation => new WorkflowCohortActivation($pdo, new MutableClock($clock), $readiness);
// The activation instant lies in the recent past (clocks of the activation steps are set before it), so orders submitted
// after it arrive now, as in production.
$activatedAt = $now->modify('-20 minutes');
try {
    $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find('trendhome'), $activatedAt, 'Test Owner', 'readiness', 'rdy-test');
    $check(false, 'a schedule is refused while the readiness fails');
} catch (ApiException $e) {
    $check($e->errorCode === 'CONFIRMARE_NOT_READY' && $e->details['failed'] === $f && (int) $one('SELECT COUNT(*) FROM workflow_cohort_activations') === 0, 'CONFIRMARE_NOT_READY names the failed checks; nothing recorded');
}

// ---- 2. People: operators, finance with four eyes, the production document, invoicing --------------------------------
$ok($asRoot('PUT', "/management/employees/{$uuids['rop']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'operator scope');
$check(!in_array('operators', $failed('trendhome'), true), 'an operator with the operate scope: operators pass');
$ok($asRoot('PUT', "/management/employees/{$uuids['rfin1']}/finance-scopes", ['view' => [], 'record' => ['trendhome'], 'approve' => ['trendhome']]), 'finance one person');
$check(in_array('finance_four_eyes', $failed('trendhome'), true), 'one person who records AND approves is not four eyes');
$ok($asRoot('PUT', "/management/employees/{$uuids['rfin2']}/finance-scopes", ['view' => [], 'record' => [], 'approve' => ['trendhome']]), 'finance second person');
$check(!in_array('finance_four_eyes', $failed('trendhome'), true), 'a second person who approves: four eyes pass');
$ok($asRoot('PUT', "/management/employees/{$uuids['rdocnoperm']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 'document scope without permission');
$check(in_array('documents', $failed('trendhome'), true), 'a document scope without the production document permission does not generate revision 1');
$ok($asRoot('PUT', "/management/employees/{$uuids['rdoc']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 'document scope with permission');
$check(!in_array('documents', $failed('trendhome'), true), 'someone who can generate revision 1: documents pass');
$ok($asRoot('PUT', "/management/employees/{$uuids['rinv']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 'invoice issuer documents');
$ok($asRoot('PUT', "/management/employees/{$uuids['rinv']}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]), 'invoice issue scope');
$check($failed('trendhome') === ['connector'], 'only the shop report is missing now: ' . json_encode($failed('trendhome')));
$check(($detail('connector')['detail']['reason'] ?? null) === 'no_report', 'no report yet');

// ---- 3. The shop's signed readiness report: strict, every mismatch named -------------------------------------------------
$hb = $heartbeat('trendhome');
$check($hb['status'] === 200 && ($hb['body']['connectorReadiness'] ?? null) === 1, 'the heartbeat announces that it accepts connectorReadiness');
foreach ([['version' => '2.19'], ['access' => 'write'], ['finance' => 'approve'], ['invoice' => 'on'], ['schemaReady' => 'yes'], ['smartbillReady' => 1]] as $bad) {
    $r = $heartbeat('trendhome', $report($bad));
    $check($r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'SOURCE_PAYLOAD_INVALID', 'a malformed report is refused: ' . json_encode($bad));
}
$extra = $report();
$extra['connectorReadiness']['token'] = 'x';
$check($heartbeat('trendhome', $extra)['status'] === 422, 'an unknown key in the report is refused');
$check($heartbeat('trendhome', ['connectorReadiness' => 'ready'])['status'] === 422 && $heartbeat('trendhome', ['readiness' => true])['status'] === 422, 'a report that is not an object, or an unknown top-level key, is refused');
$check((int) $one('SELECT COUNT(*) FROM source_connector_readiness') === 0, 'nothing recorded from a refused heartbeat');
$cases = [
    [['version' => '2.18.0'], 'connector_too_old'],
    [['version' => '2.9.1'], 'connector_too_old'],
    [['schemaReady' => false], 'shop_schema_not_ready'],
    [['access' => 'read'], 'shop_access_mode'],
    [['finance' => 'read'], 'shop_finance_mode'],
    [['invoice' => 'observe'], 'shop_invoice_mode'],
    [['smartbillReady' => false], 'shop_smartbill_not_ready'],
    [['invoiceAttested' => false], 'shop_accountant_decision_missing'],
];
foreach ($cases as [$over, $problem]) {
    $ok($heartbeat('trendhome', $report($over)), 'report ' . $problem);
    $c = $detail('connector');
    $check($c['ok'] === false && in_array($problem, $c['detail']['problems'], true), "the shop report fails with {$problem}: " . json_encode($c['detail']));
}
$ok($heartbeat('trendhome', $report(['version' => '2.20.3'])), 'report newer connector');
$check($failed('trendhome') === [], 'a newer connector with every check: ready');
$ok($heartbeat('trendhome', $report()), 'good report');
$check($failed('trendhome') === [] && $readiness->evaluate($registry->find('trendhome'), $now)['ready'] === true, 'every required check passes: ready');
$check(($detail('controlled_edits')['detail']['possible'] ?? null) === true && $detail('staff_release')['ok'] === null, 'reported only: controlled edits and the Staff release');
$auto = $detail('advance_proforma_automatic');
$check($auto['ok'] === null && is_bool($auto['detail']['automatic'] ?? null) && (($auto['detail']['automatic'] === true) === (array_key_exists('reason', $auto['detail']) && $auto['detail']['reason'] === null)),
    'reported only: whether the third unanswered call sends the advance proforma by itself, and why not: ' . json_encode($auto['detail'] ?? null));
$check($readiness->evaluate($registry->find('trendhome'), $now->modify('+16 minutes'))['failed'] === ['connector'], 'a report older than 15 minutes is stale');
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '042_confirmare_connector_readiness.sql'"); // TEST ONLY: as before 042 ran
$check($failed('trendhome') === ['schema', 'connector'], 'without migration 042 recorded: schema and connector fail');
$pdo->prepare('INSERT INTO schema_migrations (migration_name, checksum, applied_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
    ->execute(['042_confirmare_connector_readiness.sql', hash('sha256', (string) file_get_contents(dirname(__DIR__) . '/database/migrations/042_confirmare_connector_readiness.sql'))]);
$check($failed('trendhome') === [], 'restored');

// ---- 4. A source that does not invoice through Arasya needs no invoice issuer and no SmartBill --------------------------
$noInvoice = new Config(...[...get_object_vars($base), 'sourceAccess' => ['trendhome' => ['invoice' => 'off'] + $access['trendhome']]]);
$ok($heartbeat('trendhome', $report(['invoice' => 'off', 'smartbillReady' => false, 'invoiceAttested' => false])), 'report invoice off');
$check($failed('trendhome', $readinessOf($noInvoice)) === [], 'invoice off on both sides: invoicing and SmartBill are not required');
$offAuto = array_values(array_filter($readinessOf($noInvoice)->evaluate($registry->find('trendhome'), new DateTimeImmutable('now', new DateTimeZone('UTC')))['checks'], static fn (array $c): bool => $c['check'] === 'advance_proforma_automatic'))[0] ?? [];
$check(($offAuto['detail']['automatic'] ?? null) === false && ($offAuto['detail']['reason'] ?? null) === 'invoice_not_enforced',
    'invoice off: the report says plainly that no advance proforma is sent automatically (invoice_not_enforced)');
$check(str_contains((string) ($offAuto['detail']['effect'] ?? ''), 'no advance proforma is issued or e-mailed') && ($offAuto['detail']['enforceOnlyAfter'] ?? null) === ['smartbill_real_document_test', 'proforma_bank_settings', 'accountant_approval', 'efactura_spv_check'],
    'invoice off: the effect on new Confirmare orders and the manual checks before invoice enforce are written out');
$check(in_array('connector', $failed('trendhome'), true), 'the same report fails while Arasya invoices (enforce)');
$noAccess = new Config(...[...get_object_vars($base), 'sourceAccess' => []]);
$check(array_intersect(['source_access', 'finance'], $failed('trendhome', $readinessOf($noAccess))) === ['source_access', 'finance'], 'without source access: the operator cannot open the shop order, finance cannot verify');
$ok($heartbeat('trendhome', $report()), 'good report again');

// ---- 5. Trend Home is activated (no TEST override); Outlet Perdele stays off --------------------------------------------
$opFailed = $failed('outletperdele');
$check(in_array('permitted', $opFailed, true) && in_array('operators', $opFailed, true) && in_array('source_access', $opFailed, true), 'Outlet Perdele is not ready: not permitted, no operator, no source access: ' . json_encode($opFailed));
try {
    $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find('outletperdele'), $activatedAt, 'Test Owner', 'readiness', 'rdy-test');
    $check(false, 'Outlet Perdele cannot be scheduled');
} catch (ApiException $e) {
    $check($e->errorCode === 'CONFIRMARE_NOT_READY' && in_array('permitted', $e->details['failed'], true), 'Outlet Perdele: CONFIRMARE_NOT_READY (permitted)');
}
$scheduled = $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find('trendhome'), $activatedAt, 'Test Owner', 'readiness', 'rdy-test');
$check($scheduled['action'] === 'scheduled', 'Trend Home is scheduled through the production path');
$armed = $heartbeat('trendhome', ['confirmareArmedAt' => $activatedAt->format('Y-m-d\TH:i:s.u\Z')] + $report());
$check($armed['status'] === 200 && (int) $one("SELECT COUNT(*) FROM workflow_cohort_arming WHERE source_key = 'trendhome'") === 1, 'the arming report and the readiness report arrive in one heartbeat');
$committed = $activation($activatedAt->modify('-2 minutes'))->activate($registry->find('trendhome'), 'Test Owner', 'readiness', 'rdy-test');
$check($committed['action'] === 'activated' && WorkflowCohort::heartbeatState($pdo, 'outletperdele', $activatedAt->modify('+1 minute'))['state'] !== 'active', 'Trend Home is active; Outlet Perdele is not');
// After the activation instant: a new Trend Home order is Confirmare, a new Outlet Perdele order is not.
$numberBase = T::freeOrderBase($pdo, 990000, 999999, 10);
$order = static function (string $source, int $n) use ($kernel, $activatedAt, $numberBase, $suffix): array {
    $p = T::sourceOrder((string) ($numberBase + $n), "rdy-{$source}-{$n}-{$suffix}", $activatedAt->modify('+2 minutes')->format('Y-m-d\TH:i:s.u\Z'));
    $p['order']['submission'] = ['submittedAt' => $activatedAt->modify('+1 minute')->format('Y-m-d\TH:i:s.u\Z'), 'evidence' => 'checkout'];
    return T::ingest($kernel, $source, $p, $source === 'trendhome' ? null : T::OUTLET_SECRET);
};
$order('trendhome', 1);
$order('outletperdele', 2);
$cohortOf = static fn (string $global): ?string => ($v = $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$global])) === false || $v === null ? null : (string) $v;
$thCohort = $cohortOf('trendhome:' . ($numberBase + 1));
$opCohort = $cohortOf('outletperdele:' . ($numberBase + 2));
$check($thCohort === 'confirmare', 'a new Trend Home order is a Confirmare order: ' . var_export($thCohort, true));
$check($opCohort !== 'confirmare' && $opCohort !== 'undetermined', 'a new Outlet Perdele order stays in the established flow: ' . var_export($opCohort, true));

// ---- 6. The operator CLI evaluates the same readiness, read-only ---------------------------------------------------------
$cliEnvironment = ['HOME' => (string) getenv('HOME'),
    'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
    'ARASYA_SOURCE_SECRET_TRENDHOME' => T::TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active', 'ARASYA_SOURCE_AUTHORITY_TRENDHOME' => 'enforce',
    'ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_DOCUMENT_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_CONFIRMARE_TRENDHOME' => 'on',
    'ARASYA_SOURCE_ACCESS_TRENDHOME' => 'edit', 'ARASYA_SOURCE_ACCESS_URL_TRENDHOME' => 'https://trendhome.example', 'ARASYA_SOURCE_ACCESS_KEY_TRENDHOME' => 'th-test:' . RD_KEY,
    'ARASYA_SOURCE_FINANCE_TRENDHOME' => 'record', 'ARASYA_SOURCE_INVOICE_TRENDHOME' => 'enforce',
    'ARASYA_SOURCE_SECRET_OUTLETPERDELE' => T::OUTLET_SECRET, 'ARASYA_SOURCE_MODE_OUTLETPERDELE' => 'active',
];
$cli = static function (string ...$arguments) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/workflow-cohort.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
};
$rows = static fn (): string => json_encode([$one('SELECT COUNT(*) FROM workflow_cohort_activations'), $one('SELECT COUNT(*) FROM source_connector_readiness'), $one('SELECT MAX(reported_at) FROM source_connector_readiness')]);
$before = $rows();
$thCli = $cli('--source=trendhome', '--readiness');
$opCli = $cli('--source=outletperdele', '--readiness');
$check($thCli['code'] === 0 && ($thCli['json']['ready'] ?? null) === true, 'CLI --readiness: Trend Home ready (exit 0): ' . $thCli['out'] . $thCli['err']);
$check($opCli['code'] === 1 && ($opCli['json']['ready'] ?? null) === false && in_array('permitted', $opCli['json']['failed'] ?? [], true), 'CLI --readiness: Outlet Perdele not ready (exit 1)');
$check($rows() === $before && !str_contains($thCli['out'] . $opCli['out'], RD_KEY) && !str_contains($thCli['out'], 'Test r'), 'the readiness command writes nothing and prints no key or name');
$status = $cli('--source=trendhome', '--status');
$check($status['code'] === 0 && ($status['json']['readiness']['ready'] ?? null) === true && ($status['json']['state'] ?? null) === 'active', 'the status shows the readiness');
$opSchedule = $cli('--source=outletperdele', '--schedule', '--at=2099-01-01T00:00:00Z', '--actor=Test Owner');
$check($opSchedule['code'] === 3 && str_contains($opSchedule['err'], 'CONFIRMARE_NOT_READY') && str_contains($opSchedule['err'], 'permitted'), 'the CLI refuses Outlet Perdele and names the failed checks: ' . $opSchedule['err']);

// Leave the shared disposable database as the next suites expect it: no Confirmare activation, readiness or scope state.
// Every Confirmare, finance and invoice record too: a later suite deletes orders, and their events would block it.
foreach (['confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations', 'source_connector_readiness', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes', 'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes',
    'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations',
    'workflow_cohort_activations', 'workflow_cohort_arming', 'employee_confirmare_scopes', 'confirmare_operator_state', 'employee_document_scopes'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');
@unlink($logFile);

fwrite(STDOUT, "PASS MySQL Confirmare readiness integration: {$checks} checks (per-source readiness instead of WORKFLOW_READY: schema, permission, ingestion, operators, source access, finance four eyes, production document, invoicing, the shop's signed readiness report and its strict validation, stale and old connectors, the accountant's decision for enforce, Trend Home active while Outlet Perdele stays off, read-only CLI).\n");
