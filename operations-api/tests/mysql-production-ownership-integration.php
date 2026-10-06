<?php

declare(strict_types=1);

// Production Control V2 against a dedicated MySQL/MariaDB test database: supervisor release and
// reassignment of the current production owner. Covers access, eligibility, the authority ceiling,
// optimistic concurrency, idempotency, immutable production activity, IAM audit, Staff behaviour of
// the previous and new owner, IAM changes that make an owner ineligible, and the invariants that an
// owner intervention never moves the production stage, never touches commerce data and never
// produces anything for the source.

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
    fwrite(STDOUT, "SKIP MySQL production ownership integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
function checkOk(array $response, string $message): array
{
    check($response['status'] === 200, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';

$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);

foreach (['cutting_transfers', 'cutting_facts', 'order_activity_events', 'order_operation_idempotency', 'employee_order_relations', 'order_qr_references', 'operational_order_items', 'order_projection_receipts', 'operational_orders'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM system_root_identity');

$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD_ORIGIN], $who['cookie'], $query);
$asDashboard = static fn (array $who, string $method, string $path, ?array $json = null): array => T::call($kernel, $method, $path, $json, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']], $who['cookie']);
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};

// ---- Identities ------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.owner.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-ownership-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Ownership root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Ownership root passphrase 2026!');
$rootId = (string) $pdo->query('SELECT employee_uuid FROM system_root_identity')->fetchColumn();
$roleIds = [];
foreach (checkOk($get($root, '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$permissionKeys = array_column(checkOk($get($root, '/management/permissions'), 'permission catalog')['items'] ?? [], 'key');
check(in_array('production.manage_owner', $permissionKeys, true), 'production.manage_owner is in the permission catalog');
$templatePermissions = static function (string $roleKey) use ($pdo): array {
    $statement = $pdo->prepare('SELECT p.permission_key FROM role_permissions rp INNER JOIN roles r ON r.role_id = rp.role_id INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = ?');
    $statement->execute([$roleKey]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
};
check(in_array('production.manage_owner', $templatePermissions('ceo'), true) && in_array('production.manage_owner', $templatePermissions('operations-director'), true), 'the CEO and operations director templates may intervene');
check(!in_array('production.manage_owner', $templatePermissions('supervisor'), true) && !in_array('production.manage_owner', $templatePermissions('department-manager'), true) && !in_array('production.manage_owner', $templatePermissions('employee'), true), 'viewing roles and ordinary employees do not get intervention');

$customRole = static function (string $name, array $permissions, int $rank) use ($asDashboard, $root, $suffix): int {
    $created = $asDashboard($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => $rank, 'permissions' => $permissions]);
    check($created['status'] === 201, "role {$name} created");
    return (int) $created['body']['role']['id'];
};
$password = 'ownership passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $asDashboard, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    check($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications])['status'] === 200, "{$name} applications");
    if ($roles !== []) {
        check($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles])['status'] === 200, "{$name} roles");
    }
    return $employee->employeeUuid;
};
$interventionRole = $customRole('Interventie', ['dashboard.overview.view', 'orders.view_all', 'activity.view_all', 'production.manage_owner', 'iam.audit.view'], 400);
$staffStages = ['waiting', 'material-preparation', 'delivery'];
$directorId = $identity('director', [], ['dashboard'], [$interventionRole]);
$identity('viewer', [], ['dashboard'], [$roleIds['supervisor']]);
$identity('noapp', [], ['staff'], [$interventionRole]);
$aliId = $identity('ali', $staffStages, ['staff'], []);
$mehmetId = $identity('mehmet', $staffStages, ['staff'], []);
$cemId = $identity('cem', $staffStages, ['staff'], []);
$inactiveId = $identity('inactive', $staffStages, ['staff'], []);
$noStaffId = $identity('nostaff', $staffStages, ['dashboard'], []);
$noStageId = $identity('nostage', ['ironing'], ['staff'], []);
$seniorId = $identity('senior', $staffStages, ['staff'], [$customRole('Senior', ['dashboard.overview.view'], 500)]);
check($asDashboard($root, 'POST', "/management/employees/{$inactiveId}/deactivate")['status'] === 200, 'one Staff employee is deactivated');
$director = $login("director.{$suffix}", $password);
$viewer = $login("viewer.{$suffix}", $password);
$noapp = $login("noapp.{$suffix}", $password);
$ali = $login("ali.{$suffix}", $password);
$mehmet = $login("mehmet.{$suffix}", $password);
$cem = $login("cem.{$suffix}", $password);
$senior = $login("senior.{$suffix}", $password);

// ---- Orders ----------------------------------------------------------------------------------------
$changedAt = static fn (int $secondsAgo): string => gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);
$ingest = static function (string $id, string $event, ?string $stage, string $status = 'processing', string $availability = 'active', int $ago = 600) use ($kernel, $changedAt): void {
    $response = T::ingest($kernel, 'trendhome', T::sourceOrder($id, $event, $changedAt($ago), $stage === null ? null : T::stage($stage), $status, $availability));
    check(($response['body']['outcome'] ?? null) === 'applied', "ingest trendhome:{$id} {$event} (" . json_encode($response['body']) . ')');
};
$ingest('9101', "th-9101-{$suffix}", 'waiting');
$ingest('9102', "th-9102-{$suffix}", 'delivery');
$ingest('9103', "th-9103-{$suffix}", 'waiting', 'cancelled', 'cancelled');
$ingest('9104', "th-9104-{$suffix}", 'waiting');
$ingest('9105', "th-9105-{$suffix}", 'waiting');

$staff = static function (array $who, string $globalId, string $action, int $version, string $key) use ($kernel, $pdo): array {
    $input = $action === 'claim' ? T::cuttingClaim($pdo, $globalId, $who['employeeUuid'], $version) : ['expectedVersion' => $version];
    return T::call($kernel, 'POST', '/orders/' . rawurlencode($globalId) . '/' . $action, $input, ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key], $who['cookie']);
};
$intervene = static function (array $who, string $method, string $globalId, array $body, ?string $key) use ($kernel): array {
    $headers = ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($key !== null) {
        $headers['idempotency-key'] = $key;
    }
    return T::call($kernel, $method, '/management/orders/' . rawurlencode($globalId) . ($method === 'PUT' ? '/owner' : '/release-owner'), $body, $headers, $who['cookie']);
};
$reassign = static fn (array $who, string $globalId, string $employeeId, int $version, ?string $key) => $intervene($who, 'PUT', $globalId, ['employeeId' => $employeeId, 'expectedVersion' => $version], $key);
$release = static fn (array $who, string $globalId, int $version, ?string $key) => $intervene($who, 'POST', $globalId, ['expectedVersion' => $version], $key);
$production = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT production_stage_id, production_owner_employee_uuid, production_claimed_at, production_changed_at, production_version, production_completed_at, production_authority FROM operational_orders WHERE global_order_id = :id');
    $statement->execute(['id' => $globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$commerce = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT source_commerce_status_code, source_commerce_status_label, source_event_id, source_changed_at, last_source_seen_at, projection_hash, operational_status, accepted_at, production_notes FROM operational_orders WHERE global_order_id = :id');
    $statement->execute(['id' => $globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$items = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT i.* FROM operational_order_items i INNER JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = :id ORDER BY i.line_number');
    $statement->execute(['id' => $globalId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$sourceState = static fn (): array => $pdo->query('SELECT source_key, last_contact_at, last_event_at, sync_cursor_at FROM order_sources ORDER BY source_key')->fetchAll(PDO::FETCH_ASSOC);
$receipts = static fn (): int => $count('SELECT COUNT(*) FROM order_projection_receipts');
$detail = static fn (array $who, string $globalId): array => checkOk($get($who, '/management/orders/' . rawurlencode($globalId)), "{$globalId} detail");
$mine = static fn (array $who): array => array_column(checkOk(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $who['cookie']), 'Staff work list')['items'], 'employeeRelation', 'id');

$sourcesBefore = $sourceState();
$receiptsBefore = $receipts();

// ---- 1. Employee A (Ali) claims through the existing Staff flow -----------------------------------
check($staff($ali, 'trendhome:9101', 'claim', 1, "o-claim-9101-a-{$suffix}")['status'] === 200, 'Ali claims trendhome:9101 in Staff');
$commerceBefore = $commerce('trendhome:9101');
$itemsBefore = $items('trendhome:9101');
$before = $production('trendhome:9101');
$view = $detail($director, 'trendhome:9101');
check($view['production']['owner']['id'] === $aliId && $view['production']['version'] === 2 && $view['production']['attention'] === null, 'Dashboard detail shows Ali as owner at production version 2');
check($view['production']['control'] === ['canManageOwner' => true, 'blockedReason' => null], 'the director may intervene on this order');
check($detail($viewer, 'trendhome:9101')['production']['control']['canManageOwner'] === false, 'a viewing supervisor is told it cannot intervene');

// ---- Access -----------------------------------------------------------------------------------------
checkError(T::call($kernel, 'PUT', '/management/orders/trendhome%3A9101/owner', ['employeeId' => $mehmetId, 'expectedVersion' => 2], ['origin' => DASHBOARD_ORIGIN, 'idempotency-key' => "o-anon-{$suffix}-0000"]), 401, 'SESSION_EXPIRED', 'unauthenticated reassignment denied');
checkError(T::call($kernel, 'POST', '/management/orders/trendhome%3A9101/release-owner', ['expectedVersion' => 2], ['origin' => DASHBOARD_ORIGIN, 'idempotency-key' => "o-anon-{$suffix}-0001"]), 401, 'SESSION_EXPIRED', 'unauthenticated release denied');
checkError($reassign($noapp, 'trendhome:9101', $mehmetId, 2, "o-noapp-{$suffix}-00001"), 403, 'APPLICATION_ACCESS_DENIED', 'intervention without Dashboard access denied');
checkError($reassign($ali, 'trendhome:9101', $mehmetId, 2, "o-staff-{$suffix}-00001"), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff employee cannot intervene');
checkError($reassign($viewer, 'trendhome:9101', $mehmetId, 2, "o-viewer-{$suffix}-0001"), 403, 'UNAUTHORIZED_ACTION', 'orders.view_all without production.manage_owner cannot reassign');
checkError($release($viewer, 'trendhome:9101', 2, "o-viewer-{$suffix}-0002"), 403, 'UNAUTHORIZED_ACTION', 'orders.view_all without production.manage_owner cannot release');
checkError($get($viewer, '/management/orders/' . rawurlencode('trendhome:9101') . '/eligible-owners'), 403, 'UNAUTHORIZED_ACTION', 'the eligible-owner list also needs production.manage_owner');
checkError(T::call($kernel, 'PUT', '/management/orders/trendhome%3A9101/owner', ['employeeId' => $mehmetId, 'expectedVersion' => 2], ['origin' => DASHBOARD_ORIGIN, 'idempotency-key' => "o-nocsrf-{$suffix}-01"], $director['cookie']), 403, 'CSRF_INVALID', 'a mutation without CSRF is rejected');
checkError(T::call($kernel, 'PUT', '/management/orders/trendhome%3A9101/owner', ['employeeId' => $mehmetId, 'expectedVersion' => 2], ['origin' => 'https://evil.example', 'x-csrf-token' => $director['csrf'], 'idempotency-key' => "o-origin-{$suffix}-01"], $director['cookie']), 403, 'ORIGIN_DENIED', 'a mutation from a foreign origin is rejected');
checkError($reassign($director, 'trendhome:9101', $mehmetId, 2, null), 400, 'INVALID_IDEMPOTENCY_KEY', 'an Idempotency-Key is required');
checkError($intervene($director, 'PUT', 'trendhome:9101', ['employeeId' => $mehmetId, 'expectedVersion' => 2, 'stageId' => 'packing'], "o-extra-{$suffix}-0001"), 400, 'INVALID_REQUEST', 'unknown fields such as a target stage are rejected');
checkError($intervene($director, 'PUT', 'trendhome:9101', ['employeeId' => $mehmetId, 'expectedVersion' => '2'], "o-type-{$suffix}-00001"), 400, 'INVALID_REQUEST', 'expectedVersion must be an integer');
checkError($intervene($director, 'POST', 'trendhome:9101', [], "o-empty-{$suffix}-0001"), 400, 'INVALID_REQUEST', 'release requires expectedVersion');
checkError($asDashboard($director, 'PATCH', '/management/orders/trendhome%3A9101/owner', ['employeeId' => $mehmetId]), 405, 'METHOD_NOT_ALLOWED', 'no generic PATCH on ownership');
checkError($asDashboard($root, 'POST', '/management/orders/trendhome%3A9101/stage', ['stageId' => 'packing']), 404, 'NOT_FOUND', 'no stage route exists, not even for root');
checkError($asDashboard($root, 'PATCH', '/management/orders/trendhome%3A9101', ['productionStageId' => 'packing']), 404, 'NOT_FOUND', 'no generic order PATCH exists');
check($production('trendhome:9101') === $before, 'rejected requests changed nothing');

// ---- Eligible owners --------------------------------------------------------------------------------
$eligible = checkOk($get($director, '/management/orders/' . rawurlencode('trendhome:9101') . '/eligible-owners'), 'director lists eligible owners');
// The shared test database may hold employees of other suites; judge only this suite's identities.
$ours = [$directorId, $aliId, $mehmetId, $cemId, $inactiveId, $noStaffId, $noStageId, $seniorId, $rootId];
$eligibleIds = array_values(array_intersect(array_column($eligible['items'], 'id'), $ours));
sort($eligibleIds);
$expected = [$mehmetId, $cemId];
sort($expected);
check($eligibleIds === $expected, 'eligible: active Staff employees with the waiting stage, within authority; not the owner, inactive, Dashboard-only, wrong-stage, senior, root or self: ' . json_encode($eligible['items']));
check($eligible['stage']['id'] === 'waiting' && $eligible['productionVersion'] === 2 && $eligible['blockedReason'] === null, 'eligible list names the current stage and version');
check(array_keys($eligible['items'][0]) === ['id', 'displayName', 'department', 'positionTitle', 'stage'], 'eligible rows expose no IAM internals');
$rootEligible = array_column(checkOk($get($root, '/management/orders/' . rawurlencode('trendhome:9101') . '/eligible-owners'), 'root lists eligible owners')['items'], 'id');
check(in_array($seniorId, $rootEligible, true) && !in_array($rootId, $rootEligible, true), 'root may assign the senior employee; root itself is never a candidate');

// ---- Eligibility rejections ------------------------------------------------------------------------
checkError($reassign($director, 'trendhome:9101', $inactiveId, 2, "o-inactive-{$suffix}-1"), 422, 'EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE', 'inactive target rejected');
checkError($reassign($director, 'trendhome:9101', $noStaffId, 2, "o-nostaff-{$suffix}-01"), 422, 'EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE', 'target without Staff access rejected');
checkError($reassign($director, 'trendhome:9101', $noStageId, 2, "o-nostage-{$suffix}-01"), 422, 'EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE', 'target without the current stage rejected');
checkError($reassign($director, 'trendhome:9101', $rootId, 2, "o-root-{$suffix}-000001"), 403, 'ROOT_PROTECTED', 'root is never assigned production work');
checkError($reassign($root, 'trendhome:9101', $rootId, 2, "o-root-{$suffix}-000002"), 403, 'ROOT_PROTECTED', 'not even by root');
checkError($reassign($director, 'trendhome:9101', $directorId, 2, "o-self-{$suffix}-000001"), 403, 'SELF_MODIFICATION_DENIED', 'a supervisor cannot assign an order to itself');
checkError($reassign($director, 'trendhome:9101', $seniorId, 2, "o-senior-{$suffix}-0001"), 403, 'AUTHORITY_EXCEEDED', 'a supervisor cannot assign employees at or above its authority');
checkError($reassign($director, 'trendhome:9101', $aliId, 2, "o-same-{$suffix}-000001"), 400, 'INVALID_REQUEST', 'assigning the current owner again is not a change');
checkError($reassign($director, 'trendhome:9101', '00000000-0000-4000-8000-000000000000', 2, "o-ghost-{$suffix}-00001"), 404, 'EMPLOYEE_NOT_FOUND', 'unknown employee');
checkError($reassign($director, 'trendhome:9101', 'not-a-uuid', 2, "o-baduuid-{$suffix}-001"), 400, 'INVALID_REQUEST', 'malformed employee id');
checkError($reassign($director, 'trendhome:9999', $mehmetId, 1, "o-noorder-{$suffix}-001"), 404, 'ORDER_NOT_FOUND', 'unknown order');
check($production('trendhome:9101') === $before && $count("SELECT COUNT(*) FROM order_activity_events WHERE action LIKE 'owner_%'") === 0, 'no rejected intervention wrote anything');

// ---- 4. Reassign A -> B (Mehmet) -------------------------------------------------------------------
$activityBefore = $count('SELECT COUNT(*) FROM order_activity_events');
$auditBefore = $count("SELECT COUNT(*) FROM iam_audit_events WHERE action LIKE 'production.owner.%'");
$moved = checkOk($reassign($director, 'trendhome:9101', $mehmetId, 2, "o-move-9101-{$suffix}"), 'director reassigns Ali -> Mehmet');
check($moved['action'] === 'owner_reassigned' && $moved['production']['owner']['id'] === $mehmetId && $moved['production']['previousOwner']['id'] === $aliId && $moved['production']['version'] === 3 && $moved['production']['stage']['id'] === 'waiting', 'response reports the new owner, the previous owner and the unchanged stage');
$after = $production('trendhome:9101');
check($after['production_owner_employee_uuid'] === $mehmetId && (int) $after['production_version'] === 3, 'Mehmet is now the single production owner');
check($after['production_stage_id'] === $before['production_stage_id'] && $after['production_changed_at'] === $before['production_changed_at'] && $after['production_completed_at'] === null && $after['production_authority'] === $before['production_authority'], 'stage, stage-entered time, completion and authority are unchanged');
check($after['production_claimed_at'] !== null && $after['production_claimed_at'] !== $before['production_claimed_at'], 'ownership time restarts for the new owner');
check($commerce('trendhome:9101') === $commerceBefore && $items('trendhome:9101') === $itemsBefore, 'commerce status, source data, notes and items are unchanged');

// ---- 5. Duplicate submissions ---------------------------------------------------------------------
$replayed = checkOk($reassign($director, 'trendhome:9101', $mehmetId, 2, "o-move-9101-{$suffix}"), 'a browser retry with the same key');
check($replayed === $moved, 'the retry returns the committed result');
check($count('SELECT COUNT(*) FROM order_activity_events') === $activityBefore + 1 && $count("SELECT COUNT(*) FROM iam_audit_events WHERE action LIKE 'production.owner.%'") === $auditBefore + 1, 'one activity event and one audit event despite the retry');
checkError($reassign($director, 'trendhome:9101', $cemId, 2, "o-move-9101-{$suffix}"), 409, 'IDEMPOTENCY_CONFLICT', 'reusing a key for a different request is rejected');
checkError($reassign($director, 'trendhome:9101', $cemId, 2, "o-move-9101-b-{$suffix}"), 409, 'ORDER_CHANGED', 'a second dialog on the stale version cannot overwrite');

// ---- Activity and audit ---------------------------------------------------------------------------
$event = $pdo->query("SELECT * FROM order_activity_events WHERE action = 'owner_reassigned'")->fetch(PDO::FETCH_ASSOC);
check($event['employee_uuid'] === $directorId && $event['previous_owner_employee_uuid'] === $aliId && $event['new_owner_employee_uuid'] === $mehmetId, 'activity records actor, previous owner and new owner');
check($event['source_key'] === 'trendhome' && $event['global_order_id'] === 'trendhome:9101' && $event['from_stage_id'] === 'waiting' && $event['to_stage_id'] === null && (int) $event['production_version_before'] === 2 && (int) $event['production_version_after'] === 3 && $event['request_id'] !== '', 'activity records order, source, current stage, versions and request id');
// The IAM audit is permanent and shared by earlier runs; select this run's event by request id.
$auditStatement = $pdo->prepare("SELECT * FROM iam_audit_events WHERE action = 'production.owner.reassigned' AND request_id = ?");
$auditStatement->execute([$event['request_id']]);
$audit = $auditStatement->fetch(PDO::FETCH_ASSOC);
$metadata = json_decode((string) $audit['metadata_json'], true);
check(is_array($audit) && $audit['actor_employee_uuid'] === $directorId && $audit['target_type'] === 'order' && $audit['target_id'] === 'trendhome:9101', 'IAM audit records the privileged intervention with the same request id');
check($metadata['stage']['id'] === 'waiting' && $metadata['previousOwner']['id'] === $aliId && $metadata['newOwner']['id'] === $mehmetId && $metadata['productionVersion'] == ['before' => 2, 'after' => 3], 'audit metadata: stage, previous and new owner, versions');
check(!array_key_exists('9101', $mine($ali)) && !in_array('trendhome:9101', array_keys($mine($ali)), true), "the order left Ali's Staff work list");
check(($mine($mehmet)['trendhome:9101']['type'] ?? null) === 'assigned', "the order is in Mehmet's Staff work list as assigned");

// ---- 7. Previous owner is denied at operation time --------------------------------------------------
checkError($staff($ali, 'trendhome:9101', 'transition', 3, "o-ali-late-{$suffix}-1"), 409, 'ORDER_ALREADY_CLAIMED', 'Ali can no longer complete the stage, even with the current version');
checkError($staff($ali, 'trendhome:9101', 'transition', 2, "o-ali-late-{$suffix}-2"), 409, 'ORDER_CHANGED', 'Ali on his stale version is rejected too');
checkError($staff($ali, 'trendhome:9101', 'claim', 3, "o-ali-late-{$suffix}-3"), 409, 'ORDER_ALREADY_CLAIMED', 'Ali cannot claim it back');

// ---- 8. New owner acts with normal Staff permissions ------------------------------------------------
$advanced = $staff($mehmet, 'trendhome:9101', 'transition', 3, "o-mehmet-step-{$suffix}");
check($advanced['status'] === 200 && $production('trendhome:9101')['production_stage_id'] === 'material-preparation' && $production('trendhome:9101')['production_owner_employee_uuid'] === null, 'Mehmet completes waiting; production moves N -> N+1 through the canonical Staff flow');
check($detail($director, 'trendhome:9101')['production']['stage']['id'] === 'material-preparation', 'the Dashboard detail reflects the Staff change on the next read');
checkError($release($director, 'trendhome:9101', 4, "o-release-none-{$suffix}"), 409, 'ORDER_NOT_CLAIMED', 'an unowned order has nothing to release');
check($staff($mehmet, 'trendhome:9101', 'claim', 4, "o-mehmet-claim-{$suffix}")['status'] === 200, 'Mehmet claims the next stage');

// ---- Concurrency: Staff changes win over a stale supervisor ----------------------------------------
$beforeRelease = $production('trendhome:9101');
checkError($release($director, 'trendhome:9101', 4, "o-release-stale-{$suffix}"), 409, 'ORDER_CHANGED', 'a release on the version before the claim is rejected');
check($production('trendhome:9101') === $beforeRelease, 'the stale release overwrote nothing');

// ---- 10. Release B ---------------------------------------------------------------------------------
$released = checkOk($release($director, 'trendhome:9101', 5, "o-release-9101-{$suffix}"), 'director releases Mehmet');
$afterRelease = $production('trendhome:9101');
check($released['action'] === 'owner_released' && $released['production']['owner'] === null && $released['production']['previousOwner']['id'] === $mehmetId, 'release response');
check($afterRelease['production_owner_employee_uuid'] === null && $afterRelease['production_claimed_at'] === null && (int) $afterRelease['production_version'] === 6, 'owner is null');
check($afterRelease['production_stage_id'] === 'material-preparation' && $afterRelease['production_changed_at'] === $beforeRelease['production_changed_at'] && $afterRelease['production_completed_at'] === null, 'stage and stage-entered time unchanged by the release');
check($commerce('trendhome:9101') === $commerceBefore && $items('trendhome:9101') === $itemsBefore, 'commerce and items unchanged by the release');
checkError($staff($mehmet, 'trendhome:9101', 'transition', 6, "o-mehmet-late-{$suffix}"), 409, 'INVALID_STAGE_TRANSITION', 'the released owner must claim again before acting');

// ---- 13. Normal Staff claim after release -----------------------------------------------------------
check($staff($cem, 'trendhome:9101', 'claim', 6, "o-cem-claim-{$suffix}")['status'] === 200 && $production('trendhome:9101')['production_owner_employee_uuid'] === $cemId, 'Cem claims the released order through the normal Staff flow');

// ---- 14. Timeline ------------------------------------------------------------------------------------
$timeline = $detail($root, 'trendhome:9101')['activity'];
check(array_column($timeline, 'action') === ['claimed', 'owner_reassigned', 'stage_completed', 'claimed', 'owner_released', 'claimed'], 'timeline is chronological and includes both interventions: ' . json_encode(array_column($timeline, 'action')));
check($timeline[1]['employee']['id'] === $directorId && $timeline[1]['previousOwner']['displayName'] === 'Test ali' && $timeline[1]['newOwner']['displayName'] === 'Test mehmet' && $timeline[1]['toStage'] === null, 'reassignment entry: supervisor, Ali -> Mehmet, no stage move');
check($timeline[4]['previousOwner']['id'] === $mehmetId && $timeline[4]['newOwner'] === null && $timeline[4]['fromStage']['id'] === 'material-preparation', 'release entry');
check($timeline[0]['previousOwner'] === null && $timeline[0]['newOwner'] === null, 'Staff entries carry no intervention fields');
$versions = array_column($timeline, 'productionVersion');
check($versions === [2, 3, 4, 5, 6, 7], 'every production version is accounted for exactly once');
$overview = checkOk($get($root, '/management/production-overview'), 'production overview');
check(in_array('owner_released', array_column($overview['activity'], 'action'), true), 'Production Overview activity shows interventions too');

// ---- 15. IAM audit API ---------------------------------------------------------------------------------
$auditPage = checkOk($get($root, '/management/audit', ['targetType' => 'order', 'targetId' => 'trendhome:9101']), 'audit for the order');
check(array_column(array_slice($auditPage['items'], 0, 2), 'action') === ['production.owner.released', 'production.owner.reassigned'] && $auditPage['items'][0]['actorId'] === $directorId, 'IAM audit lists both interventions with stable keys: ' . json_encode(array_column($auditPage['items'], 'action')));

// Staff history stays Staff work; supervisor interventions do not appear in anyone's Staff history.
$history = checkOk(T::call($kernel, 'GET', '/activity/mine', null, ['origin' => T::ORIGIN], $root['cookie']), 'root Staff history');
check(array_filter($history['items'], static fn (array $item): bool => str_starts_with($item['action'], 'owner_')) === [], 'Staff history excludes owner interventions');

// ---- Completed and cancelled orders ----------------------------------------------------------------
check($staff($ali, 'trendhome:9102', 'claim', 1, "o-claim-9102-{$suffix}")['status'] === 200 && $staff($ali, 'trendhome:9102', 'transition', 2, "o-done-9102-{$suffix}")['status'] === 200, 'trendhome:9102 completes production');
check($detail($director, 'trendhome:9102')['production']['control']['blockedReason'] === 'production_completed', 'completed production is reported as not intervenable');
checkError($release($director, 'trendhome:9102', 3, "o-release-done-{$suffix}"), 409, 'PRODUCTION_COMPLETED', 'release on completed production rejected');
checkError($reassign($director, 'trendhome:9102', $mehmetId, 3, "o-move-done-{$suffix}-1"), 409, 'PRODUCTION_COMPLETED', 'reassign on completed production rejected');
check(checkOk($get($director, '/management/orders/' . rawurlencode('trendhome:9102') . '/eligible-owners'), 'eligible owners on completed')['items'] === [], 'no candidates for completed production');
checkError($reassign($director, 'trendhome:9103', $mehmetId, 1, "o-move-cancel-{$suffix}"), 409, 'ORDER_UNAVAILABLE', 'cancelled order rejected');

// ---- Authority over the previous owner -------------------------------------------------------------
check($staff($ali, 'trendhome:9104', 'claim', 1, "o-claim-9104-{$suffix}")['status'] === 200, 'Ali claims trendhome:9104');
checkOk($reassign($root, 'trendhome:9104', $seniorId, 2, "o-root-senior-{$suffix}"), 'root assigns the senior employee');
checkError($release($director, 'trendhome:9104', 3, "o-release-senior-{$suffix}"), 403, 'AUTHORITY_EXCEEDED', 'a supervisor cannot release an employee above its authority');
checkError($reassign($director, 'trendhome:9104', $mehmetId, 3, "o-move-senior-{$suffix}"), 403, 'AUTHORITY_EXCEEDED', 'nor take work away from them');
check($staff($senior, 'trendhome:9104', 'transition', 3, "o-senior-step-{$suffix}")['status'] === 200, 'the senior owner acts normally in Staff');

// ---- IAM changes make an owner ineligible; nothing moves automatically -------------------------------
check($staff($ali, 'trendhome:9105', 'claim', 1, "o-claim-9105-{$suffix}")['status'] === 200, 'Ali claims trendhome:9105');
$attention = static fn (): ?string => checkOk($get($director, '/management/orders', ['search' => 'trendhome:9105']), 'list 9105')['items'][0]['production']['attention'];
$beforeIam = $production('trendhome:9105');
check($attention() === null, 'an eligible owner needs no attention');
$list = checkOk($get($director, '/management/orders', ['state' => 'active']), 'active list');
check($list['counts']['unassignedActive'] === 1 && $list['counts']['ownerAttention'] === 0, 'counts: one unassigned active order (9101 is owned by Cem, 9104 by nobody after the senior step) ' . json_encode($list['counts']));

checkOk($asDashboard($root, 'PUT', "/management/employees/{$aliId}/stages", ['stageIds' => ['delivery']]), 'Ali loses the waiting stage');
check($attention() === 'owner_stage_not_allowed', 'the order needs attention: owner lacks the current stage');
check($production('trendhome:9105') === $beforeIam, 'the owner is not changed automatically');
check(in_array($staff($ali, 'trendhome:9105', 'transition', 2, "o-ali-nostage-{$suffix}")['status'], [403, 404], true), 'Ali can no longer complete the stage');
check(array_column(checkOk($get($director, '/management/orders', ['assignment' => 'owner_attention']), 'attention filter')['items'], 'globalOrderId') === ['trendhome:9105'], 'the owner_attention filter finds it');
check(checkOk($get($director, '/management/orders', ['state' => 'active']), 'counts')['counts']['ownerAttention'] === 1, 'owner-attention counter');
checkOk($asDashboard($root, 'PUT', "/management/employees/{$aliId}/stages", ['stageIds' => $staffStages]), 'Ali gets the stage back');
check($attention() === null, 'attention clears when the owner is eligible again');

checkOk($asDashboard($root, 'PUT', "/management/employees/{$aliId}/applications", ['applications' => []]), 'Ali loses Staff access');
check($attention() === 'owner_no_staff_access', 'the order needs attention: owner lost Staff access');
check($staff($ali, 'trendhome:9105', 'transition', 2, "o-ali-noapp-{$suffix}")['status'] >= 400, 'Ali cannot act without Staff access');
checkOk($asDashboard($root, 'PUT', "/management/employees/{$aliId}/applications", ['applications' => ['staff']]), 'Ali gets Staff access back');

checkOk($asDashboard($root, 'POST', "/management/employees/{$aliId}/deactivate"), 'Ali is deactivated');
check($attention() === 'owner_inactive', 'the order needs attention: owner inactive');
check($staff($ali, 'trendhome:9105', 'transition', 2, "o-ali-inactive-{$suffix}")['status'] === 401, 'a deactivated owner is blocked immediately');
check($production('trendhome:9105') === $beforeIam, 'still no automatic transfer');
check(!in_array($aliId, array_column(checkOk($get($director, '/management/orders/' . rawurlencode('trendhome:9104') . '/eligible-owners'), 'eligible after deactivation')['items'], 'id'), true), 'a deactivated employee is no longer a candidate');
checkOk($reassign($director, 'trendhome:9105', $mehmetId, 2, "o-move-9105-{$suffix}"), 'the supervisor reassigns the order of the inactive owner');
check($production('trendhome:9105')['production_stage_id'] === 'waiting' && $attention() === null, 'the order is actionable again in the same stage');

// ---- Source writeback is impossible ------------------------------------------------------------------
check($sourceState() === $sourcesBefore && $receipts() === $receiptsBefore + 0, 'interventions produced no source contact, event or receipt');
check($commerce('trendhome:9101') === $commerceBefore, 'commerce of the intervened order is still exactly the source state');
$ingest('9101', "th-9101-shipped-{$suffix}", 'delivery', 'shipped', 'active', 60);
$afterCommerce = $production('trendhome:9101');
check($afterCommerce['production_owner_employee_uuid'] === $cemId && $afterCommerce['production_stage_id'] === 'material-preparation', 'a later commerce update changes neither the owner nor the stage');

fwrite(STDOUT, "OK MySQL production ownership integration ({$checks} checks)\n");
