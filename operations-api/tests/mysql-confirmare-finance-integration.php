<?php

declare(strict_types=1);

// Confirmare finance (migration 039, API 2.41.0, Phase 5) on a disposable database, through the HTTP kernel, with an
// in-process TEST shop that speaks protocol v1 and keeps the money record like YD SOFT 2.15.0 (TEST amounts only).
// Covers: root-only finance scopes separate from Confirmare (root and supervisors hold none implicitly), who reads the
// summary (finance, assignee, supervisor; never another store, a scope-less identity or a legacy order), who acts (record
// vs approve; never an operator or a supervisor), four eyes, the summary revision, idempotency, duplicate references,
// lost answers and 5xx reconciled with the shop's stored answer, a request the shop never received, one action per order,
// production closed while an action is unsettled, refunds only after the final manufacturing stage, the order event's
// Phase 5 attentions, the YD SOFT 2.16 refusals finance_open and order_cancelled, the finance queue, and the audit (codes and hashes only; no amount, reference or phone anywhere).

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Confirmare\ConfirmareFinance;
use Arasya\Operations\Confirmare\ConfirmationClearance;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmationGate;
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
    fwrite(STDOUT, "SKIP MySQL Confirmare finance integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
// Every server log line of this run goes to one file, scanned at the end for amounts, references and phone numbers.
$logFile = tempnam(sys_get_temp_dir(), 'arasya-finance-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const FIN_ORIGIN = 'http://127.0.0.1:4174';
const FIN_KEY = 'trendhome-access-key-test-0123456789abcdef-fin';
const FIN_PHONE = '0711444555';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$refused = static function (array $r, string $reason, string $label) use ($check): array {
    $check($r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'CONFIRMARE_FINANCE_REFUSED' && ($r['body']['error']['details']['reason'] ?? '') === $reason, "{$label}: expected refusal {$reason}, got {$r['status']} " . json_encode($r['body']));
    return $r['body']['error']['details'] ?? [];
};

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on'];
$base = T::config($dbName, [T::ORIGIN, FIN_ORIGIN], ['trendhome' => $full + ['paymentGate' => 'off'], 'outletperdele' => $full + ['paymentGate' => 'off']]);
// Trend Home: finance `record`. Outlet Perdele: source access read, finance off.
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . FIN_KEY, 'finance' => 'record'],
    'outletperdele' => ['mode' => 'read', 'url' => 'https://outletperdele.example', 'key' => 'op-test:' . FIN_KEY . '-outlet', 'finance' => ''],
]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
foreach (['finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision', 'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$shop = new SourceAccessFakeShop('trendhome', 'th-test', FIN_KEY);
$container = new Container($config, $pdo, $shop);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "fin.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-fin-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Finance root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Finance root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'finance test passphrase 2026';
$uuids = [];
foreach (['fop', 'fopo', 'fsup', 'fview', 'frec', 'fapp', 'fapp2', 'fout', 'fnone'] as $name) {
    // Generic test identities: access only from the scopes granted below, never from a name.
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-fin');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
}
foreach (['fop' => ['operate' => ['trendhome'], 'supervise' => []], 'fopo' => ['operate' => ['outletperdele'], 'supervise' => []], 'fsup' => ['operate' => [], 'supervise' => ['trendhome']]] as $name => $grant) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/confirmare-scopes", $grant), "confirmare {$name}");
}

// ---- 1. Finance scopes: root-only, separate from Confirmare, nothing implicit ---------------------------------------
$grants = ['fview' => ['view' => ['trendhome'], 'record' => [], 'approve' => []], 'frec' => ['view' => [], 'record' => ['trendhome'], 'approve' => []],
    'fapp' => ['view' => [], 'record' => [], 'approve' => ['trendhome']], 'fapp2' => ['view' => [], 'record' => ['trendhome'], 'approve' => ['trendhome']], 'fout' => ['view' => [], 'record' => ['outletperdele'], 'approve' => ['outletperdele']]];
foreach ($grants as $name => $grant) {
    $employeeView = $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/finance-scopes", $grant), "finance {$name}");
    // API 2.50.0: `verify` is listed too (an older Dashboard sends three lists; the stored verify rows stay).
    $check($employeeView['financeScopes'] === $grant + ['verify' => []], "{$name}: the stored finance scopes");
}
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.finance_scopes_changed' AND target_id IN (?, ?, ?, ?, ?)", [$uuids['fview'], $uuids['frec'], $uuids['fapp'], $uuids['fapp2'], $uuids['fout']]) === 5, 'every finance scope change is in the IAM audit');
$error($asRoot('PUT', "/management/employees/{$uuids['fnone']}/finance-scopes", ['view' => ['b2b'], 'record' => [], 'approve' => []]), 400, 'UNKNOWN_SOURCE', 'finance scopes exist for the shops only (never B2B or Trendyol)');
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity LIMIT 1');
$error($asRoot('PUT', "/management/employees/{$rootUuid}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []]), 403, 'ROOT_PROTECTED', 'root never holds a finance scope');
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$op = $login('fop');
$sup = $login('fsup');
$view = $login('fview');
$rec = $login('frec');
$app = $login('fapp');
$app2 = $login('fapp2');
$out = $login('fout');
$none = $login('fnone');
$me = static fn (array $who): array => T::call($kernel, 'GET', '/employees/me', null, [], $who['cookie'])['body'] ?? [];
$check(($me($op)['financeScopes'] ?? null) === ['view' => [], 'record' => [], 'approve' => [], 'verify' => []] && ($me($rec)['financeScopes']['record'] ?? null) === ['trendhome'], 'the session shows the finance scopes (operators have none)');
$error(T::call($kernel, 'PUT', "/management/employees/{$uuids['fnone']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []], ['x-csrf-token' => $app['csrf'], 'idempotency-key' => 'fin-self-grant-' . $suffix], $app['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'a finance approver cannot grant finance scopes');

// ---- Fixtures: Confirmare orders of both stores, a legacy order -------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare finance integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'API 2.45.0: activation needs the source readiness (Phase 5)');
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'finance test', 'fin-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'finance test', 'fin-test', true);
}
$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat');
$check(in_array('financeTasks', $heartbeat['body']['orderFacts'] ?? [], true), 'the heartbeat announces the financeTasks fact');
$numberBase = T::freeOrderBase($pdo, 731000, 759999, 100); // ids base+1..base+60; a block no earlier run used
$id = static fn (int $n): string => (string) ($numberBase + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$tick = 0;
$payload = static function (string $orderId, ?array $submission, array $financial, string $method = 'online') use ($suffix, &$tick): array {
    $tick++;
    $p = T::sourceOrder($orderId, "fin-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . 'Z', null, 'processing', 'active', [[
        'id' => 9201, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $financial['pricingFingerprint']];
    $p['order']['payment'] = $method === 'online' ? ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0] : ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $financial;
    $p['order']['delivery'] = ['name' => 'TEST Client Finante', 'street' => 'Str. Exemplu 13', 'city' => 'Sibiu', 'postalCode' => '550000', 'country' => 'RO', 'phone' => FIN_PHONE];
    if ($submission !== null) $p['order']['submission'] = $submission;
    return $p;
};
$pricing = static fn (int $n, string $v = 'a'): string => 'sha256:' . hash('sha256', "fin-price-{$n}-{$v}");
$submitted = static fn (int $seconds): array => ['submittedAt' => $iso($activatedAt->modify("+{$seconds} seconds")), 'evidence' => 'checkout'];
$waiting = static fn (int $n, ?string $attention, string $v = 'a', string $state = 'waiting', ?string $basis = null): array => ['state' => $state, 'basis' => $basis, 'pricingFingerprint' => $pricing($n, $v)] + ($attention === null ? [] : ['attention' => $attention]);
foreach ([1, 2, 3, 5] as $n) $ok(T::ingest($kernel, 'trendhome', $payload($id($n), $submitted($n), $waiting($n, $n === 3 ? 'refund_due' : 'additional_payment_due'))), "trendhome order {$n}");
$ok(T::ingest($kernel, 'trendhome', $payload($id(4), $submitted(4), $waiting(4, null), 'cod')), 'trendhome COD order');
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), $submitted(50), $waiting(50, 'additional_payment_due'))), 'outlet order');
$ok(T::ingest($kernel, 'trendhome', $payload($id(60), ['submittedAt' => $iso($activatedAt->modify('-1 hour')), 'evidence' => 'checkout'], $waiting(60, null, 'a', 'cleared', 'online_payment'))), 'legacy order');
$check($service->distribute()['assigned'] >= 5, 'the server distributes the Confirmare orders (to the only Trend Home operator)');
$finance = static fn (array $f): array => $f;
$shop->orders[$id(1)] = ['id' => $id(1), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => false, 'total' => '650.00', 'paid' => '500.00', 'original' => '500.00', 'pricing' => $pricing(1)])];
$shop->orders[$id(2)] = ['id' => $id(2), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => false, 'total' => '420.00', 'paid' => '300.00', 'pricing' => $pricing(2)])];
$shop->orders[$id(3)] = ['id' => $id(3), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => false, 'total' => '700.00', 'paid' => '800.00', 'original' => '800.00', 'pricing' => $pricing(3)])];
$shop->orders[$id(4)] = ['id' => $id(4), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => true, 'total' => '1000.00', 'collected' => '100.00', 'pricing' => $pricing(4)])];
$shop->orders[$id(5)] = ['id' => $id(5), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => false, 'total' => '510.00', 'paid' => '450.00', 'pricing' => $pricing(5)])];
$shop->orders[$id(60)] = ['id' => $id(60), 'eligible' => false];
$outletOrder = 'outletperdele:' . $id(50);
// API 2.50.0: a finance scope reaches an order only through a finance task assigned to the employee by name
// (FinanceTaskAccess); record or approve alone reaches nothing. Orders 1, 2, 3 and 5 carry an exceptional money state
// (additional payment, refund): TEST fixture rows give each the exception task the inventory creates (assigned to the
// recorder, the approver named as its checker). The COD order 4 waits for its advance only: TEST fixture rows give it
// finance tasks (an open request assigned to `frec`, an earlier one decided by `fapp`), so the shop's money rules of a
// COD order stay covered here; the task workflow itself is covered by mysql-finance-requests-integration.php.
$fixtureRequest = static function (string $state, ?string $assignee, ?string $decider) use ($pdo, $th, $suffix, $uuids): void {
    $orderUuid = (string) $pdo->query('SELECT order_uuid FROM operational_orders WHERE global_order_id = ' . $pdo->quote($th(4)))->fetchColumn();
    $open = in_array($state, ['verified', 'not_received', 'cancelled'], true) ? null : $orderUuid;
    $pdo->prepare("INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at, requested_by_employee_uuid, requested_at,
            request_idempotency_key, ledger, proforma_number, outcome, decided_by_employee_uuid, decided_at, updated_at)
        VALUES (?, ?, ?, 'trendhome', 'advance', ?, 1, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), ?, 'shop', 'TEST1', ?, ?, ?, UTC_TIMESTAMP(6))")->execute([
        \Arasya\Operations\Support\Uuid::v4(), $orderUuid, $th(4), $state, $open, $assignee, $uuids['fop'], 'fixture-' . $state . '-' . $suffix, $decider === null ? null : 'not_received', $decider, $decider === null ? null : gmdate('Y-m-d H:i:s'),
    ]);
};
$fixtureRequest('not_received', $uuids['fapp'], $uuids['fapp']);
$fixtureRequest('assigned', $uuids['frec'], null);
$exceptionTask = static function (int|string $n, string $code, string $assignee, ?string $checker) use ($pdo, $th): void {
    $globalId = is_int($n) ? $th($n) : $n;
    $orderUuid = (string) $pdo->query('SELECT order_uuid FROM operational_orders WHERE global_order_id = ' . $pdo->quote($globalId))->fetchColumn();
    $pdo->prepare("INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at, requested_at,
            inventory_key, task_code, ledger, policy, checker_employee_uuid, updated_at)
        VALUES (?, ?, ?, ?, 'exception', 'assigned', 1, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, ?, 'shop', 'four_eyes', ?, UTC_TIMESTAMP(6))")->execute([
        \Arasya\Operations\Support\Uuid::v4(), $orderUuid, $globalId, explode(':', $globalId)[0], $orderUuid, $assignee, 'exception:' . $orderUuid . ':' . $code . ':fixture', $code, $checker,
    ]);
};
$exceptionTask(1, 'additional_payment_due', $uuids['frec'], $uuids['fapp']);
$exceptionTask(2, 'additional_payment_due', $uuids['fapp2'], $uuids['fapp']);
$exceptionTask(3, 'refund_due', $uuids['frec'], $uuids['fapp']);
$exceptionTask(5, 'additional_payment_due', $uuids['frec'], $uuids['fapp']);
$exceptionTask($outletOrder, 'reconciliation_required', $uuids['fout'], null);
$get = static function (array $who, string $path) use ($kernel): array {
    [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
    parse_str($query, $parameters);
    return T::call($kernel, 'GET', $route, null, ['origin' => FIN_ORIGIN], $who['cookie'], $parameters);
};
$read = static fn (array $who, string $globalId): array => $get($who, '/confirmare/orders/' . rawurlencode($globalId) . '/finance');
$act = static fn (array $who, string $globalId, string $action, array $body, ?string $key = null): array => T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($globalId) . '/finance/' . $action, $body,
    ['origin' => FIN_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'fin-act-' . bin2hex(random_bytes(10))], $who['cookie']);
$revision = static fn (string $orderId): string => 'sha256:' . hash('sha256', (string) json_encode($shop->orders[$orderId]['finance'] ?? []));
$actions = static fn (string $globalId): array => $all('SELECT * FROM order_finance_actions WHERE global_order_id = ? ORDER BY requested_at, action_uuid', [$globalId]);
$orderUuid = static fn (string $globalId): string => (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$globalId]);
$today = gmdate('Y-m-d');

// ---- 2. Who reads the summary ---------------------------------------------------------------------------------------
// API 2.50.0: a finance scope never opens the Confirmare order (lines, measurements, calls, history), even with a task.
$error($get($view, '/confirmare/orders/' . rawurlencode($th(1))), 404, 'CONFIRMARE_NOT_FOUND', 'finance view no longer sees the Confirmare detail');
$error($get($app, '/confirmare/orders/' . rawurlencode($th(1))), 404, 'CONFIRMARE_NOT_FOUND', 'a finance approver with a task of the order does not see the Confirmare detail either');
$check($ok($get($op, '/confirmare/orders/' . rawurlencode($th(1))), 'operator detail')['finance'] === ['configured' => true, 'read' => true, 'record' => false, 'approve' => false], 'the assignee may read the money, never act');
$requests = count($shop->requests);
$error($read($none, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'without any scope nothing is visible');
$error($read($out, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'another store\'s finance employee does not even see it (wrong store)');
$error($read($app, $th(60)), 404, 'CONFIRMARE_NOT_FOUND', 'a legacy order keeps its workflow: it is no Confirmare order, no finance read');
$error($read($view, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'API 2.50.0: view alone is no finance task: the order is not found');
$check(count($shop->requests) === $requests, 'refused reads never reach the shop');
$s1 = $ok($read($app, $th(1)), 'finance read');
$check($s1['available'] === true && $s1['role'] === 'finance' && $s1['summary']['totals']['current'] === '650.00' && $s1['summary']['totals']['original'] === '500.00' && $s1['summary']['received']['online'] === '500.00'
    && $s1['summary']['balance']['outstanding'] === '150.00' && $s1['summary']['financial']['attention'] === 'additional_payment_due', 'the summary: new total, original total, payment kept, outstanding 150 (read from the shop now)');
$check($s1['allowedActions'] === [] && $s1['nextAction'] === 'collect_additional_payment' && $s1['production']['refundEligible'] === false, 'a viewer may do nothing; the next step is named');
$raw = T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($th(1)) . '/finance', null, ['origin' => FIN_ORIGIN], $app['cookie']);
$check(str_contains((string) json_encode($raw['body']), '"employees":{}'), 'the employee names are a JSON object even when empty (Staff maps them strictly)');
$check(($shop->requests[count($shop->requests) - 1]['body']['actor'] ?? null) === ['employeeId' => $uuids['fapp'], 'role' => 'finance'], 'the shop is told who asks, as finance');
$sOp = $ok($read($op, $th(1)), 'assignee read');
$check($sOp['role'] === 'assignee' && $sOp['allowedActions'] === [], 'the operator reads (to tell the customer) with no action');
$check($ok($read($sup, $th(1)), 'supervisor read')['role'] === 'supervisor', 'a Confirmare supervisor reads as supervisor');
$o50 = $ok($read($out, $outletOrder), 'outlet read');
$check($o50 === ['available' => false, 'reason' => 'not_configured'], 'a store without finance answers not_configured');
$readsRow = $all('SELECT access_role, finance_revision FROM order_finance_reads WHERE global_order_id = ? AND outcome = ? ORDER BY read_at, read_uuid', [$th(1), 'ok']);
$check(array_column($readsRow, 'access_role') === ['finance', 'finance', 'assignee', 'supervisor'] && $readsRow[0]['finance_revision'] === $s1['summary']['revision'], 'every read is audited with its role and the summary revision (a hash): ' . json_encode($readsRow));

// ---- 3. Who acts --------------------------------------------------------------------------------------------------
$transfer = static fn (string $amount, string $reference, ?string $rev = null, string $purpose = 'additional_payment'): array => ['expectedRevision' => $rev ?? $revision($id(1)), 'purpose' => $purpose, 'amount' => $amount, 'reference' => $reference, 'valueDate' => $today];
$requests = count($shop->requests);
$error($act($view, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 404, 'CONFIRMARE_NOT_FOUND', 'a viewer cannot record money (it reaches no order)');
$error($act($op, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 403, 'CONFIRMARE_FINANCE_DENIED', 'the operator (customer communication) cannot record money');
$error($act($sup, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 403, 'CONFIRMARE_FINANCE_DENIED', 'a Confirmare supervisor cannot bypass finance');
$error($act($app, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 403, 'CONFIRMARE_FINANCE_DENIED', 'approve alone does not record');
$error($act($out, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 404, 'CONFIRMARE_NOT_FOUND', 'another store\'s finance employee cannot act');
$error($act($rec, $outletOrder, 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 404, 'CONFIRMARE_NOT_FOUND', 'a finance employee of Trend Home cannot act on Outlet Perdele');
$error($act($out, $outletOrder, 'submit-transfer', $transfer('150.00', 'OP FIN 0001')), 409, 'CONFIRMARE_FINANCE_DISABLED', 'a store without finance record refuses actions');
foreach ([
    'amount' => ['amount' => '1e3'], 'amount as a number' => ['amount' => 150], 'a negative amount' => ['amount' => '-5.00'], 'three decimals' => ['amount' => '1.005'],
    'reference' => ['reference' => 'x'], 'valueDate' => ['valueDate' => '2026-02-30'], 'purpose' => ['purpose' => 'gift'], 'expectedRevision' => ['expectedRevision' => 'v1'],
] as $label => $override) {
    $error($act($rec, $th(1), 'submit-transfer', $override + $transfer('150.00', 'OP FIN 0001')), 422, 'CONFIRMARE_FINANCE_INVALID', "invalid {$label} never reaches the shop");
}
$error($act($rec, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001') + ['verified' => true]), 400, 'INVALID_REQUEST', 'no field beyond the action\'s own (no "verified" flag exists)');
$error($act($rec, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001'), 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'no action without an Idempotency-Key');
$check(count($shop->requests) === $requests, 'refused actions never reach the shop');

// ---- 4. A bank transfer: recorded (not money), four eyes, verified ---------------------------------------------------
$stale = $refused($act($rec, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001', 'sha256:' . str_repeat('0', 64))), 'revision_changed', 'a decision based on an old summary');
$check($stale['revision'] === $revision($id(1)), 'the refusal names the shop\'s current revision');
$key1 = "fin-transfer-{$suffix}-000001";
$r1 = $ok($act($rec, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001'), $key1), 'submit transfer');
$entry1 = $r1['entryId'];
$check($r1['state'] === 'applied' && $r1['summary']['financial']['attention'] === 'transfer_pending' && $r1['summary']['received']['net'] === '500.00' && $r1['nextAction'] === 'verify_transfer', 'recorded: transfer_pending, nothing received yet');
$row = $actions($th(1))[1];
$check($row['state'] === 'applied' && $row['action'] === 'submit_transfer' && $row['purpose'] === 'additional_payment' && $row['source_entry_id'] === $entry1 && $row['revision_after'] === $r1['summary']['revision'] && strlen((string) $row['request_hmac']) === 32, 'the action is audited by codes, the shop entry id, revisions and an HMAC');
$replay = $ok($act($rec, $th(1), 'submit-transfer', $transfer('150.00', 'OP FIN 0001', $s1['summary']['revision']), $key1), 'replay');
$check($replay === ['state' => 'applied', 'idempotencyKey' => $key1, 'entryId' => $entry1] && count($shop->orders[$id(1)]['finance']['entries']) === 1, 'a retried request (same key, same body) returns the recorded outcome; one entry');
$error($act($rec, $th(1), 'submit-transfer', $transfer('15.00', 'OP FIN 0001', $s1['summary']['revision']), $key1), 409, 'IDEMPOTENCY_CONFLICT', 'the same key with another amount is refused');
$requests = count($shop->requests);
$decide = static fn (string $orderId, string $entry, string $decision = 'verify', ?string $reason = null): array => ['expectedRevision' => $revision($orderId), 'entryId' => $entry, 'decision' => $decision] + ($reason === null ? [] : ['reasonCode' => $reason]);
$error($act($rec, $th(1), 'decide-transfer', $decide($id(1), $entry1)), 403, 'CONFIRMARE_FINANCE_DENIED', 'record alone never verifies');
$error($act($app, $th(1), 'decide-transfer', $decide($id(1), $entry1, 'reject')), 422, 'CONFIRMARE_FINANCE_INVALID', 'a rejection needs its reason code');
$error($act($app, $th(1), 'decide-transfer', $decide($id(1), $entry1) + ['reasonCode' => 'other']), 422, 'CONFIRMARE_FINANCE_INVALID', 'a verification carries no reason code');
$check(count($shop->requests) === $requests, 'refused decisions never reach the shop');
$readApprover = $ok($read($app, $th(1)), 'approver read');
$check($readApprover['allowedActions'] === ['decide_transfer'] && ((array) $readApprover['employees'])[$uuids['frec']] === 'Test frec', 'the approver may verify the transfer another employee recorded (named in the timeline)');
$error($read($app2, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'API 2.50.0: another approver without the task does not even see it (the task names its checker)');
$v1 = $ok($act($app, $th(1), 'decide-transfer', $decide($id(1), $entry1)), 'verify transfer');
$check($v1['state'] === 'applied' && $v1['entryId'] === $entry1 && $v1['summary']['financial']['state'] === 'cleared' && $v1['summary']['received']['transfers'] === '150.00' && $v1['summary']['balance']['outstanding'] === '0.00', 'verified by another employee: received 650 of 650, cleared');
$error($act($app2, $th(1), 'decide-transfer', $decide($id(1), $entry1)), 404, 'CONFIRMARE_NOT_FOUND', 'a second approver cannot decide a task that is not theirs');
$refused($act($app, $th(1), 'decide-transfer', $decide($id(1), $entry1)), 'entry_not_pending', 'the same decision twice: the second is refused by the shop');

// The same employee may hold record and approve, but never verifies their own record.
$shop->orders[$id(2)]['finance']['total'] = '420.00';
$r2 = $ok($act($app2, $th(2), 'submit-transfer', ['expectedRevision' => $revision($id(2)), 'purpose' => 'additional_payment', 'amount' => '120.00', 'reference' => 'OP FIN 0002', 'valueDate' => $today]), 'record by record+approve');
$requests = count($shop->requests);
$error($act($app2, $th(2), 'decide-transfer', $decide($id(2), $r2['entryId'])), 403, 'CONFIRMARE_FINANCE_SAME_EMPLOYEE', 'four eyes: the recorder never verifies (refused before the shop)');
$check(count($shop->requests) === $requests, 'the four-eyes refusal never reaches the shop');
$check(!in_array('decide_transfer', $ok($read($app2, $th(2)), 'own read')['allowedActions'], true), 'the recorder is not offered the verification');
$ok($act($app, $th(2), 'decide-transfer', $decide($id(2), $r2['entryId'], 'reject', 'not_received')), 'reject: not on the statement');
$check($shop->financeNumbers($id(2))['attention'] === 'additional_payment_due' && $actions($th(2))[1]['decision'] === 'reject' && $actions($th(2))[1]['reason_code'] === 'not_received', 'a rejected transfer (false evidence) is no money; audited with its reason code');
$error($act($rec, $th(2), 'submit-transfer', ['expectedRevision' => $revision($id(2)), 'purpose' => 'additional_payment', 'amount' => '120.00', 'reference' => 'OP FIN 0009', 'valueDate' => $today]), 404, 'CONFIRMARE_NOT_FOUND', 'record alone reaches no order without a task');
$refused($act($app2, $th(2), 'submit-transfer', ['expectedRevision' => $revision($id(2)), 'purpose' => 'additional_payment', 'amount' => '120.00', 'reference' => 'op-fin-0001', 'valueDate' => $today]), 'duplicate_reference', 'the bank transfer of order 1 recorded again on order 2 (other spelling)');
$refused($act($app2, $th(2), 'submit-transfer', ['expectedRevision' => $revision($id(2)), 'purpose' => 'additional_payment', 'amount' => '500.00', 'reference' => 'OP FIN 0003', 'valueDate' => $today]), 'amount_exceeds_balance', 'never more than what is owed');

// ---- 5. COD: the advance on the current total --------------------------------------------------------------------
$c4 = $ok($read($rec, $th(4)), 'COD read');
$check($c4['summary']['advance']['required'] === '300.00' && $c4['summary']['advance']['outstanding'] === '200.00' && $c4['nextAction'] === 'collect_advance' && $c4['allowedActions'] === ['submit_transfer'], 'COD: 30 % of 1000, 100 collected, 200 outstanding');
$refused($act($rec, $th(4), 'submit-transfer', ['expectedRevision' => $revision($id(4)), 'purpose' => 'additional_payment', 'amount' => '150.00', 'reference' => 'OP FIN 0004', 'valueDate' => $today]), 'purpose_not_allowed', 'a COD order takes an advance, not an additional payment');
$r4 = $ok($act($rec, $th(4), 'submit-transfer', ['expectedRevision' => $revision($id(4)), 'purpose' => 'advance', 'amount' => '150.00', 'reference' => 'OP FIN 0004', 'valueDate' => $today]), 'advance transfer');
$v4 = $ok($act($app, $th(4), 'decide-transfer', $decide($id(4), $r4['entryId'])), 'verify advance');
$check($v4['summary']['financial']['state'] === 'partial' && $v4['summary']['advance']['outstanding'] === '50.00' && $v4['summary']['advance']['onDelivery'] === '750.00', 'a partial advance (250 of 300); the courier collects 750');

// ---- 6. Lost answers, 5xx, a request never received, one action at a time ----------------------------------------
$key5 = "fin-lost-{$suffix}-000001";
$shop->afterFinance = 'answer_lost';
$lost = $ok($act($rec, $th(5), 'submit-transfer', ['expectedRevision' => $revision($id(5)), 'purpose' => 'additional_payment', 'amount' => '60.00', 'reference' => 'OP FIN 0005', 'valueDate' => $today], $key5), 'lost answer');
$check($lost === ['state' => 'unknown', 'idempotencyKey' => $key5] && $actions($th(5))[0]['state'] === 'unknown', 'the answer was lost: unknown, never guessed');
$check(ConfirmareFinance::actionOpen($pdo, $orderUuid($th(5))) && in_array('payment', ConfirmationClearance::missing($pdo, $orderUuid($th(5))), true), 'an unsettled finance action keeps production closed');
$error($act($app, $th(5), 'decide-transfer', $decide($id(5), '00000000-0000-4000-9000-000000000999')), 409, 'CONFIRMARE_FINANCE_ACTION_PENDING', 'one finance action per order at a time');
$check($ok($read($app, $th(5)), 'read while unknown')['nextAction'] === 'reconcile_action', 'the summary says to reconcile first');
$settled = $ok(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(5)) . '/finance/reconcile', ['idempotencyKey' => $key5], ['origin' => FIN_ORIGIN, 'x-csrf-token' => $rec['csrf'], 'idempotency-key' => 'fin-rec-' . $suffix . '-01'], $rec['cookie']), 'reconcile');
$check($settled['state'] === 'applied' && $actions($th(5))[0]['state'] === 'applied' && count($shop->orders[$id(5)]['finance']['entries']) === 1, 'reconciled with the shop\'s stored answer: applied once, never replayed');
$entry5 = $settled['entryId'];
$shop->afterFinance = 'crash';
$crash = $ok($act($app, $th(5), 'decide-transfer', $decide($id(5), $entry5), "fin-crash-{$suffix}-000001"), '5xx');
$check($crash['state'] === 'unknown', 'a 5xx after the shop committed is unknown');
$error(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(5)) . '/finance/reconcile', ['idempotencyKey' => "fin-crash-{$suffix}-000001"], ['origin' => FIN_ORIGIN, 'x-csrf-token' => $app2['csrf'], 'idempotency-key' => 'fin-rec-' . $suffix . '-02x'], $app2['cookie']), 404, 'CONFIRMARE_NOT_FOUND', 'an approver without the task cannot settle it');
$byOther = $ok(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(5)) . '/finance/reconcile', ['idempotencyKey' => "fin-crash-{$suffix}-000001"], ['origin' => FIN_ORIGIN, 'x-csrf-token' => $app['csrf'], 'idempotency-key' => 'fin-rec-' . $suffix . '-02'], $app['cookie']), 'reconcile by the task\'s named approver');
$check($byOther['state'] === 'applied' && $shop->financeNumbers($id(5))['state'] === 'cleared', 'the task\'s named approver settles it with the shop\'s answer');
$error(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(5)) . '/finance/reconcile', ['idempotencyKey' => "fin-crash-{$suffix}-000001"], ['origin' => FIN_ORIGIN, 'x-csrf-token' => $view['csrf'], 'idempotency-key' => 'fin-rec-' . $suffix . '-03'], $view['cookie']), 404, 'CONFIRMARE_NOT_FOUND', 'a viewer cannot settle another employee\'s action (API 2.50.0: it does not even reach the order)');
$shop->orders[$id(5)]['finance']['total'] = '600.00';
$shop->fail = 'SOURCE_UNREACHABLE';
$keyNever = "fin-never-{$suffix}-000001";
$never = $ok($act($rec, $th(5), 'submit-transfer', ['expectedRevision' => $revision($id(5)), 'purpose' => 'additional_payment', 'amount' => '90.00', 'reference' => 'OP FIN 0006', 'valueDate' => $today], $keyNever), 'never received');
$shop->fail = null;
$check($never['state'] === 'unknown', 'a transport failure is unknown (the shop may or may not have run it)');
$early = $ok(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(5)) . '/finance/reconcile', ['idempotencyKey' => $keyNever], ['origin' => FIN_ORIGIN, 'x-csrf-token' => $rec['csrf'], 'idempotency-key' => 'fin-rec-' . $suffix . '-04'], $rec['cookie']), 'early reconcile');
$check($early === ['state' => 'unknown', 'idempotencyKey' => $keyNever, 'reachable' => true], 'absent at the shop but recent: still unknown (the shop may still be processing it)');
$pdo->prepare('UPDATE order_finance_actions SET requested_at = DATE_SUB(requested_at, INTERVAL 5 MINUTE) WHERE idempotency_key = ?')->execute([$keyNever]);
$refused(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(5)) . '/finance/reconcile', ['idempotencyKey' => $keyNever], ['origin' => FIN_ORIGIN, 'x-csrf-token' => $rec['csrf'], 'idempotency-key' => 'fin-rec-' . $suffix . '-05'], $rec['cookie']), 'not_received', 'absent after 120 s: the shop never received it');
$byKey = static fn (string $key): array => $all('SELECT * FROM order_finance_actions WHERE idempotency_key = ?', [$key])[0] ?? [];
$check(($byKey($keyNever)['state'] ?? null) === 'rejected' && ($byKey($keyNever)['outcome'] ?? null) === 'not_received' && !ConfirmareFinance::actionOpen($pdo, $orderUuid($th(5))), 'rejected as not received; the order is free again');
$shop->fail = 'SOURCE_ACCESS_DENIED';
$error($act($rec, $th(5), 'submit-transfer', ['expectedRevision' => $revision($id(5)), 'purpose' => 'additional_payment', 'amount' => '90.00', 'reference' => 'OP FIN 0007', 'valueDate' => $today]), 503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'the shop refused our key: nothing was sent');
$shop->fail = null;
$denied = $all("SELECT state, outcome FROM order_finance_actions WHERE global_order_id = ? AND outcome = 'source_access_denied'", [$th(5)]);
$check(count($denied) === 1 && $denied[0]['state'] === 'rejected', 'audited as rejected, not unknown');

// ---- 7. Price decrease: liability, acknowledgement, refund after the final manufacturing stage ---------------------
$l3 = $ok($read($app, $th(3)), 'liability read');
$check($l3['summary']['balance']['refundLiability'] === '100.00' && $l3['summary']['financial']['attention'] === 'refund_due' && $l3['allowedActions'] === ['acknowledge_liability'] && $l3['nextAction'] === 'acknowledge_refund', 'a paid order repriced down: refund liability 100, to acknowledge');
$error($act($rec, $th(3), 'acknowledge-liability', ['expectedRevision' => $revision($id(3))]), 403, 'CONFIRMARE_FINANCE_DENIED', 'only an approver acknowledges a liability');
$ack = $ok($act($app, $th(3), 'acknowledge-liability', ['expectedRevision' => $revision($id(3))]), 'acknowledge');
$check($ack['summary']['financial'] === ['state' => 'cleared', 'basis' => 'online_payment', 'attention' => 'refund_liability'] && $ack['nextAction'] === 'refund_after_production', 'acknowledged: cleared with refund_liability; the refund waits for production');
$ok(T::ingest($kernel, 'trendhome', $payload($id(3), $submitted(3), $waiting(3, 'refund_liability', 'a', 'cleared', 'online_payment'))), 'the order event of the acknowledged liability');
$o3 = $orderUuid($th(3));
$check($one('SELECT source_financial_attention FROM operational_orders WHERE order_uuid = ?', [$o3]) === 'refund_liability' && !in_array('payment', ConfirmationClearance::missing($pdo, $o3), true), 'production may start with an acknowledged refund liability (payment condition holds)');
$pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, idempotency_key, request_hmac, revision_before, state, requested_at)
    VALUES (?, ?, ?, 'trendhome', ?, 'acknowledge_liability', ?, ?, ?, 'pending', UTC_TIMESTAMP(6))")->execute(['00000000-0000-4000-8000-' . substr(hash('sha256', $suffix), 0, 12), $o3, $th(3), $uuids['fapp'], "fin-open-{$suffix}-00001", random_bytes(32), 'sha256:' . str_repeat('1', 64)]);
$check(in_array('payment', ConfirmationClearance::missing($pdo, $o3), true), 'a pending finance action closes the payment condition again');
$pdo->prepare('DELETE FROM order_finance_actions WHERE idempotency_key = ?')->execute(["fin-open-{$suffix}-00001"]);
$bad = T::ingest($kernel, 'trendhome', $payload($id(3), $submitted(3), $waiting(3, 'transfer_pending', 'a', 'cleared', 'online_payment')));
$check($bad['status'] === 422 && $one('SELECT source_financial_attention FROM operational_orders WHERE order_uuid = ?', [$o3]) === 'refund_liability', 'an open attention never comes with cleared: the event is refused, the projection kept');
$refund = static fn (string $amount, string $reference): array => ['expectedRevision' => $revision($id(3)), 'amount' => $amount, 'reference' => $reference, 'valueDate' => $today];
$requests = count($shop->requests);
foreach (['waiting' => 'in_progress', 'packing' => 'in_progress'] as $stage => $status) {
    $pdo->prepare('UPDATE operational_orders SET production_stage_id = ?, operational_status = ? WHERE order_uuid = ?')->execute([$stage, $status, $o3]);
    $error($act($rec, $th(3), 'submit-refund', $refund('100.00', 'RF FIN 0001')), 409, 'CONFIRMARE_FINANCE_REFUND_NOT_ELIGIBLE', "no refund at stage {$stage} (before the final manufacturing stage is complete)");
}
$check(count($shop->requests) === $requests, 'an ineligible refund never reaches the shop');
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'delivery' WHERE order_uuid = ?")->execute([$o3]);
$e3 = $ok($read($rec, $th(3)), 'eligible read');
$check($e3['production']['refundEligible'] === true && $e3['allowedActions'] === ['submit_refund'] && $e3['nextAction'] === 'record_refund', 'stage 14 (courier handover independent): the refund may be recorded');
$refused($act($rec, $th(3), 'submit-refund', $refund('150.00', 'RF FIN 0001')), 'amount_exceeds_liability', 'never more than the liability');
$rf = $ok($act($rec, $th(3), 'submit-refund', $refund('100.00', 'RF FIN 0001')), 'record refund');
$check($rf['summary']['financial']['attention'] === 'refund_pending' && $rf['summary']['totals']['refunded'] === '0.00', 'the bank refund is recorded: refund_pending, nothing refunded yet');
$check(($shop->requests[count($shop->requests) - 1]['body']['productionComplete'] ?? null) === true, 'Arasya states the completed manufacturing to the shop');
$error($act($rec, $th(3), 'decide-refund', ['expectedRevision' => $revision($id(3)), 'entryId' => $rf['entryId'], 'decision' => 'verify']), 403, 'CONFIRMARE_FINANCE_DENIED', 'the recorder cannot verify the refund');
$vr = $ok($act($app, $th(3), 'decide-refund', ['expectedRevision' => $revision($id(3)), 'entryId' => $rf['entryId'], 'decision' => 'verify']), 'verify refund');
$check($vr['summary']['financial'] === ['state' => 'cleared', 'basis' => 'online_payment', 'attention' => null] && $vr['summary']['totals']['refunded'] === '100.00' && $vr['summary']['balance']['refundLiability'] === '0.00', 'verified: refunded 100, the liability settled');
$pdo->prepare("UPDATE operational_orders SET operational_status = 'handed_over' WHERE order_uuid = ?")->execute([$o3]);
$check(ConfirmareFinance::refundEligible('delivery', 'handed_over') && !ConfirmareFinance::refundEligible('packing', 'in_progress') && !ConfirmareFinance::refundEligible('delivery', 'unavailable')
    && !ConfirmareFinance::refundEligible('delivery', 'in_progress', true), 'eligibility: stage 14 or handed over; never before, never a cancelled order (also one kept in production after cutting)');
$pdo->prepare("UPDATE operational_orders SET operational_status = 'in_progress', source_reported_unavailable_at = UTC_TIMESTAMP(6) WHERE order_uuid = ?")->execute([$o3]);
$check($ok($read($rec, $th(3)), 'cancelled read')['production']['refundEligible'] === false, 'an order the shop cancelled after cutting is never offered a refund');
$pdo->prepare('UPDATE operational_orders SET source_reported_unavailable_at = NULL WHERE order_uuid = ?')->execute([$o3]);

// ---- 7b. YD SOFT 2.16 safety refusals (owner rule 2, K-1): finance_open and order_cancelled ----------------------------
// The shop refuses and records nothing. Arasya closes the action as rejected (never unknown, never applied), stores no
// entry, opens nothing (production gate, cancellation) and replays the stored refusal for the same key. The codes count
// only as a signed 409 answer to their own actions; anywhere else they are a protocol violation and stay unknown.
// The per-minute shop request limit is tested on its own; this section starts from an empty bucket.
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$shop->safetyRules = true;
foreach ([11, 12, 14, 15] as $n) $ok(T::ingest($kernel, 'trendhome', $payload($id($n), $submitted($n), $waiting($n, 'additional_payment_due'))), "safety order {$n}");
$ok(T::ingest($kernel, 'trendhome', $payload($id(13), $submitted(13), $waiting(13, 'refund_due'))), 'safety order 13');
foreach ([11 => 'additional_payment_due', 12 => 'additional_payment_due', 13 => 'refund_due', 14 => 'additional_payment_due', 15 => 'additional_payment_due'] as $n => $code) {
    $exceptionTask($n, $code, $uuids['frec'], $uuids['fapp']);
}
$service->distribute();
foreach ([11, 12, 14, 15] as $n) $shop->orders[$id($n)] = ['id' => $id($n), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => false, 'total' => '650.00', 'paid' => '500.00', 'pricing' => $pricing($n)])];
$shop->orders[$id(13)] = ['id' => $id(13), 'phone' => FIN_PHONE, 'finance' => $finance(['cod' => false, 'total' => '700.00', 'paid' => '800.00', 'original' => '800.00', 'pricing' => $pricing(13)])];
$stateOf = static fn (string $globalId): array => $all('SELECT operational_status, cancellation_state, production_stage_id, source_financial_attention FROM operational_orders WHERE global_order_id = ?', [$globalId])[0];
$transferOf = static fn (int $n, string $reference): array => ['expectedRevision' => $revision($id($n)), 'purpose' => 'additional_payment', 'amount' => '40.00', 'reference' => $reference, 'valueDate' => $today];
$reconcile = static fn (array $who, string $globalId, string $key, string $label): array => T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($globalId) . '/finance/reconcile', ['idempotencyKey' => $key],
    ['origin' => FIN_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'fin-rec-' . $label . '-' . $suffix], $who['cookie']);

// order_cancelled on submit_transfer: rejected, nothing stored or opened; the same key replays without the shop.
$shop->orders[$id(11)]['status'] = 'cancelled';
$o11 = $orderUuid($th(11));
$clearance11 = ConfirmationClearance::missing($pdo, $o11);
$state11 = $stateOf($th(11));
$key11 = "fin-safety-{$suffix}-000011";
$body11 = $transferOf(11, 'OP SAFE 0011');
$refused($act($rec, $th(11), 'submit-transfer', $body11, $key11), 'order_cancelled', 'K-1: no transfer on an order the shop cancelled');
$row11 = $byKey($key11);
$check(($row11['state'] ?? null) === 'rejected' && ($row11['outcome'] ?? null) === 'order_cancelled' && $row11['source_entry_id'] === null && $row11['completed_at'] !== null, 'K-1: closed as rejected (order_cancelled), no entry: never read as money');
$check(!ConfirmareFinance::actionOpen($pdo, $o11) && ConfirmationClearance::missing($pdo, $o11) === $clearance11 && $stateOf($th(11)) === $state11, 'K-1: nothing opened: no pending action, production gate and order state unchanged (not a cancellation either)');
$check(($shop->orders[$id(11)]['finance']['entries'] ?? []) === [], 'K-1: the shop recorded no entry');
$requests = count($shop->requests);
$refused($act($rec, $th(11), 'submit-transfer', $body11, $key11), 'order_cancelled', 'K-1: the same request again (same key)');
$check(count($shop->requests) === $requests && count($actions($th(11))) === 1, 'K-1: the replay is answered from the record: no second request to the shop, no second action');
$refused($act($rec, $th(11), 'submit-transfer', $transferOf(11, 'OP SAFE 0011')), 'order_cancelled', 'K-1: a new attempt (new key) is refused the same way');
$check(count($actions($th(11))) === 2 && array_column($actions($th(11)), 'state') === ['rejected', 'rejected'] && ($shop->orders[$id(11)]['finance']['entries'] ?? []) === [], 'K-1: each attempt is one rejected audit row; no finance entry anywhere');
// The answer of the refusal is lost: unknown for now, then settled as rejected from the shop's stored answer.
$keyLost = "fin-safety-{$suffix}-000111";
$shop->afterRefusal = 'answer_lost';
$lost11 = $ok($act($rec, $th(11), 'submit-transfer', $transferOf(11, 'OP SAFE 0111'), $keyLost), 'K-1 lost refusal');
$check($lost11['state'] === 'unknown' && ConfirmareFinance::actionOpen($pdo, $o11), 'a lost refusal is unknown at first (never guessed)');
$refused($reconcile($rec, $th(11), $keyLost, '11a'), 'order_cancelled', 'reconciled with the stored refusal');
$check(($byKey($keyLost)['state'] ?? null) === 'rejected' && ($byKey($keyLost)['outcome'] ?? null) === 'order_cancelled' && !ConfirmareFinance::actionOpen($pdo, $o11), 'the temporary unknown became rejected: the order is not blocked for good');
$shop->afterRefusal = 'crash';
$keyCrash = "fin-safety-{$suffix}-000112";
$check($ok($act($rec, $th(11), 'submit-transfer', $transferOf(11, 'OP SAFE 0112'), $keyCrash), 'K-1 5xx after refusal')['state'] === 'unknown', 'a 5xx after the refusal is unknown');
$refused($reconcile($app, $th(11), $keyCrash, '11b'), 'order_cancelled', 'another approver reconciles it');
$check(($byKey($keyCrash)['state'] ?? null) === 'rejected' && !ConfirmareFinance::actionOpen($pdo, $o11), 'settled as rejected');

// order_cancelled on decide_transfer: a transfer recorded before the cancellation is not verified; rejecting it works.
$t12 = $ok($act($rec, $th(12), 'submit-transfer', $transferOf(12, 'OP SAFE 0012')), 'transfer while the order was active');
$shop->orders[$id(12)]['status'] = 'cancelled';
$refused($act($app, $th(12), 'decide-transfer', $decide($id(12), $t12['entryId'])), 'order_cancelled', 'K-1: the pending transfer is not verified as money after the cancellation');
$check($shop->orders[$id(12)]['finance']['entries'][0]['state'] === 'submitted' && !ConfirmareFinance::actionOpen($pdo, $orderUuid($th(12))), 'K-1: the entry stays pending, the action is closed');
$rejected12 = $ok($act($app, $th(12), 'decide-transfer', $decide($id(12), $t12['entryId'], 'reject', 'wrong_order')), 'K-1: rejecting stays possible');
$check($rejected12['state'] === 'applied' && $shop->orders[$id(12)]['finance']['entries'][0]['state'] === 'rejected', 'K-1: rejected (never money)');

// finance_open on submit_refund and decide_refund: a transfer of the order is still pending.
$o13 = $orderUuid($th(13));
$ok($act($app, $th(13), 'acknowledge-liability', ['expectedRevision' => $revision($id(13))]), 'liability acknowledged');
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'delivery' WHERE order_uuid = ?")->execute([$o13]);
$pendingTransfer = ['id' => '00000000-0000-4000-9000-000000001301', 'kind' => 'transfer_in', 'purpose' => 'additional_payment', 'amount' => '10.00', 'reference' => 'OP SAFE 1301', 'valueDate' => $today,
    'state' => 'submitted', 'submittedBy' => $uuids['frec'], 'submittedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'decidedBy' => null, 'decidedAt' => null, 'decisionCode' => null];
$shop->orders[$id(13)]['finance']['entries'][] = $pendingTransfer;
$refundOf = static fn (string $reference): array => ['expectedRevision' => $revision($id(13)), 'amount' => '100.00', 'reference' => $reference, 'valueDate' => $today];
$refused($act($rec, $th(13), 'submit-refund', $refundOf('RF SAFE 1301')), 'finance_open', 'rule 2: no refund is recorded while a transfer is pending');
$check(!ConfirmareFinance::actionOpen($pdo, $o13) && count($shop->orders[$id(13)]['finance']['entries']) === 2, 'rule 2: closed as rejected, the shop recorded nothing new');
$ok(T::ingest($kernel, 'trendhome', $payload($id(13), $submitted(13), $waiting(13, 'transfer_pending'))), 'the shop reports the pending transfer');
$check(in_array('payment', ConfirmationClearance::missing($pdo, $o13), true) && $one('SELECT source_financial_attention FROM operational_orders WHERE order_uuid = ?', [$o13]) === 'transfer_pending', 'rule 2: the open entry keeps the production gate closed (the shop\'s attention, not the refusal)');
$shop->orders[$id(13)]['finance']['entries'] = array_values(array_filter($shop->orders[$id(13)]['finance']['entries'], static fn (array $e): bool => $e['id'] !== $pendingTransfer['id']));
$rf13 = $ok($act($rec, $th(13), 'submit-refund', $refundOf('RF SAFE 1302')), 'refund recorded once the transfer is decided');
$shop->orders[$id(13)]['finance']['entries'][] = $pendingTransfer;
$refused($act($app, $th(13), 'decide-refund', ['expectedRevision' => $revision($id(13)), 'entryId' => $rf13['entryId'], 'decision' => 'verify']), 'finance_open', 'rule 2: the refund is not verified into WooCommerce while another entry is open');
$check(!ConfirmareFinance::actionOpen($pdo, $o13) && $shop->financeNumbers($id(13))['refunded'] < 0.01, 'rule 2: nothing refunded, the action is closed');
$ok($act($app, $th(13), 'decide-refund', ['expectedRevision' => $revision($id(13)), 'entryId' => $rf13['entryId'], 'decision' => 'reject', 'reasonCode' => 'other']), 'rule 2: rejecting the refund stays possible');

// Protocol violations: the codes outside their actions or as another HTTP status, or an unsigned answer, are never a decision.
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$shop->forceRefusal = ['finance_open', 409];
$keyWrong = "fin-safety-{$suffix}-000014";
$check($ok($act($rec, $th(14), 'submit-transfer', $transferOf(14, 'OP SAFE 0014'), $keyWrong), 'finance_open on submit_transfer')['state'] === 'unknown', 'finance_open answered to a transfer is a protocol violation: unknown, never read as a decision');
$check($ok($reconcile($rec, $th(14), $keyWrong, '14'), 'reconcile the violation')['state'] === 'unknown' && ConfirmareFinance::actionOpen($pdo, $orderUuid($th(14))), 'the violation stays unknown for a person (the gate stays closed)');
$shop->forceRefusal = ['order_cancelled', 422];
$check($ok($act($rec, $th(15), 'submit-transfer', $transferOf(15, 'OP SAFE 0015')), 'order_cancelled as 422')['state'] === 'unknown', 'order_cancelled with another status than 409 is a protocol violation');
$pdo->prepare("UPDATE order_finance_actions SET state = 'rejected', outcome = 'test_cleanup', completed_at = UTC_TIMESTAMP(6) WHERE global_order_id = ? AND state = 'unknown'")->execute([$th(15)]);
$shop->orders[$id(15)]['status'] = 'cancelled';
$shop->tamper = ['sign' => false];
$check($ok($act($rec, $th(15), 'submit-transfer', $transferOf(15, 'OP SAFE 0016')), 'unsigned order_cancelled')['state'] === 'unknown', 'an unsigned order_cancelled is not trusted: unknown');
$shop->safetyRules = false;

// ---- 8. The finance queue -------------------------------------------------------------------------------------------
$q = $ok($get($view, '/confirmare/finance'), 'queue');
$check($q['orders'] === [] && array_sum($q['tasks']) === 0 && $q['sources'] === [], 'API 2.50.0: a finance scope lists no order of its shop (its work is the tasks assigned to it)');
foreach ([$rec, $app] as $financeEmployee) {
    $check($ok($get($financeEmployee, '/confirmare/finance'), 'finance queue')['orders'] === [], 'record or approve lists nothing either');
}
// API 2.44.0: a Confirmare operator without a finance scope sees the advance tasks of their shop only (read only).
$oq = $ok($get($op, '/confirmare/finance'), 'operator queue');
$otasks = array_column($oq['orders'], 'task', 'globalOrderId');
$check(array_diff(array_unique(array_values($otasks)), ConfirmareFinance::ADVANCE_TASKS) === [] && ($otasks[$th(4)] ?? null) === 'advance_waiting' && !isset($otasks[$th(2)])
    && array_sum(array_intersect_key($oq['tasks'], array_flip(array_diff(ConfirmareFinance::TASKS, ConfirmareFinance::ADVANCE_TASKS)))) === 0, 'without a finance scope an operator sees only the advance tasks (never additional payments, refunds or reconciliation): ' . json_encode($otasks));
$check($ok($get($out, '/confirmare/finance?source=trendhome'), 'other store queue')['orders'] === [], 'another store\'s queue never lists Trend Home');
$error($get($view, '/confirmare/finance?task=everything'), 422, 'CONFIRMARE_FINANCE_INVALID', 'unknown task');
// API 2.44.0 (bank transfer of the advance): while finance verifies a transfer, the operator still sees the order as
// waiting for (or partially paid) its advance, marked as in verification; finance sees the verification task.
$ok(T::ingest($kernel, 'trendhome', $payload($id(4), $submitted(4), $waiting(4, 'transfer_pending', 'a', 'partial'), 'cod')), 'COD order: a transfer waits for the second finance employee');
$oq2 = array_column($ok($get($op, '/confirmare/finance'), 'operator queue in verification')['orders'], null, 'globalOrderId');
$check(($oq2[$th(4)]['task'] ?? null) === 'advance_partial' && ($oq2[$th(4)]['verifying'] ?? null) === true && $ok($get($view, '/confirmare/finance'), 'finance queue in verification')['orders'] === [],
    'a transfer in verification: the operator sees "partial, in verification" (never paid); finance sees it as a task of its inbox, never in a shop-wide list');
$ok(T::ingest($kernel, 'trendhome', $payload($id(4), $submitted(4), $waiting(4, null), 'cod')), 'COD order back to waiting');

// ---- 8b. Bank transfers that match no order: the shop's exception record (API 2.44.0, YD SOFT 2.18.0) --------------------
$xget = static fn (array $who, string $query = ''): array => $get($who, '/confirmare/finance/transfer-exceptions' . $query);
$xact = static fn (array $who, string $action, array $body, ?string $key = null, string $source = 'trendhome'): array => T::call($kernel, 'POST', '/confirmare/finance/transfer-exceptions/' . $action, $body,
    ['origin' => FIN_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'fin-exc-' . bin2hex(random_bytes(10))], $who['cookie'], ['source' => $source]);
$unmatched = ['amount' => '250.00', 'currency' => 'RON', 'reference' => 'OP FIN EXC 0001', 'valueDate' => $today, 'reason' => 'unmatched'];
$l0 = $ok($xget($view), 'exception record, view');
$check($l0['source'] === 'trendhome' && $l0['available'] === true && $l0['exceptions'] === [] && $l0['capabilities'] === ['record' => false, 'approve' => false], 'finance view reads the shop\'s exception record (its only shop picked), acts on nothing');
$requests = count($shop->requests);
$error($xact($view, 'record', $unmatched), 403, 'CONFIRMARE_FINANCE_DENIED', 'a viewer cannot record an exception');
$error($xact($op, 'record', $unmatched), 403, 'CONFIRMARE_FINANCE_DENIED', 'the operator cannot record an exception');
$check($xact($root, 'record', $unmatched)['status'] === 403, 'root holds no finance scope (403)');
$error($xget($none), 403, 'CONFIRMARE_FINANCE_DENIED', 'without a finance scope the exception record is closed');
$error($xact($rec, 'record', ['reason' => 'lost'] + $unmatched), 422, 'CONFIRMARE_FINANCE_INVALID', 'an unknown reason');
$error($xact($rec, 'record', $unmatched + ['orderNumber' => '1']), 400, 'INVALID_REQUEST', 'no other field');
$check(count($shop->requests) === $requests, 'refused exception actions never reach the shop');
$xkey = "fin-exc-{$suffix}-000001";
$x1 = $ok($xact($rec, 'record', $unmatched, $xkey), 'record exception');
$check($x1['state'] === 'applied' && count($x1['exceptions']) === 1 && $x1['exceptions'][0]['state'] === 'open' && $x1['exceptions'][0]['amount'] === '250.00' && $x1['capabilities'] === ['record' => true, 'approve' => false]
    && ($shop->requests[count($shop->requests) - 1]['body']['actor'] ?? null) === ['employeeId' => $uuids['frec'], 'role' => 'finance'] && !isset($shop->requests[count($shop->requests) - 1]['body']['orderId']), 'a transfer that matches no order is recorded in the shop\'s exception record (open), sent as finance without order fields');
$check($ok($xact($rec, 'record', $unmatched, $xkey), 'replay')['entryId'] === $x1['entryId'] && count($shop->exceptions) === 1, 'a retried request (same key) answers the same entry');
$refused($xact($app2, 'record', ['reference' => 'op-fin-exc-0001'] + $unmatched), 'duplicate_reference', 'the same bank reference twice (other spelling)');
$error($xact($rec, 'resolve', ['entryId' => $x1['entryId'], 'resolution' => 'returned_to_payer']), 403, 'CONFIRMARE_FINANCE_DENIED', 'record alone never closes an exception');
$error($xact($app, 'resolve', ['entryId' => $x1['entryId'], 'resolution' => 'recorded_on_order']), 422, 'CONFIRMARE_FINANCE_INVALID', 'recorded on an order names the order');
$error($xact($app, 'resolve', ['entryId' => $x1['entryId'], 'resolution' => 'recorded_on_order', 'orderNumber' => $id(50)]), 422, 'CONFIRMARE_FINANCE_INVALID', 'an order of another shop is refused');
$error($xact($app, 'resolve', ['entryId' => $x1['entryId'], 'resolution' => 'recorded_on_order', 'orderNumber' => $id(60)]), 422, 'CONFIRMARE_FINANCE_INVALID', 'an order outside Confirmare is refused');
$refused($xact($app, 'resolve', ['entryId' => $x1['entryId'], 'resolution' => 'recorded_on_order', 'orderNumber' => $id(4)]), 'transfer_not_recorded', 'the order does not hold that transfer yet');
$t4 = $ok($act($rec, $th(4), 'submit-transfer', ['expectedRevision' => $revision($id(4)), 'purpose' => 'advance', 'amount' => '40.00', 'reference' => 'OP FIN EXC 0001', 'valueDate' => $today]), 'the transfer recorded on its order');
$x2 = $ok($xact($app, 'resolve', ['entryId' => $x1['entryId'], 'resolution' => 'recorded_on_order', 'orderNumber' => '#' . $id(4)]), 'resolve on order');
$check($x2['exceptions'][0]['state'] === 'resolved' && $x2['exceptions'][0]['resolution'] === 'recorded_on_order' && $x2['exceptions'][0]['order']['id'] === $id(4) && $x2['exceptions'][0]['resolvedBy'] === $uuids['fapp']
    && ($shop->requests[count($shop->requests) - 1]['body']['resolvedOrderId'] ?? null) === $id(4), 'another finance employee closes it once the order holds the transfer (the shop is given its own order id)');
$ok($act($app, $th(4), 'decide-transfer', $decide($id(4), $t4['entryId'])), 'the transfer verified on the order');
$x3 = $ok($xact($app2, 'record', ['amount' => '90.00', 'reference' => 'OP FIN EXC 0002', 'reason' => 'over_payment'] + $unmatched), 'record by record+approve');
$refused($xact($app2, 'resolve', ['entryId' => $x3['entryId'], 'resolution' => 'returned_to_payer']), 'same_employee', 'four eyes: the shop refuses the employee who recorded it');
$shop->afterException = 'answer_lost';
$lkey = "fin-exc-{$suffix}-000002";
$x4 = $ok($xact($app, 'resolve', ['entryId' => $x3['entryId'], 'resolution' => 'returned_to_payer'], $lkey), 'lost answer');
$x5 = $ok($xact($app, 'resolve', ['entryId' => $x3['entryId'], 'resolution' => 'returned_to_payer'], $lkey), 'retry');
$check($x4 === ['state' => 'unknown', 'idempotencyKey' => $lkey] && $x5['state'] === 'applied' && $x5['entryId'] === $x3['entryId'], 'a lost answer is unknown; the same key gets the shop\'s stored answer (never a second record)');
$check($ok($xget($out, '?source=outletperdele'), 'outlet exceptions')['available'] === false && $ok($xget($out, '?source=outletperdele'), 'outlet')['reason'] === 'not_configured', 'a shop without finance: not configured');
$error($xget($out, '?source=trendhome'), 403, 'CONFIRMARE_FINANCE_DENIED', 'another shop\'s finance employee never reads Trend Home\'s exceptions');
$events = $all("SELECT event_type, metadata_json FROM auth_audit_events WHERE event_type LIKE 'CONFIRMARE_TRANSFER_EXCEPTION%' AND employee_uuid IN (?, ?, ?, ?) ORDER BY created_at", [$uuids['fview'], $uuids['frec'], $uuids['fapp'], $uuids['fapp2']]);
$check(in_array('CONFIRMARE_TRANSFER_EXCEPTION_RECORD', array_column($events, 'event_type'), true) && in_array('CONFIRMARE_TRANSFER_EXCEPTION_RESOLVE', array_column($events, 'event_type'), true) && in_array('CONFIRMARE_TRANSFER_EXCEPTIONS_READ', array_column($events, 'event_type'), true),
    'every exception read and action is audited (codes only): ' . count($events));

// ---- 8c. API 2.45.0 (YD SOFT 2.19.0): cash with its document, more money after the advance, over-payment, a cancelled proforma --
$c8 = $ok($read($rec, $th(4)), 'COD read 8c');
$check(in_array('submit_transfer', $c8['allowedActions'], true) && $c8['summary']['position']['remaining'] === '710.00' && $c8['summary']['position']['advanceGap'] === '10.00' && $c8['summary']['financial']['state'] === 'partial',
    'the one money calculation: 290 of 300 received, the advance misses 10.00, the customer owes 710.00');
$cash = ['expectedRevision' => $revision($id(4)), 'purpose' => 'advance', 'amount' => '20.00', 'reference' => 'OP FIN CASH 0001', 'valueDate' => $today];
$requests = count($shop->requests);
$error($act($rec, $th(4), 'submit-transfer', $cash + ['method' => 'cash_office']), 422, 'CONFIRMARE_FINANCE_INVALID', 'cash without its fiscal receipt or chitanță is refused');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['method' => 'cash_courier', 'collector' => 'Ion Popescu']), 422, 'CONFIRMARE_FINANCE_INVALID', 'cash with its collector but no document is refused');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['method' => 'cash_courier', 'receipt' => 'factura', 'collector' => 'Ion Popescu']), 422, 'CONFIRMARE_FINANCE_INVALID', 'a cash document is a fiscal receipt or a chitanță');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['method' => 'cash_office', 'receipt' => 'bon_fiscal', 'collector' => '<script>']), 422, 'CONFIRMARE_FINANCE_INVALID', 'who collected the cash is a name');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['method' => 'cash_drawer', 'receipt' => 'bon_fiscal', 'collector' => 'Ion Popescu']), 422, 'CONFIRMARE_FINANCE_INVALID', 'an unknown method is refused');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['receipt' => 'bon_fiscal']), 422, 'CONFIRMARE_FINANCE_INVALID', 'a bank transfer names no cash document');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['overpaymentConfirmed' => false]), 422, 'CONFIRMARE_FINANCE_INVALID', 'the over-payment confirmation is true or absent');
$error($act($rec, $th(4), 'submit-transfer', $cash + ['cancelledProforma' => 'ignore']), 422, 'CONFIRMARE_FINANCE_INVALID', 'the cancelled-proforma decision is apply_to_order or absent');
$check(count($shop->requests) === $requests, 'refused finance actions never reach the shop');
$shop->orders[$id(4)]['finance']['proformaCancelled'] = true;
$refused($act($rec, $th(4), 'submit-transfer', ['expectedRevision' => $revision($id(4))] + $cash + ['method' => 'cash_office', 'receipt' => 'bon_fiscal', 'collector' => 'Ion Popescu']), 'proforma_cancelled', 'money for a cancelled proforma needs the finance decision');
$cashed = $ok($act($rec, $th(4), 'submit-transfer', ['expectedRevision' => $revision($id(4))] + $cash + ['method' => 'cash_office', 'receipt' => 'bon_fiscal', 'collector' => 'Ion Popescu', 'cancelledProforma' => 'apply_to_order']), 'cash recorded with its document');
$entryCash = array_values(array_filter($cashed['summary']['entries'], static fn (array $e): bool => $e['id'] === $cashed['entryId']))[0] ?? [];
$check(($entryCash['method'] ?? null) === 'cash_office' && ($entryCash['receipt'] ?? null) === 'bon_fiscal' && ($entryCash['collector'] ?? null) === 'Ion Popescu' && ($entryCash['flags'] ?? null) === ['cancelled_proforma'], 'the entry names the method, the document, the collector and the finance decision');
$shop->orders[$id(4)]['finance']['proformaCancelled'] = false;
$vc = $ok($act($app, $th(4), 'decide-transfer', $decide($id(4), $cashed['entryId'])), 'cash verified by another employee');
$check($vc['summary']['financial']['state'] === 'cleared' && $vc['summary']['position']['remaining'] === '690.00' && in_array('submit_transfer', $ok($read($rec, $th(4)), 'after the advance')['allowedActions'], true), 'the advance is complete (310 of 300); more money may still be recorded (owed 690.00)');
$over = ['expectedRevision' => $revision($id(4)), 'purpose' => 'advance', 'amount' => '700.00', 'reference' => 'OP FIN OVER 0001', 'valueDate' => $today];
$refused($act($rec, $th(4), 'submit-transfer', $over), 'amount_exceeds_balance', 'more than the customer owes, without the explicit confirmation');
$o4 = $ok($act($rec, $th(4), 'submit-transfer', ['expectedRevision' => $revision($id(4))] + $over + ['overpaymentConfirmed' => true]), 'the over-payment recorded whole');
$vo = $ok($act($app, $th(4), 'decide-transfer', $decide($id(4), $o4['entryId'])), 'over-payment verified');
$check($vo['summary']['position']['remaining'] === '0.00' && $vo['summary']['position']['excess'] === '10.00' && $vo['summary']['financial']['attention'] === 'refund_due', '1010 on an order of 1000: owed 0.00 (never negative), excess 10.00 is a finance exception (refund_due)');

// ---- 9. No amount, reference or telephone number stored or logged ----------------------------------------------------
$leak = static function (string $text): bool {
    return preg_match('/(?<![0-9A-Za-z.])(?:650|150|120|100|60|90|800|700|1000|500|420|300|250|50)(?:[.,][0-9]{1,2})(?![0-9A-Za-z])/', $text) === 1
        || preg_match('/OP[ -]?FIN|RF[ -]?FIN|0711\s?444\s?555|711444555/i', $text) === 1;
};
$check($leak('{"amount":"650.00"}') && $leak('ref OP FIN 0001') && $leak('tel 0711 444 555') && !$leak('2026-10-22 13:45:00.100000') && !$leak('sha256:' . hash('sha256', 'x')), 'the leak check finds amounts, references and the phone, and ignores timestamps and hashes');
// Binary columns (HMACs, hashes) as hex, so every row can be scanned as text.
$text = static fn (array $rows): array => array_map(static fn (array $row): array => array_map(static fn (mixed $v): mixed => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? bin2hex($v) : $v, $row), $rows);
$dump = json_encode([$text($all("SELECT event_type, request_id, metadata_json FROM auth_audit_events WHERE event_type LIKE 'CONFIRMARE_TRANSFER_EXCEPTION%' AND employee_uuid IN (?, ?, ?, ?)", [$uuids['fview'], $uuids['frec'], $uuids['fapp'], $uuids['fapp2']])), $text($all("SELECT * FROM order_finance_actions WHERE source_key = 'trendhome'")), $all("SELECT * FROM order_finance_reads WHERE source_key = 'trendhome'"), $all("SELECT * FROM iam_audit_events WHERE action = 'employee.finance_scopes_changed' AND target_id = ?", [$uuids['frec']]),
    $text($all('SELECT * FROM operational_orders WHERE global_order_id IN (' . implode(', ', array_fill(0, 5, '?')) . ')', [$th(1), $th(2), $th(3), $th(4), $th(5)]))], JSON_THROW_ON_ERROR);
$check(!$leak($dump), 'the finance tables, the IAM audit and the projection hold no amount, bank reference or telephone number');
$log = (string) file_get_contents($logFile);
$check(!$leak($log) && !str_contains($log, FIN_KEY), 'the server log holds no amount, reference, telephone number or key');
@unlink($logFile);

// Leave the shared disposable database as the next suites expect it: no Confirmare or finance state.
foreach (['finance_verification_request_events', 'finance_verification_requests', 'employee_finance_supervision', 'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare finance integration: {$checks} checks (root-only finance scopes apart from Confirmare, readers and actors, four eyes, revision, idempotency, duplicate references, lost answers and 5xx reconciled, a request never received, one action per order, production closed while unsettled, liability acknowledgement, refunds only after manufacturing, Phase 5 attentions, the YD SOFT 2.16 refusals finance_open and order_cancelled, the finance queue, the operator's view of a transfer in verification, the shop's exception record of unmatched transfers, no amount stored or logged).\n");
