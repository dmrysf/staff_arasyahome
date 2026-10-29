<?php

declare(strict_types=1);

// Finance verification requests (migration 044, API 2.50.0) on a disposable database, through the HTTP kernel, with two
// in-process TEST shops (Trend Home, Outlet Perdele) that speak protocol v1 (TEST amounts only).
// Covers: the explicit request (operator only, approved COD order with an advance proforma, one open request per order,
// idempotent, never from a page load), the finance employee's task-scoped access (no order list, no order detail, no
// product, measurement, address or telephone, no unassigned or foreign request, no other source), the minimized task
// view, the four-eyes policy (recorded, second check by another employee) and the single policy (Operations AND the shop
// `single`, the `verify` scope), the bank evidence checks (payment reference, currency, revision, proforma identity),
// missing payment, clarification and resubmission, partial payment, amount mismatch, duplicate reference, two tabs, two
// employees, a lost answer reconciled, an unreadable shop, a changed or cancelled proforma, a changed total, an online
// order, a legacy order, the operator's view while finance works (poll interval), the supervisor's read-only oversight
// (no mutation, no order data, only supervised employees), revoked scopes, refunds kept apart, and that nothing else of
// the order changes (Confirmare approval, production stage, invoices, AWB).

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTransport;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;
use Arasya\Operations\Tests\SourceAccessFakeShop;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';
require __DIR__ . '/SourceAccessFakeShop.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL finance requests integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-fvr-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const FVR_KEY = 'trendhome-access-key-test-0123456789abcdef-fvr';
const FVR_PHONE = '0711666777';
const FVR_NAME = 'TEST Client Plata';
const FVR_STREET = 'Str. Exemplu 21';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

/** Two TEST shops behind one transport, by base URL. */
final class FinanceRequestShops implements SourceAccessTransport
{
    /** @param array<string, SourceAccessFakeShop> $shops */
    public function __construct(private array $shops)
    {
    }

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        return $this->shops[$baseUrl]->post($baseUrl, $path, $headers, $body);
    }
}

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
$access = static fn (string $verification): array => [
    'trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . FVR_KEY, 'finance' => 'record', 'invoice' => 'observe', 'verification' => $verification],
    'outletperdele' => ['mode' => 'read', 'url' => 'https://outletperdele.example', 'key' => 'op-test:' . FVR_KEY . '-outlet', 'finance' => 'record', 'invoice' => 'observe', 'verification' => ''],
];
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => $access('')]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
foreach (['finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision', 'confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations',
    'invoice_efactura_tasks', 'order_source_operations', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes',
    'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts',
    'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

// ---- 0. Migration 044: three tables, the verify capability, a re-run changes nothing -----------------------------------
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('employee_finance_supervision', 'finance_verification_requests', 'finance_verification_request_events')") === 3, 'migration 044 creates its three tables');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/044_finance_verification_requests.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'employee_finance_scopes' AND constraint_name LIKE 'chk_employee_finance_scopes_capability%'") === 1
    && (int) $one("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_finance_actions' AND constraint_name LIKE 'chk_order_finance_actions_action%'") === 1, 'running 044 again keeps exactly one capability CHECK and one action CHECK');

$th = new SourceAccessFakeShop('trendhome', 'th-test', FVR_KEY);
$op = new SourceAccessFakeShop('outletperdele', 'op-test', FVR_KEY . '-outlet');
$th->safetyRules = true;
$transport = new FinanceRequestShops(['https://trendhome.example' => $th, 'https://outletperdele.example' => $op]);
$container = new Container($config, $pdo, $transport);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
// The single policy needs Operations `single` too: a second kernel on the same database (the sessions are shared).
$kernelSingle = (new Container(new Config(...[...get_object_vars($base), 'sourceAccess' => $access('single')]), $pdo, $transport))->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "fvr.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-fvr-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Requests root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Requests root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fvr-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$password = 'requests test passphrase 2026';
$uuids = [];
// Generic test identities: every access comes from the scopes granted here, never from a name.
foreach (['rop', 'rop2', 'rsup', 'fin', 'fin2', 'fsup', 'fout', 'fnone', 'rdoc'] as $name) {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-fvr');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
}
$ok($asRoot('PUT', "/management/employees/{$uuids['rdoc']}/roles", ['roleIds' => [$roleIds['production-documents-operator']]]), 'documents role');
$ok($asRoot('PUT', "/management/employees/{$uuids['rdoc']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 'documents scope');
foreach (['rop' => ['operate' => ['trendhome'], 'supervise' => []], 'rop2' => ['operate' => ['outletperdele'], 'supervise' => []], 'rsup' => ['operate' => [], 'supervise' => ['trendhome']]] as $name => $grant) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/confirmare-scopes", $grant), "confirmare {$name}");
}

// ---- 1. Scopes and supervision: root only, explicit, audited ------------------------------------------------------------
// `fin` like the accountant (view + record on both shops); `fsup` like the finance director (view + approve, supervising).
$ok($asRoot('PUT', "/management/employees/{$uuids['fin']}/finance-scopes", ['view' => ['outletperdele', 'trendhome'], 'record' => ['outletperdele', 'trendhome'], 'approve' => []]), 'finance fin');
$ok($asRoot('PUT', "/management/employees/{$uuids['fsup']}/finance-scopes", ['view' => ['outletperdele', 'trendhome'], 'record' => [], 'approve' => ['outletperdele', 'trendhome']]), 'finance fsup');
$ok($asRoot('PUT', "/management/employees/{$uuids['fout']}/finance-scopes", ['view' => ['outletperdele'], 'record' => ['outletperdele'], 'approve' => []]), 'finance fout');
$withVerify = $ok($asRoot('PUT', "/management/employees/{$uuids['fin2']}/finance-scopes", ['view' => [], 'record' => [], 'approve' => [], 'verify' => ['trendhome']]), 'verify only');
$check($withVerify['financeScopes'] === ['view' => [], 'record' => [], 'approve' => [], 'verify' => ['trendhome']], 'the verify scope is stored like the others');
$kept = $ok($asRoot('PUT', "/management/employees/{$uuids['fin2']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []]), 'an older Dashboard (three lists)');
$check($kept['financeScopes']['verify'] === ['trendhome'] && $kept['financeScopes']['view'] === ['trendhome'], 'a payload without verify keeps the stored verify rows (backward compatible)');
$ok($asRoot('PUT', "/management/employees/{$uuids['fin2']}/finance-scopes", ['view' => [], 'record' => [], 'approve' => [], 'verify' => []]), 'fin2 back to nothing');
$error($asRoot('PUT', "/management/employees/{$uuids['fsup']}/finance-supervision", ['employeeIds' => [$uuids['fsup']]]), 400, 'VALIDATION_FAILED', 'nobody supervises themselves');
$error($asRoot('PUT', "/management/employees/{$uuids['fsup']}/finance-supervision", ['employeeIds' => [$uuids['fnone']]]), 400, 'VALIDATION_FAILED', 'a supervised employee holds a finance scope');
$supervision = $ok($asRoot('PUT', "/management/employees/{$uuids['fsup']}/finance-supervision", ['employeeIds' => [$uuids['fin']]]), 'supervision');
$check($supervision['financeSupervision']['employeeIds'] === [$uuids['fin']] && in_array($uuids['fout'], array_column($supervision['financeSupervision']['candidates'], 'id'), true), 'the supervision list and its candidates (finance scope holders)');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.finance_supervision_changed' AND target_id = ?", [$uuids['fsup']]) === 1, 'the supervision change is in the IAM audit');
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$who = [];
foreach (array_keys($uuids) as $name) $who[$name] = $login($name);
$error(T::call($kernel, 'PUT', "/management/employees/{$uuids['fin2']}/finance-supervision", ['employeeIds' => [$uuids['fin']]], ['x-csrf-token' => $who['fsup']['csrf'], 'idempotency-key' => 'fvr-self-' . $suffix], $who['fsup']['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'a finance supervisor cannot grant supervision');
$me = static fn (array $w): array => T::call($kernel, 'GET', '/employees/me', null, [], $w['cookie'])['body'] ?? [];
$check(($me($who['fsup'])['financeSupervisor'] ?? null) === true && ($me($who['fin'])['financeSupervisor'] ?? null) === false, 'the session says who supervises (presentation only)');

// ---- Fixtures: activated sources, approved COD orders with an advance proforma ---------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL finance requests integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'requests test', 'fvr-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'requests test', 'fvr-test', true);
}
$numberBase = T::freeOrderBase($pdo, 610000, 639999, 100);
$id = static fn (int $n): string => (string) ($numberBase + $n);
$g = static fn (int $n, string $src = 'trendhome'): string => $src . ':' . $id($n);
$tick = 0;
$pricing = static fn (string $orderId): string => 'sha256:' . hash('sha256', "fvr-price-{$orderId}");
$payload = static function (string $orderId, int $seconds, bool $cod = true, array $financial = []) use ($suffix, &$tick, $iso, $activatedAt, $pricing): array {
    $tick++;
    $p = T::sourceOrder($orderId, "fvr-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', [[
        'id' => 9501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing($orderId)];
    $p['order']['payment'] = $cod ? ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0] : ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
    $p['order']['financial'] = $financial + ($cod ? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing($orderId)] : ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $pricing($orderId)]);
    $p['order']['delivery'] = ['name' => FVR_NAME, 'street' => FVR_STREET, 'city' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => FVR_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify("+{$seconds} seconds")), 'evidence' => 'checkout'];
    return $p;
};
$pf = static fn (string $number): array => ['document' => ['series' => 'TPF', 'number' => $number, 'at' => time() - 600, 'by' => '00000000-0000-4000-8000-0000000000a1'], 'op' => 'none', 'opKey' => null, 'mail' => 'sent', 'mailCount' => 1,
    'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => [], 'reminders' => []];
$shopOrder = static fn (string $orderId, bool $cod, ?string $proforma): array => ['id' => $orderId, 'number' => $orderId, 'eligible' => true, 'phone' => FVR_PHONE, 'pricing' => 'sha256:' . hash('sha256', "fvr-price-{$orderId}"),
    'finance' => ['cod' => $cod, 'total' => '1000.00', 'collected' => '0.00', 'pricing' => 'sha256:' . hash('sha256', "fvr-price-{$orderId}")] + ($cod ? [] : ['paid' => '1000.00']),
    'invoice' => ['cod' => $cod, 'cleared' => !$cod] + ($proforma === null ? [] : ['pf' => $pf($proforma)])];
// 1-12, 14, 15: Trend Home COD with a proforma; 13: COD without one; 20: online and paid; 30: COD not approved; 60: legacy.
foreach ([...range(1, 15), 20, 30] as $n) {
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 60 + $n, $n !== 20)), "trendhome order {$n}");
    $th->orders[$id($n)] = $shopOrder($id($n), $n !== 20, $n === 13 ? null : sprintf('%04d', $n));
}
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), 150)), 'outlet order');
$op->orders[$id(50)] = $shopOrder($id(50), true, '0050');
$ok(T::ingest($kernel, 'trendhome', $payload($id(60), -3600)), 'legacy order (before the activation)');
$check($service->distribute()['assigned'] >= 15, 'the orders go to their operators');
$call = static fn (array $w, string $method, string $path, ?array $body = null, ?string $key = null, mixed $k = null): array => T::call($k ?? $kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => $key ?? 'fvr-' . bin2hex(random_bytes(10))], $w['cookie']);
$get = static function (array $w, string $path, mixed $k = null) use ($kernel): array {
    [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
    parse_str($query, $parameters);
    return T::call($k ?? $kernel, 'GET', $route, null, ['origin' => T::ORIGIN], $w['cookie'], $parameters);
};
$version = static fn (string $gid): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$gid]);
$approve = static function (array $w, string $gid) use ($call, $get, $version, $ok, $pdo): void {
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    $review = $ok($get($w, '/confirmare/orders/' . rawurlencode($gid)), "detail {$gid}")['review'];
    $ok($get($w, '/confirmare/orders/' . rawurlencode($gid) . '/source'), "shop order {$gid}");
    $ok($call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/contact', ['expectedVersion' => $version($gid), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]), "call {$gid}");
    $ok($call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/approve', ['expectedVersion' => $version($gid), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]), "approve {$gid}");
};
foreach ([...range(1, 15), 20] as $n) $approve($who['rop'], $g($n));
$approve($who['rop2'], $g(50, 'outletperdele'));
$check($one("SELECT COUNT(*) FROM order_confirmations WHERE status = 'approved'") >= 17, 'the TEST orders are approved in Confirmare');
$confirmations = static fn (): array => $all("SELECT global_order_id, status, version, decided_by_employee_uuid FROM order_confirmations ORDER BY global_order_id");
$confirmationsBefore = $confirmations();
$production = static fn (): array => $all('SELECT global_order_id, production_stage_id, operational_status, document_status, payment_method, advance_status FROM operational_orders ORDER BY global_order_id');
$productionBefore = $production();
$orderPath = static fn (string $gid, string $tail = ''): string => '/confirmare/orders/' . rawurlencode($gid) . $tail;
$ask = static fn (array $w, string $gid, ?string $key = null, mixed $k = null): array => $call($w, 'POST', $orderPath($gid, '/finance-request'), ['purpose' => 'advance'], $key, $k);
$view = static fn (array $w, string $gid): array => $get($w, $orderPath($gid, '/finance-request'));
$inbox = static fn (array $w, mixed $k = null): array => $get($w, '/confirmare/finance/requests', $k);
$task = static function (array $w, string $requestId, mixed $k = null) use ($get, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $get($w, '/confirmare/finance/requests/' . $requestId, $k); };
$cmd = static function (array $w, string $requestId, string $action, array $body, ?string $key = null, mixed $k = null) use ($call, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $call($w, 'POST', '/confirmare/finance/requests/' . $requestId . '/' . $action, $body, $key, $k); };
$today = gmdate('Y-m-d');
$reference = static fn (string $orderId): string => 'TH' . $orderId;
$evidence = static fn (array $t, string $amount, string $bankReference, array $over = []): array => $over + ['expectedVersion' => $t['request']['version'], 'outcome' => 'verified', 'expectedRevision' => $t['live']['revision'],
    'amount' => $amount, 'reference' => $bankReference, 'valueDate' => gmdate('Y-m-d'), 'paymentReference' => (string) $t['live']['paymentReference'], 'currency' => 'RON'];
$requestRow = static fn (string $requestId): array => $all('SELECT * FROM finance_verification_requests WHERE request_uuid = ?', [$requestId])[0] ?? [];
$noOrderData = static function (array $body, string $label) use ($check): void {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    foreach ([FVR_NAME, FVR_STREET, FVR_PHONE, '711666', 'Draperie', 'DV-302', 'Velvet', 'Cluj', '"width"', '"height"', '"lines"', '"customer"', '"contacts"', '"addressLines"'] as $needle) {
        $check(!str_contains((string) $json, $needle), "{$label}: no customer, product or measurement data ({$needle})");
    }
};

// ---- 2. The operator asks; nothing else creates a request ----------------------------------------------------------
$requests = count($th->requests);
$v0 = $ok($view($who['rop'], $g(1)), 'operator view before any request');
$check($v0['request'] === null && $v0['canRequest'] === true && $v0['pollSeconds'] === null && (int) $one('SELECT COUNT(*) FROM finance_verification_requests') === 0, 'reading (a page load, a refresh) creates nothing');
$check(count($th->requests) === $requests, 'the operator view never asks the shop');
$keyAsk = "fvr-ask-{$suffix}-000001";
$a1 = $ok($ask($who['rop'], $g(1), $keyAsk), 'the operator asks finance');
$check($a1['created'] === true && $a1['request']['state'] === 'assigned' && $a1['request']['assignee'] === 'Test fin' && $a1['request']['proforma'] === ['series' => 'TPF', 'number' => '0001'] && $a1['pollSeconds'] === 10, 'assigned to the only Trend Home finance employee who records; the screen asks every 10 s while it waits');
$r1 = $a1['request']['id'];
$check($ok($ask($who['rop'], $g(1), $keyAsk), 'same key')['request']['id'] === $r1, 'the same key answers with the same request');
$again = $ok($ask($who['rop'], $g(1)), 'another key (a second tab)');
$super = $ok($ask($who['rsup'], $g(1)), 'a supervisor asks too');
$check($again['created'] === false && $super['created'] === false && $again['request']['id'] === $r1 && (int) $one('SELECT COUNT(*) FROM finance_verification_requests WHERE order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)', [$g(1)]) === 1, 'one open request per order (second tab, another operator)');
$stored = $requestRow($r1);
$check($stored['proforma_series'] === 'TPF' && $stored['proforma_number'] === '0001' && $stored['assigned_employee_uuid'] === $uuids['fin'], 'only the proforma identity is kept with the request');
$columns = array_column($all("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('finance_verification_requests', 'finance_verification_request_events')"), 'c');
$check(array_values(array_filter($columns, static fn (string $c): bool => preg_match('/amount|currency|reference|iban|bank|customer|phone|address/i', $c) === 1)) === [],
    'the task tables hold no amount, currency, payment reference, IBAN, bank transaction or customer data (finance data comes from the shop at read time)');
$error($ask($who['rop'], $g(13)), 409, 'FINANCE_REQUEST_NO_PROFORMA', 'no advance proforma: nothing to verify against');
$error($ask($who['rop'], $g(20)), 409, 'FINANCE_REQUEST_NOT_COD', 'an order already paid online needs no advance verification');
$error($ask($who['rop'], $g(30)), 409, 'FINANCE_REQUEST_NOT_APPROVED', 'an order not approved yet');
$error($ask($who['rop'], $g(60)), 404, 'CONFIRMARE_NOT_FOUND', 'a legacy order keeps its workflow');
$error($ask($who['fin'], $g(2)), 404, 'CONFIRMARE_NOT_FOUND', 'a finance employee cannot create a request');
$error($ask($who['rop2'], $g(2)), 404, 'CONFIRMARE_NOT_FOUND', 'another shop\'s operator cannot either');
$error($call($who['rop'], 'POST', $orderPath($g(2), '/finance-request'), ['purpose' => 'refund']), 422, 'FINANCE_REQUEST_INVALID', 'only an advance');
$error($call($who['rop'], 'POST', $orderPath($g(2), '/finance-request'), ['purpose' => 'advance'], 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'never without an Idempotency-Key');
$a50 = $ok($ask($who['rop2'], $g(50, 'outletperdele')), 'Outlet Perdele request');
$check($a50['request']['assignee'] === 'Test fin' || $a50['request']['assignee'] === 'Test fout', 'an Outlet Perdele request goes to an Outlet Perdele finance employee');
foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 15] as $n) $ok($ask($who['rop'], $g($n)), "request {$n}");
$rid = static fn (int $n, string $src = 'trendhome'): string => (string) $one('SELECT r.request_uuid FROM finance_verification_requests r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ? ORDER BY r.requested_at DESC LIMIT 1', [$g($n, $src)]);

// ---- 3. Privacy: the finance employee reaches tasks, never orders ----------------------------------------------------
$f = $who['fin'];
$error($get($f, '/confirmare/pool?view=all'), 403, 'CONFIRMARE_SCOPE_DENIED', 'no general order list (Trend Home and Outlet Perdele alike)');
$error($get($f, '/confirmare/pool?view=open&source=outletperdele'), 403, 'CONFIRMARE_SCOPE_DENIED', 'no Outlet Perdele order list either');
$error($get($f, $orderPath($g(1))), 404, 'CONFIRMARE_NOT_FOUND', 'no Confirmare order detail, even of a task\'s order');
$error($get($f, $orderPath($g(1), '/source')), 404, 'CONFIRMARE_NOT_FOUND', 'no shop order view (telephone, address, products)');
$error($get($f, $orderPath($g(30), '/finance')), 404, 'CONFIRMARE_NOT_FOUND', 'no finance summary of an order without a task');
$error($get($f, $orderPath($g(30), '/invoice')), 404, 'CONFIRMARE_NOT_FOUND', 'no SmartBill state of an order without a task');
$error($get($f, $orderPath($g(30), '/operations')), 404, 'CONFIRMARE_NOT_FOUND', 'no AWB or refund state of an order without a task');
$error($get($f, $orderPath($g(30), '/finance-request')), 404, 'CONFIRMARE_NOT_FOUND', 'no operator view of a request');
$error($get($f, '/confirmare/handoff'), 403, 'UNAUTHORIZED_ACTION', 'no production handoff list');
$check($ok($get($f, '/confirmare/cycles'), 'proforma cycles')['items'] === [], 'no list of the shop\'s proforma cycles (it names orders)');
$queue = $ok($get($f, '/confirmare/finance'), 'the old finance queue');
$check($queue['orders'] === [] && $queue['tasks']['advance_waiting'] === 0, 'the finance queue no longer lists every COD order waiting for its advance');
$error($get($f, '/orders/' . rawurlencode($g(1))), 404, 'ORDER_NOT_FOUND', 'no production order view (no stage access: not found)');
$box = $ok($inbox($f), 'inbox');
$ids = array_column($box['items'], 'id');
$mine = 14 + ($a50['request']['assignee'] === 'Test fin' ? 1 : 0);
$check(in_array($r1, $ids, true) && count($ids) === $mine && $box['counts']['todo'] === $mine && $box['legacySecondChecks'] === [] && (int) $one('SELECT COUNT(*) FROM finance_verification_requests WHERE assigned_employee_uuid = ?', [$uuids['fin']]) === $mine,
    'the inbox: exactly the requests assigned to this employee');
$noOrderData($box, 'inbox');
$item = array_column($box['items'], null, 'id')[$r1];
$check(array_keys($item) === ['id', 'source', 'orderNumber', 'proforma', 'state', 'version', 'category', 'kind', 'assignedToMe', 'outcome', 'reasonCode', 'policy', 'requestedAt', 'updatedAt', 'decidedAt']
    && $item['kind'] === 'verify', 'an inbox card: source, order number, proforma, state, one action (no amount, no payment reference)');
$fake = '00000000-0000-4000-8000-00000000beef';
$error($task($f, $fake), 404, 'FINANCE_REQUEST_NOT_FOUND', 'a guessed request id reveals nothing');
$error($task($who['fout'], $r1), 404, 'FINANCE_REQUEST_NOT_FOUND', 'another source\'s finance employee cannot open it');
$error($task($who['fnone'], $r1), 404, 'FINANCE_REQUEST_NOT_FOUND', 'no scope, no task');
$error($inbox($who['fnone']), 403, 'CONFIRMARE_FINANCE_DENIED', 'no scope, no inbox');
$error($task($who['fsup'], $r1), 404, 'FINANCE_REQUEST_NOT_FOUND', 'the supervisor cannot open the employee\'s task as a task');
$error($task($who['rop'], $r1), 404, 'FINANCE_REQUEST_NOT_FOUND', 'the operator cannot open the finance task');
$t1 = $ok($task($f, $r1), 'task');
$noOrderData($t1, 'task');
$check($t1['live']['available'] === true && $t1['live']['proforma']['number'] === '0001' && $t1['live']['advance'] === ['percentage' => 30, 'required' => '300.00', 'verified' => '0.00', 'remaining' => '300.00', 'accepted' => false]
    && $t1['live']['bank'] === ['beneficiary' => 'TEST Trend Home SRL', 'bank' => 'Banca TEST', 'ibanMasked' => 'RO49 •••• •••• 0000', 'bic' => null] && $t1['live']['policy'] === 'four_eyes' && $t1['live']['transfers'] === [] && $t1['live']['proformaChanged'] === false,
    'the task: proforma, advance (required, verified, remaining), the bank account the proforma names (IBAN masked), the policy, the bank transfers, read from the shop now');
$check(!str_contains((string) json_encode($t1, JSON_UNESCAPED_UNICODE), 'RO49AAAA1B31007593840000'), 'the full IBAN never leaves the API');
$check(array_keys($t1['live']) === ['available', 'revision', 'readAt', 'currency', 'proforma', 'proformaChanged', 'advance', 'paymentReference', 'bank', 'financial', 'policy', 'recordEnabled', 'transfers', 'settledElsewhere'], 'the task carries finance fields only');
$check($t1['allowed'] === ['start' => true, 'verify' => true, 'notReceived' => true, 'clarify' => true, 'secondCheck' => false, 'reconcile' => false], 'the employee\'s actions: start, verify, not received, clarification');
$check((int) $one("SELECT COUNT(*) FROM order_finance_reads WHERE employee_uuid = ? AND access_role = 'finance' AND outcome = 'ok'", [$uuids['fin']]) === 1, 'the task read is audited');
$check($ok($get($f, $orderPath($g(1), '/finance')), 'the order finance summary of a task')['role'] === 'finance', 'a task\'s order: its finance summary is readable (finance fields only)');

// ---- 4. Four eyes (default policy): recorded by the employee, verified by another --------------------------------------
$requests = count($th->requests);
$error($cmd($f, $r1, 'start', ['expectedVersion' => 99]), 409, 'FINANCE_REQUEST_STALE', 'a stale version is refused');
$s1 = $ok($cmd($f, $r1, 'start', ['expectedVersion' => $t1['request']['version']]), 'start checking');
$check($s1['request']['state'] === 'checking', 'assigned -> checking');
$t1 = $ok($task($f, $r1), 'task while checking');
$requests = count($th->requests);
$error($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001', ['paymentReference' => 'TH999'])), 422, 'FINANCE_REQUEST_PAYMENT_REFERENCE', 'another payment reference than the proforma\'s: refused');
$error($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001', ['currency' => 'EUR'])), 422, 'FINANCE_REQUEST_CURRENCY', 'another currency: refused');
$error($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001', ['expectedRevision' => 'sha256:' . str_repeat('0', 64)])), 409, 'FINANCE_REQUEST_STALE', 'based on old money figures: refused');
$error($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001', ['amount' => '300.001'])), 422, 'FINANCE_REQUEST_INVALID', 'an invalid amount');
$error($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001', ['valueDate' => gmdate('Y-m-d', time() + 5 * 86400)])), 422, 'FINANCE_REQUEST_INVALID', 'a value date in the future');
$error($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001') + ['verified' => true]), 400, 'INVALID_REQUEST', 'no field beyond the evidence (no "verified" shortcut)');
$check(count($th->requests) === $requests + 3, 'refused evidence reached the shop only to read the money (three summary reads), never as an action');
$check(!array_filter($th->orders[$id(1)]['finance']['entries'] ?? [], static fn (array $e): bool => true), 'nothing recorded in the shop');
$keyDecide = "fvr-decide-{$suffix}-000001";
$d1 = $ok($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001'), $keyDecide), 'confirm the payment (four eyes)');
$check($d1['request']['state'] === 'checking' && $d1['request']['kind'] === 'awaiting_second_check' && $d1['receipt']['awaitingSecondCheck'] === true && $d1['receipt']['policy'] === 'four_eyes', 'four eyes: recorded, waiting for a second employee (not money yet)');
$entries = $th->orders[$id(1)]['finance']['entries'];
$check(count($entries) === 1 && $entries[0]['state'] === 'submitted' && $entries[0]['submittedBy'] === $uuids['fin'], 'the shop holds one submitted transfer recorded by this employee');
$check($ok($cmd($f, $r1, 'decide', $evidence($t1, '300.00', 'BT FVR 0001'), $keyDecide), 'retry with the same key')['request']['state'] === 'checking' && count($th->orders[$id(1)]['finance']['entries']) === 1, 'a retried decision records nothing twice');
$opView = $ok($view($who['rop'], $g(1)), 'operator view in verification');
$check($opView['request']['state'] === 'checking' && $opView['request']['awaitingSecondCheck'] === true && $opView['pollSeconds'] === 5 && $opView['financial']['open'] === false, 'the operator sees it in verification (asks every 5 s), never paid');
$error($cmd($f, $r1, 'second-check', ['expectedVersion' => $requestRow($r1)['version'], 'expectedRevision' => $t1['live']['revision'], 'decision' => 'verify']), 403, 'CONFIRMARE_FINANCE_SAME_EMPLOYEE', 'the recorder never verifies their own record');
$zbox = $ok($inbox($who['fsup']), 'second checker inbox');
$check(array_column($zbox['items'], 'kind', 'id')[$r1] === 'second_check' && count($zbox['items']) === 1, 'the employee with approve sees exactly the second check waiting for them');
$noOrderData($zbox, 'second checker inbox');
$tz = $ok($task($who['fsup'], $r1), 'second check task');
$check($tz['allowed']['secondCheck'] === true && $tz['allowed']['verify'] === false && count($tz['live']['transfers']) === 1 && $tz['live']['transfers'][0]['submittedBy']['displayName'] === 'Test fin', 'the second check sees the recorded transfer and who recorded it');
$z1 = $ok($cmd($who['fsup'], $r1, 'second-check', ['expectedVersion' => $requestRow($r1)['version'], 'expectedRevision' => $tz['live']['revision'], 'decision' => 'verify']), 'second check');
$check($z1['request']['state'] === 'verified' && $z1['request']['outcome'] === 'verified_full', 'verified by the second employee: 300 of 300');
$row1 = $requestRow($r1);
$check($row1['decided_by_employee_uuid'] === $uuids['fsup'] && $row1['recorded_by_employee_uuid'] === $uuids['fin'] && $row1['policy'] === 'four_eyes' && $row1['open_order_uuid'] === null, 'who recorded, who verified, under which policy');
$opView = $ok($view($who['rop'], $g(1)), 'operator view after verification');
$check($opView['request']['state'] === 'verified' && $opView['request']['outcome'] === 'verified_full' && $opView['pollSeconds'] === 5 && $opView['financial']['open'] === false, 'verified, while the shop\'s money state has not reached Arasya: the screen keeps asking (5 s)');
$ok(T::ingest($kernel, 'trendhome', $payload($id(1), 61, true, ['state' => 'cleared', 'basis' => 'advance_collected'])), 'the shop\'s order event with the verified advance');
$opView = $ok($view($who['rop'], $g(1)), 'operator view after the order event');
$check($opView['financial']['open'] === true && $opView['pollSeconds'] === null && $opView['canRequest'] === true, 'the payment condition holds now; no more polling');

// ---- 5. Missing payment, clarification, resubmission --------------------------------------------------------------
$t2 = $ok($task($f, $rid(2)), 'task 2');
$error($cmd($f, $rid(2), 'decide', ['expectedVersion' => $t2['request']['version'], 'outcome' => 'not_received', 'reasonCode' => 'lost']), 422, 'FINANCE_REQUEST_INVALID', 'a known reason only');
$requests = count($th->requests);
$n2 = $ok($cmd($f, $rid(2), 'decide', ['expectedVersion' => $t2['request']['version'], 'outcome' => 'not_received', 'reasonCode' => 'not_found']), 'not received');
$check($n2['request']['state'] === 'not_received' && $n2['receipt']['outcome'] === 'not_received' && count($th->requests) === $requests && ($th->orders[$id(2)]['finance']['entries'] ?? []) === [], 'payment not found: nothing sent to the shop, nothing recorded');
$o2 = $ok($view($who['rop'], $g(2)), 'operator view: not received');
$check($o2['request']['state'] === 'not_received' && $o2['request']['reasonCode'] === 'not_found' && $o2['canRequest'] === true && $o2['pollSeconds'] === null, 'the operator sees "not received" and may ask again later');
$t3 = $ok($task($f, $rid(3)), 'task 3');
$c3 = $ok($cmd($f, $rid(3), 'decide', ['expectedVersion' => $t3['request']['version'], 'outcome' => 'needs_clarification', 'reasonCode' => 'reference_missing']), 'clarification');
$check($c3['request']['state'] === 'needs_clarification' && $c3['request']['category'] === 'clarification', 'needs clarification (the operator asks the customer)');
$o3 = $ok($view($who['rop'], $g(3)), 'operator view: clarification');
$check($o3['canResubmit'] === true && $o3['request']['reasonCode'] === 'reference_missing', 'the operator is told what to clarify');
$check($ok($ask($who['rop'], $g(3)), 'asking again while clarifying')['created'] === false, 'asking again returns the open request');
$re3 = $ok($call($who['rop'], 'POST', $orderPath($g(3), '/finance-request/resubmit'), ['expectedVersion' => $o3['request']['version']]), 'resubmit');
$check($re3['request']['state'] === 'assigned' && $re3['request']['assignee'] === 'Test fin', 'back to the same finance employee');
$error($call($who['rop'], 'POST', $orderPath($g(3), '/finance-request/resubmit'), ['expectedVersion' => $o3['request']['version']]), 409, 'FINANCE_REQUEST_STALE', 'a second resubmit from an old screen is refused');

// ---- 6. Two tabs, two employees ------------------------------------------------------------------------------------
$t4 = $ok($task($f, $rid(4)), 'task 4 (tab A)');
$t4b = $ok($task($f, $rid(4)), 'task 4 (tab B)');
$ok($cmd($f, $rid(4), 'decide', ['expectedVersion' => $t4['request']['version'], 'outcome' => 'needs_clarification', 'reasonCode' => 'payer_unclear']), 'tab A decides');
$error($cmd($f, $rid(4), 'decide', ['expectedVersion' => $t4b['request']['version'], 'outcome' => 'not_received', 'reasonCode' => 'not_found']), 409, 'FINANCE_REQUEST_STALE', 'tab B with the same version is refused (no double decision)');
$ok($asRoot('PUT', "/management/employees/{$uuids['fin2']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => []]), 'a second Trend Home finance employee');
$who['fin2'] = $login('fin2');
$error($task($who['fin2'], $rid(5)), 404, 'FINANCE_REQUEST_NOT_FOUND', 'another finance employee cannot open a task assigned to someone else');
$error($cmd($who['fin2'], $rid(5), 'decide', ['expectedVersion' => 1, 'outcome' => 'not_received', 'reasonCode' => 'not_found']), 404, 'FINANCE_REQUEST_NOT_FOUND', 'nor decide it');
$check($ok($inbox($who['fin2']), 'second employee inbox')['items'] === [], 'nor see it in their inbox');
$a16 = $ok($ask($who['rop'], $g(15)), 'request 15 again (open)');
$check($a16['request']['assignee'] === 'Test fin', 'an open request keeps its employee');

// ---- 7. Source unavailable, a changed proforma, a changed total ----------------------------------------------------
$th->fail = 'SOURCE_UNREACHABLE';
$t5 = $ok($task($f, $rid(5)), 'task while the shop is unreachable');
$th->fail = null;
$check($t5['live']['available'] === false && $t5['allowed']['verify'] === false && $t5['allowed']['notReceived'] === true, 'the shop cannot be read: no verification offered, the money is never guessed');
$t5 = $ok($task($f, $rid(5)), 'task 5');
$th->fail = 'SOURCE_UNREACHABLE';
$error($cmd($f, $rid(5), 'decide', $evidence($t5, '300.00', 'BT FVR 0005')), 503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'deciding while the shop is unreachable records nothing');
$th->fail = null;
$check($requestRow($rid(5))['state'] === 'assigned' && $requestRow($rid(5))['finance_idempotency_key'] === null, 'the request is unchanged');
$th->orders[$id(6)]['invoice']['pf']['document']['number'] = '0006B';
$t6 = $ok($task($f, $rid(6)), 'task after the proforma changed');
$check($t6['live']['proformaChanged'] === true && $t6['allowed']['verify'] === false, 'the proforma of the request changed: no verification offered');
$error($cmd($f, $rid(6), 'decide', $evidence($t6, '300.00', 'BT FVR 0006')), 409, 'FINANCE_REQUEST_PROFORMA_CHANGED', 'a decision on another proforma is refused');
$th->orders[$id(6)]['invoice']['pf']['document']['number'] = '0006';
$th->orders[$id(6)]['finance']['proformaCancelled'] = true;
$t6 = $ok($task($f, $rid(6)), 'task after the proforma was cancelled');
$check($t6['live']['proformaChanged'] === true, 'a cancelled proforma counts as changed');
$error($cmd($f, $rid(6), 'decide', $evidence($t6, '300.00', 'BT FVR 0006')), 409, 'FINANCE_REQUEST_PROFORMA_CHANGED', 'no payment applied to a cancelled proforma through a request');
$th->orders[$id(6)]['finance']['proformaCancelled'] = false;
$t7 = $ok($task($f, $rid(7)), 'task 7');
$th->orders[$id(7)]['finance']['total'] = '800.00';
$error($cmd($f, $rid(7), 'decide', $evidence($t7, '300.00', 'BT FVR 0007')), 409, 'FINANCE_REQUEST_STALE', 'the order total changed after the task was opened: refused');
$t7 = $ok($task($f, $rid(7)), 'task 7 again');
$check($t7['live']['advance']['required'] === '240.00', 'the new total gives the new advance (30 % of 800)');

// ---- 8. The single policy: Operations AND the shop `single`, the verify scope -------------------------------------------
$t8 = $ok($task($f, $rid(8), $kernelSingle), 'task 8, Operations single, shop four eyes');
$check($t8['live']['policy'] === 'four_eyes', 'Operations alone cannot switch the policy: the shop says four eyes');
$th->financeVerification = 'single';
$t8 = $ok($task($f, $rid(8), $kernel), 'task 8, shop single, Operations four eyes');
$check($t8['live']['policy'] === 'four_eyes', 'the shop alone cannot switch it either');
$t8 = $ok($task($f, $rid(8), $kernelSingle), 'task 8, both single');
$check($t8['live']['policy'] === 'single' && $t8['singleVerification'] === false, 'both single, but the employee holds no verify scope');
$d8 = $ok($cmd($f, $rid(8), 'decide', $evidence($t8, '300.00', 'BT FVR 0008'), null, $kernelSingle), 'record without verify');
$check($d8['request']['kind'] === 'awaiting_second_check' && $th->orders[$id(8)]['finance']['entries'][0]['state'] === 'submitted', 'without the verify scope the four-eyes path stays (no single verification by accident)');
$ok($asRoot('PUT', "/management/employees/{$uuids['fin']}/finance-scopes", ['view' => ['outletperdele', 'trendhome'], 'record' => ['outletperdele', 'trendhome'], 'approve' => [], 'verify' => ['trendhome']]), 'grant verify');
$f = $who['fin'] = $login('fin');
$t9 = $ok($task($f, $rid(9), $kernelSingle), 'task 9');
$check($t9['live']['policy'] === 'single' && $t9['singleVerification'] === true, 'single verification offered');
$requests = count($th->requests);
$d9 = $ok($cmd($f, $rid(9), 'decide', $evidence($t9, '300.00', 'BT FVR 0009'), "fvr-single-{$suffix}-0001", $kernelSingle), 'single verification');
$check($d9['request']['state'] === 'verified' && $d9['request']['outcome'] === 'verified_full' && $d9['receipt']['policy'] === 'single' && $d9['receipt']['awaitingSecondCheck'] === false, 'one employee verified the bank transfer; the task closes with its receipt');
$e9 = $th->orders[$id(9)]['finance']['entries'];
$check(count($e9) === 1 && $e9[0]['state'] === 'verified' && $e9[0]['submittedBy'] === $uuids['fin'] && $e9[0]['decidedBy'] === $uuids['fin'] && $e9[0]['flags'] === ['single_verification'], 'the shop holds it verified, marked as a single verification (no invented second employee)');
$sent = end($th->requests)['body'];
$check(($sent['action'] ?? null) === 'verify_transfer' && $sent['bankStatementConfirmed'] === true && $sent['paymentReference'] === $reference($id(9)) && $sent['currency'] === 'RON', 'the shop received the statement evidence: amount, reference, value date, payment reference, currency');
$row9 = $requestRow($rid(9));
$check($row9['policy'] === 'single' && $row9['decided_by_employee_uuid'] === $uuids['fin'] && $row9['recorded_by_employee_uuid'] === $uuids['fin'] && strlen((string) $row9['evidence_hmac']) === 64, 'attributable: who, policy, an HMAC of the evidence');
$error($cmd($who['fsup'], $rid(9), 'second-check', ['expectedVersion' => $row9['version'], 'expectedRevision' => 'sha256:' . str_repeat('1', 64), 'decision' => 'verify'], null, $kernelSingle), 404, 'FINANCE_REQUEST_NOT_FOUND', 'no extra manager approval can be triggered on a verified request');
$error($call($who['fsup'], 'POST', $orderPath($g(9), '/finance/decide-transfer'), ['expectedRevision' => 'sha256:' . str_repeat('1', 64), 'entryId' => $e9[0]['id'], 'decision' => 'verify'], null, $kernelSingle), 404, 'CONFIRMARE_NOT_FOUND', 'nor through the order\'s finance route');
$check(!array_filter($ok($inbox($who['fsup'], $kernelSingle), 'supervisor inbox')['items'], static fn (array $i): bool => $i['id'] === $rid(9)), 'the routine verification never reaches the supervisor\'s inbox');
// Partial payment: 100 of 300.
$t10 = $ok($task($f, $rid(10), $kernelSingle), 'task 10');
$d10 = $ok($cmd($f, $rid(10), 'decide', $evidence($t10, '100.00', 'BT FVR 0010'), null, $kernelSingle), 'partial payment');
$check($d10['request']['outcome'] === 'verified_partial' && $th->financeNumbers($id(10))['state'] === 'partial', 'a partial payment is verified as partial, never as the full advance');
$o10 = $ok($view($who['rop'], $g(10)), 'operator view of a partial payment');
$check($o10['request']['outcome'] === 'verified_partial' && $o10['financial']['open'] === false && $o10['pollSeconds'] === null, 'the operator sees a partial advance (the 30 % rule still holds)');
// Amount mismatch and duplicate reference: refused by the shop, nothing recorded, the request stays checking.
$t11 = $ok($task($f, $rid(11), $kernelSingle), 'task 11');
$error($cmd($f, $rid(11), 'decide', $evidence($t11, '2000.00', 'BT FVR 0011'), null, $kernelSingle), 409, 'CONFIRMARE_FINANCE_REFUSED', 'more than the order costs: refused by the shop');
$check($requestRow($rid(11))['state'] === 'checking' && $requestRow($rid(11))['finance_idempotency_key'] === null && ($th->orders[$id(11)]['finance']['entries'] ?? []) === [], 'nothing recorded; the request is back to checking');
$t11 = $ok($task($f, $rid(11), $kernelSingle), 'task 11 again');
$error($cmd($f, $rid(11), 'decide', $evidence($t11, '300.00', 'BT FVR 0009'), null, $kernelSingle), 409, 'CONFIRMARE_FINANCE_REFUSED', 'the bank reference of order 9 again: duplicate');
$check((string) $one("SELECT reason_code FROM finance_verification_request_events WHERE request_uuid = ? AND action = 'decision_refused' ORDER BY version_after DESC LIMIT 1", [$rid(11)]) === 'duplicate_reference', 'the refusal is in the request history');
// The answer is lost: unknown, reconciled with the shop's stored answer.
$t12 = $ok($task($f, $rid(12), $kernelSingle), 'task 12');
$th->afterFinance = 'answer_lost';
$d12 = $ok($cmd($f, $rid(12), 'decide', $evidence($t12, '300.00', 'BT FVR 0012'), null, $kernelSingle), 'lost answer');
$check($d12['request']['state'] === 'exception' && $d12['receipt']['unknown'] === true && $d12['request']['reasonCode'] === 'outcome_unknown', 'the answer was lost: exception, never verified on a guess');
$o12 = $ok($view($who['rop'], $g(12)), 'operator view of an unknown outcome');
$check($o12['request']['state'] === 'exception' && $o12['financial']['open'] === false, 'the operator never sees it paid');
$t12 = $ok($task($f, $rid(12), $kernelSingle), 'task 12 unknown');
$check($t12['allowed']['reconcile'] === true && $t12['allowed']['verify'] === false, 'only reconciliation is offered');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$rc12 = $ok(T::call($kernelSingle, 'POST', '/confirmare/finance/requests/' . $rid(12) . '/reconcile', null, ['origin' => T::ORIGIN, 'x-csrf-token' => $f['csrf'], 'idempotency-key' => 'fvr-rc-' . $suffix . '-1', 'content-type' => 'application/json'], $f['cookie'], [], '{}'), 'reconcile');
$check($rc12['request']['state'] === 'verified' && count($th->orders[$id(12)]['finance']['entries']) === 1, 'reconciled with the shop\'s stored answer: verified once');
// Refunds stay apart: four eyes, after production, never through a request or an operator.
$error($call($who['rop'], 'POST', $orderPath($g(9), '/finance/submit-refund'), ['expectedRevision' => 'sha256:' . str_repeat('1', 64), 'amount' => '10.00', 'reference' => 'RF FVR 0001', 'valueDate' => $today], null, $kernelSingle), 403, 'CONFIRMARE_FINANCE_DENIED', 'an operator cannot record a refund');
$error($call($who['fout'], 'POST', $orderPath($g(9), '/finance/submit-refund'), ['expectedRevision' => 'sha256:' . str_repeat('1', 64), 'amount' => '10.00', 'reference' => 'RF FVR 0001', 'valueDate' => $today], null, $kernelSingle), 404, 'CONFIRMARE_NOT_FOUND', 'another shop\'s finance employee cannot reach it');
$error($call($f, 'POST', $orderPath($g(9), '/finance/submit-refund'), ['expectedRevision' => 'sha256:' . str_repeat('1', 64), 'amount' => '10.00', 'reference' => 'RF FVR 0001', 'valueDate' => $today], null, $kernelSingle), 409, 'CONFIRMARE_FINANCE_REFUND_NOT_ELIGIBLE', 'no refund before production ends (single verification never widens refunds)');

// ---- 9. Rebalancing: a revoked scope sends the request back to the pool -------------------------------------------------
$ok($asRoot('PUT', "/management/employees/{$uuids['fin2']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => [], 'verify' => []]), 'fin2 records');
$o14 = $ok($view($who['rop'], $g(14)), 'operator view 14');
$check($o14['canCancel'] === true, 'the operator may withdraw a request nobody worked on');
$ok($call($who['rop'], 'POST', $orderPath($g(14), '/finance-request/cancel'), ['expectedVersion' => $o14['request']['version'], 'reasonCode' => 'requested_by_mistake']), 'withdrawn by the operator');
$a14 = $ok($ask($who['rop'], $g(14)), 'a new request when one employee holds many open ones');
$check($a14['request']['assignee'] === 'Test fin2', 'the least loaded finance employee gets it');
$ok($asRoot('PUT', "/management/employees/{$uuids['fin2']}/finance-scopes", ['view' => [], 'record' => [], 'approve' => [], 'verify' => []]), 'fin2 loses the scope');
$who['fin2'] = $login('fin2');
$error($task($who['fin2'], $a14['request']['id']), 404, 'FINANCE_REQUEST_NOT_FOUND', 'a revoked scope: the task is gone at once');
$error($inbox($who['fin2']), 403, 'CONFIRMARE_FINANCE_DENIED', 'a revoked scope: no inbox');
$box = $ok($inbox($f), 'inbox after the revocation');
$check(in_array($a14['request']['id'], array_column($box['items'], 'id'), true) && $requestRow($a14['request']['id'])['assigned_employee_uuid'] === $uuids['fin'], 'the request went back to an eligible employee');
$check((string) $one("SELECT action FROM finance_verification_request_events WHERE request_uuid = ? ORDER BY version_after DESC LIMIT 1", [$a14['request']['id']]) === 'assigned'
    && $one("SELECT actor_employee_uuid FROM finance_verification_request_events WHERE request_uuid = ? ORDER BY version_after DESC LIMIT 1", [$a14['request']['id']]) === null, 'audited as a system reassignment');

// ---- 10. Oversight: the supervisor observes, never acts -------------------------------------------------------------
$z = $who['fsup'];
$ov = $ok($get($z, '/confirmare/finance/oversight'), 'oversight');
$noOrderData($ov, 'oversight');
$ovIds = array_column($ov['items'], null, 'id');
$check(isset($ovIds[$r1], $ovIds[$rid(9)], $ovIds[$rid(2)]) && $ov['counts']['verified'] >= 3 && $ov['counts']['not_received'] === 1 && $ov['employees'] === [['id' => $uuids['fin'], 'displayName' => 'Test fin']], 'the supervised employee\'s requests: verified, not received, the others');
$check($ovIds[$rid(9)]['decidedBy']['displayName'] === 'Test fin' && $ovIds[$rid(9)]['policy'] === 'single' && $ovIds[$r1]['recordedBy']['displayName'] === 'Test fin' && $ovIds[$r1]['decidedBy']['displayName'] === 'Test fsup', 'who acted, under which policy');
$check(!isset($ovIds[$a14['request']['id']]) || $ovIds[$a14['request']['id']]['assignee']['id'] === $uuids['fin'], 'only supervised employees\' requests');
$check(array_unique(array_column($ok($get($z, '/confirmare/finance/oversight?state=verified'), 'filter state')['items'], 'state')) === ['verified'], 'filter by state');
$check(array_diff(array_column($ok($get($z, '/confirmare/finance/oversight?source=outletperdele'), 'filter source')['items'], 'source'), ['outletperdele']) === [], 'filter by source');
$check($ok($get($z, '/confirmare/finance/oversight?from=' . gmdate('Y-m-d', time() + 2 * 86400)), 'filter date')['items'] === [], 'filter by date');
$error($get($z, '/confirmare/finance/oversight?employee=' . $uuids['fout']), 403, 'FINANCE_OVERSIGHT_DENIED', 'never an employee they do not supervise');
$error($get($f, '/confirmare/finance/oversight'), 403, 'FINANCE_OVERSIGHT_DENIED', 'a finance employee without supervision has no oversight');
$od = $ok($get($z, '/confirmare/finance/oversight/' . $rid(9)), 'oversight detail');
$noOrderData($od, 'oversight detail');
$check(count($od['transfers']) === 1 && $od['transfers'][0]['amount'] === '300.00' && $od['transfers'][0]['reference'] === 'BT FVR 0009' && in_array('verified', array_column($od['events'], 'action'), true) && $od['currency'] === 'RON', 'the detail: the amount, the bank reference (read from the shop now), the audit trail');
$error($get($z, '/confirmare/finance/oversight/' . $fake), 404, 'FINANCE_REQUEST_NOT_FOUND', 'a guessed id reveals nothing');
$error($get($z, $orderPath($g(9))), 404, 'CONFIRMARE_NOT_FOUND', 'no Confirmare order through oversight');
$error($get($z, '/confirmare/pool?view=all'), 403, 'CONFIRMARE_SCOPE_DENIED', 'no order list through oversight');
$error($cmd($z, $rid(5), 'decide', ['expectedVersion' => 1, 'outcome' => 'not_received', 'reasonCode' => 'not_found']), 404, 'FINANCE_REQUEST_NOT_FOUND', 'oversight is no power to decide a supervised employee\'s task');
$error($cmd($z, $rid(5), 'start', ['expectedVersion' => 1]), 404, 'FINANCE_REQUEST_NOT_FOUND', 'nor to start it');
$ok($asRoot('PUT', "/management/employees/{$uuids['fsup']}/finance-supervision", ['employeeIds' => []]), 'supervision revoked');
$z = $login('fsup');
$error($get($z, '/confirmare/finance/oversight'), 403, 'FINANCE_OVERSIGHT_DENIED', 'a revoked supervision denies at once');

// ---- 11. Legitimate work stays: operators, documents ----------------------------------------------------------------
$check($ok($get($who['rop'], $orderPath($g(9))), 'operator detail')['financeRequest']['request']['state'] === 'verified', 'the operator keeps the order screen, with the request state');
$check(is_array($ok($get($who['rop'], '/confirmare/pool?view=followup'), 'operator pool')['items']), 'the operator keeps the pool');
$check($ok($get($who['rdoc'], '/confirmare/handoff'), 'documents handoff')['items'] !== null, 'the production documents employee keeps the handoff list');

// ---- 12. Nothing else of the order changed --------------------------------------------------------------------------
$check($confirmations() === $confirmationsBefore, 'no Confirmare approval was re-run or changed (status, version, decider)');
$productionAfter = array_filter($production(), static fn (array $row): bool => $row['global_order_id'] !== $g(1));
$check(array_values($productionAfter) === array_values(array_filter($productionBefore, static fn (array $row): bool => $row['global_order_id'] !== $g(1))), 'no production stage, document, payment method or advance status changed (order 1: only its shop event)');
$check($th->smartbillDocuments === [] && $th->fanAwbs === [] && $op->smartbillDocuments === [], 'no invoice issued, no AWB created by a payment verification');
$text = json_encode([$all('SELECT * FROM finance_verification_request_events'), $all('SELECT request_uuid, state, outcome, reason_code, policy, source_entry_id, evidence_hmac FROM finance_verification_requests')], JSON_THROW_ON_ERROR);
$check(!str_contains($text, 'BT FVR') && !str_contains($text, FVR_PHONE) && !str_contains($text, FVR_NAME), 'the request history keeps codes, ids and hashes (no bank reference, no customer)');
$log = (string) file_get_contents($logFile);
$check(!str_contains($log, 'BT FVR') && !str_contains($log, FVR_PHONE) && !str_contains($log, 'RO49AAAA'), 'nothing of the money or the customer reached the server log');
unlink($logFile);

// Leave the shared disposable database as the next suites expect it: no Confirmare, finance, request or invoice state.
foreach (['finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision', 'confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations',
    'invoice_efactura_tasks', 'order_source_operations', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes',
    'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts',
    'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

fwrite(STDOUT, "PASS MySQL finance requests integration: {$checks} checks (explicit requests, task-scoped finance access, no order data, four eyes and the single policy, bank evidence checks, missing payment, clarification, partial payment, amount mismatch, duplicate reference, two tabs, two employees, lost answer, unreadable shop, changed proforma and total, online and legacy orders, operator polling, read-only oversight, revoked scopes, refunds apart, nothing else changed).\n");
