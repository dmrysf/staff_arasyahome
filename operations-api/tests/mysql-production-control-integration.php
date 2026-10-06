<?php

declare(strict_types=1);

// Production Control V1 against a dedicated MySQL/MariaDB test database: order list/detail access,
// keyset pagination, filters, cross-source identity, items, the production timeline, the privacy
// boundary, and the independence of the two state machines: commerce updates never move production,
// production actions never touch commerce data and never produce anything for the source.

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
    fwrite(STDOUT, "SKIP MySQL production control integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken']];
};

// ---- Identities ------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.owner.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-control-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Control root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Control root passphrase 2026!');
$roleIds = [];
foreach (checkOk($get($root, '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$customRole = static function (string $name, array $permissions, int $rank) use ($asDashboard, $root, $suffix): int {
    $created = $asDashboard($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => $rank, 'permissions' => $permissions]);
    check($created['status'] === 201, "role {$name} created");
    return (int) $created['body']['role']['id'];
};
$password = 'control passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $asDashboard, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    check($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications])['status'] === 200, "{$name} applications");
    if ($roles !== []) {
        check($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles])['status'] === 200, "{$name} roles");
    }
    return $employee->employeeUuid;
};
$workerId = $identity('worker', ['waiting', 'labeling', 'material-straightening', 'delivery'], ['staff'], []);
$identity('producer', [], ['dashboard'], [$customRole('Doar productie', ['dashboard.overview.view', 'production.view'], 150)]);
$identity('reader', [], ['dashboard'], [$customRole('Doar comenzi', ['dashboard.overview.view', 'orders.view_all'], 160)]);
$identity('supervisor', [], ['dashboard'], [$roleIds['supervisor']]);
$identity('noapp', [], ['staff'], [$roleIds['supervisor']]);
$worker = $login("worker.{$suffix}", $password);
$producer = $login("producer.{$suffix}", $password);
$reader = $login("reader.{$suffix}", $password);
$supervisor = $login("supervisor.{$suffix}", $password);
$noapp = $login("noapp.{$suffix}", $password);

// ---- Orders ----------------------------------------------------------------------------------------
$changedAt = static fn (int $secondsAgo): string => gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);
$items = [
    ['id' => 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'variant' => 'Inel', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 2],
    ['id' => 2, 'line' => 2, 'name' => 'Perdea In', 'sku' => null, 'color' => null, 'width' => null, 'height' => null, 'unit' => null, 'meters' => null, 'quantity' => 1],
];
$ingest = static function (string $source, string $id, string $event, int $ago, ?string $stage, string $status = 'processing', string $availability = 'active', ?array $orderItems = null) use ($kernel, $changedAt): void {
    $response = T::ingest($kernel, $source, T::sourceOrder($id, $event, $changedAt($ago), $stage === null ? null : T::stage($stage), $status, $availability, $orderItems));
    check(in_array($response['body']['outcome'] ?? null, ['applied'], true), "ingest {$source}:{$id} {$event} (" . json_encode($response['body']) . ')');
};
// The same order number exists in two sources: identity is the global order id.
$ingest('trendhome', '7001', "th-7001-1-{$suffix}", 600, 'waiting', 'processing', 'active', $items);
$ingest('outletperdele', '7001', "op-7001-1-{$suffix}", 600, 'labeling', 'on-hold');
$ingest('trendhome', '7002', "th-7002-1-{$suffix}", 600, 'labeling');
$ingest('trendhome', '7003', "th-7003-1-{$suffix}", 600, 'delivery');
$ingest('trendhome', '7004', "th-7004-1-{$suffix}", 600, 'ironing', 'cancelled', 'cancelled');
for ($i = 1; $i <= 24; $i++) {
    $ingest('outletperdele', (string) (8000 + $i), "op-bulk-{$i}-{$suffix}", 600, 'waiting');
}
// Deterministic import order: bulk orders oldest, then 7004 … 7001 (trendhome) newest.
$pdo->exec("UPDATE operational_orders SET created_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY) WHERE source_key = 'outletperdele' AND source_order_id LIKE '80%'");
foreach (['trendhome:7004' => 50, 'trendhome:7003' => 40, 'outletperdele:7001' => 30, 'trendhome:7002' => 20, 'trendhome:7001' => 10] as $global => $minutes) {
    $pdo->prepare('UPDATE operational_orders SET created_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL :minutes MINUTE) WHERE global_order_id = :id')->execute(['minutes' => $minutes, 'id' => $global]);
}

$operate = static function (string $globalId, string $action, int $version, string $key) use ($kernel, $worker): array {
    return T::call($kernel, 'POST', '/orders/' . rawurlencode($globalId) . '/' . $action, ['expectedVersion' => $version], ['origin' => T::ORIGIN, 'x-csrf-token' => $worker['csrf'], 'idempotency-key' => $key], $worker['cookie']);
};
$commerce = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT source_commerce_status_code, source_commerce_status_label, source_event_id, source_changed_at, last_source_seen_at, projection_hash, operational_status, accepted_at FROM operational_orders WHERE global_order_id = :id');
    $statement->execute(['id' => $globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$production = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT production_stage_id, production_owner_employee_uuid, production_version, production_completed_at, production_authority FROM operational_orders WHERE global_order_id = :id');
    $statement->execute(['id' => $globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$sourceState = static fn (): array => $pdo->query('SELECT source_key, last_contact_at, last_event_at, sync_cursor_at FROM order_sources ORDER BY source_key')->fetchAll(PDO::FETCH_ASSOC);
$receipts = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM order_projection_receipts')->fetchColumn();

// ---- Production actions never touch commerce data or the source ------------------------------------
$commerceBefore = $commerce('trendhome:7002');
$sourcesBefore = $sourceState();
$receiptsBefore = $receipts();
check($operate('trendhome:7002', 'claim', 1, "c-claim-7002-{$suffix}")['status'] === 200, 'worker claims trendhome:7002');
check($operate('trendhome:7002', 'transition', 2, "c-step-7002-{$suffix}")['status'] === 200, 'worker completes labeling on trendhome:7002');
check($production('trendhome:7002')['production_stage_id'] === 'material-straightening', 'production moved labeling -> material-straightening (N -> N+1)');
check($commerce('trendhome:7002') === $commerceBefore, 'commerce status, source event, source timestamps and projection hash are unchanged by production');
check($sourceState() === $sourcesBefore && $receipts() === $receiptsBefore, 'production produced no source contact, event or receipt');

$commerceBefore = $commerce('trendhome:7003');
check($operate('trendhome:7003', 'claim', 1, "c-claim-7003-{$suffix}")['status'] === 200, 'worker claims trendhome:7003 at delivery');
check($operate('trendhome:7003', 'transition', 2, "c-done-7003-{$suffix}")['status'] === 200, 'worker completes production of trendhome:7003');
check($production('trendhome:7003')['production_completed_at'] !== null, 'Arasya production is completed');
check($commerce('trendhome:7003') === $commerceBefore && $commerceBefore['source_commerce_status_code'] === 'processing', 'production completion does not complete or ship the commerce order');
check($sourceState() === $sourcesBefore && $receipts() === $receiptsBefore, 'production completion produced no source writeback');

check($operate('trendhome:7001', 'claim', 1, "c-claim-7001-{$suffix}")['status'] === 200, 'worker claims trendhome:7001 (waiting)');

// ---- Commerce updates never move production --------------------------------------------------------
$productionBefore = $production('trendhome:7001');
$ingest('trendhome', '7001', "th-7001-2-{$suffix}", 300, null, 'shipped', 'active', $items);
$after = $commerce('trendhome:7001');
check($after['source_commerce_status_code'] === 'shipped' && $after['source_commerce_status_label'] === 'Shipped', 'commerce status updates from the source');
check($production('trendhome:7001') === $productionBefore, 'a commerce update leaves stage, owner, production version and completion unchanged');
$ingest('trendhome', '7001', "th-7001-3-{$suffix}", 200, 'delivery', 'completed', 'active', $items);
check($production('trendhome:7001') === $productionBefore && $commerce('trendhome:7001')['source_commerce_status_code'] === 'completed', 'once Operations owns production, a source stage hint cannot move it either');
$productionBefore = $production('trendhome:7003');
$ingest('trendhome', '7003', "th-7003-2-{$suffix}", 300, null, 'pending');
check($production('trendhome:7003') === $productionBefore, 'a commerce change cannot reopen completed production');

// ---- Access ------------------------------------------------------------------------------------------
checkError(T::call($kernel, 'GET', '/management/orders', null, ['origin' => DASHBOARD_ORIGIN]), 401, 'SESSION_EXPIRED', 'anonymous list rejected');
checkError(T::call($kernel, 'GET', '/management/orders/trendhome%3A7001', null, ['origin' => DASHBOARD_ORIGIN]), 401, 'SESSION_EXPIRED', 'anonymous detail rejected');
checkError($get($worker, '/management/orders'), 403, 'APPLICATION_ACCESS_DENIED', 'Staff-only employees rejected');
checkError($get($noapp, '/management/orders'), 403, 'APPLICATION_ACCESS_DENIED', 'orders.view_all without Dashboard access rejected');
checkError($get($producer, '/management/orders'), 403, 'UNAUTHORIZED_ACTION', 'production.view alone does not expose order rows');
checkError($get($producer, '/management/orders/trendhome%3A7001'), 403, 'UNAUTHORIZED_ACTION', 'production.view alone does not expose order detail');
checkError(T::call($kernel, 'POST', '/management/orders', [], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf']], $root['cookie']), 404, 'NOT_FOUND', 'no write route on orders');
checkError(T::call($kernel, 'PATCH', '/management/orders/trendhome%3A7001', ['stage' => 'delivery'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf']], $root['cookie']), 404, 'NOT_FOUND', 'no stage override route, not even for root');

// ---- List and pagination ---------------------------------------------------------------------------
$page = checkOk($get($root, '/management/orders', ['limit' => '25']), 'root lists orders');
check(count($page['items']) === 25 && is_string($page['nextCursor']), 'first page holds 25 rows and a cursor');
check(array_column(array_slice($page['items'], 0, 5), 'globalOrderId') === ['trendhome:7001', 'trendhome:7002', 'outletperdele:7001', 'trendhome:7003', 'trendhome:7004'], 'newest import first: ' . json_encode(array_column(array_slice($page['items'], 0, 5), 'globalOrderId')));
$second = checkOk($get($root, '/management/orders', ['limit' => '25', 'cursor' => $page['nextCursor']]), 'second page');
check(count($second['items']) === 4 && $second['nextCursor'] === null, 'second page holds the remaining 4 rows');
$all = array_merge(array_column($page['items'], 'globalOrderId'), array_column($second['items'], 'globalOrderId'));
check(count($all) === 29 && count(array_unique($all)) === 29, 'pages neither overlap nor skip');
check(count(checkOk($get($root, '/management/orders'), 'default page')['items']) === 29, 'default page size is 50');
checkError($get($root, '/management/orders', ['limit' => '30']), 422, 'VALIDATION_FAILED', 'only 25, 50 or 100 per page');
checkError($get($root, '/management/orders', ['cursor' => 'not-a-cursor!']), 400, 'INVALID_CURSOR', 'malformed cursors rejected');
checkError($get($root, '/management/orders', ['state' => 'late']), 422, 'VALIDATION_FAILED', 'unknown state rejected');
checkError($get($root, '/management/orders', ['assignment' => 'mine']), 422, 'VALIDATION_FAILED', 'unknown assignment rejected');

$row = $page['items'][1];
check($row['commerce']['status'] === ['code' => 'processing', 'label' => 'Processing'] && $row['production']['stage']['id'] === 'material-straightening', 'commerce status and production stage are separate fields');
check($row['production']['owner'] === null && $row['production']['state'] === 'active' && $row['production']['claimedAt'] === null, 'after a handover the order waits unowned in the next stage');
check($page['items'][0]['production']['owner'] === ['id' => $workerId, 'displayName' => 'Test worker'], 'current owner comes from the canonical owner field');
check($page['items'][3]['production']['state'] === 'completed' && $page['items'][4]['production']['state'] === 'cancelled' && $page['items'][4]['commerce']['availability'] === 'cancelled', 'production state: completed and cancelled');
check(array_keys($row) === ['globalOrderId', 'orderNumber', 'source', 'commerce', 'production', 'importedAt', 'acceptedAt'], 'list rows are summaries');
$facets = $page['facets'];
check(array_column($facets['sources'], 'key') === ['b2b','outletperdele', 'trendhome', 'trendyol'] && count($facets['stages']) === 14, 'facets list registered sources and the 14 stages');
check(array_column($facets['commerceStatuses'], 'code') === ['cancelled', 'completed', 'on-hold', 'pending', 'processing'] && $facets['owners'] === [['id' => $workerId, 'displayName' => 'Test worker']], 'facets list commerce statuses and current owners');

// ---- Filters -----------------------------------------------------------------------------------------
$ids = static fn (array $query) => array_column(checkOk($get($root, '/management/orders', $query), 'filter ' . json_encode($query))['items'], 'globalOrderId');
check($ids(['source' => 'trendhome']) === ['trendhome:7001', 'trendhome:7002', 'trendhome:7003', 'trendhome:7004'], 'source filter');
check($ids(['stage' => 'labeling']) === ['outletperdele:7001'], 'stage filter');
check($ids(['commerceStatus' => 'on-hold']) === ['outletperdele:7001'], 'commerce status filter');
check($ids(['ownerId' => $workerId]) === ['trendhome:7001'], 'responsible employee filter');
check($ids(['assignment' => 'assigned']) === ['trendhome:7001'] && count($ids(['assignment' => 'unassigned'])) === 28, 'assigned / unassigned filter');
check($ids(['state' => 'completed']) === ['trendhome:7003'] && $ids(['state' => 'cancelled']) === ['trendhome:7004'] && count($ids(['state' => 'active'])) === 27, 'active / completed / cancelled filter');
check($ids(['search' => '7001']) === ['trendhome:7001', 'outletperdele:7001'], 'search by order number finds both sources');
check($ids(['search' => '#80']) === array_map(static fn (int $n): string => 'outletperdele:' . (8000 + $n), range(1, 24)) || count($ids(['search' => '#80'])) === 24, 'search by order-number prefix');
check($ids(['search' => 'outletperdele:7001']) === ['outletperdele:7001'], 'search by global order id');
check($ids(['search' => '7001', 'source' => 'outletperdele', 'state' => 'active']) === ['outletperdele:7001'], 'filters combine');
check($ids(['search' => '%']) === [], 'LIKE wildcards in search are literal');

// ---- Detail ------------------------------------------------------------------------------------------
$detail = checkOk($get($root, '/management/orders/' . rawurlencode('trendhome:7001')), 'trendhome:7001 detail');
$other = checkOk($get($root, '/management/orders/' . rawurlencode('outletperdele:7001')), 'outletperdele:7001 detail');
check($detail['source']['key'] === 'trendhome' && $other['source']['key'] === 'outletperdele' && $other['production']['stage']['id'] === 'labeling' && $other['commerce']['status']['code'] === 'on-hold', 'same order number in two sources stays two orders');
check($detail['commerce']['status']['code'] === 'completed' && $detail['production']['stage']['id'] === 'waiting' && $detail['production']['owner']['id'] === $workerId, 'detail separates commerce (completed) from production (waiting, owned)');
check($detail['items'] === [
    ['line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'variant' => 'Inel', 'color' => 'Bej', 'width' => 300.0, 'height' => 260.0, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 2],
    ['line' => 2, 'name' => 'Perdea In', 'sku' => null, 'variant' => null, 'color' => null, 'width' => null, 'height' => null, 'unit' => null, 'meters' => null, 'quantity' => 1],
], 'items carry normalized measurements and keep missing values null: ' . json_encode($detail['items']));
check($detail['production']['notes'] === 'Verifică sensul materialului.' && $detail['production']['version'] === 2, 'production notes and version');
$json = json_encode([$detail, $page], JSON_THROW_ON_ERROR);
check(preg_match('/"(customer\w*|email|phone|billing\w*|shipping\w*|address\w*|firstName|lastName)"/i', $json) !== 1, 'no customer personal data fields in list or detail');
checkError($get($root, '/management/orders/' . rawurlencode('trendhome:9999')), 404, 'ORDER_NOT_FOUND', 'unknown order');
checkError($get($root, '/management/orders/' . rawurlencode('7001')), 404, 'ORDER_NOT_FOUND', 'a bare order number is not an identity');
checkError($get($root, '/management/orders/' . rawurlencode("trendhome:7001' OR 1=1")), 404, 'ORDER_NOT_FOUND', 'malformed ids rejected');

$timeline = checkOk($get($root, '/management/orders/' . rawurlencode('trendhome:7002')), 'trendhome:7002 detail')['activity'];
check(array_column($timeline, 'action') === ['claimed', 'stage_completed'] && $timeline[1]['fromStage']['id'] === 'labeling' && $timeline[1]['toStage']['id'] === 'material-straightening' && $timeline[1]['employee']['displayName'] === 'Test worker', 'timeline is the immutable activity log, oldest first');
check(strcmp($timeline[0]['occurredAt'], $timeline[1]['occurredAt']) <= 0 && $timeline[0]['productionVersion'] < $timeline[1]['productionVersion'], 'timeline ordering');
$done = checkOk($get($root, '/management/orders/' . rawurlencode('trendhome:7003')), 'trendhome:7003 detail');
check(array_column($done['activity'], 'action') === ['claimed', 'production_completed'] && $done['production']['completedAt'] !== null && $done['commerce']['status']['code'] === 'pending', 'completed production with its own commerce status');

$readerDetail = checkOk($get($reader, '/management/orders/' . rawurlencode('trendhome:7002')), 'orders.view_all reads detail');
check($readerDetail['activity'] === null && $readerDetail['items'] !== [], 'without activity.view_all the timeline is withheld');
check(count(checkOk($get($supervisor, '/management/orders/' . rawurlencode('trendhome:7002')), 'supervisor detail')['activity']) === 2, 'supervisor sees the timeline');

// ---- Reads write nothing -----------------------------------------------------------------------------
$fingerprint = static fn (): array => [
    $pdo->query('SELECT SUM(version), SUM(production_version), COUNT(*) FROM operational_orders')->fetch(PDO::FETCH_NUM),
    (int) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM iam_audit_events')->fetchColumn(),
    $sourceState(),
    $receipts(),
];
$before = $fingerprint();
$get($root, '/management/orders', ['state' => 'active']);
$get($root, '/management/orders/' . rawurlencode('trendhome:7002'));
check($fingerprint() === $before, 'listing and opening orders writes nothing');

fwrite(STDOUT, "OK MySQL production control integration ({$checks} checks)\n");
