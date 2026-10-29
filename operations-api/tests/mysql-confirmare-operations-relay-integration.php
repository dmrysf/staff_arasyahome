<?php

declare(strict_types=1);

// Operations relayed to the shop (API 2.46.0, migration 043, owner master decisions 2026-10-25) on a disposable database,
// through the HTTP kernel, with in-process TEST shops (no FAN Courier, NETOPIA or SmartBill anywhere). Covers: the
// reactivation of a cancelled order (ordinary once; later only with the money's bank exception and a finance scope), the
// cancellation hold released only after the shop reports the order active, the Confirmare decided again and a new call
// cycle; the FAN Courier AWB from stage 12 only, by the routed shop employee, with the amount the employee saw, one AWB,
// a lost answer settled from the shop's stored answer, the label; the address change task; the NETOPIA full refund
// (operator with the finance approve scope, finance record, ANOTHER employee verifies); the e-Factura follow-up (5
// working days across the DST change and the 30 November / 1 December holidays); the 22 October backfill (orders before
// production taken, production untouched, dry run, idempotent); TBI orders kept in the established flow; shop isolation.

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
    fwrite(STDOUT, "SKIP MySQL Confirmare operations relay integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-ops-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const OPS_KEY = 'trendhome-access-key-test-0123456789abcdef-ops';
const OPS_PHONE = '0744555777';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return is_array($r['body']) ? $r['body'] : []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

final class OpsTestShops implements SourceAccessTransport
{
    /** @param array<string, SourceAccessFakeShop> $shops */
    public function __construct(public array $shops)
    {
    }

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        return $this->shops[$baseUrl]->post($baseUrl, $path, $headers, $body);
    }
}

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . OPS_KEY, 'finance' => 'record', 'invoice' => 'enforce'],
    'outletperdele' => ['mode' => 'read', 'url' => 'https://outletperdele.example', 'key' => 'op-test:' . OPS_KEY . '-outlet', 'finance' => 'record', 'invoice' => 'enforce'],
]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
$cycleTables = ['confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations'];
$clear = static function () use ($pdo, $cycleTables): void {
    // API 2.50.0: finance tasks point at orders (the card refund task of section 4).
    foreach (['finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision'] as $table) {
        try {
            $pdo->exec("DELETE FROM {$table}");
        } catch (PDOException) {
            // Before migration 044.
        }
    }
    foreach ([...$cycleTables, 'order_cancellation_sync_attempts', 'order_cancellation_sync_operations', 'order_cancellation_events', 'order_cancellation_controls',
        'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes',
        'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts',
        'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    $pdo->exec("UPDATE operational_orders SET cancellation_state = NULL, production_stopped_at = NULL WHERE cancellation_state IS NOT NULL");
    $pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');
};
$clear();

// ---- 1. Migration 043: additive, re-runnable, fail closed before it ------------------------------------------------------
$check((string) $one("SELECT migration_name FROM schema_migrations WHERE migration_name = '043_confirmare_dual_store.sql'") === '043_confirmare_dual_store.sql', 'migration 043 is applied by the normal migration run');
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('" . implode("', '", $cycleTables) . "')") === 6, 'migration 043 creates its six tables');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/043_confirmare_dual_store.sql');
$check((int) $one('SELECT COUNT(*) FROM confirmare_proforma_cycles') === 0 && (int) $one('SELECT COUNT(*) FROM confirmare_bank_checks') === 0, 'running 043 again changes nothing; no row is written by the migration');

$th = new SourceAccessFakeShop('trendhome', 'th-test', OPS_KEY);
$op = new SourceAccessFakeShop('outletperdele', 'op-test', OPS_KEY . '-outlet');
$opsShops = new OpsTestShops(['https://trendhome.example' => $th, 'https://outletperdele.example' => $op]);
$container = new Container($config, $pdo, $opsShops);
$kernel = $container->kernel();
$service = $container->confirmareService();
$cycles = $container->confirmareCycles();
$operations = $container->confirmareOperations();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "ops.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-ops-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Ops root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Ops root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'ops-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'ops test passphrase 2026';
$uuids = [];
$identity = static function (string $name) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-ops');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
};
foreach (['cop', 'csup', 'cout', 'cfin', 'cfinview', 'cfinout', 'cmgr', 'cdoc', 'cdoc2', 'cappr', 'cappr2', 'cfin2'] as $name) $identity($name);
$ok($asRoot('PUT', "/management/employees/{$uuids['cop']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'confirmare cop');
$ok($asRoot('PUT', "/management/employees/{$uuids['csup']}/confirmare-scopes", ['operate' => [], 'supervise' => ['trendhome']]), 'confirmare csup');
$ok($asRoot('PUT', "/management/employees/{$uuids['cout']}/confirmare-scopes", ['operate' => ['outletperdele'], 'supervise' => []]), 'confirmare cout');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfin']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => []]), 'finance cfin');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfinview']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []]), 'finance cfinview');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfinout']}/finance-scopes", ['view' => ['outletperdele'], 'record' => ['outletperdele'], 'approve' => []]), 'finance cfinout');
$ok($asRoot('PUT', "/management/employees/{$uuids['cmgr']}/invoice-scopes", ['issue' => [], 'manage' => ['trendhome']]), 'invoice manager cmgr');
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
foreach (['cdoc', 'cdoc2'] as $name) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/roles", ['roleIds' => [$roleIds['production-documents-operator']]]), "{$name} roles");
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), "documents {$name}");
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]), "invoice {$name}");
}
// The operator who refunds holds the finance approve scope of the shop; two finance employees record and verify.
$ok($asRoot('PUT', "/management/employees/{$uuids['cappr']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => ['trendhome']]), 'finance cappr');
$ok($asRoot('PUT', "/management/employees/{$uuids['cappr2']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => ['trendhome']]), 'finance cappr2');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfin2']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => []]), 'finance cfin2');
$who = [];
foreach (array_keys($uuids) as $name) $who[$name] = T::login($kernel, "{$name}.{$suffix}", $password);

// ---- Fixtures --------------------------------------------------------------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare operations relay integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'ops test', 'ops-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'ops test', 'ops-test', true);
}
$numberBase = T::freeOrderBase($pdo, 900000, 929999, 100);
$id = static fn (int $n): string => (string) ($numberBase + $n);
$g = static fn (int $n, string $src = 'trendhome'): string => $src . ':' . $id($n);
$tick = 0;
$payload = static function (string $orderId, int $seconds) use ($suffix, &$tick, $iso, $activatedAt): array {
    $tick++;
    $p = T::sourceOrder($orderId, "ops-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', [[
        'id' => 9501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-303', 'color' => 'Gri', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $pricing = 'sha256:' . hash('sha256', "ops-price-{$orderId}");
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Operatii', 'street' => 'Str. Exemplu 9', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => OPS_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify("{$seconds} seconds")), 'evidence' => 'checkout'];
    return $p;
};
foreach (range(1, 9) as $n) {
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 60 + $n)), "trendhome order {$n}");
    // The customer did not confirm anything yet: no telephone acceptance of the total.
    $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => true, 'phone' => OPS_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'ops-price-' . $id($n)),
        'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
}
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), 120)), 'outlet order');
$op->orders[$id(50)] = ['id' => $id(50), 'number' => $id(50), 'eligible' => true, 'phone' => OPS_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'ops-price-' . $id(50)), 'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
$check($service->distribute()['assigned'] >= 10, 'the orders go to their shop\'s operator');
$call = static fn (array $w, string $method, string $path, ?array $body = null, ?string $key = null): array => T::call($kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => $key ?? 'ops-' . bin2hex(random_bytes(10))], $w['cookie']);
$get = static fn (array $w, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $w['cookie']);
$version = static fn (string $gid): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$gid]);
$detail = static function (array $w, string $gid) use ($get, $ok, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $ok($get($w, '/confirmare/orders/' . rawurlencode($gid)), "detail {$gid}"); };
$contact = static function (array $w, string $gid, string $outcome) use ($call, $detail, $version, $pdo): array {
    $review = $detail($w, $gid)['review'];
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return $call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/contact', ['expectedVersion' => $version($gid), 'outcome' => $outcome, 'reviewFingerprint' => $review['fingerprint']]);
};
$orderUuid = static fn (string $gid): string => (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$gid]);
$cycleOf = static fn (string $gid): ?array => ($r = $all('SELECT * FROM confirmare_proforma_cycles WHERE global_order_id = ? ORDER BY cycle_number DESC LIMIT 1', [$gid])) === [] ? null : $r[0];
// TEST ONLY: the timer is moved into the past instead of waiting days.
$age = static function (string $gid, int $hours) use ($pdo): void {
    $pdo->prepare('UPDATE confirmare_proforma_cycles SET sent_at = DATE_SUB(sent_at, INTERVAL ? HOUR), expires_at = DATE_SUB(expires_at, INTERVAL ? HOUR) WHERE global_order_id = ? AND active_order_uuid IS NOT NULL')->execute([$hours, $hours, $gid]);
};
$run = static fn (): array => $cycles->run(100, 'test-cycles');
$reminders = static fn (int $n): int => $th->smartbillReminders['pf:' . $id($n)] ?? 0;
$cancels = static fn (int $n): int => $th->smartbillCancels['pf:' . $id($n)] ?? 0;
$proformas = static fn (int $n): int => $th->smartbillDocuments['pf:' . $id($n)] ?? 0;

$operate = static function (array $w, string $gid, string $path, array $body = [], ?string $key = null) use ($kernel, $pdo): array {
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/' . $path, null, ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'content-type' => 'application/json',
        'idempotency-key' => $key ?? 'ops-' . bin2hex(random_bytes(10))], $w['cookie'], [], $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR));
};
$state = static function (array $w, string $gid) use ($get, $ok, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $ok($get($w, '/confirmare/orders/' . rawurlencode($gid) . '/operations'), "operations {$gid}"); };
$refusedOp = static function (array $r, string $reason, string $label, string $code = 'CONFIRMARE_OPERATION_NOT_ALLOWED') use ($check): void {
    $check($r['status'] === 409 && ($r['body']['error']['code'] ?? '') === $code && ($r['body']['error']['details']['reason'] ?? '') === $reason, "{$label}: expected {$code} {$reason}, got {$r['status']} " . json_encode($r['body']));
};
// A shop event of the order with another WooCommerce status (TEST ONLY: the shop's own connector sends these).
$event = static function (int $n, string $status, string $availability) use ($payload, $id, $kernel, $ok): void {
    $p = $payload($id($n), 60 + $n);
    $p['order']['status'] = ['code' => $status, 'label' => ucfirst($status)];
    $p['order']['availability'] = $availability;
    $ok(T::ingest($kernel, 'trendhome', $p), "order {$n} becomes {$status}");
};
$approve = static function (array $w, string $gid) use ($detail, $call, $get, $version, $ok, $pdo): void {
    $review = $detail($w, $gid)['review'];
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    $ok($get($w, '/confirmare/orders/' . rawurlencode($gid) . '/source'), "shop order {$gid}");
    $ok($call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/contact', ['expectedVersion' => $version($gid), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]), "call {$gid}");
    $ok($call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/approve', ['expectedVersion' => $version($gid), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]), "approve {$gid}");
};

// ---- 2. Reactivation: once without money, later only with the money that arrived --------------------------------------------
$error($operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_returned']), 409, 'CONFIRMARE_OPERATION_NOT_ALLOWED', 'an order that is not cancelled cannot be reactivated');
$approve($who['cop'], $g(1));
$event(1, 'cancelled', 'cancelled');
$th->orders[$id(1)]['ops'] = ['status' => 'cancelled', 'exceptions' => ['bankexc0001']];
$check((string) $one('SELECT cancellation_state FROM operational_orders WHERE global_order_id = ?', [$g(1)]) === 'held', 'the shop cancelled the order: production is held');
$error($operate($who['cout'], $g(1), 'reactivate', ['reason' => 'customer_returned']), 404, 'CONFIRMARE_NOT_FOUND', 'another shop\'s operator never reaches it');
// API 2.50.0: a finance scope reaches an order only through a finance task, so a viewer without one never sees it.
$error($operate($who['cfinview'], $g(1), 'reactivate', ['reason' => 'customer_returned']), 404, 'CONFIRMARE_NOT_FOUND', 'finance alone does not reactivate (no finance task: not found)');
$error($operate($who['cop'], $g(1), 'reactivate', ['reason' => 'because']), 422, 'VALIDATION_FAILED', 'the reason is one of the two codes');
$first = $ok($operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_returned']), 'the customer came back: first reactivation');
$check($first['state'] === 'applied' && $first['result'] === 'reactivated' && $th->orders[$id(1)]['ops']['status'] === 'on-hold', 'the shop put the order back on hold');
$check((string) $one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$g(1)]) === 'pending' && (string) $one("SELECT reason_code FROM order_confirmation_events WHERE global_order_id = ? AND action = 'reopened' ORDER BY occurred_at DESC LIMIT 1", [$g(1)]) === 'reactivated',
    'the approval no longer counts: the order is decided again with the customer (prices, measures, money)');
$check((string) $one('SELECT cancellation_state FROM operational_orders WHERE global_order_id = ?', [$g(1)]) === 'held' && $operations->settleReactivations() === 0, 'the hold stays until the shop\'s own event says the order is active again');
$event(1, 'on-hold', 'active');
$check($operations->settleReactivations() === 1 && (string) $one('SELECT cancellation_state FROM operational_orders WHERE global_order_id = ?', [$g(1)]) === 'released', 'after the shop\'s event the hold is released once');
$check($operations->settleReactivations() === 0, 'settling again does nothing');
$check(\Arasya\Operations\Confirmare\ConfirmareCycles::callState($pdo, $orderUuid($g(1)))['noAnswer'] === 0 && \Arasya\Operations\Confirmare\ConfirmareCycles::callState($pdo, $orderUuid($g(1)))['boundary'] !== null, 'a new call cycle starts after the reactivation');
$event(1, 'cancelled', 'cancelled');
$th->orders[$id(1)]['ops']['status'] = 'cancelled';
$refusedOp($operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_returned']), 'payment_required', 'a second reactivation without money is refused (K-07)');
$refusedOp($operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_paid_late', 'exceptionEntryId' => 'bankexc0001']), 'finance_scope_required', 'the money is named, but the operator holds no finance scope');
$ok($asRoot('PUT', "/management/employees/{$uuids['cop']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []]), 'the operator also reviews finance (owner K-08)');
$who['cop'] = T::login($kernel, "cop.{$suffix}", $password);
$missing = $operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_paid_late', 'exceptionEntryId' => 'bankexc9999']);
$check($missing['status'] === 409 && ($missing['body']['error']['code'] ?? '') === 'CONFIRMARE_OPERATION_REFUSED' && ($missing['body']['error']['details']['reason'] ?? '') === 'payment_not_found', 'the shop finds no such bank exception: refused');
$paid = $ok($operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_paid_late', 'exceptionEntryId' => 'bankexc0001']), 'second reactivation with the money that arrived');
$check($paid['result'] === 'reactivated' && $all("SELECT kind, payment_entry_id, state FROM confirmare_reactivations WHERE global_order_id = ? ORDER BY sequence_number", [$g(1)]) === [
    ['kind' => 'ordinary', 'payment_entry_id' => null, 'state' => 'applied'], ['kind' => 'paid_exception', 'payment_entry_id' => 'bankexc9999', 'state' => 'rejected'], ['kind' => 'paid_exception', 'payment_entry_id' => 'bankexc0001', 'state' => 'applied']]
    || $check(false, 'reactivations: ' . json_encode($all("SELECT kind, payment_entry_id, state FROM confirmare_reactivations WHERE global_order_id = ? ORDER BY sequence_number", [$g(1)]))), 'every reactivation is recorded with its kind and the payment that justified it');

// Production already started: never reactivated from Confirmare (a manager decides the factory's cancellation release).
$pdo->prepare('UPDATE operational_orders SET production_stage_id = ? WHERE global_order_id = ?')->execute(['material-preparation', $g(1)]);
$event(1, 'cancelled', 'cancelled');
$th->orders[$id(1)]['ops']['status'] = 'cancelled';
$started = $operate($who['cop'], $g(1), 'reactivate', ['reason' => 'customer_paid_late', 'exceptionEntryId' => 'bankexc0001']);
$check($started['status'] === 409 && ($started['body']['error']['code'] ?? '') === 'CONFIRMARE_OPERATION_NOT_ALLOWED' && in_array('production_started', $started['body']['error']['details']['blockers'] ?? [], true),
    'an order whose production started is never reactivated here: ' . json_encode($started['body']['error'] ?? null));
$pdo->prepare('UPDATE operational_orders SET production_stage_id = ? WHERE global_order_id = ?')->execute([\Arasya\Operations\Order\OrderProjectionWriter::INITIAL_STAGE_ID, $g(1)]);

// ---- 3. FAN Courier AWB: stage 12, the routed shop employee, the amount seen, one AWB ------------------------------------------
$approve($who['cop'], $g(2));
// TEST ONLY: production moved the order (the factory's own stages are tested elsewhere) and the operator routed it.
$route = static function (string $gid, string $to) use ($pdo, $orderUuid, $uuids): void {
    $pdo->prepare("INSERT INTO order_shop_assignments (assignment_uuid, order_uuid, global_order_id, source_key, employee_uuid, assigned_by_employee_uuid, assigned_role, idempotency_key, request_hmac, state, assigned_at)
        VALUES (?, ?, ?, 'trendhome', ?, ?, 'operator', ?, ?, 'active', UTC_TIMESTAMP(6))")->execute([\Arasya\Operations\Support\Uuid::v4(), $orderUuid($gid), $gid, $uuids[$to], $uuids['cop'], 'ops-route-' . bin2hex(random_bytes(8)), random_bytes(32)]);
};
$stage = static function (string $gid, string $stage) use ($pdo): void { $pdo->prepare('UPDATE operational_orders SET production_stage_id = ?, production_changed_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?')->execute([$stage, $gid]); };
$route($g(2), 'cdoc');
$stage($g(2), 'sewing-finishing');
$th->orders[$id(2)]['ops'] = ['owed' => '503.30'];
$check($ok($get($who['cdoc'], '/confirmare/awb-queue'), 'queue at stage 11')['items'] === [], 'stage 11: not in the AWB queue');
$refusedOp($operate($who['cdoc'], $g(2), 'awb', ['parcels' => 1, 'expectedCod' => '503.30']), 'stage_not_ready', 'stage 11: no AWB');
$stage($g(2), 'quality-control');
$queue = $ok($get($who['cdoc'], '/confirmare/awb-queue'), 'queue at stage 12')['items'];
$check(count($queue) === 1 && $queue[0]['globalOrderId'] === $g(2) && $queue[0]['stage'] === 'quality-control', 'stage 12: the routed shop employee sees it in "AWB oluşturulması gereken siparişler"');
$check($ok($get($who['cdoc2'], '/confirmare/awb-queue'), 'another employee')['items'] === [] && count($ok($get($who['cmgr'], '/confirmare/awb-queue'), 'manager')['items']) === 1, 'another shop employee does not see it; the invoice manager does');
$error($operate($who['cdoc2'], $g(2), 'awb', ['parcels' => 1, 'expectedCod' => '503.30']), 403, 'CONFIRMARE_OPERATION_DENIED', 'a shop employee the order was not routed to cannot make its AWB');
$error($operate($who['cop'], $g(2), 'awb', ['parcels' => 1, 'expectedCod' => '503.30']), 403, 'CONFIRMARE_OPERATION_DENIED', 'the operator never makes the AWB');
$s2 = $state($who['cdoc'], $g(2));
$check($s2['available'] && $s2['awbStage'] && $s2['allowed']['awb'] && $s2['shop']['awb']['state'] === 'none' && $s2['shop']['awb']['cod'] === '503.30', 'the employee reads the collection the shop computed (what is still owed): ' . json_encode($s2['shop']['awb'] ?? null));
$changed = $operate($who['cdoc'], $g(2), 'awb', ['parcels' => 3, 'expectedCod' => '500.00']);
$check($changed['status'] === 409 && ($changed['body']['error']['details']['reason'] ?? '') === 'cod_changed' && ($th->fanAwbs[$id(2)] ?? 0) === 0, 'an amount the employee did not see is refused; FAN Courier is not asked');
$awbKey = 'ops-awb-' . bin2hex(random_bytes(10));
$th->awbOutcome = 'lost';
$lost = $ok($operate($who['cdoc'], $g(2), 'awb', ['parcels' => 3, 'expectedCod' => '503.30'], $awbKey), 'AWB: FAN Courier made it, the answer was lost');
$check($lost['state'] === 'unknown' && ($th->fanAwbs[$id(2)] ?? 0) === 1, 'the outcome is unknown: nothing is sent again');
$refusedOp($operate($who['cdoc'], $g(2), 'awb', ['parcels' => 3, 'expectedCod' => '503.30']), 'operation_open', 'a second click while the first is unknown is refused');
$settledAwb = $ok($operate($who['cdoc'], $g(2), 'operations/reconcile', ['idempotencyKey' => $awbKey]), 'reconcile the lost answer');
$check($settledAwb['state'] === 'applied' && $settledAwb['result'] === 'created' && ($th->fanAwbs[$id(2)] ?? 0) === 1 && $th->orders[$id(2)]['ops']['awb']['parcels'] === 3 && $th->orders[$id(2)]['ops']['awb']['cod'] === '503.30',
    'settled from the shop\'s stored answer: ONE AWB, 3 parcels, the collection once (503.30 for all parcels)');
$check($ok($get($who['cdoc'], '/confirmare/awb-queue'), 'queue after the AWB')['items'] === [], 'the order leaves the AWB queue');
$again = $operate($who['cdoc'], $g(2), 'awb', ['parcels' => 1, 'expectedCod' => '503.30']);
$check($again['status'] === 409 && ($again['body']['error']['details']['reason'] ?? '') === 'awb_exists' && ($th->fanAwbs[$id(2)] ?? 0) === 1, 'a second AWB for the order is refused by the shop');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$label = T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($g(2)) . '/awb/label', null, ['origin' => T::ORIGIN], $who['cdoc']['cookie']);
$check($label['status'] === 200 && str_starts_with((string) $label['raw'], '%PDF') && ($label['headers']['Content-Type'] ?? '') === 'application/pdf' && ($label['headers']['Cache-Control'] ?? '') === 'private, no-store', 'the AWB label is served as a PDF to the shop employee, never cached');
$error(T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($g(2)) . '/awb/label', null, ['origin' => T::ORIGIN], $who['cop']['cookie']), 403, 'CONFIRMARE_OPERATION_DENIED', 'the operator does not print the AWB');
$error($operate($who['cdoc'], $g(2), 'awb/address', ['step' => 'done', 'reasonNote' => 'Adresa schimbată']), 409, 'CONFIRMARE_OPERATION_REFUSED', 'no declaration without the task');
$ok($operate($who['cdoc'], $g(2), 'awb/address', ['step' => 'task', 'reasonNote' => 'Clientul s-a mutat: Str. Nouă 5']), 'urgent address change: FAN Courier panel task');
$done = $ok($operate($who['cdoc'], $g(2), 'awb/address', ['step' => 'done', 'reasonNote' => 'Verificat în panoul FAN Courier']), 'the employee declares the change made in the panel');
$check($done['result'] === 'panel_declared' && array_column($done['shop']['awb']['address'], 'kind') === ['task', 'done'] && $done['shop']['awb']['pickup'] === 'not_tracked', 'recorded as the employee\'s declaration, never as a carrier confirmation; pickup is a separate state');
// A request that never reached the shop: unknown at first (its answer may still arrive), "not received" only after 4 minutes.
$th->during = static function (array $input): void {
    if (($input['action'] ?? null) === 'awb_address_task') {
        throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
    }
};
$lostKey = 'ops-lost-' . bin2hex(random_bytes(8));
$never = $ok($operate($who['cdoc'], $g(2), 'awb/address', ['step' => 'task', 'reasonNote' => 'Al doilea apel'], $lostKey), 'the request never reached the shop');
$th->during = null;
$soon = $ok($operate($who['cdoc'], $g(2), 'operations/reconcile', ['idempotencyKey' => $lostKey]), 'reconcile at once');
$check($never['state'] === 'unknown' && $soon['state'] === 'unknown' && ($soon['reachable'] ?? null) === true, 'the shop has no answer yet: still unknown, never declared lost at once: ' . json_encode([$never, $soon]));
$pdo->prepare('UPDATE order_source_operations SET requested_at = DATE_SUB(requested_at, INTERVAL 5 MINUTE) WHERE idempotency_key = ?')->execute([$lostKey]);
$late = $operate($who['cdoc'], $g(2), 'operations/reconcile', ['idempotencyKey' => $lostKey]);
$check($late['status'] === 409 && ($late['body']['error']['details']['reason'] ?? '') === 'not_received' && (string) $one('SELECT state FROM order_source_operations WHERE idempotency_key = ?', [$lostKey]) === 'rejected',
    'after 4 minutes: closed as not received, nothing was done in the shop');
$check(!str_contains(json_encode($all('SELECT kind, state, outcome, result, request_id FROM order_source_operations')), 'Str. Nou'), 'no address text is stored in Arasya');

// ---- 4. NETOPIA full refund: one click, finance records, another employee verifies --------------------------------------------
$event(3, 'cancelled', 'cancelled');
$th->orders[$id(3)]['ops'] = ['status' => 'cancelled', 'netopia' => 2, 'net' => '719.00'];
$error($operate($who['cop'], $g(3), 'card-refund'), 403, 'CONFIRMARE_OPERATION_DENIED', 'an operator without the finance approve scope cannot start a refund');
$ok($asRoot('PUT', "/management/employees/{$uuids['cappr']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'confirmare cappr');
$pdo->prepare('UPDATE order_confirmations SET assigned_employee_uuid = ? WHERE global_order_id = ?')->execute([$uuids['cappr'], $g(3)]);
$who['cappr'] = T::login($kernel, "cappr.{$suffix}", $password);
$refundKey = 'ops-refund-' . bin2hex(random_bytes(10));
$req = $ok($operate($who['cappr'], $g(3), 'card-refund', [], $refundKey), 'one click: "Cere rambursarea integrală (manual, în panoul NETOPIA)"');
$check($req['result'] === 'manual_required' && $req['shop']['cardRefund']['requests'][0]['amount'] === '719.00' && $req['shop']['cardRefund']['requests'][0]['transactions'] === 2,
    'NETOPIA has no refund API: a manual task for the whole 719.00 over both card transactions');
$twice = $ok($operate($who['cappr'], $g(3), 'card-refund'), 'a second click');
$check($twice['result'] === 'already_requested' && count($th->orders[$id(3)]['ops']['refunds']) === 1, 'a second click creates nothing new');
$error($operate($who['cappr'], $g(3), 'card-refund/record', ['amount' => '719.00', 'reference' => 'NTP-REF-1', 'valueDate' => '2026-10-25']), 403, 'CONFIRMARE_OPERATION_DENIED', 'the approver does not record (record scope)');
// API 2.50.0: record or approve alone reaches no order; the requested card refund becomes a finance task of named
// employees through the task inventory (assigned to a record holder, its approver named as checker).
$error($operate($who['cfin'], $g(3), 'card-refund/record', ['amount' => '719.00', 'reference' => 'NTP-REF-1', 'valueDate' => '2026-10-25']), 404, 'CONFIRMARE_NOT_FOUND', 'before the task exists, finance does not reach the order');
$inventoried = $container->financeTaskInventory()->apply(['trendhome'], 'relay-test');
$refundTasks = $pdo->prepare("SELECT * FROM finance_verification_requests WHERE purpose = 'exception' AND task_code = 'card_refund_verify' AND global_order_id = ?");
$refundTasks->execute([$g(3)]);
$refundTask = $refundTasks->fetch(PDO::FETCH_ASSOC) ?: [];
$check($inventoried['created'] >= 1 && in_array($refundTask['assigned_employee_uuid'] ?? null, [$uuids['cfin'], $uuids['cfin2']], true) && $refundTask['checker_employee_uuid'] !== null
    && $refundTask['checker_employee_uuid'] !== $refundTask['assigned_employee_uuid'], 'the card refund is a task of a finance employee who records, with another named approver');
// The inventory picks the least loaded employees; this TEST pins `cfin` as the recorder and `cfin2` (granted approve
// below) as the named checker.
$pdo->prepare('UPDATE finance_verification_requests SET assigned_employee_uuid = ?, checker_employee_uuid = ? WHERE request_uuid = ?')->execute([$uuids['cfin'], $uuids['cfin2'], $refundTask['request_uuid']]);
$rec = $ok($operate($who['cfin'], $g(3), 'card-refund/record', ['amount' => '719.00', 'reference' => 'NTP-REF-1', 'valueDate' => '2026-10-25']), 'finance records the panel refund');
$entry = $th->orders[$id(3)]['ops']['ledger'][0]['id'];
$check($rec['result'] === 'refund_recorded', 'recorded, not yet refunded');
$error($operate($who['cfin'], $g(3), 'card-refund/verify', ['entryId' => $entry, 'decision' => 'verify']), 403, 'CONFIRMARE_OPERATION_DENIED', 'the recorder cannot verify (approve scope)');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfin2']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => ['trendhome']]), 'cfin2 also approves');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfin']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => ['trendhome']]), 'cfin also approves');
$who['cfin'] = T::login($kernel, "cfin.{$suffix}", $password);
$who['cfin2'] = T::login($kernel, "cfin2.{$suffix}", $password);
$same = $operate($who['cfin'], $g(3), 'card-refund/verify', ['entryId' => $entry, 'decision' => 'verify']);
$check($same['status'] === 409 && ($same['body']['error']['details']['reason'] ?? '') === 'same_employee', 'four eyes: the employee who recorded it never verifies it');
$ver = $ok($operate($who['cfin2'], $g(3), 'card-refund/verify', ['entryId' => $entry, 'decision' => 'verify']), 'another employee verifies');
$check($ver['result'] === 'refund_verified' && $ver['shop']['cardRefund']['requests'][0]['state'] === 'completed' && $ver['shop']['cardRefund']['requests'][0]['mailed'] === true, 'completed; the customer is e-mailed (Romanian) after the verification');

// ---- 5. e-Factura: 5 working days, DST and the national holidays ---------------------------------------------------------
$efactura = new \Arasya\Operations\Confirmare\InvoiceEfactura($pdo, $container->clock(), new \Arasya\Operations\Confirmare\InvoiceScopePolicy($pdo));
$due = static fn (string $at): string => \Arasya\Operations\Confirmare\InvoiceEfactura::due(new DateTimeImmutable($at))->format('Y-m-d\TH:i:s\Z');
$check($due('2026-10-23T10:00:00Z') === '2026-10-30T21:59:59Z', 'issued Friday 23 October (EEST): due Friday 30 October 23:59:59 Bucharest, after the DST change (EET): ' . $due('2026-10-23T10:00:00Z'));
$check($due('2026-11-27T10:00:00Z') === '2026-12-08T21:59:59Z', 'issued Friday 27 November: 30 November and 1 December are holidays, due 8 December: ' . $due('2026-11-27T10:00:00Z'));
$check($due('2026-10-24T20:30:00Z') === '2026-10-30T21:59:59Z', 'issued Saturday night (Bucharest 23:30): the five working days start on Monday');
$actionUuid = \Arasya\Operations\Support\Uuid::v4();
$efactura->onInvoiceIssued($actionUuid, $orderUuid($g(2)), $g(2), 'trendhome', $uuids['cdoc']);
$efactura->onInvoiceIssued($actionUuid, $orderUuid($g(2)), $g(2), 'trendhome', $uuids['cdoc']);
$tasks = $ok($get($who['cdoc'], '/confirmare/efactura'), 'issuer tasks')['items'];
$check(count($tasks) === 1 && $tasks[0]['state'] === 'open' && $tasks[0]['next'] === ['sent', 'accepted', 'rejected'], 'one task per invoice for the issuer');
$check($ok($get($who['cdoc2'], '/confirmare/efactura'), 'other employee')['items'] === [] && count($ok($get($who['cmgr'], '/confirmare/efactura'), 'manager')['items']) === 1, 'another employee does not see it; the invoice manager does');
$error($call($who['cdoc'], 'POST', '/confirmare/efactura/' . $tasks[0]['id'], ['outcome' => 'resolved', 'expectedVersion' => 1]), 409, 'EFACTURA_TRANSITION_REFUSED', 'an outcome that does not follow is refused');
$sent = $ok($call($who['cdoc'], 'POST', '/confirmare/efactura/' . $tasks[0]['id'], ['outcome' => 'sent', 'expectedVersion' => 1]), 'transmitted, ANAF answer pending');
$error($call($who['cdoc'], 'POST', '/confirmare/efactura/' . $tasks[0]['id'], ['outcome' => 'accepted', 'expectedVersion' => 1]), 409, 'EFACTURA_TASK_CHANGED', 'a stale version is refused');
$acc = $ok($call($who['cdoc'], 'POST', '/confirmare/efactura/' . $tasks[0]['id'], ['outcome' => 'accepted', 'expectedVersion' => 2]), 'ANAF validated');
$check($sent['task']['state'] === 'sent' && $acc['task']['state'] === 'accepted' && $acc['task']['next'] === [], 'accepted is final; recorded by a person, never automatically');
$pdo->prepare('UPDATE invoice_efactura_tasks SET issued_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 8 DAY), due_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR), state = ?, recorded_at = NULL, recorded_by_employee_uuid = NULL, outcome_code = NULL WHERE task_uuid = ?')->execute(['open', $tasks[0]['id']]);
$check($ok($get($who['cdoc'], '/confirmare/efactura'), 'overdue')['overdue'] === 1, 'an open task past its due instant is overdue');

// ---- 6. The 22 October backfill ---------------------------------------------------------------------------------------------
// API 2.48.0: the shop adopts every order Arasya takes (YD SOFT 2.22.0), signed with its source access key.
$backfill = new \Arasya\Operations\Confirmare\ConfirmareBackfill($pdo, $container->clock(), \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($config),
    new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($opsShops, $container->clock()), $uuids['csup']);
$shopOrder = static function (int $n, array $extra = []) use ($th, $id): void { $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => false, 'phone' => OPS_PHONE] + $extra; };
foreach ([...range(60, 67), ...range(80, 83)] as $n) $shopOrder($n);
// TEST ONLY: CI runs the suites on one shared database. The backfill acts on every order of a source, so the orders the
// earlier suites left (no cohort, in the window) are parked under a reason the backfill never selects, and restored after.
$parked = $all("SELECT order_uuid, workflow_cohort, workflow_cohort_reason, workflow_cohort_at FROM operational_orders o
    WHERE o.source_key IN ('trendhome', 'outletperdele') AND NOT (CHAR_LENGTH(o.order_number) = ? AND o.order_number BETWEEN ? AND ?) AND NOT EXISTS (SELECT 1 FROM order_confirmations c WHERE c.order_uuid = o.order_uuid)
      AND (o.workflow_cohort IS NULL OR (o.workflow_cohort = 'legacy' AND o.workflow_cohort_reason IN ('not_activated', 'submitted_before_activation', 'financing_legacy')))", [strlen($id(0)), $id(0), $id(99)]);
$park = $pdo->prepare("UPDATE operational_orders SET workflow_cohort = 'legacy', workflow_cohort_reason = 'test_parked', workflow_cohort_at = UTC_TIMESTAMP(6) WHERE order_uuid = ?");
foreach ($parked as $row) $park->execute([$row['order_uuid']]);
$legacyAt = $cutover->modify('+1 hour');
foreach ([60 => 'waiting', 61 => 'material-preparation', 62 => 'waiting'] as $n => $stageId) {
    $p = $payload($id($n), 0);
    $p['order']['submission'] = ['submittedAt' => $iso(min($legacyAt, $activatedAt->modify('-1 minute'))), 'evidence' => 'checkout'];
    $ok(T::ingest($kernel, 'trendhome', $p), "order {$n} submitted between 22 October and the activation");
    $pdo->prepare('UPDATE operational_orders SET production_stage_id = ? WHERE global_order_id = ?')->execute([$stageId, $g($n)]);
}
$event(62, 'cancelled', 'cancelled');
$check((string) $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$g(60)]) === 'legacy', 'submitted before the activation: legacy by the rule of 22 October');
$dry = $backfill->run('trendhome', false);
$check($dry['counts']['taken'] === 1 && $dry['counts']['in_production'] === 1 && $dry['counts']['closed'] === 1 && $dry['applied'] === 0 && (string) $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$g(60)]) === 'legacy',
    'dry run: one to take, one in production, one cancelled; nothing changed: ' . json_encode($dry['counts']));
$applied = $backfill->run('trendhome', true);
$check($applied['applied'] === 1 && (string) $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$g(60)]) === 'confirmare' && (string) $one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$g(60)]) === 'pending',
    'apply: the order before production is taken into Confirmare (pending)');
$check((string) $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$g(61)]) === 'legacy' && (string) $one('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ?', [$g(61)]) === 'material-preparation',
    'the order already in production keeps its stage in the established flow');
$check($backfill->run('trendhome', true)['applied'] === 0, 'running it again changes nothing (idempotent)');
$check($cycleOf($g(60)) === null, 'no proforma timer starts for a backfilled order');
$check(($th->adoptions[$id(60)] ?? 0) === 1 && $th->orders[$id(60)]['eligible'] === true && !isset($th->adoptions[$id(61)]) && !isset($th->adoptions[$id(62)]),
    'API 2.48.0: the shop adopted the taken order once (it is now a Confirmare order of the shop); the order in production and the cancelled one were never sent to the shop');
$adoptRequests = array_values(array_filter($th->requests, static fn (array $r): bool => $r['route'] === '/yd-arasya/v1/confirmare/adopt'));
$check(count($adoptRequests) === 2 && $adoptRequests[0]['body']['dryRun'] === true && $adoptRequests[1]['body']['dryRun'] === false && $adoptRequests[1]['body']['actor'] === ['employeeId' => $uuids['csup'], 'role' => 'backfill']
    && $adoptRequests[1]['body']['globalOrderId'] === $g(60) && $adoptRequests[1]['body']['orderId'] === $id(60),
    'the dry run only asked the shop (dryRun), the apply adopted with the backfill actor, the shop order id and the Arasya identity');
$check((string) $one("SELECT request_id FROM order_confirmation_events WHERE global_order_id = ? AND action = 'opened'", [$g(60)]) === $adoptRequests[1]['body']['requestId'], 'the Confirmare opening names the shop adoption request (audit across both sides)');

// The real go-live order of events: the orders of the window reached Arasya BEFORE API 2.46.0 (no cohort: migration 035 came
// with this release) or after it and before the activation (`legacy`, not_activated), mostly from a shop connector that sent
// neither payment facts nor submission evidence. TEST ONLY: those rows are made as such orders look after the migrations.
$asBefore = static function (int $n, ?DateTimeImmutable $createdAt, ?string $method, bool $noCohort = true) use ($pdo, $g): void {
    // Without payment facts the stored projection hash is the one of a snapshot without them (the hash covers stated facts).
    $payment = $method === null ? "payment_method = NULL, advance_status = NULL, advance_revision = NULL, payment_synced_at = NULL, projection_hash = UNHEX(SHA2('old-connector', 256))" : 'payment_method = ?';
    $pdo->prepare($noCohort
        ? "UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL, source_submitted_at = NULL, source_submission_evidence = NULL, {$payment}, created_at = COALESCE(?, created_at) WHERE global_order_id = ?"
        : "UPDATE operational_orders SET workflow_cohort_reason = 'not_activated', {$payment}, created_at = COALESCE(?, created_at) WHERE global_order_id = ?")
        ->execute([...($method === null ? [] : [$method]), $createdAt?->format('Y-m-d H:i:s.u'), $g($n)]);
};
foreach ([63, 64, 65, 66, 67] as $n) {
    $p = $payload($id($n), 0);
    $p['order']['submission'] = ['submittedAt' => $iso(min($legacyAt, $activatedAt->modify('-1 minute'))), 'evidence' => 'checkout'];
    $ok(T::ingest($kernel, 'trendhome', $p), "order {$n} of the window");
}
$asBefore(63, $legacyAt, 'cod');                          // reached Arasya before the release, COD
$asBefore(64, null, 'cod', false);                         // reached Arasya after the release, before the activation
$asBefore(65, $legacyAt, null);                            // sent by the old connector: no payment facts
$asBefore(66, $legacyAt, 'unverified');                    // a TBI credit or an unproven card payment: no cohort, no financing flag
$asBefore(67, $cutover->modify('-1 hour'), 'cod');         // before 22 October 00:00 Bucharest: outside the window
$dry2 = $backfill->run('trendhome', false);
sort($dry2['orders']['taken']); // 63 and 64 share their instant: the order between them is the uuid's
$check($dry2['counts']['taken'] === 2 && $dry2['orders']['taken'] === [$id(63), $id(64)] && $dry2['orders']['payment_unknown'] === [$id(65)] && $dry2['orders']['unverified_payment'] === [$id(66)]
    && !in_array($id(67), array_merge(...array_values($dry2['orders'])), true) && $dry2['applied'] === 0,
    'dry run of the real sequence: the order without a cohort and the not_activated order are taken; unknown and unverified payments are listed, never taken; an order before the window is not touched: ' . json_encode($dry2['orders']));
$applied2 = $backfill->run('trendhome', true);
$cohortOf = static fn (int $n): array => $all('SELECT workflow_cohort, workflow_cohort_reason FROM operational_orders WHERE global_order_id = ?', [$g($n)])[0];
$check($applied2['applied'] === 2 && $cohortOf(63) === ['workflow_cohort' => 'confirmare', 'workflow_cohort_reason' => 'backfill_22_october'] && $cohortOf(64)['workflow_cohort'] === 'confirmare'
    && (string) $one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$g(63)]) === 'pending',
    'apply: both are Confirmare orders (pending), with the backfill reason');
$check($cohortOf(65)['workflow_cohort'] === null && $cohortOf(66)['workflow_cohort'] === null && $cohortOf(67)['workflow_cohort'] === null
    && $one('SELECT 1 FROM order_confirmations WHERE global_order_id IN (?, ?, ?)', [$g(65), $g(66), $g(67)]) === false, 'the unknown, the unverified and the earlier order are unchanged');
$p = $payload($id(65), 0);
$p['order']['submission'] = ['submittedAt' => $iso($legacyAt), 'evidence' => 'checkout'];
$ok(T::ingest($kernel, 'trendhome', $p), 'the shop\'s backfill sends order 65 again with its payment facts (COD) and its submission instant');
$third = $backfill->run('trendhome', true);
$check($third['applied'] === 1 && $third['orders']['taken'] === [$id(65)] && $cohortOf(65)['workflow_cohort'] === 'confirmare' && $third['orders']['unverified_payment'] === [$id(66)],
    'after the shop sent the facts, the order is taken; the unverified one stays listed: ' . json_encode($third['orders']));
$check($backfill->run('trendhome', true)['applied'] === 0, 'running it again takes nothing twice');

// ---- 6b. API 2.48.0 (owner decision 2026-10-27): the shop's part, the window end, distribution, shop isolation ---------------
$windowOrder = static function (int $n, DateTimeImmutable $at) use ($payload, $iso, $ok, $kernel, $id): void {
    $p = $payload($id($n), 0);
    $p['order']['submission'] = ['submittedAt' => $iso($at), 'evidence' => 'checkout'];
    $ok(T::ingest($kernel, 'trendhome', $p), "order {$n} of the window");
};
$inWindow = min($legacyAt, $activatedAt->modify('-1 minute'));
foreach ([80, 81, 82] as $n) $windowOrder($n, $inWindow);
$shopOrder(80, ['adoptReasons' => ['document_exists', 'awb_exists']]);
$op->orders[$id(82)] = ['id' => $id(82), 'number' => $id(82), 'eligible' => false, 'phone' => OPS_PHONE];   // the same number in the other shop
$noShop = (new \Arasya\Operations\Confirmare\ConfirmareBackfill($pdo, $container->clock()))->run('trendhome', true);
$check($noShop['applied'] === 0 && in_array($id(80), $noShop['orders']['shop_unavailable'], true) && $noShop['refusals'][$id(80)] === ['source_access_not_configured'] && $cohortOf(80)['workflow_cohort'] !== 'confirmare',
    'without the shop (no source access client) nothing is taken: an order is never Confirmare in Arasya alone');
$th->afterAdopt = 'answer_lost';
$lost = $backfill->run('trendhome', true);
$check($lost['refusals'][$id(80)] === ['document_exists', 'awb_exists'] && in_array($id(80), $lost['orders']['shop_refused'], true) && $cohortOf(80)['workflow_cohort'] !== 'confirmare' && !isset($th->adoptions[$id(80)]),
    'the shop refuses an order with a SmartBill document or an AWB: unchanged on both sides, its reasons listed');
$lostOne = array_values(array_intersect([$id(81), $id(82)], $lost['orders']['shop_unavailable']));
$check(count($lostOne) === 1 && ($th->adoptions[$lostOne[0]] ?? 0) === 1 && $one('SELECT 1 FROM order_confirmations WHERE global_order_id = ?', ['trendhome:' . $lostOne[0]]) === false,
    'the shop adopted an order and its answer was lost: Arasya did not take it (the shop only protects it more meanwhile)');
$again = $backfill->run('trendhome', true);
$check(in_array($lostOne[0], $again['orders']['taken'], true) && ($th->adoptions[$lostOne[0]] ?? 0) === 1 && (string) $one('SELECT status FROM order_confirmations WHERE global_order_id = ?', ['trendhome:' . $lostOne[0]]) === 'pending',
    'running it again: the shop answers the adopted order with its record (no second adoption) and Arasya takes it');
$check($op->adoptions === [] && !in_array('/yd-arasya/v1/confirmare/adopt', array_column($op->requests, 'route'), true) && $th->orders[$id(82)]['adopted'] === true && ($op->orders[$id(82)]['adopted'] ?? false) === false,
    'the same order number in the other shop is never asked or adopted (the request goes to the order\'s own shop only)');
$windowOrder(83, $activatedAt->modify('-30 seconds'));
$until = $activatedAt->modify('-1 minute');
$early = $backfill->run('trendhome', false, 500, $until);
$check(!in_array($id(83), array_merge(...array_values($early['orders'])), true) && $early['window']['to'] === $until->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
    'the window ends at --until (the owner\'s 27 October 00:00) when that is before the activation: a later order is not even read');
$check(in_array($id(83), $backfill->run('trendhome', false)['orders']['taken'], true) && !isset($th->adoptions[$id(83)]), 'without --until it would be taken (dry run: nothing written, nothing adopted)');
$pdo->prepare('UPDATE order_confirmations SET assigned_employee_uuid = NULL WHERE global_order_id IN (?, ?)')->execute([$g(60), $g(63)]);
$service->distribute();
$assignee = static fn (int $n): string => (string) $one('SELECT assigned_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$g($n)]);
$operatorsTh = array_column($all("SELECT employee_uuid FROM employee_confirmare_scopes WHERE source_key = 'trendhome' AND capability = 'operate'"), 'employee_uuid');
$check($assignee(60) !== '' && $assignee(63) !== '' && in_array($assignee(60), $operatorsTh, true) && in_array($assignee(63), $operatorsTh, true),
    'the normal distribution gives the backfilled orders to the shop\'s operators, like any new order: ' . json_encode([$assignee(60), $assignee(63), $operatorsTh]));
$unpark = $pdo->prepare('UPDATE operational_orders SET workflow_cohort = ?, workflow_cohort_reason = ?, workflow_cohort_at = ? WHERE order_uuid = ?');
foreach ($parked as $row) $unpark->execute([$row['workflow_cohort'], $row['workflow_cohort_reason'], $row['workflow_cohort_at'], $row['order_uuid']]);

// ---- 7. TBI keeps the established flow (owner K-12) ------------------------------------------------------------------------
$p = $payload($id(70), 300);
$p['order']['payment'] = ['method' => 'unverified', 'advanceStatus' => 'waiting', 'advanceRevision' => 0, 'financing' => true];
unset($p['order']['financial']);
$ok(T::ingest($kernel, 'trendhome', $p), 'a TBI order submitted after the activation');
$check($all('SELECT workflow_cohort, workflow_cohort_reason FROM operational_orders WHERE global_order_id = ?', [$g(70)]) === [['workflow_cohort' => 'legacy', 'workflow_cohort_reason' => 'financing_legacy']]
    && $one('SELECT 1 FROM order_confirmations WHERE global_order_id = ?', [$g(70)]) === false, 'a TBI consumer credit order stays in the established flow (no Confirmare row)');
$p = $payload($id(71), 301);
$p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0, 'financing' => true];
$bad = T::ingest($kernel, 'trendhome', $p);
$check($bad['status'] === 422, 'financing is only stated for an unverified payment');

// ---- 8. Operating health (counts only) -------------------------------------------------------------------------------------
$health = $ok($get($who['cmgr'], '/confirmare/health'), 'health for the invoice manager');
$th8 = $health['sources']['trendhome'] ?? [];
$check(array_keys($health['sources']) === ['trendhome'] && is_int($th8['confirmare']['pending'] ?? null) && $th8['efactura']['overdue'] === 1 && $th8['operationsUnknown'] === 0 && isset($th8['proformaCycles']),
    'the Trend Home invoice manager sees the Trend Home counts only: ' . json_encode($th8));
$check(!str_contains(json_encode($health), $id(2)) && !str_contains(json_encode($health), OPS_PHONE), 'no order number or customer data in the health counts');
$error($get($who['cop'], '/confirmare/health'), 403, 'CONFIRMARE_HEALTH_DENIED', 'an operator does not see the health counts');
$check(array_keys($ok($get($root, '/confirmare/health'), 'root health')['sources']) === ['trendhome', 'outletperdele'], 'root sees both shops (break-glass)');
$rootHealth = $ok($get($root, '/confirmare/health'), 'root health per shop');
$pendingOf = static fn (string $s): int => (int) $one("SELECT COUNT(*) FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.source_key = ? AND c.status = 'pending' AND o.operational_status <> 'unavailable'", [$s]);
$check($rootHealth['sources']['trendhome']['confirmare']['pending'] === $pendingOf('trendhome') && $rootHealth['sources']['outletperdele']['confirmare']['pending'] === $pendingOf('outletperdele') && $pendingOf('outletperdele') !== $pendingOf('trendhome'),
    'each shop counts only its own orders: ' . json_encode([$pendingOf('trendhome'), $pendingOf('outletperdele')]));

// ---- Cleanup (later suites share the database in CI) ------------------------------------------------------------------------
$clear();
fwrite(STDOUT, "MySQL Confirmare operations relay integration: {$checks} checks passed.\n");
