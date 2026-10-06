<?php

declare(strict_types=1);

// Production Exceptions & Live Approvals V1 against a dedicated MySQL/MariaDB test database.
// Covers the narrow operations manager role, exact order lookup, CEO-only organisation controls,
// root-only production policy, the strict blocking cutting fault flow (report, acknowledgment with
// QR, one manager decision, rejection, linked re-review, repeated errors, root cancel), line-based
// meter attribution, immutable quality facts, the scoped live event stream, idempotency and
// concurrency, and the invariants that commerce data, items and sources are never touched.

use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Application\Container;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL production exceptions integration: ARASYA_TEST_DB_NAME is not configured.\n");
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

/** @param array{status: int, body: array<string, mixed>|null} $response @return array<string, mixed> */
function checkOk(array $response, string $message, int $status = 200): array
{
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}

/** @return list<array{event: string, id: int|null, data: array<string, mixed>}> */
function sse(string $raw): array
{
    $frames = [];
    foreach (preg_split("/\n\n/", trim($raw)) ?: [] as $block) {
        $frame = ['event' => 'message', 'id' => null, 'data' => []];
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $frame['event'] = substr($line, 7);
            } elseif (str_starts_with($line, 'id: ')) {
                $frame['id'] = (int) substr($line, 4);
            } elseif (str_starts_with($line, 'data: ')) {
                $frame['data'] = json_decode(substr($line, 6), true) ?? [];
            }
        }
        if ($frame['event'] !== 'message' || $frame['data'] !== []) {
            $frames[] = $frame;
        }
    }
    return $frames;
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';

$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);

// ---- Migration 014: additive upgrade from 013, recorded once, grants only its new template ---------
require __DIR__ . '/HandoffSchemaFixture.php';
restorePreExceptionsTestSchema($pdo);
$grantsBefore = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC);
$activityBefore = (int) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn();
check((new MigrationRunner($pdo))->migrate($migrations) === ['014_production_exceptions.sql', '015_cutting_pool.sql','016_management_analytics.sql'], '013 -> 014 -> 015 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations) === [], '014 recorded exactly once');
check($pdo->query("SELECT rp.* FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id WHERE r.role_key NOT IN ('operations-manager','analytics-reader') ORDER BY rp.role_id, rp.permission_id")->fetchAll(PDO::FETCH_ASSOC) === $grantsBefore, '014 changes no existing role grant');
check((int) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn() === $activityBefore, '014 rewrites no activity history');
$opsPermissions = $pdo->query("SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = 'operations-manager' ORDER BY p.permission_key")->fetchAll(PDO::FETCH_COLUMN);
check($opsPermissions === ['orders.lookup_exact', 'production.exceptions.approve'], 'the operations-manager template carries exactly approval and exact lookup: ' . json_encode($opsPermissions));
check($pdo->query("SELECT reason_key FROM production_fault_reasons ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN) === ['wrong-cut', 'wrong-meterage', 'wrong-product', 'wrong-variant', 'material-defect', 'other'], 'six root-managed fault reasons are seeded');
check($pdo->query("SELECT approval_mode FROM production_exception_policy")->fetchColumn() === 'blocking', 'policy starts in strict blocking mode');
check($pdo->query('SELECT weekday, is_open, opens_at, closes_at FROM business_hours ORDER BY weekday')->fetchAll(PDO::FETCH_NUM) == [
    [1, 1, '05:00:00', '20:00:00'], [2, 1, '05:00:00', '20:00:00'], [3, 1, '05:00:00', '20:00:00'], [4, 1, '05:00:00', '20:00:00'],
    [5, 1, '05:00:00', '20:00:00'], [6, 1, '05:00:00', '20:00:00'], [7, 0, null, null],
], 'working hours start Monday-Saturday 05:00-20:00, Sunday closed');

$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
foreach (['live_events', 'production_exception_idempotency', 'production_quality_events', 'production_exception_events', 'production_exception_decisions', 'production_exception_lines'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET open_exception_uuid = NULL');
foreach (['production_exceptions', 'responsibility_assignments', 'organization_principals', 'order_activity_events', 'order_operation_idempotency', 'employee_order_relations', 'order_qr_references', 'operational_order_items', 'order_projection_receipts'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec("DELETE FROM operational_orders WHERE source_key <> 'b2b'");
$pdo->exec('DELETE FROM system_root_identity');

$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD_ORIGIN], $who['cookie'], $query);
$dash = static function (array $who, string $method, string $path, ?array $json = null, ?string $key = null) use ($kernel): array {
    $headers = ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($key !== null) {
        $headers['idempotency-key'] = $key;
    }
    return T::call($kernel, $method, $path, $json, $headers, $who['cookie']);
};
$staffGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $who['cookie'], $query);
$staffPost = static function (array $who, string $path, array $json, ?string $key) use ($kernel): array {
    $headers = ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($key !== null) {
        $headers['idempotency-key'] = $key;
    }
    return T::call($kernel, 'POST', $path, $json, $headers, $who['cookie']);
};
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string {
    $keys++;
    return sprintf('pex-%s-%s-%04d', $label, $suffix, $keys);
};

// ---- Identities ------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.owner.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-pex-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Exceptions root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Exceptions root passphrase 2026!');
$roleIds = [];
foreach (checkOk($get($root, '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
check(isset($roleIds['operations-manager']), 'operations-manager role exists');
$sinemRole = (int) checkOk($dash($root, 'POST', '/management/roles', ['name' => "Director Online {$suffix}", 'description' => null, 'authorityRank' => 800, 'permissions' => [
    'employees.view', 'employees.create', 'employees.update', 'employees.manage_roles', 'employees.manage_applications', 'roles.view', 'roles.create', 'roles.update', 'roles.assign',
    'departments.view', 'orders.view_all', 'production.view', 'activity.view_all', 'iam.audit.view',
]]), 'Director Online role', 201)['role']['id'];

$password = 'exceptions passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $dash, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    checkOk($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), "{$name} applications");
    checkOk($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return $employee->employeeUuid;
};
$cutterAId = $identity('cutter-a', ['material-preparation'], ['staff'], []);
$cutterBId = $identity('cutter-b', ['material-preparation'], ['staff'], []);
$tailorId = $identity('tailor', ['workshop-receiving'], ['staff'], []);
$tailor2Id = $identity('tailor2', ['workshop-receiving'], ['staff'], []);
$denisaId = $identity('denisa', [], ['dashboard'], [$roleIds['operations-manager']]);
$hikmetId = $identity('hikmet', [], ['dashboard'], [$roleIds['operations-manager']]);
$sinemId = $identity('sinem', [], ['dashboard'], [$sinemRole]);
$mesutId = $identity('mesut', [], ['dashboard'], [$roleIds['ceo']]);
$backupId = $identity('backup', [], ['dashboard'], []);
$cutterA = $login("cutter-a.{$suffix}", $password);
$cutterB = $login("cutter-b.{$suffix}", $password);
$tailor = $login("tailor.{$suffix}", $password);
$tailor2 = $login("tailor2.{$suffix}", $password);
$denisa = $login("denisa.{$suffix}", $password);
$hikmet = $login("hikmet.{$suffix}", $password);
$sinem = $login("sinem.{$suffix}", $password);
$mesut = $login("mesut.{$suffix}", $password);
$backup = $login("backup.{$suffix}", $password);

// ---- Operations managers: one identical narrow role ------------------------------------------------
$denisaMe = checkOk($get($denisa, '/management/me'), 'Denisa me');
$hikmetMe = checkOk($get($hikmet, '/management/me'), 'Hikmet me');
check($denisaMe['permissions'] === $hikmetMe['permissions'] && $denisaMe['authorityRank'] === $hikmetMe['authorityRank'], 'Denisa and Hikmet have exactly the same permissions and rank');
check($denisaMe['permissions'] === ['dashboard.access', 'dashboard.overview.view', 'orders.lookup_exact', 'production.exceptions.approve', 'profile.view_self'], 'the operations manager permission set is narrow: ' . json_encode($denisaMe['permissions']));
check($denisaMe['capabilities'] === ['approveExceptions' => true, 'approvalViaBackup' => false, 'lookupOrders' => true, 'manageOrganization' => false, 'manageProductionSettings' => false, 'cancelExceptions' => false, 'viewAnalytics' => false, 'manageAnalyticsPolicy' => false], 'operations manager capabilities remain narrow, including no analytics');
foreach (['/management/employees', '/management/roles', '/management/permissions', '/management/departments', '/management/applications', '/management/audit', '/management/system', '/management/orders', '/management/production-overview', '/management/organization', '/management/production-settings', "/management/employees/{$cutterAId}"] as $path) {
    checkError($get($denisa, $path), 403, $path === '/management/production-settings' ? 'ROOT_ONLY' : 'UNAUTHORIZED_ACTION', "operations manager is denied {$path}");
}
check(checkOk($get($denisa, '/management/dashboard'), 'overview')['counts'] === null, 'operations manager receives no company-wide counts');
check(is_array(checkOk($get($root, '/management/dashboard'), 'root overview')['counts']), 'root still receives counts');
checkError($dash($denisa, 'PUT', "/management/employees/{$denisaId}/roles", ['roleIds' => [$roleIds['ceo']]]), 403, 'UNAUTHORIZED_ACTION', 'operations manager cannot escalate itself');
checkError($dash($denisa, 'PUT', "/management/employees/{$hikmetId}/roles", ['roleIds' => []]), 403, 'UNAUTHORIZED_ACTION', 'operations manager cannot change the other manager');
checkError($dash($denisa, 'PATCH', "/management/roles/{$roleIds['operations-manager']}", ['permissions' => ['production.exceptions.approve', 'orders.lookup_exact', 'employees.view']]), 403, 'UNAUTHORIZED_ACTION', 'operations manager cannot widen its role');
checkError($dash($denisa, 'POST', '/management/organization/responsibilities', ['responsibility' => 'operations_backup_approver', 'employeeId' => $backupId, 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], $key('ops-backup')), 403, 'UNAUTHORIZED_ACTION', 'operations managers cannot appoint their own backup');
check(checkOk($get($sinem, '/management/me'), 'Sinem me')['capabilities']['approveExceptions'] === false, 'Sinem is not in the approval pool');

// ---- Orders (source-neutral: Trendhome and OutletPerdele) ----------------------------------------
$changedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
$line = static fn (int $id, int $n, string $name, string $meters): array => ['id' => $id, 'line' => $n, 'name' => $name, 'sku' => "SKU-{$n}", 'color' => 'Ivory', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => (float) $meters, 'quantity' => 2];
$fourLines = [$line(1, 1, 'Voal A', '5'), $line(2, 2, 'Voal B', '7'), $line(3, 3, 'Draperie C', '8'), $line(4, 4, 'Draperie D', '9')];
$ingest = static function (string $source, string $id, string $number) use ($kernel, $changedAt, $fourLines, $suffix): void {
    $response = T::ingest($kernel, $source, T::sourceOrder($id, "{$source}-{$id}-{$suffix}", $changedAt, T::stage('material-preparation'), 'processing', 'active', $fourLines, $number));
    check(($response['body']['outcome'] ?? null) === 'applied', "ingest {$source}:{$id}");
};
$ingest('trendhome', '12', '12');
$ingest('outletperdele', '12', '12');
$ingest('trendhome', '13', '13');
$ingest('trendhome', '14', '14');

$order = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT order_uuid, production_stage_id, production_owner_employee_uuid, production_version, open_exception_uuid, production_claimed_at, source_commerce_status_code, source_commerce_status_label, source_event_id, projection_hash FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$items = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT i.item_uuid, i.line_number, i.meters, i.quantity FROM operational_order_items i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = ? ORDER BY i.line_number');
    $statement->execute([$globalId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$qr = static function (string $globalId) use ($pdo): string {
    $statement = $pdo->prepare("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'");
    $statement->execute([$globalId]);
    return 'ARASYA:Q1:' . $statement->fetchColumn();
};
$step = static function (array $who, string $globalId, string $action, string $label) use ($staffPost, $order, $key, $pdo): array {
    $version = (int) $order($globalId)['production_version'];
    $input = $action === 'claim' ? T::cuttingClaim($pdo, $globalId, $who['employeeUuid'], $version) : ['expectedVersion' => $version];
    return checkOk($staffPost($who, '/orders/' . rawurlencode($globalId) . "/{$action}", $input, $key($label)), "{$label} {$action} {$globalId}");
};
$cut = static function (array $cutter, array $intake, string $globalId) use ($step): void {
    $step($cutter, $globalId, 'claim', 'cut');
    $step($cutter, $globalId, 'transition', 'cut');
    $step($intake, $globalId, 'claim', 'intake');
};
$report = static fn (array $who, string $globalId, array $itemIds, string $reason, ?string $comment, ?string $idem, ?int $version = null) => $staffPost($who, '/orders/' . rawurlencode($globalId) . '/fault-reports', array_filter(['expectedVersion' => $version ?? (int) $order($globalId)['production_version'], 'itemIds' => $itemIds, 'reasonKey' => $reason, 'comment' => $comment], static fn ($v) => $v !== null), $idem);
$exceptionVersion = static fn (string $id): int => (int) $pdo->query("SELECT version FROM production_exceptions WHERE exception_uuid = " . $pdo->quote($id))->fetchColumn();
$ack = static fn (array $who, string $id, string $token, string $idem, ?int $version = null, bool $confirmed = true) => $staffPost($who, "/production-exceptions/{$id}/acknowledge", ['expectedVersion' => $version ?? $exceptionVersion($id), 'confirmed' => $confirmed, 'qrToken' => $token, 'comment' => 'Confirm că eroarea îmi aparține și refac lucrarea.'], $idem);
$decide = static fn (array $who, string $id, string $decision, ?string $comment, string $idem, ?int $version = null) => $dash($who, 'POST', "/management/production-exceptions/{$id}/decision", array_filter(['expectedVersion' => $version ?? $exceptionVersion($id), 'decision' => $decision, 'comment' => $comment], static fn ($v) => $v !== null), $idem);
$streamCursor = static function (array $who) use ($get): int {
    $response = $get($who, '/live/events');
    check($response['status'] === 200 && str_starts_with($response['headers']['Content-Type'] ?? '', 'text/event-stream'), 'live stream answers text/event-stream');
    $frames = sse((string) $response['raw']);
    check(($frames[0]['event'] ?? null) === 'ready', 'a stream without cursor only reports where to start');
    return (int) $frames[0]['data']['cursor'];
};
$streamAfter = static function (array $who, int $cursor) use ($get): array {
    $response = $get($who, '/live/events', ['after' => (string) $cursor]);
    check($response['status'] === 200, 'live stream after cursor');
    return sse((string) $response['raw']);
};
$types = static fn (array $frames): array => array_values(array_map(static fn (array $f): string => $f['event'], array_filter($frames, static fn (array $f): bool => $f['id'] !== null)));

// ---- Exact order lookup ---------------------------------------------------------------------------
$lookup = checkOk($get($denisa, '/management/order-lookup', ['number' => '#12']), 'exact lookup 12');
check(array_column($lookup['items'], 'id') === ['outletperdele:12', 'trendhome:12'], 'the same number in two sources returns exactly the two collisions');
check(checkOk($get($denisa, '/management/order-lookup', ['number' => '1']), 'prefix lookup')['items'] === [], 'a prefix never enumerates orders');
checkError($get($denisa, '/management/order-lookup', ['number' => '']), 400, 'INVALID_LOOKUP_CODE', 'an empty lookup is rejected');
checkError($get($denisa, '/management/order-lookup', ['number' => '1%']), 400, 'INVALID_LOOKUP_CODE', 'wildcards are rejected');
check(array_column(checkOk($get($denisa, '/management/order-lookup', ['number' => 'trendhome:13']), 'global id lookup')['items'], 'id') === ['trendhome:13'], 'a global id finds exactly one order');
$lookupDetail = checkOk($get($denisa, '/management/order-lookup/' . rawurlencode('trendhome:12')), 'exact lookup detail');
check(count($lookupDetail['order']['items']) === 4 && $lookupDetail['order']['items'][3]['meters'] === '9.000' && $lookupDetail['exceptions'] === [], 'lookup detail shows lines and exact meters, no customer data');
checkError($get($tailor, '/management/order-lookup', ['number' => '12']), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff-only employee cannot use Dashboard lookup');
checkError($get($backup, '/management/order-lookup', ['number' => '12']), 403, 'UNAUTHORIZED_ACTION', 'Dashboard access alone does not allow lookup');

// ---- Organisation: Root designates the CEO; only Root/CEO change hours and responsibilities -------
checkError($dash($mesut, 'PUT', '/management/organization/ceo', ['employeeId' => $mesutId], $key('ceo-self')), 403, 'ROOT_ONLY', 'the CEO cannot designate itself');
checkError($get($mesut, '/management/organization'), 403, 'UNAUTHORIZED_ACTION', 'before designation Mesut has no organisation controls');
$organization = checkOk($dash($root, 'PUT', '/management/organization/ceo', ['employeeId' => $mesutId], $key('ceo')), 'root designates the CEO');
check($organization['ceo']['id'] === $mesutId, 'Mesut is the CEO principal');
check(checkOk($get($mesut, '/management/me'), 'Mesut me')['capabilities']['manageOrganization'] === true, 'the CEO may manage organisation controls');
foreach ([$sinem, $denisa, $hikmet] as $who) {
    checkError($get($who, '/management/organization'), 403, 'UNAUTHORIZED_ACTION', 'Sinem and operations managers cannot see organisation controls');
}
$hours = [['weekday' => 1, 'isOpen' => true, 'opensAt' => '05:00', 'closesAt' => '20:00'], ['weekday' => 2, 'isOpen' => true, 'opensAt' => '05:00', 'closesAt' => '20:00'],
    ['weekday' => 3, 'isOpen' => true, 'opensAt' => '05:00', 'closesAt' => '20:00'], ['weekday' => 4, 'isOpen' => true, 'opensAt' => '05:00', 'closesAt' => '20:00'],
    ['weekday' => 5, 'isOpen' => true, 'opensAt' => '05:00', 'closesAt' => '20:00'], ['weekday' => 6, 'isOpen' => true, 'opensAt' => '06:00', 'closesAt' => '14:00'],
    ['weekday' => 7, 'isOpen' => false, 'opensAt' => null, 'closesAt' => null]];
checkError($dash($sinem, 'PUT', '/management/organization/working-hours', ['days' => $hours], $key('hours-sinem')), 403, 'UNAUTHORIZED_ACTION', 'Sinem cannot change working hours');
checkError($dash($denisa, 'PUT', '/management/organization/working-hours', ['days' => $hours], $key('hours-ops')), 403, 'UNAUTHORIZED_ACTION', 'operations managers cannot change working hours');
$hoursKey = $key('hours');
$changed = checkOk($dash($mesut, 'PUT', '/management/organization/working-hours', ['days' => $hours], $hoursKey), 'the CEO changes working hours');
check($changed['workingHours'][5] === ['weekday' => 6, 'isOpen' => true, 'opensAt' => '06:00', 'closesAt' => '14:00'], 'Saturday hours changed');
check(checkOk($dash($mesut, 'PUT', '/management/organization/working-hours', ['days' => $hours], $hoursKey), 'replay')['workingHours'] === $changed['workingHours'], 'working hours replay');
checkOk($dash($mesut, 'PUT', '/management/organization/working-hours', ['days' => array_reverse($hours)], $hoursKey), 'reordered days are the same intent and replay');
$otherHours = $hours;
$otherHours[6] = ['weekday' => 7, 'isOpen' => true, 'opensAt' => '08:00', 'closesAt' => '12:00'];
checkError($dash($mesut, 'PUT', '/management/organization/working-hours', ['days' => $otherHours], $hoursKey), 409, 'IDEMPOTENCY_CONFLICT', 'same key with a different working-hours intent');
check((int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'organization.working_hours_changed' AND actor_employee_uuid = " . $pdo->quote($mesutId))->fetchColumn() === 1, 'one audited working-hours change');

// Tailoring intake responsible: CEO only, one at a time, every change audited.
checkError($dash($sinem, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_intake_responsible', 'employeeId' => $tailorId], $key('tir-sinem')), 403, 'UNAUTHORIZED_ACTION', 'Sinem cannot appoint the tailoring intake responsible');
checkOk($dash($mesut, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_intake_responsible', 'employeeId' => $tailorId], $key('tir-1')), 'the CEO appoints the tailoring intake responsible', 201);
$replaced = checkOk($dash($mesut, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_intake_responsible', 'employeeId' => $tailor2Id, 'note' => 'Schimb de tură'], $key('tir-2')), 'the CEO changes it', 201);
$tir = array_values(array_filter($replaced['responsibilities'], static fn (array $r): bool => $r['responsibility'] === 'tailoring_intake_responsible'));
check(count($tir) === 2 && $tir[0]['employee']['id'] === $tailor2Id && $tir[0]['state'] === 'active' && $tir[1]['state'] === 'revoked', 'exactly one tailoring intake responsible is active; the previous one is kept as revoked history');
checkOk($dash($mesut, 'POST', "/management/organization/responsibilities/{$tir[0]['id']}/revoke", ['reason' => 'Test'], $key('tir-revoke')), 'the CEO removes it');
checkError($dash($mesut, 'POST', "/management/organization/responsibilities/{$tir[0]['id']}/revoke", ['reason' => 'Test'], $key('tir-revoke2')), 409, 'ASSIGNMENT_ALREADY_ENDED', 'an ended assignment cannot be revoked twice');
check((int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action IN ('responsibility.assigned', 'responsibility.revoked') AND actor_employee_uuid = " . $pdo->quote($mesutId))->fetchColumn() === 3, 'every assignment change is audited');

// ---- Root-only production policy -----------------------------------------------------------------
foreach ([$mesut, $sinem, $denisa] as $who) {
    checkError($get($who, '/management/production-settings'), 403, 'ROOT_ONLY', 'only root sees production policy');
}
checkError($dash($mesut, 'PATCH', '/management/production-settings/reasons/wrong-cut', ['label' => 'X y'], $key('reason-ceo')), 403, 'ROOT_ONLY', 'the CEO cannot edit fault reasons');
checkError($dash($root, 'PATCH', '/management/production-settings/reasons/other', ['requiresComment' => false], $key('reason-other')), 422, 'COMMENT_REQUIRED_FOR_OTHER', '"Alt motiv" always requires a comment');
checkOk($dash($root, 'POST', '/management/production-settings/reasons', ['key' => 'wrong-direction', 'label' => 'Sens material greșit', 'requiresComment' => false, 'sortOrder' => 60], $key('reason-new')), 'root adds a reason', 201);
checkOk($dash($root, 'PATCH', '/management/production-settings/reasons/wrong-direction', ['status' => 'inactive'], $key('reason-off')), 'root deactivates it');
$reasons = array_column(checkOk($staffGet($tailor, '/production-exceptions/reasons'), 'Staff reasons')['items'], 'key');
check(!in_array('wrong-direction', $reasons, true) && in_array('other', $reasons, true), 'Staff sees only active reasons');
checkError($dash($mesut, 'PATCH', '/management/production-settings/stages/material-preparation', ['label' => 'Tăiere'], $key('stage-ceo')), 403, 'ROOT_ONLY', 'the CEO cannot rename stages');
checkOk($dash($root, 'PATCH', '/management/production-settings/stages/material-preparation', ['label' => 'Tăiere'], $key('stage-2')), 'root renames stage 2 for display');
checkOk($dash($root, 'PATCH', '/management/production-settings/stages/workshop-receiving', ['label' => 'Primire Croitorie'], $key('stage-3')), 'root renames stage 3 for display');
$workflow = checkOk($staffGet($tailor, '/production/workflow'), 'workflow after rename');
check($workflow['stages'][1]['id'] === 'material-preparation' && $workflow['stages'][1]['label'] === 'Tăiere' && $workflow['stages'][2]['id'] === 'workshop-receiving' && $workflow['stages'][2]['label'] === 'Primire Croitorie', 'labels change, stable ids and ordinals do not');

// ---- Core flow: order #12 (Trendhome), four lines 5/7/8/9 m ------------------------------------------
$th12 = 'trendhome:12';
$lines12 = $items($th12);
[$a12, $b12, $c12, $d12] = array_column($lines12, 'item_uuid');
$commerceBefore = array_intersect_key($order($th12), array_flip(['source_commerce_status_code', 'source_commerce_status_label', 'source_event_id', 'projection_hash']));
$receiptsBefore = (int) $pdo->query('SELECT COUNT(*) FROM order_projection_receipts')->fetchColumn();
$sourcesBefore = $pdo->query('SELECT source_key, last_contact_at, last_event_at, sync_cursor_at FROM order_sources ORDER BY source_key')->fetchAll(PDO::FETCH_ASSOC);
$cursorA = $streamCursor($cutterA);
$cursorT2 = $streamCursor($tailor2);
$cursorDenisa = $streamCursor($denisa);
$cursorHikmet = $streamCursor($hikmet);
checkError(T::call($kernel, 'GET', '/live/events'), 401, 'SESSION_EXPIRED', 'the live stream requires a session');

$step($cutterA, $th12, 'claim', 'cut-a');
// The cutting employee cannot report a self-made mistake before handoff, and nobody can at stage 2.
checkError($report($cutterA, $th12, [$d12], 'wrong-cut', null, $key('self')), 409, 'EXCEPTION_STAGE_INVALID', 'no return request exists at the cutting stage');
$step($cutterA, $th12, 'transition', 'cut-a');
check($order($th12)['production_stage_id'] === 'workshop-receiving', 'cutter A handed the whole order to tailoring intake');
checkError($report($tailor, $th12, [$d12], 'wrong-cut', null, $key('unclaimed')), 403, 'FAULT_REPORT_NOT_ALLOWED', 'the intake employee must accept the order first');
$step($tailor, $th12, 'claim', 'intake');
checkError($report($cutterA, $th12, [$d12], 'wrong-cut', null, $key('cutter-report')), 403, 'FAULT_REPORT_NOT_ALLOWED', 'the cutting employee cannot initiate a rollback');
checkError($report($tailor2, $th12, [$d12], 'wrong-cut', null, $key('other-intake')), 403, 'FAULT_REPORT_NOT_ALLOWED', 'only the intake employee who accepted the order can report');
checkError($report($tailor, $th12, [$d12], 'other', null, $key('other-no-comment')), 422, 'COMMENT_REQUIRED', '"Alt motiv" needs a comment');
checkError($report($tailor, $th12, [$d12], 'wrong-direction', null, $key('inactive-reason')), 422, 'REASON_INVALID', 'an inactive reason cannot be used');
checkError($report($tailor, $th12, [$items('trendhome:13')[0]['item_uuid']], 'wrong-cut', null, $key('foreign-line')), 422, 'FAULT_LINES_INVALID', 'lines of another order are rejected');
checkError($report($tailor, $th12, [$d12, $d12], 'wrong-cut', null, $key('dup-line')), 400, 'INVALID_REQUEST', 'a line cannot be selected twice');
checkError($report($tailor, $th12, [], 'wrong-cut', null, $key('no-line')), 400, 'INVALID_REQUEST', 'at least one line is required');
checkError($report($tailor, $th12, [$d12], 'wrong-cut', null, $key('stale'), 1), 409, 'ORDER_CHANGED', 'a stale production version is rejected');
checkError($staffPost($tailor, '/orders/' . rawurlencode($th12) . '/fault-reports', ['expectedVersion' => (int) $order($th12)['production_version'], 'itemIds' => [$d12], 'reasonKey' => 'wrong-cut', 'responsibleEmployeeId' => $cutterBId], $key('extra')), 400, 'INVALID_REQUEST', 'the responsible employee can never be chosen by the detector');
checkError($report($tailor, $th12, [$d12], 'wrong-cut', null, null), 400, 'INVALID_IDEMPOTENCY_KEY', 'an idempotency key is required');
check($pdo->query('SELECT COUNT(*) FROM production_exceptions')->fetchColumn() == 0, 'no rejected report wrote anything');

$versionBeforeReport = (int) $order($th12)['production_version'];
$reportKey = $key('report-12');
$created = $report($tailor, $th12, [$d12, $c12], 'wrong-cut', 'Margini tăiate strâmb', $reportKey);
$exception = checkOk($created, 'tailor reports C + D', 201);
$x1 = $exception['id'];
check($exception['status'] === 'awaiting_acknowledgment' && $exception['lineCount'] === 2 && $exception['faultMeters'] === '17.000', 'fault scope is lines C + D = 17 m, never the whole 29 m');
check($exception['responsible']['displayName'] === 'Test cutter-a' && $exception['role'] === 'detector' && $exception['arrivalNumber'] === 1, 'the responsible employee is derived from the 2 -> 3 handoff');
check(array_column($exception['lines'], 'meters') === ['8.000', '9.000'] && $exception['reason']['label'] === 'Tăiere greșită', 'line snapshots and reason snapshot');
check($report($tailor, $th12, [$c12, $d12], 'wrong-cut', 'Margini tăiate strâmb', $reportKey, $versionBeforeReport)['body'] === $exception, 'same key and same intent replays the committed result');
checkError($report($tailor, $th12, [$d12], 'wrong-cut', 'Margini tăiate strâmb', $reportKey, $versionBeforeReport), 409, 'IDEMPOTENCY_CONFLICT', 'same key with a different intent');
checkError($report($tailor, $th12, [$a12], 'wrong-cut', null, $key('second-open')), 409, 'EXCEPTION_ALREADY_OPEN', 'one open request per order');
check((int) $pdo->query('SELECT COUNT(*) FROM production_exceptions')->fetchColumn() === 1, 'replays created nothing');

$blocked = $order($th12);
check($blocked['production_stage_id'] === 'workshop-receiving' && $blocked['open_exception_uuid'] === $x1 && (int) $blocked['production_version'] === $versionBeforeReport + 1 && $blocked['production_owner_employee_uuid'] === $tailorId, 'the order stays at stage 3, owned by the detector, blocked');
checkError($staffPost($tailor, '/orders/' . rawurlencode($th12) . '/transition', ['expectedVersion' => (int) $blocked['production_version']], $key('blocked')), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'the normal forward flow is blocked while the request is open');
checkError($dash($root, 'POST', '/management/orders/' . rawurlencode($th12) . '/release-owner', ['expectedVersion' => (int) $blocked['production_version']], $key('release')), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'owner interventions are blocked while the request is open');
$staffView = checkOk($staffGet($tailor, '/orders/' . rawurlencode($th12)), 'Staff order view');
check(($staffView['employeeActionBlockedReason'] ?? null) === 'exception_pending' && $staffView['productionQuality']['openException']['id'] === $x1 && $staffView['productionQuality']['arrivalNumber'] === 1, 'Staff sees the order blocked by the open request');

// Live: the responsible employee is told immediately; an uninvolved employee is not; approvers see the waiting request.
$framesA = $streamAfter($cutterA, $cursorA);
check(in_array('exception.acknowledgment_required', $types($framesA), true), 'cutter A receives the acknowledgment request live');
check($types($streamAfter($tailor2, $cursorT2)) === [], 'an uninvolved employee receives nothing');
check(in_array('exception.waiting_worker', $types($streamAfter($denisa, $cursorDenisa)), true), 'operations managers see the request waiting for the worker');
$cursorA = (int) end($framesA)['data']['cursor'];
check($types($streamAfter($cutterA, $cursorA)) === [], 'reconnecting with the last cursor never repeats events');
checkError($staffGet($tailor2, "/production-exceptions/{$x1}"), 404, 'EXCEPTION_NOT_FOUND', 'uninvolved employees cannot read the request');
check(checkOk($staffGet($cutterA, "/production-exceptions/{$x1}"), 'cutter detail')['actions'] === ['canAcknowledge' => true, 'canRequestRereview' => false], 'the responsible employee may acknowledge');
check(array_column(checkOk($staffGet($cutterA, '/production-exceptions/mine'), 'cutter list')['items'], 'id') === [$x1], 'the request is in the responsible employee list');

// Acknowledgment: only the responsible employee, explicit confirmation, QR of the same order.
checkError($ack($cutterB, $x1, $qr($th12), $key('ack-b')), 403, 'EXCEPTION_NOT_ASSIGNED', 'another cutting employee cannot acknowledge');
checkError($ack($tailor, $x1, $qr($th12), $key('ack-t')), 403, 'EXCEPTION_NOT_ASSIGNED', 'the detector cannot acknowledge');
checkError($ack($cutterA, $x1, $qr($th12), $key('ack-nc'), null, false), 400, 'CONFIRMATION_REQUIRED', 'explicit confirmation is required');
checkError($ack($cutterA, $x1, $qr('trendhome:13'), $key('ack-wrong')), 422, 'QR_ORDER_MISMATCH', 'the QR of another order is rejected');
checkError($ack($cutterA, $x1, 'ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA', $key('ack-unknown')), 422, 'INVALID_QR', 'an unknown QR is rejected');
checkError($ack($cutterA, $x1, 'not a code', $key('ack-garbage')), 422, 'INVALID_QR', 'a non-Arasya code is rejected');
checkError($ack($cutterA, $x1, $qr($th12), $key('ack-stale'), 7), 409, 'EXCEPTION_CHANGED', 'a stale request version is rejected');
$ackKey = $key('ack-a');
$acknowledged = checkOk($ack($cutterA, $x1, $qr($th12), $ackKey, 1), 'cutter A acknowledges with the order QR');
check($acknowledged['status'] === 'awaiting_approval' && $acknowledged['qrVerifiedAt'] !== null && $acknowledged['decisions'][0]['status'] === 'pending', 'the request waits for a manager');
check($ack($cutterA, $x1, $qr($th12), $ackKey, 1)['body'] === $acknowledged, 'a double acknowledgment with the same key replays');
checkError($ack($cutterA, $x1, $qr($th12), $key('ack-again')), 409, 'EXCEPTION_STATE_INVALID', 'a second acknowledgment changes nothing');
check($order($th12)['production_stage_id'] === 'workshop-receiving' && $order($th12)['open_exception_uuid'] === $x1, 'the order is still blocked at stage 3 while approval is pending');
checkError($staffPost($cutterA, '/orders/' . rawurlencode($th12) . '/claim', ['expectedVersion' => (int) $order($th12)['production_version']], $key('early-rework')), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'the cutting employee cannot start rework before approval');

// Manager pool: one approval is enough, the first valid decision wins.
foreach ([$sinem, $mesut, $backup] as $who) {
    checkError($get($who, '/management/production-exceptions'), 403, 'UNAUTHORIZED_ACTION', 'Sinem, the CEO and a plain Dashboard user are not approvers');
}
checkError($decide($sinem, $x1, 'approve', null, $key('sinem')), 403, 'UNAUTHORIZED_ACTION', 'Sinem cannot decide');
check(array_column(checkOk($get($denisa, '/management/production-exceptions', ['view' => 'pending']), 'Denisa pending')['items'], 'id') === [$x1], 'Denisa sees the pending request');
check(array_column(checkOk($get($hikmet, '/management/production-exceptions', ['view' => 'pending']), 'Hikmet pending')['items'], 'id') === [$x1], 'Hikmet sees the same request');
check(in_array('exception.approval_pending', $types($streamAfter($hikmet, $cursorHikmet)), true), 'approval pending reaches the managers live');
$cursorHikmet = $streamCursor($hikmet);
checkError($decide($denisa, $x1, 'reject', null, $key('no-comment')), 422, 'COMMENT_REQUIRED', 'a rejection needs a reason');
$expected = $exceptionVersion($x1);
$approveKey = $key('approve-12');
$approved = checkOk($decide($denisa, $x1, 'approve', null, $approveKey, $expected), 'Denisa approves');
checkError($decide($hikmet, $x1, 'reject', 'Nu este eroare', $key('late'), $expected), 409, 'EXCEPTION_ALREADY_RESOLVED', 'the second manager cannot resolve the request again');
check(in_array('exception.resolved', $types($streamAfter($hikmet, $cursorHikmet)), true), 'the other manager screen is told the request was resolved');
check(checkOk($get($hikmet, '/management/production-exceptions', ['view' => 'pending']), 'pending after')['items'] === [], 'the request left the shared queue');
check($approved['status'] === 'approved' && $approved['reworkCycle'] === 1 && $approved['decisions'][0]['decidedBy'] === 'Test denisa' && $approved['decisions'][0]['decidedVia'] === 'operations_manager', 'approved by exactly one manager');
check(is_int($approved['decisions'][0]['waitSeconds']), 'manager waiting time is recorded separately');
$returned = $order($th12);
check($returned['production_stage_id'] === 'material-preparation' && $returned['production_owner_employee_uuid'] === $cutterAId && $returned['open_exception_uuid'] === null, 'approval moved 3 -> 2 once and returned the work to cutter A');
check((int) $returned['production_version'] === $versionBeforeReport + 2, 'approval incremented the production version once');
$events = $pdo->query("SELECT action, employee_uuid, from_stage_id, to_stage_id, previous_owner_employee_uuid, new_owner_employee_uuid, meters_snapshot FROM order_activity_events WHERE order_uuid = " . $pdo->quote($returned['order_uuid']) . ' ORDER BY production_version_after')->fetchAll(PDO::FETCH_ASSOC);
check(array_column($events, 'action') === ['claimed', 'stage_completed', 'claimed', 'fault_reported', 'fault_returned'], 'history is appended, never rewritten: ' . json_encode(array_column($events, 'action')));
check($events[4]['employee_uuid'] === $denisaId && $events[4]['from_stage_id'] === 'workshop-receiving' && $events[4]['to_stage_id'] === 'material-preparation' && $events[4]['previous_owner_employee_uuid'] === $tailorId && $events[4]['new_owner_employee_uuid'] === $cutterAId && $events[4]['meters_snapshot'] === '17.000', 'the approved exception transition is attributable');
$quality = $pdo->query('SELECT event_type, employee_uuid, rework_cycle, is_repeat, line_count, meters FROM production_quality_events ORDER BY event_type')->fetchAll(PDO::FETCH_ASSOC);
check($quality == [
    ['event_type' => 'cutting_fault', 'employee_uuid' => $cutterAId, 'rework_cycle' => 1, 'is_repeat' => 0, 'line_count' => 2, 'meters' => '17.000'],
    ['event_type' => 'fault_detected', 'employee_uuid' => $tailorId, 'rework_cycle' => 1, 'is_repeat' => 0, 'line_count' => 2, 'meters' => '17.000'],
], 'fault meters for the cutter and detection meters for the detector are two independent facts: ' . json_encode($quality));
check($decide($denisa, $x1, 'approve', null, $approveKey, $expected)['body'] === $approved && (int) $pdo->query('SELECT COUNT(*) FROM production_quality_events')->fetchColumn() === 2, 'a replayed approval writes the facts only once');
check(in_array('exception.approved', $types($streamAfter($cutterA, $cursorA)), true), 'cutter A sees the approval live');
check((int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production.exception.approved' AND actor_employee_uuid = " . $pdo->quote($denisaId))->fetchColumn() === 1, 'the decision is in the privileged audit');
check((string) $returned['production_claimed_at'] > (string) $pdo->query("SELECT reported_at FROM production_exceptions WHERE exception_uuid = " . $pdo->quote($x1))->fetchColumn(), 'rework time starts at approval, not at the report: waiting is never active work');

// ---- Second arrival, repeated error, rejection, linked re-review ---------------------------------
$step($cutterA, $th12, 'transition', 'rework');
$step($tailor, $th12, 'claim', 'intake-2');
$second = checkOk($staffGet($tailor, '/orders/' . rawurlencode($th12)), 'second arrival view')['productionQuality'];
check($second['arrivalNumber'] === 2 && $second['reworkCycles'] === 1 && $second['repeatedErrors'] === false, 'tailoring intake sees the second arrival after revision');
$x2 = checkOk($report($tailor, $th12, [$d12], 'wrong-meterage', null, $key('report-12b')), 'line D is wrong again', 201)['id'];
check($x2 !== $x1, 'a repeated error is a new request, never merged');
check(checkOk($staffGet($tailor, "/production-exceptions/{$x2}"), 'x2')['faultMeters'] === '9.000', 'meters are calculated again from the newly selected line');
checkOk($ack($cutterA, $x2, $qr($th12), $key('ack-12b')), 'cutter A acknowledges again');
$cursorA = $streamCursor($cutterA);
$rejected = checkOk($decide($hikmet, $x2, 'reject', 'Metrajul corespunde comenzii', $key('reject-12b')), 'Hikmet rejects');
check($rejected['status'] === 'rejected' && $rejected['decisions'][0]['comment'] === 'Metrajul corespunde comenzii', 'the rejection reason is kept');
check($order($th12)['production_stage_id'] === 'workshop-receiving' && $order($th12)['open_exception_uuid'] === null, 'after rejection stage 3 stays current and the order is unblocked');
check(in_array('exception.rejected', $types($streamAfter($cutterA, $cursorA)), true), 'the rejection reaches the worker live');
checkError($staffPost($cutterA, "/production-exceptions/{$x2}/rereview", ['expectedVersion' => $exceptionVersion($x2), 'comment' => ''], $key('rr-empty')), 422, 'COMMENT_REQUIRED', 're-review needs a comment');
checkError($staffPost($tailor2, "/production-exceptions/{$x2}/rereview", ['expectedVersion' => $exceptionVersion($x2), 'comment' => 'Vă rog'], $key('rr-other')), 403, 'EXCEPTION_NOT_ASSIGNED', 'an uninvolved employee cannot ask for re-review');
$rereview = checkOk($staffPost($cutterA, "/production-exceptions/{$x2}/rereview", ['expectedVersion' => $exceptionVersion($x2), 'comment' => 'Am verificat din nou: lungimea este greșită.'], $key('rr')), 're-review requested');
check($rereview['status'] === 'awaiting_approval' && array_column($rereview['decisions'], 'status') === ['rejected', 'pending'] && $rereview['decisions'][1]['openedReason'] === 'rereview', 'the rejection stays; a linked second attempt is pending');
$link = $pdo->query("SELECT d2.previous_decision_uuid = d1.decision_uuid FROM production_exception_decisions d1 JOIN production_exception_decisions d2 ON d2.exception_uuid = d1.exception_uuid AND d2.attempt_number = 2 WHERE d1.exception_uuid = " . $pdo->quote($x2) . ' AND d1.attempt_number = 1')->fetchColumn();
check((int) $link === 1, 'attempt 2 links to the rejected attempt 1');
check($order($th12)['open_exception_uuid'] === $x2, 'the order is blocked again during the re-review');
$final = checkOk($decide($denisa, $x2, 'approve', 'Reanalizat', $key('approve-12b')), 'Denisa approves the second attempt');
check($final['reworkCycle'] === 2 && $final['repeatedError'] === true && array_column($final['decisions'], 'status') === ['rejected', 'approved'], 'second rework cycle, flagged as repeated');
$repeat = $pdo->query("SELECT event_type, rework_cycle, is_repeat, meters FROM production_quality_events WHERE exception_uuid = " . $pdo->quote($x2) . ' ORDER BY event_type')->fetchAll(PDO::FETCH_ASSOC);
check($repeat == [['event_type' => 'cutting_fault', 'rework_cycle' => 2, 'is_repeat' => 1, 'meters' => '9.000'], ['event_type' => 'fault_detected', 'rework_cycle' => 2, 'is_repeat' => 1, 'meters' => '9.000']], 'the repeated error has its own fault and detection facts');
$totals = $pdo->query("SELECT event_type, employee_uuid, COUNT(*) AS faults, SUM(line_count) AS line_total, SUM(meters) AS meters FROM production_quality_events GROUP BY event_type, employee_uuid ORDER BY event_type")->fetchAll(PDO::FETCH_ASSOC);
check($totals == [
    ['event_type' => 'cutting_fault', 'employee_uuid' => $cutterAId, 'faults' => 2, 'line_total' => '3', 'meters' => '26.000'],
    ['event_type' => 'fault_detected', 'employee_uuid' => $tailorId, 'faults' => 2, 'line_total' => '3', 'meters' => '26.000'],
], 'fault meters 17 + 9 for the cutter and detection meters 17 + 9 for the detector, never netted: ' . json_encode($totals));
check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name LIKE '%score%'")->fetchColumn() === 0, 'no mutable employee score exists anywhere');
$step($cutterA, $th12, 'transition', 'rework-2');
$step($tailor, $th12, 'claim', 'intake-3');
$third = checkOk($staffGet($tailor, '/orders/' . rawurlencode($th12)), 'third arrival')['productionQuality'];
check($third['arrivalNumber'] === 3 && $third['reworkCycles'] === 2 && $third['repeatedErrors'] === true, 'third arrival is flagged as repeated rework');
$history = checkOk($get($denisa, '/management/order-lookup/' . rawurlencode($th12)), 'order history for managers');
check(array_column($history['exceptions'], 'id') === [$x1, $x2] && $history['order']['reworkCycles'] === 2, 'managers see the complete exception chain of the order');
check(array_column(checkOk($get($hikmet, '/management/production-exceptions', ['view' => 'mine']), 'Hikmet history')['items'], 'id') === [$x2], 'Hikmet sees his own decision history');
// The normal forward flow continues unchanged after the exceptions.
$step($tailor, $th12, 'transition', 'forward');
check($order($th12)['production_stage_id'] === 'labeling', 'normal N -> N+1 flow resumes');

// ---- Whole order from OutletPerdele, decided by a temporary backup approver -----------------------
$op12 = 'outletperdele:12';
$cut($cutterB, $tailor2, $op12);
$x3 = checkOk($report($tailor2, $op12, array_column($items($op12), 'item_uuid'), 'material-defect', null, $key('report-op')), 'whole order is faulty', 201)['id'];
check(checkOk($staffGet($tailor2, "/production-exceptions/{$x3}"), 'x3')['faultMeters'] === '29.000', 'whole-order fault = sum of all line meters (29 m), never quantity x meters');
checkOk($ack($cutterB, $x3, $qr($op12), $key('ack-op')), 'cutter B acknowledges');
checkError($dash($sinem, 'POST', '/management/organization/responsibilities', ['responsibility' => 'operations_backup_approver', 'employeeId' => $backupId, 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], $key('backup-sinem')), 403, 'UNAUTHORIZED_ACTION', 'Sinem cannot appoint a backup approver');
checkError($dash($mesut, 'POST', '/management/organization/responsibilities', ['responsibility' => 'operations_backup_approver', 'employeeId' => $backupId], $key('backup-noend')), 422, 'END_REQUIRED', 'a backup approver needs an end time');
checkError($dash($mesut, 'POST', '/management/organization/responsibilities', ['responsibility' => 'operations_backup_approver', 'employeeId' => $denisaId, 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], $key('backup-ops')), 422, 'ALREADY_APPROVER', 'an operations manager is not a backup');
checkError($dash($mesut, 'POST', '/management/organization/responsibilities', ['responsibility' => 'operations_backup_approver', 'employeeId' => $cutterAId, 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], $key('backup-staff')), 422, 'EMPLOYEE_NEEDS_DASHBOARD', 'a backup needs Dashboard access');
$appointed = checkOk($dash($mesut, 'POST', '/management/organization/responsibilities', ['responsibility' => 'operations_backup_approver', 'employeeId' => $backupId, 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], $key('backup')), 'the CEO appoints a temporary backup', 201);
$backupAssignment = array_values(array_filter($appointed['responsibilities'], static fn (array $r): bool => $r['responsibility'] === 'operations_backup_approver'))[0];
check(checkOk($get($backup, '/management/me'), 'backup me')['capabilities']['approvalViaBackup'] === true, 'the backup approves through its time-bound assignment');
$byBackup = checkOk($decide($backup, $x3, 'approve', null, $key('backup-approve')), 'the backup approves');
check($byBackup['decisions'][0]['decidedVia'] === 'backup_approver' && $order($op12)['production_stage_id'] === 'material-preparation', 'the backup decision is recorded as such and the order returns to cutting');
check($pdo->query("SELECT meters FROM production_quality_events WHERE exception_uuid = " . $pdo->quote($x3) . " AND event_type = 'cutting_fault'")->fetchColumn() === '29.000', 'whole-order fault meters are 29');
checkOk($dash($mesut, 'POST', "/management/organization/responsibilities/{$backupAssignment['id']}/revoke", ['reason' => 'Revenire echipă'], $key('backup-revoke')), 'the CEO revokes the backup early');
checkError($get($backup, '/management/production-exceptions'), 403, 'UNAUTHORIZED_ACTION', 'a revoked backup can no longer see the queue');

// ---- Authorization revoked mid-flow, and root recovery ---------------------------------------------
$th13 = 'trendhome:13';
$cut($cutterB, $tailor, $th13);
$x4 = checkOk($report($tailor, $th13, [$items($th13)[0]['item_uuid']], 'other', 'Material pătat', $key('report-13')), 'report on order 13', 201)['id'];
checkOk($dash($root, 'POST', "/management/employees/{$cutterBId}/deactivate"), 'root deactivates cutter B while the request waits');
checkError($ack($cutterB, $x4, $qr($th13), $key('ack-13')), 401, 'SESSION_EXPIRED', 'a deactivated employee (sessions revoked) cannot continue the flow');
checkError($dash($mesut, 'POST', "/management/production-exceptions/{$x4}/cancel", ['expectedVersion' => 1, 'reason' => 'Angajat plecat'], $key('cancel-ceo')), 403, 'ROOT_ONLY', 'only root may cancel a stuck request');
$cancelled = checkOk($dash($root, 'POST', "/management/production-exceptions/{$x4}/cancel", ['expectedVersion' => 1, 'reason' => 'Angajatul responsabil nu mai este activ'], $key('cancel')), 'root cancels the stuck request');
check($cancelled['status'] === 'cancelled' && $order($th13)['open_exception_uuid'] === null && $order($th13)['production_stage_id'] === 'workshop-receiving', 'cancel unblocks the order at stage 3 without moving it');
check((int) $pdo->query("SELECT COUNT(*) FROM production_quality_events WHERE exception_uuid = " . $pdo->quote($x4))->fetchColumn() === 0, 'a cancelled request creates no quality facts');

// ---- Two managers deciding at the same time (separate connections) ---------------------------------
$th14 = 'trendhome:14';
checkOk($dash($root, 'POST', "/management/employees/{$cutterBId}/activate"), 'cutter B reactivated');
$cut($cutterA, $tailor2, $th14);
$x5 = checkOk($report($tailor2, $th14, [$items($th14)[1]['item_uuid']], 'wrong-variant', null, $key('report-14')), 'report on order 14', 201)['id'];
checkOk($ack($cutterA, $x5, $qr($th14), $key('ack-14')), 'acknowledged');
$version5 = $exceptionVersion($x5);
// Two managers decide in two separate PHP processes released at the same instant.
$startAt = (string) (microtime(true) + 0.4);
$workers = [];
foreach ([[$denisa, 'approve', null], [$hikmet, 'reject', 'Respins în paralel']] as $index => [$who, $decision, $comment]) {
    $body = json_encode(array_filter(['expectedVersion' => $version5, 'decision' => $decision, 'comment' => $comment], static fn ($v) => $v !== null), JSON_THROW_ON_ERROR);
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-order-worker.php', $dbName, 'POST', "/management/production-exceptions/{$x5}/decision", $body, $key("race-{$index}"), $who['cookie'], $who['csrf'], $startAt], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$statuses = [];
foreach ($workers as [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'decision race worker ' . $err);
    $statuses[] = json_decode((string) $out, true)['status'];
}
sort($statuses);
check($statuses === [200, 409], 'exactly one of two concurrent decisions on the same version succeeds: ' . json_encode($statuses));
check((int) $pdo->query("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = " . $pdo->quote($x5) . " AND status <> 'pending'")->fetchColumn() === 1, 'exactly one decision row was written');

// ---- Invariants: commerce, items, sources, inventory ------------------------------------------------
check(array_intersect_key($order($th12), $commerceBefore) === $commerceBefore, 'commerce status and source projection are untouched by the exception flow');
check($items($th12) === $lines12, 'order lines and meters are untouched');
check((int) $pdo->query('SELECT COUNT(*) FROM order_projection_receipts')->fetchColumn() === $receiptsBefore, 'no source event was produced');
check($pdo->query('SELECT source_key, last_contact_at, last_event_at, sync_cursor_at FROM order_sources ORDER BY source_key')->fetchAll(PDO::FETCH_ASSOC) === $sourcesBefore, 'source bookkeeping is untouched');
check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE '%inventory%' OR table_name LIKE '%stock%' OR table_name LIKE '%reservation%')")->fetchColumn() === 0, 'no inventory or stock table exists');
$audit = $pdo->query("SELECT metadata_json FROM iam_audit_events WHERE action LIKE 'production.exception.%' OR action LIKE 'organization.%' OR action LIKE 'responsibility.%'")->fetchAll(PDO::FETCH_COLUMN);
check($audit !== [] && preg_match('/password|csrf|cookie|token|hash/i', implode('', $audit)) !== 1, 'audit metadata contains no secrets');
check((int) $pdo->query("SELECT COUNT(*) FROM production_exception_events WHERE exception_uuid = " . $pdo->quote($x2))->fetchColumn() === 6, 'the re-reviewed request has a complete append-only timeline');


// ---- Organisation roster reconciliation (dry run by default, exact matching only) ----------------------
$rosterFile = dirname(__DIR__) . '/database/reference/organization-roster.json';
$canonical = json_decode((string) file_get_contents($rosterFile), true);
check(count($canonical['people']) === 46 && count($canonical['departments']) === 14, 'the canonical roster has 46 people in 14 departments');
\Arasya\Operations\Management\OrganizationRoster::fromArray($canonical);
// The shared test database keeps identities of earlier runs, so this run reconciles run-unique names
// against the real department catalog.
$tag = 'R' . strtoupper($suffix);
$roster = \Arasya\Operations\Management\OrganizationRoster::fromArray(['departments' => $canonical['departments'], 'people' => [
    ['name' => "YERLIKAYA HIKMET {$tag}", 'department' => 'operatiuni', 'title' => 'Manager operațional', 'proposedRole' => 'operations-manager'],
    ['name' => "IANCU IULIANA {$tag}", 'department' => 'vanzari-online'],
    ['name' => "BARBU PAUL {$tag}", 'department' => 'depozit', 'additionalDepartments' => ['montaj']],
    ['name' => "NOBODY HERE {$tag}", 'department' => 'taiere'],
]]);
$departmentNames = array_map([\Arasya\Operations\Management\OrganizationRoster::class, 'departmentKey'], $pdo->query('SELECT name FROM departments')->fetchAll(PDO::FETCH_COLUMN));
$toCreate = count(array_filter($canonical['departments'], static fn (array $d): bool => !in_array(\Arasya\Operations\Management\OrganizationRoster::departmentKey($d['name']), $departmentNames, true)));
$hikmetMatch = $admin->create("Hikmet Yerlikaya {$tag}", "hikmet.yerlikaya.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test')->employeeUuid;
$admin->create("Iancu Iuliana {$tag}", "iancu.a.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
$admin->create("IANCU IULIANA {$tag}", "iancu.b.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
$barbuMatch = $admin->create("Paul Barbu {$tag}", "paul.barbu.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test')->employeeUuid;
$admin->create("Paul Barbuta {$tag}", "paul.barbuta.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
$reconciler = new \Arasya\Operations\Management\OrganizationReconciler($pdo, $container->managementService(), $container->employeeRepository());
$employeesBefore = $pdo->query('SELECT employee_uuid, username, password_hash, status, department_id, position_title, authorization_version FROM employees ORDER BY employee_uuid')->fetchAll(PDO::FETCH_ASSOC);
$dry = $reconciler->plan($roster);
check($pdo->query('SELECT employee_uuid, username, password_hash, status, department_id, position_title, authorization_version FROM employees ORDER BY employee_uuid')->fetchAll(PDO::FETCH_ASSOC) === $employeesBefore, 'the dry run changes nothing');
check(array_column($dry['matched'], 'employeeId') === [$hikmetMatch, $barbuMatch], 'exact token matches only, in any word order; a similar name never matches: ' . json_encode(array_column($dry['matched'], 'name')));
check(array_column($dry['ambiguous'], 'name') === ["IANCU IULIANA {$tag}"] && count($dry['ambiguous'][0]['candidates']) === 2, 'two identities with one roster name are ambiguous and never applied');
check(array_column($dry['missing'], 'name') === ["NOBODY HERE {$tag}"] && $dry['summary']['departmentsToCreate'] === $toCreate, 'missing people need explicit onboarding; only absent departments would be created');
$applied = $reconciler->apply($roster, 'test-roster-' . $suffix);
$after = $applied['plan'];
check($after['summary']['withChanges'] === 0 && $after['summary']['departmentsToCreate'] === 0, 'apply leaves no pending membership change');
$hikmetRow = $pdo->query('SELECT e.position_title, d.name FROM employees e JOIN departments d ON d.department_id = e.department_id WHERE e.employee_uuid = ' . $pdo->quote($hikmetMatch))->fetch(PDO::FETCH_ASSOC);
check($hikmetRow == ['position_title' => 'Manager operațional', 'name' => 'Operațiuni'], 'matched membership applied through official IAM');
check($pdo->query('SELECT d.name FROM employee_secondary_departments s JOIN departments d ON d.department_id = s.department_id WHERE s.employee_uuid = ' . $pdo->quote($barbuMatch))->fetchAll(PDO::FETCH_COLUMN) === ['Montaj'], 'BARBU PAUL keeps one identity with Montaj as an additional function');
$employeesAfter = $pdo->query('SELECT employee_uuid, username, password_hash, status FROM employees ORDER BY employee_uuid')->fetchAll(PDO::FETCH_ASSOC);
check($employeesAfter === array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['employee_uuid', 'username', 'password_hash', 'status'])), $employeesBefore), 'apply creates no identity and changes no password or status');
check((int) $pdo->query('SELECT COUNT(*) FROM employee_role_assignments era JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid = ' . $pdo->quote($hikmetMatch) . " AND r.role_key = 'operations-manager'")->fetchColumn() === 0, 'the roster never assigns roles');
check((int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE request_id = " . $pdo->quote('test-roster-' . $suffix))->fetchColumn() === $toCreate + 2 + 1, 'every applied department and membership change is in the IAM audit');
check(count($reconciler->apply($roster, 'test-roster-again-' . $suffix)['applied']) === 0, 'a second apply is a no-op');

fwrite(STDOUT, "OK MySQL production exceptions integration ({$checks} checks)\n");
