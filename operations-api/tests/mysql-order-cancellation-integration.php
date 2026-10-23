<?php

declare(strict_types=1);

// Authoritative production cancellation control (migration 033, API 2.35.0) against a dedicated MySQL/MariaDB test
// database, end to end through the HTTP kernel, the signed source ingestion, the Trendyol synchronization store and
// the operator CLIs. Every order is synthetic; every assertion reads committed rows.
//
//   C0  migration 033: additive, permissions granted to nobody, the 14-stage workflow unchanged
//   C1  3 sources x 14 stages: a cancellation stops production where it is; stage, owner and history stay; every
//       manufacturing command is refused; Staff and Dashboard show the same state; nothing is claimable
//   C2  completed production followed by a cancellation: completion kept, nothing reopened, no release, disposition only
//   C3  source evidence: duplicate and out-of-order events, a new event with the same meaning, reactivation never
//       releases, a later cancellation after a release stops again
//   C4  local stop, pending source request (nothing sent), confirmation by the source, duplicate stop
//   C5  physical reconciliation and authorized release: permissions, revoked authority, wrong QR, superseded QR,
//       stale document, versions, refusals recorded, stale frontend requests after the release
//   C6  open quality exceptions (supervised flow): review, custody, cutter receipt, approval, manager rejection,
//       restore receipt (a synthetic EX-000001-style recovery), approved rework
//   C7  multi-piece and mixed orders under piece routing: a route track at another stage stops with its order
//   C8  documents and QR: no revision and no QR rotation because of a cancellation; pending revision independent
//   C9  races A-N with real concurrent processes
//   C10 operator CLI, legacy cancellations, rollback guard, Dashboard overview without double counting
//   C11 mutation inventory: allowed physical and administrative operations stay possible on a stopped order

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Production\FeatureActivation;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL order cancellation integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
const DASHBOARD = 'http://127.0.0.1:4174';
$config = T::config($dbName, [T::ORIGIN, DASHBOARD]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$pdo->exec('DELETE FROM system_root_identity');
$pdo->exec("UPDATE production_feature_flags SET enabled = 0 WHERE flag_key IN ('partial_rework', 'piece_routing', 'perdea_assisted_checkpoints')");

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$clock = $container->clock();
$suffix = bin2hex(random_bytes(4));
$password = 'cancellation control passphrase 2026';
$rootName = "cancel.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('test-cancellation-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Cancellation root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Cancellation root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('cx-%s-%s-%05d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'cx-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'cx-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');

// ---- C0. Migration 033 ----------------------------------------------------------------------------------------------
$check((string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '033_order_cancellation_control.sql'") === hash_file('sha256', $migrations . '/033_order_cancellation_control.sql'), 'C0 033 recorded with its checksum');
$check((int) $one("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('production.cancellation.manage', 'production.cancellation.release') AND role_grantable = 1") === 2, 'C0 both permissions exist, role-grantable');
$check((int) $one("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'production.cancellation.%'") === 0, 'C0 no role receives a cancellation permission automatically');
$canonical = ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'];
$stagesNow = static fn (): array => array_column($rows("SELECT ps.stage_id FROM production_stages ps JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id WHERE pw.workflow_key = 'curtain-production' AND pw.status = 'active' AND ps.status = 'active' ORDER BY ps.ordinal"), 'stage_id');
$check($stagesNow() === $canonical, 'C0 the canonical 14-stage workflow is unchanged');

// ---- Identities -----------------------------------------------------------------------------------------------------
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = []) use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-cancellation');
    $uuids[$name] = $employee->employeeUuid;
    $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid, 'name' => $display];
};
$line3 = array_slice($canonical, 2);
$tailoringStages = array_slice($canonical, 2, 10);
$waiter = $identity('waiter', 'Test Waiting Desk', ['waiting']);
$cutter = $identity('cutter', 'Test Cutter One', ['material-preparation']);
$cutter2 = $identity('cutter2', 'Test Cutter Two', ['material-preparation']);
$worker = $identity('worker', 'Test Line Worker', $line3);
$worker2 = $identity('worker2', 'Test Second Worker', $line3);
$tailor = $identity('tailor', 'Test Tailor', $tailoringStages);
$iuliana = $identity('iuliana', 'Test Supervisor', $tailoringStages);
$denisa = $identity('denisa', 'Test Operations Manager', [], ['dashboard'], [$roleIds['operations-manager']]);
$hikmet = $identity('hikmet', 'Test Second Manager', [], ['dashboard'], [$roleIds['operations-manager']]);
$ceo = $identity('ceo', 'Test CEO', [], ['dashboard'], [$roleIds['ceo']]);
$ok($dash($root, 'PUT', '/management/organization/ceo', ['employeeId' => $uuids['ceo']]), 'root designates the CEO');
// Narrow, explicit cancellation roles, assigned by a person (root) only to the test identities that need them.
$stopRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Oprire producție test {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => ['orders.view_all', 'production.cancellation.manage']]), 'stop role', 201)['role']['id'];
$releaseRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Reluare producție test {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => ['orders.view_all', 'production.cancellation.release']]), 'release role', 201)['role']['id'];
$keeper = $identity('keeper', 'Test Stop Keeper', [], ['dashboard'], [$stopRole]);
$releaser = $identity('releaser', 'Test Releaser', [], ['dashboard'], [$releaseRole]);
$releaser2 = $identity('releaser2', 'Test Releaser Two', [], ['dashboard'], [$releaseRole]);

// ---- Orders and helpers ---------------------------------------------------------------------------------------------
$events = [];
$base = time() - 3 * 3600;
$tick = static function (string $id) use (&$events, $base): string { $events[$id] = ($events[$id] ?? 0) + 1; return gmdate('Y-m-d\TH:i:s\Z', $base + $events[$id] * 7); };
$orderInfo = static function (string $id, string $source) use ($one, $rows): array {
    $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$id]);
    return ['id' => $id, 'uuid' => $uuid, 'source' => $source, 'path' => '/orders/' . rawurlencode($id),
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]),
        'items' => array_column($rows('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number', [$uuid]), 'item_uuid')];
};
$wooLines = static fn (int $base): array => [
    ['id' => $base + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1],
    ['id' => $base + 2, 'line' => 2, 'name' => 'Draperie Velvet B', 'sku' => 'DV-303', 'color' => 'Gri', 'width' => 200, 'height' => 260, 'unit' => 'cm', 'meters' => 5.0, 'quantity' => 1],
];
$wooItems = [];
// A signed WooCommerce order created at $stage (legacy source authority places a new order at the stage it reports).
$woo = static function (string $source, string $tag, string $stage) use ($kernel, $ok, $tick, $orderInfo, $suffix, $wooLines, &$wooItems): array {
    $number = "CX{$tag}{$suffix}";
    $id = "{$source}:{$number}";
    $wooItems[$id] = $wooLines(crc32($tag) % 100000 * 10);
    $ok(T::ingest($kernel, $source, T::sourceOrder($number, "cx-{$tag}-{$suffix}-1", $tick($id), T::stage($stage), 'processing', 'active', $wooItems[$id], $number)), "ingest {$tag}");
    return $orderInfo($id, $source) + ['number' => $number];
};
$wooEvent = static function (array $o, string $availability, string $status, ?string $eventId = null) use ($kernel, $tick, &$wooItems): array {
    $payload = T::sourceOrder($o['number'], $eventId ?? 'cx-' . bin2hex(random_bytes(6)), $tick($o['id']), null, $status, $availability, $wooItems[$o['id']], $o['number']);
    return T::ingest($kernel, $o['source'], $payload);
};
// A synthetic approved Trendyol order (the approval transaction itself is covered by the Trendyol suite) with its
// released package row, so marketplace synchronization reaches it exactly as in production.
$packages = [];
$trendyol = static function (string $tag) use ($container, $pdo, $orderInfo, $suffix, $rootUuid, &$packages): array {
    $number = "119CX{$tag}{$suffix}";
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $items = [
        new OperationalOrderItem(Uuid::v4(), "ty-{$tag}-1", 1, 'Draperie blackout', 'TY-1', null, 'Gri', 250.0, 260.0, 'cm', 6.0, 1, ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => 'Draperie', 'barcode' => null]),
        new OperationalOrderItem(Uuid::v4(), "ty-{$tag}-2", 2, 'Draperie blackout B', 'TY-2', null, 'Gri', 150.0, 260.0, 'cm', 4.0, 1, ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => 'Draperie', 'barcode' => null]),
    ];
    $pdo->beginTransaction();
    $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-cx{$tag}-{$suffix}", "approval-cx{$tag}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now, $items), []);
    $packageId = random_int(10_000_000, 99_999_999);
    $modified = (int) floor(microtime(true) * 1000) - 600_000;
    $package = ['shipmentPackageId' => $packageId, 'orderNumber' => $number, 'lastModifiedDate' => $modified, 'status' => 'Created', 'orderDate' => $modified - 1000,
        'lines' => [['lineId' => $packageId * 10 + 1, 'productName' => 'Draperie blackout', 'quantity' => 1], ['lineId' => $packageId * 10 + 2, 'productName' => 'Draperie blackout B', 'quantity' => 1]]];
    $pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, lines_hash, version, first_seen_at, last_seen_at, released_order_uuid, released_by_employee_uuid, released_at)
        VALUES (?, ?, 'released', 'Created', ?, ?, ?, 1, ?, ?, ?, ?, ?)")->execute([$packageId, $number, $modified, $modified - 1000, TrendyolPackage::fromApi($package)->linesHash(), $now->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), $uuid, $rootUuid, $now->format('Y-m-d H:i:s.u')]);
    $pdo->commit();
    $statement = $pdo->prepare('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?');
    $statement->execute([$uuid]);
    $id = (string) $statement->fetchColumn();
    $packages[$id] = $package;
    return $orderInfo($id, 'trendyol') + ['number' => $number];
};
$store = new TrendyolIntakeStore($pdo, $container->projectionWriter(), $clock);
$trendyolPackage = static function (array $o, string $status) use (&$packages): array {
    $packages[$o['id']]['lastModifiedDate'] += 1000;
    $packages[$o['id']]['status'] = $status;
    return $packages[$o['id']];
};
$marketplace = static fn (array $o, string $status): string => $store->record(TrendyolPackage::fromApi($trendyolPackage($o, $status)), 0);
$cancel = static fn (array $o): mixed => $o['source'] === 'trendyol' ? $marketplace($o, 'Cancelled') : $wooEvent($o, 'cancelled', 'cancelled');
$reactivate = static fn (array $o): mixed => $o['source'] === 'trendyol' ? $marketplace($o, 'Picking') : $wooEvent($o, 'active', 'processing');
$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, open_exception_uuid AS exception,
    production_completed_at AS completed, operational_status AS availability, cancellation_state AS cancellation, production_stopped_at AS stopped, version AS orderVersion FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$control = static fn (array $o): ?array => $rows('SELECT * FROM order_cancellation_controls WHERE order_uuid = ?', [$o['uuid']])[0] ?? null;
$eventTypes = static fn (array $o): array => array_column($rows('SELECT event_type FROM order_cancellation_events WHERE order_uuid = ? ORDER BY event_seq', [$o['uuid']]), 'event_type');
$claim = static function (array $who, array $o) use ($post, $ok, $state, $pdo, $key): array {
    return $ok($post($who, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $who['employeeUuid'], (int) $state($o)['version']), $key('claim')), "claim {$o['id']} at " . $state($o)['stage']);
};
$advance = static function (array $who, array $o, array $extra = []) use ($post, $ok, $state, $key): array {
    return $ok($post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']] + $extra, $key('move')), "transition {$o['id']} at " . $state($o)['stage']);
};
$intakeBody = static function (array $who, array $o) use ($pdo): array {
    $count = (new \Arasya\Operations\Cutting\CuttingLifecycle($pdo))->ownedCount($who['employeeUuid']);
    return ['qrToken' => $o['qr']] + ($count > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $count] : []);
};
$intake = static fn (array $who, array $o, ?string $idem = null): array => $post($who, '/cutting/intake', $intakeBody($who, $o), $idem ?? $key('intake'));
$stageWorker = static fn (string $stage): array => match ($stage) { 'waiting' => $waiter, 'material-preparation' => $cutter, default => $worker };
// Brings an order to $target and leaves it there, claimed by the stage employee when $claimed (stage 1 Trendyol orders
// enter cutting through the QR intake, as in production).
$bring = static function (array $o, string $target, bool $claimed = true) use ($claim, $advance, $state, $check, $stageWorker, $intake, $ok, $cutter): void {
    $guard = 0;
    while (true) {
        $current = $state($o);
        if ($current['stage'] === $target) {
            if ($claimed && $current['owner'] === null) $claim($stageWorker($target), $o);
            return;
        }
        if ($current['stage'] === 'waiting' && $o['source'] === 'trendyol') {
            $ok($intake($cutter, $o), 'Trendyol cutting intake');
        } else {
            if ($current['owner'] === null) $claim($stageWorker($current['stage']), $o);
            $advance($stageWorker($current['stage']), $o);
        }
        $check(++$guard < 20, 'bring terminates');
    }
};
$staffView = static fn (array $who, array $o): array => $ok($get($who, $o['path']), "Staff view {$o['id']}");
$dashView = static fn (array $o): array => $ok($dashGet($root, '/management/orders/' . rawurlencode($o['id'])), "Dashboard view {$o['id']}");
$cancellationView = static fn (array $who, array $o): array => $dashGet($who, '/management/orders/' . rawurlencode($o['id']) . '/cancellation');
$stop = static fn (array $who, array $o, array $body, ?string $idem = null): array => $dash($who, 'POST', '/management/orders/' . rawurlencode($o['id']) . '/cancellation/stop', $body, $idem);
$physical = static fn (array $who, array $o, array $body, ?string $idem = null): array => $dash($who, 'POST', '/management/orders/' . rawurlencode($o['id']) . '/cancellation/physical', $body + ['expectedVersion' => (int) ($control($o)['version'] ?? 0)], $idem);
$release = static fn (array $who, array $o, array $body = [], ?string $idem = null): array => $dash($who, 'POST', '/management/orders/' . rawurlencode($o['id']) . '/cancellation/release', $body + ['expectedVersion' => (int) ($control($o)['version'] ?? 0), 'reason' => 'Clientul a confirmat comanda din nou.'], $idem);
$queueIds = static fn (array $who, string $stage): array => array_column($ok($get($who, '/orders/stage-queue', ['stage' => $stage]), "queue {$stage}")['items'] ?? [], 'id');
$activityCount = static fn (array $o): int => (int) $one('SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ?', [$o['uuid']]);
$history = static fn (array $o): array => $rows('SELECT action, from_stage_id, to_stage_id, employee_uuid, production_version_before FROM order_activity_events WHERE order_uuid = ? ORDER BY production_version_after, occurred_at', [$o['uuid']]);
$qrRows = static fn (array $o): array => $rows('SELECT qr_reference, status FROM order_qr_references WHERE order_uuid = ? ORDER BY created_at', [$o['uuid']]);
$revisions = static fn (array $o): int => (int) $one('SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = ?', [$o['uuid']]);
$race = static function (array $jobs) use ($dbName): array {
    $start = (string) (microtime(true) + 0.6);
    $workers = [];
    foreach ($jobs as $job) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cancellation-worker.php', $dbName, $start, ...$job], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $results[] = json_decode($out, true) ?? ['status' => 0, 'error' => trim($out . ' ' . $err)];
    }
    return $results;
};
$httpJob = static fn (array $who, string $path, array $body, ?string $origin = null): array => ['http', 'POST', $path, json_encode($body, JSON_THROW_ON_ERROR), 'cx-race-' . bin2hex(random_bytes(8)), $who['cookie'], $who['csrf'], ...($origin === null ? [] : [$origin])];
$cancelJob = static function (array $o) use (&$wooItems, $tick, $trendyolPackage): array {
    if ($o['source'] === 'trendyol') return ['trendyol', json_encode($trendyolPackage($o, 'Cancelled'), JSON_THROW_ON_ERROR)];
    return ['ingest', $o['source'], json_encode(T::sourceOrder($o['number'], 'cx-race-' . bin2hex(random_bytes(6)), $tick($o['id']), null, 'cancelled', 'cancelled', $wooItems[$o['id']], $o['number']), JSON_THROW_ON_ERROR)];
};
$transitionBody = static fn (array $o): array => ['expectedVersion' => (int) $state($o)['version']];
// A stopped order refuses with ORDER_UNAVAILABLE while the source reports it cancelled (the established contract) and with
// ORDER_PRODUCTION_STOPPED otherwise; both are the shared guard.
const STOP_CODES = ['ORDER_PRODUCTION_STOPPED', 'ORDER_UNAVAILABLE'];
$stopped = static fn (array $r): bool => $r['status'] === 409 && in_array($r['body']['error']['code'] ?? '', STOP_CODES, true);
$localStopped = static fn (array $r): bool => $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'ORDER_PRODUCTION_STOPPED';
$lastEvent = static fn (array $o): ?string => ($types = $eventTypes($o)) === [] ? null : $types[count($types) - 1];

// ---- C1. Three sources x fourteen stages ------------------------------------------------------------------------------
$matrix = 0;
foreach (['trendhome', 'outletperdele', 'trendyol'] as $source) {
    foreach ($canonical as $ordinal => $stage) {
        $tag = substr($source, 0, 2) . ($ordinal + 1);
        $o = $source === 'trendyol' ? $trendyol($tag) : $woo($source, $tag, $stage);
        // Stage 1 stays unclaimed (never started); every later stage is owned by its employee when the stop arrives.
        $claimed = $stage !== 'waiting';
        $bring($o, $stage, $claimed);
        $before = $state($o);
        $historyBefore = $history($o);
        $qrBefore = $qrRows($o);
        $owner = $before['owner'];
        $label = "C1 {$source} {$stage}";
        $result = $cancel($o);
        if (is_array($result)) $ok($result, "{$label} cancellation ingested");
        $after = $state($o);
        $check($after['stage'] === $stage && $after['owner'] === $owner && $after['completed'] === null, "{$label}: stage and owner are preserved");
        $check($after['cancellation'] === 'held' && $after['stopped'] !== null && (int) $after['version'] === (int) $before['version'] && $after['availability'] === 'unavailable', "{$label}: production is stopped, the production version untouched: " . json_encode($after));
        $check($history($o) === $historyBefore && $qrRows($o) === $qrBefore, "{$label}: production history and QR unchanged");
        $c = $control($o);
        $notStarted = $stage === 'waiting';
        $check($c['origin'] === 'source' && $c['reason_code'] === ($source === 'trendyol' ? 'marketplace_cancelled' : 'source_cancelled') && $c['source_status'] === 'cancelled'
            && $c['stage_id_at_hold'] === $stage && $c['owner_employee_uuid_at_hold'] === $owner && (int) $c['production_version_at_hold'] === (int) $before['version']
            && $c['production_phase'] === ($notStarted ? 'not_started' : 'in_progress') && $c['physical_status'] === ($notStarted ? 'not_required' : 'pending'), "{$label}: control row " . json_encode($c));
        $check($eventTypes($o) === ($notStarted ? ['source_cancellation_received', 'hold_applied'] : ['source_cancellation_received', 'hold_applied', 'physical_reconciliation_requested']), "{$label}: ordered audit " . json_encode($eventTypes($o)));
        // Every manufacturing command is refused, whoever sends it.
        if ($owner !== null) {
            $ownerSession = $stageWorker($stage);
            $check($stopped($post($ownerSession, $o['path'] . '/transition', ['expectedVersion' => (int) $after['version']])), "{$label}: the owner cannot complete the stage");
            $check($stopped($post($ownerSession, $o['path'] . '/transition', ['expectedVersion' => (int) $before['version'] - 1])), "{$label}: a stale screen is refused with the stop, not a version error");
            $view = $staffView($ownerSession, $o);
            $check(($view['productionStop']['state'] ?? null) === 'stopped' && ($view['employeeActionBlockedReason'] ?? null) === 'order_unavailable' && !isset($view['employeeAllowedAction'])
                && $view['productionStop']['canContinue'] === false && $view['productionStop']['sourceStatus'] === 'cancelled' && $view['productionStageId'] === $stage, "{$label}: Staff shows the stop read-only");
            $other = $stage === 'material-preparation' ? $cutter2 : $worker2;
            $check($stopped($post($other, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $other['employeeUuid'], (int) $after['version']))), "{$label}: nobody else can claim it");
        } elseif ($source === 'trendyol') {
            $check($stopped($intake($cutter, $o)), "{$label}: the cutting intake by QR is refused");
        } else {
            $check($stopped($post($waiter, $o['path'] . '/claim', ['expectedVersion' => (int) $after['version']])), "{$label}: stage 1 cannot be claimed");
        }
        $check(!in_array($o['id'], $queueIds($stageWorker($stage), $stage), true), "{$label}: not a claimable task in the stage queue");
        $dashboard = $dashView($o);
        $check($dashboard['production']['state'] === 'cancelled' && $dashboard['production']['stop']['category'] === 'source_cancelled'
            && $dashboard['production']['stop']['nextAction'] === ($notStarted ? 'none' : 'physical_reconciliation') && $dashboard['production']['stage']['id'] === $stage, "{$label}: Dashboard agrees " . json_encode($dashboard['production']));
        $check($state($o)['stage'] === $stage && $history($o) === $historyBefore, "{$label}: still nothing moved");
        $matrix++;
        $GLOBALS['matrixOrders'][$source][$stage] = $o;
    }
}
$check($matrix === 42, 'C1 42 baseline positions');
$check($stagesNow() === $canonical, 'C1 the workflow is unchanged after the matrix');

// ---- C2. Completed production followed by a cancellation (stage 14 case 2) and stage 13 / 14 before completion --------
$done = $woo('trendhome', 'done', 'delivery');
$bring($done, 'delivery');
$advance($worker, $done);
$completedAt = $state($done)['completed'];
$check($completedAt !== null, 'C2 production completed');
$completedToday = (int) $ok($dashGet($root, '/management/production-overview'), 'overview')['summary']['completedToday'];
$ok($cancel($done), 'C2 cancellation after completion');
$after = $state($done);
$check($after['completed'] === $completedAt && $after['stage'] === 'delivery' && $after['cancellation'] === 'held', 'C2 the completion is kept and nothing reopens');
$check($control($done)['production_phase'] === 'completed' && $control($done)['production_completed_at_hold'] === $completedAt && $control($done)['physical_status'] === 'pending', 'C2 cancellation after completion is recorded with its disposition pending');
$check((int) $ok($dashGet($root, '/management/production-overview'), 'overview')['summary']['completedToday'] === $completedToday, 'C2 the completion metric is not erased');
$dashboard = $dashView($done);
$check($dashboard['production']['state'] === 'completed' && $dashboard['production']['stop']['category'] === 'completed_then_cancelled', 'C2 Dashboard: completed, then cancelled');
$error($release($root, $done), 409, 'RELEASE_NOT_APPLICABLE', 'C2 a completed order is never released into production');
$error($physical($keeper, $done, ['outcome' => 'nothing_produced', 'note' => 'nu s-a produs']), 422, 'PHYSICAL_OUTCOME_INVALID', 'C2 a completed order cannot be declared unproduced');
$ok($physical($keeper, $done, ['outcome' => 'left_factory', 'note' => 'Coletul a fost predat curierului înainte de anulare.']), 'C2 disposition: left the factory');
$check($control($done)['physical_status'] === 'verified' && $state($done)['completed'] === $completedAt && $dashView($done)['production']['stop']['nextAction'] === 'none', 'C2 disposition recorded, no rollback');
$packing = $GLOBALS['matrixOrders']['trendhome']['packing'];
$delivery = $GLOBALS['matrixOrders']['trendyol']['delivery'];
$check($state($packing)['stage'] === 'packing' && $state($delivery)['stage'] === 'delivery' && $state($delivery)['completed'] === null, 'C2 stage 13 and 14 orders stay where they were (no backwards transition)');
$ok($physical($keeper, $packing, ['outcome' => 'left_factory', 'note' => 'Ambalat; a plecat cu curierul.']), 'C2 a packed order may be declared gone');

// ---- C3. Source evidence ----------------------------------------------------------------------------------------------
$e = $woo('trendhome', 'evid', 'ironing');
$bring($e, 'ironing');
$eventId = 'cx-evid-cancel-' . $suffix;
$ok($wooEvent($e, 'cancelled', 'cancelled', $eventId), 'C3 cancellation');
$c1 = $control($e);
$typesAfterFirst = $eventTypes($e);
// N (sequential form): the identical delivery again is a duplicate receipt and changes nothing.
$payload = T::sourceOrder($e['number'], $eventId, gmdate('Y-m-d\TH:i:s\Z', $base + $events[$e['id']] * 7), null, 'cancelled', 'cancelled', $wooItems[$e['id']], $e['number']);
$check($ok(T::ingest($kernel, 'trendhome', $payload), 'C3 replayed delivery')['outcome'] === 'duplicate', 'C3 a repeated network delivery is a duplicate');
$check($control($e) == $c1 && $eventTypes($e) === $typesAfterFirst, 'C3 the duplicate creates no stop and no audit row');
// A new event with the same meaning (different id, still cancelled) updates nothing either.
$ok($wooEvent($e, 'cancelled', 'cancelled'), 'C3 the same cancellation in a new event');
$check((int) $control($e)['version'] === (int) $c1['version'] && $eventTypes($e) === $typesAfterFirst, 'C3 the same cancellation twice is one stop');
// An older event arriving late never overwrites the newer cancellation.
$old = T::sourceOrder($e['number'], 'cx-evid-old-' . $suffix, gmdate('Y-m-d\TH:i:s\Z', $base + 1), null, 'processing', 'active', $wooItems[$e['id']], $e['number']);
$check($ok(T::ingest($kernel, 'trendhome', $old), 'C3 late older event')['outcome'] === 'out_of_order', 'C3 the older event is out of order');
$check($control($e)['source_status'] === 'cancelled' && $state($e)['availability'] === 'unavailable' && $state($e)['cancellation'] === 'held', 'C3 the newer cancellation stands');
// Reactivation at the source records the evidence and keeps the stop.
$ok($reactivate($e), 'C3 reactivated at the source');
$check($state($e)['cancellation'] === 'held' && $state($e)['availability'] === 'in_progress' && $control($e)['source_status'] === 'reactivated', 'C3 reactivation keeps production stopped');
$check(in_array('source_reactivated', $eventTypes($e), true), 'C3 reactivation is in the audit');
$check($localStopped($post($worker, $e['path'] . '/transition', ['expectedVersion' => (int) $state($e)['version']])), 'C3 the source never releases production (ORDER_PRODUCTION_STOPPED once the source is active)');
$check(($staffView($worker, $e)['employeeActionBlockedReason'] ?? null) === 'production_stopped', 'C3 Staff: stopped, awaiting a release');
$check($dashView($e)['production']['state'] === 'stopped' && $dashView($e)['production']['stop']['category'] === 'reactivated_awaiting_release', 'C3 Dashboard: reactivated, awaiting release');
// A Trendyol reactivation behaves the same.
$te = $trendyol('evid');
$bring($te, 'labeling');
$marketplace($te, 'Cancelled');
$check($marketplace($te, 'Cancelled') === 'unchanged' && count($eventTypes($te)) === 3, 'C3 an unchanged marketplace copy adds nothing');
$marketplace($te, 'Picking');
$check($state($te)['cancellation'] === 'held' && $control($te)['source_status'] === 'reactivated' && $state($te)['availability'] === 'in_progress', 'C3 a Trendyol status after the cancellation keeps the stop');

// ---- C4. Local stop ---------------------------------------------------------------------------------------------------
$l = $woo('outletperdele', 'local', 'height');
$bring($l, 'height');
$error($stop($denisa, $l, ['reason' => 'Clientul a sunat pentru anulare.']), 403, 'UNAUTHORIZED_ACTION', 'C4 an operations manager without the permission cannot stop production');
$error($stop($worker, $l, ['reason' => 'Clientul a sunat pentru anulare.']), 403, 'APPLICATION_ACCESS_DENIED', 'C4 a Staff worker cannot stop production');
$error($stop($keeper, $l, ['reason' => 'scurt']), 422, 'VALIDATION_FAILED', 'C4 a reason is required');
$stopKey = $key('stop');
$first = $ok($stop($keeper, $l, ['reason' => 'Clientul a sunat pentru anulare.', 'requestSourceCancellation' => true], $stopKey), 'C4 local stop with a source request');
$check($first['cancellation']['state'] === 'stopped' && $first['cancellation']['category'] === 'pending_source_confirmation' && $first['cancellation']['source']['request'] === 'pending'
    && $first['cancellation']['source']['outbound'] === 'not_sent' && $first['sourceAvailability'] === 'active', 'C4 stopped locally, request pending, nothing sent, source still active');
$check($state($l)['availability'] === 'in_progress' && $state($l)['cancellation'] === 'held', 'C4 the source availability is not falsified');
$replayed = $ok($stop($keeper, $l, ['reason' => 'Clientul a sunat pentru anulare.', 'requestSourceCancellation' => true], $stopKey), 'C4 replay');
$check($replayed == $first && count($eventTypes($l)) === count($eventTypes($l)), 'C4 a replay returns the committed result');
$typesLocal = $eventTypes($l);
$ok($stop($keeper, $l, ['reason' => 'A doua oprire din alt ecran.']), 'C4 duplicate stop');
$check(array_slice($eventTypes($l), 0, count($typesLocal)) === $typesLocal && $lastEvent($l) === 'duplicate_ignored' && (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'hold_applied'", [$l['uuid']]) === 1, 'C4 a duplicate stop is one stop (diagnostic event only)');
$check($localStopped($post($worker, $l['path'] . '/transition', ['expectedVersion' => (int) $state($l)['version']])), 'C4 the local stop blocks the owner');
$check(($staffView($worker, $l)['productionStop']['origin'] ?? null) === 'local', 'C4 Staff shows a local stop');
$ok($cancel($l), 'C4 the source confirms the cancellation');
$check($control($l)['source_request_status'] === 'confirmed' && $control($l)['source_status'] === 'cancelled' && in_array('source_cancellation_confirmed', $eventTypes($l), true)
    && (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'hold_applied'", [$l['uuid']]) === 1, 'C4 confirmed by the source, still one stop');

// ---- C5. Physical reconciliation and release --------------------------------------------------------------------------
$r = $woo('trendhome', 'rel', 'side-hem');
$bring($r, 'side-hem');
$ownerAtStop = $state($r)['owner'];
$versionBeforeStop = (int) $state($r)['version'];
$ok($cancel($r), 'C5 cancellation');
$error($release($releaser, $r), 409, 'RELEASE_SOURCE_CANCELLED', 'C5 never released while the source reports it cancelled');
$ok($reactivate($r), 'C5 reactivated at the source');
$error($release($denisa, $r), 403, 'UNAUTHORIZED_ACTION', 'C5 an approver of exceptions cannot release a stop');
$error($release($keeper, $r), 403, 'UNAUTHORIZED_ACTION', 'C5 the stop permission is not the release permission');
$error($release($releaser, $r), 409, 'RELEASE_PHYSICAL_UNVERIFIED', 'C5 the physical state must be verified first');
$check($lastEvent($r) === 'release_refused', 'C5 a refused release is audited');
$error($physical($keeper, $r, ['outcome' => 'secured', 'note' => 'În raftul de oprire.']), 422, 'QR_REQUIRED', 'C5 securing the product needs a scan');
$error($physical($keeper, $r, ['outcome' => 'secured', 'note' => 'În raftul de oprire.', 'qrToken' => $e['qr']]), 422, 'QR_ORDER_MISMATCH', 'C5 the QR of another order is refused');
$error($physical($keeper, $r, ['outcome' => 'secured', 'note' => 'În raftul de oprire.', 'qrToken' => 'ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA']), 422, 'INVALID_QR', 'C5 an unknown code is refused');
$error($physical($keeper, $r, ['outcome' => 'secured', 'note' => 'În raftul de oprire.', 'qrToken' => $r['qr'], 'expectedVersion' => 1]), 409, 'CANCELLATION_CHANGED', 'C5 a stale stop version is refused');
$ok($physical($keeper, $r, ['outcome' => 'secured', 'note' => 'În raftul de oprire, etichetat.', 'qrToken' => $r['qr']]), 'C5 product secured with its QR');
$check($control($r)['physical_status'] === 'verified' && $control($r)['physical_scan'] === 'order_qr' && $control($r)['physical_verified_by_employee_uuid'] === $keeper['employeeUuid'], 'C5 physical evidence recorded');
$check($state($r)['owner'] === $ownerAtStop && $state($r)['stage'] === 'side-hem', 'C5 reconciliation moved nothing');
$error($release($releaser, $r, ['expectedVersion' => 1]), 409, 'CANCELLATION_CHANGED', 'C5 a stale release version is refused');
$error($release($releaser, $r, ['reason' => 'ok']), 422, 'VALIDATION_FAILED', 'C5 a release needs a reason');
// A role removed while the release screen was open decides nothing.
$ok($dash($root, 'PUT', "/management/employees/{$releaser2['employeeUuid']}/roles", ['roleIds' => []]), 'C5 the second releaser loses the role');
$error($release($releaser2, $r), 403, 'UNAUTHORIZED_ACTION', 'C5 revoked release authority is refused');
$releaseKey = $key('release');
$released = $ok($release($releaser, $r, [], $releaseKey), 'C5 authorized release');
$check($released['cancellation']['state'] === 'released' && $state($r)['cancellation'] === 'released' && $state($r)['stopped'] === null, 'C5 production released');
$check($state($r)['owner'] === $ownerAtStop && $state($r)['stage'] === 'side-hem' && (int) $state($r)['version'] === $versionBeforeStop + 1, 'C5 resumed from the same stage and owner (the release is the one production version step)');
$check($ok($release($releaser, $r, ['expectedVersion' => (int) $released['cancellation']['version'] - 1], $releaseKey), 'C5 release replay') == $released, 'C5 the release replays');
$error($post($worker, $r['path'] . '/transition', ['expectedVersion' => $versionBeforeStop]), 409, 'ORDER_CHANGED', 'C5 (M) a request prepared before the stop cannot apply after the release');
$advance($worker, $r);
$check($state($r)['stage'] === 'ironing', 'C5 normal production continues only after the release');
$typesReleased = $eventTypes($r);
$check(in_array('release_approved', $typesReleased, true) && in_array('physical_reconciliation_recorded', $typesReleased, true), 'C5 release audited');
// A later cancellation after the release stops again (a new stop in the same control row, history kept).
$ok($cancel($r), 'C5 cancelled again');
$check($state($r)['cancellation'] === 'held' && (int) $control($r)['version'] > (int) $released['cancellation']['version'] && $control($r)['physical_status'] === 'pending'
    && (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'hold_applied'", [$r['uuid']]) === 2, 'C5 a new stop after a release');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action IN ('production.cancellation.released', 'production.cancellation.physical_recorded', 'production.cancellation.stopped') AND target_id = ?", [$r['id']]) === 2, 'C5 the IAM audit names the person of each decision');

// Release of a local stop that the source never cancelled; root holds every permission.
$ls = $woo('outletperdele', 'lrel', 'labeling');
$bring($ls, 'labeling', false);
$ok($stop($keeper, $ls, ['reason' => 'Verificare internă a comenzii.']), 'C5 local stop of an unclaimed order');
$check($control($ls)['physical_status'] === 'pending' && $control($ls)['source_request_status'] === 'not_requested', 'C5 an order past cutting needs a physical check');
$ok($physical($keeper, $ls, ['outcome' => 'secured', 'note' => 'Produsul este în coșul etapei.', 'qrToken' => $ls['qr']]), 'C5 secured');
$ok($release($root, $ls), 'C5 root releases a local stop');
$check($state($ls)['cancellation'] === 'released' && in_array($ls['id'], $queueIds($worker, 'labeling'), true), 'C5 a released unclaimed order is claimable again');

// ---- C6. Open quality exceptions (supervised tailoring flow), including a synthetic EX-000001-style recovery -----------
$ok($dash($ceo, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_quality_supervisor', 'employeeId' => $uuids['iuliana']]), 'C6 the CEO appoints the supervisor', 201);
$exVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$exStatus = static fn (string $id): string => (string) $one('SELECT status FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$exEvents = static fn (string $id): int => (int) $one('SELECT COUNT(*) FROM production_exception_events WHERE exception_uuid = ?', [$id]);
$report = static fn (array $who, array $o) => $post($who, $o['path'] . '/fault-reports', ['expectedVersion' => (int) $state($o)['version'], 'itemIds' => [$o['items'][0]], 'reasonKey' => 'cut-meterage', 'comment' => 'Metraj scurt la bucata 1']);
$review = static fn (array $who, string $id, array $body) => $post($who, "/production-exceptions/{$id}/review", ['expectedVersion' => $exVersion($id)] + $body);
$sendReturn = static fn (array $who, string $id) => $post($who, "/production-exceptions/{$id}/return", ['expectedVersion' => $exVersion($id)]);
$ack = static fn (array $who, string $id, string $qr) => $post($who, "/production-exceptions/{$id}/acknowledge", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr, 'responsibility' => 'accepted']);
$decide = static fn (array $who, string $id, array $body) => $dash($who, 'POST', "/management/production-exceptions/{$id}/decision", ['expectedVersion' => $exVersion($id)] + $body);
$restore = static fn (array $who, string $id, string $qr, array $extra = []) => $post($who, "/production-exceptions/{$id}/restore", ['expectedVersion' => $exVersion($id), 'qrToken' => $qr, 'comment' => 'Produsul revine la croitorie'] + $extra);
$receive = static fn (array $who, string $id, string $qr) => $post($who, "/production-exceptions/{$id}/receive", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr]);
// Each case: an order cut by $cutter, brought to the detection stage by $tailor, reported there.
$caseOrder = static function (string $tag, string $stage) use ($woo, $claim, $advance, $state, $cutter, $tailor, $check): array {
    $o = $woo('trendhome', $tag, 'material-preparation');
    $claim($cutter, $o);
    $advance($cutter, $o);
    while (true) {
        $claim($tailor, $o);
        if ($state($o)['stage'] === $stage) break;
        $advance($tailor, $o);
    }
    $check($state($o)['stage'] === $stage, "case order at {$stage}");
    return $o;
};
// (a) awaiting supervisor review
$qa = $caseOrder('qa', 'ironing');
$xa = $ok($report($tailor, $qa), 'C6a report', 201)['id'];
$ok($cancel($qa), 'C6a cancellation');
$eventsA = $exEvents($xa);
$check($stopped($review($iuliana, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $qa['qr']])), 'C6a custody acceptance is refused (it would send work back)');
$check($exStatus($xa) === 'awaiting_supervisor_review' && $exEvents($xa) === $eventsA, 'C6a the case is neither dismissed nor accepted as a side effect');
$check($control($qa)['exception_uuid_at_hold'] === $xa, 'C6a the open case is referenced by the stop');
$qa2 = $caseOrder('qa2', 'labeling');
$ok($cancel($qa2), 'C6a2 cancellation before any report');
$check($stopped($report($tailor, $qa2)), 'C6a no new defect report on a stopped order');
$check((int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ?', [$qa2['uuid']]) === 0, 'C6a nothing was reported');
// (b) in supervisor custody: the return to cutting is refused, the handover back to tailoring stays possible
$qb = $caseOrder('qb', 'side-hem');
$xb = $ok($report($tailor, $qb), 'C6b report', 201)['id'];
$ok($review($iuliana, $xb, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $qb['qr']]), 'C6b custody');
$ok($cancel($qb), 'C6b cancellation');
$check($stopped($sendReturn($iuliana, $xb)), 'C6b no return to cutting while stopped');
$check($exStatus($xb) === 'in_supervisor_custody', 'C6b custody preserved');
$ok($restore($iuliana, $xb, $qb['qr']), 'C6b the supervisor hands the product back (physical)');
$ok($receive($tailor, $xb, $qb['qr']), 'C6b the tailor confirms receipt (physical)');
$check($exStatus($xb) === 'restored' && $state($qb)['stage'] === 'side-hem' && $state($qb)['owner'] === $tailor['employeeUuid'] && $state($qb)['cancellation'] === 'held', 'C6b the holder is recorded, production stays stopped');
$check($stopped($post($tailor, $qb['path'] . '/transition', ['expectedVersion' => (int) $state($qb)['version']])), 'C6b the received product is not workable');
// (c) awaiting cutter acknowledgment -> (d) awaiting approval -> rejection -> (e) return rejected -> restore -> (f) receipt
$qc = $caseOrder('qc', 'labeling');
$xc = $ok($report($tailor, $qc), 'C6c report', 201)['id'];
$ok($review($iuliana, $xc, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $qc['qr']]), 'C6c custody');
$ok($sendReturn($iuliana, $xc), 'C6c return sent');
$check($exStatus($xc) === 'awaiting_acknowledgment', 'C6c awaiting cutter acknowledgment');
$ok($cancel($qc), 'C6c cancellation');
$ok($ack($cutter, $xc, $qc['qr']), 'C6c the cutter still records the physical receipt');
$check($exStatus($xc) === 'awaiting_approval', 'C6d awaiting manager approval');
$check($stopped($decide($denisa, $xc, ['decision' => 'approve', 'attribution' => 'cutter'])), 'C6d approval of rework is refused while stopped');
$check($exStatus($xc) === 'awaiting_approval' && $state($qc)['stage'] === 'workshop-receiving', 'C6d nothing moved back to cutting');
$ok($decide($denisa, $xc, ['decision' => 'reject', 'comment' => 'Comanda este anulată; nu se reface.']), 'C6d a rejection stays possible');
$check($exStatus($xc) === 'return_rejected', 'C6e manager-rejected return');
$ok($restore($iuliana, $xc, $qc['qr']), 'C6e restore after the rejection');
$check($exStatus($xc) === 'awaiting_restore_receipt', 'C6f awaiting restore receipt');
$ok($receive($tailor, $xc, $qc['qr']), 'C6f receipt');
$check($exStatus($xc) === 'restored' && $state($qc)['cancellation'] === 'held', 'C6f restored, still stopped');
// Synthetic EX-000001-style sequence: report, custody, return requested, withdrawn before the cutter's receipt (restore with
// cancelReturn), cancellation arrives while the restore receipt is pending, the original owner receives the product.
$qx = $caseOrder('qx', 'ironing');
$xx = $ok($report($tailor, $qx), 'EX-like report', 201)['id'];
$ok($review($iuliana, $xx, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $qx['qr']]), 'EX-like custody');
$ok($sendReturn($iuliana, $xx), 'EX-like return requested');
$ok($restore($iuliana, $xx, $qx['qr'], ['cancelReturn' => true]), 'EX-like return withdrawn, restore to the owner');
$check($exStatus($xx) === 'awaiting_restore_receipt' && $one('SELECT restore_recipient_employee_uuid FROM production_exceptions WHERE exception_uuid = ?', [$xx]) === $tailor['employeeUuid'], 'EX-like: awaiting the original owner');
$ok($cancel($qx), 'EX-like cancellation while the receipt is pending');
$error($receive($worker2, $xx, $qx['qr']), 403, 'EXCEPTION_NOT_ASSIGNED', 'EX-like nobody else receives it');
$ok($receive($tailor, $xx, $qx['qr']), 'EX-like the original owner receives the product');
$check($exStatus($xx) === 'restored' && $state($qx)['stage'] === 'ironing' && $state($qx)['owner'] === $tailor['employeeUuid'] && $state($qx)['cancellation'] === 'held', 'EX-like: custody recorded, production stopped');
// Approved rework: the order is back at cutting with the responsible cutter when the cancellation arrives.
$qr = $caseOrder('qr', 'height');
$xr = $ok($report($tailor, $qr), 'C6 rework report', 201)['id'];
$ok($review($iuliana, $xr, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $qr['qr']]), 'C6 rework custody');
$ok($sendReturn($iuliana, $xr), 'C6 rework return');
$ok($ack($cutter, $xr, $qr['qr']), 'C6 rework receipt');
$ok($decide($denisa, $xr, ['decision' => 'approve', 'attribution' => 'cutter']), 'C6 rework approved');
$check($state($qr)['stage'] === 'material-preparation' && $state($qr)['owner'] === $cutter['employeeUuid'], 'C6 approved rework at cutting');
$ok($cancel($qr), 'C6 cancellation during approved rework');
$check($stopped($post($cutter, $qr['path'] . '/transition', ['expectedVersion' => (int) $state($qr)['version'], 'pieceOutcome' => 'repair'])), 'C6 re-cutting is refused');
$check($state($qr)['stage'] === 'material-preparation' && $exStatus($xr) === 'approved', 'C6 rework history preserved');

// ---- C7. Multi-piece, mixed PERDEA + DRAPERII, a route track at another stage (flags on in this isolated database) -------
$features = new FeatureActivation($pdo);
$nowSql = static fn (): string => gmdate('Y-m-d H:i:s.000000');
$m = $trendyol('mix');
$check($features->setCohortMember(FeatureActivation::PIECE_ROUTING, $m['uuid'], true, 'test-operator', 'cancellation suite pilot', $nowSql())['member'] === true, 'C7 piece routing cohort');
$plan = $ok($get($iuliana, $m['path'] . '/manufacturing-plan'), 'C7 plan');
$hashes = array_column($plan['lines'], 'sourcePlanHash', 'itemId');
$ok($post($iuliana, $m['path'] . '/manufacturing-plan/lines/' . $m['items'][0] . '/confirm', ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată', 'expectedSourceHash' => $hashes[$m['items'][0]]]), 'C7 DRAPERII line');
$ok($post($iuliana, $m['path'] . '/manufacturing-plan/lines/' . $m['items'][1] . '/confirm', ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată', 'expectedSourceHash' => $hashes[$m['items'][1]]]), 'C7 PERDEA line');
$bring($m, 'material-straightening');
$advance($worker, $m);
$tracks = $rows("SELECT track_uuid, stage_id, version FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$m['uuid']]);
$check(count($tracks) === 1 && $tracks[0]['stage_id'] === 'ironing' && $state($m)['stage'] === 'bottom-hem', 'C7 the PERDEA piece travels on its own route (two pieces in two stages)');
$trackId = (string) $tracks[0]['track_uuid'];
$ok($post($worker2, "/production-tracks/{$trackId}/claim", ['expectedVersion' => (int) $tracks[0]['version'], 'qrToken' => $m['qr']]), 'C7 the track is claimed');
$marketplace($m, 'Cancelled');
$trackAfter = $rows('SELECT stage_id, owner_employee_uuid, version, status FROM production_tracks WHERE track_uuid = ?', [$trackId])[0];
$check($trackAfter['stage_id'] === 'ironing' && $trackAfter['owner_employee_uuid'] === $worker2['employeeUuid'] && $trackAfter['status'] === 'active', 'C7 the track keeps its stage and owner');
$check(count(json_decode((string) $control($m)['active_tracks_at_hold'], true)) === 1, 'C7 the stop records the track');
$check($stopped($post($worker2, "/production-tracks/{$trackId}/complete", ['expectedVersion' => (int) $trackAfter['version']])), 'C7 the track cannot continue independently');
$check($stopped($post($worker, $m['path'] . '/claim', ['expectedVersion' => (int) $state($m)['version']])), 'C7 the order cannot continue');
$pieces = $staffView($worker2, $m)['pieces']['tracks'][0];
$check($pieces['allowedAction'] === null && $pieces['blockedReason'] === 'order_unavailable', 'C7 Staff shows the track stopped too');
$check((int) $one('SELECT COUNT(*) FROM production_unit_custody WHERE order_uuid = ?', [$m['uuid']]) === 0, 'C7 no piece is marked recovered without evidence');
// The informational PERDEA 6/7 rule is untouched by this release: the flag stays off and nothing became a gate.
$check((int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'") === 0, 'C7 the PERDEA status flag stays off');

// ---- C8. Documents and QR -----------------------------------------------------------------------------------------------
$d = $woo('outletperdele', 'doc', 'labeling');
$docPath = static fn (array $o, string $action = ''): string => '/production-documents/orders/' . rawurlencode($o['id']) . ($action === '' ? '' : "/{$action}");
$ok($post($root, $docPath($d, 'generate'), ['expectedDocumentVersion' => (int) $one('SELECT document_version FROM operational_orders WHERE order_uuid = ?', [$d['uuid']])]), 'C8 document generated', 201);
// A printed field changes at the source: the document becomes stale and a revision is requested (not yet approved).
$wooItems[$d['id']][0]['meters'] = 7.7;
$ok($wooEvent($d, 'active', 'processing'), 'C8 content change');
$ok($post($root, $docPath($d, 'revision-requests'), ['expectedDocumentVersion' => (int) $one('SELECT document_version FROM operational_orders WHERE order_uuid = ?', [$d['uuid']]), 'comment' => 'Corectare lungime']), 'C8 a revision request is pending', 201);
$revisionsBefore = $revisions($d);
$requestsBefore = $rows('SELECT status FROM production_document_revision_requests WHERE order_uuid = ?', [$d['uuid']]);
$qrBefore = $qrRows($d);
$documentBefore = $one('SELECT document_status FROM operational_orders WHERE order_uuid = ?', [$d['uuid']]);
$ok($cancel($d), 'C8 cancellation');
$check($revisions($d) === $revisionsBefore && $rows('SELECT status FROM production_document_revision_requests WHERE order_uuid = ?', [$d['uuid']]) === $requestsBefore, 'C8 no document revision and the pending request is unchanged');
$check($qrRows($d) === $qrBefore && $one('SELECT document_status FROM operational_orders WHERE order_uuid = ?', [$d['uuid']]) === $documentBefore, 'C8 the QR is not rotated and the document state stays as it was');
$resolved = $ok($post($worker, '/orders/resolve-qr', ['token' => $d['qr']]), 'C8 scanning the current QR');
$check(($resolved['productionStop']['state'] ?? null) === 'stopped' && !isset($resolved['employeeAllowedAction']), 'C8 a valid QR shows the stop read-only and authorizes nothing');
// A superseded QR never serves a physical reconciliation; the current one does.
$oldQr = $d['qr'];
$container->projectionWriter()->rotateQrReference($d['id']);
$newQr = 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$d['uuid']]);
$check($control($d)['hold_status'] === 'held', 'C8 a QR rotation does not touch the stop');
$check($physical($keeper, $d, ['outcome' => 'nothing_produced', 'note' => 'Comanda era la etichetare; nu s-a tăiat.'])['status'] === 200, 'C8 an unproduced order may be closed without a scan');
$d2 = $woo('trendhome', 'doc2', 'quality-control');
$bring($d2, 'quality-control');
$ok($post($root, $docPath($d2, 'generate'), ['expectedDocumentVersion' => (int) $one('SELECT document_version FROM operational_orders WHERE order_uuid = ?', [$d2['uuid']])]), 'C8 second document', 201);
$old2 = $d2['qr'];
$container->projectionWriter()->rotateQrReference($d2['id']);
$ok($cancel($d2), 'C8 cancellation after a QR supersession');
$check($physical($keeper, $d2, ['outcome' => 'secured', 'note' => 'Raft oprire', 'qrToken' => $old2])['status'] >= 400, 'C8 the superseded QR is refused');
$current2 = 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$d2['uuid']]);
// A stale document blocks the release, independently of the stop (a content change at the source).
$ok($reactivate($d2), 'C8 reactivated');
$ok($physical($keeper, $d2, ['outcome' => 'secured', 'note' => 'Raft oprire', 'qrToken' => $current2]), 'C8 secured with the current QR');
$wooItems[$d2['id']][0]['meters'] = 9.1;
$ok($wooEvent($d2, 'active', 'processing'), 'C8 content change at the source');
$check($one('SELECT document_status FROM operational_orders WHERE order_uuid = ?', [$d2['uuid']]) !== 'active', 'C8 the document is stale');
$error($release($releaser, $d2), 409, 'RELEASE_DOCUMENT_NOT_READY', 'C8 the release waits for the document revision');

// ---- C9. Races ----------------------------------------------------------------------------------------------------------
$raceOutcome = static function (array $o, array $before, array $results, int $opIndex, string $label) use ($state, $check, $control, $one, $history): void {
    $after = $state($o);
    $op = $results[$opIndex];
    $check($after['cancellation'] === 'held', "{$label}: the cancellation is in force " . json_encode($results));
    $holds = (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'hold_applied'", [$o['uuid']]);
    $check($holds === 1, "{$label}: exactly one stop");
    if ($op['status'] === 200) {
        // The command committed first: it is history, and the stop applies afterwards.
        $check($control($o)['production_version_at_hold'] > $before['version'], "{$label}: the stop came after the committed command");
    } else {
        $check(in_array($op['error'], [...STOP_CODES, 'ORDER_CHANGED'], true), "{$label}: the refused command reports the stop: " . json_encode($op));
        $check($after['stage'] === $before['stage'] && $after['owner'] === $before['owner'] && $after['completed'] === $before['completed'], "{$label}: the refused command wrote nothing");
    }
};
// A: stage completion versus cancellation
for ($i = 0; $i < 3; $i++) {
    $o = $woo('trendhome', "ra{$i}", 'bottom-hem');
    $bring($o, 'bottom-hem');
    $before = $state($o);
    $results = $race([$httpJob($worker, $o['path'] . '/transition', $transitionBody($o)), $cancelJob($o)]);
    $raceOutcome($o, $before, $results, 0, "C9 A{$i}");
    $check(count($history($o)) === count($history($o)) && ($results[0]['status'] === 200) === ($state($o)['stage'] === 'side-hem'), "C9 A{$i}: the stage moved exactly when the completion committed");
}
// B: cutting intake versus a marketplace cancellation
for ($i = 0; $i < 2; $i++) {
    $o = $trendyol("rb{$i}");
    $before = $state($o);
    $results = $race([$httpJob($cutter2, '/cutting/intake', $intakeBody($cutter2, $o)), $cancelJob($o)]);
    $raceOutcome($o, $before, $results, 0, "C9 B{$i}");
    $check(($results[0]['status'] === 200) === ($state($o)['stage'] === 'material-preparation'), "C9 B{$i}: intake committed or nothing moved");
}
// C: two different cancellation events at once
$o = $woo('outletperdele', 'rc', 'ironing');
$bring($o, 'ironing');
$results = $race([$cancelJob($o), $cancelJob($o)]);
$check(count(array_filter($results, static fn (array $r): bool => $r['status'] === 200)) >= 1 && (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'hold_applied'", [$o['uuid']]) === 1 && $state($o)['cancellation'] === 'held', 'C9 C: two cancellations, one stop ' . json_encode($results));
// N: the same delivery twice at once
$o = $woo('trendhome', 'rn', 'height');
$bring($o, 'height');
$same = $cancelJob($o);
$results = $race([$same, $same]);
$check(count(array_filter($results, static fn (array $r): bool => ($r['outcome'] ?? null) === 'applied')) === 1 && (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ?", [$o['uuid']]) === 3, 'C9 N: a duplicated delivery is applied once ' . json_encode($results));
// D: cancellation versus a document revision request
$o = $woo('trendhome', 'rd', 'labeling');
$ok($post($root, $docPath($o, 'generate'), ['expectedDocumentVersion' => (int) $one('SELECT document_version FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])]), 'C9 D document', 201);
$wooItems[$o['id']][0]['meters'] = 6.6;
$ok($wooEvent($o, 'active', 'processing'), 'C9 D the document becomes stale');
$results = $race([$httpJob($root, $docPath($o, 'revision-requests'), ['expectedDocumentVersion' => (int) $one('SELECT document_version FROM operational_orders WHERE order_uuid = ?', [$o['uuid']]), 'comment' => 'Revizie în paralel']), $cancelJob($o)]);
$check($state($o)['cancellation'] === 'held' && in_array($results[0]['status'], [201, 409], true) && $revisions($o) === 1, 'C9 D: revision and cancellation stay independent ' . json_encode($results));
// E: cancellation versus the supervisor accepting the faulty piece
$o = $caseOrder('re', 'ironing');
$xe = $ok($report($tailor, $o), 'C9 E report', 201)['id'];
$results = $race([$httpJob($iuliana, "/production-exceptions/{$xe}/review", ['expectedVersion' => $exVersion($xe), 'decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $o['qr']]), $cancelJob($o)]);
$check($state($o)['cancellation'] === 'held' && ($results[0]['status'] === 200 ? $exStatus($xe) === 'in_supervisor_custody' : $exStatus($xe) === 'awaiting_supervisor_review' && in_array($results[0]['error'], STOP_CODES, true)), 'C9 E: deterministic ' . json_encode($results));
// F: cancellation versus the cutter's QR acknowledgment (both are allowed: either order of commits is consistent)
$o = $caseOrder('rf', 'labeling');
$xf = $ok($report($tailor, $o), 'C9 F report', 201)['id'];
$ok($review($iuliana, $xf, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $o['qr']]), 'C9 F custody');
$ok($sendReturn($iuliana, $xf), 'C9 F return');
$results = $race([$httpJob($cutter, "/production-exceptions/{$xf}/acknowledge", ['expectedVersion' => $exVersion($xf), 'confirmed' => true, 'qrToken' => $o['qr'], 'responsibility' => 'accepted']), $cancelJob($o)]);
$check($results[0]['status'] === 200 && $exStatus($xf) === 'awaiting_approval' && $state($o)['cancellation'] === 'held', 'C9 F: receipt and stop both recorded ' . json_encode($results));
// G: cancellation versus the manager approving rework
$results = $race([$httpJob($denisa, "/management/production-exceptions/{$xf}/decision", ['expectedVersion' => $exVersion($xf), 'decision' => 'approve', 'attribution' => 'cutter'], DASHBOARD), $cancelJob($o)]);
$check($state($o)['cancellation'] === 'held' && $exStatus($xf) === 'awaiting_approval' && in_array($results[0]['error'], STOP_CODES, true), 'C9 G: the order was already stopped, the approval is refused ' . json_encode($results));
$o = $caseOrder('rg', 'side-hem');
$xg = $ok($report($tailor, $o), 'C9 G2 report', 201)['id'];
$ok($review($iuliana, $xg, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $o['qr']]), 'C9 G2 custody');
$ok($sendReturn($iuliana, $xg), 'C9 G2 return');
$ok($ack($cutter, $xg, $o['qr']), 'C9 G2 receipt');
$before = $state($o);
$results = $race([$httpJob($denisa, "/management/production-exceptions/{$xg}/decision", ['expectedVersion' => $exVersion($xg), 'decision' => 'approve', 'attribution' => 'cutter'], DASHBOARD), $cancelJob($o)]);
$check($state($o)['cancellation'] === 'held' && ($results[0]['status'] === 200 ? $exStatus($xg) === 'approved' && $state($o)['stage'] === 'material-preparation' : $exStatus($xg) === 'awaiting_approval' && $state($o)['stage'] === $before['stage']), 'C9 G2: deterministic ' . json_encode($results));
// H: cancellation versus rework-track progression
$h = $trendyol('rh');
$features->setCohortMember(FeatureActivation::PIECE_ROUTING, $h['uuid'], true, 'test-operator', 'cancellation suite pilot', $nowSql());
$plan = $ok($get($iuliana, $h['path'] . '/manufacturing-plan'), 'C9 H plan');
$hashes = array_column($plan['lines'], 'sourcePlanHash', 'itemId');
$ok($post($iuliana, $h['path'] . '/manufacturing-plan/lines/' . $h['items'][0] . '/confirm', ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată', 'expectedSourceHash' => $hashes[$h['items'][0]]]), 'C9 H DRAPERII');
$ok($post($iuliana, $h['path'] . '/manufacturing-plan/lines/' . $h['items'][1] . '/confirm', ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată', 'expectedSourceHash' => $hashes[$h['items'][1]]]), 'C9 H PERDEA');
$bring($h, 'material-straightening');
$advance($worker, $h);
$ht = $rows("SELECT track_uuid, version FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$h['uuid']])[0];
$ok($post($worker2, "/production-tracks/{$ht['track_uuid']}/claim", ['expectedVersion' => (int) $ht['version'], 'qrToken' => $h['qr']]), 'C9 H track claimed');
$tv = (int) $one('SELECT version FROM production_tracks WHERE track_uuid = ?', [$ht['track_uuid']]);
$results = $race([$httpJob($worker2, "/production-tracks/{$ht['track_uuid']}/complete", ['expectedVersion' => $tv]), $cancelJob($h)]);
$trackStage = (string) $one('SELECT stage_id FROM production_tracks WHERE track_uuid = ?', [$ht['track_uuid']]);
$check($state($h)['cancellation'] === 'held' && ($results[0]['status'] === 200 ? $trackStage !== 'ironing' : $trackStage === 'ironing' && in_array($results[0]['error'], STOP_CODES, true)), 'C9 H: deterministic ' . json_encode($results));
// I: packing completion and J: final completion versus cancellation
foreach (['packing' => 'I', 'delivery' => 'J'] as $stage => $letter) {
    $o = $woo('outletperdele', 'r' . strtolower($letter), $stage);
    $bring($o, $stage);
    $before = $state($o);
    $results = $race([$httpJob($worker, $o['path'] . '/transition', $transitionBody($o)), $cancelJob($o)]);
    $raceOutcome($o, $before, $results, 0, "C9 {$letter}");
    if ($stage === 'delivery') {
        $check(($results[0]['status'] === 200) === ($state($o)['completed'] !== null) && ($state($o)['completed'] === null || $control($o)['production_phase'] === 'completed'), 'C9 J: completed before the stop, or not completed at all');
    } else {
        $check(($results[0]['status'] === 200) === ($state($o)['stage'] === 'delivery'), 'C9 I: packed and moved, or nothing moved');
    }
}
// K: release versus a newer cancellation event; L: two managers releasing the same stop
$k = $woo('trendhome', 'rk', 'labeling');
$bring($k, 'labeling');
$ok($cancel($k), 'C9 K cancellation');
$ok($reactivate($k), 'C9 K reactivated');
$ok($physical($keeper, $k, ['outcome' => 'secured', 'note' => 'Raft oprire', 'qrToken' => $k['qr']]), 'C9 K secured');
$results = $race([$httpJob($releaser, '/management/orders/' . rawurlencode($k['id']) . '/cancellation/release', ['expectedVersion' => (int) $control($k)['version'], 'reason' => 'Clientul a confirmat din nou.'], DASHBOARD), $cancelJob($k)]);
$check($state($k)['cancellation'] === 'held' && $control($k)['source_status'] === 'cancelled', 'C9 K: the newer cancellation always ends stopped ' . json_encode($results));
$l2 = $woo('outletperdele', 'rl', 'labeling');
$bring($l2, 'labeling');
$ok($stop($keeper, $l2, ['reason' => 'Verificare internă paralelă.']), 'C9 L stop');
$ok($physical($keeper, $l2, ['outcome' => 'secured', 'note' => 'Raft oprire', 'qrToken' => $l2['qr']]), 'C9 L secured');
$ok($dash($root, 'PUT', "/management/employees/{$releaser2['employeeUuid']}/roles", ['roleIds' => [$releaseRole]]), 'C9 L second releaser');
$v = (int) $control($l2)['version'];
$body = ['expectedVersion' => $v, 'reason' => 'Verificarea s-a încheiat.'];
$results = $race([$httpJob($releaser, '/management/orders/' . rawurlencode($l2['id']) . '/cancellation/release', $body, DASHBOARD), $httpJob($releaser2, '/management/orders/' . rawurlencode($l2['id']) . '/cancellation/release', $body, DASHBOARD)]);
$check(count(array_filter($results, static fn (array $r): bool => $r['status'] === 200)) === 1 && count(array_filter($results, static fn (array $r): bool => $r['error'] === 'CANCELLATION_CHANGED' || $r['error'] === 'CANCELLATION_NOT_HELD')) === 1
    && (int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'release_approved'", [$l2['uuid']]) === 1, 'C9 L: one release wins ' . json_encode($results));
// M: an old frontend request retried after the cancellation, with the same idempotency key
$mo = $woo('trendhome', 'rm', 'header-tape');
$bring($mo, 'header-tape', false);
$claimKey = $key('mclaim');
$claimed = $ok($post($worker, $mo['path'] . '/claim', ['expectedVersion' => (int) $state($mo)['version']], $claimKey), 'C9 M claim before the cancellation');
$check(($claimed['employeeAllowedAction']['id'] ?? null) === 'complete_stage', 'C9 M the committed claim offered the stage completion');
$ok($cancel($mo), 'C9 M cancellation');
$replay = $ok($post($worker, $mo['path'] . '/claim', ['expectedVersion' => (int) $claimed['productionVersion'] - 1], $claimKey), 'C9 M the lost answer is replayed');
$check(!isset($replay['employeeAllowedAction']) && $replay['employeeActionBlockedReason'] === 'order_unavailable' && ($replay['productionStop']['state'] ?? null) === 'stopped'
    && $replay['productionVersion'] === $claimed['productionVersion'], 'C9 M a replay returns the committed result but never presents the old authorization');
$check($stopped($post($worker, $mo['path'] . '/transition', ['expectedVersion' => (int) $claimed['productionVersion']])), 'C9 M the old screen cannot complete the stage');

// ---- C10. Operator CLI, legacy cancellations, rollback guard, Dashboard overview --------------------------------------
$cliEnvironment = ['HOME' => (string) getenv('HOME'), 'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD')];
$cli = static function (string $script, string ...$arguments) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
};
// A legacy order (API 2.34.0 kept a cancelled order workable after cutting): simulated in this test database only.
$lg = $woo('trendhome', 'legacy', 'ironing');
$bring($lg, 'ironing');
$pdo->prepare('UPDATE operational_orders SET source_reported_unavailable_at = ? WHERE order_uuid = ?')->execute([gmdate('Y-m-d H:i:s.000000', $base), $lg['uuid']]);
$audit = $cli('cancellation-control.php', 'audit', '--details=1');
$check($audit['code'] === 0 && $audit['json']['legacyUnstopped'] >= 1 && in_array($lg['id'], array_column($audit['json']['legacy'], 'order'), true), 'C10 the audit lists the legacy cancellation (read-only)');
$check($state($lg)['cancellation'] === null, 'C10 the audit changed nothing');
$check($cli('cancellation-control.php', 'stop-legacy', '--order=' . $lg['id'], '--operator=test', '--reason=aprobat', '--confirm=WRONG')['code'] === 2 && $state($lg)['cancellation'] === null, 'C10 a wrong confirmation changes nothing');
$check($cli('cancellation-control.php', 'stop-legacy', '--order=' . $e['id'], '--operator=test', '--reason=aprobat', '--confirm=STOP-PRODUCTION')['code'] === 2, 'C10 only a legacy candidate can be stopped this way');
$stopCli = $cli('cancellation-control.php', 'stop-legacy', '--order=' . $lg['id'], '--operator=test operator', '--reason=Aprobat de proprietar', '--confirm=STOP-PRODUCTION');
$check($stopCli['code'] === 0 && $state($lg)['cancellation'] === 'held' && $control($lg)['reason_code'] === 'legacy_source_cancellation'
    && $control($lg)['source_cancellation_received_at'] === gmdate('Y-m-d H:i:s.000000', $base) && $state($lg)['owner'] !== null, 'C10 one explicitly named legacy order is stopped with its original report time');
// A legacy unavailable order (pre-033, no control row) reactivated at the source: never released silently.
$lu = $woo('outletperdele', 'legacy2', 'waiting');
$pdo->prepare("UPDATE operational_orders SET operational_status = 'unavailable', source_reported_unavailable_at = ? WHERE order_uuid = ?")->execute([gmdate('Y-m-d H:i:s.000000', $base), $lu['uuid']]);
$ok($reactivate($lu), 'C10 legacy reactivation');
$check($state($lu)['cancellation'] === 'held' && $control($lu)['reason_code'] === 'legacy_source_cancellation' && $control($lu)['source_status'] === 'reactivated', 'C10 a pre-033 cancellation reactivated at the source still needs a release');
$rollback = $cli('check-rollback.php', '2.34.0');
$check($rollback['code'] === 3 && $rollback['json']['requiredFloor'] === '2.35.0' && $rollback['json']['state']['openStoppedOrders'] > 0, 'C10 an API without the guard is refused while a stop is in force');
$check($cli('check-rollback.php', '2.35.0')['code'] === 0, 'C10 the current API is a safe target');
// Dashboard overview: stopped orders are not active production and each order is counted once.
$overview = $ok($dashGet($root, '/management/production-overview'), 'C10 overview');
$stoppedOpen = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE cancellation_state = 'held'");
$check($overview['cancellations']['stopped'] === $stoppedOpen && $overview['cancellations']['physicalPending'] === (int) $one("SELECT COUNT(*) FROM order_cancellation_controls WHERE hold_status = 'held' AND physical_status = 'pending'"), 'C10 cancellation counts match the database');
$activeSql = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_completed_at IS NULL AND operational_status <> 'unavailable' AND production_stopped_at IS NULL");
$check($overview['summary']['active'] === $activeSql, 'C10 active production excludes every stopped order');
$categories = $overview['cancellations']['counts'];
$check(array_sum($categories) === (int) $one("SELECT COUNT(*) FROM order_cancellation_controls WHERE hold_status = 'held' OR released_at >= ?", [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]), 'C10 one category per order, no double count');
$check($error($dashGet($denisa, '/management/production-overview'), 403, 'UNAUTHORIZED_ACTION', 'C10 overview needs production.view') !== null, 'C10 permission boundary unchanged');
$list = $ok($dashGet($root, '/management/orders', ['state' => 'stopped', 'limit' => '100']), 'C10 stopped filter');
$check(array_unique(array_column(array_column($list['items'], 'production'), 'state')) === ['stopped'] && in_array($e['id'], array_column($list['items'], 'globalOrderId'), true), 'C10 the stopped filter lists exactly stopped orders');
$error($cancellationView($denisa, $e), 403, 'UNAUTHORIZED_ACTION', 'C10 the cancellation view needs orders.view_all');
$view = $ok($cancellationView($keeper, $e), 'C10 the cancellation view');
$check($view['capabilities'] === ['stop' => true, 'recordPhysical' => true, 'release' => false, 'sync' => false] && $view['cancellation']['category'] === 'reactivated_awaiting_release'
    && $view['cancellation']['nextAction'] === 'physical_reconciliation' && array_column($view['events'], 'sequence') === array_values(array_map('intval', array_column($rows('SELECT event_seq FROM order_cancellation_events WHERE order_uuid = ? ORDER BY event_seq', [$e['uuid']]), 'event_seq'))), 'C10 an ordered audit trail with the next action');
$check(!str_contains(json_encode($view), 'Str. ') && !str_contains(json_encode($staffView($worker, $e)), 'cancellation_note'), 'C10 no customer data in the views');

// ---- C11. Allowed operations on a stopped order -----------------------------------------------------------------------
$a = $GLOBALS['matrixOrders']['trendhome']['header-tape'];
$check($get($worker, $a['path'])['status'] === 200 && $get($worker, '/activity/mine')['status'] === 200, 'C11 read-only history stays available');
$check($dashGet($root, '/management/orders/' . rawurlencode($a['id']))['status'] === 200, 'C11 the Dashboard detail stays available');
$check($stagesNow() === $canonical, 'C11 the 14-stage workflow is unchanged at the end');

// ---- C12. Stops without a source cancellation: only the shared guard refuses (the failure-injection targets) -----------
// The source keeps reporting these orders active, so no availability rule can block them; every refusal below must come
// from ProductionStop. Disabling any guard site makes the matching check fail.
$g = $woo('trendhome', 'guardcut', 'material-preparation');
$bring($g, 'material-preparation');
$ok($stop($keeper, $g, ['reason' => 'Verificare internă fără anulare la sursă.']), 'C12 local stop at cutting');
$check($state($g)['availability'] === 'in_progress' && $state($g)['cancellation'] === 'held', 'C12 the source availability stays active');
$check($localStopped($post($cutter, $g['path'] . '/transition', ['expectedVersion' => (int) $state($g)['version']])), 'C12 cutting completion is refused (OrderOperationsService)');
$check($localStopped($post($cutter, '/cutting/orders/' . rawurlencode($g['id']) . '/transfers', ['expectedVersion' => (int) $state($g)['version'], 'targetId' => $cutter2['employeeUuid'], 'reasonKey' => 'illness'])), 'C12 a cutting transfer is refused (CuttingService)');
$check($localStopped($dash($root, 'POST', '/management/orders/' . rawurlencode($g['id']) . '/release-owner', ['expectedVersion' => (int) $state($g)['version']])), 'C12 an owner release is refused (OrderOwnershipService)');
$check($localStopped($dash($root, 'PUT', '/management/orders/' . rawurlencode($g['id']) . '/owner', ['employeeId' => $cutter2['employeeUuid'], 'expectedVersion' => (int) $state($g)['version']])), 'C12 an owner reassignment is refused');
$check($state($g)['owner'] === $cutter['employeeUuid'], 'C12 the owner is unchanged');
$gi = $trendyol('guardintake');
$ok($stop($keeper, $gi, ['reason' => 'Verificare internă înainte de tăiere.']), 'C12 local stop at stage 1');
$check($control($gi)['physical_status'] === 'not_required', 'C12 nothing physical exists before cutting');
$check($localStopped($intake($cutter, $gi)), 'C12 the QR cutting intake is refused');
$gp = $trendyol('guardplan');
$features->setCohortMember(FeatureActivation::PIECE_ROUTING, $gp['uuid'], true, 'test-operator', 'cancellation suite pilot', $nowSql());
$ok($stop($keeper, $gp, ['reason' => 'Verificare internă a planului.']), 'C12 local stop before the plan');
$hashes = array_column($ok($get($iuliana, $gp['path'] . '/manufacturing-plan'), 'C12 plan view')['lines'], 'sourcePlanHash', 'itemId');
$check($localStopped($post($iuliana, $gp['path'] . '/manufacturing-plan/lines/' . $gp['items'][0] . '/confirm', ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată', 'expectedSourceHash' => $hashes[$gp['items'][0]]])), 'C12 a plan confirmation is refused (PieceOperationsService)');
$gq = $caseOrder('guardq', 'ironing');
$ok($stop($keeper, $gq, ['reason' => 'Verificare internă la croitorie.']), 'C12 local stop in tailoring');
$check($localStopped($report($tailor, $gq)), 'C12 a defect report is refused (CuttingFaultService)');
$gr = $caseOrder('guardr', 'labeling');
$xg2 = $ok($report($tailor, $gr), 'C12 report before the stop', 201)['id'];
$ok($stop($keeper, $gr, ['reason' => 'Verificare internă cu defect deschis.']), 'C12 local stop with an open case');
$check($localStopped($review($iuliana, $xg2, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $gr['qr']])), 'C12 custody acceptance is refused');
$gs = $caseOrder('guards', 'side-hem');
$xg3 = $ok($report($tailor, $gs), 'C12 rework report', 201)['id'];
$ok($review($iuliana, $xg3, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $gs['qr']]), 'C12 custody');
$ok($sendReturn($iuliana, $xg3), 'C12 return');
$ok($ack($cutter, $xg3, $gs['qr']), 'C12 receipt');
$ok($stop($keeper, $gs, ['reason' => 'Verificare internă înainte de aprobare.']), 'C12 local stop awaiting approval');
$check($localStopped($decide($denisa, $xg3, ['decision' => 'approve', 'attribution' => 'cutter'])), 'C12 approval of rework is refused');
$check($exStatus($xg3) === 'awaiting_approval', 'C12 the case waits, unchanged');
// The mixed order of C7 is reactivated at the source: its piece track is refused by the stop alone.
$ok($marketplace($m, 'Picking') === 'updated' ? ['status' => 200, 'body' => []] : ['status' => 500, 'body' => []], 'C12 the mixed order is reactivated');
$check($state($m)['availability'] === 'in_progress' && $state($m)['cancellation'] === 'held', 'C12 reactivated, still stopped');
$check($localStopped($post($worker2, "/production-tracks/{$trackId}/complete", ['expectedVersion' => (int) $one('SELECT version FROM production_tracks WHERE track_uuid = ?', [$trackId])])), 'C12 the track is refused (ReworkTrackService)');

fwrite(STDOUT, "PASS MySQL order cancellation integration ({$checks} checks).\n");
