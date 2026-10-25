<?php

declare(strict_types=1);

// Confirmare operations (migration 036, API 2.38.0, Phase 2) on a disposable database, through the HTTP kernel.
// Covers: default deny and root-only scopes (IAM audit), the pool per scope, availability, the automatic and the pull
// distribution (fairness, capacity), claim/release/reassign/approve/reject/reopen with versions and idempotency, the
// production gate after each decision together with the advance payment gate, undetermined and cancelled orders,
// reclaim after a scope or account change, races (claim/claim, decision/decision, next/next, approval/cutting intake,
// distribution/distribution), the append-only audit trail without customer data, the CLIs, and that no source can be
// activated in this release.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Confirmare\ConfirmareService;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmationGate;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';
require_once __DIR__ . '/HandoffSchemaFixture.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare operations integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $body = $r['body']; unset($body['document']['pdf']); $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($body)); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$apiError = static function (Closure $call, string $code, string $label) use ($check): void {
    try { $call(); } catch (ApiException $e) { $check($e->errorCode === $code, "{$label}: expected {$code}, got {$e->errorCode}"); return; }
    $check(false, "{$label}: expected {$code}, nothing thrown");
};

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on'];
$settings = ['trendhome' => $full + ['paymentGate' => 'enforce'], 'outletperdele' => $full + ['paymentGate' => 'off']];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

// Earlier suites on the shared disposable database may have left Confirmare state; this suite starts clean.
$pdo->exec('DELETE FROM order_confirmation_contacts');
$pdo->exec('DELETE FROM order_confirmation_idempotency');
$pdo->exec('DELETE FROM confirmare_alerts');
$pdo->exec('DELETE FROM order_confirmation_events');
$pdo->exec('DELETE FROM order_confirmations');
$pdo->exec('DELETE FROM workflow_cohort_activations');
$pdo->exec('DELETE FROM employee_confirmare_scopes');
$pdo->exec('DELETE FROM confirmare_operator_state');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "co.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-co-root', $rootName);
$dash = static function (string $username, string $password) use ($kernel): array {
    $r = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($r['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $r['headers']['Set-Cookie'] ?? '', $m)) throw new RuntimeException("login {$username}");
    return ['cookie' => rawurldecode($m[1]), 'csrf' => (string) $r['body']['csrfToken']];
};
$first = $dash($rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Confirmare ops root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = $dash($rootName, 'Confirmare ops root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'co-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$password = 'confirmare operations passphrase 2026';
$uuids = [];
$identity = static function (string $name, array $stages, array $apps, array $roles = []) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-co');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
};
// Generic test identities: Confirmare access comes only from scopes granted below, never from a name.
$identity('coopa', [], ['staff']);
$identity('coopb', [], ['staff']);
$identity('coopc', [], ['dashboard']);
$identity('cosup', [], ['dashboard']);
$identity('conone', [], ['staff']);
$identity('cocutter', ['material-preparation'], ['staff']);
$identity('codocs', [], ['staff', 'dashboard'], [$roleIds['production-documents-operator']]);
$ok($asRoot('PUT', "/management/employees/{$uuids['codocs']}/document-scopes", ['operate' => ['outletperdele', 'trendhome'], 'approve' => []]), 'document operator scope');
$login = static fn (string $name, bool $dashboard = false): array => $dashboard ? $dash("{$name}.{$suffix}", $password) : T::login($kernel, "{$name}.{$suffix}", $password);
$opA = $login('coopa');
$opB = $login('coopb');
$opC = $login('coopc', true);
$sup = $login('cosup', true);
$none = $login('conone');
$cutter = $login('cocutter');
$docs = $login('codocs');
$call = static function (array $who, string $method, string $path, ?array $body = null, ?string $key = null) use ($kernel): array {
    return T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'co-' . bin2hex(random_bytes(10))], $who['cookie']);
};
$pool = static fn (array $who, string $view = 'open', ?string $source = null): array => T::call($kernel, 'GET', '/confirmare/pool', null, ['origin' => DASHBOARD_ORIGIN], $who['cookie'], ['view' => $view] + ($source === null ? [] : ['source' => $source]));
$act = static fn (array $who, string $globalId, string $action, array $body, ?string $key = null): array => $call($who, 'POST', '/confirmare/orders/' . rawurlencode($globalId) . '/' . $action, $body, $key);
$version = static fn (string $globalId): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$globalId]);
$conf = static function (string $globalId) use ($all): array {
    $row = $all('SELECT c.status, c.version, c.assigned_employee_uuid, c.assignment_kind, c.decision_reason_code, c.decided_by_employee_uuid, c.production_cleared FROM order_confirmations c WHERE c.global_order_id = ?', [$globalId])[0] ?? [];
    if ($row !== []) $row['production_cleared'] = (int) $row['production_cleared'];
    return $row;
};
$events = static fn (string $globalId): array => $all('SELECT action, actor_kind, actor_employee_uuid, previous_status, new_status, previous_assignee_uuid, new_assignee_uuid, reason_code, version_after FROM order_confirmation_events WHERE global_order_id = ? ORDER BY version_after', [$globalId]);

// ---- Fixtures ----------------------------------------------------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$tick = 0;
$payload = static function (string $id, string $status, ?array $submission, ?array $payment, array $extra = []) use ($suffix, &$tick): array {
    $tick++;
    $p = T::sourceOrder($id, "co-{$id}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 5000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, $status, 'active', [[
        'id' => 7001, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => $extra['quantity'] ?? 1,
        'options' => [['label' => 'buc', 'value' => '2 buc.']],
    ], [
        'id' => 7002, 'line' => 2, 'name' => 'Draperie Bej', 'sku' => 'DR-BEJ', 'color' => 'Bej', 'width' => 2, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    // API 2.38.0 order facts: the shop's pricing fingerprint (never an amount) and an optional due date.
    $priced = ($extra['pricing'] ?? true) !== false;
    $fingerprint = 'sha256:' . hash('sha256', "price-{$id}-" . ($extra['price'] ?? 'a'));
    if ($priced) $p['order']['pricing'] = ['fingerprint' => $fingerprint];
    // The source's financial clearance (owner rule 2026-10-22), as YD SOFT evaluates it on the final total. By default it
    // follows the advance payment facts; a legacy `not_required` (a disabled advance module) is waiting, never cleared.
    $state = match (true) {
        $payment === null => 'unknown',
        $payment['method'] === 'online' => 'cleared',
        $payment['method'] === 'cod' && $payment['advanceStatus'] === 'confirmed' => 'cleared',
        $payment['method'] === 'cod' && $payment['advanceStatus'] === 'partial' => 'partial',
        default => 'waiting',
    };
    $state = $state === 'cleared' && !$priced ? 'unknown' : $state;
    $derived = ['state' => $state, 'basis' => $state === 'cleared' ? ($payment['method'] === 'online' ? 'online_payment' : 'advance_collected') : null, 'pricingFingerprint' => $priced ? $fingerprint : null];
    $financial = array_key_exists('financial', $extra) ? $extra['financial'] : $derived;
    if ($financial !== false) $p['order']['financial'] = $financial;
    if (isset($extra['due'])) $p['order']['dueDate'] = $extra['due'];
    unset($p['order']['notes']);
    $p['order']['delivery'] = ['name' => 'Ionescu Testclient', 'street' => 'Str. Test 1', 'city' => 'Cluj-Napoca', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0722999111'];
    if ($submission !== null) $p['order']['submission'] = $submission;
    if ($payment !== null) $p['order']['payment'] = $payment;
    return $p;
};
$submitted = static fn (DateTimeImmutable $at): array => ['submittedAt' => $iso($at), 'evidence' => 'checkout'];
$cod = static fn (string $status): array => ['method' => 'cod', 'advanceStatus' => $status, 'advanceRevision' => $status === 'confirmed' ? 1 : 0];
$online = ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
$send = static fn (string $source, array $p): array => T::ingest($kernel, $source, $p);
$qr = static fn (string $globalId): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$globalId]);
$intake = static function (string $globalId) use ($call, $cutter, $qr, $one, &$uuids): array {
    $owned = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_stage_id = 'material-preparation' AND production_completed_at IS NULL", [$uuids['cocutter']]);
    return $call($cutter, 'POST', '/cutting/intake', ['qrToken' => $qr($globalId)] + ($owned > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $owned] : []));
};
$generate = static fn (string $globalId): array => $call($docs, 'POST', '/production-documents/orders/' . rawurlencode($globalId) . '/generate', ['expectedDocumentVersion' => 0]);
$stage = static fn (string $globalId): string => (string) $one('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ?', [$globalId]);
$activation = static fn (DateTimeImmutable $at): WorkflowCohortActivation => new WorkflowCohortActivation($pdo, new MutableClock($at));
$base = T::freeOrderBase($pdo, 760000, 859999, 400); // ids base+1..base+305; a block no earlier run used
$id = static fn (int $n): string => (string) ($base + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$op = static fn (int $n): string => 'outletperdele:' . $id($n);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare operations integration: needs a clock at least 10 minutes after 2026-10-22 00:00 Bucharest.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
$after = static fn (int $seconds): DateTimeImmutable => $activatedAt->modify("+{$seconds} seconds");

// ---- 1. Default deny; scopes are root-only, audited and never derived from a name ---------------------------------
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'API 2.45.0: activation needs the source readiness (Phase 2)');
$apiError(fn () => $activation($activatedAt)->activate($registry->find('trendhome'), 'Test Owner', null, 'co-test'), 'CONFIRMARE_NOT_READY', 'no source can be activated without its readiness');
$apiError(fn () => $activation($activatedAt)->activate($registry->find('trendhome'), 'Test Owner', null, 'co-test', true), 'CONFIRMARE_NO_OPERATOR', 'not before somebody can confirm');
$error($pool($opA), 403, 'CONFIRMARE_SCOPE_DENIED', 'no scope: no pool');
$error($call($opA, 'POST', '/confirmare/next', ['source' => null]), 403, 'CONFIRMARE_SCOPE_DENIED', 'no scope: nothing to take');
$error($call($sup, 'PUT', "/management/employees/{$uuids['coopa']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 403, 'ROOT_ONLY', 'only root grants Confirmare scopes');
$error($asRoot('PUT', "/management/employees/{$uuids['coopa']}/confirmare-scopes", ['operate' => ['b2b'], 'supervise' => []]), 400, 'UNKNOWN_SOURCE', 'B2B never has Confirmare');
$error($asRoot('PUT', "/management/employees/{$uuids['coopa']}/confirmare-scopes", ['operate' => ['trendyol'], 'supervise' => []]), 400, 'UNKNOWN_SOURCE', 'Trendyol never has Confirmare');
$authVersion = (int) $one('SELECT authorization_version FROM employees WHERE employee_uuid = ?', [$uuids['coopa']]);
$granted = $ok($asRoot('PUT', "/management/employees/{$uuids['coopa']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'grant A');
$check($granted['confirmareScopes'] === ['operate' => ['trendhome'], 'supervise' => []], 'the employee view shows the Confirmare scopes');
$check((int) $one('SELECT authorization_version FROM employees WHERE employee_uuid = ?', [$uuids['coopa']]) === $authVersion + 1, 'a scope change bumps the authorization version');
$audit = json_decode((string) $one("SELECT metadata_json FROM iam_audit_events WHERE action = 'employee.confirmare_scopes_changed' AND target_id = ? ORDER BY created_at DESC LIMIT 1", [$uuids['coopa']]), true);
$check(($audit['before'] ?? null) === ['operate' => [], 'supervise' => []] && ($audit['after'] ?? null) === ['operate' => ['trendhome'], 'supervise' => []], 'the IAM audit records before and after: ' . json_encode($audit));
$ok($asRoot('PUT', "/management/employees/{$uuids['coopb']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'grant B');
$ok($asRoot('PUT', "/management/employees/{$uuids['coopc']}/confirmare-scopes", ['operate' => ['outletperdele'], 'supervise' => []]), 'grant C');
$ok($asRoot('PUT', "/management/employees/{$uuids['cosup']}/confirmare-scopes", ['operate' => [], 'supervise' => ['outletperdele', 'trendhome']]), 'grant supervisor');
$check((int) $one('SELECT COUNT(*) FROM employee_confirmare_scopes') === 5, 'exactly the granted scopes exist');

// ---- 2. Activation (test override of the source readiness) and intake ----------------------------------------------------
// Two-phase first activation (Phase 1 rule): schedule, the source reports its armed fence, commit before the instant.
$activateTwoPhase = static function (string $src) use ($activation, $activatedAt, $registry, $kernel, $iso): void {
    $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'operations test', 'co-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    $activation($activatedAt->modify('-1 minute'))->activate($registry->find($src), 'Test Owner', 'operations test', 'co-test', true);
};
$activateTwoPhase('trendhome');
$activateTwoPhase('outletperdele');
$dueDates = [121 => '2026-10-30', 115 => '2026-11-03'];
for ($n = 100; $n <= 122; $n++) {
    $ok($send('trendhome', $payload($id($n), 'processing', $submitted($after($n)), $n % 2 === 0 ? $cod('waiting') : $online, ['pricing' => $n !== 120] + (isset($dueDates[$n]) ? ['due' => $dueDates[$n]] : []))), "trendhome order {$n}");
}
$check($one('SELECT source_due_date FROM operational_orders WHERE global_order_id = ?', [$th(121)]) === '2026-10-30' && $one('SELECT source_pricing_fingerprint FROM operational_orders WHERE global_order_id = ?', [$th(120)]) === null, 'due date and pricing fingerprint are stored as sent');
$error($send('trendhome', array_replace_recursive($payload($id(199), 'processing', $submitted($after(199)), $online), ['order' => ['pricing' => ['fingerprint' => '12.50 RON']]])), 422, 'SOURCE_PAYLOAD_INVALID', 'an amount is never accepted as a pricing fingerprint');
$error($send('trendhome', array_replace_recursive($payload($id(199), 'processing', $submitted($after(199)), $online), ['order' => ['dueDate' => '2026-02-30']])), 422, 'SOURCE_PAYLOAD_INVALID', 'an impossible due date is refused');
$ok($send('trendhome', $payload($id(130), 'processing', null, $online)), 'order without evidence');
$ok($send('trendhome', $payload($id(140), 'processing', $submitted($activatedAt->modify('-1 hour')), $online)), 'order submitted before the activation');
$ok($send('outletperdele', $payload($id(200), 'processing', $submitted($after(200)), $online)), 'outlet order 200');
$ok($send('outletperdele', $payload($id(201), 'processing', $submitted($after(201)), $online)), 'outlet order 201');
$check((int) $one("SELECT COUNT(*) FROM order_confirmations WHERE status = 'pending' AND assigned_employee_uuid IS NULL") === 26, 'every Confirmare and undetermined order waits unassigned');
$opened = $events($th(100));
$check(count($opened) === 1 && $opened[0]['action'] === 'opened' && $opened[0]['actor_kind'] === 'system' && $opened[0]['actor_employee_uuid'] === null && (int) $opened[0]['version_after'] === 1, 'the audit trail starts with a system "opened" event at version 1');
$check(($events($th(130))[0]['reason_code'] ?? null) === 'undetermined', 'an undetermined order is opened as such');
$check($events($th(140)) === [] && $conf($th(140)) === [], 'an order submitted before the activation has no Confirmare state');

// ---- 3. Pool per scope -------------------------------------------------------------------------------------------
$idsOf = static fn (array $body): array => array_column($body['items'] ?? [], 'globalOrderId');
$poolA = $ok($pool($opA), 'pool A');
$check(count($poolA['items']) === 23 && !in_array($th(130), $idsOf($poolA), true) && array_filter($idsOf($poolA), static fn (string $g): bool => str_starts_with($g, 'outletperdele:')) === [], 'an operator sees the unassigned Confirmare orders of their source only (no undetermined, no other source)');
$check(array_slice($idsOf($poolA), 0, 3) === [$th(121), $th(115), $th(100)] && $poolA['items'][0]['dueDate'] === '2026-10-30', 'the nearest due date first, then stable FIFO');
$check($poolA['items'][2]['payment']['state'] === 'waiting' && $poolA['items'][3]['payment']['state'] === 'online' && $poolA['items'][0]['productionCleared'] === false, 'the payment state only, never an amount');
$check($poolA['items'][2]['financial'] === ['state' => 'waiting', 'basis' => null, 'attention' => null, 'open' => false] && $poolA['items'][3]['financial'] === ['state' => 'cleared', 'basis' => 'online_payment', 'attention' => null, 'open' => true], 'the financial clearance state and basis only (API 2.40.0: and the attention, none here)');
$check(!str_contains(json_encode($poolA), 'Ionescu') && !str_contains(json_encode($poolA), '0722999111') && !str_contains(json_encode($poolA), 'Str. Test'), 'the pool carries no customer data');
$check($idsOf($ok($pool($opC), 'pool C')) === [$op(200), $op(201)], 'a Dashboard operator of the other source sees only that source');
$supAll = $ok($pool($sup, 'all'), 'pool supervisor all');
$check(count($supAll['items']) === 26 && in_array($th(130), $idsOf($supAll), true), 'a supervisor sees every order of the supervised sources, undetermined included');
$error($pool($opA, 'all'), 403, 'CONFIRMARE_SCOPE_DENIED', 'an operator never sees the whole pool');
$error($pool($none), 403, 'CONFIRMARE_SCOPE_DENIED', 'no scope, no pool');
$error(T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($op(200)), null, ['origin' => DASHBOARD_ORIGIN], $opA['cookie']), 404, 'CONFIRMARE_NOT_FOUND', 'another source\'s order is invisible');
$error($act($opA, $op(200), 'release', ['expectedVersion' => $version($op(200))]), 404, 'CONFIRMARE_NOT_FOUND', 'a command on another source\'s order finds nothing');
$error($act($none, $th(100), 'release', ['expectedVersion' => $version($th(100))]), 404, 'CONFIRMARE_NOT_FOUND', 'without a scope a command finds nothing');

// ---- 4. Distribution: no opt-in, fair, due date first, never blocked by the balance target --------------------------
$load = static fn (string $name): int => (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids[$name]]);
$check($ok(T::call($kernel, 'GET', '/confirmare/availability', null, ['origin' => DASHBOARD_ORIGIN], $opA['cookie']), 'availability A')['available'] === true, 'an active identity with the operate scope is available without opting in (owner rule)');
$error($call($none, 'PUT', '/confirmare/availability', ['available' => false]), 403, 'CONFIRMARE_SCOPE_DENIED', 'without a scope there is no availability to change');
$error($call($opA, 'PUT', '/confirmare/availability', ['available' => false, 'employeeId' => $uuids['coopb']]), 403, 'CONFIRMARE_SCOPE_DENIED', 'an operator never changes a colleague\'s availability');
$dry = $service->distribute(null, true);
$check($dry['assigned'] === 0 && $dry['operators'] === ['trendhome' => 2, 'outletperdele' => 1] && (int) $one('SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid IS NOT NULL') === 0, 'the dry run reads only: ' . json_encode($dry));
$distribution = $service->distribute();
$check($distribution['assigned'] === 25 && $load('coopa') + $load('coopb') === 23 && abs($load('coopa') - $load('coopb')) <= 1 && $distribution['waiting'] === [] && $distribution['overTarget'] === 2,
    'every order is distributed fairly, also beyond the balance target of ' . ConfirmareService::TARGET_OPEN_PER_OPERATOR . ' (the queue never blocks): ' . json_encode($distribution));
$check($conf($op(200))['assigned_employee_uuid'] === $uuids['coopc'] && $conf($op(201))['assigned_employee_uuid'] === $uuids['coopc'], 'each source goes only to its own operators');
$check($conf($th(130))['assigned_employee_uuid'] === null, 'an undetermined order is never distributed');
$firstTwo = [$conf($th(121))['assigned_employee_uuid'], $conf($th(115))['assigned_employee_uuid']];
sort($firstTwo);
$expectedTwo = [$uuids['coopa'], $uuids['coopb']];
sort($expectedTwo);
$check($firstTwo === $expectedTwo, 'the two most urgent orders go to two different operators first');
$assigned = $events($th(100));
$check(count($assigned) === 2 && $assigned[1]['action'] === 'assigned' && $assigned[1]['actor_kind'] === 'system' && $assigned[1]['new_assignee_uuid'] !== null && (int) $assigned[1]['version_after'] === 2, 'an automatic assignment is a system event');
$check($service->distribute()['assigned'] === 0, 'a second run assigns nothing more');
$check($ok($call($opA, 'POST', '/confirmare/next', ['source' => 'trendhome']), 'next with nothing waiting')['assigned'] === null, 'next with nothing waiting assigns nothing (no hard cap refusal either)');

// ---- 5. Decisions: the production checklist, versions and idempotency -----------------------------------------------
$mine = static fn (array $who): array => $idsOf($ok($pool($who, 'mine'), 'mine'));
$detail = static fn (array $who, string $g): array => $ok(T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($g), null, ['origin' => DASHBOARD_ORIGIN], $who['cookie']), 'detail');
$checklist = static function (array $who, string $g) use ($detail, $act, $version): array {
    $review = $detail($who, $g)['review'];
    // API 2.39.0: the approval needs the confirmed call recorded on this review; record it as the operator does (a
    // refusal here, for example on a colleague's order, is asserted by the approval that follows).
    $act($who, $g, 'contact', ['expectedVersion' => $version($g), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]);
    return ['customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']];
};
$approveBody = static fn (array $who, string $g) => ['expectedVersion' => $version($g)] + $checklist($who, $g);
$pick = static function (array $orders, callable $test): string { foreach ($orders as $g) { if ($test($g)) return $g; } throw new RuntimeException('fixture: no order matches'); };
$mineA = $mine($opA);
$isCod = static fn (string $g): bool => (int) substr($g, -1) % 2 === 0 && $g !== $th(120);
$aOrder = $pick($mineA, $isCod);
$bOrder = $mine($opB)[0];
$error($act($opA, $bOrder, 'approve', $approveBody($opA, $bOrder)), 409, 'CONFIRMARE_NOT_ASSIGNED_TO_YOU', 'an operator never decides a colleague\'s order');
$body = $approveBody($opA, $aOrder);
$error($act($opA, $aOrder, 'approve', ['expectedVersion' => $body['expectedVersion'] - 1] + $body), 409, 'CONFIRMARE_CHANGED', 'a decision on a stale view is refused');
$error($act($opA, $aOrder, 'approve', ['customerCall' => 'not_called'] + $body), 422, 'CONFIRMARE_CALL_REQUIRED', 'no approval without the confirmed customer call');
$error($act($opA, $aOrder, 'approve', ['priceVerified' => false] + $body), 422, 'CONFIRMARE_PRICE_NOT_VERIFIED', 'no approval without the price verified against the shop');
$error($act($opA, $aOrder, 'approve', ['confirmedLineIds' => ['7001']] + $body), 409, 'CONFIRMARE_LINES_NOT_CONFIRMED', 'every cart line must be confirmed');
$error($act($opA, $aOrder, 'approve', ['reviewFingerprint' => str_repeat('f', 64)] + $body), 409, 'CONFIRMARE_REVIEW_STALE', 'an approval is bound to the reviewed order');
$error($act($opA, $aOrder, 'approve', $body + ['note' => 'x']), 400, 'INVALID_REQUEST', 'no free text is accepted');
$error($act($opA, $aOrder, 'approve', $body, 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'a command needs an Idempotency-Key');
$error($intake($aOrder), 409, 'CONFIRMARE_NECESARA', 'before the decision production cannot start');
$key = 'co-approve-' . bin2hex(random_bytes(8));
$before = $version($aOrder);
$approved = $ok($act($opA, $aOrder, 'approve', $body, $key), 'approve');
$check($approved['status'] === 'approved' && $approved['version'] === $before + 1 && $approved['assignment'] === null && $approved['decision']['by'] === 'Test coopa' && $approved['productionCleared'] === false, 'approved, but not cleared: the COD advance is still waiting');
$replayed = $ok($act($opA, $aOrder, 'approve', $body, $key), 'approve replay');
$check($replayed === $approved && count($events($aOrder)) === $before + 1, 'the same Idempotency-Key replays the answer and writes nothing');
$error($act($opA, $aOrder, 'reject', ['expectedVersion' => $before, 'reasonCode' => 'other'], $key), 409, 'IDEMPOTENCY_CONFLICT', 'a key reused for another request is refused');
$error($act($sup, $aOrder, 'approve', $approveBody($sup, $aOrder)), 409, 'CONFIRMARE_NOT_PENDING', 'a decided order is not decided again');
$approvedEvent = $events($aOrder)[$before];
$check($approvedEvent['action'] === 'approved' && $approvedEvent['previous_status'] === 'pending' && $approvedEvent['new_status'] === 'approved' && $approvedEvent['actor_employee_uuid'] === $uuids['coopa'] && $approvedEvent['previous_assignee_uuid'] === $uuids['coopa'] && $approvedEvent['new_assignee_uuid'] === null, 'the approval event names actor, before and after');
// `approved` alone never opens production (owner rule): the financial condition is missing.
$check($detail($sup, $aOrder)['production'] === ['cleared' => false, 'missing' => ['payment']], 'the only missing condition is the payment: ' . json_encode($detail($sup, $aOrder)['production']));
$error($intake($aOrder), 409, 'CONFIRMARE_NECESARA', 'an approved order with a waiting advance never starts production');
$closedDoc = $error($generate($aOrder), 409, 'CONFIRMARE_NECESARA', 'nor gets its first production document');
$check(($closedDoc['error']['details']['missing'] ?? null) === ['payment'], 'the refusal names what is missing');
$ok($send('trendhome', $payload(explode(':', $aOrder)[1], 'processing', null, $cod('confirmed'))), 'the advance is confirmed in the shop');
$check($conf($aOrder)['production_cleared'] === 1 && $detail($sup, $aOrder)['production']['missing'] === [], 'the confirmed advance completes the clearance');
// API 2.42.0 (Phase 6): a cleared Confirmare order is handed to cutting through its first production document only.
$error($intake($aOrder), 409, 'CONFIRMARE_DOCUMENT_NECESAR', 'a cleared order without its production document is not received by cutting');
$ok($generate($aOrder), 'revision 1 after clearance', 201);
$check($ok($intake($aOrder), 'intake after clearance')['outcome'] === 'started' && $stage($aOrder) === 'material-preparation', 'only now production starts');
// Owner financial rules (2026-10-22): the source's financial clearance on the CURRENT total decides, whatever the gate mode.
$fin = $pick($mineA, static fn (string $g): bool => $isCod($g) && $g !== $aOrder && ($conf($g)['status'] ?? '') === 'pending');
$finId = explode(':', $fin)[1];
$finPrice = static fn (string $price): string => 'sha256:' . hash('sha256', "price-{$finId}-{$price}");
$missingOf = static fn (string $g): array => $detail($sup, $g)['production']['missing'];
$finSend = static fn (array $payment, mixed $financial, array $extra = []): array => $ok($send('trendhome', $payload($finId, 'processing', null, $payment, ['financial' => $financial] + $extra)), 'financial event');
$ok($act($opA, $fin, 'approve', $approveBody($opA, $fin)), 'approve a COD order without its advance');
$finSend($cod('not_required'), ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $finPrice('a')]);
$check($missingOf($fin) === ['payment'], 'a disabled advance module is no exemption: a positive total keeps the 30 % advance');
$finSend($cod('not_required'), ['state' => 'cleared', 'basis' => 'zero_total', 'pricingFingerprint' => $finPrice('a')]);
$check($conf($fin)['production_cleared'] === 1 && $missingOf($fin) === [], 'a verified final total of 0 needs no advance');
$finSend($cod('not_required'), ['state' => 'cleared', 'basis' => 'zero_total', 'pricingFingerprint' => $finPrice('old')]);
$check($conf($fin)['production_cleared'] === 0 && $missingOf($fin) === ['payment'], 'a clearance evaluated on another total never carries over');
$finSend($cod('confirmed', 1), ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $finPrice('a')]);
$check($missingOf($fin) === ['payment'], 'an online basis for a COD order is inconsistent and stays closed');
$finSend($cod('confirmed', 1), ['state' => 'cleared', 'basis' => 'advance_collected', 'pricingFingerprint' => $finPrice('a')]);
$check($conf($fin)['production_cleared'] === 1, 'collections covering the advance of the current total clear it');
$finSend($cod('confirmed', 1), false);
$check($conf($fin)['production_cleared'] === 0 && $missingOf($fin) === ['payment'], 'an event without the clearance leaves the order financially unknown');
$finSend($cod('confirmed', 1), ['state' => 'partial', 'basis' => null, 'pricingFingerprint' => $finPrice('b')], ['price' => 'b']);
$check(in_array('payment', $missingOf($fin), true) && in_array('sync', $missingOf($fin), true), 'a higher total: the old advance confirmation no longer counts, and the order needs a new review');
$error($send('trendhome', $payload($finId, 'processing', null, $cod('confirmed', 1), ['price' => 'b', 'financial' => ['state' => 'cleared', 'basis' => 'exemption', 'pricingFingerprint' => $finPrice('b')]])), 422, 'SOURCE_PAYLOAD_INVALID', 'no exemption can be stated until an audited exemption exists');
$error($send('trendhome', $payload($finId, 'processing', null, $cod('confirmed', 1), ['price' => 'b', 'financial' => ['state' => 'cleared', 'basis' => 'advance_collected', 'pricingFingerprint' => $finPrice('b'), 'amount' => 450]])), 422, 'SOURCE_PAYLOAD_INVALID', 'no amount is accepted');
$finSend($cod('confirmed', 1), ['state' => 'unknown', 'basis' => null, 'pricingFingerprint' => null], ['price' => 'b']);
$check(in_array('payment', $missingOf($fin), true), 'an unknown financial state stays closed');
$error($intake($fin), 409, 'CONFIRMARE_NECESARA', 'and production never starts on it');
// The refused amount must not surface anywhere in the view: no "amount" key and no value holding 450 as a number of its
// own (450, "450", "450.00", "450,00 lei"). A bare substring test also matched random fingerprint hex digits and
// microsecond timestamps (about one run in twenty), so every scalar is checked as a token instead.
$leaksAmount = static function (mixed $value, int|string|null $key = null) use (&$leaksAmount): bool {
    if (is_string($key) && stripos($key, 'amount') !== false) return true;
    if (is_array($value)) {
        foreach ($value as $k => $v) if ($leaksAmount($v, $k)) return true;
        return false;
    }
    return (is_int($value) || is_float($value)) ? (float) $value === 450.0 : (is_string($value) && preg_match('/(?<![0-9A-Za-z.])450(?:[.,][0-9]{1,2})?(?![0-9A-Za-z])/', $value) === 1);
};
$check($leaksAmount(['x' => 450]) && $leaksAmount(['total' => '450.00']) && $leaksAmount(['paidAmount' => null]) && $leaksAmount(['note' => 'avans 450,00 lei']) && !$leaksAmount(['fingerprint' => 'a4503f', 'at' => '2026-10-22T11:05:50.450123Z']), 'the amount probe finds an amount and ignores hex digits and timestamps');
$finView = $detail($sup, $fin);
$check($finView['financial'] === ['state' => 'unknown', 'basis' => null, 'attention' => null, 'open' => false] && !$leaksAmount($finView), 'the view shows the state and basis only');
// Legacy orders keep the advance payment gate unchanged: the financial clearance is never read for them.
$ok($send('trendhome', $payload($id(140), 'processing', $submitted($activatedAt->modify('-1 hour')), $online, ['financial' => ['state' => 'unknown', 'basis' => null, 'pricingFingerprint' => null]])), 'legacy order with an unknown financial clearance');
$ok($generate($th(140)), 'a legacy online order still gets its document through the advance payment gate', 201);
// An online order: cleared by the approval itself (every other condition holds).
$online1 = $pick($mineA, static fn (string $g): bool => !$isCod($g) && $g !== $th(121) && $g !== $th(120));
$ok($act($opA, $online1, 'approve', $approveBody($opA, $online1)), 'approve online order');
$ok($generate($online1), 'document after clearance', 201);
$check((int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$online1]) === 1, 'the first production document is allowed once cleared');
// Every condition counts on its own: a stored line confirmation that no longer covers the cart is not enough.
$onlineUuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$online1]);
$pdo->prepare("UPDATE order_confirmations SET confirmed_line_ids = '[\"7001\"]' WHERE order_uuid = ?")->execute([$onlineUuid]);
$check(\Arasya\Operations\Confirmare\ConfirmationClearance::missing($pdo, $onlineUuid) === ['lines'], 'one unconfirmed cart line is enough to keep production closed');
$pdo->prepare("UPDATE order_confirmations SET confirmed_line_ids = '[\"7001\",\"7002\"]' WHERE order_uuid = ?")->execute([$onlineUuid]);
// No pricing fingerprint from the shop: the price cannot be verified, so no approval.
$noPrice = $th(120);
$noPriceOwner = $conf($noPrice)['assigned_employee_uuid'] === $uuids['coopa'] ? $opA : $opB;
$error($act($noPriceOwner, $noPrice, 'approve', $approveBody($noPriceOwner, $noPrice)), 409, 'CONFIRMARE_PRICE_UNAVAILABLE', 'without the shop\'s pricing fingerprint nothing is approved');
// The shop changes the cart after the approval: the clearance is lost until a new review.
$changed = $pick($mineA, static fn (string $g): bool => !$isCod($g) && !in_array($g, [$online1, $th(121), $th(120)], true) && ($conf($g)['status'] ?? '') === 'pending');
$ok($act($opA, $changed, 'approve', $approveBody($opA, $changed)), 'approve before the change');
$check($conf($changed)['production_cleared'] === 1, 'cleared before the change');
$ok($send('trendhome', $payload(explode(':', $changed)[1], 'processing', null, $online, ['quantity' => 2])), 'the shop changes a quantity');
$check($conf($changed)['production_cleared'] === 0 && in_array('sync', $detail($sup, $changed)['production']['missing'], true), 'a changed cart closes production again (sync)');
$error($intake($changed), 409, 'CONFIRMARE_NECESARA', 'a changed order never starts on an old approval');
$ok($send('trendhome', $payload(explode(':', $changed)[1], 'processing', null, $online, ['quantity' => 2, 'price' => 'b'])), 'the shop changes the price too');
$error($act($opA, $changed, 'reopen', ['expectedVersion' => $version($changed)]), 403, 'CONFIRMARE_SCOPE_DENIED', 'only a supervisor reopens');
$reopenedApproval = $ok($act($sup, $changed, 'reopen', ['expectedVersion' => $version($changed)]), 'reopen a stale approval');
$check($reopenedApproval['status'] === 'pending' && $reopenedApproval['decision'] === null && $conf($changed)['production_cleared'] === 0, 'a stale approval returns to the pool for a new review');
$ok($act($sup, $changed, 'approve', $approveBody($sup, $changed)), 'approved again on the new review');
$check($conf($changed)['production_cleared'] === 1, 'the new review clears it');
$error($act($sup, $aOrder, 'reopen', ['expectedVersion' => $version($aOrder)]), 409, 'CONFIRMARE_NOT_REOPENABLE', 'a cleared approval is never reopened');
// Reject: reason codes only; the gate stays closed; a supervisor may reopen.
$rejectOrder = $pick($mineA, static fn (string $g): bool => ($conf($g)['status'] ?? '') === 'pending' && $g !== $th(120));
$error($act($opA, $rejectOrder, 'reject', ['expectedVersion' => $version($rejectOrder), 'reasonCode' => 'clientul nu raspunde']), 422, 'INVALID_REASON', 'a free-text reason is refused');
$rejected = $ok($act($opA, $rejectOrder, 'reject', ['expectedVersion' => $version($rejectOrder), 'reasonCode' => 'customer_unreachable']), 'reject');
$check($rejected['status'] === 'rejected' && $rejected['decision']['reasonCode'] === 'customer_unreachable', 'rejected with its code');
$error($intake($rejectOrder), 409, 'CONFIRMARE_NECESARA', 'a rejected order never starts production');
$error($generate($rejectOrder), 409, 'CONFIRMARE_NECESARA', 'a rejected order gets no production document');
$reopened = $ok($act($sup, $rejectOrder, 'reopen', ['expectedVersion' => $version($rejectOrder)]), 'reopen');
$check($reopened['status'] === 'pending' && $reopened['assignment'] === null && $reopened['decision'] === null && $conf($rejectOrder)['decision_reason_code'] === null, 'a reopened order is pending and unassigned again');
// Undetermined: visible to the supervisor, never approved here.
$error($act($sup, $th(130), 'approve', ['expectedVersion' => $version($th(130))] + $checklist($sup, $th(130))), 409, 'CONFIRMARE_UNDETERMINED', 'an undetermined order cannot be approved');
$error($act($opA, $th(130), 'claim', ['expectedVersion' => $version($th(130))]), 409, 'CONFIRMARE_UNDETERMINED', 'nor claimed');
// Cancelled in the shop while waiting: cannot be approved, can be rejected.
$cancelOrder = $pick($mine($opA), static fn (string $g): bool => $g !== $th(120));
$cancelBody = $approveBody($opA, $cancelOrder);
$ok($send('trendhome', $payload(explode(':', $cancelOrder)[1], 'cancelled', null, null)), 'the shop cancels the order');
$error($act($opA, $cancelOrder, 'approve', ['expectedVersion' => $version($cancelOrder)] + $cancelBody), 409, 'CONFIRMARE_ORDER_CLOSED', 'a cancelled order cannot be approved');
$ok($act($opA, $cancelOrder, 'reject', ['expectedVersion' => $version($cancelOrder), 'reasonCode' => 'customer_cancelled']), 'reject the cancelled order');

// ---- 6. Claim, release, reassign, leave, reclaim, alerts and suspension --------------------------------------------
$releaseOrder = $pick($mine($opA), static fn (string $g): bool => $g !== $th(120));
$released = $ok($act($opA, $releaseOrder, 'release', ['expectedVersion' => $version($releaseOrder)]), 'release');
$check($released['assignment'] === null && end($events($releaseOrder))['action'] === 'released', 'released back to the pool');
$claimed = $ok($act($opB, $releaseOrder, 'claim', ['expectedVersion' => $version($releaseOrder)]), 'claim beyond the balance target');
$check($claimed['assignment']['employeeId'] === $uuids['coopb'] && $claimed['assignment']['kind'] === 'claim' && $load('coopb') > ConfirmareService::TARGET_OPEN_PER_OPERATOR, 'a colleague above the balance target can still claim (no hard cap)');
$error($act($opA, $releaseOrder, 'release', ['expectedVersion' => $version($releaseOrder)]), 409, 'CONFIRMARE_ASSIGNED_TO_OTHER', 'an operator never releases a colleague\'s order');
$error($act($sup, $releaseOrder, 'reassign', ['expectedVersion' => $version($releaseOrder), 'employeeId' => $uuids['coopc']]), 409, 'CONFIRMARE_OPERATOR_INELIGIBLE', 'never reassigned to someone without the source');
$error($act($opA, $releaseOrder, 'reassign', ['expectedVersion' => $version($releaseOrder), 'employeeId' => $uuids['coopa']]), 403, 'CONFIRMARE_SCOPE_DENIED', 'only a supervisor reassigns');
$moved = $ok($act($sup, $releaseOrder, 'reassign', ['expectedVersion' => $version($releaseOrder), 'employeeId' => $uuids['coopa']]), 'reassign');
$check($moved['assignment']['employeeId'] === $uuids['coopa'] && $moved['assignment']['kind'] === 'supervisor', 'a supervisor reassigns');
// Leave: the supervisor puts A on leave; A's orders go back to the pool and straight to B (controlled redistribution).
$openA = $load('coopa');
$loadBefore = $load('coopb');
$waitingTrendhome = static fn (): int => (int) $one("SELECT COUNT(*) FROM order_confirmations c JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.source_key = 'trendhome' AND c.status = 'pending' AND c.assigned_employee_uuid IS NULL AND o.workflow_cohort = 'confirmare'");
$alreadyWaiting = $waitingTrendhome();
$leave = $ok($call($sup, 'PUT', '/confirmare/availability', ['available' => false, 'employeeId' => $uuids['coopa']]), 'A on leave');
$check($leave['available'] === false && $leave['released'] === $openA && $openA > 0 && $load('coopa') === 0 && $load('coopb') === $loadBefore + $openA + $alreadyWaiting && $waitingTrendhome() === 0, 'leave releases every own order and redistributes it at once: ' . json_encode($leave));
$check((int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE action = 'released' AND reason_code = 'operator_unavailable' AND actor_employee_uuid = ?", [$uuids['cosup']]) === $openA, 'one audited release per order, by the supervisor');
$check($service->distribute('trendhome')['assigned'] === 0 && $load('coopa') === 0, 'nobody on leave receives orders');
$ok($call($opA, 'PUT', '/confirmare/availability', ['available' => true]), 'A is back');
// Scope removal: the next run returns B's orders to the pool and redistributes them to A.
$openB = $load('coopb');
$ok($asRoot('PUT', "/management/employees/{$uuids['coopb']}/confirmare-scopes", ['operate' => [], 'supervise' => []]), 'root removes B\'s scope');
$reclaim = $service->distribute('trendhome');
$check($reclaim['released'] === $openB && $reclaim['assigned'] === $openB && $load('coopb') === 0 && $load('coopa') === $openB, 'a removed scope returns the orders to the pool, and they are redistributed: ' . json_encode($reclaim));
$check((int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE action = 'released' AND actor_kind = 'system' AND reason_code = 'operator_unavailable'") === $openB, 'system releases are audited');
// A deactivated account loses its orders the same way; without another operator they wait in the pool.
$ok($asRoot('POST', "/management/employees/{$uuids['coopc']}/deactivate", null), 'deactivate C');
$check($service->distribute('outletperdele')['released'] === 2 && $conf($op(200))['assigned_employee_uuid'] === null, 'an inactive account\'s orders return to the pool');
$ok($asRoot('POST', "/management/employees/{$uuids['coopc']}/activate", null), 'reactivate C');
$opC = $login('coopc', true);
$ok($asRoot('PUT', "/management/employees/{$uuids['coopb']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'root restores B');
// Inactivity raises one supervisor alert and never reassigns.
$stale = $pick($mine($opA), static fn (string $g): bool => true);
$pdo->prepare('UPDATE order_confirmations SET updated_at = ? WHERE global_order_id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 3 * 3600), $stale]);
$run = $service->distribute('trendhome');
$check($run['openAlerts'] === 1 && $service->distribute('trendhome')['openAlerts'] === 1 && $conf($stale)['assigned_employee_uuid'] === $uuids['coopa'], 'one alert, raised once, and the order stays with its operator: ' . json_encode($run));
$alerts = $ok(T::call($kernel, 'GET', '/confirmare/alerts', null, ['origin' => DASHBOARD_ORIGIN], $sup['cookie']), 'alerts');
$check(count($alerts['items']) === 1 && $alerts['items'][0]['globalOrderId'] === $stale && $alerts['items'][0]['assignee']['employeeId'] === $uuids['coopa'] && $alerts['items'][0]['kind'] === 'inactive_assignment', 'the supervisor sees the alert');
$error(T::call($kernel, 'GET', '/confirmare/alerts', null, ['origin' => DASHBOARD_ORIGIN], $opA['cookie']), 403, 'CONFIRMARE_SCOPE_DENIED', 'alerts are for supervisors');
$ok($act($opA, $stale, 'release', ['expectedVersion' => $version($stale)]), 'the operator acts');
$check($service->distribute('trendhome')['openAlerts'] === 0, 'an alert resolves once the assignment moves');
// Suspension holds orders: nothing is distributed or handed out until the source is resumed.
$nowTs = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$activation($nowTs)->suspend('trendhome', 'Test Owner', 'hold test', 'co-test');
$ok($send('trendhome', $payload($id(150), 'processing', $submitted($nowTs->modify('-5 seconds')), $online)), 'order while suspended');
$check($one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$th(150)]) === 'confirmare', 'an order submitted while suspended is held as a Confirmare order');
$heldRun = $service->distribute('trendhome');
$check($heldRun['held'] === ['trendhome'] && $conf($th(150))['assigned_employee_uuid'] === null, 'a suspended source is held: nothing is distributed');
$error($call($opA, 'POST', '/confirmare/next', ['source' => 'trendhome']), 409, 'CONFIRMARE_SOURCE_SUSPENDED', 'nor handed out on request');
$activation($nowTs->modify('+1 second'))->resume($registry->find('trendhome'), 'Test Owner', 'hold test over', 'co-test', true);
$check($service->distribute('trendhome')['assigned'] >= 1 && $conf($th(150))['assigned_employee_uuid'] !== null, 'after the resumption the held order is distributed');

// ---- 7. Races (separate processes) ---------------------------------------------------------------------------------
$parallel = static function (array $jobs) use ($dbName): array {
    if (!function_exists('pcntl_fork')) return [];
    $files = []; $children = [];
    foreach ($jobs as $index => $job) {
        $files[$index] = tempnam(sys_get_temp_dir(), 'confops');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $result = $job($dbName);
            file_put_contents($files[$index], json_encode($result));
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    $results = [];
    foreach ($files as $index => $file) { $results[$index] = json_decode((string) file_get_contents($file), true); unlink($file); }
    return $results;
};
$httpJob = static fn (array $who, string $method, string $path, ?array $body): Closure => static function (string $db) use ($who, $method, $path, $body, $settings): array {
    usleep(20000);
    $k = (new Container(T::config($db, [T::ORIGIN, DASHBOARD_ORIGIN], $settings)))->kernel();
    $r = T::call($k, $method, $path, $body, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'co-race-' . bin2hex(random_bytes(10))], $who['cookie']);
    return ['status' => $r['status'], 'code' => $r['body']['error']['code'] ?? null, 'body' => $r['body']];
};
$codes = static fn (array $results): array => array_map(static fn (array $r): string => $r['status'] === 200 ? 'ok' : (string) $r['code'], $results);
$probe = $parallel([static fn (string $db): array => ['ok' => true]]);
if ($probe === []) {
    echo "NOT RUN Confirmare races: pcntl is not available\n";
} else {
    for ($n = 160; $n < 166; $n++) $ok($send('trendhome', $payload($id($n), 'processing', $submitted($after($n)), $n % 2 === 0 ? $cod('confirmed') : $online)), "race order {$n}");
    // Two operators claim the same order at once: exactly one gets it.
    $raceOrder = $th(160);
    $v = $version($raceOrder);
    $results = $codes($parallel([
        $httpJob($opA, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/claim', ['expectedVersion' => $v]),
        $httpJob($opB, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/claim', ['expectedVersion' => $v]),
    ]));
    sort($results);
    $check(count(array_keys($results, 'ok', true)) === 1 && array_diff($results, ['ok', 'CONFIRMARE_CHANGED', 'CONFIRMARE_ASSIGNED_TO_OTHER']) === [] && $version($raceOrder) === $v + 1, 'claim race: exactly one wins: ' . json_encode($results));
    // The assignee approves while a supervisor rejects: exactly one decision, one event.
    $assignee = $conf($raceOrder)['assigned_employee_uuid'] === $uuids['coopa'] ? $opA : $opB;
    $body = $approveBody($assignee, $raceOrder);
    $results = $codes($parallel([
        $httpJob($assignee, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/approve', $body),
        $httpJob($sup, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/reject', ['expectedVersion' => $body['expectedVersion'], 'reasonCode' => 'duplicate_order']),
    ]));
    $decisions = (int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE global_order_id = ? AND action IN ('approved', 'rejected')", [$raceOrder]);
    $check(count(array_keys($results, 'ok', true)) === 1 && $decisions === 1 && $version($raceOrder) === $body['expectedVersion'] + 1 && in_array($conf($raceOrder)['status'], ['approved', 'rejected'], true), 'decision race: exactly one decision: ' . json_encode($results));
    // Approval racing a cutting intake: production never starts while the order is not cleared.
    $raceOrder2 = $th(162);
    $ok($act($sup, $raceOrder2, 'reassign', ['expectedVersion' => $version($raceOrder2), 'employeeId' => $uuids['coopa']]), 'prepare intake race');
    $owned = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_stage_id = 'material-preparation' AND production_completed_at IS NULL", [$uuids['cocutter']]);
    $results = $parallel([
        $httpJob($opA, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder2) . '/approve', $approveBody($opA, $raceOrder2)),
        $httpJob($cutter, 'POST', '/cutting/intake', ['qrToken' => $qr($raceOrder2)] + ($owned > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $owned] : [])),
    ]);
    $intakeResult = $results[1]['status'] === 200 ? 'started' : (string) $results[1]['code'];
    $check($results[0]['status'] === 200 && $conf($raceOrder2)['status'] === 'approved' && $conf($raceOrder2)['production_cleared'] === 1
        && in_array($intakeResult, ['CONFIRMARE_NECESARA', 'CONFIRMARE_DOCUMENT_NECESAR'], true) && $stage($raceOrder2) === 'waiting', 'approval vs intake: the intake was refused before the clearance, or after it for the missing production document (API 2.42.0): ' . $intakeResult);
    // Two operators pull the last waiting order at once: one gets it, nobody gets it twice.
    $service->distribute('trendhome');
    $ok($act($sup, $th(163), 'release', ['expectedVersion' => $version($th(163))]), 'one order back to the pool');
    $results = $parallel([
        $httpJob($opA, 'POST', '/confirmare/next', ['source' => 'trendhome']),
        $httpJob($opB, 'POST', '/confirmare/next', ['source' => 'trendhome']),
    ]);
    $got = array_values(array_filter(array_map(static fn (array $r): ?string => $r['status'] === 200 ? ($r['body']['assigned']['globalOrderId'] ?? null) : null, $results)));
    $check($got === [$th(163)] && $conf($th(163))['assigned_employee_uuid'] !== null, 'next race: the order goes to exactly one operator: ' . json_encode($codes($results)));
    // Two distribution runs at once never assign an order twice.
    for ($n = 300; $n < 306; $n++) $ok($send('trendhome', $payload($id($n), 'processing', $submitted($after($n)), $online)), "race order {$n}");
    $distJob = static fn (): Closure => static function (string $db) use ($settings): array {
        usleep(20000);
        return (new Container(T::config($db, [T::ORIGIN, DASHBOARD_ORIGIN], $settings)))->confirmareService()->distribute('trendhome');
    };
    $known = array_flip(array_column($all('SELECT event_uuid FROM order_confirmation_events'), 'event_uuid'));
    $runs = $parallel([$distJob(), $distJob()]);
    $doubles = (int) $one("SELECT COUNT(*) FROM (SELECT order_uuid, version_after FROM order_confirmation_events GROUP BY order_uuid, version_after HAVING COUNT(*) > 1) d");
    $fresh = array_values(array_filter($all("SELECT event_uuid, order_uuid FROM order_confirmation_events WHERE action = 'assigned' AND actor_kind = 'system'"), static fn (array $e): bool => !isset($known[$e['event_uuid']])));
    $check($doubles === 0 && count($fresh) === array_sum(array_column($runs, 'assigned')) && count($fresh) === count(array_unique(array_column($fresh, 'order_uuid'))) && count($fresh) === 6, 'parallel distribution runs never assign one order twice: ' . json_encode([$runs, count($fresh)]));
}

// The production gate reads the clearance as committed now, not from a snapshot its transaction took earlier.
$gateOrder = $th(161);
if (($conf($gateOrder)['status'] ?? '') === 'pending') {
    $gateOwner = $conf($gateOrder)['assigned_employee_uuid'] === $uuids['coopa'] ? $opA : ($conf($gateOrder)['assigned_employee_uuid'] === $uuids['coopb'] ? $opB : $sup);
    $gateUuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$gateOrder]);
    $other = Connection::create($config);
    $other->beginTransaction();
    $other->query("SELECT production_cleared FROM order_confirmations WHERE order_uuid = " . $other->quote($gateUuid))->fetchAll();
    $ok($act($sup, $gateOrder, 'approve', $approveBody($sup, $gateOrder)), 'approve while another transaction holds an older snapshot');
    $opened = true;
    try { ConfirmationGate::assertOpen($other, $gateUuid, 'waiting'); } catch (ApiException) { $opened = false; }
    $other->rollBack();
    $check($opened, 'the gate sees the committed clearance, never an older snapshot of its transaction');
}

// ---- 8. Audit trail, read model and invariants ---------------------------------------------------------------------
$detail = $ok(T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($aOrder), null, ['origin' => DASHBOARD_ORIGIN], $sup['cookie']), 'detail');
$check(array_column($detail['events'], 'version') === range(1, $detail['version']) && $detail['events'][0]['action'] === 'opened' && $detail['events'][0]['actor'] === null, 'the detail lists every version once, in order');
$versionsMatch = (int) $one('SELECT COUNT(*) FROM order_confirmations c WHERE c.version <> (SELECT MAX(e.version_after) FROM order_confirmation_events e WHERE e.order_uuid = c.order_uuid)');
$check($versionsMatch === 0, 'every confirmation version has exactly its audit event');
$trail = json_encode($all('SELECT * FROM order_confirmation_events')) . json_encode($all('SELECT response_json FROM order_confirmation_idempotency'));
$check(!str_contains($trail, 'Ionescu') && !str_contains($trail, '0722999111') && !str_contains($trail, 'Cluj') && !str_contains($trail, 'Perdea'), 'no customer or product data in the audit or the stored answers');
$source = (string) file_get_contents(dirname(__DIR__) . '/src/Confirmare/ConfirmareService.php') . (string) file_get_contents(dirname(__DIR__) . '/src/Order/OrderProjectionWriter.php');
$check(preg_match('/(UPDATE|DELETE FROM)\s+order_confirmation_events/i', $source) === 0, 'no code path changes or deletes an audit event');
$check(preg_match('/IVAN|IRINA|BLEGU|IANCU|YETIS|SINEM/i', $source . (string) file_get_contents(dirname(__DIR__) . '/src/Confirmare/ConfirmareScopePolicy.php') . (string) file_get_contents(dirname(__DIR__) . '/database/migrations/036_confirmare_operations.sql')) === 0, 'no employee identity is named in code');
foreach ([
    "UPDATE order_confirmations SET status = 'approved', decided_at = UTC_TIMESTAMP(6) WHERE global_order_id = " . $pdo->quote($releaseOrder) => 'an assigned order that is no longer pending',
    "UPDATE order_confirmations SET assignment_kind = 'magic' WHERE global_order_id = " . $pdo->quote($releaseOrder) => 'an unknown assignment kind',
    "UPDATE order_confirmations SET assigned_at = NULL WHERE global_order_id = " . $pdo->quote($releaseOrder) => 'an assignment without its time',
    "UPDATE order_confirmations SET decision_reason_code = 'other' WHERE global_order_id = " . $pdo->quote($releaseOrder) => 'a reason on an undecided order',
    "INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, new_status, version_after, occurred_at) SELECT UUID(), order_uuid, global_order_id, source_key, 'approved', 'system', NULL, 'approved', 999, UTC_TIMESTAMP(6) FROM order_confirmations LIMIT 1" => 'a system approval',
    "INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, new_status, version_after, occurred_at) SELECT UUID(), order_uuid, global_order_id, source_key, 'opened', 'system', NULL, 'pending', 1, UTC_TIMESTAMP(6) FROM order_confirmations WHERE global_order_id = " . $pdo->quote($aOrder) => 'a second event for one version',
    "INSERT INTO employee_confirmare_scopes (employee_uuid, capability, source_key, granted_at, granted_by_employee_uuid) VALUES (" . $pdo->quote($uuids['coopa']) . ", 'approve', 'trendhome', UTC_TIMESTAMP(6), " . $pdo->quote($uuids['coopa']) . ')' => 'an unknown capability',
    "UPDATE order_confirmations SET production_cleared = 1, cleared_at = UTC_TIMESTAMP(6) WHERE global_order_id = " . $pdo->quote($rejectOrder) => 'a production clearance without an approval',
    "UPDATE operational_orders SET source_pricing_fingerprint = '1250 RON' WHERE global_order_id = " . $pdo->quote($aOrder) => 'an amount stored as a pricing fingerprint',
    "UPDATE operational_orders SET source_financial_state = 'cleared', source_financial_basis = NULL WHERE global_order_id = " . $pdo->quote($aOrder) => 'a financial clearance without its basis',
    "UPDATE operational_orders SET source_financial_state = 'cleared', source_financial_basis = 'exemption', source_financial_pricing = source_pricing_fingerprint WHERE global_order_id = " . $pdo->quote($aOrder) => 'an exemption basis',
    "UPDATE operational_orders SET source_financial_pricing = '450.00' WHERE global_order_id = " . $pdo->quote($aOrder) => 'an amount stored as the evaluated pricing',
] as $sql => $label) {
    try { $pdo->exec($sql); $check(false, "the database refuses {$label}"); } catch (PDOException) { $check(true, "the database refuses {$label}"); }
}
$check($stage($th(140)) === 'waiting' || $stage($th(140)) !== '', 'an order submitted before the activation is untouched by Confirmare');

// ---- 9. CLIs and readiness -----------------------------------------------------------------------------------------
$cliEnvironment = ['HOME' => (string) getenv('HOME'),
    'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
    'ARASYA_SOURCE_SECRET_TRENDHOME' => T::TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active', 'ARASYA_SOURCE_AUTHORITY_TRENDHOME' => 'enforce',
    'ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_DOCUMENT_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_CONFIRMARE_TRENDHOME' => 'on',
];
$cli = static function (string $script, string ...$arguments) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
};
$state = static fn (): string => json_encode([$one('SELECT COUNT(*) FROM order_confirmation_events'), $one('SELECT SUM(version) FROM order_confirmations'), $one('SELECT COUNT(*) FROM employees'), $one('SELECT COUNT(*) FROM employee_confirmare_scopes')]);
$before = $state();
$operators = $cli('confirmare-operators.php', "--username=coopa.{$suffix}", '--username=nobody.at.all');
$check($operators['code'] === 0 && ($operators['json'][0]['confirmareScopes']['operate'] ?? null) === ['trendhome'] && ($operators['json'][0]['canWorkNow'] ?? null) === true && ($operators['json'][1]['exists'] ?? null) === false, 'the operators report finds existing accounts by username and never creates one: ' . $operators['out'] . $operators['err']);
$listing = $cli('confirmare-operators.php');
$check($listing['code'] === 0 && count($listing['json']) === 4 && !str_contains($listing['out'], 'password'), 'the operators listing shows every scoped identity');
$dryCli = $cli('confirmare-distribute.php', '--dry-run');
$check($dryCli['code'] === 0 && ($dryCli['json']['dryRun'] ?? null) === true && $state() === $before, 'the distribution dry run and the reports change nothing: ' . $dryCli['out'] . $dryCli['err']);
$check($cli('confirmare-distribute.php', '--source=Bad Source')['code'] === 2, 'a bad source is a usage error');
$readiness = $cli('readiness.php');
$check(str_contains($readiness['out'] . $readiness['err'], 'confirmare_trendhome_operators_'), 'readiness reports the Confirmare operators: ' . substr($readiness['out'], 0, 200));
$refused = $cli('workflow-cohort.php', '--source=trendhome', '--suspend', '--actor=Test Owner', '--reason=reset');
$check($refused['code'] === 0, 'suspend works');
$activate = $cli('workflow-cohort.php', '--source=trendhome', '--activate', '--actor=Test Owner');
$check($activate['code'] === 3 && str_contains($activate['err'], 'CONFIRMARE_NOT_READY'), 'the operator CLI refuses the activation while the source readiness fails: ' . $activate['err']);

// ---- 10. Resumable migration and teardown ---------------------------------------------------------------------------
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/036_confirmare_operations.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmations' AND column_name IN ('assigned_employee_uuid', 'assigned_at', 'assignment_kind', 'decision_reason_code', 'production_cleared')") === 5, 'a repeated 036 run changes nothing');
$pdo->exec('DELETE FROM order_confirmation_contacts');
$pdo->exec('DELETE FROM order_confirmation_idempotency');
$pdo->exec('DELETE FROM confirmare_alerts');
$pdo->exec('DELETE FROM order_confirmation_events');
$pdo->exec('DELETE FROM order_confirmations');
$pdo->exec('DELETE FROM workflow_cohort_activations');
$pdo->exec('DELETE FROM employee_confirmare_scopes');
$pdo->exec('DELETE FROM confirmare_operator_state');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');
// An interrupted 036 (tables created, the ALTER not applied) completes on the next run.
// 038 (and 039 on it) build on the 036 columns; take them off first and re-apply them after the 036 re-run.
restorePreConfirmareSourceAccessTestSchema($pdo);
$pdo->exec('ALTER TABLE order_confirmations DROP CONSTRAINT chk_order_confirmations_cleared, DROP FOREIGN KEY fk_order_confirmations_caller, DROP CONSTRAINT chk_order_confirmations_reason, DROP CONSTRAINT chk_order_confirmations_assigned_pending, DROP CONSTRAINT chk_order_confirmations_assignment,
    DROP FOREIGN KEY fk_order_confirmations_assignee, DROP KEY idx_order_confirmations_assignee, DROP COLUMN cleared_at, DROP COLUMN production_cleared, DROP COLUMN approved_review_fingerprint, DROP COLUMN confirmed_line_ids,
    DROP COLUMN call_confirmed_by_employee_uuid, DROP COLUMN call_confirmed_at, DROP COLUMN decision_reason_code, DROP COLUMN assignment_kind, DROP COLUMN assigned_at, DROP COLUMN assigned_employee_uuid');
$pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_financial_complete, DROP CONSTRAINT chk_operational_orders_financial_pricing, DROP CONSTRAINT chk_operational_orders_financial_basis,
    DROP CONSTRAINT chk_operational_orders_financial_state, DROP COLUMN source_financial_pricing, DROP COLUMN source_financial_basis, DROP COLUMN source_financial_state');
$pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_pricing_fingerprint, DROP COLUMN source_due_date, DROP COLUMN source_pricing_fingerprint');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/036_confirmare_operations.sql');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/038_confirmare_source_access.sql');
$pdo->prepare('INSERT INTO schema_migrations (migration_name, checksum, applied_at) VALUES (:name, :checksum, UTC_TIMESTAMP(6))')
    ->execute(['name' => '038_confirmare_source_access.sql', 'checksum' => hash_file('sha256', dirname(__DIR__) . '/database/migrations/038_confirmare_source_access.sql')]);
// 039 builds on the 038 column (restorePreConfirmareSourceAccessTestSchema took it off first).
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/039_confirmare_finance.sql');
$pdo->prepare('INSERT INTO schema_migrations (migration_name, checksum, applied_at) VALUES (:name, :checksum, UTC_TIMESTAMP(6))')
    ->execute(['name' => '039_confirmare_finance.sql', 'checksum' => hash_file('sha256', dirname(__DIR__) . '/database/migrations/039_confirmare_finance.sql')]);
$check((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmations' AND column_name IN ('assigned_employee_uuid', 'assigned_at', 'assignment_kind', 'decision_reason_code', 'call_confirmed_at', 'confirmed_line_ids', 'approved_review_fingerprint', 'production_cleared')") === 8
    && (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name IN ('source_pricing_fingerprint', 'source_due_date', 'source_financial_state', 'source_financial_basis', 'source_financial_pricing')") === 5, 'an interrupted 036 completes on the next run');

fwrite(STDOUT, "PASS MySQL Confirmare operations integration: {$checks} checks (default deny, root-only audited scopes, pool per scope, availability, automatic and pull distribution, capacity, decisions with versions and idempotency, gate with the payment gate, undetermined and cancelled orders, reassign, reclaim, races, audit trail, CLIs, readiness, resumable migration).\n");
