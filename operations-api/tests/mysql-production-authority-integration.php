<?php

declare(strict_types=1);

// Production authority control plane (API 2.18.0, migration 018) against a dedicated MySQL/MariaDB test
// database: per-source authority modes, the explicit manager takeover with a selected canonical stage,
// idempotency, optimistic concurrency (including two concurrent takeovers), the restricted release,
// source ingestion and cancellation after a takeover, normal 14-stage work and the controlled 3 -> 2
// fault return on a taken-over order, the enforced new-order policy, Staff/supervisor guards for
// source-managed orders, observation records, the signed authority answer and the audit trail.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL production authority integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }
}

/** @param array{status: int, body: array<string, mixed>|null} $response */
function checkError(array $response, int $status, string $code, string $message): void
{
    check($response['status'] === $status && ($response['body']['error']['code'] ?? null) === $code, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
}

/** @param array{status: int, body: array<string, mixed>|null} $response */
function checkOk(array $response, string $message, int $status = 200): array
{
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$enforcing = ['trendhome' => ['mode' => 'active', 'authority' => 'enforce'], 'outletperdele' => ['mode' => 'active', 'authority' => 'observe']];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $enforcing);
$legacyConfig = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);

// ---- Migration 018: additive, recorded once, converts no order, grants only the two top templates ---
require __DIR__ . '/HandoffSchemaFixture.php';
restorePreAuthorityTestSchema($pdo);
$grantsBefore = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC);
$authorityBefore = $pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, version FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC);
check((new MigrationRunner($pdo))->migrate($migrations) === ['018_production_authority.sql'], '017 -> 018 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations) === [], '018 recorded exactly once');
check($pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, version FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC) === $authorityBefore, '018 changes the authority, stage or version of no existing order');
$newGrants = $pdo->query("SELECT r.role_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key = 'production.manage_authority' ORDER BY r.role_key")->fetchAll(PDO::FETCH_COLUMN);
check($newGrants === ['ceo', 'operations-director'], 'only the CEO and operations director templates receive production.manage_authority: ' . json_encode($newGrants));
check($pdo->query("SELECT rp.* FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key <> 'production.manage_authority' ORDER BY rp.role_id, rp.permission_id")->fetchAll(PDO::FETCH_ASSOC) === $grantsBefore, '018 changes no existing grant');

foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$cleanup = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM production_authority_events');
    foreach (['production_document_blocks', 'production_document_events', 'production_document_prints'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('UPDATE operational_orders SET active_document_revision_uuid = NULL');
    $pdo->exec('UPDATE production_document_revisions SET request_uuid = NULL');
    $pdo->exec('UPDATE production_document_revision_requests SET previous_request_uuid = NULL');
    $pdo->exec('UPDATE production_exception_decisions SET previous_decision_uuid = NULL');
    $pdo->exec('DELETE FROM production_document_revision_requests');
    $pdo->exec('DELETE FROM production_document_revisions');
    foreach (['live_events', 'production_exception_idempotency', 'production_quality_events', 'production_exception_events', 'production_exception_decisions', 'production_exception_lines'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('UPDATE operational_orders SET open_exception_uuid = NULL');
    foreach (['production_exceptions', 'cutting_transfers', 'cutting_facts', 'analytics_ownership_intervals', 'analytics_order_projection', 'responsibility_assignments', 'organization_principals', 'order_activity_events', 'order_operation_idempotency', 'employee_order_relations', 'order_qr_references', 'operational_order_items', 'order_projection_receipts'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec("DELETE FROM operational_orders WHERE source_key <> 'b2b'");
};
$cleanup();
$pdo->exec('DELETE FROM system_root_identity');
// Later suites delete orders: never leave authority evidence that would pin them, even after a failure.
register_shutdown_function(static function () use ($pdo): void {
    try {
        $pdo->exec('DELETE FROM production_authority_events');
    } catch (Throwable) {
    }
});

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$legacyKernel = (new Container($legacyConfig, $pdo))->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string {
    $keys++;
    return sprintf('pau-%s-%s-%04d', $label, $suffix, $keys);
};

$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};
$dash = static function (array $who, string $method, string $path, ?array $json = null, ?string $idem = null) use ($kernel): array {
    $headers = ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($idem !== null) {
        $headers['idempotency-key'] = $idem;
    }
    return T::call($kernel, $method, $path, $json, $headers, $who['cookie']);
};
$get = static fn (array $who, string $path, ?object $k = null): array => T::call($k ?? $kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $who['cookie']);
$staffPost = static function (array $who, string $path, array $json, ?string $idem, ?object $k = null) use ($kernel): array {
    $headers = ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($idem !== null) {
        $headers['idempotency-key'] = $idem;
    }
    return T::call($k ?? $kernel, 'POST', $path, $json, $headers, $who['cookie']);
};
$authorityPath = static fn (string $globalId, string $action = ''): string => '/orders/' . rawurlencode($globalId) . '/production-authority' . ($action === '' ? '' : "/{$action}");
$takeover = static fn (array $who, string $globalId, array $input, ?string $idem, ?object $k = null): array => $staffPost($who, $authorityPath($globalId, 'takeover'), $input, $idem, $k);
$release = static fn (array $who, string $globalId, int $version, ?string $idem): array => $staffPost($who, $authorityPath($globalId, 'release'), ['expectedVersion' => $version], $idem);
$order = static function (string $globalId) use ($pdo): array|false {
    $statement = $pdo->prepare('SELECT order_uuid, production_authority, production_stage_id, production_version, version, production_owner_employee_uuid, operational_status, production_changed_at, open_exception_uuid, source_commerce_status_code FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$count = static function (string $sql, array $args = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    return (int) $statement->fetchColumn();
};
$authorityEvents = static fn (string $globalId, ?string $action = null): int => $count('SELECT COUNT(*) FROM production_authority_events WHERE global_order_id = ?' . ($action === null ? '' : ' AND action = ?'), $action === null ? [$globalId] : [$globalId, $action]);
$changedAt = static fn (int $secondsAgo): string => gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);
$ingest = static function (object $k, string $source, string $id, string $event, int $ago, ?string $stage = null, string $status = 'processing', string $availability = 'active', ?array $items = null): array {
    $payload = T::sourceOrder($id, $event, gmdate('Y-m-d\TH:i:s\Z', time() - $ago), $stage === null ? null : T::stage($stage), $status, $availability, $items);
    $payload['order']['delivery'] = ['name' => 'TEST Client Autoritate', 'street' => 'Str. Test 9', 'city' => 'Iasi', 'postalCode' => '700000', 'country' => 'RO', 'phone' => '0722000999'];
    return T::ingest($k, $source, $payload);
};

// ---- Identities ------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.owner.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-authority-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Authority root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Authority root passphrase 2026!');
$roleIds = [];
foreach (checkOk(T::call($kernel, 'GET', '/management/roles', null, ['origin' => DASHBOARD_ORIGIN], $root['cookie']), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
check(in_array('production.manage_authority', array_column(checkOk(T::call($kernel, 'GET', '/management/permissions', null, ['origin' => DASHBOARD_ORIGIN], $root['cookie']), 'permission catalog')['items'], 'key'), true), 'production.manage_authority is in the IAM permission catalog');
$authorityRole = (int) checkOk($dash($root, 'POST', '/management/roles', ['name' => "Autoritate {$suffix}", 'description' => null, 'authorityRank' => 400, 'permissions' => ['production.manage_authority']]), 'authority role', 201)['role']['id'];
$password = 'authority passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles, string $department = 'pregatire-material') use ($admin, $dash, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, $department, 'employee', $password, $stages, 'test');
    checkOk($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), "{$name} applications");
    checkOk($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return $employee->employeeUuid;
};
$managerId = $identity('manager', [], ['staff', 'dashboard'], [$authorityRole]);
$manager2Id = $identity('manager2', [], ['staff', 'dashboard'], [$authorityRole]);
$identity('staffonly', [], ['staff'], [$authorityRole]);
$identity('opsmanager', [], ['dashboard'], [$roleIds['operations-manager']]);
$waiterId = $identity('waiter', ['waiting', 'material-preparation'], ['staff'], []);
$cutterId = $identity('cutter', ['material-preparation'], ['staff'], []);
$tailorId = $identity('tailor', ['workshop-receiving'], ['staff'], []);
$identity('supervisor', [], ['dashboard'], [$roleIds['supervisor']]);
$manager = $login("manager.{$suffix}", $password);
$manager2 = $login("manager2.{$suffix}", $password);
$staffOnly = $login("staffonly.{$suffix}", $password);
$opsManager = $login("opsmanager.{$suffix}", $password);
$waiter = $login("waiter.{$suffix}", $password);
$cutter = $login("cutter.{$suffix}", $password);
$tailor = $login("tailor.{$suffix}", $password);
$supervisor = $login("supervisor.{$suffix}", $password);

// ---- Legacy orders: created while every source is legacy -------------------------------------------
foreach (['7101', '7102', '7103', '7104', '7105', '7106', '7107'] as $id) {
    check(($ingest($legacyKernel, 'trendhome', $id, "th-{$id}-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', "legacy ingest trendhome:{$id}");
}
check(($ingest($legacyKernel, 'outletperdele', '8101', "op-8101-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'legacy ingest outletperdele:8101');
foreach (['trendhome:7101', 'trendhome:7106', 'outletperdele:8101'] as $globalId) {
    $row = $order($globalId);
    check($row['production_authority'] === 'source' && $row['production_stage_id'] === 'waiting' && (int) $row['production_version'] === 1, "{$globalId} keeps source authority under legacy");
}
check($count('SELECT COUNT(*) FROM production_authority_events') === 0, 'legacy ingestion records no authority decision');

// Legacy mode: no explicit takeover, the Staff claim keeps today's behaviour.
checkError($takeover($manager, 'trendhome:7106', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('legacy'), $legacyKernel), 409, 'AUTHORITY_CUTOVER_DISABLED', 'takeover is refused while the source is legacy');
check($order('trendhome:7106')['production_authority'] === 'source', 'a refused takeover changes nothing');
$legacyClaim = checkOk($staffPost($waiter, '/orders/trendhome%3A7106/claim', ['expectedVersion' => 1], $key('legacy-claim'), $legacyKernel), 'legacy Staff claim works as before');
check($legacyClaim['productionAuthority'] === 'operations' && $order('trendhome:7106')['production_authority'] === 'operations', 'legacy claim keeps the historic implicit authority change');
check($authorityEvents('trendhome:7106') === 0, 'legacy claim is not recorded as an observation');

// ---- Access (4) ---------------------------------------------------------------------------------------
checkError(T::call($kernel, 'POST', $authorityPath('trendhome:7101', 'takeover'), ['expectedVersion' => 1, 'stageId' => 'waiting'], ['origin' => T::ORIGIN, 'idempotency-key' => $key('anon')]), 401, 'SESSION_EXPIRED', 'unauthenticated takeover denied');
checkError(T::call($kernel, 'GET', $authorityPath('trendhome:7101'), null, ['origin' => T::ORIGIN], $waiter['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff employee cannot even inspect authority');
checkError($takeover($waiter, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('staff')), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff employee cannot take over');
checkError($takeover($staffOnly, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('noapp')), 403, 'APPLICATION_ACCESS_DENIED', 'the permission without Dashboard access is not enough');
checkError($takeover($supervisor, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('nostaffsession')), 403, 'UNAUTHORIZED_ACTION', 'a role without production.manage_authority is denied');
checkError(T::call($kernel, 'POST', $authorityPath('trendhome:7101', 'takeover'), ['expectedVersion' => 1, 'stageId' => 'waiting'], ['origin' => T::ORIGIN, 'idempotency-key' => $key('csrf')], $manager['cookie']), 403, 'CSRF_INVALID', 'a mutation without CSRF token is denied');
check($order('trendhome:7101')['production_authority'] === 'source' && (int) $order('trendhome:7101')['production_version'] === 1, 'no denied request changed the order');
check($count("SELECT COUNT(*) FROM auth_audit_events WHERE event_type = 'PRODUCTION_AUTHORITY_DENIED'") >= 3, 'denied takeovers are recorded in the security audit');

// ---- Validation (5, 6) --------------------------------------------------------------------------------
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'cutting'], $key('stage')), 422, 'INVALID_STAGE', 'a non-canonical stage is rejected');
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'fabric_prep'], $key('legacy-stage')), 422, 'INVALID_STAGE', 'a legacy YD stage key is never accepted');
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting', 'workflowVersion' => 2], $key('wf')), 422, 'WORKFLOW_MISMATCH', 'another workflow version is rejected');
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1], $key('nostage')), 400, 'INVALID_REQUEST', 'the stage must be selected explicitly');
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting', 'legacyStage' => 'sewing'], $key('extra')), 400, 'INVALID_REQUEST', 'no legacy stage input is accepted');
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting'], null), 400, 'INVALID_IDEMPOTENCY_KEY', 'an idempotency key is required');
checkError($takeover($manager, 'trendhome:999999', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('missing')), 404, 'ORDER_NOT_FOUND', 'a non-existent order is rejected');
checkError($takeover($manager, 'b2b:B2B-1', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('b2b')), 404, 'ORDER_NOT_FOUND', 'an unknown internal order is not found');

// ---- Inspect --------------------------------------------------------------------------------------------
$view = checkOk($get($manager, $authorityPath('trendhome:7101')), 'manager inspects trendhome:7101');
check($view['productionAuthority'] === 'source' && $view['authorityMode'] === 'enforce' && $view['stage']['id'] === 'waiting' && $view['productionVersion'] === 1 && $view['takeover'] === ['allowed' => true, 'blockedReason' => null] && $view['release']['allowed'] === false, 'the manager sees source authority, the enforcing mode and that a takeover is possible');
check(count($view['workflow']['stages']) === 14 && $view['workflow']['id'] === 'curtain-production' && $view['workflow']['version'] === 1 && array_column($view['workflow']['stages'], 'id')[13] === 'delivery', 'the manager chooses among exactly the 14 canonical stages');
check(!str_contains(json_encode($view), 'TEST Client') && !str_contains(json_encode($view), '0722000999'), 'the authority view carries no customer data');

// ---- Stale version (3) ---------------------------------------------------------------------------------
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 5, 'stageId' => 'material-preparation'], $key('stale')), 409, 'ORDER_CHANGED', 'a stale production version is rejected');
check($order('trendhome:7101')['production_authority'] === 'source', 'a stale takeover changes nothing');

// ---- Takeover succeeds (1) and audit (13) --------------------------------------------------------------
$before = $order('trendhome:7101');
$qrBefore = $count('SELECT COUNT(*) FROM order_qr_references WHERE order_uuid = ?', [$before['order_uuid']]);
$takeKey = $key('take-7101');
$taken = checkOk($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'material-preparation', 'workflowId' => 'curtain-production', 'workflowVersion' => 1], $takeKey), 'manager takes trendhome:7101 over at stage 2');
check($taken === ['globalOrderId' => 'trendhome:7101', 'action' => 'authority_taken_over', 'changed' => true, 'productionAuthority' => 'operations', 'stage' => ['id' => 'material-preparation', 'label' => $taken['stage']['label']], 'productionVersion' => 2], 'takeover answer: ' . json_encode($taken));
$after = $order('trendhome:7101');
check($after['production_authority'] === 'operations' && $after['production_stage_id'] === 'material-preparation' && (int) $after['production_version'] === 2 && (int) $after['version'] === (int) $before['version'] + 1 && $after['production_owner_employee_uuid'] === null && $after['production_changed_at'] !== null, 'authority, selected stage and versions are applied once');
check($count('SELECT COUNT(*) FROM order_qr_references WHERE order_uuid = ?', [$before['order_uuid']]) === $qrBefore, 'a takeover issues no QR reference');
check($count('SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = ?', [$before['order_uuid']]) === 0, 'a takeover creates no production document');
$event = $pdo->query("SELECT * FROM production_authority_events WHERE global_order_id = 'trendhome:7101'")->fetchAll(PDO::FETCH_ASSOC);
check(count($event) === 1 && $event[0]['action'] === 'takeover' && $event[0]['authority_mode'] === 'enforce' && $event[0]['previous_authority'] === 'source' && $event[0]['new_authority'] === 'operations'
    && $event[0]['previous_stage_id'] === 'waiting' && $event[0]['new_stage_id'] === 'material-preparation' && (int) $event[0]['production_version_before'] === 1 && (int) $event[0]['production_version_after'] === 2
    && $event[0]['actor_employee_uuid'] === $managerId && $event[0]['source_key'] === 'trendhome' && $event[0]['reason_code'] === 'explicit_manager_takeover' && $event[0]['idempotency_key'] === $takeKey, 'the authority event records order, source, prior/new authority, prior/selected stage, versions, actor and reason');
$activity = $pdo->query("SELECT action, employee_uuid, from_stage_id, to_stage_id, production_version_before, production_version_after FROM order_activity_events WHERE global_order_id = 'trendhome:7101'")->fetchAll(PDO::FETCH_ASSOC);
check($activity === [['action' => 'authority_taken_over', 'employee_uuid' => $managerId, 'from_stage_id' => 'waiting', 'to_stage_id' => 'material-preparation', 'production_version_before' => 1, 'production_version_after' => 2]], 'the production timeline shows the takeover: ' . json_encode($activity));
$audit = $pdo->query("SELECT metadata_json FROM iam_audit_events WHERE action = 'production.authority.taken_over' AND target_id = 'trendhome:7101' AND actor_employee_uuid = " . $pdo->quote($managerId))->fetchAll(PDO::FETCH_COLUMN);
check(count($audit) === 1 && str_contains($audit[0], '"material-preparation"') && !str_contains($audit[0], 'TEST Client') && !str_contains($audit[0], 'Iasi') && !str_contains($audit[0], '0722000999'), 'the privileged audit records the takeover without customer data');
check($count("SELECT COUNT(*) FROM cutting_facts WHERE order_uuid = ? AND fact_type = 'pool_entered'", [$before['order_uuid']]) === 1, 'a takeover into the cutting stage enters the cutting pool');

// ---- Idempotency (2) -----------------------------------------------------------------------------------
$eventsBefore = [$count('SELECT COUNT(*) FROM production_authority_events'), $count('SELECT COUNT(*) FROM order_activity_events'), $count("SELECT COUNT(*) FROM iam_audit_events WHERE action LIKE 'production.authority.%'")];
check(checkOk($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'material-preparation', 'workflowId' => 'curtain-production', 'workflowVersion' => 1], $takeKey), 'replay') === $taken, 'the same key and intent replays the committed answer');
checkError($takeover($manager, 'trendhome:7101', ['expectedVersion' => 1, 'stageId' => 'waiting'], $takeKey), 409, 'IDEMPOTENCY_CONFLICT', 'the same key with another intent is a conflict');
$repeat = checkOk($takeover($manager2, 'trendhome:7101', ['expectedVersion' => 2, 'stageId' => 'material-preparation'], $key('repeat')), 'a repeated takeover to the same final state');
check($repeat['changed'] === false && $repeat['productionVersion'] === 2 && $repeat['productionAuthority'] === 'operations', 'a repeated takeover reports no change');
checkError($takeover($manager2, 'trendhome:7101', ['expectedVersion' => 2, 'stageId' => 'labeling'], $key('restage')), 409, 'AUTHORITY_ALREADY_OPERATIONS', 'a takeover can never move the stage of an Arasya-managed order');
check([$count('SELECT COUNT(*) FROM production_authority_events'), $count('SELECT COUNT(*) FROM order_activity_events'), $count("SELECT COUNT(*) FROM iam_audit_events WHERE action LIKE 'production.authority.%'")] === $eventsBefore && (int) $order('trendhome:7101')['production_version'] === 2, 'replays and repeats increment nothing and record nothing');

// ---- Concurrency (14): two managers race on the same expected version ---------------------------------
$start = (string) (microtime(true) + 1.5);
$workers = [];
foreach ([[$manager, 'race-a', 'labeling'], [$manager2, 'race-b', 'ironing']] as [$who, $label, $stage]) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/authority-worker.php', $dbName, $authorityPath('trendhome:7102', 'takeover'), json_encode(['expectedVersion' => 1, 'stageId' => $stage]), $key($label), $who['cookie'], $who['csrf'], $start], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$results = [];
foreach ($workers as [$process, $pipes]) {
    $results[] = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['status' => 0, 'stderr' => stream_get_contents($pipes[2])];
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
$winners = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 200 && $r['changed'] === true));
$losers = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 409 && in_array($r['error'], ['ORDER_CHANGED', 'AUTHORITY_ALREADY_OPERATIONS'], true)));
check(count($winners) === 1 && count($losers) === 1, 'exactly one concurrent takeover applies, the other is rejected: ' . json_encode($results));
$raced = $order('trendhome:7102');
check((int) $raced['production_version'] === 2 && $raced['production_stage_id'] === $winners[0]['stage'] && $authorityEvents('trendhome:7102', 'takeover') === 1, 'the concurrent takeover was applied exactly once');

// ---- Source ingestion after takeover (7, 8, 9) -----------------------------------------------------------
$receipts = $count('SELECT COUNT(*) FROM order_projection_receipts WHERE global_order_id = ?', ['trendhome:7101']);
$update = $ingest($kernel, 'trendhome', '7101', "th-7101-b-{$suffix}", 600, 'delivery', 'processing', 'active', [[
    'id' => 501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Gri', 'width' => 320, 'height' => 260, 'unit' => 'cm', 'meters' => 9.1, 'quantity' => 2,
]]);
check(($update['body']['outcome'] ?? null) === 'applied' && $update['body']['globalOrderId'] === 'trendhome:7101', 'a later source snapshot (measurements, colour, quantity and even an explicit source stage) is applied');
check(array_keys($update['body']) === ['outcome', 'globalOrderId', 'qr'], 'the /orders answer keeps exactly the contract YD SOFT 2.5.8 verifies');
$ingested = $order('trendhome:7101');
check($ingested['production_stage_id'] === 'material-preparation', 'ingestion after takeover never changes the production stage');
check($ingested['production_authority'] === 'operations', 'ingestion after takeover never changes the production authority');
check((int) $ingested['production_version'] === 2, 'ingestion after takeover never changes the production version');
check((int) $ingested['version'] === (int) $after['version'] + 1, 'the commerce update still increments the row version');
check($count('SELECT COUNT(*) FROM order_projection_receipts WHERE global_order_id = ?', ['trendhome:7101']) === $receipts + 1 && $count('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', ['trendhome:7101']) === 1, 'one new receipt, the same canonical order');
check($count("SELECT COUNT(*) FROM operational_order_items i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = 'trendhome:7101' AND i.quantity = 2 AND i.color = 'Gri'") === 1, 'commerce data is updated');

// ---- Normal 14-stage work after takeover (11) -------------------------------------------------------------
$claim = static function (array $who, string $globalId, string $label) use ($staffPost, $order, $pdo, $key): array {
    $version = (int) $order($globalId)['production_version'];
    return checkOk($staffPost($who, '/orders/' . rawurlencode($globalId) . '/claim', T::cuttingClaim($pdo, $globalId, $who['employeeUuid'], $version), $key($label)), "{$label} claims {$globalId}");
};
$transition = static fn (array $who, string $globalId, string $label): array => checkOk($staffPost($who, '/orders/' . rawurlencode($globalId) . '/transition', ['expectedVersion' => (int) $order($globalId)['production_version']], $key($label)), "{$label} completes the stage of {$globalId}");
$claimed = $claim($cutter, 'trendhome:7101', 'cut');
check($claimed['productionAuthority'] === 'operations' && $claimed['productionVersion'] === 3, 'the cutting employee claims the taken-over order in Staff');
check($authorityEvents('trendhome:7101') === 1, 'claiming an Arasya-managed order is no authority decision');
$transition($cutter, 'trendhome:7101', 'cut');
check($order('trendhome:7101')['production_stage_id'] === 'workshop-receiving' && (int) $order('trendhome:7101')['production_version'] === 4, 'the canonical workflow continues 2 -> 3');
$claim($tailor, 'trendhome:7101', 'intake');

// ---- Controlled 3 -> 2 fault return after takeover (12) ---------------------------------------------------
$item = (string) $pdo->query("SELECT i.item_uuid FROM operational_order_items i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = 'trendhome:7101'")->fetchColumn();
$reported = checkOk($staffPost($tailor, '/orders/trendhome%3A7101/fault-reports', ['expectedVersion' => (int) $order('trendhome:7101')['production_version'], 'itemIds' => [$item], 'reasonKey' => 'wrong-cut'], $key('report')), 'the intake employee reports a cutting fault', 201);
$qr = 'ARASYA:Q1:' . $pdo->query("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = 'trendhome:7101' AND q.status = 'active'")->fetchColumn();
$exceptionVersion = static fn (string $id): int => (int) $pdo->query('SELECT version FROM production_exceptions WHERE exception_uuid = ' . $pdo->quote($id))->fetchColumn();
checkOk($staffPost($cutter, "/production-exceptions/{$reported['id']}/acknowledge", ['expectedVersion' => $exceptionVersion($reported['id']), 'confirmed' => true, 'qrToken' => $qr, 'comment' => 'Confirm eroarea.'], $key('ack')), 'the cutting employee acknowledges');
checkOk($dash($opsManager, 'POST', "/management/production-exceptions/{$reported['id']}/decision", ['expectedVersion' => $exceptionVersion($reported['id']), 'decision' => 'approve'], $key('approve')), 'an operations manager approves the return');
$returned = $order('trendhome:7101');
check($returned['production_stage_id'] === 'material-preparation' && $returned['production_authority'] === 'operations' && $returned['production_owner_employee_uuid'] === $cutterId, 'the approved 3 -> 2 return works on an Arasya-managed order');
checkError($release($manager, 'trendhome:7101', (int) $returned['production_version'], $key('release-moved')), 409, 'AUTHORITY_RELEASE_NOT_ALLOWED', 'an order Arasya already produced can never return to the source');

// ---- Cancellation after takeover (10) -------------------------------------------------------------------
checkOk($takeover($manager, 'trendhome:7103', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('take-7103')), 'manager takes trendhome:7103 over at the initial stage');
$beforeCancel = $order('trendhome:7103');
$cancel = $ingest($kernel, 'trendhome', '7103', "th-7103-b-{$suffix}", 600, null, 'cancelled', 'cancelled');
check(($cancel['body']['outcome'] ?? null) === 'applied', 'the source cancellation is applied');
$cancelled = $order('trendhome:7103');
check($cancelled['operational_status'] === 'unavailable' && $cancelled['source_commerce_status_code'] === 'cancelled', 'cancellation updates the operational status and the commerce status');
check($cancelled['production_stage_id'] === 'waiting' && $cancelled['production_authority'] === 'operations' && (int) $cancelled['production_version'] === (int) $beforeCancel['production_version'], 'cancellation never rewinds the stage, replaces the authority or resets the production version');
check($count("SELECT COUNT(*) FROM order_activity_events WHERE global_order_id = 'trendhome:7103'") === 1, 'cancellation erases no production history');
checkError($takeover($manager, 'trendhome:7103', ['expectedVersion' => (int) $cancelled['production_version'], 'stageId' => 'labeling'], $key('take-cancelled')), 409, 'AUTHORITY_ALREADY_OPERATIONS', 'a cancelled Arasya-managed order is never re-staged by a takeover');
check(($ingest($kernel, 'trendhome', '7105', "th-7105-b-{$suffix}", 600, null, 'cancelled', 'cancelled')['body']['outcome'] ?? null) === 'applied', 'a source-managed order is cancelled at the source');
checkError($takeover($manager, 'trendhome:7105', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('take-unavailable')), 409, 'ORDER_UNAVAILABLE', 'a cancelled source-managed order cannot be taken over');

// ---- Restricted release ---------------------------------------------------------------------------------
checkOk($takeover($manager, 'trendhome:7104', ['expectedVersion' => 1, 'stageId' => 'ironing'], $key('take-7104')), 'manager takes trendhome:7104 over at stage 8');
$inspectTaken = checkOk($get($manager, $authorityPath('trendhome:7104')), 'inspect taken-over order');
check($inspectTaken['release'] === ['allowed' => true, 'blockedReason' => null] && $inspectTaken['takeover']['blockedReason'] === 'already_operations', 'an untouched takeover may be released');
checkError($release($manager, 'trendhome:7104', 1, $key('release-stale')), 409, 'ORDER_CHANGED', 'a stale release is rejected');
checkError($release($waiter, 'trendhome:7104', 2, $key('release-staff')), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff employee cannot release');
$releaseKey = $key('release-7104');
$released = checkOk($release($manager, 'trendhome:7104', 2, $releaseKey), 'manager releases the untouched takeover');
check($released['productionAuthority'] === 'source' && $released['stage']['id'] === 'waiting' && $released['productionVersion'] === 3 && $released['action'] === 'authority_released', 'release restores source authority and the previous stage with a new production version');
check(checkOk($release($manager, 'trendhome:7104', 2, $releaseKey), 'release replay') === $released, 'a replayed release returns the committed answer');
checkError($release($manager, 'trendhome:7104', 3, $key('release-again')), 409, 'AUTHORITY_NOT_OPERATIONS', 'a released order cannot be released again');
check($authorityEvents('trendhome:7104', 'release') === 1 && $count("SELECT COUNT(*) FROM order_activity_events WHERE global_order_id = 'trendhome:7104' AND action = 'authority_released'") === 1 && $count("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production.authority.released' AND target_id = 'trendhome:7104' AND actor_employee_uuid = ?", [$managerId]) === 1, 'the release is recorded once in every trail');

// ---- Enforced source: Staff and supervisors cannot act on source-managed orders ----------------------------
$blockedView = checkOk($get($waiter, '/orders/trendhome%3A7104'), 'Staff sees the released source-managed order');
check(($blockedView['employeeActionBlockedReason'] ?? null) === 'production_authority_source' && $blockedView['productionAuthority'] === 'source' && !isset($blockedView['employeeAllowedAction']), 'Staff is told a manager must take the order over first');
checkError($staffPost($waiter, '/orders/trendhome%3A7104/claim', ['expectedVersion' => 3], $key('claim-source')), 409, 'PRODUCTION_AUTHORITY_SOURCE', 'a Staff claim of a source-managed order is refused under enforcement');
checkError($dash($root, 'PUT', '/management/orders/trendhome%3A7104/owner', ['employeeId' => $waiterId, 'expectedVersion' => 3], $key('reassign-source')), 409, 'PRODUCTION_AUTHORITY_SOURCE', 'a supervisor cannot assign work on a source-managed order under enforcement');
check($order('trendhome:7104')['production_authority'] === 'source' && $order('trendhome:7104')['production_owner_employee_uuid'] === null && (int) $order('trendhome:7104')['production_version'] === 3, 'the refused claim and assignment changed nothing');

// ---- Enforced new-order policy ----------------------------------------------------------------------------
$fresh = $ingest($kernel, 'trendhome', '7201', "th-7201-a-{$suffix}", 300, 'delivery');
check(($fresh['body']['outcome'] ?? null) === 'applied' && array_keys($fresh['body']) === ['outcome', 'globalOrderId', 'qr'], 'a genuinely new order is ingested with the unchanged /orders contract');
$new = $order('trendhome:7201');
check($new['production_authority'] === 'operations' && $new['production_stage_id'] === 'waiting' && (int) $new['production_version'] === 1 && (int) $new['version'] === 1, 'an enforcing source hands a new order to Arasya at the initial stage; a source stage is ignored');
$policy = $pdo->query("SELECT action, authority_mode, previous_authority, new_authority, new_stage_id, production_version_after, actor_employee_uuid, reason_code FROM production_authority_events WHERE global_order_id = 'trendhome:7201'")->fetchAll(PDO::FETCH_ASSOC);
check($policy === [['action' => 'new_order_policy', 'authority_mode' => 'enforce', 'previous_authority' => null, 'new_authority' => 'operations', 'new_stage_id' => 'waiting', 'production_version_after' => 1, 'actor_employee_uuid' => null, 'reason_code' => 'source_authority_enforced']], 'the new-order policy is recorded: ' . json_encode($policy));
check($count("SELECT COUNT(*) FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = 'trendhome:7201' AND q.status = 'active'") === 1, 'exactly one QR reference, as before');
check(($ingest($kernel, 'trendhome', '7201', "th-7201-a-{$suffix}", 300, 'delivery')['body']['outcome'] ?? null) === 'duplicate' && $authorityEvents('trendhome:7201') === 1, 'a replayed event records no second authority decision');
$claimNew = $claim($waiter, 'trendhome:7201', 'claim-new');
check($claimNew['productionAuthority'] === 'operations' && $claimNew['productionVersion'] === 2 && $authorityEvents('trendhome:7201') === 1, 'Staff works on the new Arasya-managed order immediately');
$existing = $ingest($kernel, 'trendhome', '7107', "th-7107-b-{$suffix}", 600, null, 'processing', 'active', [[
    'id' => 501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 250, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1,
]]);
check(($existing['body']['outcome'] ?? null) === 'applied' && $order('trendhome:7107')['production_authority'] === 'source', 'an existing source-managed order is never converted by a later snapshot: ' . json_encode([$existing['body'], $order('trendhome:7107')]));

// ---- Observe mode (outletperdele) --------------------------------------------------------------------------
$observedNew = $ingest($kernel, 'outletperdele', '8201', "op-8201-a-{$suffix}", 300);
check(($observedNew['body']['outcome'] ?? null) === 'applied' && $order('outletperdele:8201')['production_authority'] === 'source', 'an observing source keeps new orders source-managed');
$observedClaim = checkOk($staffPost($waiter, '/orders/outletperdele%3A8101/claim', ['expectedVersion' => 1], $key('claim-observe')), 'observe mode blocks nothing');
check($observedClaim['productionAuthority'] === 'operations', 'the historic implicit change still happens in observe mode');
// Known risk (docs/production-authority.md, "Observe is a short pilot"): this implicit change bypasses the audited
// takeover. The source can only see it through the signed authority answer, so a YD SOFT reconciliation marks it.
$implicit = T::ingest($kernel, 'outletperdele', ['orderIds' => ['8101']], null, null, 'orders/authority');
check($implicit['status'] === 200 && $implicit['body']['productionAuthorityMode'] === 'observe' && $implicit['body']['orders'][0]['productionAuthority'] === 'operations', 'known risk: an implicit claim flip in observe is visible to the source only through the authority answer');
$observed = $pdo->query("SELECT action, authority_mode, previous_authority, new_authority, actor_employee_uuid, reason_code FROM production_authority_events WHERE global_order_id = 'outletperdele:8101'")->fetchAll(PDO::FETCH_ASSOC);
check($observed === [['action' => 'claim_observed', 'authority_mode' => 'observe', 'previous_authority' => 'source', 'new_authority' => 'operations', 'actor_employee_uuid' => $waiterId, 'reason_code' => 'staff_claim_of_source_order']], 'the claim that enforcement would refuse is recorded: ' . json_encode($observed));
checkOk($takeover($manager, 'outletperdele:8201', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('take-observe')), 'an observing source allows explicit pilot takeovers');

// ---- Signed authority answer for the source -----------------------------------------------------------------
$answer = T::ingest($kernel, 'trendhome', ['orderIds' => ['7101', '7104', '7201', '424242']], null, null, 'orders/authority');
check($answer['status'] === 200 && $answer['body']['productionAuthorityMode'] === 'enforce', 'the signed authority answer reports the source mode');
check(array_column($answer['body']['orders'], 'productionAuthority', 'orderId') === ['7101' => 'operations', '7104' => 'source', '7201' => 'operations', '424242' => null] && $answer['body']['orders'][3]['exists'] === false, 'the source learns which of its orders Arasya manages');
check(!str_contains(json_encode($answer['body']), 'ARASYA:Q1') && !str_contains(json_encode($answer['body']), 'TEST Client'), 'no QR value and no customer data are returned');
check(T::ingest($kernel, 'outletperdele', ['orderIds' => ['7101']], null, null, 'orders/authority')['body']['orders'][0]['exists'] === false, 'a source never sees another source\'s orders');
checkError(T::ingest($kernel, 'trendhome', ['orderIds' => ['7101']], T::OUTLET_SECRET, null, 'orders/authority'), 401, 'SOURCE_SIGNATURE_INVALID', 'the authority answer requires the source signature');
$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat');
check($heartbeat['body']['productionAuthorityMode'] === 'enforce' && $heartbeat['body']['mode'] === 'active' && $heartbeat['body']['contract']['stageCount'] === 14, 'the heartbeat reports the authority mode beside the unchanged contract');

// ---- Workflow invariants --------------------------------------------------------------------------------------
check($pdo->query("SELECT ps.stage_id FROM production_stages ps JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id WHERE pw.workflow_key = 'curtain-production' AND pw.status = 'active' ORDER BY ps.ordinal")->fetchAll(PDO::FETCH_COLUMN)
    === ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'], 'the canonical 14-stage workflow is unchanged');

$cleanup();
fwrite(STDOUT, "PASS MySQL production authority integration: {$checks} checks.\n");
