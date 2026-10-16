<?php

declare(strict_types=1);

// Adversarial simulation of the cancellation synchronization (migration 034, API 2.36.0) on a disposable test database.
// A seeded pseudo-random scenario interleaves, over synthetic orders of all three sources: stops, sends, worker runs,
// Trendyol answers (accepted, lost before or after acting, 429, 5xx, refusals, failed reads), connector pickups and
// reports (including duplicates, conflicts and another site's reports), inbound cancellations and reactivations,
// withdrawals, retries, closes, release attempts, physical records, gates closing mid-flight and crashed workers.
// The invariants below are checked after every step.
//
//   ARASYA_SIM_SEED (default 20261016) and ARASYA_SIM_STEPS (default 600) select the scenario. The scenario (every
//   action, its target and every provider behaviour) is a function of the seed; the worker's backoff jitter is random
//   by design and only changes when an operation becomes due, which the invariants must survive anyway.
//
//   I1  at most one open operation per order, and it belongs to the current stop generation
//   I2  no release while an operation is open; a released order has no open operation
//   I3  every confirmation names its evidence, and the evidence exists (stored source cancellation / cancelled status)
//   I4  Trendyol: every notice is a recorded submit, and every submit directly follows a status read
//   I5  no notice and no pickup while a gate of that provider is closed
//   I6  a withdrawn operation never reaches the provider afterwards
//   I7  production is never moved by the synchronization: stage, owner, production version, history of a stopped order
//   I8  a stopped order refuses manufacturing commands
//   I9  the attempt history explains every state (an unbroken chain ending at the current state); it only grows
//   I10 nothing but an authorized release lifts a stop (reactivation, reports, confirmations, reconciliation never do)
//   I11 another site's report is indistinguishable from a missing operation

use Arasya\Operations\Application\Container;
use Arasya\Operations\Cancellation\Sync\CancellationSyncWorker;
use Arasya\Operations\Cancellation\Sync\ProviderRegistry;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\Trendyol\TrendyolEligibility;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP cancellation synchronization simulation: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$seed = (int) (getenv('ARASYA_SIM_SEED') ?: 20261016);
$steps = (int) (getenv('ARASYA_SIM_STEPS') ?: 600);
mt_srand($seed);
$pick = static fn (array $items): mixed => $items[mt_rand(0, count($items) - 1)];
$chance = static fn (int $percent): bool => mt_rand(1, 100) <= $percent;

const DASHBOARD = 'http://127.0.0.1:4174';
$suffix = sprintf('%08x', $seed & 0xffffffff) . bin2hex(random_bytes(2));
$fixtureFile = sys_get_temp_dir() . "/arasya-cancellation-sim-{$suffix}.json";
file_put_contents($fixtureFile, json_encode(['packages' => [], 'putCalls' => []], JSON_THROW_ON_ERROR));
register_shutdown_function(static fn () => @unlink($fixtureFile));
$config = new Config(...[...(array) T::config($dbName, [T::ORIGIN, DASHBOARD]), 'trendyolFixtureFile' => $fixtureFile, 'cancellationSync' => 'enabled']);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$pdo->exec('DELETE FROM system_root_identity');
$pdo->exec("UPDATE production_feature_flags SET enabled = 0 WHERE flag_key IN ('partial_rework', 'piece_routing', 'perdea_assisted_checkpoints')");
$pdo->exec("UPDATE production_feature_flags SET enabled = 1 WHERE flag_key LIKE 'cancellation\\_sync\\_%'");
$pdo->exec("UPDATE trendyol_intake_state SET status = 'inactive', baseline_at = NULL WHERE state_id = 1");

$checks = 0;
$step = 0;
$check = static function (bool $condition, string $label) use (&$checks, &$step, $seed): void { $checks++; if (!$condition) throw new RuntimeException("FAIL seed {$seed} step {$step}: {$label}"); };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$clock = $container->clock();
$rootName = "sim.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('test-cancellation-sim-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Cancellation sim root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']);
$root = T::login($kernel, $rootName, 'Cancellation sim root passphrase 2026!');
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');
$dash = static fn (array $who, string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'sim-' . bin2hex(random_bytes(10))], $who['cookie']);
$post = static fn (array $who, string $path, array $body): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => 'sim-' . bin2hex(random_bytes(10))], $who['cookie']);
$password = 'cancellation sim passphrase 2026';
$identity = static function (string $name, array $stages, array $apps, array $roles = []) use ($container, $dash, $root, $password, $suffix, $kernel): array {
    $employee = $container->employeeAdmin()->create("Sim {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-cancellation-sim');
    $dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]);
    if ($roles !== []) $dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]);
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid];
};
$canonical = ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'];
$role = (int) ($dash($root, 'POST', '/management/roles', ['name' => "Simulare anulare {$suffix}", 'description' => null, 'authorityRank' => 200,
    'permissions' => ['orders.view_all', 'production.cancellation.manage', 'production.cancellation.sync', 'production.cancellation.release']])['body']['role']['id'] ?? 0);
$check($role > 0, 'simulation role');
$manager = $identity('manager', [], ['dashboard'], [$role]);
$worker = $identity('worker', array_slice($canonical, 1), ['staff']);

// ---- Fixture and worker -------------------------------------------------------------------------------------------
$fx = static fn (): array => json_decode((string) file_get_contents($fixtureFile), true, 64, JSON_THROW_ON_ERROR);
$fxWrite = static fn (array $fixture): int => (int) file_put_contents($fixtureFile, json_encode($fixture, JSON_THROW_ON_ERROR));
$fxPackage = static function (int $packageId) use ($fx): ?array { foreach ($fx()['packages'] as $p) if ($p['shipmentPackageId'] === $packageId) return $p; return null; };
$putsFor = static fn (int $packageId): int => count(array_filter($fx()['putCalls'] ?? [], static fn (array $c): bool => str_contains($c['url'], "/shipment-packages/{$packageId}/")));
$wclock = new class implements Clock {
    public int $offset = 0;
    public function now(): DateTimeImmutable { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$this->offset} seconds"); }
};
$virtual = 0; // virtual seconds ahead of the real clock, only ever increasing
$store = new TrendyolIntakeStore($pdo, $container->projectionWriter(), $clock);

// ---- Synthetic orders -----------------------------------------------------------------------------------------------
$orders = [];
$wooItems = [];
$tickets = [];
$tick = static function (string $id) use (&$tickets): string { $tickets[$id] = ($tickets[$id] ?? 0) + 1; return gmdate('Y-m-d\TH:i:s\Z', time() - 7200 + $tickets[$id] * 5); };
for ($i = 0; $i < 24; $i++) {
    $source = ['trendyol', 'trendhome', 'outletperdele'][$i % 3];
    if ($source === 'trendyol') {
        $number = "119SIM{$i}{$suffix}";
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pdo->beginTransaction();
        $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-sim{$i}-{$suffix}", "approval-sim{$i}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now,
            [new OperationalOrderItem(Uuid::v4(), "sim-{$i}-1", 1, 'Draperie', 'SIM-1', null, 'Gri', 200.0, 260.0, 'cm', 5.0, 1, ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => 'Draperie', 'barcode' => null])]), []);
        $packageId = random_int(10_000_000, 99_999_999); // not part of the scenario: unique across runs on one database
        $modified = (int) floor(microtime(true) * 1000) - 600_000;
        $package = ['shipmentPackageId' => $packageId, 'orderNumber' => $number, 'lastModifiedDate' => $modified, 'status' => 'Created', 'orderDate' => $modified - 1000,
            'lines' => [['lineId' => $packageId * 10 + 1, 'productName' => 'Draperie', 'quantity' => 1]]];
        $pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, lines_hash, version, first_seen_at, last_seen_at, released_order_uuid, released_by_employee_uuid, released_at)
            VALUES (?, ?, 'released', 'Created', ?, ?, ?, 1, ?, ?, ?, ?, ?)")->execute([$packageId, $number, $modified, $modified - 1000, TrendyolPackage::fromApi($package)->linesHash(), $now->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), $uuid, $rootUuid, $now->format('Y-m-d H:i:s.u')]);
        $pdo->commit();
        $f = $fx();
        $f['packages'][] = $package;
        $fxWrite($f);
        $id = (string) $one('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?', [$uuid]);
        $orders[$id] = ['id' => $id, 'uuid' => $uuid, 'source' => 'trendyol', 'package' => $packageId, 'lastModified' => $modified];
    } else {
        $number = "SIM{$i}{$suffix}";
        $id = "{$source}:{$number}";
        $stage = $pick($canonical);
        $wooItems[$id] = [['id' => 9000 + $i, 'line' => 1, 'name' => 'Draperie', 'sku' => 'SIM-1', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1]];
        $r = T::ingest($kernel, $source, T::sourceOrder($number, "sim-{$i}-{$suffix}-0", $tick($id), T::stage($stage), 'processing', 'active', $wooItems[$id], $number));
        $check($r['status'] === 200, "ingest {$id}");
        $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$id]);
        $orders[$id] = ['id' => $id, 'uuid' => $uuid, 'source' => $source, 'number' => $number];
        if ($stage !== 'waiting' && $chance(60)) {
            $version = (int) $one('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$uuid]);
            $claim = $post($worker, '/orders/' . rawurlencode($id) . '/claim', T::cuttingClaim($pdo, $id, $worker['employeeUuid'], $version));
            $check($claim['status'] === 200, "claim {$id}: " . json_encode($claim['body']));
        }
    }
}
$ids = array_keys($orders);

// ---- Model of what the scenario did (used by the invariants) ---------------------------------------------------------
$gates = ['trendyol' => true, 'trendhome' => true, 'outletperdele' => true];
$frozen = [];       // order uuid => production facts at the stop
$withdrawnPuts = []; // operation uuid => notices of its package when withdrawn
$attemptCounts = [];
$state = static fn (string $uuid): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, cancellation_state AS cancellation,
    (SELECT COUNT(*) FROM order_activity_events a WHERE a.order_uuid = o.order_uuid) AS activity FROM operational_orders o WHERE order_uuid = ?', [$uuid])[0];
$control = static fn (string $uuid): ?array => $rows('SELECT * FROM order_cancellation_controls WHERE order_uuid = ?', [$uuid])[0] ?? null;
$opsOf = static fn (string $uuid): array => $rows('SELECT * FROM order_cancellation_sync_operations WHERE order_uuid = ? ORDER BY hold_generation', [$uuid]);
$current = static function (string $uuid) use ($control, $rows): ?array {
    $c = $control($uuid);
    return $c === null ? null : ($rows('SELECT * FROM order_cancellation_sync_operations WHERE order_uuid = ? AND hold_generation = ?', [$uuid, $c['hold_generation']])[0] ?? null);
};
$path = static fn (array $o): string => '/management/orders/' . rawurlencode($o['id']) . '/cancellation';
$wooEvent = static function (array $o, bool $cancelled) use ($kernel, $tick, &$wooItems): array {
    return T::ingest($kernel, $o['source'], T::sourceOrder($o['number'], 'sim-ev-' . bin2hex(random_bytes(6)), $tick($o['id']), null, $cancelled ? 'cancelled' : 'processing', $cancelled ? 'cancelled' : 'active', $wooItems[$o['id']], $o['number']));
};
$marketplace = static function (array &$o, ?string $status = null) use ($store, $fxPackage): void {
    $package = $fxPackage($o['package']);
    if ($status !== null) $package['status'] = $status;
    $o['lastModified'] = max($o['lastModified'] + 1000, (int) $package['lastModifiedDate']);
    $package['lastModifiedDate'] = $o['lastModified'];
    $store->record(TrendyolPackage::fromApi($package), 0);
};
$runWorker = static function (?int $advance = null) use (&$virtual, $wclock, $pdo, $container, $pick, $fx, &$gates, $check, $orders): void {
    $virtual += $advance ?? $pick([30, 90, 200, 400, 2000, 4000]);
    $wclock->offset = $virtual;
    $before = $fx()['putCalls'] ?? [];
    (new CancellationSyncWorker($pdo, $wclock, $container->cancellationProviders()))->run(100);
    $after = $fx()['putCalls'] ?? [];
    // I5: no notice while the Trendyol gate is closed.
    $check($gates['trendyol'] || count($after) === count($before), 'I5 a Trendyol notice was sent while its gate was closed');
};

// ---- Invariants -------------------------------------------------------------------------------------------------------
$invariants = static function () use ($rows, $one, $check, &$orders, $state, $control, $opsOf, &$frozen, &$withdrawnPuts, &$attemptCounts, $putsFor): void {
    // I1
    $check((int) $one('SELECT COUNT(*) FROM (SELECT order_uuid FROM order_cancellation_sync_operations WHERE open_order_uuid IS NOT NULL GROUP BY order_uuid HAVING COUNT(*) > 1) x') === 0, 'I1 two open operations on one order');
    $check((int) $one('SELECT COUNT(*) FROM order_cancellation_sync_operations x JOIN order_cancellation_controls c ON c.order_uuid = x.order_uuid WHERE x.open_order_uuid IS NOT NULL AND x.hold_generation <> c.hold_generation') === 0, 'I1 an open operation of an older stop');
    // I2
    $check((int) $one("SELECT COUNT(*) FROM order_cancellation_sync_operations x JOIN operational_orders o ON o.order_uuid = x.order_uuid WHERE x.open_order_uuid IS NOT NULL AND (o.cancellation_state IS NULL OR o.cancellation_state <> 'held')") === 0, 'I2 an open operation on a released order');
    foreach ($orders as $o) {
        $ops = $opsOf($o['uuid']);
        foreach ($ops as $operation) {
            // I3
            if ($operation['state'] === 'confirmed') {
                $check(in_array($operation['confirmed_by'], ['inbound_event', 'status_read'], true), 'I3 confirmation without evidence');
                if ($operation['confirmed_by'] === 'status_read') {
                    $check(TrendyolEligibility::statusClass((string) $operation['provider_status']) === TrendyolEligibility::CLASS_CANCELLED, 'I3 status-read confirmation without a cancelled status: ' . $operation['provider_status']);
                } else {
                    $check((int) $rows("SELECT COUNT(*) AS n FROM order_cancellation_events WHERE order_uuid = ? AND event_type IN ('source_cancellation_received', 'source_evidence_updated', 'source_cancellation_confirmed')", [$o['uuid']])[0]['n'] > 0
                        || $control($o['uuid'])['source_status'] === 'cancelled', 'I3 inbound confirmation without a stored source cancellation');
                }
            }
            // I9
            $trail = $rows('SELECT kind, state_before, state_after, outcome FROM order_cancellation_sync_attempts WHERE operation_uuid = ? ORDER BY attempt_seq', [$operation['operation_uuid']]);
            $check(($attemptCounts[$operation['operation_uuid']] ?? 0) <= count($trail), 'I9 the attempt history shrank');
            $attemptCounts[$operation['operation_uuid']] = count($trail);
            $expected = 'queued';
            foreach ($trail as $index => $attempt) {
                if ($index === 0) {
                    $check($attempt['kind'] === 'create', 'I9 history starts with the creation');
                    $expected = $attempt['state_after'];
                    continue;
                }
                $check($attempt['state_before'] === $expected, "I9 broken history chain at attempt {$index}: {$attempt['state_before']} after {$expected}");
                $expected = $attempt['state_after'];
                // I4: every submit directly follows a status read of the same lease.
                if ($attempt['kind'] === 'submit' && !str_starts_with($attempt['outcome'], 'late_')) {
                    $check($trail[$index - 1]['kind'] === 'status_read', 'I4 a submit without a status read before it');
                }
            }
            $check($expected === $operation['state'], "I9 history ends at {$expected}, state is {$operation['state']}");
        }
        if ($o['source'] === 'trendyol') {
            $submits = 0;
            foreach ($ops as $operation) {
                $submits += (int) $one("SELECT COUNT(*) FROM order_cancellation_sync_attempts WHERE operation_uuid = ? AND kind = 'submit'", [$operation['operation_uuid']]);
            }
            $check($putsFor($o['package']) === $submits, "I4 notices {$putsFor($o['package'])} vs recorded submits {$submits}");
        }
        // I7 / I10
        $now = $state($o['uuid']);
        if (isset($frozen[$o['uuid']])) {
            if ($now['cancellation'] === 'held') {
                $f = $frozen[$o['uuid']];
                $check($now['stage'] === $f['stage'] && $now['owner'] === $f['owner'] && (int) $now['version'] === (int) $f['version'] && (int) $now['activity'] === (int) $f['activity'], 'I7 a stopped order moved: ' . json_encode([$f, $now]));
            } else {
                $check((int) $one("SELECT COUNT(*) FROM order_cancellation_events WHERE order_uuid = ? AND event_type = 'release_approved'", [$o['uuid']]) > 0, 'I10 a stop was lifted without an authorized release');
                unset($frozen[$o['uuid']]);
            }
        } elseif ($now['cancellation'] === 'held') {
            $frozen[$o['uuid']] = $now;
        }
    }
    // I6
    foreach ($withdrawnPuts as $operationUuid => [$packageId, $count]) {
        $check($packageId === null || $putsFor($packageId) === $count || (int) $one("SELECT COUNT(*) FROM order_cancellation_sync_operations x JOIN order_cancellation_sync_operations w ON w.order_uuid = x.order_uuid AND x.hold_generation > w.hold_generation WHERE w.operation_uuid = ?", [$operationUuid]) > 0,
            'I6 a withdrawn operation reached Trendyol');
    }
};

// ---- Scenario ---------------------------------------------------------------------------------------------------------
$counts = [];
for ($step = 1; $step <= $steps; $step++) {
    $id = $pick($ids);
    $o = &$orders[$id];
    $c = $control($o['uuid']);
    $cur = $current($o['uuid']);
    // The action depends on where the chosen order is, so every phase of an operation is exercised often.
    $action = $pick(match (true) {
        $c === null || $c['hold_status'] === 'released' => ['stop', 'stop', 'stop', 'stop', 'inbound_cancel', 'reactivate', 'run', 'gate', 'script', 'pull'],
        $cur === null && $c['source_status'] !== 'cancelled' => ['send', 'send', 'send', 'send', 'send', 'release', 'physical', 'run', 'reactivate', 'stale', 'inbound_cancel'],
        $cur === null => ['release', 'physical', 'reactivate', 'reactivate', 'run', 'stale', 'stop'],
        default => ['run', 'run', 'run', 'run', 'pull', 'pull', 'pull', 'inbound_cancel', 'inbound_cancel', 'withdraw', 'retry', 'retry', 'close', 'release', 'physical', 'crash', 'foreign', 'stale', 'script', 'script', 'gate', 'reactivate', 'send'],
    });
    $counts[$action] = ($counts[$action] ?? 0) + 1;
    switch ($action) {
        case 'stop':
            $dash($manager, 'POST', $path($o) . '/stop', ['reason' => 'Simulare: clientul a cerut anularea.', 'requestSourceCancellation' => $chance(80)]);
            break;
        case 'send':
            if ($c !== null) {
                $dash($manager, 'POST', $path($o) . '/sync', ['expectedVersion' => (int) $c['version']] + ($o['source'] === 'trendyol' ? ['reasonCode' => $pick([500, 501, 504, 506])] : []));
            }
            break;
        case 'run':
            $runWorker();
            break;
        case 'script':
            $f = $fx();
            $f['put'] = $pick([['status' => 200, 'applyStatus' => 'Cancelled'], ['status' => 200, 'applyStatus' => 'Cancelled'], ['status' => 429], ['status' => 503], ['status' => 200, 'applyStatus' => 'Cancelled', 'failAfterApply' => true],
                ['fail' => true], ['status' => 400], ['status' => 200, 'applyStatus' => null]]);
            unset($f['fail'], $f['status']);
            if ($chance(15)) $f['fail'] = true; // the status reads fail too for a while
            $fxWrite($f);
            break;
        case 'pull':
            $source = $pick(['trendhome', 'outletperdele']);
            $pulled = T::ingest($kernel, $source, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'orders/cancellations');
            $check($pulled['status'] === 200, 'pull answers');
            // I5: nothing is handed over while the gate is closed.
            $check($gates[$source] || ($pulled['body']['operations'] ?? []) === [], 'I5 a pickup while the gate was closed');
            foreach ($pulled['body']['operations'] ?? [] as $item) {
                $owner = $one('SELECT source_key FROM order_cancellation_sync_operations WHERE operation_uuid = ?', [$item['operationId']]);
                $check($owner === $source, 'I11 a connector received another site\'s operation');
                if ($chance(70) && isset($orders[(string) $one('SELECT o.global_order_id FROM order_cancellation_sync_operations x JOIN operational_orders o ON o.order_uuid = x.order_uuid WHERE x.operation_uuid = ?', [$item['operationId']])])) {
                    $outcome = $pick(['cancelled', 'cancelled', 'already_cancelled', 'rejected_paid', 'rejected_status', 'not_found']);
                    $times = $chance(25) ? 2 : 1;
                    for ($t = 0; $t < $times; $t++) {
                        $report = T::ingest($kernel, $source, ['operationId' => $item['operationId'], 'outcome' => $outcome], null, null, 'orders/cancellations/result');
                        $check(in_array($report['status'], [200, 409], true), 'report answers: ' . json_encode($report['body']));
                    }
                    if (in_array($outcome, ['cancelled', 'already_cancelled'], true) && $chance(70)) {
                        $target = (string) $one('SELECT o.global_order_id FROM order_cancellation_sync_operations x JOIN operational_orders o ON o.order_uuid = x.order_uuid WHERE x.operation_uuid = ?', [$item['operationId']]);
                        // On a shared CI database other suites' requests are pulled too; only this scenario's shops answer.
                        if (isset($orders[$target])) $wooEvent($orders[$target], true);
                    }
                }
            }
            break;
        case 'inbound_cancel':
            if ($o['source'] === 'trendyol') {
                $marketplace($o, $chance(50) ? null : 'Cancelled');
            } else {
                $wooEvent($o, true);
            }
            break;
        case 'reactivate':
            if ($o['source'] === 'trendyol') {
                $marketplace($o, 'Picking');
            } else {
                $wooEvent($o, false);
            }
            break;
        case 'withdraw':
            if ($cur !== null) {
                $r = $dash($manager, 'POST', "/management/cancellation-sync/operations/{$cur['operation_uuid']}/withdraw", ['expectedVersion' => (int) $cur['version'], 'reason' => 'Simulare: retras de manager.']);
                if ($r['status'] === 200) $withdrawnPuts[$cur['operation_uuid']] = [$o['package'] ?? null, isset($o['package']) ? $putsFor($o['package']) : 0];
            }
            break;
        case 'retry':
            if ($cur !== null) $dash($manager, 'POST', "/management/cancellation-sync/operations/{$cur['operation_uuid']}/retry", ['expectedVersion' => (int) $cur['version']]);
            break;
        case 'close':
            if ($cur !== null) $dash($manager, 'POST', "/management/cancellation-sync/operations/{$cur['operation_uuid']}/close", ['expectedVersion' => (int) $cur['version'], 'note' => 'Simulare: rezolvat manual în canal.']);
            break;
        case 'release':
            if ($c !== null) {
                $openBefore = (int) $one('SELECT COUNT(*) FROM order_cancellation_sync_operations WHERE open_order_uuid = ?', [$o['uuid']]);
                $r = $dash($manager, 'POST', $path($o) . '/release', ['expectedVersion' => (int) $c['version'], 'reason' => 'Simulare: clientul păstrează comanda.']);
                // I2: a release succeeds only without an open operation.
                $check($r['status'] !== 200 || $openBefore === 0, 'I2 released while an operation was open');
                if ($openBefore > 0) $check($r['status'] === 409, 'I2 an open operation must refuse the release: ' . json_encode($r['body']));
            }
            break;
        case 'physical':
            if ($c !== null && $c['physical_status'] === 'pending') {
                $qr = 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$o['uuid']]);
                $dash($manager, 'POST', $path($o) . '/physical', ['expectedVersion' => (int) $c['version'], 'outcome' => 'secured', 'note' => 'Simulare: securizat.', 'qrToken' => $qr]);
            }
            break;
        case 'gate':
            $source = $pick(['trendyol', 'trendhome', 'outletperdele']);
            $gates[$source] = !$gates[$source];
            $pdo->prepare('UPDATE production_feature_flags SET enabled = ? WHERE flag_key = ?')->execute([$gates[$source] ? 1 : 0, ProviderRegistry::FLAGS[$source]]);
            break;
        case 'crash':
            // A worker leased a due API operation (exactly as the worker records a lease) and died before recording a result.
            if ($cur !== null && $cur['mechanism'] === 'api' && in_array($cur['state'], ['queued', 'retry_scheduled'], true)) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE order_cancellation_sync_operations SET state = 'processing', lease_owner = 'worker-dead', lease_expires_at = ?, version = version + 1 WHERE operation_uuid = ? AND version = ?")
                    ->execute([gmdate('Y-m-d H:i:s', time() + $virtual - 600), $cur['operation_uuid'], $cur['version']]);
                $pdo->prepare("INSERT INTO order_cancellation_sync_attempts (operation_uuid, order_uuid, kind, state_before, state_after, outcome, actor_system, occurred_at) VALUES (?, ?, 'status_read', ?, 'processing', 'leased', 'worker-dead', UTC_TIMESTAMP(6))")
                    ->execute([$cur['operation_uuid'], $o['uuid'], $cur['state']]);
                $pdo->commit();
            }
            break;
        case 'foreign':
            if ($cur !== null && $cur['mechanism'] === 'source_pull') {
                $other = $cur['source_key'] === 'trendhome' ? 'outletperdele' : 'trendhome';
                $r = T::ingest($kernel, $other, ['operationId' => $cur['operation_uuid'], 'outcome' => 'cancelled'], null, null, 'orders/cancellations/result');
                $check($r['status'] === 404 && ($r['body']['error']['code'] ?? '') === 'CANCELLATION_SYNC_NOT_FOUND', 'I11 another site could act on an operation');
            }
            break;
        case 'stale':
            // I8: a stopped, owned order refuses its owner's transition.
            $s = $state($o['uuid']);
            if ($s['cancellation'] === 'held' && $s['owner'] === $worker['employeeUuid']) {
                $r = $post($worker, '/orders/' . rawurlencode($o['id']) . '/transition', ['expectedVersion' => (int) $s['version']]);
                $check($r['status'] === 409 && in_array($r['body']['error']['code'] ?? '', ['ORDER_PRODUCTION_STOPPED', 'ORDER_UNAVAILABLE'], true), 'I8 a stopped order accepted a transition: ' . json_encode($r['body']));
            }
            break;
    }
    unset($o);
    $invariants();
}

// ---- Drain: every gate open, every answer good, every connector reports; then nothing may remain undecided -------------
foreach (array_keys($gates) as $source) {
    $gates[$source] = true;
}
$pdo->exec("UPDATE production_feature_flags SET enabled = 1 WHERE flag_key LIKE 'cancellation\\_sync\\_%'");
$f = $fx();
unset($f['fail'], $f['status'], $f['put']);
$fxWrite($f);
for ($round = 0; $round < 12; $round++) {
    $runWorker(4000);
    foreach (['trendhome', 'outletperdele'] as $source) {
        foreach (T::ingest($kernel, $source, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'orders/cancellations')['body']['operations'] ?? [] as $item) {
            $target = (string) $one('SELECT o.global_order_id FROM order_cancellation_sync_operations x JOIN operational_orders o ON o.order_uuid = x.order_uuid WHERE x.operation_uuid = ?', [$item['operationId']]);
            if (!isset($orders[$target])) continue;
            T::ingest($kernel, $source, ['operationId' => $item['operationId'], 'outcome' => 'cancelled'], null, null, 'orders/cancellations/result');
            $wooEvent($orders[$target], true);
        }
    }
    foreach ($orders as $id => $o) {
        if ($o['source'] === 'trendyol' && TrendyolEligibility::statusClass((string) $fxPackage($o['package'])['status']) === TrendyolEligibility::CLASS_CANCELLED) {
            $marketplace($orders[$id]);
        }
    }
    $invariants();
}
$step = $steps + 1;
$mineSql = implode(',', array_map([$pdo, 'quote'], array_column($orders, 'uuid')));
$undecided = $rows("SELECT state, mechanism, COUNT(*) AS n FROM order_cancellation_sync_operations WHERE order_uuid IN ({$mineSql}) AND state IN ('queued', 'processing', 'retry_scheduled', 'uncertain', 'awaiting_pickup') GROUP BY state, mechanism");
$check($undecided === [], 'after the drain every operation is decided or waits for a person / confirmation: ' . json_encode($undecided));
$summary = [];
foreach ($rows("SELECT state, COUNT(*) AS n FROM order_cancellation_sync_operations WHERE order_uuid IN ({$mineSql}) GROUP BY state ORDER BY state") as $row) $summary[$row['state']] = (int) $row['n'];
$findings = $container->cancellationReconciler()->scan();
$check(array_filter($findings, static fn (array $f): bool => $f['kind'] === 'released_with_open_operation') === [], 'the reconciliation finds no released order with an open operation');
ksort($counts);
fwrite(STDOUT, 'PASS cancellation synchronization simulation (seed ' . $seed . ", {$steps} steps, {$checks} checks) operations " . json_encode($summary) . ' actions ' . json_encode($counts) . "\n");
