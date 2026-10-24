<?php

declare(strict_types=1);

// SmartBill invoicing of Confirmare orders (API 2.43.0) on a disposable database, through the HTTP kernel, with
// in-process TEST shops that speak protocol v1 and keep the invoice state like YD SOFT 2.17.0 (no SmartBill anywhere).
// Covers: fail closed before the draft schema; root-only invoice scopes; who reads (documents, manager, finance, viewer;
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
$dropDraft = static function () use ($pdo): void { foreach (['order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}"); };
$dropDraft();
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

// ---- 1. Before the draft schema: fail closed --------------------------------------------------------------------------
$error($asRoot('PUT', "/management/employees/{$uuids['idoc']}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]), 409, 'INVOICE_SCHEMA_MISSING', 'no invoice scope can be granted before the schema exists');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/drafts/smartbill-invoicing.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('employee_invoice_scopes', 'order_invoice_actions', 'order_invoice_reads')") === 3, 'the draft schema creates its three tables (applied by the test only)');
$check(!in_array('smartbill-invoicing.sql', array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []), true) && !is_file(dirname(__DIR__) . '/database/migrations/040_smartbill_invoicing.sql'), 'the draft is not a numbered migration (no 040)');

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
$check(ConfirmationGate::WORKFLOW_READY === false, 'invoicing keeps WORKFLOW_READY false');
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
foreach (range(1, 12) as $n) {
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 60 + $n, $n === 3 ? ['payment' => 'cod'] : [])), "trendhome order {$n}");
    $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => true, 'phone' => INV_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'inv-price-' . $id($n)), 'invoice' => ['cleared' => $n !== 3]];
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
foreach ([1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $n) $approve($who['iop'], $g($n));

// ---- 3. Who reads the state --------------------------------------------------------------------------------------------
$r = $read($who['idoc'], $g(1));
$check($r['available'] === true && $r['role'] === 'documents' && $r['summary']['document']['exists'] === false && $r['handoff']['state'] === 'ready' && in_array('issue', $r['allowedActions'], true), 'the production ticket employee with the issue scope: documents role, the order is ready, issuing allowed: ' . json_encode($r['allowedActions']));
$v = $read($who['iview'], $g(1));
$f = $read($who['ifin'], $g(1));
$m = $read($who['imgr'], $g(1));
$check($v['role'] === 'viewer' && $v['allowedActions'] === [] && in_array('not_allowed_for_role', $v['blockers']['issue'], true) && $f['role'] === 'finance' && $f['allowedActions'] === [] && $m['role'] === 'manager', 'a ticket employee without the scope reads as viewer, finance as finance (both read only), the manager as manager');
$check(($detail($who['iop'], $g(1))['invoice'] ?? null) === ['configured' => true, 'read' => true, 'issue' => true, 'manage' => false], 'the detail shows the invoice capabilities (presentation only)');
$error($invoice($who['inodocs'], $g(1)), 404, 'CONFIRMARE_NOT_FOUND', 'the issue scope without the production document permission reaches nothing');
$error($invoice($who['isup'], $g(1)), 404, 'CONFIRMARE_NOT_FOUND', 'a Confirmare supervisor alone does not read invoices');
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
$docPath = static fn (string $gid, string $action): string => '/production-documents/orders/' . rawurlencode($gid) . '/' . $action;
$docVersion = static fn (string $gid): int => (int) $one('SELECT document_version FROM operational_orders WHERE global_order_id = ?', [$gid]);
$qr = static fn (string $gid): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$gid]);
$production = static fn (string $gid): array => $all('SELECT production_stage_id, production_owner_employee_uuid, production_version, document_status, document_version FROM operational_orders WHERE global_order_id = ?', [$gid])[0];
$tickets = static fn (string $gid): array => [(int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$gid]), (int) $one('SELECT COUNT(*) FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id = ?', [$gid])];
$issuable = static fn (string $gid): array => [$read($who['idoc'], $gid)['handoff']['state'], in_array('issue', $read($who['idoc'], $gid)['allowedActions'], true)];
$check($issuable($g(11)) === ['ready', true], 'ready: issuing allowed');
$generated = $ok($call($who['idoc'], 'POST', $docPath($g(11), 'generate'), ['expectedDocumentVersion' => $docVersion($g(11))]), 'ticket generated', 201);
$check($issuable($g(11)) === ['print', true], 'print (ticket generated, not printed): issuing allowed');
$ok($call($who['idoc'], 'POST', $docPath($g(11), 'print'), ['revisionNumber' => (int) ($generated['document']['revisionNumber'] ?? $generated['revisionNumber'] ?? 1)]), 'ticket printed');
$check($issuable($g(11)) === ['awaiting_cutting', true], 'awaiting_cutting (printed, not scanned): issuing allowed');
$before = $production($g(11));
$ticketsBefore = $tickets($g(11));
$ok($act($who['idoc'], $g(11), 'issue'), 'issue while awaiting the cutting scan');
$check($production($g(11)) === $before && $tickets($g(11)) === $ticketsBefore && $before['production_stage_id'] === 'waiting', 'issuing moved no stage, made no ticket revision and no print');
$scan = $call($who['idoc'], 'POST', '/cutting/intake', ['qrToken' => $qr($g(11))]);
$check(in_array($scan['status'], [403, 404], true) && $production($g(11)) === $before, 'the invoice employee cannot scan the cutting QR (' . $scan['status'] . ' ' . ($scan['body']['error']['code'] ?? '') . '); the stage stays');
$generated12 = $ok($call($who['idoc'], 'POST', $docPath($g(12), 'generate'), ['expectedDocumentVersion' => $docVersion($g(12))]), 'ticket 12 generated', 201);
$ok($call($who['idoc'], 'POST', $docPath($g(12), 'print'), ['revisionNumber' => (int) ($generated12['document']['revisionNumber'] ?? $generated12['revisionNumber'] ?? 1)]), 'ticket 12 printed');
$started = $ok($call($who['icut'], 'POST', '/cutting/intake', ['qrToken' => $qr($g(12))]), 'the cutter scans');
$check(($started['outcome'] ?? null) === 'started' && $issuable($g(12)) === ['in_production', true], 'in_production (scanned by the cutter): issuing allowed');
$before12 = $production($g(12));
$ok($act($who['idoc'], $g(12), 'issue'), 'issue during production');
$check($production($g(12)) === $before12 && $before12['production_stage_id'] === 'material-preparation' && $before12['production_owner_employee_uuid'] === $cutter->employeeUuid, 'issuing during production changes neither the stage nor its owner');

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
$refused($act($who['idoc2'], $g(1), 'issue'), 'document_exists', 'another employee with a new key: document_exists');
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
$th->invoiceOutcome = 'unknown';
$u = $ok($act($who['idoc'], $g(4), 'issue'), 'unknown issue');
$check($u['state'] === 'applied' && $u['result'] === 'unknown' && $u['summary']['operation']['state'] === 'unknown' && in_array('operation_unknown', $u['summary']['blockers']['issue'], true), 'SmartBill did not answer the shop: unknown; the shop blocks a new document');
$refused($act($who['idoc2'], $g(4), 'issue'), 'operation_unknown', 'a new attempt is refused by the shop');
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

// ---- 9. No link, number, amount or customer data in the audit or the log ----------------------------------------------------
$dump = json_encode([$all('SELECT * FROM order_invoice_actions'), $all('SELECT * FROM order_invoice_reads'), $all("SELECT action, metadata_json FROM iam_audit_events WHERE action = 'employee.invoice_scopes_changed'")], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
$check(!str_contains((string) $dump, 'smartbill.ro') && !str_contains((string) $dump, 'Factur') && !str_contains((string) $dump, '719.00') && preg_match('/0744\s?666\s?777|744666777/', (string) $dump) !== 1, 'the invoice audit holds codes and hashes only');
$check(preg_match('/0744\s?666\s?777|744666777|smartbill\.ro/', (string) file_get_contents($logFile)) !== 1, 'the server log holds no telephone number and no SmartBill link');
@unlink($logFile);

// Leave the shared disposable database as the next suites expect it: no Confirmare, finance or invoice state, no draft tables.
$dropDraft();
foreach (['order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL, cancellation_state = NULL, production_stopped_at = NULL WHERE workflow_cohort IS NOT NULL OR cancellation_state IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare invoice integration: {$checks} checks (fail closed without the draft schema, root-only scopes, roles, the confirming operator never invoices, handoff gate in every issuable state, no production power, cancellation hold, finance action, revision, idempotency, one document, resend confirmation, re-issue statement, unknown outcome, lost answer, 5xx, never received, two employees at once, observe, no leak)\n");
