<?php

declare(strict_types=1);

// The root intervention desk (API 2.57.0) on a disposable database, through the HTTP kernel, with an in-process TEST
// Trend Home shop (protocol v1, TEST amounts only). Replays the TH 64871 incident of 2026-11-04: an approved COD order
// whose advance request went back as "amount unclear", changed in the shop after the approval (a line added), with no
// Confirmare supervisor for the source, and an advance paid above what is owed. Covers: root only (another Dashboard user
// and a Staff-only employee are refused), the stuck list and its reasons, the unstaffed source, the order detail (money,
// proforma and request read as root), a written reason for every change (refusals audited too), reopen of a stale
// approval, approval on the owner's declaration (call recorded under root), a replacement proforma that moves the open
// request to it, the advance recorded and verified alone with the over-payment confirmed, and that Staff routes still
// keep root away from money and proformas.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
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
    fwrite(STDOUT, "SKIP MySQL root intervention integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-rin-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const RIN_KEY = 'trendhome-access-key-test-0123456789abcdef-rin';
const RIN_PHONE = '0711555444';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . RIN_KEY, 'finance' => 'record', 'invoice' => 'enforce', 'verification' => 'single'],
]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
// A database a schema-rollback suite ran on keeps the 039 capability CHECK; 044 (idempotent) restores `verify`.
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/044_finance_verification_requests.sql');
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
foreach (['finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision', 'confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations',
    'invoice_efactura_tasks', 'order_source_operations', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes',
    'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts',
    'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec("DELETE FROM iam_audit_events WHERE action LIKE 'intervention.%'");
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$th = new SourceAccessFakeShop('trendhome', 'th-test', RIN_KEY);
$th->safetyRules = true;
$th->financeVerification = 'single';
$container = new Container($config, $pdo, $th);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "rin.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-rin-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Intervention root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Intervention root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['origin' => T::ORIGIN, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'rin-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'intervention test passphrase 2026';
$uuids = [];
foreach (['rop' => ['staff'], 'fin' => ['staff'], 'boss' => ['dashboard', 'staff'], 'shopemp' => ['staff']] as $name => $apps) {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-rin');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
}
// Like the incident: one operator of Trend Home, nobody supervising it; one accountant with record + verify.
$ok($asRoot('PUT', "/management/employees/{$uuids['rop']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'operator scope');
$ok($asRoot('PUT', "/management/employees/{$uuids['fin']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => [], 'verify' => ['trendhome']]), 'accountant scope');
// The shop employee: SmartBill issue, the production document role and its Trend Home document scope (like Staff).
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$ok($asRoot('PUT', "/management/employees/{$uuids['shopemp']}/roles", ['roleIds' => [$roleIds['production-documents-operator']]]), 'shop employee role');
$ok($asRoot('PUT', "/management/employees/{$uuids['shopemp']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 'shop employee document scope');
$ok($asRoot('PUT', "/management/employees/{$uuids['shopemp']}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]), 'shop employee scope');
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$who = ['rop' => $login('rop'), 'fin' => $login('fin'), 'boss' => $login('boss')];

$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL root intervention integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
(new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find('trendhome'), $activatedAt, 'Test Owner', 'intervention test', 'rin-test', true);
T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
(new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find('trendhome'), 'Test Owner', 'intervention test', 'rin-test', true);
$orderId = (string) T::freeOrderBase($pdo, 640000, 659999, 10);
$gid = 'trendhome:' . $orderId;
$tick = 0;
$line = static fn (int $id, string $name, string $sku): array => ['id' => $id, 'line' => $id - 9600, 'name' => $name, 'sku' => $sku, 'color' => 'Bej', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1];
$payload = static function (string $pricing, array $lines, ?array $financial = null, ?string $forOrder = null) use ($orderId, $suffix, &$tick, $iso, $activatedAt): array {
    $orderId = $forOrder ?? $orderId;
    $tick++;
    $p = T::sourceOrder($orderId, "rin-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', $lines);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $financial ?? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Interventie', 'street' => 'Str. Exemplu 3', 'city' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => RIN_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify('+60 seconds')), 'evidence' => 'checkout'];
    return $p;
};
$priceA = 'sha256:' . hash('sha256', "rin-price-a-{$orderId}");
$priceB = 'sha256:' . hash('sha256', "rin-price-b-{$orderId}");
$pf = static fn (string $number): array => ['document' => ['series' => 'TPF', 'number' => $number, 'at' => time() - 600, 'by' => '00000000-0000-4000-8000-0000000000a1'], 'op' => 'none', 'opKey' => null, 'mail' => 'sent', 'mailCount' => 1,
    'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => [], 'reminders' => []];
$ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')])), 'the order arrives');
$th->orders[$orderId] = ['id' => $orderId, 'number' => $orderId, 'eligible' => true, 'phone' => RIN_PHONE, 'pricing' => $priceA,
    'finance' => ['cod' => true, 'total' => '421.02', 'collected' => '0.00', 'pricing' => $priceA], 'invoice' => ['cod' => true, 'cleared' => false, 'pf' => $pf('0027')]];
$check($service->distribute()['assigned'] >= 1, 'the order goes to its operator');
$call = static fn (array $w, string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => 'rin-' . bin2hex(random_bytes(10))], $w['cookie']);
$get = static fn (array $w, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $w['cookie']);
$version = static fn (): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$gid]);
$orderPath = '/confirmare/orders/' . rawurlencode($gid);
$review = $ok($get($who['rop'], $orderPath), 'operator detail')['review'];
$ok($get($who['rop'], $orderPath . '/source'), 'operator reads the shop order');
$ok($call($who['rop'], 'POST', $orderPath . '/contact', ['expectedVersion' => $version(), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]), 'operator call');
$ok($call($who['rop'], 'POST', $orderPath . '/approve', ['expectedVersion' => $version(), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]), 'operator approves');
$asked = $ok($call($who['rop'], 'POST', $orderPath . '/finance-request', ['purpose' => 'advance']), 'operator asks finance');
$rid = $asked['request']['id'];
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$task = $ok($get($who['fin'], '/confirmare/finance/requests/' . $rid), 'accountant opens the task');
$started = $ok($call($who['fin'], 'POST', '/confirmare/finance/requests/' . $rid . '/start', ['expectedVersion' => $task['request']['version']]), 'start');
$ok($call($who['fin'], 'POST', '/confirmare/finance/requests/' . $rid . '/decide', ['expectedVersion' => $started['request']['version'], 'outcome' => 'needs_clarification', 'reasonCode' => 'amount_unclear']), 'amount unclear');
$check($one('SELECT state FROM finance_verification_requests WHERE request_uuid = ?', [$rid]) === 'needs_clarification', 'the incident state: the request waits for a clarification');

// ---- 1. Root only ---------------------------------------------------------------------------------------------------
$error($get($who['boss'], '/management/interventions'), 403, 'ROOT_ONLY', 'another Dashboard user never sees the desk');
$error($get($who['rop'], '/management/interventions'), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff-only employee neither');
$error($call($who['boss'], 'POST', '/management/interventions/orders/' . rawurlencode($gid) . '/reopen', ['reason' => 'test reason', 'expectedVersion' => $version()]), 403, 'ROOT_ONLY', 'nor acts through it');
$error(T::call($kernel, 'POST', '/management/interventions/orders/' . rawurlencode($gid) . '/reopen', ['reason' => 'no csrf', 'expectedVersion' => $version()], ['origin' => T::ORIGIN, 'idempotency-key' => 'rin-nocsrf-' . $suffix . '-0001'], $root['cookie']), 403, 'CSRF_INVALID', 'a change needs the CSRF token');

// ---- 2. The stuck list ------------------------------------------------------------------------------------------------
$list = $ok($asRoot('GET', '/management/interventions'), 'root opens the desk');
$item = array_values(array_filter($list['items'], static fn (array $i): bool => $i['globalOrderId'] === $gid))[0] ?? null;
$check($item !== null && $item['status'] === 'approved' && in_array('payment_waiting', $item['blockers'], true) && in_array('request_needs_clarification', $item['blockers'], true) && in_array('no_supervisor', $item['blockers'], true),
    'the order is listed as stuck: advance not proven, request waiting for a clarification, no supervisor ' . json_encode($item));
$staffing = array_column($list['sources'], null, 'source');
$check($staffing['trendhome']['operators'] === 1 && $staffing['trendhome']['supervisors'] === 0, 'the unstaffed source is named (one operator, no supervisor)');
$byNumber = $ok(T::call($kernel, 'GET', '/management/interventions', null, ['origin' => T::ORIGIN], $root['cookie'], ['number' => $orderId]), 'search by number');
$check(count($byNumber['items']) === 1 && $byNumber['items'][0]['orderNumber'] === $orderId, 'one order by its number');
$error(T::call($kernel, 'GET', '/management/interventions', null, ['origin' => T::ORIGIN], $root['cookie'], ['view' => 'everything']), 422, 'INTERVENTION_INVALID', 'an unknown view');

// ---- 3. The order -----------------------------------------------------------------------------------------------------
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$detail = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'order detail');
$check($detail['confirmare']['status'] === 'approved' && ($detail['finance']['available'] ?? false) === true && ($detail['invoice']['available'] ?? true) !== false && ($detail['request']['request']['state'] ?? null) === 'needs_clarification',
    'root reads the Confirmare state, the shop money, the proforma and the request ' . json_encode(['f' => $detail['finance']['available'] ?? null, 'i' => $detail['invoice']['available'] ?? null, 'r' => $detail['request']]));
$check(in_array('payment', $detail['missing'], true) && $detail['operators'][0]['displayName'] === 'Test rop', 'what clearance misses and who can be assigned');
$check((int) $one("SELECT COUNT(*) FROM order_finance_reads WHERE employee_uuid = (SELECT employee_uuid FROM system_root_identity)") >= 1, 'the money read is recorded under root');

// ---- 4. A written reason for every change; refusals audited --------------------------------------------------------
$act = static fn (string $action, array $body): array => $asRoot('POST', '/management/interventions/orders/' . rawurlencode($gid) . '/' . $action, $body);
$error($act('reopen', ['expectedVersion' => $version()]), 422, 'INTERVENTION_REASON_REQUIRED', 'no reason, no change');
$error($act('reopen', ['reason' => 'x', 'expectedVersion' => $version()]), 422, 'INTERVENTION_REASON_REQUIRED', 'a reason of one letter neither');
$error($act('reopen', ['reason' => 'Siparis tekrar acilmali', 'expectedVersion' => $version(), 'force' => true]), 400, 'INVALID_REQUEST', 'only the documented fields');
$error($act('reopen', ['reason' => 'Siparis tekrar acilmali', 'expectedVersion' => $version()]), 409, 'CONFIRMARE_NOT_REOPENABLE', 'an approval the shop did not change is not reopened (the Staff rule holds)');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'intervention.reopen' AND target_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.outcome')) = 'refused' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.code')) = 'CONFIRMARE_NOT_REOPENABLE'", [$gid]) === 1, 'the refusal is audited with its reason');
$error($act('dance', ['reason' => 'Unknown action here']), 404, 'NOT_FOUND', 'an unknown action');

// ---- 5. The shop changes the approved order (a line added) -----------------------------------------------------------
$ok(T::ingest($kernel, 'trendhome', $payload($priceB, [$line(9601, 'Draperie Bella', 'bella-4516'), $line(9602, 'Rejansa K7050', 'K7050-')])), 'the shop adds a line after the approval');
$th->orders[$orderId]['pricing'] = $priceB;
$th->orders[$orderId]['finance']['pricing'] = $priceB;
$th->orders[$orderId]['finance']['total'] = '499.02';
$check(array_intersect(\Arasya\Operations\Confirmare\ConfirmationClearance::missing($pdo, (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$gid])), ['lines', 'sync', 'price']) !== [], 'the approval is stale');
$error($call($who['rop'], 'POST', $orderPath . '/reopen', ['expectedVersion' => $version()]), 403, 'CONFIRMARE_SCOPE_DENIED', 'the operator cannot reopen it (no supervisor exists: the incident)');
$reopened = $ok($act('reopen', ['reason' => 'Musteri rejansa istedi, siparis yeniden acildi', 'expectedVersion' => $version()]), 'root reopens');
$check($reopened['status'] === 'pending', 'pending again');
$event = $all("SELECT action, actor_kind, actor_employee_uuid FROM order_confirmation_events WHERE global_order_id = ? ORDER BY version_after DESC LIMIT 1", [$gid])[0];
$check($event['action'] === 'reopened' && $event['actor_employee_uuid'] === $one('SELECT employee_uuid FROM system_root_identity'), 'the event names root, not an employee');
$assigned = $ok($act('assign', ['reason' => 'Iuliana devam etsin', 'expectedVersion' => $version(), 'employeeId' => $uuids['rop']]), 'root assigns it to the operator');
$check(($assigned['assignment']['employeeId'] ?? $assigned['assignee']['id'] ?? null) === $uuids['rop'] || $one('SELECT assigned_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$gid]) === $uuids['rop'], 'assigned to the operator');

// ---- 6. Approval on the owner's declaration ------------------------------------------------------------------------
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$approved = $ok($act('approve', ['reason' => 'Musteri ile ben konustum, onayliyorum', 'expectedVersion' => $version()]), 'root approves');
$check($approved['status'] === 'approved', 'approved');
$contact = $all("SELECT employee_uuid, outcome FROM order_confirmation_contacts WHERE global_order_id = ? ORDER BY attempt_number DESC LIMIT 1", [$gid])[0] ?? [];
$check(($contact['outcome'] ?? null) === 'confirmed' && $contact['employee_uuid'] === $one('SELECT employee_uuid FROM system_root_identity'), 'the confirmed call is recorded under root (never under the operator)');
$check($one('SELECT decided_by_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$gid]) === $one('SELECT employee_uuid FROM system_root_identity'), 'decided by root');

// ---- 7. Staff routes still keep root away from money and proformas ---------------------------------------------------
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$fin = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail after approval');
$revision = (string) ($fin['finance']['summary']['revision'] ?? '');
$check(preg_match('/^sha256:[0-9a-f]{64}$/D', $revision) === 1, 'the money revision as root ' . json_encode($fin['finance']));
$staffFinance = $asRoot('POST', $orderPath . '/finance/submit-transfer', ['expectedRevision' => $revision, 'purpose' => 'advance', 'amount' => '150.00', 'reference' => 'ROOTSTAFF01', 'valueDate' => gmdate('Y-m-d'), 'method' => 'bank_transfer']);
$check(in_array($staffFinance['status'], [403, 404], true), 'the Staff finance route still refuses root ' . json_encode($staffFinance['body']));
$staffProforma = $asRoot('POST', $orderPath . '/proforma/issue', ['expectedRevision' => (string) ($fin['invoice']['summary']['revision'] ?? 'sha256:' . str_repeat('0', 64)), 'replaceOutdated' => true]);
$check(in_array($staffProforma['status'], [403, 404], true), 'the Staff proforma route still refuses root ' . json_encode($staffProforma['body']));

// ---- 8. A replacement proforma moves the open request to it ----------------------------------------------------------
$invoiceRevision = (string) ($fin['invoice']['summary']['revision'] ?? '');
$accept = $act('proforma', ['reason' => 'Yeni toplam 499,02 kabul', 'route' => 'accept-total', 'fields' => ['expectedRevision' => $invoiceRevision, 'declaration' => true]]);
$check($accept['status'] === 200 || ($accept['body']['error']['code'] ?? '') === 'CONFIRMARE_INVOICE_REFUSED', 'root accepts the new total through the shop (or the shop answers why not) ' . json_encode($accept['body']));
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$fin = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail before the new proforma');
$error($act('proforma', ['reason' => 'Eski proforma yerine yenisi', 'route' => 'issue', 'fields' => ['expectedRevision' => (string) ($fin['invoice']['summary']['revision'] ?? ''), 'replaceOutdated' => true]]), 409, 'CONFIRMARE_INVOICE_REFUSED', 'a second proforma while the first stands: the shop refuses (proforma_exists)');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$fin = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail before the cancellation');
$cancelled = $ok($act('proforma', ['reason' => 'Toplam degisti, eski proforma iptal', 'route' => 'cancel', 'fields' => ['expectedRevision' => (string) $fin['invoice']['summary']['revision'], 'reasonCode' => 'order_changed', 'confirmNoPayment' => true]]), 'root cancels the old proforma');
$check(($cancelled['result'] ?? null) === 'cancelled', 'cancelled in the shop');
$check($one('SELECT proforma_number FROM finance_verification_requests WHERE request_uuid = ?', [$rid]) === '0027', 'the request still names the cancelled proforma (nothing new yet)');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$fin = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail before the new proforma');
$issued = $ok($act('proforma', ['reason' => 'Yeni proforma 499,02 uzerinden', 'route' => 'issue', 'fields' => ['expectedRevision' => (string) $fin['invoice']['summary']['revision']]]), 'root issues the new proforma');
$check(($issued['result'] ?? null) === 'issued', 'issued');
$request = $all('SELECT proforma_series, proforma_number, state, version FROM finance_verification_requests WHERE request_uuid = ?', [$rid])[0];
$newNumber = (string) ($th->orders[$orderId]['invoice']['pf']['document']['number'] ?? '');
$check($newNumber !== '' && $newNumber !== '0027' && $request['proforma_number'] === $newNumber && $request['state'] === 'needs_clarification', 'the open request follows the new proforma and keeps its state ' . json_encode([$request, $newNumber]));
$check((int) $one("SELECT COUNT(*) FROM finance_verification_request_events WHERE request_uuid = ? AND action = 'proforma_replaced'", [$rid]) === 1, 'one proforma_replaced event');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$taskNow = $ok($get($who['fin'], '/confirmare/finance/requests/' . $rid), 'the accountant reopens the task');
$check(($taskNow['live']['proformaChanged'] ?? true) === false, 'the task no longer says "the proforma changed" (the incident screen)');

// ---- 9. The advance found on the statement, above what is owed --------------------------------------------------------
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$fin = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail before the advance');
$error($act('advance', ['reason' => 'Bankada 150 lei var', 'expectedRevision' => (string) $fin['finance']['summary']['revision'], 'amount' => '600.00', 'reference' => 'ROOTBANK0001', 'valueDate' => gmdate('Y-m-d'), 'overpayment' => false]), 409, 'CONFIRMARE_FINANCE_REFUSED', 'above what is owed without the confirmation: the shop refuses');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$fin = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail again');
$advance = $ok($act('advance', ['reason' => 'Bankada 150 lei var, fazla odeme onayli', 'expectedRevision' => (string) $fin['finance']['summary']['revision'], 'amount' => '600.00', 'reference' => 'ROOTBANK0002', 'valueDate' => gmdate('Y-m-d'), 'overpayment' => true]), 'root records and verifies the advance');
$check($advance['step'] === 'verified', 'recorded and verified alone (single policy) ' . json_encode($advance));
$entry = array_values(array_filter($th->orders[$orderId]['finance']['entries'] ?? [], static fn (array $e): bool => $e['reference'] === 'ROOTBANK0002'))[0] ?? [];
$check(in_array('overpayment', $entry['flags'] ?? [], true) && in_array('single_verification', $entry['flags'] ?? [], true) && ($entry['state'] ?? null) === 'verified', 'the shop entry carries the over-payment and single-verification flags ' . json_encode($entry));
$requestAfter = $all('SELECT state, outcome FROM finance_verification_requests WHERE request_uuid = ?', [$rid])[0];
$check($requestAfter['state'] === 'verified', 'the waiting request is settled by the recorded money ' . json_encode($requestAfter));
$check($one("SELECT employee_uuid FROM order_finance_actions WHERE global_order_id = ? ORDER BY requested_at DESC LIMIT 1", [$gid]) === $one('SELECT employee_uuid FROM system_root_identity'), 'the money actions name root');

// ---- 9b. Cleared: the order waits for the shop employee; root hands it over ------------------------------------------
$ok(T::ingest($kernel, 'trendhome', $payload($priceB, [$line(9601, 'Draperie Bella', 'bella-4516'), $line(9602, 'Rejansa K7050', 'K7050-')], ['state' => 'cleared', 'basis' => 'advance_collected', 'pricingFingerprint' => $priceB])), 'the shop reports the advance collected');
$check((int) $one('SELECT production_cleared FROM order_confirmations WHERE global_order_id = ?', [$gid]) === 1, 'production is cleared');
$stuck = $ok($asRoot('GET', '/management/interventions'), 'the desk after the clearance');
$mine = array_values(array_filter($stuck['items'], static fn (array $i): bool => $i['globalOrderId'] === $gid))[0] ?? null;
$check($mine !== null && in_array('handoff_waiting', $mine['blockers'], true), 'cleared but not handed to a shop employee: listed ' . json_encode($mine));
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$detail = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail before the handoff');
$check(in_array($uuids['shopemp'], array_column($detail['shop']['candidates'], 'id'), true) && $detail['shop']['employeeId'] === null, 'the shop employees who can take it ' . json_encode($detail['shop']));
$check(($detail['operations']['allowed']['reactivate'] ?? null) === true && is_array($detail['sync']) && $detail['production']['stage'] === 'waiting', 'operations, sync and production are shown');
$ok($act('shop-assign', ['reason' => 'Magaza calisanina devir', 'employeeId' => $uuids['shopemp'], 'expectedAssigneeId' => null]), 'root hands it to the shop employee');
$check($one('SELECT employee_uuid FROM order_shop_assignments WHERE active_order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)', [$gid]) === $uuids['shopemp'], 'routed to the shop employee');
$after = $ok($asRoot('GET', '/management/interventions'), 'the desk after the handoff');
$check(array_filter($after['items'], static fn (array $i): bool => $i['globalOrderId'] === $gid) === [], 'the order leaves the stuck list');
$check(array_column($after['otherFlows'], 'flow') === ['trendyol', 'b2b', 'shop_legacy'], 'the other flows are counted');
$refusedInvoice = $act('invoice', ['reason' => 'Fatura denemesi', 'invoiceAction' => 'issue', 'fields' => ['expectedRevision' => 'sha256:' . str_repeat('0', 64)]]);
$check(($refusedInvoice['body']['error']['code'] ?? '') === 'CONFIRMARE_INVOICE_SAME_EMPLOYEE', 'a final invoice goes through the invoice service: root confirmed this order, so another person invoices it (the Staff rule holds) ' . json_encode($refusedInvoice['body']));
$error($act('efactura', ['reason' => 'e-Factura kontrol', 'taskId' => '00000000-0000-4000-8000-000000000000', 'expectedVersion' => 1, 'outcome' => 'accepted']), 404, 'EFACTURA_TASK_NOT_FOUND', 'an e-Factura task of another order is never touched');

// ---- 9c. A pending order: root edits its lines in the shop, a lost answer is reconciled --------------------------------
$orderId2 = (string) ((int) $orderId + 1);
$gid2 = 'trendhome:' . $orderId2;
$ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')], null, $orderId2)), 'a second order');
$th->orders[$orderId2] = ['id' => $orderId2, 'number' => $orderId2, 'eligible' => true, 'phone' => RIN_PHONE, 'pricing' => $priceA,
    'finance' => ['cod' => true, 'total' => '421.02', 'collected' => '0.00', 'pricing' => $priceA], 'invoice' => ['cod' => true, 'cleared' => false]];
$th->products['K7050-'] = ['id' => '64911', 'name' => 'Rejansa pentru sina K7050 10 cm', 'sku' => 'K7050-', 'type' => 'simple', 'purchasable' => true, 'inStock' => true, 'stock' => 100, 'variations' => [], 'calculator' => null];
$service->distribute();
$act2 = static fn (string $action, array $body): array => $asRoot('POST', '/management/interventions/orders/' . rawurlencode($gid2) . '/' . $action, $body);
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$product = $ok($act2('edit-product', ['sku' => 'K7050-']), 'root looks the product up by its SKU (a read: no reason needed)');
$check(($product['product']['id'] ?? null) === '64911', 'the shop product');
$shopVersion = 'sha256:' . hash('sha256', 'version-' . $orderId2);
$ops = [['op' => 'add_line', 'productId' => '64911', 'quantity' => 13]];
$preview = $ok($act2('edit-preview', ['expectedVersion' => $shopVersion, 'operations' => $ops]), 'preview')['preview'];
$check(preg_match('/^[0-9a-f]{64}$/', (string) ($preview['previewToken'] ?? '')) === 1, 'the shop computes the edit and gives its token');
$error($act2('edit-apply', ['expectedVersion' => $shopVersion, 'operations' => $ops, 'previewToken' => $preview['previewToken']]), 422, 'INTERVENTION_REASON_REQUIRED', 'applying needs a reason');
$th->afterApply = 'answer_lost';
$lost = $ok($act2('edit-apply', ['reason' => 'Musteri 13 m rejansa istedi', 'expectedVersion' => $shopVersion, 'operations' => $ops, 'previewToken' => $preview['previewToken']]), 'apply (the answer is lost)');
$check(($lost['state'] ?? null) === 'unknown', 'a lost answer stays unknown, never guessed');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$d2 = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid2)), 'detail with the unanswered edit');
$open = array_values(array_filter($d2['unanswered'], static fn (array $u): bool => $u['kind'] === 'edit'))[0] ?? null;
$check($open !== null && $open['state'] === 'unknown' && $open['idempotencyKey'] !== '', 'the unanswered edit is listed with its key');
$settled = $ok($act2('edit-reconcile', ['reason' => 'Yanitsiz islem kapatiliyor', 'idempotencyKey' => $open['idempotencyKey']]), 'root reconciles it');
$check(($settled['state'] ?? null) === 'applied', 'reconciled with the shop\'s stored answer: applied ' . json_encode($settled));
$check($one("SELECT employee_uuid FROM order_confirmation_source_edits WHERE global_order_id = ?", [$gid2]) === $one('SELECT employee_uuid FROM system_root_identity'), 'the edit names root');

// ---- 10. The audit ----------------------------------------------------------------------------------------------------
$audit = $all("SELECT action, JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.reason')) reason, JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.outcome')) outcome FROM iam_audit_events WHERE target_id = ? AND action LIKE 'intervention.%' ORDER BY created_at", [$gid]);
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ? AND action LIKE 'intervention.edit-%'", [$gid2]) === 2 && (int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ? AND action IN ('intervention.edit-product', 'intervention.edit-preview')", [$gid2]) === 0, 'edits are audited (apply, reconcile); reads are not');
$check(count($audit) >= 7 && array_filter($audit, static fn (array $a): bool => $a['reason'] === null || $a['reason'] === '') === [], 'every desk change is audited with its written reason (' . count($audit) . ')');
$history = $ok($asRoot('GET', '/management/interventions/orders/' . rawurlencode($gid)), 'detail with history')['interventions'];
$check(count($history) === count($audit) && $history[0]['reason'] !== null, 'the order shows the desk history');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action LIKE 'intervention.%' AND metadata_json LIKE '%" . RIN_PHONE . "%'") === 0, 'no customer data in the audit');

fwrite(STDOUT, "PASS MySQL root intervention integration: {$checks} checks (root only, stuck list and reasons, unstaffed source, order detail as root, written reason for every change, refusals audited, stale approval reopened, approval on the owner's declaration, replacement proforma moves the request, advance recorded and verified alone with the over-payment, Staff routes still refuse root, handoff to the shop employee, final invoice and e-Factura through their services, root line edits and a lost answer reconciled, other flows counted).\n");
