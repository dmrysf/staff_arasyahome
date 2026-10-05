<?php

declare(strict_types=1);

// Production overview against a dedicated MySQL/MariaDB test database: permission gates, metric
// definitions, per-stage aggregation, unassigned counts, oldest-order ordering, the Europe/Bucharest
// "completed today" boundary (including the DST change), source health and section-level data scope.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Management\ProductionOverviewService;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL production overview integration: ARASYA_TEST_DB_NAME is not configured.\n");
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

// Aggregates are production-wide, so this dedicated test database starts without operational orders.
foreach (['order_activity_events', 'order_operation_idempotency', 'employee_order_relations', 'order_qr_references', 'operational_order_items', 'order_projection_receipts', 'operational_orders'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec("UPDATE order_sources SET status = 'active', last_contact_at = NULL, last_event_at = NULL");
$pdo->exec('DELETE FROM system_root_identity');

$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD_ORIGIN], $who['cookie'], $query);
$asDashboard = static fn (array $who, string $method, string $path, ?array $json = null): array => T::call($kernel, $method, $path, $json, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']], $who['cookie']);
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken']];
};

// ---- Identities ------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.owner.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-overview-root', $rootUsername);
$rootSession = $login($rootUsername, $rootTemporary);
$changed = T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Overview root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $rootSession['csrf']], $rootSession['cookie']);
check($changed['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Overview root passphrase 2026!');

$roles = [];
foreach (checkOk($get($root, '/management/roles'), 'roles list')['items'] as $role) {
    $roles[$role['key']] = (int) $role['id'];
}
$viewOnly = checkOk($asDashboard($root, 'POST', '/management/roles', ['name' => "Doar panou {$suffix}", 'description' => null, 'authorityRank' => 150, 'permissions' => ['dashboard.overview.view']]), 'root creates an overview-only role', 201);
$productionOnly = checkOk($asDashboard($root, 'POST', '/management/roles', ['name' => "Doar productie {$suffix}", 'description' => null, 'authorityRank' => 160, 'permissions' => ['dashboard.overview.view', 'production.view']]), 'root creates a production-only role', 201);

$password = 'overview passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roleIds) use ($admin, $asDashboard, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    check($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications])['status'] === 200, "{$name} applications set");
    if ($roleIds !== []) {
        $set = $asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roleIds]);
        check($set['status'] === 200, "{$name} roles set " . json_encode($set['body']));
    }
    return $employee->employeeUuid;
};
$workerId = $identity('worker', ['waiting', 'labeling', 'delivery'], ['staff'], []);
$identity('viewer', [], ['dashboard'], [(int) $viewOnly['role']['id']]);
$identity('producer', [], ['dashboard'], [(int) $productionOnly['role']['id']]);
$identity('supervisor', [], ['dashboard'], [$roles['supervisor']]);
$identity('director', [], ['dashboard'], [$roles['operations-director']]);
$identity('noapp', [], ['staff'], [$roles['supervisor']]);
$worker = $login("worker.{$suffix}", $password);
$viewer = $login("viewer.{$suffix}", $password);
$producer = $login("producer.{$suffix}", $password);
$supervisor = $login("supervisor.{$suffix}", $password);
$director = $login("director.{$suffix}", $password);
$noapp = $login("noapp.{$suffix}", $password);

// ---- Orders ----------------------------------------------------------------------------------------
$changedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
$orders = [
    // number => [source, stage, availability]
    '81001' => ['trendhome', 'waiting', 'active'],
    '81002' => ['trendhome', 'waiting', 'active'],
    '81003' => ['trendhome', 'labeling', 'active'],
    '81004' => ['outletperdele', 'labeling', 'active'],
    '81005' => ['outletperdele', 'sewing-finishing', 'active'],
    '81006' => ['trendhome', 'delivery', 'active'],
    '81007' => ['outletperdele', 'ironing', 'cancelled'],
];
foreach ($orders as $number => [$source, $stage, $availability]) {
    $ingested = T::ingest($kernel, $source, T::sourceOrder((string) $number, "overview-{$number}-{$suffix}", $changedAt, T::stage($stage), 'processing', $availability));
    check(($ingested['body']['outcome'] ?? null) === 'applied', "order {$number} ingested");
}
$operate = static function (string $globalId, string $action, int $version, string $key) use ($kernel, $worker): array {
    return T::call($kernel, 'POST', '/orders/' . rawurlencode($globalId) . '/' . $action, ['expectedVersion' => $version], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $worker['csrf'], 'idempotency-key' => $key], $worker['cookie']);
};
// The worker claims 81003 (assigned at labeling) and completes production of 81006 at the last stage.
check($operate('trendhome:81003', 'claim', 1, "ov-claim-81003-{$suffix}")['status'] === 200, 'worker claims 81003');
check($operate('trendhome:81006', 'claim', 1, "ov-claim-81006-{$suffix}")['status'] === 200, 'worker claims 81006');
$completed = $operate('trendhome:81006', 'transition', 2, "ov-complete-81006-{$suffix}");
check($completed['status'] === 200, 'worker completes production of 81006 (got ' . json_encode($completed['body']) . ')');

// Deterministic stage-entry times: 81004 entered its stage first, then 81001, 81005, 81002, 81003.
$enteredAt = ['81004' => '-9 hours', '81001' => '-7 hours', '81005' => '-5 hours', '81002' => '-3 hours', '81003' => '-1 hours'];
foreach ($enteredAt as $number => $offset) {
    $pdo->prepare('UPDATE operational_orders SET production_changed_at = NULL, created_at = :at WHERE order_number = :number')
        ->execute(['at' => gmdate('Y-m-d H:i:s', strtotime($offset)) . '.000000', 'number' => $number]);
}

// ---- Permission gates ------------------------------------------------------------------------------
checkError(T::call($kernel, 'GET', '/management/production-overview', null, ['origin' => DASHBOARD_ORIGIN]), 401, 'SESSION_EXPIRED', 'anonymous requests are rejected');
checkError($get($worker, '/management/production-overview'), 403, 'APPLICATION_ACCESS_DENIED', 'Staff-only employees are rejected');
checkError($get($noapp, '/management/production-overview'), 403, 'APPLICATION_ACCESS_DENIED', 'production permissions without Dashboard access are rejected');
checkError($get($viewer, '/management/production-overview'), 403, 'UNAUTHORIZED_ACTION', 'Dashboard access without production.view is rejected');
checkError(T::call($kernel, 'POST', '/management/production-overview', [], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf']], $root['cookie']), 404, 'NOT_FOUND', 'the overview has no write route');

// ---- Root: full overview ---------------------------------------------------------------------------
$overview = checkOk($get($root, '/management/production-overview'), 'root reads the overview');
check($overview['timezone'] === 'Europe/Bucharest' && $overview['filters']['source'] === null, 'overview declares timezone and filters');
check($overview['summary'] === ['active' => 5, 'waiting' => 2, 'inWork' => 3, 'unassigned' => 4, 'completedToday' => 1], 'summary metrics follow the documented definitions: ' . json_encode($overview['summary']));
check(array_column($overview['stages'], 'id') === ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'], 'all 14 canonical stages in order');
$stageCounts = array_combine(array_column($overview['stages'], 'id'), array_column($overview['stages'], 'active'));
check($stageCounts['waiting'] === 2 && $stageCounts['labeling'] === 2 && $stageCounts['sewing-finishing'] === 1 && $stageCounts['ironing'] === 0 && $stageCounts['delivery'] === 0 && array_sum($stageCounts) === 5, 'stage counts exclude completed and cancelled orders: ' . json_encode($stageCounts));
$labeling = $overview['stages'][3];
check($labeling['unassigned'] === 1 && $labeling['oldestEnteredAt'] !== null && $overview['stages'][1]['oldestEnteredAt'] === null, 'per-stage unassigned and oldest entry');
check(array_column($overview['oldestOrders'], 'orderNumber') === ['81004', '81001', '81005', '81002', '81003'], 'oldest orders sorted by stage age: ' . json_encode(array_column($overview['oldestOrders'], 'orderNumber')));
$claimed = $overview['oldestOrders'][4];
check($claimed['owner']['displayName'] === 'Test worker' && $claimed['claimedAt'] !== null && $overview['oldestOrders'][0]['owner'] === null, 'oldest orders show the current owner');
check($claimed['source'] === ['key' => 'trendhome', 'name' => 'Trendhome'] && $claimed['stage']['id'] === 'labeling' && $claimed['commerceStatus']['code'] === 'processing', 'oldest orders carry source, stage and commerce status');
check(array_keys($claimed) === ['globalOrderId', 'orderNumber', 'source', 'stage', 'stageEnteredAt', 'owner', 'claimedAt', 'commerceStatus'], 'oldest orders expose no customer or note fields');
check(array_column($overview['activity'], 'action') === ['production_completed', 'claimed', 'claimed'] && $overview['activity'][0]['order']['orderNumber'] === '81006' && $overview['activity'][0]['employee']['displayName'] === 'Test worker', 'production activity, newest first: ' . json_encode(array_column($overview['activity'], 'action')));
check($overview['activity'][0]['fromStage']['id'] === 'delivery' && $overview['activity'][0]['toStage'] === null, 'activity carries stage ids');
$health = array_combine(array_column($overview['sources'], 'key'), array_column($overview['sources'], 'health'));
check($health === ['b2b'=>'healthy','outletperdele' => 'healthy', 'trendhome' => 'healthy', 'trendyol' => 'not_configured'], 'source health after signed contact: ' . json_encode($health));
$sourceActive = array_combine(array_column($overview['sources'], 'key'), array_column($overview['sources'], 'activeOrders'));
check($sourceActive === ['b2b'=>0,'outletperdele' => 2, 'trendhome' => 3, 'trendyol' => 0], 'active orders per source');

// ---- Source filter ---------------------------------------------------------------------------------
$filtered = checkOk($get($root, '/management/production-overview', ['source' => 'outletperdele']), 'source filter');
check($filtered['filters']['source'] === 'outletperdele' && $filtered['summary'] === ['active' => 2, 'waiting' => 0, 'inWork' => 2, 'unassigned' => 2, 'completedToday' => 0], 'filtered summary: ' . json_encode($filtered['summary']));
check(array_column($filtered['oldestOrders'], 'orderNumber') === ['81004', '81005'] && $filtered['activity'] === [] && count($filtered['sources']) === 4, 'filter scopes orders and activity, not source health');
checkError($get($root, '/management/production-overview', ['source' => 'unknown']), 422, 'VALIDATION_FAILED', 'unknown source filters are rejected');

// ---- Section scope ---------------------------------------------------------------------------------
$production = checkOk($get($producer, '/management/production-overview'), 'production.view alone reads aggregates');
check($production['summary'] === $overview['summary'] && $production['oldestOrders'] === null && $production['activity'] === null && $production['sources'] === null, 'production.view alone sees no order rows, activity or sources');
$supervised = checkOk($get($supervisor, '/management/production-overview'), 'supervisor reads the overview');
check(count($supervised['oldestOrders']) === 5 && count($supervised['activity']) === 3 && $supervised['sources'] === null, 'supervisor without sources.view sees no source health');
$directed = checkOk($get($director, '/management/production-overview'), 'operations director reads the overview');
check(count($directed['sources']) === 4, 'operations director sees source health');

// ---- Source health states --------------------------------------------------------------------------
$pdo->prepare('UPDATE order_sources SET last_contact_at = :at WHERE source_key = :key')->execute(['at' => gmdate('Y-m-d H:i:s', time() - 2000) . '.000000', 'key' => 'outletperdele']);
$pdo->prepare('UPDATE order_sources SET last_contact_at = :at WHERE source_key = :key')->execute(['at' => gmdate('Y-m-d H:i:s', time() - 7200) . '.000000', 'key' => 'trendhome']);
$health = array_column(checkOk($get($root, '/management/production-overview'), 'stale sources')['sources'], 'health', 'key');
check($health === ['b2b'=>'healthy','outletperdele' => 'stale', 'trendhome' => 'offline', 'trendyol' => 'not_configured'], 'stale and offline by last contact age: ' . json_encode($health));
$pdo->exec("UPDATE order_sources SET last_contact_at = NULL WHERE source_key = 'outletperdele'");
$pdo->exec("UPDATE order_sources SET status = 'inactive' WHERE source_key = 'trendhome'");
$health = array_column(checkOk($get($root, '/management/production-overview'), 'silent sources')['sources'], 'health', 'key');
check($health === ['b2b'=>'healthy','outletperdele' => 'no_contact', 'trendhome' => 'disabled', 'trendyol' => 'not_configured'], 'configured-but-silent and disabled sources: ' . json_encode($health));
$pdo->exec("UPDATE order_sources SET status = 'active' WHERE source_key = 'trendhome'");

// ---- Completed today: Europe/Bucharest calendar day ------------------------------------------------
$rootIdentity = (new PdoEmployeeRepository($pdo))->findByNormalizedUsername($rootUsername) ?? throw new RuntimeException('root identity missing');
$clock = new MutableClock(new DateTimeImmutable('2026-10-04T21:30:00Z')); // 00:30 on 5 October in Bucharest (UTC+3)
$service = new ProductionOverviewService($pdo, new AuthorizationService(), $clock, $config);
$pdo->exec("UPDATE operational_orders SET production_completed_at = '2026-10-04 20:59:59.000000' WHERE order_number = '81006'");
check($service->overview($rootIdentity, [])['summary']['completedToday'] === 0, '23:59:59 local the previous day is not today');
$pdo->exec("UPDATE operational_orders SET production_completed_at = '2026-10-04 21:00:00.000000' WHERE order_number = '81006'");
check($service->overview($rootIdentity, [])['summary']['completedToday'] === 1, '00:00:00 local is today even though the UTC date is the previous day');
$clock->time = new DateTimeImmutable('2026-10-25T12:00:00Z'); // the 25-hour day when Bucharest leaves summer time
$pdo->exec("UPDATE operational_orders SET production_completed_at = '2026-10-25 21:30:00.000000' WHERE order_number = '81006'");
check($service->overview($rootIdentity, [])['summary']['completedToday'] === 1, '23:30 local on the 25-hour DST day is still today');
$pdo->exec("UPDATE operational_orders SET production_completed_at = '2026-10-24 21:00:00.000000' WHERE order_number = '81006'");
check($service->overview($rootIdentity, [])['summary']['completedToday'] === 1, 'local midnight under summer time starts the DST day');
$pdo->exec("UPDATE operational_orders SET production_completed_at = '2026-10-25 22:00:00.000000' WHERE order_number = '81006'");
check($service->overview($rootIdentity, [])['summary']['completedToday'] === 0, 'local midnight under winter time ends the DST day');

// ---- No writes ------------------------------------------------------------------------------------
$before = $pdo->query('SELECT SUM(version), SUM(production_version), COUNT(*) FROM operational_orders')->fetch(PDO::FETCH_NUM);
$auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM iam_audit_events')->fetchColumn();
$activityBefore = (int) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn();
checkOk($get($root, '/management/production-overview'), 'repeat read');
check($pdo->query('SELECT SUM(version), SUM(production_version), COUNT(*) FROM operational_orders')->fetch(PDO::FETCH_NUM) === $before
    && (int) $pdo->query('SELECT COUNT(*) FROM iam_audit_events')->fetchColumn() === $auditBefore
    && (int) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn() === $activityBefore, 'reading the overview writes nothing');

fwrite(STDOUT, "OK MySQL production overview integration ({$checks} checks)\n");
