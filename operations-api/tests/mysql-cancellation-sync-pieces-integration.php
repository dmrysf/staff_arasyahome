<?php

declare(strict_types=1);

// Cancellation synchronization (migration 034, API 2.36.0) on multi-piece orders, against a dedicated MySQL/MariaDB test
// database, end to end through the HTTP kernel, the signed source routes, the Trendyol fixture transport (never the real
// API) and the outbound worker with an injected clock. Every order is synthetic. The assertion that matters is on rows:
// every piece, track, defect case, label, plan, custody, checkpoint, history and QR row of the order is byte-identical
// before and after every synchronization step, and only an authorized release could ever let production continue.
//
//   P1  DRAPERII, two pieces: piece A defective (case awaiting the manager's decision), piece B at ironing; Trendyol notice
//       with an unknown outcome (partial physical completion + uncertain), observed, manual review, then the marketplace
//       confirms; the defect decision stays refused while stopped
//   P2  PERDEA + DRAPERII under piece routing: the order at stage 6 (bottom hem), the PERDEA piece on its own track at
//       ironing, claimed; Trendhome pull, report, inbound cancelled event; the PERDEA 6/7 checkpoint rule unchanged
//   P3  two DRAPERII pieces in packing (stage 13); OutletPerdele refuses a paid order: manual review, release refused
//   P4  DRAPERII, two pieces, one complete through quality control, the other back in rework at cutting; Trendyol
//       notice accepted and confirmed by a status read; partial completion never becomes order completion

use Arasya\Operations\Application\Container;
use Arasya\Operations\Cancellation\Sync\CancellationSyncWorker;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Production\FeatureActivation;
use Arasya\Operations\Production\WorkUnits;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL cancellation synchronization pieces integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
const DASHBOARD = 'http://127.0.0.1:4174';
$suffix = bin2hex(random_bytes(4));
$fixtureFile = sys_get_temp_dir() . "/arasya-cancellation-sync-pieces-{$suffix}.json";
file_put_contents($fixtureFile, json_encode(['packages' => [], 'putCalls' => []], JSON_THROW_ON_ERROR));
register_shutdown_function(static fn () => @unlink($fixtureFile));
$baseConfig = T::config($dbName, [T::ORIGIN, DASHBOARD]);
$config = new Config(...[...(array) $baseConfig, 'trendyolFixtureFile' => $fixtureFile, 'cancellationSync' => 'enabled']);
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
$clock = $container->clock();
$password = 'cancellation pieces passphrase 2026';
$rootName = "syncpc.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('test-sync-pieces-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Sync pieces root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Sync pieces root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('sp-%s-%s-%05d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'sp-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'sp-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');

// ---- Identities -------------------------------------------------------------------------------------------------------
$tailoringStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = []) use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-sync-pieces');
    $uuids[$name] = $employee->employeeUuid;
    $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid, 'name' => $display];
};
$cutter = $identity('cutter', 'Pieces Cutter', ['material-preparation']);
$tailor = $identity('tailor', 'Pieces Tailor', $tailoringStages);
$tailor2 = $identity('tailor2', 'Pieces Second Tailor', $tailoringStages);
$finisher = $identity('finisher', 'Pieces Finisher', ['height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery']);
$supervisor = $identity('supervisor', 'Pieces Supervisor', $tailoringStages);
$manager = $identity('manager', 'Pieces Operations Manager', [], ['dashboard'], [$roleIds['operations-manager']]);
$ceo = $identity('ceo', 'Pieces CEO', [], ['dashboard'], [$roleIds['ceo']]);
$ok($dash($root, 'PUT', '/management/organization/ceo', ['employeeId' => $uuids['ceo']]), 'root designates the CEO');
$ok($dash($ceo, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_quality_supervisor', 'employeeId' => $uuids['supervisor']]), 'the CEO appoints the supervisor', 201);
$syncRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Sincronizare anulare test pc {$suffix}", 'description' => null, 'authorityRank' => 200,
    'permissions' => ['orders.view_all', 'production.cancellation.manage', 'production.cancellation.sync']]), 'sync role', 201)['role']['id'];
$releaseRole = (int) $ok($dash($root, 'POST', '/management/roles', ['name' => "Reluare test pc {$suffix}", 'description' => null, 'authorityRank' => 200,
    'permissions' => ['orders.view_all', 'production.cancellation.release']]), 'release role', 201)['role']['id'];
$syncer = $identity('syncer', 'Pieces Sync Manager', [], ['dashboard'], [$syncRole]);
$releaser = $identity('releaser', 'Pieces Releaser', [], ['dashboard'], [$releaseRole]);

// ---- Fixture, clock, worker -------------------------------------------------------------------------------------------
$fx = static fn (): array => json_decode((string) file_get_contents($fixtureFile), true, 64, JSON_THROW_ON_ERROR);
$fxWrite = static fn (array $fixture): int => (int) file_put_contents($fixtureFile, json_encode($fixture, JSON_THROW_ON_ERROR));
$fxPut = static function (?array $script) use ($fx, $fxWrite): void { $f = $fx(); unset($f['put']); if ($script !== null) $f['put'] = $script; $fxWrite($f); };
$fxPackage = static function (int $packageId) use ($fx): ?array { foreach ($fx()['packages'] as $p) if ($p['shipmentPackageId'] === $packageId) return $p; return null; };
$fxStatus = static function (int $packageId, string $status) use ($fx, $fxWrite): void {
    $f = $fx();
    foreach ($f['packages'] as $i => $p) if ($p['shipmentPackageId'] === $packageId) { $f['packages'][$i]['status'] = $status; $f['packages'][$i]['lastModifiedDate'] += 1000; }
    $fxWrite($f);
};
$puts = static fn (int $packageId): array => array_values(array_filter($fx()['putCalls'] ?? [], static fn (array $c): bool => str_contains($c['url'], "/shipment-packages/{$packageId}/")));
$wclock = new class implements Clock {
    public int $offset = 0;
    public function now(): DateTimeImmutable { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$this->offset} seconds"); }
};
$run = static function (int $offset = 0) use ($wclock, $pdo, $container): array {
    $wclock->offset = $offset;
    return (new CancellationSyncWorker($pdo, $wclock, $container->cancellationProviders()))->run(100);
};
foreach (['cancellation_sync_trendyol', 'cancellation_sync_trendhome', 'cancellation_sync_outletperdele'] as $flagKey) {
    $pdo->prepare('UPDATE production_feature_flags SET enabled = 1 WHERE flag_key = ?')->execute([$flagKey]);
}
$pdo->beginTransaction();
$check((new WorkUnits($pdo))->setPartialRework(true, 'sync pieces suite', gmdate('Y-m-d H:i:s.000000'))['enabled'] ?? true, 'per-piece rework enabled in this isolated database');
$pdo->commit();
$features = new FeatureActivation($pdo);

// ---- Orders -----------------------------------------------------------------------------------------------------------
$line = static fn (string $name, string $code, int $quantity, string $kind = 'drapery'): array => ['name' => $name, 'code' => $code, 'color' => 'Crem', 'width' => 250.0, 'height' => 260.0, 'meters' => null, 'quantity' => $quantity, 'kind' => $kind];
$orderInfo = static function (string $uuid) use ($one, $rows): array {
    $id = (string) $one('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?', [$uuid]);
    return ['id' => $id, 'uuid' => $uuid, 'path' => '/orders/' . rawurlencode($id), 'source' => (string) $one('SELECT source_key FROM operational_orders WHERE order_uuid = ?', [$uuid]),
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]),
        'items' => array_column($rows('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number', [$uuid]), 'item_uuid')];
};
// A released Trendyol package (intake row + fixture package with one line per order line and its full quantity).
$trendyol = static function (string $tag, array $lines) use ($container, $pdo, $orderInfo, $suffix, $rootUuid, $fx, $fxWrite): array {
    $number = "118PC{$tag}{$suffix}";
    $items = [];
    foreach ($lines as $i => $l) {
        $items[] = new OperationalOrderItem(Uuid::v4(), "pc-{$tag}-{$i}", $i + 1, $l['name'], $l['code'], null, $l['color'], $l['width'], $l['height'], 'cm', $l['meters'], $l['quantity'],
            ['kind' => $l['kind'], 'notes' => null, 'productionNotes' => null, 'marketplaceTitle' => $l['name'], 'barcode' => null]);
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo->beginTransaction();
    $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-pc{$tag}-{$suffix}", "approval-pc{$tag}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now, $items), []);
    $packageId = random_int(10_000_000, 99_999_999);
    $modified = (int) floor(microtime(true) * 1000) - 600_000;
    $package = ['shipmentPackageId' => $packageId, 'orderNumber' => $number, 'lastModifiedDate' => $modified, 'status' => 'Created', 'orderDate' => $modified - 1000,
        'lines' => array_map(static fn (int $i, array $l): array => ['lineId' => $packageId * 10 + $i + 1, 'productName' => $l['name'], 'quantity' => $l['quantity']], array_keys($lines), $lines)];
    $pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, lines_hash, version, first_seen_at, last_seen_at, released_order_uuid, released_by_employee_uuid, released_at)
        VALUES (?, ?, 'released', 'Created', ?, ?, ?, 1, ?, ?, ?, ?, ?)")->execute([$packageId, $number, $modified, $package['orderDate'], TrendyolPackage::fromApi($package)->linesHash(), $now->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), $uuid, $rootUuid, $now->format('Y-m-d H:i:s.u')]);
    $pdo->commit();
    $f = $fx();
    $f['packages'][] = $package;
    $fxWrite($f);
    return $orderInfo($uuid) + ['package' => $packageId, 'pkg' => $package];
};
$wooLines = [];
$woo = static function (string $source, string $tag, array $lines) use ($suffix, $kernel, $ok, $one, $orderInfo, &$wooLines): array {
    $number = "SP{$tag}{$suffix}";
    $payload = [];
    foreach ($lines as $i => $l) {
        $payload[] = ['id' => $i + 1, 'line' => $i + 1, 'name' => $l['name'], 'sku' => $l['code'], 'color' => $l['color'], 'width' => $l['width'], 'height' => $l['height'], 'unit' => 'cm', 'meters' => $l['meters'], 'quantity' => $l['quantity']];
    }
    $wooLines["{$source}:{$number}"] = $payload;
    $ok(T::ingest($kernel, $source, T::sourceOrder($number, "evt-sp-{$tag}-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 300), T::stage('material-preparation'), 'processing', 'active', $payload, $number)), "ingest {$tag}");
    return $orderInfo((string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', ["{$source}:{$number}"])) + ['number' => $number];
};
$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, open_exception_uuid AS exception, production_completed_at AS completed, cancellation_state AS cancellation FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$claim = static function (array $who, array $o) use ($post, $ok, $state, $pdo, $key): void {
    $ok($post($who, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $who['employeeUuid'], (int) $state($o)['version']), $key('claim')), "claim {$o['id']} at " . $state($o)['stage']);
};
$advance = static function (array $who, array $o) use ($post, $ok, $state, $key): void {
    $ok($post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']], $key('move')), "transition {$o['id']} at " . $state($o)['stage']);
};
$stageWorker = static fn (string $stage): array => match (true) { $stage === 'material-preparation' => $cutter, in_array($stage, ['height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'], true) => $finisher, default => $tailor };
$bring = static function (array $o, string $target) use ($post, $ok, $key, $claim, $advance, $state, $check, $stageWorker): void {
    for ($guard = 0; ; $guard++) {
        $current = $state($o);
        if ($current['stage'] === $target) {
            if ($current['owner'] === null) $claim($stageWorker($target), $o);
            return;
        }
        if ($current['stage'] === 'waiting') {
            $ok($post($stageWorker('material-preparation'), '/cutting/intake', ['qrToken' => $o['qr']], $key('intake')), "cutting intake {$o['id']}");
        } else {
            if ($current['owner'] === null) $claim($stageWorker($current['stage']), $o);
            $advance($stageWorker($current['stage']), $o);
        }
        $check($guard < 20, 'bring terminates');
    }
};
$confirmPlan = static function (array $o, array $types) use ($dash, $dashGet, $manager, $ok): void {
    $plan = $ok($dashGet($manager, $o['path'] . '/manufacturing-plan'), 'plan view');
    foreach ($plan['lines'] as $index => $planLine) {
        $ok($dash($manager, 'POST', $o['path'] . '/manufacturing-plan/lines/' . $planLine['itemId'] . '/confirm', ['expectedSourceHash' => $planLine['sourcePlanHash'], 'expectedRevision' => $planLine['plan']['revision'],
            'productType' => $types[$index], 'pieceCount' => $planLine['commercialQuantity'], 'note' => 'Plan verificat pentru testul de sincronizare']), 'plan confirmed');
    }
};
$exVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$exStatus = static fn (string $id): string => (string) $one('SELECT status FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$reportUnits = static fn (array $who, array $o, array $units, string $reason) => $post($who, $o['path'] . '/fault-reports', ['expectedVersion' => (int) $state($o)['version'], 'units' => $units, 'reasonKey' => $reason, 'comment' => 'Defect pe o singură bucată']);
$review = static fn (array $who, string $id, array $body) => $post($who, "/production-exceptions/{$id}/review", ['expectedVersion' => $exVersion($id)] + $body);
$sendReturn = static fn (array $who, string $id) => $post($who, "/production-exceptions/{$id}/return", ['expectedVersion' => $exVersion($id), 'comment' => 'Se retaie bucata defectă']);
$ack = static fn (array $who, string $id, string $qr) => $post($who, "/production-exceptions/{$id}/acknowledge", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr, 'responsibility' => 'accepted']);
$decide = static fn (array $who, string $id, array $body) => $dash($who, 'POST', "/management/production-exceptions/{$id}/decision", ['expectedVersion' => $exVersion($id)] + $body);
$unitsOf = static fn (array $o): array => $rows("SELECT unit_uuid, item_uuid, unit_index, track_uuid, rework_cycle, status FROM production_work_units WHERE order_uuid = ? ORDER BY item_uuid, unit_index", [$o['uuid']]);
$tracksOf = static fn (array $o): array => $rows('SELECT * FROM production_tracks WHERE order_uuid = ? ORDER BY track_uuid', [$o['uuid']]);
$cancellationPath = static fn (array $o): string => '/management/orders/' . rawurlencode($o['id']) . '/cancellation';
$control = static fn (array $o): ?array => $rows('SELECT * FROM order_cancellation_controls WHERE order_uuid = ?', [$o['uuid']])[0] ?? null;
$op = static fn (array $o): ?array => $rows('SELECT * FROM order_cancellation_sync_operations WHERE order_uuid = ? ORDER BY hold_generation DESC LIMIT 1', [$o['uuid']])[0] ?? null;
$stop = static fn (array $who, array $o): array => $dash($who, 'POST', $cancellationPath($o) . '/stop', ['reason' => 'Clientul a cerut anularea comenzii.', 'requestSourceCancellation' => true]);
$send = static fn (array $who, array $o, array $body = []): array => $dash($who, 'POST', $cancellationPath($o) . '/sync', $body + ['expectedVersion' => (int) ($control($o)['version'] ?? 1)]);
$release = static fn (array $who, array $o): array => $dash($who, 'POST', $cancellationPath($o) . '/release', ['expectedVersion' => (int) ($control($o)['version'] ?? 0), 'reason' => 'Clientul a confirmat comanda din nou.']);
$sourceCall = static fn (string $source, string $route, array $payload): array => T::ingest($kernel, $source, $payload, null, null, $route);
$pull = static fn (string $source): array => $sourceCall($source, 'orders/cancellations', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')]);
$report = static fn (string $source, array $operation, string $outcome, ?string $status = null): array => $sourceCall($source, 'orders/cancellations/result', ['operationId' => $operation['operation_uuid'], 'outcome' => $outcome] + ($status === null ? [] : ['sourceStatus' => $status]));
$stopped = static fn (array $r): bool => $r['status'] === 409 && in_array($r['body']['error']['code'] ?? '', ['ORDER_PRODUCTION_STOPPED', 'ORDER_UNAVAILABLE'], true);
$store = new TrendyolIntakeStore($pdo, $container->projectionWriter(), $clock);
$marketplace = static function (array $o, string $status) use ($store): string {
    $package = $o['pkg'];
    $package['status'] = $status;
    $package['lastModifiedDate'] += 5000;
    return $store->record(TrendyolPackage::fromApi($package), 0);
};

// Every production row of the order (pieces, tracks, defect cases, labels, plans, custody, checkpoints, history, QR) plus
// the order's own production columns. The synchronization must never change any of them.
$pieceTables = ['production_work_units', 'production_tracks', 'production_track_events', 'production_exceptions', 'production_exception_scopes', 'production_unit_labels',
    'production_line_plans', 'production_line_plan_heads', 'production_route_decisions', 'production_unit_stage_log', 'production_unit_custody', 'production_custody_events',
    'production_assisted_checkpoints', 'production_assisted_checkpoint_events', 'production_material_records', 'production_unit_verifications', 'production_route_reconciliations',
    'order_activity_events', 'order_qr_references'];
$snapshot = static function (array $o) use ($rows, $pieceTables): array {
    $out = [];
    foreach ($pieceTables as $table) {
        $out[$table] = $rows("SELECT * FROM {$table} WHERE order_uuid = ? ORDER BY 1, 2", [$o['uuid']]);
    }
    $exceptions = array_column($out['production_exceptions'], 'exception_uuid');
    foreach (['production_exception_events', 'production_exception_units'] as $table) {
        $out[$table] = $exceptions === [] ? [] : $rows("SELECT * FROM {$table} WHERE exception_uuid IN (" . implode(',', array_fill(0, count($exceptions), '?')) . ') ORDER BY 1, 2', $exceptions);
    }
    $out['order'] = $rows('SELECT production_stage_id, production_owner_employee_uuid, production_version, production_completed_at, production_claimed_at, production_changed_at, production_stopped_at, production_authority, production_context, open_exception_uuid, document_status, document_version FROM operational_orders WHERE order_uuid = ?', [$o['uuid']]);
    return $out;
};
$same = static function (array $before, array $after, string $label) use ($check): void {
    foreach ($before as $table => $value) {
        $check($after[$table] === $value, "{$label}: {$table} unchanged");
    }
};

// ---- P1. DRAPERII x2: piece A defective under managerial review, piece B at ironing; Trendyol unknown outcome -----------
$p1 = $trendyol('p1', [$line('Draperie de Textura Ivory', 'PC-21', 2)]);
$confirmPlan($p1, ['draperii']);
$bring($p1, 'ironing');
$units = $unitsOf($p1);
$check(count($units) === 2 && $units[0]['unit_index'] !== $units[1]['unit_index'], 'P1 two physical pieces of one line');
$x1 = $ok($reportUnits($tailor, $p1, [$units[0]['unit_uuid']], 'material-defect'), 'P1 piece A reported at ironing', 201)['id'];
$ok($review($supervisor, $x1, ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $p1['qr']]), 'P1 supervisor custody of piece A');
$ok($sendReturn($supervisor, $x1), 'P1 piece A returned to cutting');
$ok($ack($cutter, $x1, $p1['qr']), 'P1 cutter received piece A');
$check($exStatus($x1) === 'awaiting_approval', 'P1 the defect case awaits the manager decision');
$check($state($p1)['stage'] === 'ironing' && $state($p1)['owner'] === $uuids['tailor'], 'P1 piece B stays at ironing with its owner');
$unitsBefore = $unitsOf($p1);
$check(count(array_filter($unitsBefore, static fn (array $u): bool => $u['track_uuid'] !== null)) === 1, 'P1 exactly one piece is on a rework track');
$ok($stop($syncer, $p1), 'P1 stop with a source request');
$s1 = $snapshot($p1);
$ok($send($syncer, $p1, ['reasonCode' => 501]), 'P1 Trendyol notice queued');
$same($s1, $snapshot($p1), 'P1 queueing');
$fxPut(['fail' => true]);
$run(0);
$fxPut(null);
$check($op($p1)['state'] === 'uncertain' && count($puts($p1['package'])) === 1, 'P1 the answer is lost: uncertain');
$call = $puts($p1['package'])[0];
$check($call['body']['lines'] === [['lineId' => $p1['package'] * 10 + 1, 'quantity' => 2]], 'P1 the notice names the line with its full quantity 2 (both pieces), never one piece: ' . json_encode($call['body']));
$same($s1, $snapshot($p1), 'P1 unknown outcome');
foreach ([330, 660, 990, 1320, 1650, 2000] as $at) $run($at);
$check($op($p1)['state'] === 'manual_review' && $op($p1)['last_error_code'] === 'UNCERTAIN_OUTCOME_UNRESOLVED' && count($puts($p1['package'])) === 1, 'P1 observed, then manual review; never resent');
$same($s1, $snapshot($p1), 'P1 observation and manual review');
$error($release($releaser, $p1), 409, 'RELEASE_SYNC_OPEN', 'P1 no release while the outcome is unknown');
$approval = $decide($manager, $x1, ['decision' => 'approve', 'attribution' => 'none']);
$check($stopped($approval), 'P1 the rework approval stays refused while stopped: ' . json_encode([$approval['status'], $approval['body']['error'] ?? $approval['body']['status'] ?? null, $exStatus($x1), $tracksOf($p1)[0]['stage_id'] ?? null]));
$check($stopped($post($tailor, $p1['path'] . '/transition', ['expectedVersion' => (int) $state($p1)['version']])), 'P1 piece B cannot move on');
$same($s1, $snapshot($p1), 'P1 refused commands');
$marketplace($p1, 'Cancelled');
$check($op($p1)['state'] === 'confirmed' && $op($p1)['confirmed_by'] === 'inbound_event', 'P1 the marketplace cancellation confirms the operation');
$after = $snapshot($p1);
$same($s1, $after, 'P1 confirmation');
$check($exStatus($x1) === 'awaiting_approval' && $unitsOf($p1) === $unitsBefore, 'P1 the defect case and both piece identities are exactly as before the stop');
$check($state($p1)['completed'] === null, 'P1 nothing is completed by the cancellation');

// ---- P2. PERDEA + DRAPERII under piece routing: stage 6 + a PERDEA track at ironing; Trendhome pull/report/event ------
$checkpointsFlag = (int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'");
$p2 = $woo('trendhome', 'p2', [$line('Draperie Velvet', 'PC-DV', 1), $line('Perdea Voal', 'PC-PV', 1, 'sheer')]);
$check($features->setCohortMember(FeatureActivation::PIECE_ROUTING, $p2['uuid'], true, 'test-operator', 'sync pieces pilot', gmdate('Y-m-d H:i:s.000000'))['member'] === true, 'P2 piece routing cohort');
$confirmPlan($p2, ['draperii', 'perdea']);
$bring($p2, 'material-straightening');
$advance($tailor, $p2);
$tracks = $rows("SELECT track_uuid, stage_id, version FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$p2['uuid']]);
$check(count($tracks) === 1 && $tracks[0]['stage_id'] === 'ironing' && $state($p2)['stage'] === 'bottom-hem', 'P2 order at stage 6 (bottom hem), the PERDEA piece on its own track at ironing');
$ok($post($tailor2, "/production-tracks/{$tracks[0]['track_uuid']}/claim", ['expectedVersion' => (int) $tracks[0]['version'], 'qrToken' => $p2['qr']]), 'P2 the PERDEA track is claimed');
$claimTailor = $state($p2)['owner'];
$ok($stop($syncer, $p2), 'P2 stop with a source request');
$s2 = $snapshot($p2);
$queued = $ok($send($syncer, $p2), 'P2 Trendhome request queued for the connector');
$check($queued['state'] === 'awaiting_pickup' && $queued['mechanism'] === 'source_pull', 'P2 source pull');
$pulled = $ok($pull('trendhome'), 'P2 the connector pulls');
$mine = array_values(array_filter($pulled['operations'], static fn (array $item): bool => $item['orderId'] === $p2['number']));
$check(count($mine) === 1, 'P2 the request is offered to Trendhome once: ' . json_encode($pulled));
$same($s2, $snapshot($p2), 'P2 pickup');
$ok($report('trendhome', $op($p2), 'cancelled', 'cancelled'), 'P2 the connector reports cancelled');
$check($op($p2)['state'] === 'awaiting_confirmation', 'P2 a report is not a confirmation');
$same($s2, $snapshot($p2), 'P2 report');
$track = $rows('SELECT * FROM production_tracks WHERE track_uuid = ?', [$tracks[0]['track_uuid']])[0];
$check($stopped($post($tailor2, "/production-tracks/{$track['track_uuid']}/complete", ['expectedVersion' => (int) $track['version']])), 'P2 the PERDEA track cannot continue');
$check($stopped($post($tailor, $p2['path'] . '/transition', ['expectedVersion' => (int) $state($p2)['version']])), 'P2 the order cannot continue at stage 6');
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($p2['number'], "evt-sp-p2-cancel-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 10), null, 'cancelled', 'cancelled', $wooLines[$p2['id']], $p2['number'])), 'P2 the shop\'s signed cancelled event');
$check($op($p2)['state'] === 'confirmed' && $op($p2)['confirmed_by'] === 'inbound_event', 'P2 confirmed by the signed event');
$same($s2, $snapshot($p2), 'P2 confirmation');
$check($state($p2)['owner'] === $claimTailor && $track['owner_employee_uuid'] === $uuids['tailor2'] && $track['stage_id'] === 'ironing', 'P2 both pieces keep their stage and owner');
$check((int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'") === $checkpointsFlag, 'P2 the PERDEA 6/7 rule (informational checkpoints) is untouched');

// ---- P3. Two DRAPERII pieces in packing (stage 13); OutletPerdele refuses a paid order -----------------------------------
$p3 = $woo('outletperdele', 'p3', [$line('Draperie Blackout', 'PC-BO', 2)]);
$confirmPlan($p3, ['draperii']);
$bring($p3, 'packing');
$check($state($p3)['stage'] === 'packing' && count($unitsOf($p3)) === 2, 'P3 both pieces at packing');
$ok($stop($syncer, $p3), 'P3 stop with a source request');
$s3 = $snapshot($p3);
$ok($send($syncer, $p3), 'P3 request queued');
$ok($pull('outletperdele'), 'P3 pulled');
$ok($report('outletperdele', $op($p3), 'rejected_paid', 'processing'), 'P3 the shop refuses: money received');
$check($op($p3)['state'] === 'manual_review' && $op($p3)['last_error_code'] === 'PAID_ORDER_REQUIRES_PERSON', 'P3 a person decides; no refund anywhere');
$same($s3, $snapshot($p3), 'P3 refusal');
$error($release($releaser, $p3), 409, 'RELEASE_SYNC_OPEN', 'P3 no release while the request is under review');
$check($stopped($post($finisher, $p3['path'] . '/transition', ['expectedVersion' => (int) $state($p3)['version']])), 'P3 packing cannot be completed while stopped');
$check($state($p3)['stage'] === 'packing' && $state($p3)['completed'] === null, 'P3 stage 13 kept, nothing completed, no backward move');
$same($s3, $snapshot($p3), 'P3 refused commands');

// ---- P4. DRAPERII x2: piece B through quality control, piece A back in rework at cutting; accepted and confirmed -------
$p4 = $trendyol('p4', [$line('Draperie In', 'PC-IN', 2)]);
$confirmPlan($p4, ['draperii']);
$bring($p4, 'ironing');
$u4 = $unitsOf($p4);
$x4 = $ok($reportUnits($tailor, $p4, [$u4[0]['unit_uuid']], 'material-defect'), 'P4 piece A reported', 201)['id'];
$ok($review($supervisor, $x4, ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $p4['qr']]), 'P4 custody');
$ok($sendReturn($supervisor, $x4), 'P4 returned');
$ok($ack($cutter, $x4, $p4['qr']), 'P4 received at cutting');
$ok($decide($manager, $x4, ['decision' => 'approve', 'attribution' => 'none']), 'P4 rework approved: piece A at cutting');
// Piece B continues with the order up to quality control (stage 12) and is completed there; packing waits for piece A.
$bring($p4, 'quality-control');
$check($state($p4)['stage'] === 'quality-control', 'P4 piece B (the order) at quality control');
$t4 = $rows("SELECT * FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$p4['uuid']]);
$check(count($t4) === 1 && $t4[0]['stage_id'] === 'material-preparation', 'P4 piece A is in rework at cutting on its own track');
$ok($stop($syncer, $p4), 'P4 stop with a source request');
$s4 = $snapshot($p4);
$ok($send($syncer, $p4, ['reasonCode' => 501]), 'P4 Trendyol notice queued');
$run(0);
$check($op($p4)['state'] === 'awaiting_confirmation' && count($puts($p4['package'])) === 1 && $puts($p4['package'])[0]['body']['lines'][0]['quantity'] === 2, 'P4 accepted; the notice covers both pieces');
$run(200);
$check($op($p4)['state'] === 'confirmed' && $op($p4)['confirmed_by'] === 'status_read', 'P4 confirmed by reading the package');
$same($s4, $snapshot($p4), 'P4 send and confirmation');
$check($state($p4)['completed'] === null && $state($p4)['stage'] === 'quality-control', 'P4 partial completion never becomes order completion');
$check($stopped($post($cutter, "/production-tracks/{$t4[0]['track_uuid']}/claim", ['expectedVersion' => (int) $t4[0]['version'], 'qrToken' => $p4['qr']])), 'P4 the rework piece cannot be re-cut after the cancellation');

// ---- Scope: the four orders' production rows were written only by production commands before each stop ----------------
foreach ([[$p1, $s1], [$p2, $s2], [$p3, $s3], [$p4, $s4]] as [$o, $s]) {
    $same($s, $snapshot($o), 'final');
}
$check((int) $one("SELECT COUNT(*) FROM order_cancellation_sync_attempts a JOIN order_cancellation_sync_operations o ON o.operation_uuid = a.operation_uuid WHERE o.order_uuid IN (?, ?, ?, ?) AND a.kind = 'submit'", [$p1['uuid'], $p2['uuid'], $p3['uuid'], $p4['uuid']]) === 2, 'only two Trendyol notices in total (P1 once, P4 once)');

fwrite(STDOUT, "PASS MySQL cancellation synchronization pieces integration ({$checks} checks)\n");
