<?php

declare(strict_types=1);

// The Operations half of the ING advance reconciliation (API 2.58.0, Phase 0, issue #114) on a disposable database,
// through the HTTP kernel, with an in-process TEST Trend Home shop (protocol v1, TEST amounts only). A real Confirmare
// order whose advance request an accountant sent back as "amount unclear" (an open, assigned human finance task, like
// Cristina Nita's), a clean Confirmare order, a legacy order, a cancelled order and an order with a finance action.
// Covers: the guard refuses every order with ANY finance task or finance action, outside Confirmare, unavailable or
// unknown, and always says auto apply is unavailable; it writes nothing (no INSERT, UPDATE or DELETE on its connection);
// the snapshot digest of the human finance workflow (requests with state, version, assignee, checker, outcome; events;
// actions; scopes) is identical before and after many guard runs; the assigned task keeps its state, version, assignee
// and visibility for its accountant.

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
    fwrite(STDOUT, "SKIP MySQL ING settlement guard integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-ing-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const ING_KEY = 'trendhome-access-key-test-0123456789abcdef-ing';
const ING_PHONE = '0711555444';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . ING_KEY, 'finance' => 'record', 'invoice' => 'enforce', 'verification' => 'single'],
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

$th = new SourceAccessFakeShop('trendhome', 'th-test', ING_KEY);
$th->safetyRules = true;
$th->financeVerification = 'single';
$container = new Container($config, $pdo, $th);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "ing.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-ing-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Ing guard root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Ing guard root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['origin' => T::ORIGIN, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'ing-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'ing guard test passphrase 2026';
$uuids = [];
foreach (['rop' => ['staff'], 'fin' => ['staff'], 'boss' => ['dashboard', 'staff'], 'shopemp' => ['staff']] as $name => $apps) {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-ing');
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
    fwrite(STDOUT, "SKIP MySQL ING settlement guard integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
(new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find('trendhome'), $activatedAt, 'Test Owner', 'ing guard test', 'ing-test', true);
T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
(new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find('trendhome'), 'Test Owner', 'ing guard test', 'ing-test', true);
$orderId = (string) T::freeOrderBase($pdo, 660000, 669999, 10);
$gid = 'trendhome:' . $orderId;
$tick = 0;
$line = static fn (int $id, string $name, string $sku): array => ['id' => $id, 'line' => $id - 9600, 'name' => $name, 'sku' => $sku, 'color' => 'Bej', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1];
$payload = static function (string $pricing, array $lines, ?array $financial = null, ?string $forOrder = null) use ($orderId, $suffix, &$tick, $iso, $activatedAt): array {
    $orderId = $forOrder ?? $orderId;
    $tick++;
    $p = T::sourceOrder($orderId, "ing-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', $lines);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $financial ?? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client ING', 'street' => 'Str. Exemplu 3', 'city' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => ING_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify('+60 seconds')), 'evidence' => 'checkout'];
    return $p;
};
$priceA = 'sha256:' . hash('sha256', "ing-price-a-{$orderId}");
$priceB = 'sha256:' . hash('sha256', "ing-price-b-{$orderId}");
$pf = static fn (string $number): array => ['document' => ['series' => 'TPF', 'number' => $number, 'at' => time() - 600, 'by' => '00000000-0000-4000-8000-0000000000a1'], 'op' => 'none', 'opKey' => null, 'mail' => 'sent', 'mailCount' => 1,
    'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => [], 'reminders' => []];
$ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')])), 'the order arrives');
$th->orders[$orderId] = ['id' => $orderId, 'number' => $orderId, 'eligible' => true, 'phone' => ING_PHONE, 'pricing' => $priceA,
    'finance' => ['cod' => true, 'total' => '421.02', 'collected' => '0.00', 'pricing' => $priceA], 'invoice' => ['cod' => true, 'cleared' => false, 'pf' => $pf('0027')]];
$check($service->distribute()['assigned'] >= 1, 'the order goes to its operator');
$call = static fn (array $w, string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => 'ing-' . bin2hex(random_bytes(10))], $w['cookie']);
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

// ---- The other TEST orders ----------------------------------------------------------------------------------------------
$guard = new \Arasya\Operations\Confirmare\IngSettlementGuard($pdo);
$gids = ['task' => $gid];
foreach (['clean' => 1, 'legacy' => 2, 'cancelled' => 3, 'action' => 4] as $name => $offset) {
    $other = (string) ((int) $orderId + $offset);
    $ok(T::ingest($kernel, 'trendhome', $payload($priceA, [$line(9601, 'Draperie Bella', 'bella-4516')], null, $other)), "the {$name} order arrives");
    $gids[$name] = 'trendhome:' . $other;
}
$check($one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$gids['clean']]) === 'confirmare', 'the clean order is a Confirmare order');
// TEST fixture states of the disposable database (a legacy order, a cancelled order, a finance action recorded).
$pdo->prepare("UPDATE operational_orders SET workflow_cohort = 'legacy', workflow_cohort_reason = 'before_activation' WHERE global_order_id = ?")->execute([$gids['legacy']]);
$pdo->prepare("UPDATE operational_orders SET operational_status = 'unavailable' WHERE global_order_id = ?")->execute([$gids['cancelled']]);
$pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, purpose, idempotency_key, request_hmac, revision_before, state, requested_at)
    SELECT ?, order_uuid, global_order_id, source_key, ?, 'submit_transfer', 'advance', ?, ?, ?, 'unknown', UTC_TIMESTAMP(6) FROM operational_orders WHERE global_order_id = ?")
    ->execute([\Arasya\Operations\Support\Uuid::v4(), $uuids['fin'], 'ing-action-' . $suffix, random_bytes(32), 'sha256:' . str_repeat('0', 64), $gids['action']]);

// ---- 1. The frozen human task and the snapshot ---------------------------------------------------------------------------
$taskBefore = $all('SELECT * FROM finance_verification_requests ORDER BY request_uuid');
$eventsBefore = $all('SELECT * FROM finance_verification_request_events ORDER BY event_uuid');
$actionsBefore = $all('SELECT * FROM order_finance_actions ORDER BY action_uuid');
$before = $guard->snapshot();
$check($before['requests'] === ['advance/needs_clarification' => 1] && $before['assignees'] === [substr($uuids['fin'], 0, 8) => 1] && $before['events'] >= 3 && $before['actions'] !== [],
    'the snapshot counts the assigned task by purpose/state and masked assignee ' . json_encode($before));
$check(str_starts_with($before['digest'], 'sha256:') && !str_contains(json_encode($before), $uuids['fin']), 'the snapshot shows a digest and masked ids only');

// ---- 2. Verdicts ---------------------------------------------------------------------------------------------------------
$status = static function () use ($pdo): array {
    $rows = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert', 'Com_update', 'Com_delete', 'Com_replace', 'Com_insert_select', 'Com_update_multi', 'Com_delete_multi')")->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
};
$writesBefore = $status();
$v = [];
for ($i = 0; $i < 5; $i++) {
    $result = $guard->verdicts([...array_values($gids), 'trendhome:999999999', 'not an id', 'trendyol:123']);
    $guard->snapshot();
}
$writesAfter = $status();
foreach ($result['verdicts'] as $verdict) $v[$verdict['globalOrderId']] = $verdict;
$check($writesAfter === $writesBefore, 'the guard and the snapshot wrote nothing (no INSERT, UPDATE, DELETE on their connection) ' . json_encode([$writesBefore, $writesAfter]));
$check($result['autoApplyAvailable'] === false && array_filter($result['verdicts'], static fn (array $x): bool => !in_array('auto_apply_unavailable', $x['refusals'], true)) === [], 'auto apply is unavailable for every order');
$check($v[$gids['task']]['eligibleForShadow'] === false && in_array('finance_task_exists', $v[$gids['task']]['refusals'], true), 'the order with the open assigned task (amount unclear) is refused: finance_task_exists');
$check($v[$gids['clean']]['eligibleForShadow'] === true && $v[$gids['clean']]['refusals'] === ['auto_apply_unavailable'], 'the clean Confirmare order passes the Operations predicates (and still cannot be applied)');
$check($v[$gids['legacy']]['refusals'][0] === 'outside_confirmare', 'a legacy order is refused: outside_confirmare');
$check(in_array('order_unavailable', $v[$gids['cancelled']]['refusals'], true), 'a cancelled order is refused: order_unavailable');
$check(in_array('finance_action_exists', $v[$gids['action']]['refusals'], true), 'an order with a finance action (even unknown) is refused: finance_action_exists');
$check($v['trendhome:999999999']['refusals'][0] === 'order_unknown' && !isset($v['not an id']) && $v['trendyol:123']['eligibleForShadow'] === false, 'unknown orders and invalid ids are refused or ignored');

// A task in every other state of the human workflow also refuses (fixture rows copied from the real task, TEST database).
foreach (['assigned', 'checking', 'exception', 'resolved', 'cancelled'] as $state) {
    $closed = in_array($state, ['resolved', 'cancelled'], true);
    $pdo->prepare('UPDATE finance_verification_requests SET state = ?, open_order_uuid = ' . ($closed ? 'NULL' : 'order_uuid') . ' WHERE request_uuid = ?')->execute([$state, $rid]);
    $refusals = $guard->verdicts([$gid])['verdicts'][0]['refusals'];
    $check(in_array('finance_task_exists', $refusals, true), "a task in state {$state} also refuses (open or closed)");
}
$restore = $taskBefore[0];
$pdo->prepare('UPDATE finance_verification_requests SET state = ?, open_order_uuid = ?, updated_at = ? WHERE request_uuid = ?')->execute([$restore['state'], $restore['open_order_uuid'], $restore['updated_at'], $rid]);

// ---- 3. Nothing of the human workflow changed ------------------------------------------------------------------------------
$after = $guard->snapshot();
$check($after === $before, 'the snapshot (digest and counts) is identical after the guard runs');
$check($all('SELECT * FROM finance_verification_requests ORDER BY request_uuid') == $taskBefore && $all('SELECT * FROM finance_verification_request_events ORDER BY event_uuid') == $eventsBefore && $all('SELECT * FROM order_finance_actions ORDER BY action_uuid') == $actionsBefore,
    'every finance task, task event and finance action is row-equivalent (state, version, assignee, checker, updated_at)');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$still = $ok($get($who['fin'], '/confirmare/finance/requests/' . $rid), 'the accountant still opens the task');
$check($still['request']['state'] === 'needs_clarification' && $still['request']['version'] === (int) $taskBefore[0]['version'], 'the task keeps its state and version for its accountant ' . json_encode([$still['request']['state'], $still['request']['version']]));

fwrite(STDOUT, "PASS MySQL ING settlement guard integration: {$checks} checks (open assigned task frozen and refused, every task state refuses, finance action refuses, legacy/cancelled/unknown refused, clean order passes Operations predicates but auto apply unavailable, no writes, snapshot and rows identical, accountant's task unchanged).\n");
