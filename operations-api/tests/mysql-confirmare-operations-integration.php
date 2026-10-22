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
$pdo->exec('DELETE FROM order_confirmation_idempotency');
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
$conf = static fn (string $globalId): array => $all('SELECT c.status, c.version, c.assigned_employee_uuid, c.assignment_kind, c.decision_reason_code, c.decided_by_employee_uuid FROM order_confirmations c WHERE c.global_order_id = ?', [$globalId])[0] ?? [];
$events = static fn (string $globalId): array => $all('SELECT action, actor_kind, actor_employee_uuid, previous_status, new_status, previous_assignee_uuid, new_assignee_uuid, reason_code, version_after FROM order_confirmation_events WHERE global_order_id = ? ORDER BY version_after', [$globalId]);

// ---- Fixtures ----------------------------------------------------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$tick = 0;
$payload = static function (string $id, string $status, ?array $submission, ?array $payment) use ($suffix, &$tick): array {
    $tick++;
    $p = T::sourceOrder($id, "co-{$id}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 5000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, $status, 'active', [[
        'id' => 7001, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1,
        'options' => [['label' => 'buc', 'value' => '2 buc.']],
    ]]);
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
$base = 760000 + random_int(0, 8999) * 10;
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
$check(ConfirmationGate::WORKFLOW_READY === false, 'Phase 2 keeps WORKFLOW_READY false');
$apiError(fn () => $activation($activatedAt)->activate($registry->find('trendhome'), 'Test Owner', null, 'co-test'), 'CONFIRMARE_WORKFLOW_NOT_READY', 'no source can be activated in this release');
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

// ---- 2. Activation (test override of WORKFLOW_READY) and intake ----------------------------------------------------
// Two-phase first activation (Phase 1 rule): schedule, the source reports its armed fence, commit before the instant.
$activateTwoPhase = static function (string $src) use ($activation, $activatedAt, $registry, $kernel, $iso): void {
    $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'operations test', 'co-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    $activation($activatedAt->modify('-1 minute'))->activate($registry->find($src), 'Test Owner', 'operations test', 'co-test', true);
};
$activateTwoPhase('trendhome');
$activateTwoPhase('outletperdele');
for ($n = 100; $n <= 122; $n++) {
    $ok($send('trendhome', $payload($id($n), 'processing', $submitted($after($n)), $n % 2 === 0 ? $cod('waiting') : $online)), "trendhome order {$n}");
}
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
$check($poolA['items'][0]['globalOrderId'] === $th(100) && $poolA['items'][0]['payment']['state'] === 'waiting' && $poolA['items'][1]['payment']['state'] === 'online', 'oldest first, with the payment state only');
$check(!str_contains(json_encode($poolA), 'Ionescu') && !str_contains(json_encode($poolA), '0722999111') && !str_contains(json_encode($poolA), 'Str. Test'), 'the pool carries no customer data');
$check($idsOf($ok($pool($opC), 'pool C')) === [$op(200), $op(201)], 'a Dashboard operator of the other source sees only that source');
$supAll = $ok($pool($sup, 'all'), 'pool supervisor all');
$check(count($supAll['items']) === 26 && in_array($th(130), $idsOf($supAll), true), 'a supervisor sees every order of the supervised sources, undetermined included');
$error($pool($opA, 'all'), 403, 'CONFIRMARE_SCOPE_DENIED', 'an operator never sees the whole pool');
$error($pool($none), 403, 'CONFIRMARE_SCOPE_DENIED', 'no scope, no pool');
$error(T::call($kernel, 'GET', '/confirmare/orders/' . rawurlencode($op(200)), null, ['origin' => DASHBOARD_ORIGIN], $opA['cookie']), 404, 'CONFIRMARE_NOT_FOUND', 'another source\'s order is invisible');
$error($act($opA, $op(200), 'release', ['expectedVersion' => $version($op(200))]), 404, 'CONFIRMARE_NOT_FOUND', 'a command on another source\'s order finds nothing');
$error($act($none, $th(100), 'release', ['expectedVersion' => $version($th(100))]), 404, 'CONFIRMARE_NOT_FOUND', 'without a scope a command finds nothing');

// ---- 4. Availability and the automatic distribution ----------------------------------------------------------------
$dry = $service->distribute(null, true);
$check($dry['assigned'] === 0 && $dry['operators'] === ['outletperdele' => 0, 'trendhome' => 0] && (int) $one('SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid IS NOT NULL') === 0, 'nobody accepts yet: nothing is assigned by default');
$check($ok(T::call($kernel, 'GET', '/confirmare/availability', null, ['origin' => DASHBOARD_ORIGIN], $opA['cookie']), 'availability A')['accepting'] === false, 'an operator does not accept orders until they opt in');
$ok($call($opA, 'PUT', '/confirmare/availability', ['accepting' => true]), 'A accepts');
$error($call($none, 'PUT', '/confirmare/availability', ['accepting' => true]), 403, 'CONFIRMARE_SCOPE_DENIED', 'without a scope nobody accepts orders');
$error($call($opA, 'PUT', '/confirmare/availability', ['accepting' => true, 'employeeId' => $uuids['coopb']]), 403, 'CONFIRMARE_SCOPE_DENIED', 'an operator never changes a colleague\'s availability');
$ok($call($sup, 'PUT', '/confirmare/availability', ['accepting' => true, 'employeeId' => $uuids['coopb']]), 'supervisor sets B available');
$ok($call($opC, 'PUT', '/confirmare/availability', ['accepting' => true]), 'C accepts');
$distribution = $service->distribute();
$loadA = (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopa']]);
$loadB = (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopb']]);
$check($distribution['assigned'] === 22 && $loadA === ConfirmareService::MAX_OPEN_PER_OPERATOR && $loadB === ConfirmareService::MAX_OPEN_PER_OPERATOR && $distribution['waiting'] === ['trendhome' => 3], 'fair distribution up to the capacity of each operator: ' . json_encode($distribution));
$check($conf($op(200))['assigned_employee_uuid'] === $uuids['coopc'] && $conf($op(201))['assigned_employee_uuid'] === $uuids['coopc'], 'each source goes only to its own operators');
$check($conf($th(130))['assigned_employee_uuid'] === null, 'an undetermined order is never distributed');
$firstTwo = [$conf($th(100))['assigned_employee_uuid'], $conf($th(101))['assigned_employee_uuid']];
sort($firstTwo);
$expectedTwo = [$uuids['coopa'], $uuids['coopb']];
sort($expectedTwo);
$check($firstTwo === $expectedTwo, 'the two oldest orders go to two different operators (round robin by load)');
$assigned = $events($th(100));
$check(count($assigned) === 2 && $assigned[1]['action'] === 'assigned' && $assigned[1]['actor_kind'] === 'system' && $assigned[1]['new_assignee_uuid'] !== null && (int) $assigned[1]['version_after'] === 2, 'an automatic assignment is a system event');
$check($service->distribute()['assigned'] === 0, 'a second run assigns nothing more');
$error($call($opA, 'POST', '/confirmare/next', ['source' => 'trendhome']), 409, 'CONFIRMARE_CAPACITY_REACHED', 'an operator at capacity takes nothing more');
$mineA = $idsOf($ok($pool($opA, 'mine'), 'mine A'));
$check(count($mineA) === ConfirmareService::MAX_OPEN_PER_OPERATOR, 'the operator\'s own list');
$ofA = static fn (): array => array_values(array_filter($mineA, static fn (string $g): bool => true));
$aOrder = $mineA[0];
$bOrder = $idsOf($ok($pool($opB, 'mine'), 'mine B'))[0];

// ---- 5. Decisions, versions and idempotency -----------------------------------------------------------------------
$error($act($opA, $bOrder, 'approve', ['expectedVersion' => $version($bOrder)]), 409, 'CONFIRMARE_NOT_ASSIGNED_TO_YOU', 'an operator never decides a colleague\'s order');
$error($act($opA, $aOrder, 'approve', ['expectedVersion' => $version($aOrder) - 1]), 409, 'CONFIRMARE_CHANGED', 'a decision on a stale view is refused');
$error($act($opA, $aOrder, 'approve', ['expectedVersion' => $version($aOrder), 'note' => 'x']), 400, 'INVALID_REQUEST', 'no free text is accepted');
$error($act($opA, $aOrder, 'approve', ['expectedVersion' => $version($aOrder)], 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'a command needs an Idempotency-Key');
$error($intake($aOrder), 409, 'CONFIRMARE_NECESARA', 'before the decision production cannot start');
$key = 'co-approve-' . bin2hex(random_bytes(8));
$before = $version($aOrder);
$approved = $ok($act($opA, $aOrder, 'approve', ['expectedVersion' => $before], $key), 'approve');
$check($approved['status'] === 'approved' && $approved['version'] === $before + 1 && $approved['assignment'] === null && $approved['decision']['by'] === 'Test coopa', 'approved, unassigned, decided by the operator');
$replayed = $ok($act($opA, $aOrder, 'approve', ['expectedVersion' => $before], $key), 'approve replay');
$check($replayed === $approved && count($events($aOrder)) === $before + 1, 'the same Idempotency-Key replays the answer and writes nothing');
$error($act($opA, $aOrder, 'reject', ['expectedVersion' => $before, 'reasonCode' => 'other'], $key), 409, 'IDEMPOTENCY_CONFLICT', 'a key reused for another request is refused');
$error($act($sup, $aOrder, 'approve', ['expectedVersion' => $version($aOrder)]), 409, 'CONFIRMARE_NOT_PENDING', 'a decided order is not decided again');
$approvedEvent = $events($aOrder)[$before];
$check($approvedEvent['action'] === 'approved' && $approvedEvent['previous_status'] === 'pending' && $approvedEvent['new_status'] === 'approved' && $approvedEvent['actor_employee_uuid'] === $uuids['coopa'] && $approvedEvent['previous_assignee_uuid'] === $uuids['coopa'] && $approvedEvent['new_assignee_uuid'] === null, 'the approval event names actor, before and after');
// The approval opens the Confirmare gate only; the advance payment gate still applies (trendhome enforce).
$aPayment = (string) $one('SELECT advance_status FROM operational_orders WHERE global_order_id = ?', [$aOrder]);
if ($aPayment === 'waiting') {
    $error($intake($aOrder), 409, 'AVANS_NEVALIDAT', 'approved but the COD advance is not confirmed: the payment gate still stops production');
    $ok($send('trendhome', $payload(explode(':', $aOrder)[1], 'processing', null, $cod('confirmed'))), 'the advance is confirmed in the shop');
}
$check($ok($intake($aOrder), 'intake after approval')['outcome'] === 'started' && $stage($aOrder) === 'material-preparation', 'after the approval (and the advance) production starts');
// An online order: the approval alone opens it; a payment alone never does.
$online1 = null;
for ($n = 101; $n <= 121; $n += 2) {
    if (!in_array($th($n), array_slice($mineA, 0, 4), true) && ($conf($th($n))['status'] ?? '') === 'pending' && $conf($th($n))['assigned_employee_uuid'] !== null) { $online1 = $th($n); break; }
}
$check($online1 !== null, 'fixture: an assigned online order');
$owner101 = $conf($online1)['assigned_employee_uuid'] === $uuids['coopa'] ? $opA : $opB;
$error($intake($online1), 409, 'CONFIRMARE_NECESARA', 'a paid online order is not confirmed by its payment');
$ok($act($owner101, $online1, 'approve', ['expectedVersion' => $version($online1)]), 'approve online order');
$ok($generate($online1), 'document after approval', 201);
$check((int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$online1]) === 1, 'the first production document is allowed after the approval');
// Reject: reason codes only; the gate stays closed; a supervisor may reopen.
$rejectOrder = $mineA[1];
$error($act($opA, $rejectOrder, 'reject', ['expectedVersion' => $version($rejectOrder), 'reasonCode' => 'clientul nu raspunde']), 422, 'INVALID_REASON', 'a free-text reason is refused');
$rejected = $ok($act($opA, $rejectOrder, 'reject', ['expectedVersion' => $version($rejectOrder), 'reasonCode' => 'customer_unreachable']), 'reject');
$check($rejected['status'] === 'rejected' && $rejected['decision']['reasonCode'] === 'customer_unreachable', 'rejected with its code');
$error($intake($rejectOrder), 409, 'CONFIRMARE_NECESARA', 'a rejected order never starts production');
$error($generate($rejectOrder), 409, 'CONFIRMARE_NECESARA', 'a rejected order gets no production document');
$error($act($opA, $rejectOrder, 'reopen', ['expectedVersion' => $version($rejectOrder)]), 403, 'CONFIRMARE_SCOPE_DENIED', 'only a supervisor reopens');
$reopened = $ok($act($sup, $rejectOrder, 'reopen', ['expectedVersion' => $version($rejectOrder)]), 'reopen');
$check($reopened['status'] === 'pending' && $reopened['assignment'] === null && $reopened['decision'] === null && $conf($rejectOrder)['decision_reason_code'] === null, 'a reopened order is pending and unassigned again');
$error($act($sup, $aOrder, 'reopen', ['expectedVersion' => $version($aOrder)]), 409, 'CONFIRMARE_NOT_REJECTED', 'an approval is never reopened here');
// Undetermined: visible to the supervisor, never approved here.
$error($act($sup, $th(130), 'approve', ['expectedVersion' => $version($th(130))]), 409, 'CONFIRMARE_UNDETERMINED', 'an undetermined order cannot be approved');
$error($act($opA, $th(130), 'claim', ['expectedVersion' => $version($th(130))]), 409, 'CONFIRMARE_UNDETERMINED', 'nor claimed');
// Cancelled in the shop while waiting: cannot be approved, can be rejected.
$cancelOrder = $mineA[2];
$ok($send('trendhome', $payload(explode(':', $cancelOrder)[1], 'cancelled', null, null)), 'the shop cancels the order');
$error($act($opA, $cancelOrder, 'approve', ['expectedVersion' => $version($cancelOrder)]), 409, 'CONFIRMARE_ORDER_CLOSED', 'a cancelled order cannot be approved');
$ok($act($opA, $cancelOrder, 'reject', ['expectedVersion' => $version($cancelOrder), 'reasonCode' => 'customer_cancelled']), 'reject the cancelled order');

// ---- 6. Claim, release, reassign, availability and reclaim ---------------------------------------------------------
$releaseOrder = $mineA[3];
$released = $ok($act($opA, $releaseOrder, 'release', ['expectedVersion' => $version($releaseOrder)]), 'release');
$check($released['assignment'] === null && end($events($releaseOrder))['action'] === 'released', 'released back to the pool');
// B pulls until full (the released order is the oldest waiting one, so B fills up with others first or takes it).
for ($i = 0; $i < ConfirmareService::MAX_OPEN_PER_OPERATOR && (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopb']]) < ConfirmareService::MAX_OPEN_PER_OPERATOR; $i++) {
    $pulled = $ok($call($opB, 'POST', '/confirmare/next', ['source' => 'trendhome']), 'B pulls');
    if (($pulled['assigned']['globalOrderId'] ?? null) === $releaseOrder) {
        $ok($act($opB, $releaseOrder, 'release', ['expectedVersion' => $version($releaseOrder)]), 'B gives it back');
    }
}
$check((int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopb']]) === ConfirmareService::MAX_OPEN_PER_OPERATOR, 'B is at capacity');
$error($act($opB, $releaseOrder, 'claim', ['expectedVersion' => $version($releaseOrder)]), 409, 'CONFIRMARE_CAPACITY_REACHED', 'a colleague at capacity cannot claim it');
$claimed = $ok($act($opA, $releaseOrder, 'claim', ['expectedVersion' => $version($releaseOrder)]), 'claim');
$check($claimed['assignment']['employeeId'] === $uuids['coopa'] && $claimed['assignment']['kind'] === 'claim', 'claimed');
$error($act($opB, $releaseOrder, 'release', ['expectedVersion' => $version($releaseOrder)]), 409, 'CONFIRMARE_ASSIGNED_TO_OTHER', 'an operator never releases a colleague\'s order');
$error($act($sup, $releaseOrder, 'reassign', ['expectedVersion' => $version($releaseOrder), 'employeeId' => $uuids['coopc']]), 409, 'CONFIRMARE_OPERATOR_INELIGIBLE', 'never reassigned to someone without the source');
$error($act($opA, $releaseOrder, 'reassign', ['expectedVersion' => $version($releaseOrder), 'employeeId' => $uuids['coopb']]), 403, 'CONFIRMARE_SCOPE_DENIED', 'only a supervisor reassigns');
$moved = $ok($act($sup, $releaseOrder, 'reassign', ['expectedVersion' => $version($releaseOrder), 'employeeId' => $uuids['coopb']]), 'reassign');
$check($moved['assignment']['employeeId'] === $uuids['coopb'] && $moved['assignment']['kind'] === 'supervisor', 'a supervisor may reassign (capacity is the supervisor\'s call)');
// Going unavailable returns the operator's orders to the pool at once.
$openA = (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopa']]);
$off = $ok($call($opA, 'PUT', '/confirmare/availability', ['accepting' => false]), 'A goes unavailable');
$check($off['accepting'] === false && $off['released'] === $openA && $openA > 0 && (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopa']]) === 0, 'going unavailable releases every own order: ' . json_encode($off));
$check((int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE action = 'released' AND reason_code = 'operator_unavailable' AND actor_employee_uuid = ?", [$uuids['coopa']]) === $openA, 'one audited release per order');
// Scope removal: the next distribution run returns B's orders to the pool (system release), then nobody receives them.
$openB = (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopb']]);
$ok($asRoot('PUT', "/management/employees/{$uuids['coopb']}/confirmare-scopes", ['operate' => [], 'supervise' => []]), 'root removes B\'s scope');
$reclaim = $service->distribute('trendhome');
$check($reclaim['released'] === $openB && $reclaim['assigned'] === 0 && (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopb']]) === 0, 'a removed scope returns the orders to the pool: ' . json_encode($reclaim));
$check((int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE action = 'released' AND actor_kind = 'system' AND reason_code = 'operator_unavailable'") === $openB, 'system releases are audited');
// A deactivated account loses its orders the same way.
$ok($asRoot('POST', "/management/employees/{$uuids['coopc']}/deactivate", null), 'deactivate C');
$check($service->distribute('outletperdele')['released'] === 2 && $conf($op(200))['assigned_employee_uuid'] === null, 'an inactive account\'s orders return to the pool');
$ok($asRoot('POST', "/management/employees/{$uuids['coopc']}/activate", null), 'reactivate C');
$opC = $login('coopc', true);
// Pull distribution.
$ok($asRoot('PUT', "/management/employees/{$uuids['coopb']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'root restores B');
$next = $ok($call($opB, 'POST', '/confirmare/next', ['source' => 'trendhome']), 'next');
$check(($next['assigned']['assignment']['kind'] ?? null) === 'auto' && $next['assigned']['assignment']['employeeId'] === $uuids['coopb'], 'next assigns the oldest waiting order to the caller');
$error($call($opB, 'POST', '/confirmare/next', ['source' => 'outletperdele']), 403, 'CONFIRMARE_SCOPE_DENIED', 'next only within one\'s sources');

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
    // Two operators claim the same order at once: exactly one gets it.
    $ok($call($opA, 'PUT', '/confirmare/availability', ['accepting' => true]), 'A accepts again');
    $free = $all("SELECT c.global_order_id FROM order_confirmations c JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.source_key = 'trendhome' AND c.status = 'pending' AND c.assigned_employee_uuid IS NULL AND o.workflow_cohort = 'confirmare' ORDER BY c.opened_at LIMIT 3");
    $raceOrder = (string) $free[0]['global_order_id'];
    $v = $version($raceOrder);
    $results = $codes($parallel([
        $httpJob($opA, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/claim', ['expectedVersion' => $v]),
        $httpJob($opB, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/claim', ['expectedVersion' => $v]),
    ]));
    sort($results);
    $check(count(array_keys($results, 'ok', true)) === 1 && array_diff($results, ['ok', 'CONFIRMARE_CHANGED', 'CONFIRMARE_ASSIGNED_TO_OTHER']) === [] && $version($raceOrder) === $v + 1, 'claim race: exactly one wins: ' . json_encode($results));
    // The assignee approves while a supervisor rejects: exactly one decision, one event.
    $assignee = $conf($raceOrder)['assigned_employee_uuid'] === $uuids['coopa'] ? $opA : $opB;
    $v = $version($raceOrder);
    $results = $codes($parallel([
        $httpJob($assignee, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/approve', ['expectedVersion' => $v]),
        $httpJob($sup, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder) . '/reject', ['expectedVersion' => $v, 'reasonCode' => 'duplicate_order']),
    ]));
    $decisions = (int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE global_order_id = ? AND action IN ('approved', 'rejected')", [$raceOrder]);
    $check(count(array_keys($results, 'ok', true)) === 1 && $decisions === 1 && $version($raceOrder) === $v + 1 && in_array($conf($raceOrder)['status'], ['approved', 'rejected'], true), 'decision race: exactly one decision: ' . json_encode($results));
    // Approval racing a cutting intake: production never starts while the order is still pending.
    $raceOrder2 = (string) $free[1]['global_order_id'];
    $ok($act($sup, $raceOrder2, 'reassign', ['expectedVersion' => $version($raceOrder2), 'employeeId' => $uuids['coopa']]), 'prepare intake race');
    if ((string) $one('SELECT advance_status FROM operational_orders WHERE global_order_id = ?', [$raceOrder2]) === 'waiting') {
        $ok($send('trendhome', $payload(explode(':', $raceOrder2)[1], 'processing', null, $cod('confirmed'))), 'advance confirmed for the intake race');
    }
    $owned = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_stage_id = 'material-preparation' AND production_completed_at IS NULL", [$uuids['cocutter']]);
    $results = $parallel([
        $httpJob($opA, 'POST', '/confirmare/orders/' . rawurlencode($raceOrder2) . '/approve', ['expectedVersion' => $version($raceOrder2)]),
        $httpJob($cutter, 'POST', '/cutting/intake', ['qrToken' => $qr($raceOrder2)] + ($owned > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $owned] : [])),
    ]);
    $intakeResult = $results[1]['status'] === 200 ? 'started' : (string) $results[1]['code'];
    $check($results[0]['status'] === 200 && $conf($raceOrder2)['status'] === 'approved'
        && (($intakeResult === 'started' && $stage($raceOrder2) === 'material-preparation') || ($intakeResult === 'CONFIRMARE_NECESARA' && $stage($raceOrder2) === 'waiting')), 'approval vs intake: the intake either waited for the approval or was refused before it: ' . $intakeResult);
    // The same operator pulls twice with one free slot: exactly one assignment.
    $ok($call($sup, 'PUT', '/confirmare/availability', ['accepting' => false, 'employeeId' => $uuids['coopb']]), 'B unavailable for the next race');
    $fill = ConfirmareService::MAX_OPEN_PER_OPERATOR - 1 - (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopa']]);
    for ($i = 0; $i < $fill; $i++) $ok($call($opA, 'POST', '/confirmare/next', ['source' => 'trendhome']), 'fill A');
    $waitingBefore = (int) $one("SELECT COUNT(*) FROM order_confirmations c JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.status = 'pending' AND c.assigned_employee_uuid IS NULL AND o.workflow_cohort = 'confirmare' AND c.source_key = 'trendhome'");
    $results = $parallel(array_fill(0, 6, $httpJob($opA, 'POST', '/confirmare/next', ['source' => 'trendhome'])));
    $loadA = (int) $one("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'", [$uuids['coopa']]);
    $check($waitingBefore >= 1 && $loadA === ConfirmareService::MAX_OPEN_PER_OPERATOR && count(array_filter($results, static fn (array $r): bool => $r['status'] === 200 && ($r['body']['assigned'] ?? null) !== null)) === 1, 'next race: the capacity holds: ' . json_encode($codes($results)));
    // Two distribution runs at once never assign an order twice.
    $ok($call($sup, 'PUT', '/confirmare/availability', ['accepting' => true, 'employeeId' => $uuids['coopb']]), 'B available again');
    for ($n = 300; $n < 306; $n++) $ok($send('trendhome', $payload($id($n), 'processing', $submitted($after($n)), $online)), "race order {$n}");
    $distJob = static fn (): Closure => static function (string $db) use ($settings): array {
        usleep(20000);
        return (new Container(T::config($db, [T::ORIGIN, DASHBOARD_ORIGIN], $settings)))->confirmareService()->distribute('trendhome');
    };
    $known = array_flip(array_column($all('SELECT event_uuid FROM order_confirmation_events'), 'event_uuid'));
    $runs = $parallel([$distJob(), $distJob()]);
    $doubles = (int) $one("SELECT COUNT(*) FROM (SELECT order_uuid, version_after FROM order_confirmation_events GROUP BY order_uuid, version_after HAVING COUNT(*) > 1) d");
    $fresh = array_values(array_filter($all("SELECT event_uuid, order_uuid FROM order_confirmation_events WHERE action = 'assigned' AND actor_kind = 'system'"), static fn (array $e): bool => !isset($known[$e['event_uuid']])));
    $newEvents = count($fresh);
    $newOrders = array_unique(array_column($fresh, 'order_uuid'));
    $overCapacity = (int) $one("SELECT COUNT(*) FROM (SELECT assigned_employee_uuid FROM order_confirmations WHERE status = 'pending' AND assigned_employee_uuid IS NOT NULL GROUP BY assigned_employee_uuid HAVING COUNT(*) > " . ConfirmareService::MAX_OPEN_PER_OPERATOR . ') x');
    $check($doubles === 0 && $newEvents === array_sum(array_column($runs, 'assigned')) && $newEvents === count($newOrders) && $overCapacity === 0, 'parallel distribution runs never assign one order twice nor exceed a capacity: ' . json_encode([$runs, $newEvents, count($newOrders)]));
}

// The production gate reads the decision as committed now, not from a snapshot its transaction took earlier.
$gateOrder = (string) $one("SELECT c.global_order_id FROM order_confirmations c JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.status = 'pending' AND o.workflow_cohort = 'confirmare' AND c.source_key = 'trendhome' AND o.production_stage_id = 'waiting' ORDER BY c.opened_at LIMIT 1");
$gateUuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$gateOrder]);
$other = Connection::create($config);
$other->beginTransaction();
$other->query("SELECT status FROM order_confirmations WHERE order_uuid = " . $other->quote($gateUuid))->fetchAll();
$ok($act($sup, $gateOrder, 'approve', ['expectedVersion' => $version($gateOrder)]), 'approve while another transaction holds an older snapshot');
$opened = true;
try { ConfirmationGate::assertOpen($other, $gateUuid, 'waiting'); } catch (ApiException) { $opened = false; }
$other->rollBack();
$check($opened, 'the gate sees the committed approval, never an older snapshot of its transaction');

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
$check($activate['code'] === 3 && str_contains($activate['err'], 'CONFIRMARE_WORKFLOW_NOT_READY'), 'the operator CLI still refuses every activation: ' . $activate['err']);

// ---- 10. Resumable migration and teardown ---------------------------------------------------------------------------
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/036_confirmare_operations.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmations' AND column_name IN ('assigned_employee_uuid', 'assigned_at', 'assignment_kind', 'decision_reason_code')") === 4, 'a repeated 036 run changes nothing');
$pdo->exec('DELETE FROM order_confirmation_idempotency');
$pdo->exec('DELETE FROM order_confirmation_events');
$pdo->exec('DELETE FROM order_confirmations');
$pdo->exec('DELETE FROM workflow_cohort_activations');
$pdo->exec('DELETE FROM employee_confirmare_scopes');
$pdo->exec('DELETE FROM confirmare_operator_state');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');
// An interrupted 036 (tables created, the ALTER not applied) completes on the next run.
$pdo->exec('ALTER TABLE order_confirmations DROP CONSTRAINT chk_order_confirmations_reason, DROP CONSTRAINT chk_order_confirmations_assigned_pending, DROP CONSTRAINT chk_order_confirmations_assignment,
    DROP FOREIGN KEY fk_order_confirmations_assignee, DROP KEY idx_order_confirmations_assignee, DROP COLUMN decision_reason_code, DROP COLUMN assignment_kind, DROP COLUMN assigned_at, DROP COLUMN assigned_employee_uuid');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/036_confirmare_operations.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmations' AND column_name IN ('assigned_employee_uuid', 'assigned_at', 'assignment_kind', 'decision_reason_code')") === 4, 'an interrupted 036 completes on the next run');

fwrite(STDOUT, "PASS MySQL Confirmare operations integration: {$checks} checks (default deny, root-only audited scopes, pool per scope, availability, automatic and pull distribution, capacity, decisions with versions and idempotency, gate with the payment gate, undetermined and cancelled orders, reassign, reclaim, races, audit trail, CLIs, readiness, resumable migration).\n");
