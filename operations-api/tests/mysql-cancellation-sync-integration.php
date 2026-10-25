<?php

declare(strict_types=1);

// Multi-channel cancellation synchronization (migration 034, API 2.36.0) against a dedicated MySQL/MariaDB test database,
// end to end through the HTTP kernel, the signed source routes, the Trendyol fixture transport (never the real API), the
// outbound worker with an injected clock and the operator CLI. Every order is synthetic; every assertion reads committed
// rows or the fixture's recorded write calls.
//
//   S0  migration 034: additive, flags created disabled, permission granted to nobody, generations start at 1
//   S1  gates: server switch and provider flags, both required; B2B and unconfigured providers go to manual review
//   S2  Trendyol: status read, unsupplied notice with every line, confirmation only by a status read or the inbound sync
//   S3  Trendyol lost answers: uncertain, never resent by the worker, observed then manual review; a person-approved retry
//   S3b unknown outcomes: delayed/stale status, four workers on an uncertain operation, inbound confirmation meanwhile,
//       split or shipped after the unknown outcome, crash after a possible send
//   S3c retry-safety matrix R1-R16: server evidence + a person's attestation before any second notice, the worker's own
//       guard, read-only rechecks, stale evidence, forged and legacy flags, concurrent managers, old idempotency keys,
//       duplicate cron runs, racing workers on an authorized retry, external changes between check and send
//   S4  Trendyol failures: 429 backoff, 5xx uncertain, 401 failed, 4xx rejected, status not cancellable, unknown package,
//       attempt budget, provider unreachable
//   S5  worker crash (expired lease), concurrent workers (one notice only), inbound confirmation during the HTTP call
//   S6  WooCommerce (source pull): pickup, cross-site isolation, report, duplicate and conflicting reports, paid order,
//       inbound confirmation, withdraw before / after pickup, pickup timeout, heartbeat mode, signatures
//   S7  authorization, CSRF, idempotency, stale versions, revoked roles
//   S8  release blocked while an operation is open; generations after a release; no reuse of an old operation
//   S9  3 sources x 14 stages: synchronization never moves production, never changes history, QR or documents
//   S10 reconciliation: dry run changes nothing, apply records and resolves, auto-confirmation, Fresh Start boundary
//   S11 operator CLI and rollback preflight
//   S12 mutation scope: the synchronization code writes only its own tables; no secret is stored

use Arasya\Operations\Application\Container;
use Arasya\Operations\Cancellation\Sync\CancellationSyncWorker;
use Arasya\Operations\Cancellation\Sync\ProviderRegistry;
use Arasya\Operations\Cancellation\Sync\SyncOperations;
use Arasya\Operations\Cancellation\Sync\TrendyolCancellationProvider;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\TrendyolCredentials;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\Trendyol\FixtureTrendyolTransport;
use Arasya\Operations\Integration\Trendyol\TrendyolCancellationApi;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Integration\Trendyol\TrendyolTransport;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL cancellation synchronization integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
const DASHBOARD = 'http://127.0.0.1:4174';
$suffix = bin2hex(random_bytes(4));
$fixtureFile = sys_get_temp_dir() . "/arasya-cancellation-sync-{$suffix}.json";
file_put_contents($fixtureFile, json_encode(['packages' => [], 'putCalls' => []], JSON_THROW_ON_ERROR));
register_shutdown_function(static fn () => @unlink($fixtureFile));
$baseConfig = T::config($dbName, [T::ORIGIN, DASHBOARD]);
$config = new Config(...[...(array) $baseConfig, 'trendyolFixtureFile' => $fixtureFile, 'cancellationSync' => 'enabled']);
$offConfig = new Config(...[...(array) $baseConfig, 'trendyolFixtureFile' => $fixtureFile]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$pdo->exec('DELETE FROM system_root_identity');
$pdo->exec("UPDATE production_feature_flags SET enabled = 0 WHERE flag_key IN ('partial_rework', 'piece_routing', 'perdea_assisted_checkpoints', 'cancellation_sync_trendyol', 'cancellation_sync_trendhome', 'cancellation_sync_outletperdele')");
$pdo->exec("UPDATE trendyol_intake_state SET status = 'inactive', baseline_at = NULL WHERE state_id = 1");

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$offKernel = (new Container($offConfig, $pdo))->kernel();
$clock = $container->clock();
$password = 'cancellation sync passphrase 2026';
$rootName = "sync.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('test-cancellation-sync-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Cancellation sync root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Cancellation sync root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('cs-%s-%s-%05d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'cs-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'cs-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');

// ---- S0. Migration 034 ----------------------------------------------------------------------------------------------
$check((string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '034_order_cancellation_sync.sql'") === hash_file('sha256', $migrations . '/034_order_cancellation_sync.sql'), 'S0 034 recorded with its checksum');
$check((int) $one("SELECT COUNT(*) FROM permissions WHERE permission_key = 'production.cancellation.sync' AND role_grantable = 1") === 1, 'S0 the sync permission exists, role-grantable');
// Roles created by a person (this suite creates its own below; a shared CI database may hold earlier runs') are excluded:
// no seeded role and no role created by a migration ever receives the permission.
$check((int) $one("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id JOIN roles r ON r.role_id = rp.role_id
    WHERE p.permission_key = 'production.cancellation.sync' AND r.name NOT LIKE 'Sincronizare anulare test %' AND r.name NOT LIKE 'Simulare anulare %'") === 0, 'S0 no role receives the sync permission automatically');
foreach (ProviderRegistry::FLAGS as $flagKey) {
    $check($one('SELECT enabled FROM production_feature_flags WHERE flag_key = ?', [$flagKey]) !== false, "S0 flag {$flagKey} exists");
}
$check((string) $one("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cancellation_controls' AND COLUMN_NAME = 'hold_generation'") === '1'
    && (int) $one('SELECT COUNT(*) FROM order_cancellation_controls WHERE hold_generation < 1') === 0, 'S0 control rows start at generation 1 (existing rows receive the default)');
$tablesBefore = (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
$check((new MigrationRunner($pdo))->migrate($migrations) === [] && (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === $tablesBefore, 'S0 recorded once, nothing re-applied');
$canonical = ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'];
$stagesNow = static fn (): array => array_column($rows("SELECT ps.stage_id FROM production_stages ps JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id WHERE pw.workflow_key = 'curtain-production' AND pw.status = 'active' AND ps.status = 'active' ORDER BY ps.ordinal"), 'stage_id');
$check($stagesNow() === $canonical, 'S0 the canonical 14-stage workflow is unchanged');

// ---- Identities -----------------------------------------------------------------------------------------------------
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = []) use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-cancellation-sync');
    $uuids[$name] = $employee->employeeUuid;
    $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid, 'name' => $display];
};
$waiter = $identity('waiter', 'Sync Waiting Desk', ['waiting']);
$cutter = $identity('cutter', 'Sync Cutter', ['material-preparation']);
$worker = $identity('worker', 'Sync Line Worker', array_slice($canonical, 2));
$syncRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Sincronizare anulare test {$suffix}", 'description' => null, 'authorityRank' => 200,
    'permissions' => ['orders.view_all', 'production.cancellation.manage', 'production.cancellation.sync']]), 'sync role', 201)['role']['id'];
$stopRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Oprire fără sincronizare test {$suffix}", 'description' => null, 'authorityRank' => 200,
    'permissions' => ['orders.view_all', 'production.cancellation.manage']]), 'stop role', 201)['role']['id'];
$releaseRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Reluare test sync {$suffix}", 'description' => null, 'authorityRank' => 200,
    'permissions' => ['orders.view_all', 'production.cancellation.release']]), 'release role', 201)['role']['id'];
$syncer = $identity('syncer', 'Sync Manager', [], ['dashboard'], [$syncRole]);
$syncer2 = $identity('syncer2', 'Sync Manager Two', [], ['dashboard'], [$syncRole]);
$keeper = $identity('keeper', 'Sync Stop Keeper', [], ['dashboard'], [$stopRole]);
$releaser = $identity('releaser', 'Sync Releaser', [], ['dashboard'], [$releaseRole]);
$manager = $identity('manager', 'Sync Operations Manager', [], ['dashboard'], [$roleIds['operations-manager']]);

// ---- Fixture, clock, worker -----------------------------------------------------------------------------------------
$fx = static fn (): array => json_decode((string) file_get_contents($fixtureFile), true, 64, JSON_THROW_ON_ERROR);
$fxWrite = static fn (array $fixture): int => (int) file_put_contents($fixtureFile, json_encode($fixture, JSON_THROW_ON_ERROR));
$fxPut = static function (?array $script) use ($fx, $fxWrite): void { $f = $fx(); unset($f['put']); if ($script !== null) $f['put'] = $script; $fxWrite($f); };
$fxGet = static function (array $patch) use ($fx, $fxWrite): void { $f = $fx(); unset($f['fail'], $f['status'], $f['body']); $fxWrite($f + $patch); };
$fxPackage = static function (int $packageId) use ($fx): ?array { foreach ($fx()['packages'] as $p) if ($p['shipmentPackageId'] === $packageId) return $p; return null; };
$fxStatus = static function (int $packageId, string $status) use ($fx, $fxWrite): void {
    $f = $fx();
    foreach ($f['packages'] as $i => $p) if ($p['shipmentPackageId'] === $packageId) { $f['packages'][$i]['status'] = $status; $f['packages'][$i]['lastModifiedDate'] += 1000; }
    $fxWrite($f);
};
$fxRemove = static function (int $packageId) use ($fx, $fxWrite): void { $f = $fx(); $f['packages'] = array_values(array_filter($f['packages'], static fn (array $p): bool => $p['shipmentPackageId'] !== $packageId)); $fxWrite($f); };
$puts = static fn (int $packageId): array => array_values(array_filter($fx()['putCalls'] ?? [], static fn (array $c): bool => str_contains($c['url'], "/shipment-packages/{$packageId}/")));
$wclock = new class implements Clock {
    public int $offset = 0;
    public function now(): DateTimeImmutable { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$this->offset} seconds"); }
};
// One worker run at real time + $offset seconds (absolute, never accumulated).
$run = static function (int $offset = 0, ?ProviderRegistry $registry = null) use ($wclock, $pdo, $container): array {
    $wclock->offset = $offset;
    return (new CancellationSyncWorker($pdo, $wclock, $registry ?? $container->cancellationProviders()))->run(100);
};
$flag = static function (string $source, bool $enabled) use ($pdo): void {
    $pdo->prepare('UPDATE production_feature_flags SET enabled = ? WHERE flag_key = ?')->execute([$enabled ? 1 : 0, ProviderRegistry::FLAGS[$source]]);
};

// ---- Orders and helpers ---------------------------------------------------------------------------------------------
$events = [];
$base = time() - 3 * 3600;
$tick = static function (string $id) use (&$events, $base): string { $events[$id] = ($events[$id] ?? 0) + 1; return gmdate('Y-m-d\TH:i:s\Z', $base + $events[$id] * 7); };
$orderInfo = static function (string $id, string $source) use ($one, $rows): array {
    $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$id]);
    return ['id' => $id, 'uuid' => $uuid, 'source' => $source, 'path' => '/orders/' . rawurlencode($id),
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid])];
};
$wooItems = [];
$woo = static function (string $source, string $tag, string $stage = 'waiting') use ($kernel, $ok, $tick, $orderInfo, $suffix, &$wooItems): array {
    $number = "CS{$tag}{$suffix}";
    $id = "{$source}:{$number}";
    $lineBase = crc32($tag . $source) % 100000 * 10;
    $wooItems[$id] = [['id' => $lineBase + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1]];
    $ok(T::ingest($kernel, $source, T::sourceOrder($number, "cs-{$tag}-{$suffix}-1", $tick($id), T::stage($stage), 'processing', 'active', $wooItems[$id], $number)), "ingest {$tag}");
    return $orderInfo($id, $source) + ['number' => $number];
};
$wooEvent = static function (array $o, string $availability, string $status, ?string $eventId = null, ?\Arasya\Operations\Http\ApiKernel $via = null) use ($kernel, $tick, &$wooItems): array {
    return T::ingest($via ?? $kernel, $o['source'], T::sourceOrder($o['number'], $eventId ?? 'cs-' . bin2hex(random_bytes(6)), $tick($o['id']), null, $status, $availability, $wooItems[$o['id']], $o['number']));
};
$packages = [];
$trendyol = static function (string $tag, ?int $orderDate = null) use ($container, $pdo, $orderInfo, $suffix, $rootUuid, $fx, $fxWrite, &$packages): array {
    $number = "119CS{$tag}{$suffix}";
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $items = [
        new OperationalOrderItem(Uuid::v4(), "tys-{$tag}-1", 1, 'Draperie blackout', 'TY-1', null, 'Gri', 250.0, 260.0, 'cm', 6.0, 1, ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => 'Draperie', 'barcode' => null]),
        new OperationalOrderItem(Uuid::v4(), "tys-{$tag}-2", 2, 'Draperie blackout B', 'TY-2', null, 'Gri', 150.0, 260.0, 'cm', 4.0, 2, ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => 'Draperie', 'barcode' => null]),
    ];
    $pdo->beginTransaction();
    $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-cs{$tag}-{$suffix}", "approval-cs{$tag}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now, $items), []);
    $packageId = random_int(10_000_000, 99_999_999);
    $modified = (int) floor(microtime(true) * 1000) - 600_000;
    $package = ['shipmentPackageId' => $packageId, 'orderNumber' => $number, 'lastModifiedDate' => $modified, 'status' => 'Created', 'orderDate' => $orderDate ?? $modified - 1000,
        'lines' => [['lineId' => $packageId * 10 + 1, 'productName' => 'Draperie blackout', 'quantity' => 1], ['lineId' => $packageId * 10 + 2, 'productName' => 'Draperie blackout B', 'quantity' => 2]]];
    $pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, lines_hash, version, first_seen_at, last_seen_at, released_order_uuid, released_by_employee_uuid, released_at)
        VALUES (?, ?, 'released', 'Created', ?, ?, ?, 1, ?, ?, ?, ?, ?)")->execute([$packageId, $number, $modified, $package['orderDate'], TrendyolPackage::fromApi($package)->linesHash(), $now->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), $uuid, $rootUuid, $now->format('Y-m-d H:i:s.u')]);
    $pdo->commit();
    $f = $fx();
    $f['packages'][] = $package;
    $fxWrite($f);
    $id = (string) $pdo->query('SELECT global_order_id FROM operational_orders WHERE order_uuid = ' . $pdo->quote($uuid))->fetchColumn();
    $packages[$id] = $package;
    return $orderInfo($id, 'trendyol') + ['number' => $number, 'package' => $packageId];
};
$store = new TrendyolIntakeStore($pdo, $container->projectionWriter(), $clock);
// The Trendyol intake reading the package (the inbound marketplace synchronization) with the fixture's current status.
$marketplace = static function (array $o, ?string $status = null) use ($store, $fxPackage, &$packages): string {
    $package = $fxPackage($o['package']) ?? $packages[$o['id']];
    if ($status !== null) $package['status'] = $status;
    $package['lastModifiedDate'] = max($package['lastModifiedDate'], ($packages[$o['id']]['lastModifiedDate'] ?? 0) + 1000);
    $packages[$o['id']] = $package;
    return $store->record(TrendyolPackage::fromApi($package), 0);
};
$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, production_completed_at AS completed,
    operational_status AS availability, cancellation_state AS cancellation, production_stopped_at AS stopped, document_status AS document FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$control = static fn (array $o): ?array => $rows('SELECT * FROM order_cancellation_controls WHERE order_uuid = ?', [$o['uuid']])[0] ?? null;
$op = static fn (array $o): ?array => $rows('SELECT * FROM order_cancellation_sync_operations WHERE order_uuid = ? ORDER BY hold_generation DESC LIMIT 1', [$o['uuid']])[0] ?? null;
$ops = static fn (array $o): array => $rows('SELECT * FROM order_cancellation_sync_operations WHERE order_uuid = ? ORDER BY hold_generation', [$o['uuid']]);
$trail = static fn (array $operation): array => array_map(static fn (array $r): string => "{$r['kind']}:{$r['outcome']}", $rows('SELECT kind, outcome FROM order_cancellation_sync_attempts WHERE operation_uuid = ? ORDER BY attempt_seq', [$operation['operation_uuid']]));
$eventTypes = static fn (array $o): array => array_column($rows('SELECT event_type FROM order_cancellation_events WHERE order_uuid = ? ORDER BY event_seq', [$o['uuid']]), 'event_type');
$history = static fn (array $o): array => $rows('SELECT action, from_stage_id, to_stage_id, employee_uuid, production_version_before FROM order_activity_events WHERE order_uuid = ? ORDER BY production_version_after, occurred_at', [$o['uuid']]);
$qrRows = static fn (array $o): array => $rows('SELECT qr_reference, status FROM order_qr_references WHERE order_uuid = ? ORDER BY created_at', [$o['uuid']]);
$claim = static function (array $who, array $o) use ($post, $ok, $state, $pdo, $key): array {
    return $ok($post($who, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $who['employeeUuid'], (int) $state($o)['version']), $key('claim')), "claim {$o['id']} at " . $state($o)['stage']);
};
$advance = static fn (array $who, array $o): array => $ok($post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']], $key('move')), "transition {$o['id']} at " . $state($o)['stage']);
$stageWorker = static fn (string $stage): array => match ($stage) { 'waiting' => $waiter, 'material-preparation' => $cutter, default => $worker };
$intake = static function (array $o) use ($post, $cutter, $pdo, $key): array {
    $count = (new \Arasya\Operations\Cutting\CuttingLifecycle($pdo))->ownedCount($cutter['employeeUuid']);
    return $post($cutter, '/cutting/intake', ['qrToken' => $o['qr']] + ($count > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $count] : []), $key('intake'));
};
$bring = static function (array $o, string $target, bool $claimed = true) use ($claim, $advance, $state, $check, $stageWorker, $intake, $ok): void {
    for ($guard = 0; ; $guard++) {
        $current = $state($o);
        if ($current['stage'] === $target) {
            if ($claimed && $current['owner'] === null) $claim($stageWorker($target), $o);
            return;
        }
        if ($current['stage'] === 'waiting' && $o['source'] === 'trendyol') {
            $ok($intake($o), 'Trendyol cutting intake');
        } else {
            if ($current['owner'] === null) $claim($stageWorker($current['stage']), $o);
            $advance($stageWorker($current['stage']), $o);
        }
        $check($guard < 20, 'bring terminates');
    }
};
$cancellationPath = static fn (array $o): string => '/management/orders/' . rawurlencode($o['id']) . '/cancellation';
$stop = static fn (array $who, array $o, bool $request = true): array => $dash($who, 'POST', $cancellationPath($o) . '/stop', ['reason' => 'Clientul a cerut anularea comenzii.', 'requestSourceCancellation' => $request]);
$send = static fn (array $who, array $o, array $body = [], ?string $idem = null): array => $dash($who, 'POST', $cancellationPath($o) . '/sync', $body + ['expectedVersion' => (int) ($control($o)['version'] ?? 1)], $idem);
$opAction = static fn (array $who, array $operation, string $action, array $body = [], ?string $idem = null): array => $dash($who, 'POST', "/management/cancellation-sync/operations/{$operation['operation_uuid']}/{$action}", $body + ['expectedVersion' => (int) $operation['version']], $idem);
$release = static fn (array $who, array $o): array => $dash($who, 'POST', $cancellationPath($o) . '/release', ['expectedVersion' => (int) ($control($o)['version'] ?? 0), 'reason' => 'Clientul a confirmat comanda din nou.']);
$view = static fn (array $who, array $o): array => $ok($dashGet($who, $cancellationPath($o)), "cancellation view {$o['id']}");
$staffView = static fn (array $who, array $o): array => $ok($get($who, $o['path']), "Staff view {$o['id']}");
$sourceCall = static fn (string $source, string $route, array $payload, ?string $secret = null, ?\Arasya\Operations\Http\ApiKernel $via = null): array => T::ingest($via ?? $kernel, $source, $payload, $secret, null, $route);
$pull = static fn (string $source, ?\Arasya\Operations\Http\ApiKernel $via = null): array => $sourceCall($source, 'orders/cancellations', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, $via);
$report = static fn (string $source, array $operation, string $outcome, ?string $status = null): array => $sourceCall($source, 'orders/cancellations/result', ['operationId' => $operation['operation_uuid'], 'outcome' => $outcome] + ($status === null ? [] : ['sourceStatus' => $status]));
$stopped = static fn (array $r): bool => $r['status'] === 409 && in_array($r['body']['error']['code'] ?? '', ['ORDER_PRODUCTION_STOPPED', 'ORDER_UNAVAILABLE'], true);

// ---- S1. Gates ------------------------------------------------------------------------------------------------------
$g1 = $trendyol('gate1');
$ok($stop($syncer, $g1), 'S1 local stop with a source request');
$check($control($g1)['source_request_status'] === 'pending' && (int) $control($g1)['hold_generation'] === 1, 'S1 the request is pending, generation 1');
$error($send($syncer, $g1, ['reasonCode' => 500]), 409, 'CANCELLATION_SYNC_DISABLED', 'S1 flag off: Trendyol cannot be sent');
$check($op($g1) === null, 'S1 nothing was queued');
$flag('trendyol', true);
$offDash = static fn (array $who, string $path, array $body): array => T::call($offKernel, 'POST', $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'cs-off-' . bin2hex(random_bytes(10))], $who['cookie']);
$error($offDash($syncer, $cancellationPath($g1) . '/sync', ['expectedVersion' => (int) $control($g1)['version'], 'reasonCode' => 500]), 409, 'CANCELLATION_SYNC_DISABLED', 'S1 server switch off with the flag on: refused');
$offWorker = (new CancellationSyncWorker($pdo, $wclock, (new Container($offConfig, $pdo))->cancellationProviders()));
$view1 = $view($syncer, $g1);
$check($view1['cancellation']['sync']['provider']['mechanism'] === 'api' && $view1['cancellation']['sync']['provider']['active'] === true && $view1['cancellation']['sync']['actions']['send'] === true
    && $view1['cancellation']['sync']['provider']['endpoint'] === 'PUT /integration/order/sellers/{sellerId}/shipment-packages/{packageId}/items/unsupplied', 'S1 the capability is shown with both gates open');
$error($send($syncer, $g1, []), 422, 'REASON_REQUIRED', 'S1 a Trendyol reason is never chosen automatically');
$error($send($syncer, $g1, ['reasonCode' => 499]), 422, 'VALIDATION_FAILED', 'S1 only documented reasons');
$error($send($syncer, $g1, ['reasonCode' => '500']), 422, 'VALIDATION_FAILED', 'S1 the reason is an integer');
$sent = $ok($send($syncer, $g1, ['reasonCode' => 500, 'note' => 'Stoc epuizat la furnizor.']), 'S1 sent with both gates open');
$check($sent['state'] === 'queued' && $sent['mechanism'] === 'api' && $sent['reasonCode'] === '500' && $sent['generation'] === 1, 'S1 queued once: ' . json_encode($sent));
$flag('trendyol', false);
$check($run()['processed'] === 0 && $puts($g1['package']) === [] && $op($g1)['state'] === 'queued', 'S1 flag turned off: the worker contacts nothing');
$flag('trendyol', true);
$wclock->offset = 0;
$offWorker->run();
$check($puts($g1['package']) === [] && $op($g1)['state'] === 'queued', 'S1 server switch off: the worker contacts nothing');
$check(in_array('source_cancellation_requested', $eventTypes($g1), true), 'S1 the request is in the stop audit');
// B2B has no supported outbound call: manual review at once, whatever the gates say.
$pdo->exec("UPDATE production_feature_flags SET enabled = 0 WHERE flag_key LIKE 'cancellation\\_sync\\_%'");
$b2bNumber = "B2BCS{$suffix}";
$pdo->beginTransaction();
$b2bUuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('b2b', "b2b-cs-{$suffix}", "b2b-ev-{$suffix}", 1, new DateTimeImmutable('now', new DateTimeZone('UTC')), $b2bNumber, 'waiting', 'confirmed', 'Confirmed', null, 'in_progress', new DateTimeImmutable('now', new DateTimeZone('UTC')),
    [new OperationalOrderItem(Uuid::v4(), 'b2b-1', 1, 'Draperie proiect', 'B2B-1', null, 'Alb', 200.0, 250.0, 'cm', 5.0, 1, ['notes' => null, 'productionNotes' => null])]), []);
$pdo->commit();
$b2b = $orderInfo((string) $one('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?', [$b2bUuid]), 'b2b');
$ok($stop($syncer, $b2b), 'S1 B2B local stop');
$b2bSent = $ok($send($syncer, $b2b), 'S1 B2B request recorded');
$check($b2bSent['state'] === 'manual_review' && $b2bSent['mechanism'] === 'manual' && $b2bSent['lastError'] === 'B2B_OUTBOUND_NOT_AVAILABLE', 'S1 B2B: manual review, never shown as sent');
$check($view($syncer, $b2b)['cancellation']['sync']['provider']['outbound'] === 'manual', 'S1 B2B capability: manual');
$flag('trendyol', true);

// ---- S2. Trendyol happy path --------------------------------------------------------------------------------------
$check($run()['processed'] >= 1, 'S2 the worker processes the queued operation');
$o2 = $op($g1);
$calls = $puts($g1['package']);
$check(count($calls) === 1 && $calls[0]['body'] === ['lines' => [['lineId' => $g1['package'] * 10 + 1, 'quantity' => 1], ['lineId' => $g1['package'] * 10 + 2, 'quantity' => 2]], 'reasonId' => 500]
    && $calls[0]['storeFrontCode'] === 'RO' && $calls[0]['url'] === "/integration/order/sellers/1000001/shipment-packages/{$g1['package']}/items/unsupplied", 'S2 one notice naming every line with its full quantity: ' . json_encode($calls));
$check($o2['state'] === 'awaiting_confirmation' && (int) $o2['attempt_count'] === 1 && $o2['provider_result'] === 'accepted' && $o2['confirmed_at'] === null, 'S2 accepted is not confirmed');
$check($trail($o2) === ['create:created', 'status_read:leased', 'submit:accepted'], 'S2 trail: read before the send ' . json_encode($trail($o2)));
$check($state($g1)['availability'] === 'in_progress' && $control($g1)['source_status'] !== 'cancelled', 'S2 Arasya still does not claim the marketplace cancelled it');
$run(0);
$check(count($puts($g1['package'])) === 1 && $op($g1)['state'] === 'awaiting_confirmation', 'S2 not due yet: no read, no resend');
$run(130);
$o2 = $op($g1);
$check($o2['state'] === 'confirmed' && $o2['confirmed_by'] === 'status_read' && $o2['provider_status'] === 'Cancelled' && count($puts($g1['package'])) === 1, 'S2 confirmed by reading the package: ' . json_encode($o2));
$check($view($syncer, $g1)['cancellation']['sync']['operation']['staffState'] === 'confirmed', 'S2 Dashboard shows confirmed');
$marketplace($g1);
$check($control($g1)['source_status'] === 'cancelled' && $control($g1)['source_request_status'] === 'confirmed' && $state($g1)['availability'] === 'unavailable' && $op($g1)['state'] === 'confirmed', 'S2 the inbound sync then records the marketplace cancellation');
$run(400);
$check(count($puts($g1['package'])) === 1, 'S2 nothing is ever sent again');
$error($release($releaser, $g1), 409, 'RELEASE_SOURCE_CANCELLED', 'S2 a cancelled order is never released');

// ---- S3. Lost answers -----------------------------------------------------------------------------------------------
// Accepted, then the answer is lost: uncertain; the next read finds it cancelled; no second notice.
$g3 = $trendyol('lost1');
$ok($stop($syncer, $g3), 'S3 stop');
$ok($send($syncer, $g3, ['reasonCode' => 501]), 'S3 sent');
$fxPut(['status' => 200, 'applyStatus' => 'Cancelled', 'failAfterApply' => true]);
$run(0);
$check($op($g3)['state'] === 'uncertain' && $op($g3)['last_error_code'] === 'TRENDYOL_ANSWER_LOST' && count($puts($g3['package'])) === 1, 'S3 a lost answer is uncertain');
$run(60);
$check($op($g3)['state'] === 'uncertain' && count($puts($g3['package'])) === 1, 'S3 inside the consistency window nothing happens');
$fxPut(null);
$run(330);
$check($op($g3)['state'] === 'confirmed' && $op($g3)['confirmed_by'] === 'status_read' && count($puts($g3['package'])) === 1, 'S3 the read confirms it: never sent twice');
// Lost before Trendyol acted: uncertain; open reads never cause a resend; after the observation window a person decides.
$g4 = $trendyol('lost2');
$ok($stop($syncer, $g4), 'S3 stop 2');
$ok($send($syncer, $g4, ['reasonCode' => 504]), 'S3 sent 2');
$fxPut(['fail' => true]);
$run(0);
$check($op($g4)['state'] === 'uncertain' && count($puts($g4['package'])) === 1 && $fxPackage($g4['package'])['status'] === 'Created' && $op($g4)['dispatched_at'] !== null, 'S3 lost without effect: uncertain, counted as possibly dispatched');
$fxPut(null);
foreach ([330, 660, 990, 1320, 1650] as $at) {
    $run($at);
    $check($op($g4)['state'] === 'uncertain' && $op($g4)['last_error_code'] === 'UNCERTAIN_STILL_OPEN' && count($puts($g4['package'])) === 1, "S3 read open at +{$at}s: still uncertain, never resent");
}
$run(2000);
$check($op($g4)['state'] === 'manual_review' && $op($g4)['last_error_code'] === 'UNCERTAIN_OUTCOME_UNRESOLVED' && count($puts($g4['package'])) === 1, 'S3 after the observation window: manual review, still one notice');
$run(5000);
$check(count($puts($g4['package'])) === 1 && $op($g4)['state'] === 'manual_review', 'S3 the worker never resends an unresolved uncertain operation');
$check($view($syncer, $g4)['cancellation']['sync']['operation']['staffState'] === 'attention', 'S3 Staff/Dashboard: needs attention');
$error($release($releaser, $g4), 409, 'RELEASE_SYNC_OPEN', 'S3 production cannot be released while the outcome is unknown');
// A person checked the Seller Panel and retries: the worker reads first, then sends once.
$check($view($syncer, $g4)['cancellation']['sync']['operation']['providerCheckRequired'] === true, 'S3 the view states that a retry needs a check in the channel');
$error($opAction($syncer, $op($g4), 'retry'), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_REQUIRED', 'S3 a retry after an unknown outcome without a stated channel check is refused');
$error($opAction($syncer, $op($g4), 'retry', ['providerChecked' => true]), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_REQUIRED', 'S3 a bare providerChecked flag is not evidence of a check');
$check($op($g4)['state'] === 'manual_review' && count($puts($g4['package'])) === 1, 'S3 a refused retry changes nothing');
$attest = static fn (int $ago = 60, string $note = 'Seller Panel: pachetul este încă activ, fără notificare de nelivrare.', int $now = 0): array => ['checkedAt' => gmdate('Y-m-d\TH:i:s\Z', ($now ?: time()) - $ago), 'note' => $note];
$check($view($syncer, $g4)['cancellation']['sync']['operation']['providerEvidence']['status'] === 'ok', 'S3 the server has its own open read after the observation window');
$ok($opAction($syncer, $op($g4), 'retry', ['providerCheck' => $attest()]), 'S3 person retries after review');
$run(5100);
$check($op($g4)['state'] === 'awaiting_confirmation' && count($puts($g4['package'])) === 2, 'S3 the person-approved retry is read, then sent once');
$t4 = $trail($op($g4));
foreach ($t4 as $i => $step) {
    if (str_starts_with($step, 'submit:')) {
        $check($i > 0 && str_starts_with($t4[$i - 1], 'status_read:'), 'S3 every send directly follows a status read: ' . json_encode($t4));
    }
}
$check(in_array('retry:retry_after_provider_check', $t4, true) && array_search('retry:retry_after_provider_check', $t4, true) < array_search('submit:accepted', $t4, true), 'S3 the second send comes only after the person\'s retry: ' . json_encode($t4));
$run(5300);
$check($op($g4)['state'] === 'confirmed', 'S3 confirmed after the person-approved resend');

// ---- S3b. Unknown outcomes (the retry-safety audit, 2026-10-16) ---------------------------------------------------------
// Accepted, answer lost, and Trendyol's read shows the package open for a while (delayed or stale status): no resend; the
// later read confirms.
$late = $trendyol('lateread');
$ok($stop($syncer, $late), 'S3b delayed stop');
$ok($send($syncer, $late, ['reasonCode' => 500]), 'S3b delayed sent');
$fxPut(['status' => 200, 'applyStatus' => null, 'failAfterApply' => true]);
$run(0);
$fxPut(null);
$check($op($late)['state'] === 'uncertain' && count($puts($late['package'])) === 1, 'S3b accepted, answer lost, package still reads open: uncertain');
$run(330);
$run(660);
$check($op($late)['state'] === 'uncertain' && count($puts($late['package'])) === 1, 'S3b stale open reads: never resent');
$fxStatus($late['package'], 'Cancelled');
$run(990);
$check($op($late)['state'] === 'confirmed' && $op($late)['confirmed_by'] === 'status_read' && count($puts($late['package'])) === 1, 'S3b the delayed status confirms it: exactly one notice');
// Four workers at once on a due uncertain operation: none of them sends.
$quad = $trendyol('quaduncertain');
$ok($stop($syncer, $quad), 'S3b race stop');
$ok($send($syncer, $quad, ['reasonCode' => 500]), 'S3b race sent');
$fxPut(['fail' => true]);
$run(0);
$fxPut(null);
$check($op($quad)['state'] === 'uncertain' && count($puts($quad['package'])) === 1, 'S3b race: uncertain');
$start = (string) (microtime(true) + 0.7);
$procs = [];
$procPipes = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cancellation-sync-worker.php', $dbName, $start, $fixtureFile, '400'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $procPipes[] = $pipes;
}
$outputs = [];
foreach ($procs as $i => $process) {
    $outputs[] = (string) stream_get_contents($procPipes[$i][1]) . (string) stream_get_contents($procPipes[$i][2]);
    fclose($procPipes[$i][1]);
    fclose($procPipes[$i][2]);
    proc_close($process);
}
$check(count($puts($quad['package'])) === 1 && $op($quad)['state'] === 'uncertain' && count(array_filter($trail($op($quad)), static fn (string $s): bool => $s === 'status_read:still_open')) === 1,
    'S3b four concurrent workers on an uncertain operation: one read, no resend ' . json_encode($outputs));
// The marketplace confirms (inbound sync) while the operation waits for its next read: confirmed, nothing sent.
$inb = $trendyol('inbounduncertain');
$ok($stop($syncer, $inb), 'S3b inbound stop');
$ok($send($syncer, $inb, ['reasonCode' => 500]), 'S3b inbound sent');
$fxPut(['fail' => true]);
$run(0);
$fxPut(null);
$fxStatus($inb['package'], 'Cancelled');
$marketplace($inb);
$check($op($inb)['state'] === 'confirmed' && $op($inb)['confirmed_by'] === 'inbound_event', 'S3b inbound confirmation during the uncertainty: confirmed');
$run(400);
$check(count($puts($inb['package'])) === 1 && $op($inb)['state'] === 'confirmed', 'S3b nothing is sent after the inbound confirmation');
// Conflicting status after an unknown outcome: split (UnPacked) or shipped — a person decides, never "rejected".
foreach (['UnPacked' => 'split', 'Shipped' => 'shipped'] as $status => $tag) {
    $c = $trendyol("conflict{$tag}");
    $ok($stop($syncer, $c), "S3b {$tag} stop");
    $ok($send($syncer, $c, ['reasonCode' => 500]), "S3b {$tag} sent");
    $fxPut(['fail' => true]);
    $run(0);
    $fxPut(null);
    $fxStatus($c['package'], $status);
    $run(400);
    $check($op($c)['state'] === 'manual_review' && $op($c)['last_error_code'] === 'TRENDYOL_STATUS_NOT_CANCELLABLE' && $op($c)['provider_status'] === $status && count($puts($c['package'])) === 1,
        "S3b {$status} after an unknown outcome: manual review, no resend " . json_encode([$op($c)['state'], $op($c)['last_error_code']]));
}
// A worker crashed after it may have sent, and the package still reads open: no resend, manual review after the window.
$crashOpen = $trendyol('crashopen');
$ok($stop($syncer, $crashOpen), 'S3b crash-open stop');
$ok($send($syncer, $crashOpen, ['reasonCode' => 500]), 'S3b crash-open sent');
$pdo->prepare("UPDATE order_cancellation_sync_operations SET state = 'processing', lease_owner = 'worker-dead', lease_expires_at = ?, version = version + 1 WHERE order_uuid = ?")->execute([gmdate('Y-m-d H:i:s', time() - 600), $crashOpen['uuid']]);
$run(0);
$check($op($crashOpen)['state'] === 'uncertain' && $op($crashOpen)['dispatched_at'] !== null && $puts($crashOpen['package']) === [], 'S3b recovered lease: uncertain and possibly dispatched, read open, nothing sent');
$run(400);
$run(1000);
$check($op($crashOpen)['state'] === 'uncertain' && $puts($crashOpen['package']) === [], 'S3b recovered lease: still never sent');
$run(2000);
$check($op($crashOpen)['state'] === 'manual_review' && $op($crashOpen)['last_error_code'] === 'UNCERTAIN_OUTCOME_UNRESOLVED' && $puts($crashOpen['package']) === [], 'S3b recovered lease: manual review after the window');
$error($opAction($syncer, $op($crashOpen), 'retry'), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_REQUIRED', 'S3b a recovered lease counts as a possible delivery: a retry needs a provider check');

// ---- S3c. Retry-safety matrix (final hardening, 2026-10-16) -------------------------------------------------------------
// Every row is one transition of the unknown-outcome state machine, run end to end with the real worker, service and
// fixture transport. A second notice after a possible delivery needs (1) the server's own open status read after the
// observation window, at most an hour old, and (2) a person's dated statement of their check in the channel; the worker
// independently refuses to send without such a retry after the last possible delivery.
$evidence = static fn (array $o): ?array => $view($syncer, $o)['cancellation']['sync']['operation']['providerEvidence'];
$retryRows = static fn (array $o): array => $rows("SELECT outcome, evidence_json FROM order_cancellation_sync_attempts WHERE operation_uuid = ? AND kind = 'retry' ORDER BY attempt_seq", [$op($o)['operation_uuid']]);
$unresolved = static function (string $tag, int $at = 0) use ($trendyol, $ok, $stop, $send, $syncer, $fxPut, $run, $op, $check, $puts): array {
    $o = $trendyol($tag);
    $ok($stop($syncer, $o), "{$tag} stop");
    $ok($send($syncer, $o, ['reasonCode' => 500]), "{$tag} sent");
    $fxPut(['fail' => true]);
    $run($at);
    $fxPut(null);
    $run($at + 2000);
    $check($op($o)['state'] === 'manual_review' && $op($o)['last_error_code'] === 'UNCERTAIN_OUTCOME_UNRESOLVED' && count($puts($o['package'])) === 1, "{$tag}: unresolved after the observation window " . json_encode($op($o)));
    return $o;
};
$race = static function (int $offset, int $workers = 4) use ($dbName, $fixtureFile): array {
    $start = (string) (microtime(true) + 0.7);
    $procs = [];
    $pipesOf = [];
    for ($i = 0; $i < $workers; $i++) {
        $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cancellation-sync-worker.php', $dbName, $start, $fixtureFile, (string) $offset], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $pipesOf[] = $pipes;
    }
    $outputs = [];
    foreach ($procs as $i => $process) {
        $outputs[] = (string) stream_get_contents($pipesOf[$i][1]) . (string) stream_get_contents($pipesOf[$i][2]);
        fclose($pipesOf[$i][1]);
        fclose($pipesOf[$i][2]);
        proc_close($process);
    }
    return $outputs;
};
// The HTTP kernel runs on the system clock. Stale evidence needs a later "now": the same service class with a later clock.
$laterService = static function (int $offset) use ($pdo, $container): \Arasya\Operations\Cancellation\Sync\CancellationSyncService {
    $later = new class ($offset) implements Clock {
        public function __construct(private int $offset) {}
        public function now(): DateTimeImmutable { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$this->offset} seconds"); }
    };
    return new \Arasya\Operations\Cancellation\Sync\CancellationSyncService($pdo, new \Arasya\Operations\Authorization\AuthorizationService(), new \Arasya\Operations\Quality\IdempotencyStore($pdo), $later, $container->cancellationProviders());
};
$syncerIdentity = (new \Arasya\Operations\Employee\PdoEmployeeRepository($pdo))->findByUuid($syncer['employeeUuid']);
$apiError = static function (callable $call): ?string { try { $call(); return null; } catch (\Arasya\Operations\Http\ApiException $e) { return $e->errorCode; } };

// R1 first request succeeds: one notice, accepted is not confirmed, the status read confirms.
$r1 = $trendyol('r1ok');
$ok($stop($syncer, $r1), 'R1 stop');
$ok($send($syncer, $r1, ['reasonCode' => 500]), 'R1 sent');
$check($view($syncer, $r1)['cancellation']['sync']['operation']['providerCheckRequired'] === false, 'R1 nothing sent yet: no provider check needed');
$run(0);
$check($op($r1)['state'] === 'awaiting_confirmation' && $op($r1)['confirmed_at'] === null && count($puts($r1['package'])) === 1, 'R1 accepted, awaiting confirmation, one notice');
$check($view($syncer, $r1)['cancellation']['sync']['operation']['providerCheckRequired'] === true, 'R1 once accepted, any later retry would need a provider check');
$run(130);
$check($op($r1)['state'] === 'confirmed' && $op($r1)['confirmed_by'] === 'status_read' && count($puts($r1['package'])) === 1, 'R1 confirmed by the status read, one notice');

// R2 first request definitively refused (4xx): nothing can have been delivered, a retry needs no check and is read first.
$r2 = $trendyol('r2refused');
$ok($stop($syncer, $r2), 'R2 stop');
$ok($send($syncer, $r2, ['reasonCode' => 500]), 'R2 sent');
$fxPut(['status' => 400, 'applyStatus' => null]);
$run(0);
$fxPut(null);
$check($op($r2)['state'] === 'rejected' && count($puts($r2['package'])) === 1 && $view($syncer, $r2)['cancellation']['sync']['operation']['providerCheckRequired'] === false, 'R2 refused: rejected, no possible delivery');
$error($opAction($syncer, $op($r2), 'recheck'), 409, 'CANCELLATION_SYNC_RECHECK_NOT_ELIGIBLE', 'R2 no recheck without a possible delivery');
$run(300);
$check(count($puts($r2['package'])) === 1, 'R2 the worker never resends a refusal');
$ok($opAction($syncer, $op($r2), 'retry'), 'R2 retry of a refusal without a check');
$run(400);
$check(count($puts($r2['package'])) === 2 && $op($r2)['state'] === 'awaiting_confirmation' && str_starts_with(implode(',', array_slice($trail($op($r2)), -2)), 'status_read:leased,submit:accepted'), 'R2 the retry is read, then sent once');

// R3 the request fails in transit (rate limit before any effect, or a broken connection that may or may not have reached
// Trendyol). 429 is retried automatically; a broken connection is uncertain even when the package shows nothing.
$r3 = $trendyol('r3transit');
$ok($stop($syncer, $r3), 'R3 stop');
$ok($send($syncer, $r3, ['reasonCode' => 500]), 'R3 sent');
$fxPut(['status' => 429]);
$run(0);
$check($op($r3)['state'] === 'retry_scheduled' && $view($syncer, $r3)['cancellation']['sync']['operation']['providerCheckRequired'] === false, 'R3 a 429 is not a possible delivery');
$fxPut(['fail' => true]);
$run(200);
$fxPut(null);
$check($op($r3)['state'] === 'uncertain' && $fxPackage($r3['package'])['status'] === 'Created' && count($puts($r3['package'])) === 2, 'R3 a broken connection is uncertain although Trendyol shows nothing');
$check(!in_array($control($r3)['source_request_status'], ['confirmed', 'failed'], true) && $op($r3)['confirmed_at'] === null, 'R3 a timeout is neither a success nor a failure: ' . $control($r3)['source_request_status']);
$run(560);
$check($op($r3)['state'] === 'uncertain' && count($puts($r3['package'])) === 2 && $op($r3)['last_error_code'] === 'UNCERTAIN_STILL_OPEN', 'R3 an open read after the timeout never resends');

// R4 / R15 the write succeeded but its answer was lost: uncertain, the read confirms, exactly one notice.
$r4 = $trendyol('r4lost');
$ok($stop($syncer, $r4), 'R4 stop');
$ok($send($syncer, $r4, ['reasonCode' => 500]), 'R4 sent');
$fxPut(['status' => 200, 'applyStatus' => 'Cancelled', 'failAfterApply' => true]);
$run(0);
$fxPut(null);
$check($op($r4)['state'] === 'uncertain' && $op($r4)['confirmed_at'] === null, 'R4 applied but answer lost: uncertain, not confirmed');
$run(330);
$check($op($r4)['state'] === 'confirmed' && count($puts($r4['package'])) === 1, 'R4 the read confirms the lost success; one notice');

// R5 the provider confirms only after a while (delayed status): open reads keep it uncertain, the later read confirms.
$r5 = $trendyol('r5delayed');
$ok($stop($syncer, $r5), 'R5 stop');
$ok($send($syncer, $r5, ['reasonCode' => 500]), 'R5 sent');
$fxPut(['status' => 200, 'applyStatus' => null, 'failAfterApply' => true]);
$run(0);
$fxPut(null);
$run(330);
$check($op($r5)['state'] === 'uncertain' && count($puts($r5['package'])) === 1, 'R5 still open inside the window: uncertain');
$fxStatus($r5['package'], 'Cancelled');
$run(660);
$check($op($r5)['state'] === 'confirmed' && count($puts($r5['package'])) === 1, 'R5 the delayed status confirms; one notice');

// R6 the provider still shows the package open after the window: manual review with the server's evidence.
$r6 = $unresolved('r6open');
$check($evidence($r6)['status'] === 'ok' && $evidence($r6)['providerStatus'] === 'Created', 'R6 the server evidence: open after the observation window ' . json_encode($evidence($r6)));
$check($view($syncer, $r6)['cancellation']['sync']['actions']['recheck'] === true && $view($syncer, $r6)['cancellation']['sync']['actions']['retry'] === true, 'R6 retry and recheck are offered');

// R10 an old client (bare flag) and R11 forged or malformed statements: refused, nothing changes.
$before6 = $op($r6);
$error($opAction($syncer, $op($r6), 'retry', ['providerChecked' => true]), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_REQUIRED', 'R10 an old client\'s bare flag is refused');
$error($opAction($syncer, $op($r6), 'retry', ['providerCheck' => true]), 422, 'VALIDATION_FAILED', 'R11 a boolean in place of the statement is refused');
$error($opAction($syncer, $op($r6), 'retry', ['providerCheck' => ['note' => 'Am verificat în panou.']]), 422, 'VALIDATION_FAILED', 'R11 a statement without its time is refused');
$error($opAction($syncer, $op($r6), 'retry', ['providerCheck' => $attest(60, 'ok')]), 422, 'VALIDATION_FAILED', 'R11 a statement without a real note is refused');
$error($opAction($syncer, $op($r6), 'retry', ['providerCheck' => $attest(7200)]), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_STALE', 'R11 a check two hours old is refused');
$error($opAction($syncer, $op($r6), 'retry', ['providerCheck' => $attest(-3600)]), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_STALE', 'R11 a check in the future is refused');
$error($opAction($syncer, $op($r6), 'retry', ['providerCheck' => $attest() + ['providerStatus' => 'Cancelled']]), 422, 'VALIDATION_FAILED', 'R11 extra claims are refused');
$error($opAction($keeper, $op($r6), 'retry', ['providerCheck' => $attest()]), 403, 'UNAUTHORIZED_ACTION', 'R11 a manager without the sync permission cannot retry');
$check($op($r6) == $before6 && $retryRows($r6) === [] && count($puts($r6['package'])) === 1, 'R10/R11 refused retries change nothing');

// R9 two managers act on the same screen: the second decision is refused (optimistic version), one retry only.
$body6 = ['expectedVersion' => (int) $before6['version'], 'providerCheck' => $attest()];
$key6 = $key('r6retry');
$ok($dash($syncer, 'POST', "/management/cancellation-sync/operations/{$before6['operation_uuid']}/retry", $body6, $key6), 'R8 the first manager retries with evidence and a statement');
$error($opAction($syncer2, $before6, 'close', ['note' => 'Rezolvat manual în Seller Panel.']), 409, 'CANCELLATION_SYNC_CHANGED', 'R9 a second manager\'s close on the old screen is refused');
$error($opAction($syncer2, $before6, 'retry', ['providerCheck' => $attest()]), 409, 'CANCELLATION_SYNC_CHANGED', 'R9 a second manager\'s retry on the old screen is refused');
$rr6 = $retryRows($r6);
$check(count($rr6) === 1 && $rr6[0]['outcome'] === 'retry_after_provider_check' && $op($r6)['state'] === 'retry_scheduled', 'R9 exactly one retry recorded');
// R8 the recorded decision: the person's statement and the server read it rested on, labelled as an attestation.
$ev6 = json_decode((string) $rr6[0]['evidence_json'], true);
$check(($ev6['attestation']['note'] ?? '') === $body6['providerCheck']['note'] && ($ev6['attestation']['employeeId'] ?? '') === $syncer['employeeUuid'] && ($ev6['serverRead']['providerStatus'] ?? '') === 'Created'
    && is_int($ev6['serverRead']['attemptSeq'] ?? null) && is_int($ev6['deliveryAttemptSeq'] ?? null) && $ev6['serverRead']['attemptSeq'] > $ev6['deliveryAttemptSeq'], 'R8 the retry records the attestation and the server read: ' . json_encode($ev6));
$audit6 = json_decode((string) $one("SELECT metadata_json FROM iam_audit_events WHERE action = 'production.cancellation.sync_retry' AND actor_employee_uuid = ? ORDER BY created_at DESC LIMIT 1", [$syncer['employeeUuid']]), true);
$check(($audit6['outcome'] ?? '') === 'retry_after_provider_check' && ($audit6['evidence']['attestation']['checkedAt'] ?? '') === $body6['providerCheck']['checkedAt'], 'R8 the audit names the attestation: ' . json_encode($audit6));
$check($op($r6)['confirmed_at'] === null && $op($r6)['state'] !== 'confirmed', 'R8 a person\'s statement is never a confirmation');
// Old idempotency key: the same request again is answered from the record, nothing new happens.
$replay6 = $dash($syncer, 'POST', "/management/cancellation-sync/operations/{$before6['operation_uuid']}/retry", $body6, $key6);
$check($replay6['status'] === 200 && count($retryRows($r6)) === 1, 'R8 the same idempotency key replays without a second retry');
// R14 four workers race on the authorized retry: exactly one more notice.
$outputs6 = $race(2100);
$check(count($puts($r6['package'])) === 2 && $op($r6)['state'] === 'awaiting_confirmation', 'R14 four racing workers on an authorized retry: one notice ' . json_encode($outputs6));
$t6 = $trail($op($r6));
$check(array_slice($t6, -3) === ['retry:retry_after_provider_check', 'status_read:leased', 'submit:accepted'], 'R8 read before the authorized send: ' . json_encode($t6));
$replay6b = $dash($syncer, 'POST', "/management/cancellation-sync/operations/{$before6['operation_uuid']}/retry", $body6, $key6);
$run(2200);
$check($replay6b['status'] === 200 && count($puts($r6['package'])) === 2 && count($retryRows($r6)) === 1, 'R8 an old key after the round authorizes nothing new');

// R13 the cron runs twice: one notice.
$r13 = $trendyol('r13cron');
$ok($stop($syncer, $r13), 'R13 stop');
$ok($send($syncer, $r13, ['reasonCode' => 500]), 'R13 sent');
$fxPut(['status' => 200, 'applyStatus' => null]);
$run(0);
$run(0);
$fxPut(null);
$check(count($puts($r13['package'])) === 1 && $op($r13)['state'] === 'awaiting_confirmation', 'R13 two runs of the same schedule: one notice');

// R15 the authorized resend loses its answer again: the old statement does not authorize a third notice.
$r15 = $unresolved('r15again');
$ok($opAction($syncer, $op($r15), 'retry', ['providerCheck' => $attest()]), 'R15 authorized retry');
$fxPut(['fail' => true]);
$run(2100);
$fxPut(null);
$check($op($r15)['state'] === 'uncertain' && count($puts($r15['package'])) === 2, 'R15 the resend is uncertain again');
$run(4200);
$check($op($r15)['state'] === 'manual_review' && count($puts($r15['package'])) === 2, 'R15 observed again, then manual review; no third notice');
// R11 forged state in the database (bypassing the API): the worker's own guard sends nothing.
$cur15 = $op($r15);
$pdo->prepare("UPDATE order_cancellation_sync_operations SET state = 'retry_scheduled', next_attempt_at = UTC_TIMESTAMP(6), version = version + 1 WHERE operation_uuid = ?")->execute([$cur15['operation_uuid']]);
$pdo->prepare("INSERT INTO order_cancellation_sync_attempts (operation_uuid, order_uuid, kind, state_before, state_after, outcome, actor_system, occurred_at) VALUES (?, ?, 'retry', 'manual_review', 'retry_scheduled', 'retry_requested', 'forged', UTC_TIMESTAMP(6))")
    ->execute([$cur15['operation_uuid'], $r15['uuid']]);
$run(4300);
$check($op($r15)['state'] === 'manual_review' && $op($r15)['last_error_code'] === 'PROVIDER_CHECK_REQUIRED' && count($puts($r15['package'])) === 2, 'R11 a forged retry state is refused by the worker: no notice ' . json_encode($op($r15)));

// R16 the package changes between the person's check and the send: the worker's read decides, nothing is sent.
foreach (['Shipped' => 'manual_review', 'Cancelled' => 'confirmed'] as $status => $expect) {
    $r16 = $unresolved('r16' . strtolower($status));
    $ok($opAction($syncer, $op($r16), 'retry', ['providerCheck' => $attest()]), "R16 {$status}: authorized retry");
    $fxStatus($r16['package'], $status);
    $run(2100);
    $check($op($r16)['state'] === $expect && count($puts($r16['package'])) === 1, "R16 package {$status} after the check: {$expect}, no notice " . json_encode($op($r16)));
}
// R11 a statement against server evidence that rules a notice out (the package shipped after the unknown outcome).
$r11 = $trendyol('r11shipped');
$ok($stop($syncer, $r11), 'R11 shipped stop');
$ok($send($syncer, $r11, ['reasonCode' => 500]), 'R11 shipped sent');
$fxPut(['fail' => true]);
$run(0);
$fxPut(null);
$fxStatus($r11['package'], 'Shipped');
$run(400);
$check($op($r11)['state'] === 'manual_review' && $evidence($r11)['status'] === 'not_open', 'R11 the server read rules a notice out');
$error($opAction($syncer, $op($r11), 'retry', ['providerCheck' => $attest()]), 409, 'CANCELLATION_SYNC_PROVIDER_EVIDENCE_REQUIRED', 'R11 a statement cannot override the server read');

// R11 a forged retry state right after a lost answer: the worker refuses, and its open read inside the observation window
// is not evidence for a person's retry either.
$r11b = $trendyol('r11bwindow');
$ok($stop($syncer, $r11b), 'R11b stop');
$ok($send($syncer, $r11b, ['reasonCode' => 500]), 'R11b sent');
$fxPut(['fail' => true]);
$run(0);
$fxPut(null);
$cur11b = $op($r11b);
$pdo->prepare("UPDATE order_cancellation_sync_operations SET state = 'retry_scheduled', next_attempt_at = UTC_TIMESTAMP(6), version = version + 1 WHERE operation_uuid = ?")->execute([$cur11b['operation_uuid']]);
$run(100);
$check($op($r11b)['state'] === 'manual_review' && $op($r11b)['last_error_code'] === 'PROVIDER_CHECK_REQUIRED' && count($puts($r11b['package'])) === 1 && $evidence($r11b)['status'] === 'observing', 'R11b forged at once: no notice, evidence still observing ' . json_encode($evidence($r11b)));
$error($opAction($syncer, $op($r11b), 'retry', ['providerCheck' => $attest()]), 409, 'CANCELLATION_SYNC_PROVIDER_EVIDENCE_REQUIRED', 'R11b a read inside the observation window is not evidence');
// An operation of an earlier stop is never retried: refused 4xx, production released, the old operation stays closed.
$oldGen = $trendyol('oldgeneration');
$ok($stop($syncer, $oldGen), 'old generation stop');
$ok($send($syncer, $oldGen, ['reasonCode' => 500]), 'old generation sent');
$fxPut(['status' => 400, 'applyStatus' => null]);
$run(0);
$fxPut(null);
$oldOp = $op($oldGen);
$check($oldOp['state'] === 'rejected', 'old generation: refused by Trendyol');
$ok($release($releaser, $oldGen), 'old generation: production released');
$error($opAction($syncer, $oldOp, 'retry'), 409, 'CANCELLATION_SYNC_STALE', 'an operation of a released stop cannot be retried');
$ok($stop($syncer, $oldGen), 'old generation: stopped again');
$error($opAction($syncer, $op($oldGen), 'retry'), 409, 'CANCELLATION_SYNC_STALE', 'an operation of an earlier stop cannot be retried under a new stop');
$run(300);
$check(count($puts($oldGen['package'])) === 1 && $op($oldGen)['state'] === 'rejected', 'the old operation sends nothing');

// R12 the server's evidence is stale: refused until a read-only recheck produces a new read; the recheck sends nothing.
$r12 = $unresolved('r12stale');
$later = $laterService(2000 + SyncOperations::RETRY_EVIDENCE_MAX_AGE_SECONDS + 100);
$laterNow = time() + 2000 + SyncOperations::RETRY_EVIDENCE_MAX_AGE_SECONDS + 100;
$stale = $apiError(fn () => $later->retry($syncerIdentity, $op($r12)['operation_uuid'], ['expectedVersion' => (int) $op($r12)['version'], 'providerCheck' => $attest(60, 'Seller Panel: pachetul este încă activ.', $laterNow)], $key('r12a'), 'r12a'));
$check($stale === 'CANCELLATION_SYNC_PROVIDER_EVIDENCE_REQUIRED', 'R12 an hour-old server read does not support a retry: ' . var_export($stale, true));
$later->recheck($syncerIdentity, $op($r12)['operation_uuid'], ['expectedVersion' => (int) $op($r12)['version']], $key('r12b'), 'r12b');
$check($op($r12)['state'] === 'uncertain' && count($puts($r12['package'])) === 1, 'R12 the recheck only schedules a read');
$run(2000 + SyncOperations::RETRY_EVIDENCE_MAX_AGE_SECONDS + 150);
$check($op($r12)['state'] === 'manual_review' && count($puts($r12['package'])) === 1 && array_slice($trail($op($r12)), -1) === ['status_read:still_open'], 'R12 the recheck read the package, sent nothing');
$fresh12 = $later->retry($syncerIdentity, $op($r12)['operation_uuid'], ['expectedVersion' => (int) $op($r12)['version'], 'providerCheck' => $attest(60, 'Seller Panel: pachetul este încă activ.', $laterNow)], $key('r12c'), 'r12c');
$check($fresh12['state'] === 'retry_scheduled', 'R12 with a fresh read the retry is accepted');

// R1b an accepted notice that never shows cancelled (six hours): a person decides, and a second notice needs the same
// evidence and statement as after a lost answer (an accepted notice may still be processed).
$r1b = $trendyol('r1baccepted');
$ok($stop($syncer, $r1b), 'R1b stop');
$ok($send($syncer, $r1b, ['reasonCode' => 500]), 'R1b sent');
$fxPut(['status' => 200, 'applyStatus' => null]);
$run(0);
$fxPut(null);
$run(SyncOperations::CONFIRMATION_TIMEOUT_SECONDS + 100);
$check($op($r1b)['state'] === 'manual_review' && $op($r1b)['last_error_code'] === 'AWAITING_CONFIRMATION_TIMEOUT' && $evidence($r1b)['status'] === 'ok', 'R1b accepted, never confirmed: manual review with evidence');
$error($opAction($syncer, $op($r1b), 'retry'), 409, 'CANCELLATION_SYNC_PROVIDER_CHECK_REQUIRED', 'R1b a second notice after an accepted one needs a provider check');
$ok($opAction($syncer, $op($r1b), 'retry', ['providerCheck' => $attest()]), 'R1b checked retry');
$run(SyncOperations::CONFIRMATION_TIMEOUT_SECONDS + 200);
$check(count($puts($r1b['package'])) === 2, 'R1b one more notice after the checked retry');

// R7 the provider cannot be read after the unknown outcome: the server has no evidence; a recheck later produces it.
$r7 = $trendyol('r7dark');
$ok($stop($syncer, $r7), 'R7 stop');
$ok($send($syncer, $r7, ['reasonCode' => 500]), 'R7 sent');
$fxPut(['fail' => true]);
$run(0);
$fxPut(null);
$fxGet(['fail' => true]);
$run(400);
$check($op($r7)['state'] === 'uncertain' && count($puts($r7['package'])) === 1, 'R7 unreadable: still uncertain, nothing sent');
$run(7 * 3600);
$fxGet([]);
$check($op($r7)['state'] === 'manual_review' && $op($r7)['last_error_code'] === 'PROVIDER_UNREACHABLE' && $evidence($r7)['status'] === 'unknown', 'R7 six hours unreadable: manual review, the server has no evidence ' . json_encode($evidence($r7)));
$error($opAction($syncer, $op($r7), 'retry', ['providerCheck' => $attest()]), 409, 'CANCELLATION_SYNC_PROVIDER_EVIDENCE_REQUIRED', 'R7 a statement alone cannot replace the server read');
$ok($opAction($syncer, $op($r7), 'recheck'), 'R7 recheck');
$run(7 * 3600 + 100);
$check($op($r7)['state'] === 'manual_review' && $evidence($r7)['status'] === 'ok' && count($puts($r7['package'])) === 1, 'R7 the recheck read gives the evidence, no notice');
$ok($opAction($syncer, $op($r7), 'retry', ['providerCheck' => $attest()]), 'R7 retry with evidence and a statement');
$run(7 * 3600 + 200);
$check(count($puts($r7['package'])) === 2 && $op($r7)['state'] === 'awaiting_confirmation', 'R7 one authorized notice');
// Scenario A: a cancellation that arrives from Trendyol (customer or marketplace) stops production and records the
// external status; it never creates an outbound request, and nothing is sent to Trendyol.
$inboundOnly = $trendyol('inboundonly');
$fxStatus($inboundOnly['package'], 'Cancelled');
$marketplace($inboundOnly);
$run(7 * 3600 + 300);
$check($state($inboundOnly)['cancellation'] === 'held' && $control($inboundOnly)['source_status'] === 'cancelled' && $op($inboundOnly) === null && $puts($inboundOnly['package']) === []
    && $view($syncer, $inboundOnly)['cancellation']['sync']['required'] === false && $view($syncer, $inboundOnly)['cancellation']['sync']['actions']['send'] === false,
    'A an inbound Trendyol cancellation stops production and never produces an unsupplied notice ' . json_encode([$state($inboundOnly)['cancellation'], $control($inboundOnly)['source_status'] ?? null]));
$error($send($syncer, $inboundOnly, ['reasonCode' => 500]), 409, 'SOURCE_ALREADY_CANCELLED', 'A no notice can be requested for an order the marketplace already cancelled');
// A manual close is never shown as a confirmation.
$rc = $unresolved('rclose');
$ok($opAction($syncer, $op($rc), 'close', ['note' => 'Rezolvat manual în Seller Panel de manager.']), 'close after manual handling');
$check($op($rc)['state'] === 'closed' && $op($rc)['confirmed_at'] === null && $view($syncer, $rc)['cancellation']['sync']['operation']['staffState'] === null && $control($rc)['source_status'] !== 'cancelled', 'a person\'s close is not a confirmation');

// ---- S4. Failures ---------------------------------------------------------------------------------------------------
$failCase = static function (string $tag, array $script, string $expectState, ?string $expectError, string $label) use ($trendyol, $stop, $send, $ok, $syncer, $fxPut, $run, $op, $check, $puts): array {
    $o = $trendyol($tag);
    $ok($stop($syncer, $o), "{$label} stop");
    $ok($send($syncer, $o, ['reasonCode' => 500]), "{$label} sent");
    $fxPut($script);
    $run(0);
    $fxPut(null);
    $check($op($o)['state'] === $expectState && $op($o)['last_error_code'] === $expectError && count($puts($o['package'])) === 1, "{$label}: " . json_encode($op($o)));
    return $o;
};
$r429 = $failCase('f429', ['status' => 429], 'retry_scheduled', 'TRENDYOL_RATE_LIMITED', 'S4 429 is retried later');
$next = strtotime($op($r429)['next_attempt_at'] . ' UTC') - time();
$check($next >= 40 && $next <= 80, 'S4 first backoff is about one minute (jitter): ' . $next);
$run(100);
$check($op($r429)['state'] === 'awaiting_confirmation' && count($puts($r429['package'])) === 2, 'S4 the retry after the backoff is accepted');
$r500 = $failCase('f500', ['status' => 503, 'applyStatus' => null], 'uncertain', 'TRENDYOL_SERVER_ERROR', 'S4 5xx is uncertain (Trendyol may have acted)');
$r401 = $failCase('f401', ['status' => 401, 'applyStatus' => null], 'failed', 'TRENDYOL_AUTH_FAILED', 'S4 401 is a permanent authorization failure');
$r400 = $failCase('f400', ['status' => 400, 'applyStatus' => null], 'rejected', 'TRENDYOL_REJECTED_400', 'S4 400 is a refusal');
$run(4000);
$check(count($puts($r401['package'])) === 1 && count($puts($r400['package'])) === 1, 'S4 failed and rejected operations are never resent by the worker');
$check(count($puts($r500['package'])) === 1 && $op($r500)['state'] === 'manual_review' && $op($r500)['last_error_code'] === 'UNCERTAIN_OUTCOME_UNRESOLVED', 'S4 a 5xx is never resent by the worker: manual review after the window');
// Not cancellable / not found: refused before anything is sent.
$ship = $trendyol('ship');
$ok($stop($syncer, $ship), 'S4 shipped stop');
$ok($send($syncer, $ship, ['reasonCode' => 500]), 'S4 shipped sent');
$fxStatus($ship['package'], 'Shipped');
$run(0);
$check($op($ship)['state'] === 'rejected' && $op($ship)['last_error_code'] === 'TRENDYOL_STATUS_NOT_CANCELLABLE' && $puts($ship['package']) === [], 'S4 a shipped package is never sent');
$gone = $trendyol('gone');
$ok($stop($syncer, $gone), 'S4 unknown stop');
$ok($send($syncer, $gone, ['reasonCode' => 500]), 'S4 unknown sent');
$fxRemove($gone['package']);
$run(0);
$check($op($gone)['state'] === 'rejected' && $op($gone)['last_error_code'] === 'TRENDYOL_PACKAGE_NOT_FOUND' && $puts($gone['package']) === [], 'S4 an unknown package is never sent');
// Attempt budget: six rate-limited sends, then a person decides.
$budget = $trendyol('budget');
$ok($stop($syncer, $budget), 'S4 budget stop');
$ok($send($syncer, $budget, ['reasonCode' => 500]), 'S4 budget sent');
$fxPut(['status' => 429]);
for ($i = 0, $at = 0; $i < 8; $i++, $at += 4000) $run($at);
$fxPut(null);
$check($op($budget)['state'] === 'manual_review' && $op($budget)['last_error_code'] === 'ATTEMPTS_EXHAUSTED' && count($puts($budget['package'])) === 6, 'S4 at most six sends, then manual review: ' . count($puts($budget['package'])));
$check($view($syncer, $budget)['cancellation']['sync']['operation']['staffState'] === 'attention', 'S4 Dashboard: needs attention');
// The status read itself failing: retried, never sent blind; six hours of failures go to a person.
$dark = $trendyol('dark');
$ok($stop($syncer, $dark), 'S4 unreachable stop');
$ok($send($syncer, $dark, ['reasonCode' => 500]), 'S4 unreachable sent');
$fxGet(['fail' => true]);
$run(0);
$check($op($dark)['state'] === 'queued' || $op($dark)['state'] === 'retry_scheduled', 'S4 a failed read: retried later ' . $op($dark)['state']);
$check($puts($dark['package']) === [], 'S4 never sent without a successful read');
$run(7 * 3600);
$fxGet([]);
$check($op($dark)['state'] === 'manual_review' && $op($dark)['last_error_code'] === 'PROVIDER_UNREACHABLE' && $puts($dark['package']) === [], 'S4 six hours unreachable: manual review');
$fxGet(['status' => 401]);
$authRead = $trendyol('authread');
$ok($stop($syncer, $authRead), 'S4 auth read stop');
$ok($send($syncer, $authRead, ['reasonCode' => 500]), 'S4 auth read sent');
$run(0);
$fxGet([]);
$check($op($authRead)['state'] === 'failed' && $op($authRead)['last_error_code'] === 'TRENDYOL_AUTH_FAILED' && $puts($authRead['package']) === [], 'S4 refused credentials on the read: failed, nothing sent');

// ---- S5. Crash, concurrency, inbound during the call ----------------------------------------------------------------
// A worker that died while holding the lease: recovered as uncertain (it may have sent), then read before anything else.
$crash = $trendyol('crash');
$ok($stop($syncer, $crash), 'S5 crash stop');
$ok($send($syncer, $crash, ['reasonCode' => 500]), 'S5 crash sent');
$past = gmdate('Y-m-d H:i:s', time() - 600);
$pdo->prepare("UPDATE order_cancellation_sync_operations SET state = 'processing', lease_owner = 'worker-dead', lease_expires_at = ?, version = version + 1 WHERE order_uuid = ?")->execute([$past, $crash['uuid']]);
$fxStatus($crash['package'], 'Cancelled'); // the dead worker's notice had reached Trendyol
$summary = $run(0);
$check($summary['recovered'] >= 1 && in_array('lease_recovery:lease_expired', $trail($op($crash)), true), 'S5 the expired lease is recovered');
$check($op($crash)['state'] === 'confirmed' && $puts($crash['package']) === [], 'S5 the read after recovery confirms; no second notice');
// Four workers at once on one due operation: exactly one notice.
$race = $trendyol('race');
$ok($stop($syncer, $race), 'S5 race stop');
$ok($send($syncer, $race, ['reasonCode' => 500]), 'S5 race sent');
$start = (string) (microtime(true) + 0.7);
$procs = [];
$procPipes = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cancellation-sync-worker.php', $dbName, $start, $fixtureFile, '0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $procPipes[] = $pipes;
}
$outputs = [];
foreach ($procs as $i => $process) {
    $outputs[] = (string) stream_get_contents($procPipes[$i][1]) . (string) stream_get_contents($procPipes[$i][2]);
    fclose($procPipes[$i][1]);
    fclose($procPipes[$i][2]);
    proc_close($process);
}
$check(count($puts($race['package'])) === 1 && $op($race)['state'] === 'awaiting_confirmation', 'S5 four concurrent workers: one notice ' . json_encode($outputs));
// The inbound cancellation arrives while the worker is inside the HTTP call: the worker holds no transaction, the inbound
// confirmation commits, and the worker's late answer is recorded without changing the state.
$hooked = $trendyol('hook');
$ok($stop($syncer, $hooked), 'S5 hook stop');
$ok($send($syncer, $hooked, ['reasonCode' => 500]), 'S5 hook sent');
$hookTransport = new class ($fixtureFile, $marketplace, $hooked) implements TrendyolTransport {
    private FixtureTrendyolTransport $inner;
    public function __construct(string $file, private Closure $marketplace, private array $order) { $this->inner = new FixtureTrendyolTransport($file); }
    public function get(string $url, array $headers): array { return $this->inner->get($url, $headers); }
    public function put(string $url, array $headers, string $body): array
    {
        $answer = $this->inner->put($url, $headers, $body);
        ($this->marketplace)($this->order); // the intake synchronization runs in parallel and sees the cancellation
        return $answer;
    }
};
$credentials = TrendyolCredentials::fromValues('1000001', 'fixture', 'fixture', '');
$hookRegistry = new ProviderRegistry($pdo, $config, new TrendyolCancellationProvider(new TrendyolClient($credentials, $hookTransport), new TrendyolCancellationApi($credentials, $hookTransport, 'RO')));
$run(0, $hookRegistry);
$hookOp = $op($hooked);
$check($hookOp['state'] === 'confirmed' && $hookOp['confirmed_by'] === 'inbound_event' && in_array('submit:late_accepted', $trail($hookOp), true) && count($puts($hooked['package'])) === 1, 'S5 inbound confirmation during the call wins; the late answer is kept as evidence: ' . json_encode($trail($hookOp)));

// ---- S6. WooCommerce (source pull) ----------------------------------------------------------------------------------
$heartbeat = static fn (string $source, ?\Arasya\Operations\Http\ApiKernel $via = null): string => (string) ($sourceCall($source, 'heartbeat', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, $via)['body']['cancellationSyncMode'] ?? '');
$check($heartbeat('trendhome') === 'disabled', 'S6 heartbeat: disabled while the flag is off');
$w1 = $woo('trendhome', 'w1');
$ok($stop($syncer, $w1), 'S6 stop');
$error($send($syncer, $w1), 409, 'CANCELLATION_SYNC_DISABLED', 'S6 flag off: not handed over');
$check($ok($pull('trendhome'), 'S6 pull with the flag off')['operations'] === [], 'S6 the connector receives nothing while disabled');
$flag('trendhome', true);
$check($heartbeat('trendhome') === 'enabled' && $heartbeat('trendhome', $offKernel) === 'disabled' && $heartbeat('outletperdele') === 'disabled', 'S6 heartbeat: enabled only with both gates for this source');
$w1Sent = $ok($send($syncer, $w1), 'S6 handed to the connector');
$check($w1Sent['state'] === 'awaiting_pickup' && $w1Sent['mechanism'] === 'source_pull' && $w1Sent['reasonCode'] === null, 'S6 awaiting pickup');
$check($ok($pull('trendhome', $offKernel), 'S6 pull with the server switch off')['operations'] === [], 'S6 server switch off: nothing handed over');
$check($ok($pull('outletperdele'), 'S6 the other site pulls')['operations'] === [], 'S6 another site never sees it');
$pulled = $ok($pull('trendhome'), 'S6 pull');
$mine = array_values(array_filter($pulled['operations'], static fn (array $item): bool => $item['operationId'] === $op($w1)['operation_uuid']));
$check(count($mine) === 1 && $mine[0]['orderId'] === $w1['number'] && array_keys($mine[0]) === ['operationId', 'orderId', 'requestedAt'], 'S6 the connector receives the shop order id only: ' . json_encode($mine));
$check($op($w1)['last_pulled_at'] !== null && in_array('pickup:picked_up', $trail($op($w1)), true), 'S6 pickup recorded');
$error($opAction($syncer, $op($w1), 'withdraw', ['reason' => 'Clientul s-a răzgândit.']), 409, 'CANCELLATION_SYNC_NOT_WITHDRAWABLE', 'S6 after pickup it cannot be withdrawn');
$error($sourceCall('trendhome', 'orders/cancellations', ['sentAt' => 'x'], 'wrong-secret-0123456789abcdef0123456789'), 401, 'SOURCE_SIGNATURE_INVALID', 'S6 an unsigned pull is refused');
$error($report('outletperdele', $op($w1), 'cancelled'), 404, 'CANCELLATION_SYNC_NOT_FOUND', 'S6 another site cannot report it (indistinguishable from missing)');
$error($sourceCall('trendhome', 'orders/cancellations/result', ['operationId' => $op($w1)['operation_uuid'], 'outcome' => 'refunded']), 422, 'SOURCE_PAYLOAD_INVALID', 'S6 unknown outcomes are refused');
$error($sourceCall('trendhome', 'orders/cancellations/result', ['operationId' => $op($w1)['operation_uuid'], 'outcome' => 'cancelled', 'customer' => 'x']), 422, 'SOURCE_PAYLOAD_INVALID', 'S6 extra fields are refused');
$reported = $ok($report('trendhome', $op($w1), 'cancelled', 'cancelled'), 'S6 report');
$check($reported['state'] === 'awaiting_confirmation' && $op($w1)['confirmed_at'] === null, 'S6 a report is not a confirmation');
$staffStop = $staffView($waiter, $w1)['productionStop'] ?? [];
$check(($staffStop['sync'] ?? null) === 'awaiting_confirmation' && !isset($staffStop['operation']) && !str_contains(json_encode($staffStop), $op($w1)['operation_uuid']), 'S6 Staff: awaiting confirmation, no operation details');
$check($ok($report('trendhome', $op($w1), 'cancelled', 'cancelled'), 'S6 duplicate report')['state'] === 'awaiting_confirmation' && in_array('report:duplicate_report', $trail($op($w1)), true), 'S6 a repeated report changes nothing');
$error($report('trendhome', $op($w1), 'rejected_paid'), 409, 'CANCELLATION_SYNC_CONFLICT', 'S6 a conflicting report is refused and kept as evidence');
$ok($wooEvent($w1, 'cancelled', 'cancelled'), 'S6 the shop event arrives');
$check($op($w1)['state'] === 'confirmed' && $op($w1)['confirmed_by'] === 'inbound_event' && $control($w1)['source_request_status'] === 'confirmed', 'S6 confirmed by the inbound event');
$check(($staffView($waiter, $w1)['productionStop']['sync'] ?? null) === 'confirmed', 'S6 Staff: confirmed');
$check($ok($report('trendhome', $op($w1), 'already_cancelled'), 'S6 report after confirmation')['state'] === 'confirmed', 'S6 a late report after confirmation is harmless');
// Paid order: the connector refuses; a person decides and closes it (never a refund).
$w2 = $woo('trendhome', 'w2paid');
$ok($stop($syncer, $w2), 'S6 paid stop');
$ok($send($syncer, $w2), 'S6 paid handed over');
$ok($pull('trendhome'), 'S6 paid pull');
$check($ok($report('trendhome', $op($w2), 'rejected_paid'), 'S6 paid refused by the shop')['state'] === 'manual_review' && $op($w2)['last_error_code'] === 'PAID_ORDER_REQUIRES_PERSON', 'S6 a paid order needs a person');
$error($release($releaser, $w2), 409, 'RELEASE_SYNC_OPEN', 'S6 no release while it waits for a person');
$error($opAction($syncer, $op($w2), 'close', ['note' => 'scurt']), 422, 'VALIDATION_FAILED', 'S6 closing needs a note');
$closed = $ok($opAction($syncer, $op($w2), 'close', ['note' => 'Anulat manual în magazin după verificarea plății.']), 'S6 closed by a person');
$check($closed['state'] === 'closed' && $closed['confirmedAt'] === null && $op($w2)['closed_by_employee_uuid'] === $syncer['employeeUuid'], 'S6 closed, never shown as confirmed');
// Withdraw before pickup.
$w3 = $woo('outletperdele', 'w3');
$flag('outletperdele', true);
$ok($stop($syncer, $w3), 'S6 withdraw stop');
$ok($send($syncer, $w3), 'S6 withdraw handed over');
$withdrawn = $ok($opAction($syncer, $op($w3), 'withdraw', ['reason' => 'Clientul a revenit asupra anulării.']), 'S6 withdrawn before pickup');
$check($withdrawn['state'] === 'withdrawn' && $ok($pull('outletperdele'), 'S6 pull after withdraw')['operations'] === [], 'S6 a withdrawn request is never handed over');
// Pickup timeout: 24 hours without pickup goes to a person.
$w4 = $woo('outletperdele', 'w4');
$ok($stop($syncer, $w4), 'S6 timeout stop');
$ok($send($syncer, $w4), 'S6 timeout handed over');
$run(25 * 3600);
$check($op($w4)['state'] === 'manual_review' && $op($w4)['last_error_code'] === 'PICKUP_TIMEOUT', 'S6 no pickup for a day: manual review');
// ---- S6d. Pickup window after a retry (fixed clock) -------------------------------------------------------------------
// The first 24 hours without pickup go to a person (above). A person's retry hands the same operation over again and
// starts a new pickup window: the operation does not time out again at once, it is handed out once, its history and
// identity stay, and only the next full day without a report times it out again. Timestamps are moved a day back, as if
// the operation had been created yesterday (the HTTP routes run on the system clock).
$timed4 = $op($w4);
$created4 = $timed4['created_at'];
$pdo->prepare('UPDATE order_cancellation_sync_operations SET created_at = DATE_SUB(created_at, INTERVAL 26 HOUR), updated_at = DATE_SUB(updated_at, INTERVAL 26 HOUR) WHERE operation_uuid = ?')->execute([$timed4['operation_uuid']]);
$pdo->prepare('UPDATE order_cancellation_sync_attempts SET occurred_at = DATE_SUB(occurred_at, INTERVAL 26 HOUR) WHERE operation_uuid = ?')->execute([$timed4['operation_uuid']]); // test fixture: yesterday's history
$timed4 = $op($w4);
$trail4 = $trail($timed4);
$check(end($trail4) === 'expire:timeout' && $timed4['last_error_code'] === 'PICKUP_TIMEOUT', 'S6d the first day without pickup ended in manual review: ' . json_encode($trail4));
$error($opAction($keeper, $timed4, 'retry'), 403, 'UNAUTHORIZED_ACTION', 'S6d a manager without the sync permission cannot retry');
$error($opAction($syncer, $timed4, 'retry', ['expectedVersion' => (int) $timed4['version'] - 1]), 409, 'CANCELLATION_SYNC_CHANGED', 'S6d a retry on an old version is refused');
$check($op($w4)['version'] === $timed4['version'] && $trail($op($w4)) === $trail4, 'S6d refused retries change nothing');
$retried4 = $ok($opAction($syncer, $timed4, 'retry'), 'S6d authorized retry');
$error($opAction($syncer2, $timed4, 'retry'), 409, 'CANCELLATION_SYNC_CHANGED', 'S6d a second manager\'s retry on the same screen is refused (one retry)');
$after4 = $op($w4);
$check($retried4['state'] === 'awaiting_pickup' && $after4['operation_uuid'] === $timed4['operation_uuid'] && count($ops($w4)) === 1 && $after4['created_at'] === $timed4['created_at']
    && $after4['requested_by_employee_uuid'] === $timed4['requested_by_employee_uuid'] && $after4['last_pulled_at'] === null, 'S6d the same operation is handed over again; identity and creation kept');
$check($trail($after4) === [...$trail4, 'retry:retry_requested', 'retry:handed_to_source'], 'S6d the history keeps every attempt and adds the retry: ' . json_encode($trail($after4)));
$run(0);
$check($op($w4)['state'] === 'awaiting_pickup', 'S6d the retried operation is not timed out again at once (worker right after the retry): ' . $op($w4)['state']);
$run(23 * 3600);
$check($op($w4)['state'] === 'awaiting_pickup', 'S6d nor 23 hours after the retry');
$pickupDelayed = static function (int $offset) use ($pdo, $wclock, $container, $w4): bool {
    $wclock->offset = $offset;
    $findings = (new \Arasya\Operations\Cancellation\Sync\CancellationReconciler($pdo, $wclock, $container->cancellationProviders()))->scan();
    $wclock->offset = 0;
    return array_filter($findings, static fn (array $f): bool => $f['orderUuid'] === $w4['uuid'] && $f['kind'] === 'operation_pickup_delayed') !== [];
};
$check(!$pickupDelayed(0) && $pickupDelayed(31 * 60), 'S6d the reconciliation counts the pickup delay from the retry, not from yesterday\'s creation');
// The retry was two hours ago; the shop picks it up now. The pickup does not restart the window: 23 hours after the
// pickup (25 after the retry) without a report it goes to a person again.
$pdo->prepare("UPDATE order_cancellation_sync_attempts SET occurred_at = DATE_SUB(occurred_at, INTERVAL 2 HOUR) WHERE operation_uuid = ? AND kind = 'retry'")->execute([$after4['operation_uuid']]); // test fixture
$picked4 = array_column($ok($pull('outletperdele'), 'S6d pull after the retry')['operations'], 'operationId');
$check(count(array_keys($picked4, $after4['operation_uuid'], true)) === 1 && array_count_values($trail($op($w4)))['pickup:picked_up'] === 1, 'S6d handed out once; one pickup recorded');
$repeat4 = array_column($ok($pull('outletperdele'), 'S6d repeated pull')['operations'], 'operationId');
$check(count(array_keys($repeat4, $after4['operation_uuid'], true)) === 1 && array_count_values($trail($op($w4)))['pickup:picked_up'] === 1 && count($ops($w4)) === 1, 'S6d a repeated pull offers the same operation id, records no second pickup, creates nothing');
$run(21 * 3600);
$check($op($w4)['state'] === 'awaiting_pickup', 'S6d 24 hours after the retry have not passed yet');
$run(23 * 3600);
$final4 = $op($w4);
$check($final4['state'] === 'manual_review' && $final4['last_error_code'] === 'PICKUP_TIMEOUT' && array_count_values($trail($final4))['expire:timeout'] === 2, 'S6d a full day after the retry without a report: a person again (the pickup did not extend the window)');
$check($final4['operation_uuid'] === $timed4['operation_uuid'] && count($ops($w4)) === 1 && $final4['created_at'] === $timed4['created_at'], 'S6d still one operation');
// Race: a worker selects the operation while another worker's timeout and a person's retry are committing. Under the
// order lock it sees a changed operation and leaves it to the next run, so the fresh retry is not timed out.
$retriedRace = $ok($opAction($syncer, $final4, 'retry'), 'S6d second retry');
$pdo->prepare('UPDATE order_cancellation_sync_attempts SET occurred_at = DATE_SUB(occurred_at, INTERVAL 26 HOUR) WHERE operation_uuid = ?')->execute([$final4['operation_uuid']]); // test fixture: due again
$pdoB = Connection::create($config);
$opsB = new SyncOperations($pdoB, $clock);
$pdoB->beginTransaction();
$pdoB->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$w4['uuid']]);
$inB = $opsB->byId($final4['operation_uuid']);
$inB = $opsB->transition($inB, 'manual_review', ['next_attempt_at' => null], ['kind' => 'expire', 'outcome' => 'timeout', 'error' => 'PICKUP_TIMEOUT', 'system' => 'test-other-worker']);
$inB = $opsB->transition($inB, 'retry_scheduled', ['next_attempt_at' => $opsB->now(), 'attempt_count' => 0], ['kind' => 'retry', 'outcome' => 'retry_requested', 'actor' => $syncer['employeeUuid']]);
$inB = $opsB->transition($inB, 'awaiting_pickup', ['next_attempt_at' => null, 'last_pulled_at' => null], ['kind' => 'retry', 'outcome' => 'handed_to_source', 'actor' => $syncer['employeeUuid']]);
$raceWorker = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cancellation-sync-worker.php', $dbName, (string) microtime(true), $fixtureFile, '0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $racePipes);
fclose($racePipes[0]);
usleep(1_500_000); // the worker has selected the operation and waits for the order lock
$waiting4 = (int) $one("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'");
$pdoB->commit();
$raceOut = (string) stream_get_contents($racePipes[1]) . (string) stream_get_contents($racePipes[2]);
fclose($racePipes[1]);
fclose($racePipes[2]);
proc_close($raceWorker);
$raced4 = $op($w4);
$check($waiting4 >= 1, 'S6d the worker was waiting for the order lock while the retry committed');
$check($raced4['state'] === 'awaiting_pickup' && $raced4['version'] === $inB['version'] && array_count_values($trail($raced4))['expire:timeout'] === 3, 'S6d the worker did not time out the fresh retry it had selected before: ' . $raceOut . ' ' . json_encode(array_slice($trail($raced4), -4)));
$run(0);
$check($op($w4)['state'] === 'awaiting_pickup', 'S6d the next run keeps the fresh window');

// The connector's shop no longer has the order: failed; a retry hands it over again.
$w5 = $woo('outletperdele', 'w5');
$ok($stop($syncer, $w5), 'S6 not found stop');
$ok($send($syncer, $w5), 'S6 not found handed over');
$ok($pull('outletperdele'), 'S6 not found pull');
$check($ok($report('outletperdele', $op($w5), 'not_found'), 'S6 not found report')['state'] === 'failed', 'S6 not found: failed');
$retried = $ok($opAction($syncer, $op($w5), 'retry'), 'S6 retry');
$check($retried['state'] === 'awaiting_pickup' && $op($w5)['last_pulled_at'] === null && in_array('retry:handed_to_source', $trail($op($w5)), true), 'S6 a retry hands it over again, same operation');
$check(count($ops($w5)) === 1, 'S6 a retry never creates a second logical cancellation');

// ---- S6b. Owner rule 2: an open finance entry blocks the cancellation request ----------------------------------------
// The shop's last reported attention (stored from its signed order event) and Arasya's own relayed finance actions.
$attention = static function (array $o, ?string $value) use ($pdo): void {
    $pdo->prepare('UPDATE operational_orders SET source_financial_attention = ? WHERE order_uuid = ?')->execute([$value, $o['uuid']]);
};
$financeAction = static function (array $o, string $state) use ($pdo, $syncer): string {
    $uuid = Uuid::v4();
    $done = $state === 'applied';
    $pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, purpose, source_entry_id, idempotency_key, request_hmac,
        revision_before, state, outcome, requested_at, completed_at) VALUES (?, ?, ?, ?, ?, 'submit_transfer', 'advance', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?)")
        ->execute([$uuid, $o['uuid'], $o['id'], $o['source'], $syncer['employeeUuid'], $done ? Uuid::v4() : null, 'fin-' . bin2hex(random_bytes(8)), random_bytes(32),
            'sha256:' . str_repeat('a', 64), $state, $done ? 'applied' : null, $done ? gmdate('Y-m-d H:i:s') : null]);
    return $uuid;
};
$setAction = static function (string $uuid, string $state) use ($pdo): void {
    $done = $state === 'applied';
    $pdo->prepare('UPDATE order_finance_actions SET state = ?, outcome = ?, completed_at = ?, source_entry_id = ? WHERE action_uuid = ?')
        ->execute([$state, $done ? 'applied' : null, $done ? gmdate('Y-m-d H:i:s') : null, $done ? Uuid::v4() : null, $uuid]);
};
$f1 = $woo('trendhome', 'fin1');
$ok($stop($syncer, $f1), 'S6b stop');
foreach (['transfer_pending', 'refund_pending', 'reconciliation_required'] as $open) {
    $attention($f1, $open);
    $error($send($syncer, $f1), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', "S6b the shop reports {$open}: no request");
}
$fixedKey = 'cs-fin-same-key-' . bin2hex(random_bytes(4));
$error($send($syncer, $f1, [], $fixedKey), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'S6b refused with a fixed key');
$error($send($syncer, $f1, [], $fixedKey), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'S6b the same request again: the same refusal, nothing stored');
$check($ops($f1) === [], 'S6b the refusals created no operation');
$attention($f1, null);
$pending = $financeAction($f1, 'pending');
$error($send($syncer, $f1), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'S6b a relayed finance action waiting for the shop blocks');
$setAction($pending, 'unknown');
$error($send($syncer, $f1), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'S6b a relayed finance action with an unknown answer blocks');
$setAction($pending, 'applied');
$attention($f1, 'additional_payment_due');
$f1Sent = $ok($send($syncer, $f1), 'S6b decided: the request is created (an amount still due is not an open entry)');
$check($f1Sent['state'] === 'awaiting_pickup', 'S6b awaiting pickup');
$f2 = $woo('trendhome', 'fin2');
$ok($stop($syncer, $f2), 'S6b second order stop');
$ok($send($syncer, $f2), 'S6b second order handed over');
// A transfer is recorded in the shop after the hand-over: the operation is not handed to the shop until it is decided.
$attention($f1, 'transfer_pending');
$ids = array_column($ok($pull('trendhome'), 'S6b pull while the transfer is pending')['operations'], 'operationId');
$check(!in_array($op($f1)['operation_uuid'], $ids, true) && in_array($op($f2)['operation_uuid'], $ids, true), 'S6b the open order is held back, the other order is handed out');
$check($op($f1)['state'] === 'awaiting_pickup' && $op($f1)['last_pulled_at'] === null && !in_array('pickup:picked_up', $trail($op($f1)), true), 'S6b nothing was recorded for the held-back operation');
$check(!in_array($op($f1)['operation_uuid'], array_column($ok($pull('trendhome'), 'S6b repeated pull')['operations'], 'operationId'), true), 'S6b a repeated pull still holds it back');
$attention($f1, null);
$check(in_array($op($f1)['operation_uuid'], array_column($ok($pull('trendhome'), 'S6b pull after the decision')['operations'], 'operationId'), true) && in_array('pickup:picked_up', $trail($op($f1)), true), 'S6b decided: handed out and recorded');
$ok($report('trendhome', $op($f1), 'rejected_status', 'on-hold'), 'S6b the shop refuses');
$attention($f1, 'refund_pending');
$error($opAction($syncer, $op($f1), 'retry'), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'S6b no retry while a refund is pending');
$check($op($f1)['state'] === 'rejected', 'S6b the refused retry changed nothing');
$attention($f1, null);
$check($ok($opAction($syncer, $op($f1), 'retry'), 'S6b retry after the decision')['state'] === 'awaiting_pickup', 'S6b retried once the entry is decided');

// ---- S6c. Owner rule 2: a request held back for an open finance entry, the 24 hour pickup timeout and its recovery ------
// The request is never lost: the operation stays (awaiting_pickup, then manual_review PICKUP_TIMEOUT), the stop stays held
// and source_request_status stays pending. A person retries once the entry is decided; the same operation is handed out
// again, exactly once, and no second stop, operation or cancellation event appears.
$f3 = $woo('trendhome', 'fin3');
$ok($stop($syncer, $f3), 'S6c stop');
$ok($send($syncer, $f3), 'S6c handed over');
$attention($f3, 'transfer_pending');
$check(!in_array($op($f3)['operation_uuid'], array_column($ok($pull('trendhome'), 'S6c pull while the transfer is pending')['operations'], 'operationId'), true), 'S6c held back');
$events3 = $eventTypes($f3);
$finance3 = (int) $one('SELECT COUNT(*) FROM order_finance_actions WHERE order_uuid = ?', [$f3['uuid']]);
$run(25 * 3600);
$timedOut = $op($f3);
$check($timedOut['state'] === 'manual_review' && $timedOut['last_error_code'] === 'PICKUP_TIMEOUT' && $timedOut['last_pulled_at'] === null, 'S6c 24 hours without pickup: manual review PICKUP_TIMEOUT, never handed out');
$check(count($ops($f3)) === 1 && $control($f3)['hold_status'] === 'held' && $control($f3)['source_request_status'] === 'pending', 'S6c the request is not lost: one operation, stop held, request pending');
$check(!in_array($timedOut['operation_uuid'], array_column($ok($pull('trendhome'), 'S6c pull after the timeout')['operations'], 'operationId'), true), 'S6c a timed-out operation is not handed out by itself');
$error($opAction($syncer, $op($f3), 'retry'), 409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'S6c no retry while the transfer is pending');
$check($op($f3)['state'] === 'manual_review' && $op($f3)['version'] === $timedOut['version'], 'S6c the refused retry changed nothing');
$error($release($releaser, $f3), 409, 'RELEASE_SYNC_OPEN', 'S6c the open request still blocks a release');
// The transfer is decided in the shop (verified or rejected): the attention clears with the next signed order event.
$attention($f3, null);
$retried3 = $ok($opAction($syncer, $op($f3), 'retry'), 'S6c retry after the decision');
$check($retried3['state'] === 'awaiting_pickup' && $retried3['id'] === $timedOut['operation_uuid'], 'S6c the same operation is handed over again');
$picked3 = array_column($ok($pull('trendhome'), 'S6c pull after the retry')['operations'], 'operationId');
$check(count(array_keys($picked3, $timedOut['operation_uuid'], true)) === 1, 'S6c handed out once in the pull');
// Delivery to the shop is at least once (a repeated pull offers the same operation id until the report); the pickup is
// recorded once and a repeated report changes nothing.
$ok($pull('trendhome'), 'S6c repeated pull');
$check(count(array_keys($trail($op($f3)), 'pickup:picked_up', true)) === 1, 'S6c one pickup recorded (the held-back hand-over was never picked up)');
$ok($report('trendhome', $op($f3), 'cancelled', 'cancelled'), 'S6c the shop cancels');
$check($ok($report('trendhome', $op($f3), 'cancelled', 'cancelled'), 'S6c the same report again')['state'] === 'awaiting_confirmation' && in_array('report:duplicate_report', $trail($op($f3)), true), 'S6c a repeated report changes nothing');
$ok($wooEvent($f3, 'cancelled', 'cancelled'), 'S6c the shop event arrives');
$check($op($f3)['state'] === 'confirmed' && count($ops($f3)) === 1, 'S6c confirmed, still one operation');
$after3 = $eventTypes($f3);
$check(count(array_keys($after3, 'hold_applied', true)) === 1 && count(array_keys($events3, 'hold_applied', true)) === 1, 'S6c one stop for the whole history');
$check((int) $one('SELECT COUNT(*) FROM order_finance_actions WHERE order_uuid = ?', [$f3['uuid']]) === $finance3, 'S6c the cancellation created no finance action');

// ---- S7. Authorization, idempotency, versions --------------------------------------------------------------------
$a = $woo('trendhome', 'auth');
$ok($stop($keeper, $a), 'S7 stop by a keeper');
$error($send($keeper, $a), 403, 'UNAUTHORIZED_ACTION', 'S7 the stop permission is not the sync permission');
$error($send($manager, $a), 403, 'UNAUTHORIZED_ACTION', 'S7 an operations manager without the permission is refused');
$error($post($worker, $cancellationPath($a) . '/sync', ['expectedVersion' => 1]), 403, 'APPLICATION_ACCESS_DENIED', 'S7 a Staff worker is refused');
$noCsrf = T::call($kernel, 'POST', $cancellationPath($a) . '/sync', ['expectedVersion' => 1], ['origin' => DASHBOARD, 'idempotency-key' => $key('csrf')], $syncer['cookie']);
$check($noCsrf['status'] === 403, 'S7 CSRF is required');
$error($send($syncer, $a, ['expectedVersion' => 99]), 409, 'CANCELLATION_CHANGED', 'S7 a stale stop version is refused');
$error($send($syncer, $a, [], 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'S7 an idempotency key is required');
$idem = $key('send');
$firstSend = $ok($send($syncer, $a, [], $idem), 'S7 send');
$check($ok($send($syncer, $a, [], $idem), 'S7 replay') == $firstSend && count($ops($a)) === 1, 'S7 a replay returns the committed result, one operation');
$error($send($syncer, $a, ['note' => 'alt corp'], $idem), 409, 'IDEMPOTENCY_CONFLICT', 'S7 the same key with another body is refused');
$error($send($syncer, $a), 409, 'CANCELLATION_SYNC_EXISTS', 'S7 a second send for the same stop is refused');
$error($opAction($syncer, $op($a), 'retry', ['expectedVersion' => 99]), 409, 'CANCELLATION_SYNC_CHANGED', 'S7 a stale operation version is refused');
$error($opAction($keeper, $op($a), 'withdraw', ['reason' => 'Fără drept de sincronizare.']), 403, 'UNAUTHORIZED_ACTION', 'S7 withdraw needs the permission');
$error($dash($syncer, 'POST', '/management/cancellation-sync/operations/00000000-0000-4000-8000-000000000000/retry', ['expectedVersion' => 1]), 404, 'CANCELLATION_SYNC_NOT_FOUND', 'S7 unknown operation');
// A role removed while the screen was open decides nothing.
$a2 = $woo('trendhome', 'auth2');
$ok($stop($syncer, $a2), 'S7 stop 2');
$ok($dash($root, 'PUT', "/management/employees/{$syncer2['employeeUuid']}/roles", ['roleIds' => []]), 'S7 the second manager loses the role');
$error($send($syncer2, $a2), 403, 'UNAUTHORIZED_ACTION', 'S7 revoked authority is refused');
$check($op($a2) === null, 'S7 nothing was created');
$check(($view($releaser, $a2)['capabilities']['sync'] ?? null) === false && $view($syncer, $a2)['capabilities']['sync'] === true, 'S7 capabilities follow the permission');

// ---- S8. Release while open, generations ---------------------------------------------------------------------------
$gen = $woo('trendhome', 'gen');
$ok($stop($syncer, $gen), 'S8 stop');
$ok($send($syncer, $gen), 'S8 handed over');
$check($view($releaser, $gen)['cancellation']['release']['refusal'] === 'sync_open', 'S8 the Dashboard says why a release is impossible');
$error($release($releaser, $gen), 409, 'RELEASE_SYNC_OPEN', 'S8 no release while the request is open');
$check($eventTypes($gen)[count($eventTypes($gen)) - 1] === 'release_refused', 'S8 the refusal is audited');
$ok($opAction($syncer, $op($gen), 'withdraw', ['reason' => 'Clientul păstrează comanda.']), 'S8 withdrawn');
$ok($release($releaser, $gen), 'S8 released after the withdrawal');
$check($state($gen)['cancellation'] === 'released' && (int) $control($gen)['hold_generation'] === 1, 'S8 released');
$ok($stop($syncer, $gen), 'S8 stopped again');
$check((int) $control($gen)['hold_generation'] === 2 && $control($gen)['source_request_status'] === 'pending', 'S8 a new stop is a new generation');
$check($view($syncer, $gen)['cancellation']['sync']['operation'] === null && $view($syncer, $gen)['cancellation']['sync']['actions']['send'] === true, 'S8 the old operation does not belong to the new stop');
$second = $ok($send($syncer, $gen), 'S8 sent for generation 2');
$check($second['generation'] === 2 && count($ops($gen)) === 2 && $ops($gen)[0]['state'] === 'withdrawn', 'S8 one operation per generation, the old one untouched');
$foreign = $ok($pull('outletperdele'), 'S8 the other site pulls with its own gates open');
$check($foreign['enabled'] === true && !in_array($second['id'], array_column($foreign['operations'], 'operationId'), true) && $op($gen)['last_pulled_at'] === null, 'S8 another site never receives (or marks picked up) this site\'s request');
$pulledIds = array_column($ok($pull('trendhome'), 'S8 pull')['operations'], 'operationId');
$check(in_array($second['id'], $pulledIds, true) && !in_array($ops($gen)[0]['operation_uuid'], $pulledIds, true), 'S8 only the current generation is handed over');
// A reactivation at the source never releases, and never withdraws a request by itself.
$ok($wooEvent($gen, 'cancelled', 'cancelled'), 'S8 cancelled at the shop');
$ok($wooEvent($gen, 'active', 'processing'), 'S8 reactivated at the shop');
$check($state($gen)['cancellation'] === 'held' && $op($gen)['state'] === 'confirmed', 'S8 the reactivation keeps the stop; the confirmation stands');

// ---- S9. 3 sources x 14 stages ----------------------------------------------------------------------------------------
$flag('trendyol', true);
$flag('trendhome', true);
$flag('outletperdele', true);
$matrix = 0;
foreach (['trendhome', 'outletperdele', 'trendyol'] as $source) {
    foreach ($canonical as $ordinal => $stage) {
        $tag = 'm' . substr($source, 0, 2) . ($ordinal + 1);
        $o = $source === 'trendyol' ? $trendyol($tag) : $woo($source, $tag, $stage);
        $bring($o, $stage, $stage !== 'waiting');
        $label = "S9 {$source} {$stage}";
        $before = $state($o);
        $historyBefore = $history($o);
        $qrBefore = $qrRows($o);
        $ok($stop($syncer, $o), "{$label} stop");
        $stopVersion = (int) $control($o)['version'];
        $ok($send($syncer, $o, $source === 'trendyol' ? ['reasonCode' => 506] : []), "{$label} sent");
        if ($source === 'trendyol') {
            $run(0);
            $check(count($puts($o['package'])) === 1, "{$label}: one notice");
            $run(200);
            $marketplace($o);
        } else {
            $ok($pull($source), "{$label} pull");
            $ok($report($source, $op($o), 'cancelled'), "{$label} report");
            $ok($wooEvent($o, 'cancelled', 'cancelled'), "{$label} shop event");
        }
        $after = $state($o);
        $check($op($o)['state'] === 'confirmed', "{$label}: confirmed " . $op($o)['state']);
        $check($after['stage'] === $stage && $after['owner'] === $before['owner'] && (int) $after['version'] === (int) $before['version'] && $after['completed'] === null, "{$label}: stage, owner and production version untouched");
        $check($history($o) === $historyBefore && $qrRows($o) === $qrBefore && $after['document'] === $before['document'], "{$label}: history, QR and document untouched");
        $check($after['cancellation'] === 'held' && $after['availability'] === 'unavailable' && $control($o)['source_request_status'] === 'confirmed' && (int) $control($o)['version'] > $stopVersion, "{$label}: stopped, cancelled at the source, request confirmed");
        if ($before['owner'] !== null) {
            $check($stopped($post($stageWorker($stage), $o['path'] . '/transition', ['expectedVersion' => (int) $after['version']])), "{$label}: the owner cannot continue");
            $check(($staffView($stageWorker($stage), $o)['productionStop']['sync'] ?? null) === 'confirmed', "{$label}: Staff shows the confirmed cancellation");
        }
        $matrix++;
    }
}
$check($matrix === 42 && $stagesNow() === $canonical, 'S9 42 positions; the workflow is unchanged');
// Completed production, then a cancellation request: the completion is kept, nothing reopens.
$done = $woo('trendhome', 'done', 'delivery');
$bring($done, 'delivery');
$advance($worker, $done);
$completedAt = $state($done)['completed'];
$ok($stop($syncer, $done), 'S9 stop after completion');
$ok($send($syncer, $done), 'S9 sent after completion');
$ok($pull('trendhome'), 'S9 completed pull');
$ok($report('trendhome', $op($done), 'rejected_status', 'completed'), 'S9 the shop refuses (already completed)');
$check($op($done)['state'] === 'rejected' && $state($done)['completed'] === $completedAt && $state($done)['stage'] === 'delivery', 'S9 refused; completion kept, no backward transition');

// ---- S10. Reconciliation ---------------------------------------------------------------------------------------------
$discrepancies = static fn (): int => (int) $one('SELECT COUNT(*) FROM order_cancellation_discrepancies');
$before = $discrepancies();
$dry = $ok($dash($syncer, 'POST', '/management/cancellation-sync/reconcile', []), 'S10 dry run');
$check($dry['dryRun'] === true && $discrepancies() === $before, 'S10 a dry run writes nothing');
// Only this suite's orders (a shared CI database also holds other suites' stops).
$kinds = array_count_values(array_column(array_filter($dry['findings'], static fn (array $f): bool => str_contains((string) $f['orderNumber'], $suffix)), 'kind'));
$check(($kinds['operation_manual_review'] ?? 0) >= 2 && ($kinds['operation_refused_still_stopped'] ?? 0) >= 2 && ($kinds['local_stop_without_request'] ?? 0) === 0, 'S10 findings: ' . json_encode($kinds));
$check(!str_contains(json_encode($dry), 'Draperie') && !str_contains(json_encode($dry), 'fixture'), 'S10 no customer data or credentials in findings');
$error($dash($keeper, 'POST', '/management/cancellation-sync/reconcile', []), 403, 'UNAUTHORIZED_ACTION', 'S10 a dry run needs the sync permission');
// An inconsistency only a reconciliation fixes: the source cancellation is stored but the operation is still open.
$inc = $woo('trendhome', 'inc');
$ok($stop($syncer, $inc), 'S10 stop');
$ok($send($syncer, $inc), 'S10 handed over');
$pdo->prepare("UPDATE order_cancellation_controls SET source_status = 'cancelled' WHERE order_uuid = ?")->execute([$inc['uuid']]); // test fixture of an earlier inconsistency
$reconciler = $container->cancellationReconciler();
$applied = $reconciler->apply('test-operator');
$check($applied['autoConfirmed'] >= 1 && $op($inc)['state'] === 'confirmed' && $op($inc)['confirmed_by'] === 'inbound_event' && in_array('inbound:reconciled', $trail($op($inc)), true), 'S10 apply confirms from the stored source evidence');
$recorded = $discrepancies();
$check($recorded > 0 && (int) $one("SELECT COUNT(*) FROM order_cancellation_discrepancies WHERE state = 'open' AND kind = 'source_cancelled_operation_open'") === 0, 'S10 findings recorded; the auto-confirmed one is not open');
$reconciler->apply('test-operator');
$check($discrepancies() === $recorded, 'S10 a second apply duplicates nothing');
$manual = $op($budget);
$ok($opAction($syncer, $manual, 'close', ['note' => 'Verificat manual în Trendyol Seller Center.']), 'S10 a person closes the exhausted one');
$reconciler->apply('test-operator');
$check((int) $one("SELECT COUNT(*) FROM order_cancellation_discrepancies WHERE operation_uuid = ? AND state = 'resolved'", [$manual['operation_uuid']]) === 1, 'S10 a fixed finding is resolved');
$overview = $ok($dashGet($syncer, '/management/cancellation-sync'), 'S10 overview');
$check($overview['ready'] === true && count($overview['providers']) === 4 && isset($overview['operations']['trendyol']['confirmed']) && $overview['discrepancies'] !== [], 'S10 overview: providers, states, open findings');
// Trendyol Fresh Start boundary: an order from before the intake baseline is never sent and is marked in findings.
$baselineMs = (int) floor(microtime(true) * 1000) - 3_600_000;
$pdo->prepare("UPDATE trendyol_intake_state SET baseline_at = ?, status = 'paused' WHERE state_id = 1")->execute([gmdate('Y-m-d H:i:s', intdiv($baselineMs, 1000))]);
$old = $trendyol('old', $baselineMs - 86_400_000);
$ok($stop($syncer, $old), 'S10 pre-baseline stop');
$error($send($syncer, $old, ['reasonCode' => 500]), 409, 'TRENDYOL_BEFORE_BASELINE', 'S10 a pre-baseline Trendyol order is never re-cancelled');
$fresh = $trendyol('fresh', $baselineMs + 60_000);
$ok($stop($syncer, $fresh), 'S10 post-baseline stop');
$ok($send($syncer, $fresh, ['reasonCode' => 500]), 'S10 a post-baseline order can be sent');
$findings = $reconciler->scan();
$oldFinding = array_values(array_filter($findings, static fn (array $f): bool => $f['orderUuid'] === $old['uuid']));
$check($oldFinding !== [] && $oldFinding[0]['kind'] === 'request_without_operation' && $oldFinding[0]['beforeBaseline'] === true, 'S10 the pre-baseline order is listed, marked before the baseline');
$pdo->exec("UPDATE trendyol_intake_state SET status = 'inactive', baseline_at = NULL WHERE state_id = 1");

// ---- S11. Operator CLI and rollback preflight --------------------------------------------------------------------------
$env = ['HOME' => (string) getenv('HOME'), 'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => $config->dbHost, 'ARASYA_DB_PORT' => (string) $config->dbPort, 'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => $config->dbUser, 'ARASYA_DB_PASSWORD' => $config->dbPassword];
$cli = static function (string $script, string ...$arguments) use ($env): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'json' => json_decode($out, true), 'out' => $out, 'err' => $err];
};
$status = $cli('cancellation-sync.php', 'status');
$check($status['code'] === 0 && $status['json']['serverSwitch'] === 'disabled' && count($status['json']['providers']) === 4, 'S11 status (the CLI environment has the switch off) ' . $status['err']);
$runCli = $cli('cancellation-sync.php', 'run');
$check($runCli['code'] === 0 && $runCli['json']['processed'] === 0, 'S11 run with the switch off contacts nothing');
$check($cli('cancellation-sync.php', 'enable', '--source=trendyol', '--operator=Test Operator', '--reason=Activare aprobată de proprietar.', '--confirm=yes')['code'] === 2, 'S11 enable without the phrase is refused');
$check($cli('cancellation-sync.php', 'enable', '--source=b2b', '--operator=Test Operator', '--reason=Activare aprobată de proprietar.', '--confirm=ENABLE-CANCELLATION-SYNC')['code'] === 2, 'S11 B2B has no flag');
$trendyolFlag = (int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'cancellation_sync_trendyol'");
$unconfigured = $cli('cancellation-sync.php', 'enable', '--source=trendyol', '--operator=Test Operator', '--reason=Activare aprobată de proprietar.', '--confirm=ENABLE-CANCELLATION-SYNC');
$check($unconfigured['code'] === 2 && str_contains($unconfigured['err'], 'ARASYA_TRENDYOL_STORE_FRONT_CODE') && (int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'cancellation_sync_trendyol'") === $trendyolFlag,
    'S11 Trendyol cannot be enabled without credentials and a storefront code; the flag is unchanged');
$malformed = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/cancellation-sync.php', 'status'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $mPipes, null, $env + ['ARASYA_TRENDYOL_STORE_FRONT_CODE' => 'ro-guess1']);
fclose($mPipes[0]);
$mOut = (string) stream_get_contents($mPipes[1]) . (string) stream_get_contents($mPipes[2]);
fclose($mPipes[1]);
fclose($mPipes[2]);
$check(proc_close($malformed) === 1 && !str_contains($mOut, 'ro-guess1') && str_contains($mOut, 'Refused: ARASYA_TRENDYOL_STORE_FRONT_CODE must be 2-5 capital letters.')
    && !str_contains($mOut, 'Stack trace') && !str_contains($mOut, substr((string) $config->dbPassword, 0, 8)), 'S11 a malformed storefront code stops the CLI with the variable name only: no value, no trace, no secret prefix ' . $mOut);
$check(($status['json']['providers'][array_search('trendyol', array_column($status['json']['providers'], 'source'), true)]['configured'] ?? null) === false, 'S11 status shows Trendyol as not configured');
$flag('trendhome', false);
$enabledEvents = (int) $one("SELECT COUNT(*) FROM production_feature_flag_events WHERE feature_key = 'cancellation_sync_trendhome' AND action = 'enabled'");
$enabled = $cli('cancellation-sync.php', 'enable', '--source=trendhome', '--operator=Test Operator', '--reason=Activare aprobată de proprietar.', '--confirm=ENABLE-CANCELLATION-SYNC');
$check($enabled['code'] === 0 && $enabled['json']['changed'] === true && $enabled['json']['active'] === false && (int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'cancellation_sync_trendhome'") === 1
    && (int) $one("SELECT COUNT(*) FROM production_feature_flag_events WHERE feature_key = 'cancellation_sync_trendhome' AND action = 'enabled'") === $enabledEvents + 1, 'S11 enabled with an audit row; still inactive without the server switch');
$disabled = $cli('cancellation-sync.php', 'disable', '--source=trendhome', '--operator=Test Operator', '--reason=Oprire de test după verificare.');
$check($disabled['code'] === 0 && (int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'cancellation_sync_trendhome'") === 0, 'S11 disabled');
$dryCli = $cli('cancellation-sync.php', 'reconcile', '--dry-run=1');
$check($dryCli['code'] === 0 && $dryCli['json']['dryRun'] === true, 'S11 reconcile dry run');
$check($cli('cancellation-sync.php', 'reconcile', '--dry-run=0')['code'] === 2, 'S11 recording needs an operator');
$rollback = $cli('check-rollback.php', '2.35.0');
$check($rollback['code'] === 3 && $rollback['json']['requiredFloor'] === '2.36.0' && $rollback['json']['state']['openCancellationSyncOperations'] > 0, 'S11 rollback below 2.36.0 is refused while an operation is open');
$check($cli('check-rollback.php', '2.36.0')['code'] === 0, 'S11 the current API is a safe target');

// ---- S12. Mutation scope and secrets ----------------------------------------------------------------------------------
$writes = [];
foreach (glob(dirname(__DIR__) . '/src/Cancellation/Sync/*.php') ?: [] as $file) {
    preg_match_all('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+([a-z_]+)/i', (string) file_get_contents($file), $m);
    foreach ($m[1] as $table) $writes[strtolower($table)] = true;
}
ksort($writes);
$check(array_keys($writes) === ['order_cancellation_discrepancies', 'order_cancellation_sync_attempts', 'order_cancellation_sync_operations'], 'S12 the synchronization writes only its own tables (the stop itself goes through CancellationControl): ' . json_encode(array_keys($writes)));
$stored = json_encode($rows('SELECT * FROM order_cancellation_sync_attempts')) . json_encode($rows('SELECT * FROM order_cancellation_sync_operations')) . json_encode($rows('SELECT * FROM order_cancellation_discrepancies'));
$check(!str_contains($stored, base64_encode('fixture:fixture')) && !str_contains($stored, 'Authorization') && !str_contains($stored, 'Draperie'), 'S12 no credentials or product/customer data are stored');
$check((int) $one("SELECT COUNT(*) FROM order_cancellation_sync_operations WHERE state = 'confirmed' AND confirmed_by IS NULL") === 0, 'S12 every confirmation names its evidence');
$check((int) $one('SELECT COUNT(*) FROM (SELECT order_uuid FROM order_cancellation_sync_operations WHERE open_order_uuid IS NOT NULL GROUP BY order_uuid HAVING COUNT(*) > 1) x') === 0, 'S12 at most one open operation per order');
$check((int) $one("SELECT COUNT(*) FROM order_cancellation_sync_operations x JOIN operational_orders o ON o.order_uuid = x.order_uuid WHERE x.open_order_uuid IS NOT NULL AND o.cancellation_state <> 'held'") === 0, 'S12 no released order has an open operation');

fwrite(STDOUT, "PASS MySQL cancellation synchronization integration ({$checks} checks)\n");
