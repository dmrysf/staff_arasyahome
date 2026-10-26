<?php

declare(strict_types=1);

// SmartBill invoicing of Confirmare orders (API 2.43.0) on a disposable database, through the HTTP kernel, with
// in-process TEST shops that speak protocol v1 and keep the invoice state like YD SOFT 2.17.0 (no SmartBill anywhere).
// Covers: fail closed before migration 041; root-only invoice scopes; who reads (documents, manager, finance, viewer;
// never another shop, a Confirmare operator or supervisor alone, a scope-less identity or a legacy order); who acts
// (issue scope + document permission + document scope; manager for re-issue and resolve); never the employee who
// approved the order or confirmed it by telephone; the handoff gate (not approved, not cleared, cancellation hold, open
// finance action); the summary revision; idempotency; one document; resend confirmation; re-issue statement; an unknown
// SmartBill outcome; lost answers and 5xx reconciled with the shop's stored answer; a request the shop never received;
// two employees at once; observe mode; and the audit (codes and hashes only: no link, number or customer data).

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTransport;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmationGate;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;
use Arasya\Operations\Tests\SourceAccessFakeShop;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';
require __DIR__ . '/SourceAccessFakeShop.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare invoice integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-invoice-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const INV_KEY = 'trendhome-access-key-test-0123456789abcdef-inv';
const INV_PHONE = '0744666777';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return is_array($r['body']) ? $r['body'] : []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$refused = static function (array $r, string $reason, string $label, string $code = 'CONFIRMARE_INVOICE_REFUSED') use ($check): array {
    $check($r['status'] === 409 && ($r['body']['error']['code'] ?? '') === $code && ($r['body']['error']['details']['reason'] ?? '') === $reason, "{$label}: expected {$code} {$reason}, got {$r['status']} " . json_encode($r['body']));
    return $r['body']['error']['details'] ?? [];
};

/** Routes each request to the TEST shop of its base URL (two shops, two keys). */
final class InvoiceTestShops implements SourceAccessTransport
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
$settings = ['trendhome' => $full, 'outletperdele' => $full];
$base = T::config($dbName, [T::ORIGIN], $settings);
// Trend Home: invoicing enforce. Outlet Perdele: observe (the state only).
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . INV_KEY, 'finance' => 'read', 'invoice' => 'enforce'],
    'outletperdele' => ['mode' => 'read', 'url' => 'https://outletperdele.example', 'key' => 'op-test:' . INV_KEY . '-outlet', 'finance' => '', 'invoice' => 'observe'],
]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
// Migration 041's tables stay (schema_migrations records them); between suites only their rows are removed.
$clearInvoice = static function () use ($pdo): void { foreach (['confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes'] as $table) $pdo->exec("DELETE FROM {$table}"); };
$clearInvoice();
foreach (['order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$th = new SourceAccessFakeShop('trendhome', 'th-test', INV_KEY);
$op = new SourceAccessFakeShop('outletperdele', 'op-test', INV_KEY . '-outlet');
$transport = new InvoiceTestShops(['https://trendhome.example' => $th, 'https://outletperdele.example' => $op]);
$container = new Container($config, $pdo, $transport);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "inv.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-inv-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Invoice root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Invoice root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'inv-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$password = 'invoice test passphrase 2026';
$uuids = [];
// Generic test identities: every access comes from the roles and scopes granted here, never from a name.
$identity = static function (string $name, array $roles = []) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-inv');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
};
$docs = $roleIds['production-documents-operator'];
$identity('iop', [$docs]);      // Trend Home Confirmare operator who calls and approves, also holding document and invoice rights
$identity('idoc', [$docs]);     // Trend Home production ticket employee with the invoice issue scope
$identity('idoc2', [$docs]);    // a second one
$identity('iview', [$docs]);    // Trend Home production ticket employee WITHOUT an invoice scope
$identity('inodocs');           // invoice issue scope, but no production document permission
$identity('imgr');              // Trend Home invoice manager
$identity('ifin');              // Trend Home finance viewer
$identity('isup');              // Trend Home Confirmare supervisor only
$identity('iout', [$docs]);     // Outlet Perdele operator and production ticket employee with the invoice issue scope
$identity('iboth', [$docs]);    // production tickets of BOTH shops, invoice issue for Outlet Perdele only
$identity('imgrop');            // invoice manager of Outlet Perdele only
foreach (['iop' => ['operate' => ['trendhome'], 'supervise' => []], 'isup' => ['operate' => [], 'supervise' => ['trendhome']], 'iout' => ['operate' => ['outletperdele'], 'supervise' => []]] as $name => $grant) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/confirmare-scopes", $grant), "confirmare {$name}");
}
foreach (['iop' => ['trendhome'], 'idoc' => ['trendhome'], 'idoc2' => ['trendhome'], 'iview' => ['trendhome'], 'iout' => ['outletperdele'], 'iboth' => ['outletperdele', 'trendhome']] as $name => $sources) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/document-scopes", ['operate' => $sources, 'approve' => []]), "documents {$name}");
}
$ok($asRoot('PUT', "/management/employees/{$uuids['ifin']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []]), 'finance ifin');

// ---- 1. Before migration 041: fail closed (the API deployed before its migration ran) ----------------------------------
$invoiceTables = ['order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes'];
$check((string) $one("SELECT migration_name FROM schema_migrations WHERE migration_name = '041_smartbill_invoicing.sql'") === '041_smartbill_invoicing.sql', 'migration 041 is applied by the normal migration run');
foreach ($invoiceTables as $table) $pdo->exec("DROP TABLE {$table}"); // TEST ONLY: the state before 041
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '041_smartbill_invoicing.sql'");
$error($asRoot('PUT', "/management/employees/{$uuids['idoc']}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]), 409, 'INVOICE_SCHEMA_MISSING', 'no invoice scope can be granted before the schema exists');
$check((new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations') === ['041_smartbill_invoicing.sql'], 'bin/migrate.php applies exactly 041 afterwards');
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('" . implode("', '", $invoiceTables) . "')") === 5, 'migration 041 creates its five tables');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/041_smartbill_invoicing.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('" . implode("', '", $invoiceTables) . "')") === 5 && (int) $one('SELECT COUNT(*) FROM employee_invoice_scopes') === 0, 'running 041 again changes nothing (CREATE TABLE IF NOT EXISTS); nobody holds an invoice scope');
$check(!is_file(dirname(__DIR__) . '/database/migrations/040_smartbill_invoicing.sql') && !is_dir(dirname(__DIR__) . '/database/drafts'), 'the schema is 041, never 040; the draft is gone (one source)');

// ---- 2. Invoice scopes: root-only, separate from documents, finance and Confirmare --------------------------------------
$grants = ['iop' => ['issue' => ['trendhome'], 'manage' => []], 'idoc' => ['issue' => ['trendhome'], 'manage' => []], 'idoc2' => ['issue' => ['trendhome'], 'manage' => []],
    'inodocs' => ['issue' => ['trendhome'], 'manage' => []], 'imgr' => ['issue' => [], 'manage' => ['trendhome']], 'iout' => ['issue' => ['outletperdele'], 'manage' => []],
    'iboth' => ['issue' => ['outletperdele'], 'manage' => []], 'imgrop' => ['issue' => [], 'manage' => ['outletperdele']]];
foreach ($grants as $name => $grant) {
    $check($ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/invoice-scopes", $grant), "invoice {$name}")['invoiceScopes'] === $grant, "{$name}: the stored invoice scopes");
}
$targets = array_map(static fn (string $name): string => $uuids[$name], array_keys($grants));
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.invoice_scopes_changed' AND target_id IN (" . implode(', ', array_fill(0, count($targets), '?')) . ')', $targets) === count($grants), 'every invoice scope change is in the IAM audit: ' . $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.invoice_scopes_changed'"));
$error($asRoot('PUT', "/management/employees/{$uuids['ifin']}/invoice-scopes", ['issue' => ['b2b'], 'manage' => []]), 400, 'UNKNOWN_SOURCE', 'invoice scopes exist for the shops only');
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity LIMIT 1');
$error($asRoot('PUT', "/management/employees/{$rootUuid}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]), 403, 'ROOT_PROTECTED', 'root never holds an invoice scope');
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$who = [];
foreach (array_keys($uuids) as $name) $who[$name] = $login($name);
$me = static fn (array $w): array => T::call($kernel, 'GET', '/employees/me', null, [], $w['cookie'])['body'] ?? [];
$check(($me($who['idoc'])['invoiceScopes'] ?? null) === ['issue' => ['trendhome'], 'manage' => []] && ($me($who['iview'])['invoiceScopes'] ?? null) === ['issue' => [], 'manage' => []], 'the session shows the invoice scopes (presentation only)');
$error(T::call($kernel, 'PUT', "/management/employees/{$uuids['iview']}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []], ['x-csrf-token' => $who['imgr']['csrf'], 'idempotency-key' => 'inv-self-grant-' . $suffix], $who['imgr']['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'an invoice manager cannot grant invoice scopes');

// ---- Fixtures: activated sources, Confirmare orders of both shops, a legacy order -----------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare invoice integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'API 2.45.0: activation needs the source readiness (invoicing)');
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'invoice test', 'inv-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'invoice test', 'inv-test', true);
}
$numberBase = T::freeOrderBase($pdo, 960000, 989999, 100);
$id = static fn (int $n): string => (string) ($numberBase + $n);
$g = static fn (int $n, string $src = 'trendhome'): string => $src . ':' . $id($n);
$tick = 0;
$payload = static function (string $orderId, int $seconds, array $extra = []) use ($suffix, &$tick, $iso, $activatedAt): array {
    $tick++;
    $p = T::sourceOrder($orderId, "inv-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', [[
        'id' => 9401, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $pricing = 'sha256:' . hash('sha256', "inv-price-{$orderId}");
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $cod = ($extra['payment'] ?? 'online') === 'cod';
    $p['order']['payment'] = $cod ? ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0] : ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
    $p['order']['financial'] = $cod ? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing] : ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Factură', 'street' => 'Str. Exemplu 8', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => INV_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify("{$seconds} seconds")), 'evidence' => 'checkout'];
    return $p;
};
foreach (range(1, 14) as $n) {
    // 3, 13 and 14: cash on delivery, the advance not collected yet (13 and 14: the operator's advance proforma, 2.44.0).
    $cod = in_array($n, [3, 13, 14], true);
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 60 + $n, $cod ? ['payment' => 'cod'] : [])), "trendhome order {$n}");
    $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => true, 'phone' => INV_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'inv-price-' . $id($n)), 'invoice' => ['cleared' => !$cod, 'cod' => $cod]];
}
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), 120)), 'outlet order');
$op->orders[$id(50)] = ['id' => $id(50), 'number' => $id(50), 'eligible' => true, 'phone' => INV_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'inv-price-' . $id(50)), 'invoice' => ['cleared' => true]];
$ok(T::ingest($kernel, 'trendhome', $payload($id(60), -3600)), 'legacy order (submitted before the activation)');
$check($service->distribute()['assigned'] >= 12, 'the Trend Home orders go to the Trend Home operator');
$call = static fn (array $w, string $method, string $path, ?array $body = null, ?string $key = null): array => T::call($kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => $key ?? 'inv-' . bin2hex(random_bytes(10))], $w['cookie']);
$get = static fn (array $w, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $w['cookie']);
$version = static fn (string $gid): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$gid]);
$detail = static fn (array $w, string $gid): array => $ok($get($w, '/confirmare/orders/' . rawurlencode($gid)), "detail {$gid}");
$approve = static function (array $w, string $gid) use ($detail, $call, $get, $version, $ok): void {
    $review = $detail($w, $gid)['review'];
    // The operator reads the shop order (current prices) before deciding (API 2.40.0).
    $ok($get($w, '/confirmare/orders/' . rawurlencode($gid) . '/source'), "shop order {$gid}");
    $ok($call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/contact', ['expectedVersion' => $version($gid), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]), "call {$gid}");
    $ok($call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/approve', ['expectedVersion' => $version($gid), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]), "approve {$gid}");
};
// The per-employee shop call limit (30 a minute) is a production guard; this suite makes many calls on purpose.
$invoice = static function (array $w, string $gid) use ($get, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $get($w, '/confirmare/orders/' . rawurlencode($gid) . '/invoice'); };
$read = static fn (array $w, string $gid): array => $ok($invoice($w, $gid), "invoice {$gid}");
$act = static function (array $w, string $gid, string $action, array $fields = [], ?string $key = null, ?string $revision = null) use ($call, $read, $pdo): array {
    $revision ??= $read($w, $gid)['summary']['revision'];
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return $call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/invoice/' . $action, ['expectedRevision' => $revision] + $fields, $key);
};
$docsMade = static fn (int $n): int => $th->smartbillDocuments[$id($n)] ?? 0;
foreach ([1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14] as $n) $approve($who['iop'], $g($n));
// API 2.44.0: the final invoice needs the production ticket and its QR, the operator's routing to the shop employee, and
// that employee's scan of the QR. $prepare does all three the way people do it.
$docPath = static fn (string $gid, string $action): string => '/production-documents/orders/' . rawurlencode($gid) . '/' . $action;
$docVersion = static fn (string $gid): int => (int) $one('SELECT document_version FROM operational_orders WHERE global_order_id = ?', [$gid]);
$qr = static fn (string $gid): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$gid]);
$ticket = static function (string $gid, string $by = 'idoc') use ($call, $docPath, $docVersion, $one, $ok, &$who): void {
    if ((string) $one('SELECT document_status FROM operational_orders WHERE global_order_id = ?', [$gid]) === 'active') {
        return;
    }
    $made = $ok($call($who[$by], 'POST', $docPath($gid, 'generate'), ['expectedDocumentVersion' => $docVersion($gid)]), "ticket {$gid}", 201);
    $ok($call($who[$by], 'POST', $docPath($gid, 'print'), ['revisionNumber' => (int) ($made['document']['revisionNumber'] ?? $made['revisionNumber'] ?? 1)]), "ticket printed {$gid}");
};
$assignee = static fn (string $gid): ?string => ($v = $one("SELECT a.employee_uuid FROM order_shop_assignments a JOIN operational_orders o ON o.order_uuid = a.order_uuid WHERE o.global_order_id = ? AND a.state = 'active'", [$gid])) === false ? null : (string) $v;
$route = static function (string $gid, string $to = 'idoc', string $by = 'iop', ?string $key = null) use ($call, $assignee, &$who, &$uuids, $pdo): array {
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return $call($who[$by], 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/shop-assignment', ['employeeId' => $uuids[$to], 'expectedAssigneeId' => $assignee($gid)], $key);
};
$scanQr = static function (string $gid, string $by = 'idoc', ?string $token = null) use ($call, $qr, &$who, $pdo): array {
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return $call($who[$by], 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/invoice/qr', ['qrToken' => $token ?? $qr($gid)]);
};
$prepare = static function (string $gid, string $to = 'idoc') use ($ticket, $route, $scanQr, $assignee, $ok, &$uuids): void {
    $ticket($gid, $to);
    if ($assignee($gid) !== $uuids[$to]) {
        $ok($route($gid, $to), "route {$gid} to {$to}");
    }
    $ok($scanQr($gid, $to), "QR scan {$gid} by {$to}");
};

// ---- 3. Who reads the state --------------------------------------------------------------------------------------------
$r = $read($who['idoc'], $g(1));
$check($r['available'] === true && $r['role'] === 'documents' && $r['summary']['document']['exists'] === false && $r['handoff']['state'] === 'ready' && !in_array('issue', $r['allowedActions'], true)
    && array_slice($r['blockers']['issue'], -2) === ['not_assigned', 'qr_not_verified'], 'the production ticket employee with the issue scope: documents role, the order is ready; no invoice before the routing and the QR scan: ' . json_encode($r['blockers']['issue']));
$prepare($g(1));
$r = $read($who['idoc'], $g(1));
$check($r['handoff']['state'] === 'awaiting_cutting' && in_array('issue', $r['allowedActions'], true) && $r['assignment']['employeeId'] === $uuids['idoc'] && $r['qr']['verified'] === true, 'ticket printed, routed to this employee, QR scanned: issuing allowed: ' . json_encode($r['allowedActions']));
$v = $read($who['iview'], $g(1));
$f = $read($who['ifin'], $g(1));
$m = $read($who['imgr'], $g(1));
$check($v['role'] === 'viewer' && $v['allowedActions'] === [] && in_array('not_allowed_for_role', $v['blockers']['issue'], true) && $f['role'] === 'finance' && $f['allowedActions'] === [] && $m['role'] === 'manager', 'a ticket employee without the scope reads as viewer, finance as finance (both read only), the manager as manager');
$check(($detail($who['iop'], $g(1))['invoice'] ?? null) === ['configured' => true, 'read' => true, 'issue' => true, 'manage' => false, 'proforma' => true, 'route' => true, 'payment' => true], 'the detail shows the invoice capabilities (presentation only): ' . json_encode($detail($who['iop'], $g(1))['invoice'] ?? null));
$error($invoice($who['inodocs'], $g(1)), 404, 'CONFIRMARE_NOT_FOUND', 'the issue scope without the production document permission reaches nothing');
$sup = $read($who['isup'], $g(1));
$check($sup['role'] === 'operator' && $sup['allowedActions'] === [] && $sup['summary']['document']['link'] === null && in_array('not_allowed_for_role', $sup['blockers']['issue'], true), 'API 2.44.0: a Confirmare supervisor reads the state as operator (for the proforma and the routing), never issues the final invoice, no invoice link');
$error($invoice($who['iout'], $g(1)), 404, 'CONFIRMARE_NOT_FOUND', 'Outlet Perdele never reads a Trend Home order');
$error($invoice($who['imgrop'], $g(1)), 404, 'CONFIRMARE_NOT_FOUND', 'an Outlet Perdele invoice manager never reaches a Trend Home order');
$both = $read($who['iboth'], $g(1));
$check($both['role'] === 'viewer' && $both['allowedActions'] === [], 'production tickets of both shops but the issue scope of Outlet Perdele only: a Trend Home order is read only (viewer)');
$error($invoice($who['idoc'], $g(60)), 404, 'CONFIRMARE_NOT_FOUND', 'a legacy order is outside Confirmare invoicing (its WordPress SmartBill flow is untouched)');
$check($all("SELECT access_role FROM order_invoice_reads WHERE global_order_id = ? AND outcome = 'ok' ORDER BY read_at", [$g(1)]) !== [] && (int) $one("SELECT COUNT(*) FROM order_invoice_reads WHERE global_order_id = ? AND invoice_revision IS NOT NULL", [$g(1)]) >= 4, 'every read is audited with the role and the revision hash');

// ---- 4. Who acts, and when ------------------------------------------------------------------------------------------------
$error($act($who['iop'], $g(1), 'issue'), 403, 'CONFIRMARE_INVOICE_SAME_EMPLOYEE', 'the operator who approved and called the customer never issues the invoice (even holding the scope)');
$error($act($who['iview'], $g(1), 'issue'), 403, 'CONFIRMARE_INVOICE_DENIED', 'no issue scope: refused');
$error($act($who['ifin'], $g(1), 'issue'), 403, 'CONFIRMARE_INVOICE_DENIED', 'finance never issues');
$error($act($who['idoc'], $g(1), 'reissue', ['reasonCode' => 'order_changed', 'previousCancelled' => true]), 403, 'CONFIRMARE_INVOICE_DENIED', 'only a manager re-issues');
$refused($act($who['idoc'], $g(2), 'issue'), 'confirmare_not_approved', 'an order not approved yet', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$refused($act($who['idoc'], $g(3), 'issue'), 'handoff_not_ready', 'approved but the money is not proven (handoff blocked)', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$pdo->prepare("UPDATE operational_orders SET cancellation_state = 'held', production_stopped_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?")->execute([$g(7)]);
$refused($act($who['idoc'], $g(7), 'issue'), 'order_cancellation', 'a manager\'s production stop for a cancellation', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$orderUuid8 = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$g(8)]);
$pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, idempotency_key, request_hmac, revision_before, state, requested_at)
    VALUES (?, ?, ?, 'trendhome', ?, 'submit_transfer', ?, ?, ?, 'pending', UTC_TIMESTAMP(6))")->execute([Uuid::v4(), $orderUuid8, $g(8), $uuids['ifin'], 'inv-fin-open-' . $suffix, random_bytes(32), 'sha256:' . str_repeat('b', 64)]);
$refused($act($who['idoc'], $g(8), 'issue'), 'handoff_not_ready', 'an open finance action closes the handoff, so no invoice', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$error($act($who['iboth'], $g(1), 'issue'), 403, 'CONFIRMARE_INVOICE_DENIED', 'the issue scope of another shop never issues here');
$check($docsMade(2) + $docsMade(3) + $docsMade(7) + $docsMade(8) === 0 && $th->requests !== [] && !in_array('/yd-arasya/v1/confirmare/invoice-action', array_column(array_slice($th->requests, 0), 'route'), true), 'none of these refusals ever reached the shop\'s action route');
$refused($act($who['idoc'], $g(1), 'issue', [], null, 'sha256:' . str_repeat('a', 64)), 'revision_changed', 'an action on an old revision');

// ---- 4b. Every issuable handoff state; the invoice scope grants no production power ------------------------------------
// The handoff states after the approval: ready (no ticket), print (ticket generated, not printed), awaiting_cutting
// (printed, waiting for the cutting scan), in_production (scanned). Issuing is allowed in each; it never generates or
// prints a ticket, never scans, never moves the stage. The invoice scope adds no permission and no stage scope.
$cutter = $container->employeeAdmin()->create('Test icut', "icut.{$suffix}", null, 'pregatire-material', 'employee', $password, ['material-preparation'], 'test-inv');
$ok($asRoot('PUT', "/management/employees/{$cutter->employeeUuid}/applications", ['applications' => ['staff']]), 'icut applications');
$ok($asRoot('PUT', "/management/employees/{$cutter->employeeUuid}/stage-scopes", ['stageId' => 'material-preparation', 'sources' => ['trendhome'], 'expectedSources' => null]), 'icut cutting scope');
$who['icut'] = $login('icut');
$a = $me($who['idoc']);
$b = $me($who['iview']);
$differs = array_keys(array_filter($a, static fn ($value, $key): bool => json_encode($b[$key] ?? null) !== json_encode($value), ARRAY_FILTER_USE_BOTH));
$check(array_values(array_diff($differs, ['employeeUuid', 'username', 'displayName', 'authorizationVersion', 'invoiceScopes'])) === [], 'the invoice scope adds nothing else to the session (same permissions, stages and scopes as the same role without it): ' . json_encode($differs));
$production = static fn (string $gid): array => $all('SELECT production_stage_id, production_owner_employee_uuid, production_version, document_status, document_version FROM operational_orders WHERE global_order_id = ?', [$gid])[0];
$tickets = static fn (string $gid): array => [(int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$gid]), (int) $one('SELECT COUNT(*) FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id = ?', [$gid])];
$issuable = static fn (string $gid): array => [$read($who['idoc'], $gid)['handoff']['state'], in_array('issue', $read($who['idoc'], $gid)['allowedActions'], true)];
$check($issuable($g(11)) === ['ready', false], 'ready (no production ticket, so no QR to scan): no invoice yet');
$generated = $ok($call($who['idoc'], 'POST', $docPath($g(11), 'generate'), ['expectedDocumentVersion' => $docVersion($g(11))]), 'ticket generated', 201);
$ok($route($g(11)), 'routed to the shop employee');
$check($issuable($g(11)) === ['print', false], 'print (ticket generated, routed): still no invoice before the QR scan');
$error($scanQr($g(11), 'idoc', 'ARASYA:Q1:' . str_repeat('A', 26)), 409, 'INVOICE_QR_REFUSED', 'an unknown code is refused');
$error($scanQr($g(11), 'idoc', $qr($g(1))), 409, 'INVOICE_QR_REFUSED', 'the QR of another order is refused (never says which)');
$error($scanQr($g(11), 'idoc', 'not a code'), 422, 'INVOICE_QR_INVALID', 'a malformed code is refused');
$error($scanQr($g(11), 'iview'), 403, 'CONFIRMARE_INVOICE_DENIED', 'a ticket viewer without the invoice scope does not scan for the invoice');
$check(array_column($all("SELECT outcome FROM order_invoice_qr_checks q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? ORDER BY checked_at", [$g(11)]), 'outcome') === ['unknown_qr', 'wrong_order'], 'refused scans are recorded with their reason');
$ok($scanQr($g(11)), 'the QR of this order');
$check($issuable($g(11)) === ['print', true], 'print + routed + QR scanned: issuing allowed');
$ok($call($who['idoc'], 'POST', $docPath($g(11), 'print'), ['revisionNumber' => (int) ($generated['document']['revisionNumber'] ?? $generated['revisionNumber'] ?? 1)]), 'ticket printed');
$check($issuable($g(11)) === ['awaiting_cutting', true], 'awaiting_cutting (printed, not scanned at cutting): issuing allowed');
$pdo->prepare('UPDATE order_invoice_qr_checks SET checked_at = DATE_SUB(checked_at, INTERVAL 31 MINUTE) WHERE order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)')->execute([$g(11)]);
$check($issuable($g(11)) === ['awaiting_cutting', false] && in_array('qr_not_verified', $read($who['idoc'], $g(11))['blockers']['issue'], true), 'a scan older than 30 minutes no longer counts');
$pdo->prepare("UPDATE operational_orders SET document_status = 'stale' WHERE global_order_id = ?")->execute([$g(11)]);
$error($scanQr($g(11)), 409, 'INVOICE_QR_REFUSED', 'the QR of a stale production document is refused');
$pdo->prepare("UPDATE operational_orders SET document_status = 'active' WHERE global_order_id = ?")->execute([$g(11)]);
$ok($scanQr($g(11)), 'scanned again');
$before = $production($g(11));
$ticketsBefore = $tickets($g(11));
$ok($act($who['idoc'], $g(11), 'issue'), 'issue while awaiting the cutting scan');
$check($production($g(11)) === $before && $tickets($g(11)) === $ticketsBefore && $before['production_stage_id'] === 'waiting', 'issuing (and the invoice QR scan) moved no stage, made no ticket revision and no print');
$scan = $call($who['idoc'], 'POST', '/cutting/intake', ['qrToken' => $qr($g(11))]);
$check(in_array($scan['status'], [403, 404], true) && $production($g(11)) === $before, 'the invoice employee cannot scan the cutting QR (' . $scan['status'] . ' ' . ($scan['body']['error']['code'] ?? '') . '); the stage stays');
$generated12 = $ok($call($who['idoc'], 'POST', $docPath($g(12), 'generate'), ['expectedDocumentVersion' => $docVersion($g(12))]), 'ticket 12 generated', 201);
$ok($call($who['idoc'], 'POST', $docPath($g(12), 'print'), ['revisionNumber' => (int) ($generated12['document']['revisionNumber'] ?? $generated12['revisionNumber'] ?? 1)]), 'ticket 12 printed');
$ok($route($g(12)), 'ticket 12 routed');
$started = $ok($call($who['icut'], 'POST', '/cutting/intake', ['qrToken' => $qr($g(12))]), 'the cutter scans');
$ok($scanQr($g(12)), 'the shop employee scans the same QR for the invoice');
$check(($started['outcome'] ?? null) === 'started' && $issuable($g(12)) === ['in_production', true], 'in_production (scanned by the cutter): issuing allowed');
$before12 = $production($g(12));
$ok($act($who['idoc'], $g(12), 'issue'), 'issue during production');
$check($production($g(12)) === $before12 && $before12['production_stage_id'] === 'material-preparation' && $before12['production_owner_employee_uuid'] === $cutter->employeeUuid, 'issuing during production changes neither the stage nor its owner');

// ---- 4c. The operator's advance proforma, the paid-advance queue and the routing (API 2.44.0) -----------------------------
$proforma = static function (string $who_, string $gid, string $action, array $fields = [], ?string $key = null, ?string $revision = null) use ($call, $read, $pdo, &$who): array {
    $revision ??= $read($who[$who_], $gid)['summary']['revision'];
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return $call($who[$who_], 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/proforma/' . $action, ['expectedRevision' => $revision] + $fields, $key);
};
$pfMade = static fn (int $n): int => $th->smartbillDocuments['pf:' . $id($n)] ?? 0;
$p13 = $read($who['iop'], $g(13));
$check($p13['proforma']['role'] === 'operator' && in_array('proformaIssue', $p13['proforma']['allowedActions'], true) && $p13['summary']['proforma']['advance']['outstanding'] === '215.70' && $p13['summary']['proforma']['document'] === null, 'the order\'s Confirmare operator may issue the advance proforma of the waiting COD order (outstanding 215.70)');
$check($read($who['idoc'], $g(13))['proforma']['allowedActions'] === [] && in_array('not_allowed_for_role', $read($who['idoc'], $g(13))['proforma']['blockers']['proformaIssue'], true), 'the shop employee never issues the proforma');
$error($proforma('idoc', $g(13), 'issue'), 403, 'CONFIRMARE_INVOICE_DENIED', 'the shop employee: refused by the server');
$error($proforma('ifin', $g(13), 'issue'), 403, 'CONFIRMARE_INVOICE_DENIED', 'finance reads, never issues a proforma');
$error($proforma('iout', $g(13), 'issue', [], null, 'sha256:' . str_repeat('c', 64)), 404, 'CONFIRMARE_NOT_FOUND', 'another shop\'s operator: nothing');
$refused($proforma('iop', $g(1), 'issue'), 'advance_not_required', 'a card-paid order needs no advance proforma', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$k13 = 'pf-issue-0001-' . $suffix;
$rev13 = $read($who['iop'], $g(13))['summary']['revision'];
$pf = $ok($proforma('iop', $g(13), 'issue', [], $k13, $rev13), 'proforma');
$check($pf['state'] === 'applied' && $pf['result'] === 'issued' && $pfMade(13) === 1 && $pf['summary']['proforma']['document']['series'] === 'PRF' && $pf['summary']['proforma']['document']['link'] !== null && $pf['summary']['financial']['state'] === 'waiting', 'one advance proforma; the money is still not proven (a proforma is not a payment)');
// 2.18.0 owner decision: the advance is paid by bank transfer to the shop's account printed on the proforma.
$t13 = $pf['summary']['proforma'];
$check($t13['transfer'] === ['iban' => 'RO49AAAA1B31007593840000', 'bank' => 'Banca TEST', 'beneficiary' => 'TEST Trend Home SRL', 'reference' => 'TH' . $id(13), 'bic' => null] && $t13['document']['reference'] === 'TH' . $id(13)
    && $t13['advance']['state'] === 'waiting' && $t13['advance']['verifying'] === false, 'the operator sees the shop\'s IBAN, bank, beneficiary and the payment reference tied to the proforma; the advance is waiting');
$check($read($who['iview'], $g(13))['summary']['proforma']['transfer'] === null, 'a ticket viewer gets no transfer details');
$again = $ok($proforma('iop', $g(13), 'issue', [], $k13, $rev13), 'proforma replay');
$refused($proforma('iop', $g(13), 'issue'), 'proforma_exists', 'a second click with a new key: refused by the shop');
$check($again['state'] === 'applied' && $pfMade(13) === 1, 'a double click: still one proforma');
$th->proformaMailOutcome = 'refused';
$refused($proforma('iop', $g(13), 'send-mail'), 'mail_refused', 'SmartBill refused the e-mail');
$m13 = $ok($proforma('iop', $g(13), 'send-mail'), 'proforma e-mail retried');
$check($m13['summary']['proforma']['mail']['count'] === 1 && $pfMade(13) === 1 && ($th->smartbillMails['pf:' . $id(13)] ?? 0) === 1, 'the retry sends the SAME proforma (no new proforma for an e-mail problem)');
$refused($proforma('iop', $g(13), 'send-mail'), 'resend_confirmation_required', 'a second e-mail needs the confirmation');
$th->proformaOutcome = 'unknown';
$u14 = $ok($proforma('iop', $g(14), 'issue'), 'proforma unknown');
$check($u14['result'] === 'unknown' && $pfMade(14) === 1, 'SmartBill did not answer: unknown, never sent again');
$refused($proforma('iop', $g(14), 'issue'), 'operation_unknown', 'a new proforma waits for a manager');
$error($proforma('iop', $g(14), 'resolve', ['target' => 'document', 'resolution' => 'issued', 'series' => 'PRF', 'number' => '0099']), 403, 'CONFIRMARE_INVOICE_DENIED', 'only a manager resolves');
$res14 = $ok($proforma('imgr', $g(14), 'resolve', ['target' => 'document', 'resolution' => 'issued', 'series' => 'PRF', 'number' => '0099']), 'manager resolves');
$check($res14['result'] === 'issued' && $res14['summary']['proforma']['document']['number'] === '0099', 'the manager names the proforma found in SmartBill');
$check($all("SELECT access_role, action, state FROM order_invoice_actions WHERE global_order_id = ? AND state = 'applied' ORDER BY requested_at", [$g(13)]) === [['access_role' => 'operator', 'action' => 'proforma_issue', 'state' => 'applied'], ['access_role' => 'operator', 'action' => 'proforma_send_mail', 'state' => 'applied']], 'the proforma actions are audited with the operator role');
// The paid-advance queue and the routing: only proven money.
$queue = static function (string $who_, ?string $task = null) use ($get, $ok, &$who): array {
    return $ok($get($who[$who_], '/confirmare/finance' . ($task === null ? '' : '?task=' . $task)), "finance queue {$who_}");
};
$tasksOf = static fn (array $q, string $gid): array => array_column(array_values(array_filter($q['orders'], static fn (array $o): bool => $o['globalOrderId'] === $gid)), 'task');
$check($tasksOf($queue('iop'), $g(13)) === ['advance_waiting'], 'the operator sees the order waiting for its advance (no finance scope needed for the advance tasks)');
$refused($route($g(13)), 'advance_not_verified', 'an unpaid advance is never routed (a proforma or an e-mail is not money)', 'CONFIRMARE_ROUTING_NOT_ALLOWED');
// SmartBill invoiced the proforma (a person matched a bank statement to it in SmartBill): still no money for Arasya.
$th->orders[$id(13)]['invoice']['pf']['invoiced'] = true;
$inv13 = $read($who['iop'], $g(13));
$check($inv13['summary']['proforma']['invoiced']['invoiced'] === true && $inv13['summary']['proforma']['advance']['state'] === 'waiting' && $inv13['routing']['allowed'] === false && $tasksOf($queue('iop'), $g(13)) === ['advance_waiting'],
    'a proforma invoiced in SmartBill is never taken as payment: still waiting, never routed');
$refused($route($g(13)), 'advance_not_verified', 'routing refused while only the proforma status says invoiced', 'CONFIRMARE_ROUTING_NOT_ALLOWED');
$th->orders[$id(13)]['invoice']['pf']['invoiced'] = false;
// The verified advance arrives from the shop (its own evidence: a verified collection or transfer).
$paid = $payload($id(13), 200, ['payment' => 'cod']);
$paid['order']['financial'] = ['state' => 'cleared', 'basis' => 'advance_collected', 'pricingFingerprint' => $paid['order']['pricing']['fingerprint']];
$ok(T::ingest($kernel, 'trendhome', $paid), 'the shop reports the verified advance');
$th->orders[$id(13)]['invoice']['cleared'] = true;
$check($tasksOf($queue('iop'), $g(13)) === ['advance_verified'] && $tasksOf($queue('ifin'), $g(13)) === ['advance_verified'], 'paid: the operator and finance see "Avans plătit"');
$check($read($who['iop'], $g(13))['summary']['proforma']['advance']['state'] === 'paid' && $read($who['iop'], $g(13))['routing']['allowed'] === true, 'the operator sees the advance paid without confirming anything and may route the order');
$error($route($g(13), 'ifin'), 422, 'CONFIRMARE_SHOP_EMPLOYEE_NOT_ELIGIBLE', 'finance is not a shop employee for invoices');
$error($route($g(13), 'iout'), 422, 'CONFIRMARE_SHOP_EMPLOYEE_NOT_ELIGIBLE', 'another shop\'s employee never receives it');
$error($route($g(13), 'iop', 'isup'), 422, 'CONFIRMARE_SHOP_EMPLOYEE_NOT_ELIGIBLE', 'never the operator who confirmed the order');
$error($route($g(13), 'idoc', 'idoc'), 403, 'CONFIRMARE_INVOICE_DENIED', 'a shop employee does not route orders');
$cands = array_column($ok($get($who['iop'], '/confirmare/orders/' . rawurlencode($g(13)) . '/shop-employees'), 'candidates')['items'], 'id');
$check(in_array($uuids['idoc'], $cands, true) && in_array($uuids['idoc2'], $cands, true) && !in_array($uuids['iop'], $cands, true) && !in_array($uuids['iout'], $cands, true) && !in_array($uuids['ifin'], $cands, true), 'the candidates: this shop\'s invoice employees only, never the confirming operator');
$kr = 'route-0001-' . $suffix;
$r13 = $ok($route($g(13), 'idoc', 'iop', $kr), 'routed');
$check($r13['assignment']['employeeId'] === $uuids['idoc'] && $tasksOf($queue('iop'), $g(13)) === ['advance_routed'], 'routed: "Direcționate către magazin"');
$check(in_array($g(13), array_column($ok($get($who['idoc'], '/confirmare/shop-queue'), 'shop queue')['items'], 'globalOrderId'), true) && !in_array($g(13), array_column($ok($get($who['idoc2'], '/confirmare/shop-queue'), 'shop queue 2')['items'], 'globalOrderId'), true), 'the order is in the assigned employee\'s queue only');
$error($call($who['iop'], 'POST', '/confirmare/orders/' . rawurlencode($g(13)) . '/shop-assignment', ['employeeId' => $uuids['idoc2'], 'expectedAssigneeId' => null]), 409, 'CONFIRMARE_ASSIGNMENT_CHANGED', 'routing from an old screen (expected nobody) is refused');
$ok($route($g(13), 'idoc2', 'isup'), 'the supervisor reassigns');
$check((int) $one("SELECT COUNT(*) FROM order_shop_assignments a JOIN operational_orders o ON o.order_uuid = a.order_uuid WHERE o.global_order_id = ? AND a.state = 'active'", [$g(13)]) === 1 && $assignee($g(13)) === $uuids['idoc2'] && !in_array($g(13), array_column($ok($get($who['idoc'], '/confirmare/shop-queue'), 'shop queue')['items'], 'globalOrderId'), true), 'one active assignment; the previous one is released (kept)');
$ticket($g(13), 'idoc2');
$ok($scanQr($g(13), 'idoc'), 'the former assignee scans');
$refused($act($who['idoc'], $g(13), 'issue'), 'assigned_to_other', 'the former assignee cannot invoice', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$error($act($who['isup'], $g(13), 'issue'), 403, 'CONFIRMARE_INVOICE_DENIED', 'the operator never issues the final invoice');
$ok($scanQr($g(13), 'idoc2'), 'the assignee scans');
$th->orders[$id(13)]['invoice']['pf']['invoiced'] = true;
$refused($act($who['idoc2'], $g(13), 'issue'), 'proforma_invoiced', 'SmartBill already invoiced the paid proforma: no final invoice from Arasya');
$th->orders[$id(13)]['invoice']['pf']['invoiced'] = false;
$f13 = $ok($act($who['idoc2'], $g(13), 'issue'), 'the assignee issues the final invoice');
$check($f13['result'] === 'issued' && $docsMade(13) === 1, 'the final invoice: once, by the assigned shop employee');
if (function_exists('pcntl_fork')) {
    // Two operators route the same paid order at the same moment: one wins, the other is told to read again.
    $files = [];
    $children = [];
    $pdo->exec("UPDATE order_shop_assignments SET state = 'released', released_at = UTC_TIMESTAMP(6), released_by_employee_uuid = assigned_by_employee_uuid, release_reason = 'reassigned' WHERE global_order_id = '{$g(12)}' AND state = 'active'");
    foreach ([['iop', 'idoc'], ['isup', 'idoc2']] as $index => [$by, $to]) {
        $files[$index] = tempnam(sys_get_temp_dir(), 'route');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $childContainer = new Container($config, null, $transport);
            usleep(20000);
            $response = T::call($childContainer->kernel(), 'POST', '/confirmare/orders/' . rawurlencode($g(12)) . '/shop-assignment', ['employeeId' => $uuids[$to], 'expectedAssigneeId' => null],
                ['origin' => T::ORIGIN, 'x-csrf-token' => $who[$by]['csrf'], 'idempotency-key' => "route-race-{$index}-{$suffix}"], $who[$by]['cookie']);
            file_put_contents($files[$index], json_encode(['status' => $response['status'], 'code' => $response['body']['error']['code'] ?? null]));
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    $results = array_map(static fn (string $file): array => json_decode((string) file_get_contents($file), true) ?: [], $files);
    array_map('unlink', $files);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [200, 409] && (int) $one("SELECT COUNT(*) FROM order_shop_assignments WHERE global_order_id = ? AND state = 'active'", [$g(12)]) === 1, 'two routings at once: one active assignment, the other refused: ' . json_encode($results));
}

// ---- 4d. API 2.45.0 (YD SOFT 2.19.0 master update): cancel in SmartBill, telephone acceptance, smaller advance --------------
$cancels = static fn (int $n): int => $th->smartbillCancels['pf:' . $id($n)] ?? 0;
$p14 = $read($who['iop'], $g(14));
$check(in_array('proformaCancel', $p14['proforma']['allowedActions'], true) && $p14['summary']['proforma']['document']['number'] === '0099' && $p14['summary']['proforma']['money']['remaining'] === '719.00', 'the operator may cancel the unpaid proforma 0099; the money calculation is shown (owed 719.00)');
$error($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'whim', 'confirmNoPayment' => true]), 422, 'CONFIRMARE_INVOICE_INVALID', 'an unknown cancellation reason is refused before the shop');
$error($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'other', 'confirmNoPayment' => 'yes']), 422, 'CONFIRMARE_INVOICE_INVALID', 'the no-payment statement is a boolean');
$error($proforma('idoc', $g(14), 'cancel', ['reasonCode' => 'other', 'confirmNoPayment' => true]), 403, 'CONFIRMARE_INVOICE_DENIED', 'the shop employee never cancels the proforma');
$error($proforma('ifin', $g(14), 'cancel', ['reasonCode' => 'other', 'confirmNoPayment' => true]), 403, 'CONFIRMARE_INVOICE_DENIED', 'finance never cancels the proforma');
$refused($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'customer_request']), 'no_payment_confirmation_required', 'the operator must state that no payment came');
$th->proformaCancelOutcome = 'refused';
$refused($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'customer_request', 'confirmNoPayment' => true]), 'cancel_refused', 'SmartBill refuses: never shown cancelled');
$check($read($who['iop'], $g(14))['summary']['proforma']['document']['number'] === '0099' && $cancels(14) === 1, 'after the refusal the proforma stays');
$th->proformaCancelOutcome = 'unknown';
$cu = $ok($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'customer_request', 'confirmNoPayment' => true]), 'cancel unknown');
$s14 = $read($who['iop'], $g(14));
$check($cu['result'] === 'unknown' && $s14['summary']['proforma']['cancel']['state'] === 'unknown' && $s14['summary']['proforma']['document'] !== null && !in_array('proformaSendMail', $s14['proforma']['allowedActions'], true), 'no answer: "Anulare în curs de verificare"; nothing else is done with the proforma');
$error($proforma('iop', $g(14), 'resolve', ['target' => 'cancel', 'resolution' => 'cancelled']), 403, 'CONFIRMARE_INVOICE_DENIED', 'only a manager closes an unknown cancellation');
$error($proforma('imgr', $g(14), 'resolve', ['target' => 'cancel', 'resolution' => 'maybe']), 422, 'CONFIRMARE_INVOICE_INVALID', 'a cancellation resolution is cancelled or not_cancelled');
$rc = $ok($proforma('imgr', $g(14), 'resolve', ['target' => 'cancel', 'resolution' => 'cancelled']), 'manager closes the cancellation');
$check($rc['result'] === 'cancelled' && $rc['summary']['proforma']['document'] === null && ($rc['summary']['proforma']['cancelled'][0]['number'] ?? null) === '0099', 'the manager saw it cancelled in SmartBill: 0099 is in the history, the order waits for a new proforma');
$kc = 'pf-cancel-0002-' . $suffix;
$ok($proforma('iop', $g(14), 'issue'), 'a new proforma after the cancellation');
$rev14 = $read($who['iop'], $g(14))['summary']['revision'];
$c14 = $ok($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'order_changed', 'reasonNote' => 'Clientul a modificat comanda.', 'confirmNoPayment' => true], $kc, $rev14), 'the operator cancels');
$c14b = $ok($proforma('iop', $g(14), 'cancel', ['reasonCode' => 'order_changed', 'reasonNote' => 'Clientul a modificat comanda.', 'confirmNoPayment' => true], $kc, $rev14), 'the same cancellation again');
$check($c14['result'] === 'cancelled' && $c14b['state'] === 'applied' && $cancels(14) === 3 && count($c14['summary']['proforma']['cancelled']) === 2, 'cancelled in SmartBill once; a double click answers the recorded result (no second cancellation)');
$cancelRows = $all("SELECT reason_code, state, result FROM order_invoice_actions WHERE global_order_id = ? AND action = 'proforma_cancel' ORDER BY requested_at", [$g(14)]);
// Refused twice (no statement, SmartBill refused), unknown once, then cancelled: every attempt audited with its reason code.
$check(array_column($cancelRows, 'reason_code') === ['customer_request', 'customer_request', 'customer_request', 'order_changed'] && array_column($cancelRows, 'state') === ['rejected', 'rejected', 'applied', 'applied'] && array_column($cancelRows, 'result') === [null, null, 'unknown', 'cancelled']
    && (int) $one("SELECT COUNT(*) FROM order_invoice_actions WHERE global_order_id = ? AND (CAST(reason_code AS CHAR) LIKE '%modificat%' OR CAST(outcome AS CHAR) LIKE '%modificat%')", [$g(14)]) === 0, 'the cancellation is audited with its reason code (never the note)');
// The customer's telephone acceptance of a new total.
$th->orders[$id(14)]['invoice']['totalAccepted'] = false;
$a14 = $read($who['iop'], $g(14));
$check($a14['summary']['proforma']['totalAcceptance']['state'] === 'required' && $a14['summary']['proforma']['totalAcceptance']['previousTotal'] === '699.00' && in_array('totalAccept', $a14['proforma']['allowedActions'], true) && !in_array('proformaIssue', $a14['proforma']['allowedActions'], true),
    'new prices: the operator sees the total before (699.00) and the new one; no proforma before the customer accepts it');
$refused($proforma('iop', $g(14), 'issue'), 'total_not_accepted', 'no proforma on prices the customer has not accepted');
$refused($proforma('iop', $g(14), 'accept-total'), 'declaration_required', 'the acceptance needs the operator\'s explicit declaration');
$error($proforma('idoc', $g(14), 'accept-total', ['declaration' => true]), 403, 'CONFIRMARE_INVOICE_DENIED', 'the shop employee never records the customer\'s acceptance');
$ta = $ok($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'note' => 'Confirmat la telefon.']), 'the operator records the acceptance');
$check($ta['result'] === 'accepted' && $ta['summary']['proforma']['totalAcceptance']['state'] === 'accepted' && (string) $one("SELECT access_role FROM order_invoice_actions WHERE global_order_id = ? AND action = 'total_accept' AND state = 'applied'", [$g(14)]) === 'operator', 'accepted, audited with the operator role');
// A smaller verified advance accepted by the operator.
$refused($proforma('iop', $g(2), 'accept-advance'), 'confirmare_not_approved', 'an order not approved in Confirmare: no advance is accepted for it', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$refused($proforma('iop', $g(14), 'accept-advance'), 'advance_not_received', 'nothing received: zero is never an accepted advance');
$th->orders[$id(14)]['invoice']['partial'] = true;
$v14 = $read($who['iop'], $g(14));
$check($v14['summary']['proforma']['money']['net'] === '200.00' && $v14['summary']['proforma']['money']['advanceGap'] === '15.70' && $v14['summary']['proforma']['money']['remaining'] === '519.00' && in_array('advanceAccept', $v14['proforma']['allowedActions'], true), '200.00 of 215.70 received: the operator sees the difference 15.70 and what is owed (519.00)');
$error($proforma('ifin', $g(14), 'accept-advance'), 403, 'CONFIRMARE_INVOICE_DENIED', 'finance never accepts an advance commercially');
$error($proforma('iop', $g(14), 'accept-advance', ['confirmLow' => 'da']), 422, 'CONFIRMARE_INVOICE_INVALID', 'the low-advance confirmation is a boolean');
$aa = $ok($proforma('iop', $g(14), 'accept-advance', ['reasonNote' => 'Restul la livrare.']), 'the operator accepts the advance received');
$check($aa['result'] === 'accepted' && $aa['summary']['proforma']['advanceAcceptance']['received'] === '200.00' && (string) $one("SELECT access_role FROM order_invoice_actions WHERE global_order_id = ? AND action = 'advance_accept' AND state = 'applied'", [$g(14)]) === 'operator', 'accepted and audited (role operator, no amount stored in Arasya)');
$refused($proforma('iop', $g(14), 'accept-advance'), 'advance_already_accepted', 'accepted once');
// A YD SOFT 2.18.0 shop: none of the new actions.
$th->legacyProforma = true;
$l14 = $read($who['iop'], $g(14));
$check(array_intersect(['proformaCancel', 'totalAccept', 'advanceAccept'], $l14['proforma']['allowedActions']) === [] && $l14['summary']['proforma']['blockers']['proformaCancel'] === ['not_supported'] && $l14['summary']['proforma']['money'] === null, 'a 2.18.0 shop: the new actions are not offered (not_supported), no money calculation is invented');
$th->legacyProforma = false;
// Owner business rule 2026-10-25: after a revision, verified money below the new advance counts only with the operator's
// explicit decision; reaching half of it is never a reason by itself. The shipping must match WooCommerce's configuration.
$th->orders[$id(14)]['invoice'] = ['totalAccepted' => false, 'advanceAccepted' => false, 'cleared' => false, 'advanceDecision' => true, 'advanceKind' => 'accepted'] + $th->orders[$id(14)]['invoice'];
$k14 = $read($who['iop'], $g(14));
$check($k14['summary']['proforma']['totalAcceptance']['state'] === 'required' && $k14['summary']['proforma']['totalAcceptance']['advanceDecisionRequired'] === true && $k14['summary']['proforma']['shipping']['state'] === 'match', 'the operator sees that accepting the new total needs an explicit decision on the advance paid');
$error($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'advanceDecision' => 'keep']), 422, 'CONFIRMARE_INVOICE_INVALID', 'only accept_received or request_difference');
$error($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'advanceDecision' => true]), 422, 'CONFIRMARE_INVOICE_INVALID', 'the decision is a word, never a flag');
$error($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'requestAdditional' => true]), 400, 'INVALID_REQUEST', 'the withdrawn automatic-keep field never reaches the shop');
$refused($proforma('iop', $g(14), 'accept-total', ['declaration' => true]), 'advance_decision_required', 'no decision: the shop refuses (never kept by itself)');
$orderUuid14 = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$g(14)]);
$fa14 = Uuid::v4();
$pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, idempotency_key, request_hmac, revision_before, state, requested_at)
    VALUES (?, ?, ?, 'trendhome', ?, 'submit_transfer', ?, ?, ?, 'pending', UTC_TIMESTAMP(6))")->execute([$fa14, $orderUuid14, $g(14), $uuids['ifin'], 'inv-fin-open14-' . $suffix, random_bytes(32), 'sha256:' . str_repeat('c', 64)]);
$refused($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'advanceDecision' => 'accept_received']), 'finance_action_open', 'a finance action not settled: the money received is not accepted', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$pdo->prepare('DELETE FROM order_finance_actions WHERE action_uuid = ?')->execute([$fa14]);
$tk = $ok($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'advanceDecision' => 'accept_received', 'note' => 'Acceptat la telefon.']), 'the new total accepted, the money received accepted explicitly');
$check($tk['summary']['proforma']['advanceAcceptance']['kind'] === 'kept' && $tk['summary']['proforma']['totalAcceptance']['advanceDecisionRequired'] === null && $tk['summary']['financial']['state'] === 'cleared', 'the money paid before the revision is the advance (kind kept), by the operator\'s decision: no second proforma');
$th->orders[$id(14)]['invoice'] = ['totalAccepted' => false, 'advanceAccepted' => false, 'cleared' => false, 'advanceDecision' => true, 'advanceKind' => 'accepted'] + $th->orders[$id(14)]['invoice'];
$tq = $ok($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'advanceDecision' => 'request_difference']), 'the new total accepted, the difference asked');
$check($tq['summary']['proforma']['advanceAcceptance'] === null && $tq['summary']['financial']['state'] !== 'cleared', 'the difference asked: the order waits for it (its own proforma)');
$th->orders[$id(14)]['invoice'] = ['totalAccepted' => false, 'advanceAccepted' => false, 'cleared' => false, 'advanceDecision' => false] + $th->orders[$id(14)]['invoice'];
$refused($proforma('iop', $g(14), 'accept-total', ['declaration' => true, 'advanceDecision' => 'accept_received']), 'advance_decision_not_applicable', 'no money to decide on (zero is never an advance)');
$th->orders[$id(14)]['invoice'] = ['totalAccepted' => true, 'advanceAccepted' => true, 'cleared' => true, 'advanceDecision' => false, 'advanceKind' => 'accepted', 'shippingMismatch' => true] + $th->orders[$id(14)]['invoice'];
$s14 = $read($who['iop'], $g(14));
$check($s14['summary']['proforma']['shipping'] === ['state' => 'mismatch', 'code' => 'shipping_cost_mismatch', 'current' => '25.00', 'expected' => '35.00'] && in_array('shipping_mismatch', $s14['summary']['proforma']['blockers']['proformaIssue'], true)
    && in_array('shipping_mismatch', $s14['summary']['blockers']['issue'], true), 'a shipping amount WooCommerce\'s configuration does not give: shown with both amounts; no proforma, no final invoice (shipping_mismatch)');
$v14s = $read($who['iview'], $g(14))['summary']['proforma']['shipping'] ?? 'absent';
$check(is_array($v14s) && $v14s['state'] === 'mismatch' && $v14s['current'] === null && $v14s['expected'] === null, 'the viewer sees the shipping state without amounts ' . json_encode($v14s));
$th->orders[$id(14)]['invoice'] = ['shippingMismatch' => false] + $th->orders[$id(14)]['invoice'];

// ---- 5. Issue once; replay; a new key; resend; re-issue -----------------------------------------------------------------
$k1 = 'inv-issue-0001-' . $suffix;
$rev1 = $read($who['idoc'], $g(1))['summary']['revision'];
$i1 = $ok($act($who['idoc'], $g(1), 'issue', [], $k1, $rev1), 'issue');
$check($i1['state'] === 'applied' && $i1['result'] === 'issued' && $i1['summary']['document']['exists'] && $i1['summary']['document']['link'] !== null && $docsMade(1) === 1 && ($th->smartbillMails[$id(1)] ?? 0) === 1, 'issued: one document, its link for the documents role, the customer e-mailed once');
$check($read($who['iview'], $g(1))['summary']['document']['link'] === null && $read($who['ifin'], $g(1))['summary']['document']['link'] === null, 'viewer and finance see the same document without the SmartBill link');
foreach (['https://smartbill.ro.evil.example/f', 'http://cloud.smartbill.ro/f', 'https://user:pw@cloud.smartbill.ro/f', 'javascript:alert(1)'] as $foreign) {
    $th->invoiceLinkOverride = $foreign;
    $bad = $read($who['idoc'], $g(1));
    $check($bad['available'] === false && $bad['reason'] === 'source_response_invalid', "a shop answer with a foreign link is never shown ({$foreign})");
}
$th->invoiceLinkOverride = null;
$again = $ok($act($who['idoc'], $g(1), 'issue', [], $k1, $rev1), 'replay');
$check($again['state'] === 'applied' && $docsMade(1) === 1, 'the same key returns the recorded outcome, never a second call');
$error($act($who['idoc'], $g(1), 'issue', [], $k1, $i1['summary']['revision']), 409, 'IDEMPOTENCY_CONFLICT', 'the same key with another request (another revision) is refused');
$refused($act($who['idoc2'], $g(1), 'issue'), 'assigned_to_other', 'another shop employee: the order is routed to someone else', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$refused($act($who['idoc'], $g(1), 'issue'), 'document_exists', 'the assignee again with a new key: document_exists');
$check($docsMade(1) === 1, 'still one document');
$refused($act($who['idoc'], $g(1), 'send-mail'), 'resend_confirmation_required', 'a second e-mail needs the confirmation');
$s1 = $ok($act($who['idoc'], $g(1), 'send-mail', ['confirmResend' => true]), 'resend');
$check($s1['result'] === 'sent' && ($th->smartbillMails[$id(1)] ?? 0) === 2, 'confirmed: sent once more');
$refused($act($who['imgr'], $g(1), 'reissue', ['reasonCode' => 'order_changed']), 'reissue_attestation_required', 'a re-issue of an invoice needs the manager\'s statement that the previous one was cancelled');
$re = $ok($act($who['imgr'], $g(1), 'reissue', ['reasonCode' => 'order_changed', 'previousCancelled' => true, 'reasonNote' => 'Factura anterioară stornată în SmartBill.']), 're-issue');
$check($re['result'] === 'issued' && $docsMade(1) === 2, 'the manager re-issues: a new document');
$timeline = $read($who['imgr'], $g(1))['timeline'];
$applied = array_values(array_filter($timeline, static fn (array $row): bool => $row['state'] === 'applied'));
$rejected = array_values(array_filter($timeline, static fn (array $row): bool => $row['state'] === 'rejected'));
$check(array_column($applied, 'action') === ['issue', 'send_mail', 'reissue'] && $applied[2]['reasonCode'] === 'order_changed' && $applied[2]['role'] === 'manager'
    && array_column($rejected, 'outcome') === ['revision_changed', 'document_exists', 'resend_confirmation_required', 'reissue_attestation_required'], 'the Arasya timeline: issue, e-mail, re-issue applied once each (role, reason code); every refusal recorded with its code: ' . json_encode(array_map(static fn (array $r): array => [$r['action'], $r['state'], $r['outcome']], $timeline)));

// ---- 6. SmartBill's outcome unknown; lost answers; 5xx; never received -----------------------------------------------------
foreach ([4, 5, 6, 9, 10] as $n) $prepare($g($n));
$ok($scanQr($g(4), 'imgr'), 'the manager scans too');
$th->invoiceOutcome = 'unknown';
$u = $ok($act($who['idoc'], $g(4), 'issue'), 'unknown issue');
$check($u['state'] === 'applied' && $u['result'] === 'unknown' && $u['summary']['operation']['state'] === 'unknown' && in_array('operation_unknown', $u['summary']['blockers']['issue'], true), 'SmartBill did not answer the shop: unknown; the shop blocks a new document');
$refused($act($who['imgr'], $g(4), 'issue'), 'operation_unknown', 'a new attempt is refused by the shop');
$error($act($who['idoc'], $g(4), 'resolve', ['target' => 'document', 'resolution' => 'issued']), 403, 'CONFIRMARE_INVOICE_DENIED', 'only a manager resolves');
$res = $ok($act($who['imgr'], $g(4), 'resolve', ['target' => 'document', 'resolution' => 'issued', 'reasonNote' => 'Documentul există în SmartBill.']), 'resolve issued');
$refused($act($who['idoc'], $g(4), 'issue'), 'document_exists', 'after the manager confirmed the document exists, never a second one');
$check($res['result'] === 'issued' && $docsMade(4) === 1, 'one document for the unknown outcome');
$th->afterInvoice = 'answer_lost';
$lost = $ok($act($who['idoc'], $g(5), 'issue', [], 'inv-lost-0001-' . $suffix), 'lost answer');
$check($lost['state'] === 'unknown' && $docsMade(5) === 1, 'the shop issued, the answer was lost: unknown in Arasya');
$refused($act($who['imgr'], $g(5), 'issue'), 'invoice_action_open', 'while an action is unsettled no other invoice action starts', 'CONFIRMARE_INVOICE_NOT_ALLOWED');
$rec = $ok($call($who['idoc'], 'POST', '/confirmare/orders/' . rawurlencode($g(5)) . '/invoice/reconcile', ['idempotencyKey' => 'inv-lost-0001-' . $suffix]), 'reconcile lost');
$check($rec['state'] === 'applied' && $rec['result'] === 'issued' && $docsMade(5) === 1 && (string) $one("SELECT state FROM order_invoice_actions WHERE idempotency_key = ?", ['inv-lost-0001-' . $suffix]) === 'applied', 'reconciled with the shop\'s stored answer: applied once');
$th->afterInvoice = 'crash';
$crash = $ok($act($who['idoc'], $g(6), 'issue', [], 'inv-crash-0001-' . $suffix), '5xx');
$check($crash['state'] === 'unknown', 'a 5xx after the shop ran the action: unknown');
$mrec = $ok($call($who['imgr'], 'POST', '/confirmare/orders/' . rawurlencode($g(6)) . '/invoice/reconcile', ['idempotencyKey' => 'inv-crash-0001-' . $suffix]), 'manager reconciles another employee\'s action');
$check($mrec['state'] === 'applied' && $docsMade(6) === 1, 'a manager settles it: one document');
$rev9 = $read($who['idoc'], $g(9))['summary']['revision'];
$th->fail = 'SOURCE_UNREACHABLE';
$never = $ok($act($who['idoc'], $g(9), 'issue', [], 'inv-never-0001-' . $suffix, $rev9), 'never received');
$th->fail = null;
$check($never['state'] === 'unknown' && $docsMade(9) === 0, 'the request never reached the shop: unknown in Arasya, nothing made');
$early = $ok($call($who['idoc'], 'POST', '/confirmare/orders/' . rawurlencode($g(9)) . '/invoice/reconcile', ['idempotencyKey' => 'inv-never-0001-' . $suffix]), 'early reconcile');
$check($early['state'] === 'unknown' && ($early['reachable'] ?? null) === true, 'the shop has no record yet: still unknown (no guess)');
$pdo->prepare('UPDATE order_invoice_actions SET requested_at = DATE_SUB(requested_at, INTERVAL 10 MINUTE) WHERE idempotency_key = ?')->execute(['inv-never-0001-' . $suffix]);
$refused($call($who['idoc'], 'POST', '/confirmare/orders/' . rawurlencode($g(9)) . '/invoice/reconcile', ['idempotencyKey' => 'inv-never-0001-' . $suffix]), 'not_received', 'past the reconciliation window the action was never received');
$i9 = $ok($act($who['idoc'], $g(9), 'issue'), 'issue after not received');
$check($i9['result'] === 'issued' && $docsMade(9) === 1, 'then the order is issued once');

// ---- 7. Two employees at once ---------------------------------------------------------------------------------------------
if (function_exists('pcntl_fork')) {
    $ok($scanQr($g(10), 'imgr'), 'the manager scans g10');
    $revision = $read($who['idoc'], $g(10))['summary']['revision'];
    $files = [];
    $children = [];
    foreach (['idoc', 'imgr'] as $index => $name) {
        $files[$index] = tempnam(sys_get_temp_dir(), 'invoice');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $childContainer = new Container($config, null, $transport);
            usleep(20000);
            $response = T::call($childContainer->kernel(), 'POST', '/confirmare/orders/' . rawurlencode($g(10)) . '/invoice/issue', ['expectedRevision' => $revision],
                ['origin' => T::ORIGIN, 'x-csrf-token' => $who[$name]['csrf'], 'idempotency-key' => "inv-race-{$index}-{$suffix}"], $who[$name]['cookie']);
            file_put_contents($files[$index], json_encode(['status' => $response['status'], 'body' => $response['body']]));
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    $results = array_map(static fn (string $file): array => json_decode((string) file_get_contents($file), true) ?: [], $files);
    array_map('unlink', $files);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [200, 409] && (int) $one("SELECT COUNT(*) FROM order_invoice_actions WHERE global_order_id = ? AND action = 'issue'", [$g(10)]) === 1, 'two employees issue at the same moment: one action reaches the shop, the other is refused: ' . json_encode($results));
}

// ---- 8. Observe mode (Outlet Perdele) and the shop scopes -------------------------------------------------------------------
$op->invoiceActions = false;
$approve($who['iout'], $g(50, 'outletperdele'));
$o = $read($who['iout'], $g(50, 'outletperdele'));
$check($o['summary']['mode'] === 'observe' && $o['allowedActions'] === [] && in_array('invoice_observe', $o['blockers']['issue'], true), 'Outlet Perdele in observe: the state is readable, no action');
$error($act($who['iout'], $g(50, 'outletperdele'), 'issue'), 409, 'CONFIRMARE_INVOICE_DISABLED', 'observe: an action is refused before the shop is asked');
$check(($op->smartbillDocuments[$id(50)] ?? 0) === 0 && $docsMade(50) === 0, 'nothing made in either shop');

// ---- 8b. API 2.47.0: the read-only SmartBill payment reconciliation of the final invoice -------------------------------------
$payment = static function (array $w, string $gid) use ($get, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $get($w, '/confirmare/orders/' . rawurlencode($gid) . '/invoice/payment'); };
$th->invoicePayment = ['state' => 'association_pending', 'severity' => 'pending', 'code' => 'advance_not_associated', 'checkedAt' => '2026-10-26T20:00:00Z', 'currency' => 'RON',
    'invoice' => ['series' => 'SMART', 'number' => '0042'], 'smartbill' => ['total' => '1000.00', 'paid' => '0.00', 'unpaid' => '1000.00'], 'arasya' => ['total' => '1000.00', 'verified' => '300.00', 'remaining' => '700.00', 'excess' => '0.00']];
$reads0 = $th->invoicePaymentReads;
$pf = $ok($payment($who['ifin'], $g(1)), 'finance reads the SmartBill payment state');
$check($pf['available'] === true && $pf['role'] === 'finance' && $pf['payment']['state'] === 'association_pending' && $pf['payment']['smartbill']['unpaid'] === '1000.00' && $pf['payment']['arasya']['remaining'] === '700.00' && $th->invoicePaymentReads === $reads0 + 1,
    'finance: SmartBill shows 1 000 unpaid, Arasya verified 300: association pending (both amounts shown, nothing stored)');
$check(($detail($who['isup'], $g(1))['invoice']['payment'] ?? null) === false, 'capabilities: no payment reconciliation for a Confirmare supervisor alone (the document employee has it, see above)');
$ok($payment($who['imgr'], $g(1)), 'the invoice manager reads it');
$ok($payment($who['idoc'], $g(1)), 'the document employee reads it');
$error($payment($who['iview'], $g(1)), 403, 'CONFIRMARE_INVOICE_DENIED', 'a document viewer without an invoice scope cannot read it');
$error($payment($who['isup'], $g(1)), 403, 'CONFIRMARE_INVOICE_DENIED', 'a Confirmare supervisor alone cannot read it');
$error($payment($who['imgrop'], $g(1)), 404, 'CONFIRMARE_NOT_FOUND', 'the Outlet Perdele invoice manager cannot read a Trend Home order');
// Owner remediation 2026-10-26: SmartBill fully paid while Arasya still waits for 700 is attention, never settled; a cached
// answer keeps the time of the real SmartBill read.
$th->invoicePayment = ['state' => 'paid_not_verified', 'severity' => 'attention', 'code' => 'smartbill_paid_arasya_owed', 'checkedAt' => '2026-10-26T19:59:40Z', 'cached' => true, 'currency' => 'RON',
    'invoice' => ['series' => 'SMART', 'number' => '0042'], 'smartbill' => ['total' => '1000.00', 'paid' => '1000.00', 'unpaid' => '0.00'], 'arasya' => ['total' => '1000.00', 'verified' => '300.00', 'remaining' => '700.00', 'excess' => '0.00']];
$pnv = $ok($payment($who['ifin'], $g(1)), 'SmartBill fully paid, Arasya still owed');
$check($pnv['available'] === true && $pnv['payment']['state'] === 'paid_not_verified' && $pnv['payment']['severity'] === 'attention' && $pnv['payment']['cached'] === true && $pnv['payment']['checkedAt'] === '2026-10-26T19:59:40Z',
    'SmartBill 1 000 paid while Arasya verified 300: attention (paid_not_verified), the cached answer keeps its SmartBill read time: ' . json_encode($pnv['payment']));
$check($pf['payment']['cached'] === false, 'an answer without `cached` is a fresh read (cached false)');
foreach ([['settled', 'attention'], ['paid_not_verified', 'ok']] as [$st, $sv]) {
    $th->invoicePayment = ['state' => $st, 'severity' => $sv] + $th->invoicePayment;
    $r = $ok($payment($who['ifin'], $g(1)), "{$st} with severity {$sv}");
    $check($r['available'] === false && $r['reason'] === 'source_response_invalid', "a shop answer {$st}/{$sv} is refused (each state has one severity)");
}
$th->invoicePayment = ['state' => 'association_pending', 'severity' => 'pending', 'cached' => 'yes'] + $th->invoicePayment;
$r = $ok($payment($who['ifin'], $g(1)), 'cached not a boolean');
$check($r['available'] === false && $r['reason'] === 'source_response_invalid', 'a shop answer whose `cached` is not a boolean is refused');
$th->invoicePayment = ['state' => 'matched', 'severity' => 'attention', 'code' => null, 'checkedAt' => '2026-10-26T20:00:00Z', 'currency' => 'RON', 'invoice' => null, 'smartbill' => ['total' => '1000.00', 'paid' => '300.00', 'unpaid' => '700.00'], 'arasya' => null];
$bad = $ok($payment($who['ifin'], $g(1)), 'a malformed shop answer');
$check($bad['available'] === false && $bad['reason'] === 'source_response_invalid', 'a shop answer whose severity does not fit its state is refused (never shown as a state): ' . json_encode($bad));
$th->invoicePayment = null;
$none = $ok($payment($who['ifin'], $g(1)), 'no final invoice');
$check($none['payment']['state'] === 'no_invoice' && $none['payment']['smartbill'] === null, 'no final invoice yet: no_invoice');
$check((int) $one("SELECT COUNT(*) FROM order_invoice_reads WHERE outcome = 'payment_association_pending'") === 3 && (int) $one("SELECT COUNT(*) FROM order_invoice_reads WHERE outcome = 'payment_no_invoice'") === 1
    && (int) $one("SELECT COUNT(*) FROM order_invoice_reads WHERE outcome = 'payment_paid_not_verified'") === 1, 'every payment read is audited with its state only');
$error(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($g(1)) . '/invoice/payment', [], ['origin' => T::ORIGIN, 'x-csrf-token' => $who['ifin']['csrf'], 'idempotency-key' => 'inv-pay-post-0001'], $who['ifin']['cookie']), 405, 'METHOD_NOT_ALLOWED', 'the payment state is read-only (GET only)');

// ---- 9. No link, number, amount or customer data in the audit or the log ----------------------------------------------------
$dump = json_encode([$all('SELECT * FROM order_invoice_actions'), $all('SELECT * FROM order_invoice_reads'), $all("SELECT action, metadata_json FROM iam_audit_events WHERE action = 'employee.invoice_scopes_changed'")], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
$check(!str_contains((string) $dump, 'smartbill.ro') && !str_contains((string) $dump, 'Factur') && !str_contains((string) $dump, '719.00') && preg_match('/0744\s?666\s?777|744666777/', (string) $dump) !== 1, 'the invoice audit holds codes and hashes only');
$check(preg_match('/0744\s?666\s?777|744666777|smartbill\.ro/', (string) file_get_contents($logFile)) !== 1, 'the server log holds no telephone number and no SmartBill link');
@unlink($logFile);

// Leave the shared disposable database as the next suites expect it: no Confirmare, finance or invoice state (041's tables stay).
$clearInvoice();
foreach (['order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL, cancellation_state = NULL, production_stopped_at = NULL WHERE workflow_cohort IS NOT NULL OR cancellation_state IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare invoice integration: {$checks} checks (fail closed without migration 041, root-only scopes, roles, the confirming operator never invoices, handoff gate in every issuable state, routing, QR scan, advance proforma, paid-advance queue, no production power, cancellation hold, finance action, revision, idempotency, one document, resend confirmation, re-issue statement, unknown outcome, lost answer, 5xx, never received, two employees at once, observe, no leak)\n");
