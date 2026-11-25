<?php

declare(strict_types=1);

// The order resolver (API 2.75.0, migration 056) on a disposable database, through the HTTP kernel, with an in-process
// TEST Trend Home shop (TEST amounts only). Covers: who may use it (root and the "Rezolvare comenzi blocate" role, never
// another Dashboard user or a Staff-only employee), the Dashboard flag, one number in and one diagnosis out, the one step
// pressed by the resolver employee (approve, send to finance, verify the advance found on the statement, hand over to the
// shop employee, issue the missing proforma, answer a clarification, close the task of a cancelled order), a stale screen
// refused (RESOLVER_CHANGED), and the audit naming the employee who pressed it while the records name root.

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
    fwrite(STDOUT, "SKIP MySQL order resolver integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-rsv-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const RSV_KEY = 'trendhome-access-key-test-0123456789abcdef-rsv';
const RSV_PHONE = '0711555444';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . RSV_KEY, 'finance' => 'record', 'invoice' => 'enforce', 'verification' => 'single'],
]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
// A database a schema-rollback suite ran on keeps the 039 capability CHECK; 044 (idempotent) restores `verify`.
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/044_finance_verification_requests.sql');
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
foreach (['finance_task_amounts', 'finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision', 'confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations',
    'invoice_efactura_tasks', 'order_source_operations', 'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes',
    'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts',
    'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec("DELETE FROM iam_audit_events WHERE action LIKE 'intervention.%'");
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$th = new SourceAccessFakeShop('trendhome', 'th-test', RSV_KEY);
$th->safetyRules = true;
$th->financeVerification = 'single';
$container = new Container($config, $pdo, $th);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "rsv.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-rsv-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Intervention root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Intervention root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['origin' => T::ORIGIN, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'rsv-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'intervention test passphrase 2026';
$uuids = [];
foreach (['rop' => ['staff'], 'fin' => ['staff'], 'boss' => ['dashboard', 'staff'], 'shopemp' => ['staff'], 'sinem' => ['dashboard']] as $name => $apps) {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-rsv');
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
$ok($asRoot('PUT', "/management/employees/{$uuids['sinem']}/roles", ['roleIds' => [$roleIds['order-resolver']]]), 'the resolver role');
$who = ['rop' => $login('rop'), 'fin' => $login('fin'), 'boss' => $login('boss'), 'sinem' => $login('sinem')];

$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL order resolver integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
(new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find('trendhome'), $activatedAt, 'Test Owner', 'intervention test', 'rsv-test', true);
T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
(new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find('trendhome'), 'Test Owner', 'intervention test', 'rsv-test', true);
$orderId = (string) T::freeOrderBase($pdo, 640000, 659999, 10);
$gid = 'trendhome:' . $orderId;
$tick = 0;
$line = static fn (int $id, string $name, string $sku): array => ['id' => $id, 'line' => $id - 9600, 'name' => $name, 'sku' => $sku, 'color' => 'Bej', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1];
$payload = static function (string $pricing, array $lines, ?array $financial = null, ?string $forOrder = null) use ($orderId, $suffix, &$tick, $iso, $activatedAt): array {
    $orderId = $forOrder ?? $orderId;
    $tick++;
    $p = T::sourceOrder($orderId, "rsv-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', $lines);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $financial ?? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Interventie', 'street' => 'Str. Exemplu 3', 'city' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => RSV_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify('+60 seconds')), 'evidence' => 'checkout'];
    return $p;
};
$priceA = 'sha256:' . hash('sha256', "rsv-price-a-{$orderId}");
$priceB = 'sha256:' . hash('sha256', "rsv-price-b-{$orderId}");
$pf = static fn (string $number): array => ['document' => ['series' => 'TPF', 'number' => $number, 'at' => time() - 600, 'by' => '00000000-0000-4000-8000-0000000000a1'], 'op' => 'none', 'opKey' => null, 'mail' => 'sent', 'mailCount' => 1,
    'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => [], 'reminders' => []];
$ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')])), 'the order arrives');
$th->orders[$orderId] = ['id' => $orderId, 'number' => $orderId, 'eligible' => true, 'phone' => RSV_PHONE, 'pricing' => $priceA,
    'finance' => ['cod' => true, 'total' => '421.02', 'collected' => '0.00', 'pricing' => $priceA], 'invoice' => ['cod' => true, 'cleared' => false, 'pf' => $pf('0027')]];
$check($service->distribute()['assigned'] >= 1, 'the order goes to its operator');
$call = static fn (array $w, string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => 'rsv-' . bin2hex(random_bytes(10))], $w['cookie']);
$get = static fn (array $w, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $w['cookie']);
$version = static fn (): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$gid]);
$orderPath = '/confirmare/orders/' . rawurlencode($gid);
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');
$find = static fn (array $w, string $number): array => T::call($kernel, 'GET', '/management/interventions/resolver', null, ['origin' => T::ORIGIN], $w['cookie'], ['number' => $number]);
$press = static fn (array $w, string $globalId, array $body): array => $call($w, 'POST', '/management/interventions/resolver/orders/' . rawurlencode($globalId), $body);
$diagnose = static function (array $w, string $number) use ($find, $ok, $check): array {
    $body = $ok($find($w, $number), "diagnose {$number}");
    $check(count($body['orders']) === 1, "one order {$number} " . json_encode($body));
    return $body['orders'][0];
};
$fresh = static function () use ($pdo): void { $pdo->exec('DELETE FROM api_rate_limit_buckets'); };

// ---- 1. Who may use it --------------------------------------------------------------------------------------------------
$error($find($who['boss'], $orderId), 403, 'RESOLVER_DENIED', 'another Dashboard user never sees the resolver');
$error($find($who['rop'], $orderId), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff-only employee neither');
$error($press($who['boss'], $gid, ['action' => 'approve', 'fingerprint' => str_repeat('0', 64)]), 403, 'RESOLVER_DENIED', 'nor presses a step');
$flag = static fn (array $w): bool => (bool) ($ok($get($w, '/management/me'), 'me')['capabilities']['resolveOrders'] ?? false);
$check($flag($who['sinem']) && $flag($root) && !$flag($who['boss']), 'the Dashboard flag: the resolver role and root, nobody else');
$check((int) $one("SELECT COUNT(*) FROM permissions WHERE permission_key = 'orders.intervene' AND role_grantable = 1") === 1, 'migration 056: the permission exists');
$check((int) $one("SELECT COUNT(*) FROM employee_role_assignments a INNER JOIN roles r ON r.role_id = a.role_id INNER JOIN employees e ON e.employee_uuid = a.employee_uuid WHERE r.role_key = 'order-resolver' AND e.username LIKE ?", ["%.{$suffix}"]) === 1
    && (int) $one("SELECT COUNT(*) FROM employee_role_assignments a INNER JOIN roles r ON r.role_id = a.role_id WHERE r.role_key = 'order-resolver' AND a.employee_uuid = ?", [$uuids['sinem']]) === 1, 'the role is held only by the employee root gave it to');
$error(T::call($kernel, 'POST', '/management/interventions/resolver/orders/' . rawurlencode($gid), ['action' => 'approve', 'fingerprint' => str_repeat('0', 64)], ['origin' => T::ORIGIN, 'idempotency-key' => 'rsv-nocsrf-' . $suffix . '-0001'], $who['sinem']['cookie']), 403, 'CSRF_INVALID', 'a step needs the CSRF token');
$error($find($who['sinem'], 'x y'), 422, 'RESOLVER_INVALID', 'not an order number');
$check($ok($find($who['sinem'], '99999999'), 'unknown number')['orders'] === [], 'an unknown number: no order');

// ---- 2. Pending with its operator: the step is the approval ----------------------------------------------------------
$fresh();
$card = $diagnose($who['sinem'], $orderId);
$check($card['flow'] === 'confirmare' && $card['problem']['code'] === 'waiting_call' && $card['problem']['system'] === 'confirmare' && $card['problem']['owner'] === 'Test rop'
    && ($card['fix']['action'] ?? null) === 'approve' && $card['steps'][0] === ['key' => 'confirmation', 'state' => 'blocked'], 'waiting for the operator\'s call: approve ' . json_encode($card));
$error($press($who['sinem'], $gid, ['action' => 'approve', 'fingerprint' => str_repeat('a', 64)]), 409, 'RESOLVER_CHANGED', 'an old screen never acts');
$error($press($who['sinem'], $gid, ['action' => 'reopen', 'fingerprint' => $card['fingerprint']]), 409, 'RESOLVER_CHANGED', 'only the step offered');
$error($press($who['sinem'], $gid, ['action' => 'approve', 'fingerprint' => $card['fingerprint'], 'force' => true]), 400, 'INVALID_REQUEST', 'only the documented fields');
$fresh();
$done = $ok($press($who['sinem'], $gid, ['action' => 'approve', 'fingerprint' => $card['fingerprint']]), 'the resolver approves');
$check($one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$gid]) === 'approved' && $one('SELECT decided_by_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$gid]) === $rootUuid, 'approved, the record names root');

// ---- 3. Approved, proforma issued, nothing sent to finance: send it --------------------------------------------------
$card = $done['order'];
$check($card['problem']['code'] === 'finance_not_sent' && $card['problem']['system'] === 'finance' && ($card['fix']['action'] ?? null) === 'send-finance' && ($card['alternative']['action'] ?? null) === 'verify-advance'
    && $card['facts']['proforma']['number'] === '0027', 'approved, the advance never sent to finance: send it (or verify it) ' . json_encode($card));
$fresh();
$sent = $ok($press($who['sinem'], $gid, ['action' => 'send-finance', 'fingerprint' => $card['fingerprint']]), 'the resolver sends it to finance');
$task = $all('SELECT state, assigned_employee_uuid, requested_by_employee_uuid FROM finance_verification_requests WHERE global_order_id = ? AND open_order_uuid IS NOT NULL', [$gid])[0] ?? [];
$check(($task['state'] ?? null) === 'assigned' && $task['assigned_employee_uuid'] === $uuids['fin'] && $task['requested_by_employee_uuid'] === $rootUuid, 'the task lands in the accountant\'s inbox ' . json_encode($task));
$inbox = $ok($get($who['fin'], '/confirmare/finance/requests'), 'accountant inbox');
$check(in_array($orderId, array_column($inbox['items'], 'orderNumber'), true), 'the accountant sees it');

// ---- 4. Waiting at finance: the resolver verifies the advance found on the statement ---------------------------------
$card = $sent['order'];
$check($card['problem']['code'] === 'finance_waiting' && $card['problem']['owner'] === 'Test fin' && ($card['fix']['action'] ?? null) === 'verify-advance' && $card['fix']['needs'] === ['amount', 'reference', 'valueDate'], 'waiting at finance: verify ' . json_encode($card));
$amount = (string) $card['fix']['defaults']['amount'];
$fresh();
$error($press($who['sinem'], $gid, ['action' => 'verify-advance', 'fingerprint' => $card['fingerprint'], 'fields' => ['amount' => $amount, 'reference' => 'RSVBANK0001', 'valueDate' => gmdate('Y-m-d')]]), 422, 'RESOLVER_STATEMENT_REQUIRED', 'the statement tick is required');
$fresh();
$verified = $ok($press($who['sinem'], $gid, ['action' => 'verify-advance', 'fingerprint' => $card['fingerprint'], 'fields' => ['amount' => $amount, 'reference' => 'RSVBANK0001', 'valueDate' => gmdate('Y-m-d'), 'statementChecked' => true]]), 'the resolver verifies the advance');
$check($verified['outcome'] === 'verified', 'recorded and verified ' . json_encode($verified['outcome']));
$check($one("SELECT state FROM finance_verification_requests WHERE global_order_id = ? ORDER BY requested_at DESC LIMIT 1", [$gid]) === 'verified', 'the finance task is closed as verified');
$check($one("SELECT employee_uuid FROM order_finance_actions WHERE global_order_id = ? ORDER BY requested_at DESC LIMIT 1", [$gid]) === $rootUuid, 'the money actions name root');
$audit = $all("SELECT actor_employee_uuid, JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.fix')) fix, JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.outcome')) outcome FROM iam_audit_events WHERE action = 'intervention.resolve' AND target_id = ? ORDER BY created_at", [$gid]);
$check(array_column($audit, 'fix') === ['approve', 'send-finance', 'verify-advance', 'verify-advance'] && array_unique(array_column($audit, 'actor_employee_uuid')) === [$uuids['sinem']], 'the audit names the employee who pressed each step ' . json_encode($audit));
$check($audit[2]['outcome'] === 'refused' && $audit[3]['outcome'] === 'verified', 'the refusal is audited too');
$reasons = $all("SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.reason')) reason FROM iam_audit_events WHERE action = 'intervention.advance' AND target_id = ?", [$gid]);
$check(str_contains((string) ($reasons[0]['reason'] ?? ''), "sinem.{$suffix}"), 'the desk reason names the employee');

// ---- 5. Cleared: hand it to the shop employee -------------------------------------------------------------------------
$ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')], ['state' => 'cleared', 'basis' => 'advance_collected', 'pricingFingerprint' => $priceA])), 'the shop reports the advance collected');
$fresh();
$card = $diagnose($who['sinem'], $orderId);
$check($card['problem']['code'] === 'handoff_waiting' && ($card['fix']['action'] ?? null) === 'shop-assign' && $card['fix']['target'] === 'Test shopemp', 'cleared, not handed over: shop-assign ' . json_encode($card));
$fresh();
$handed = $ok($press($who['sinem'], $gid, ['action' => 'shop-assign', 'fingerprint' => $card['fingerprint']]), 'the resolver hands it over');
$check($handed['order']['problem']['code'] === 'document_waiting' && $handed['order']['fix'] === null && $handed['order']['problem']['owner'] === 'Test shopemp', 'now the shop employee prints the production document (nothing to press) ' . json_encode($handed['order']['problem']));

// ---- 6. Approved without a proforma: issue it -------------------------------------------------------------------------
$newOrder = static function (int $offset, bool $proforma) use ($orderId, $kernel, $payload, $priceA, $line, $th, $pf, $service, $ok): array {
    $id = (string) ((int) $orderId + $offset);
    $ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')], null, $id)), "order {$id}");
    $th->orders[$id] = ['id' => $id, 'number' => $id, 'eligible' => true, 'phone' => RSV_PHONE, 'pricing' => $priceA,
        'finance' => ['cod' => true, 'total' => '421.02', 'collected' => '0.00', 'pricing' => $priceA], 'invoice' => ['cod' => true, 'cleared' => false] + ($proforma ? ['pf' => $pf('00' . $offset . '1')] : [])];
    $service->distribute();
    return [$id, 'trendhome:' . $id];
};
[$id2, $gid2] = $newOrder(2, false);
$fresh();
$card = $diagnose($root, $id2);
$fresh();
$card = $ok($press($root, $gid2, ['action' => 'approve', 'fingerprint' => $card['fingerprint']]), 'root approves the second order')['order'];
$check($card['problem']['code'] === 'proforma_missing' && ($card['fix']['action'] ?? null) === 'proforma', 'approved without a proforma: issue it ' . json_encode($card));
$fresh();
$card = $ok($press($root, $gid2, ['action' => 'proforma', 'fingerprint' => $card['fingerprint']]), 'root issues the proforma')['order'];
$check($card['problem']['code'] === 'finance_not_sent' && $card['facts']['proforma'] !== null, 'the proforma exists; next: finance ' . json_encode($card['problem']));

// ---- 7. A clarification: answer it ------------------------------------------------------------------------------------
$fresh();
$card = $ok($press($who['sinem'], $gid2, ['action' => 'send-finance', 'fingerprint' => $card['fingerprint']]), 'sent')['order'];
$rid2 = (string) $one('SELECT request_uuid FROM finance_verification_requests WHERE global_order_id = ? AND open_order_uuid IS NOT NULL', [$gid2]);
$fresh();
$taskView = $ok($get($who['fin'], '/confirmare/finance/requests/' . $rid2), 'accountant opens');
$ok($call($who['fin'], 'POST', '/confirmare/finance/requests/' . $rid2 . '/decide', ['expectedVersion' => $taskView['request']['version'], 'outcome' => 'needs_clarification', 'reasonCode' => 'amount_unclear']), 'amount unclear');
$fresh();
$card = $diagnose($who['sinem'], $id2);
$check($card['problem']['code'] === 'finance_clarification' && ($card['fix']['action'] ?? null) === 'resubmit' && ($card['alternative']['action'] ?? null) === 'verify-advance', 'a clarification: resubmit ' . json_encode($card));
$fresh();
$card = $ok($press($who['sinem'], $gid2, ['action' => 'resubmit', 'fingerprint' => $card['fingerprint']]), 'resubmitted')['order'];
$check($card['problem']['code'] === 'finance_waiting' && $one('SELECT state FROM finance_verification_requests WHERE request_uuid = ?', [$rid2]) === 'assigned', 'back at finance');

// ---- 8. The shop cancels the order: close the task ---------------------------------------------------------------------
$cancelled = $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')], null, $id2);
$cancelled['order']['status'] = ['code' => 'cancelled', 'label' => 'Cancelled'];
$ok(T::ingest($kernel, 'trendhome', $cancelled), 'the shop cancels the second order');
$check($one('SELECT source_commerce_status_code FROM operational_orders WHERE global_order_id = ?', [$gid2]) === 'cancelled', 'cancelled in Arasya');
$fresh();
$card = $diagnose($who['sinem'], $id2);
$check($card['problem']['code'] === 'task_on_closed_order' && ($card['fix']['action'] ?? null) === 'close-task', 'a cancelled order with an open task: close it ' . json_encode($card['problem']));
$fresh();
$card = $ok($press($who['sinem'], $gid2, ['action' => 'close-task', 'fingerprint' => $card['fingerprint']]), 'closed')['order'];
$check($one('SELECT state FROM finance_verification_requests WHERE request_uuid = ?', [$rid2]) === 'cancelled' && $card['problem'] === null, 'the task is withdrawn; nothing left ' . json_encode($card['problem']));

// ---- 9. The stuck list, and no customer data in the audit -------------------------------------------------------------
$list = $ok(T::call($kernel, 'GET', '/management/interventions/resolver', null, ['origin' => T::ORIGIN], $who['sinem']['cookie']), 'the stuck list');
$check(isset($list['items']) && is_array($list['items']), 'the resolver lists the blocked orders');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action LIKE 'intervention.%' AND metadata_json LIKE '%" . RSV_PHONE . "%'") === 0, 'no customer data in the audit');
$ok($asRoot('GET', '/management/interventions'), 'the root desk still answers');

fwrite(STDOUT, "PASS MySQL order resolver integration: {$checks} checks (root and the resolver role only, Dashboard flag, one number one diagnosis, approve, send to finance, verify the advance from the statement, hand over to the shop employee, issue the missing proforma, answer a clarification, close the task of a cancelled order, a stale screen refused, audit names who pressed while records name root).\n");
