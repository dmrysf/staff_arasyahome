<?php

declare(strict_types=1);

// Production Intelligence V2 (API 2.70.0) against a disposable MySQL test database:
// A. the meterBasis fact never changes an order, its version, its hash, its items or its production document;
// B. the sold-meter ledger is idempotent (re-sync, repeated runs, corrections, cancellation, reactivation, removed lines,
//    exclusion, concurrent run) and only reads canonical tables;
// C. production metrics (cut / recut / processed / completed) follow K1, K2, K8 and never count unknown as zero;
// D. security: only Root and the canonical CEO read management intelligence (K4); an employee reads only their own work;
// E. the automatic incremental run: no implicit backfill, new orders, cancellations, late facts, exclusions and late
//    commits are booked once, intake never writes the ledger and the run never waits on a canonical row lock.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Intelligence\SoldMeterLedger;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP production intelligence integration: test database not configured\n"; exit(0); }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label) use ($check): array { $check($r['status'] === 200, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body']['error'] ?? null)); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): void { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); };
$container = new Container($config, $pdo); $kernel = $container->kernel(); $clock = $container->clock();
$suffix = bin2hex(random_bytes(5)); $password = 'intelligence test passphrase 2026';
$pdo->exec('DELETE FROM system_root_identity');
$rootName = 'pi.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('pi-test', $rootName);
$root = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => $password], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'root password');
$root = T::login($kernel, $rootName, $password);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$create = static function (string $name, array $stages, array $apps, string $role = 'employee') use ($container, $root, $password, $suffix, $pdo, $kernel, $ok): array {
    $e = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'pi-test');
    $ok(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/applications", ['applications' => $apps], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'application access');
    if ($role !== 'employee') {
        $s = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = ?'); $s->execute([$role]);
        $ok(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/roles", ['roleIds' => [(int) $s->fetchColumn()]], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'role ' . $role);
    }
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $e->employeeUuid];
};
$cutter = $create('pi-cutter', ['material-preparation'], ['staff']);
$labeler = $create('pi-labeler', ['labeling'], ['staff']);
$packer = $create('pi-packer', ['delivery'], ['staff']);
$ceo = $create('pi-ceo', [], ['dashboard'], 'ceo');
$ceoRoleOnly = $create('pi-ceo-role-only', [], ['dashboard'], 'ceo');
$reader = $create('pi-analytics-reader', [], ['dashboard'], 'analytics-reader');
$manager = $create('pi-ops-manager', [], ['dashboard'], 'operations-manager');
$dashboardEmployee = $create('pi-dashboard-employee', [], ['dashboard']);
$ok(T::call($kernel, 'PUT', '/management/organization/ceo', ['employeeId' => $ceo['employeeUuid']], ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'pi-ceo-' . $suffix], $root['cookie']), 'canonical CEO');

$canonical = static fn (string $orderUuid): array => [
    'order' => $pdo->query("SELECT order_uuid, version, production_version, production_stage_id, projection_hash, document_status, document_version, active_document_revision_uuid, workflow_cohort,
        cancellation_state, production_stopped_at, advance_status, source_pricing_fingerprint, operational_status FROM operational_orders WHERE order_uuid = " . $pdo->quote($orderUuid))->fetch(PDO::FETCH_ASSOC),
    'items' => $pdo->query('SELECT item_uuid, source_item_id, line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity, production_context, updated_at
        FROM operational_order_items WHERE order_uuid = ' . $pdo->quote($orderUuid) . ' ORDER BY line_number')->fetchAll(PDO::FETCH_ASSOC),
    'qr' => $pdo->query('SELECT qr_reference, status FROM order_qr_references WHERE order_uuid = ' . $pdo->quote($orderUuid) . ' ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC),
    'documents' => $pdo->query('SELECT COUNT(*) FROM production_document_blocks WHERE order_uuid = ' . $pdo->quote($orderUuid))->fetchColumn(),
    'confirmation' => $pdo->query('SELECT status, version FROM order_confirmations WHERE order_uuid = ' . $pdo->quote($orderUuid))->fetch(PDO::FETCH_ASSOC),
    'activity' => $pdo->query('SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ' . $pdo->quote($orderUuid))->fetchColumn(),
];
$sku = 'DV-PI-' . $suffix;
$items = static fn (array $extra = []): array => [
    ($extra[11] ?? []) + ['id' => 11, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => $sku, 'variant' => 'Culoare: Bej', 'color' => 'Bej', 'width' => 2.5, 'height' => 2.6, 'unit' => 'm', 'quantity' => 2,
        'options' => [['label' => 'buc', 'value' => '2 buc.'], ['label' => 'Manopera', 'value' => 'Manopera Rejansa Normal 6cm']]],
    ($extra[12] ?? []) + ['id' => 12, 'line' => 2, 'name' => 'Galerie Aluminiu', 'sku' => 'GAL-PI', 'width' => 3, 'unit' => 'm', 'quantity' => 1],
    ($extra[13] ?? []) + ['id' => 13, 'line' => 3, 'name' => 'Inele', 'sku' => 'INL-PI', 'quantity' => 10],
];
$basis = [11 => ['meterBasis' => ['kind' => 'fabric_linear', 'length' => 2.5]], 12 => ['meterBasis' => ['kind' => 'rail_linear', 'length' => 3]], 13 => ['meterBasis' => ['kind' => 'unit']]];
$at = static fn (int $secondsAgo): string => gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);
$orderId = "PI{$suffix}"; $global = "trendhome:{$orderId}";
$send = static function (string $event, string $changedAt, array $lineItems, string $availability = 'active') use ($kernel, $orderId): array {
    return T::ingest($kernel, 'trendhome', T::sourceOrder($orderId, "pi-{$event}-{$orderId}", $changedAt, null, 'processing', $availability, $lineItems));
};
$orderUuid = static function (string $globalId) use ($pdo): string { $s = $pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?'); $s->execute([$globalId]); return (string) $s->fetchColumn(); };

// ---- A. the fact is invisible to every production path ------------------------------------------------------
$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => $at(0)], null, null, 'heartbeat');
$check(in_array('meterBasis', $heartbeat['body']['orderFacts'] ?? [], true), 'A1 heartbeat announces meterBasis');
$check($send('v1', $at(600), $items())['body']['outcome'] === 'applied', 'A2 order without meter basis applied');
$uuid = $orderUuid($global);
// A printed production document is simulated as active so a staleness would be visible.
$pdo->prepare("UPDATE operational_orders SET document_status = 'active' WHERE order_uuid = ?")->execute([$uuid]);
$before = $canonical($uuid);
$resent = $send('v1-basis', $at(590), $items($basis));
$check($resent['status'] === 200 && $resent['body']['outcome'] === 'duplicate', 'A3 the same order with meterBasis is a duplicate, not a change: ' . json_encode($resent['body']));
$check($canonical($uuid) === $before, 'A4 order, version, hash, items, QR, document, Confirmare and activity are byte-identical');
$facts = $pdo->query('SELECT source_item_id, basis_kind, priced_length FROM order_item_meter_facts WHERE order_uuid = ' . $pdo->quote($uuid) . ' ORDER BY source_item_id')->fetchAll(PDO::FETCH_ASSOC);
$check($facts === [['source_item_id' => '11', 'basis_kind' => 'fabric_linear', 'priced_length' => '2.500'], ['source_item_id' => '12', 'basis_kind' => 'rail_linear', 'priced_length' => '3.000'],
    ['source_item_id' => '13', 'basis_kind' => 'unit', 'priced_length' => null]], 'A5 facts stored per line');
$check($send('v1-plain', $at(580), $items())['body']['outcome'] === 'duplicate', 'A6 an older shop build without the fact is still a duplicate');
$check($pdo->query('SELECT COUNT(*) FROM order_item_meter_facts WHERE order_uuid = ' . $pdo->quote($uuid))->fetchColumn() === 3, 'A7 absence never erases a stored fact');
foreach (['bad kind' => ['kind' => 'pleated'], 'length on unit' => ['kind' => 'unit', 'length' => 2], 'extra key' => ['kind' => 'unit', 'factor' => 2.2]] as $label => $bad) {
    $error($send('bad-' . md5($label), $at(570), $items([11 => ['meterBasis' => $bad]])), 422, 'SOURCE_PAYLOAD_INVALID', "A8 invalid meterBasis rejected ({$label})");
}
$check($canonical($uuid) === $before, 'A9 a rejected payload changed nothing');
// The simulated document was only a staleness probe; a real source change below needs the order without it.
$pdo->prepare("UPDATE operational_orders SET document_status = 'none' WHERE order_uuid = ?")->execute([$uuid]);

// ---- B. the sold-meter ledger ------------------------------------------------------------------------------
$fingerprint = static fn (): string => (string) $pdo->query("SELECT CONCAT((SELECT COUNT(*) FROM operational_orders), ':', (SELECT COALESCE(SUM(version), 0) FROM operational_orders), ':',
    (SELECT COUNT(*) FROM operational_order_items), ':', (SELECT COUNT(*) FROM order_activity_events), ':', (SELECT COUNT(*) FROM cutting_facts), ':', (SELECT COUNT(*) FROM order_item_meter_facts))")->fetchColumn();
$ledger = new SoldMeterLedger($pdo, $clock);
$sums = static fn (): array => array_column($pdo->query('SELECT metric, SUM(delta_meters) total FROM production_meter_ledger WHERE order_uuid = ' . $pdo->quote($uuid) . ' GROUP BY metric ORDER BY metric')->fetchAll(PDO::FETCH_ASSOC), 'total', 'metric');
$reasons = static fn (): array => $pdo->query('SELECT reason, metric, delta_meters FROM production_meter_ledger WHERE order_uuid = ' . $pdo->quote($uuid) . ' ORDER BY recorded_at, metric')->fetchAll(PDO::FETCH_ASSOC);
$run = static function (bool $dryRun, int $batch, string $order) use ($ledger, $fingerprint, $check): array {
    $before = $fingerprint();
    $report = $ledger->sync($dryRun, $batch, $order);
    $check($fingerprint() === $before, 'B0 a ledger run leaves orders, versions, items, activity, cutting facts and meter facts unchanged');
    return $report;
};
$dry = $run(true, 50, $uuid);
$check($dry['dryRun'] && $dry['entries'] === 2 && $dry['netUnits'] === ['sold_fabric' => 5000, 'sold_rail' => 3000], 'B1 dry run computes 5.000 m fabric (2.5 x 2) and 3.000 m rail: ' . json_encode($dry));
$check($sums() === [], 'B2 dry run writes nothing');
$first = $run(false, 50, $uuid);
$check($first['byReason'] === ['sale' => 2] && $sums() === ['sold_fabric' => '5.000', 'sold_rail' => '3.000'], 'B3 sale entries written');
$check($run(false, 50, $uuid)['entries'] === 0 && $run(false, 50, $uuid)['entries'] === 0 && $sums() === ['sold_fabric' => '5.000', 'sold_rail' => '3.000'], 'B4 repeated runs write nothing twice');
$check($send('v1-again', $at(560), $items($basis))['body']['outcome'] === 'duplicate' && $run(false, 50, $uuid)['entries'] === 0, 'B5 a re-sync of the same order writes nothing');
// A real source correction: L 2.5 -> 3.0 (printed width and pricing basis agree).
$check($send('v2', $at(500), $items([11 => ['width' => 3, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 3]]] + $basis))['body']['outcome'] === 'applied', 'B6 corrected order applied');
$check($run(false, 50, $uuid)['byReason'] === ['correction' => 1] && $sums()['sold_fabric'] === '6.000', 'B7 correction is one +1.000 m entry');
// The source states a length that disagrees with the printed width: never a value, the ledger withdraws the line.
$check($send('v2-conflict', $at(490), $items([11 => ['width' => 3, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 3.4]], 12 => $basis[12], 13 => $basis[13]]))['body']['outcome'] === 'duplicate', 'B8 conflicting fact received');
$check($run(false, 50, $uuid)['byReason'] === ['correction' => 1] && $sums()['sold_fabric'] === '0.000', 'B9 a conflict is not sold meters');
$check($send('v2-fixed', $at(480), $items([11 => ['width' => 3, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 3]]] + $basis))['body']['outcome'] === 'duplicate'
    && $run(false, 50, $uuid)['byReason'] === ['reactivation' => 1] && $sums()['sold_fabric'] === '6.000', 'B10 resolved fact returns as one entry');
$check($send('v3-cancel', $at(400), $items([11 => ['width' => 3, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 3]]] + $basis), 'cancelled')['body']['outcome'] === 'applied', 'B11 cancellation applied');
$cancel = $run(false, 50, $uuid);
$check($cancel['byReason'] === ['cancellation' => 2] && $sums() === ['sold_fabric' => '0.000', 'sold_rail' => '0.000'], 'B12 cancellation reverses fabric and rail');
$cancelAt = $pdo->query("SELECT occurred_at FROM production_meter_ledger WHERE order_uuid = " . $pdo->quote($uuid) . " AND reason = 'cancellation' LIMIT 1")->fetchColumn();
$reportedAt = $pdo->query('SELECT source_reported_unavailable_at FROM operational_orders WHERE order_uuid = ' . $pdo->quote($uuid))->fetchColumn();
$check($cancelAt === $reportedAt, 'B13 the cancellation is dated when the source reported it');
$check($run(false, 50, $uuid)['entries'] === 0, 'B14 a cancelled order is reversed once');
$check($send('v4-active', $at(300), $items([11 => ['width' => 3, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 3]]] + $basis))['body']['outcome'] === 'applied'
    && $run(false, 50, $uuid)['byReason'] === ['reactivation' => 2] && $sums() === ['sold_fabric' => '6.000', 'sold_rail' => '3.000'], 'B15 reactivation sells again once');
// The rail line leaves the order.
$check($send('v5-removed', $at(200), [$items([11 => ['width' => 3, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 3]]])[0], $items($basis)[2]])['body']['outcome'] === 'applied'
    && $run(false, 50, $uuid)['byReason'] === ['line_removed' => 1] && $sums()['sold_rail'] === '0.000', 'B16 a removed line is brought to zero');
// Unique key: the same transition can never be stored twice.
$row = $pdo->query('SELECT * FROM production_meter_ledger WHERE order_uuid = ' . $pdo->quote($uuid) . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
try {
    $pdo->prepare('INSERT INTO production_meter_ledger (entry_uuid, metric, order_uuid, item_uuid, source_key, delta_meters, reason, cause_key, formula_version, occurred_at, recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([Uuid::v4(), $row['metric'], $row['order_uuid'], $row['item_uuid'], $row['source_key'], $row['delta_meters'], $row['reason'], $row['cause_key'], 1, $row['occurred_at'], $row['recorded_at']]);
    $check(false, 'B17 duplicate cause rejected');
} catch (PDOException $duplicate) {
    $check((int) $duplicate->errorInfo[1] === 1062, 'B17 duplicate cause rejected by the unique key');
}
// One run at a time.
$other = Connection::create($config);
$other->query("SELECT GET_LOCK('arasya_sold_meter_ledger', 0)")->fetchColumn();
try { $ledger->sync(false, 50, $uuid); $check(false, 'B18 concurrent run refused'); } catch (RuntimeException $busy) { $check(str_contains($busy->getMessage(), 'in progress'), 'B18 concurrent run refused'); }
$other->query("SELECT RELEASE_LOCK('arasya_sold_meter_ledger')");
// Exclusion (K7): the order is kept and every sold entry is reversed.
$pdo->prepare("INSERT INTO production_meter_exclusions (order_uuid, reason, note, decided_by_label, decided_at) VALUES (?, 'test_order', NULL, 'integration', UTC_TIMESTAMP(6))")->execute([$uuid]);
$check($run(false, 50, $uuid)['byReason'] === ['exclusion' => 1] && $sums()['sold_fabric'] === '0.000', 'B19 exclusion reverses the sold meters');
$check((int) $pdo->query('SELECT COUNT(*) FROM operational_orders WHERE order_uuid = ' . $pdo->quote($uuid))->fetchColumn() === 1, 'B20 the excluded order still exists');
$pdo->prepare('DELETE FROM production_meter_exclusions WHERE order_uuid = ?')->execute([$uuid]);
$run(false, 50, $uuid);
$check($sums()['sold_fabric'] === '6.000', 'B21 lifting the exclusion sells again');
$check(array_sum(array_map(static fn (array $r): float => (float) $r['delta_meters'], array_filter($reasons(), static fn ($r) => $r['metric'] === 'sold_fabric'))) === 6.0, 'B22 the signed history sums to the current value');

// ---- C. production metrics ---------------------------------------------------------------------------------
$event = static function (array $who, string $order, string $action, string $from, ?string $to, int $versionAfter, int $secondsAgo) use ($pdo): void {
    $o = $pdo->query('SELECT global_order_id, source_key, order_number FROM operational_orders WHERE order_uuid = ' . $pdo->quote($order))->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('INSERT INTO order_activity_events (event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action, workflow_key, workflow_version, from_stage_id,
        from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot, production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NULL, ?, ?, UTC_TIMESTAMP(6) - INTERVAL ? SECOND)')
        ->execute([Uuid::v4(), $who['employeeUuid'], $order, $o['global_order_id'], $o['source_key'], $o['order_number'], $action, 'curtain-production', $from, $from, $to, $to, $versionAfter - 1, $versionAfter,
            'pi-test', 'pi-' . bin2hex(random_bytes(8)), $secondsAgo]);
};
// Fixture rows on the disposable database: a canonical history for the order (cut, recut, labeling, completion) and a
// legacy order that was cut but never completed in Arasya.
$event($cutter, $uuid, 'stage_completed', 'material-preparation', 'workshop-receiving', 20, 180);
$event($cutter, $uuid, 'stage_completed', 'material-preparation', 'workshop-receiving', 24, 150);
$event($labeler, $uuid, 'stage_completed', 'labeling', 'material-straightening', 25, 120);
$event($packer, $uuid, 'production_completed', 'delivery', null, 40, 60);
$legacyId = "PL{$suffix}";
T::ingest($kernel, 'outletperdele', T::sourceOrder($legacyId, "pi-legacy-{$legacyId}", $at(900), null, 'processing', 'active', [['id' => 21, 'line' => 1, 'name' => 'Perdea', 'width' => 4, 'unit' => 'm', 'quantity' => 1]]));
$legacy = $orderUuid("outletperdele:{$legacyId}");
$event($cutter, $legacy, 'stage_completed', 'material-preparation', 'workshop-receiving', 3, 100);
$range = ['from' => '2026-10-04', 'to' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')];
$summary = $ok($get($root, '/management/production-intelligence/summary', $range), 'C1 Root summary');
$check($summary['cut']['evidence'] === 'source_line' && $summary['cut']['measuredCutMeters'] === null, 'C2 cut meters are labelled as source line meters, never measured (K1)');
$check($summary['cut']['orders'] >= 2 && $summary['recut']['orders'] >= 1 && $summary['completed']['orders'] >= 1, 'C3 cut, recut and completion are separate');
$check($summary['shipped'] === ['available' => false] && $summary['delivered'] === ['available' => false], 'C4 shipping and delivery are not invented (K6)');
$check(($summary['coverage']['linesByStatus']['unknown_basis'] ?? 0) >= 1, 'C5 a line without a stated basis is counted as unknown, never zero');
$mine = $ok($get($cutter, '/production-intelligence/mine', $range), 'C6 cutter reads own work');
$stage = array_values(array_filter($mine['stages'], static fn ($r) => $r['stageId'] === 'material-preparation'))[0] ?? null;
$check($stage !== null && $stage['completions'] === 3 && $stage['cuts'] === 2 && $stage['recuts'] === 1 && $stage['fabricMeters'] === '12.000' && $stage['completionsWithoutMeters'] === 1,
    'C7 cutter: cut + recut of the 6.000 m order and a legacy cut without basis that credits no meter: ' . json_encode($stage));
$check(count(array_filter($mine['items'], static fn ($i) => $i['stageId'] !== 'material-preparation')) === 0, 'C8 the cutter sees only their own stage work');
$check(!array_key_exists('employeeId', $mine['stages'][0]) && !array_key_exists('employeeName', $mine['stages'][0]), 'C9 own view carries no employee identities');
$employees = $ok($get($ceo, '/management/production-intelligence/employees', $range), 'C10 canonical CEO reads employees');
$completedRow = array_values(array_filter($employees['items'], static fn ($r) => $r['employeeId'] === $packer['employeeUuid']))[0] ?? null;
$check($completedRow !== null && $completedRow['stageId'] === 'delivery' && $completedRow['fabricMeters'] === '6.000', 'C11 completion credits the packer once');
$check(count(array_filter($employees['items'], static fn ($r) => $r['employeeId'] === $cutter['employeeUuid'] && $r['stageId'] !== 'material-preparation')) === 0, 'C12 stages are never added together');
$products = $ok($get($root, '/management/production-intelligence/products', $range + ['source' => 'trendhome']), 'C13 products');
$product = array_values(array_filter($products['items'], static fn ($r) => $r['productCode'] === $sku))[0] ?? null;
$check($product !== null && $product['soldFabricMeters'] === '6.000' && $product['cutFabricMeters'] === '6.000' && $product['completedFabricMeters'] === '6.000', 'C14 product sold/cut/completed: ' . json_encode($product));
$legacyCompleted = (int) $pdo->query("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = " . $pdo->quote($legacy) . " AND action = 'production_completed'")->fetchColumn();
$opSummary = $ok($get($root, '/management/production-intelligence/summary', $range + ['source' => 'outletperdele']), 'C15 outlet summary');
$s = $pdo->prepare("SELECT COUNT(*) FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid WHERE o.source_key = 'outletperdele' AND e.action = 'production_completed'
    AND e.occurred_at >= ? AND e.occurred_at < ? AND NOT EXISTS (SELECT 1 FROM production_meter_exclusions x WHERE x.order_uuid = e.order_uuid)");
$s->execute([$opSummary['range']['fromUtc'], $opSummary['range']['toUtcExclusive']]);
$check($legacyCompleted === 0 && $opSummary['completed']['orders'] === (int) $s->fetchColumn() && $opSummary['cut']['ordersWithUnknownLines'] >= 1, 'C16 only Arasya completions count; a legacy order without one is not completed (K8)');
$series = $ok($get($root, '/management/production-intelligence/series', $range + ['bucket' => 'month']), 'C17 monthly series');
$check($series['bucket'] === 'month' && count($series['items']) >= 1 && preg_match('/^\d{4}-\d{2}$/D', $series['items'][0]['period']) === 1, 'C18 series bucketed by local month');
$check(str_contains(json_encode($summary + $employees + $products) ?: '', 'TEST Client') === false, 'C19 no customer identity in the reports');

// ---- D. security ---------------------------------------------------------------------------------------------
foreach (['summary', 'products', 'employees', 'series'] as $section) {
    $ok($get($root, "/management/production-intelligence/{$section}", $range), "D1 Root {$section}");
    $ok($get($ceo, "/management/production-intelligence/{$section}", $range), "D2 canonical CEO {$section}");
    foreach (['CEO role without the canonical principal' => $ceoRoleOnly, 'analytics-reader (analytics.view)' => $reader, 'operations manager' => $manager, 'dashboard employee' => $dashboardEmployee, 'staff cutter' => $cutter] as $label => $denied) {
        $error($get($denied, "/management/production-intelligence/{$section}", $range), 403, 'UNAUTHORIZED_ACTION', "D3 {$label} denied {$section}");
    }
}
$ok($get($reader, '/management/analytics/sources', ['period' => 'month']), 'D4 the analytics reader keeps the existing analytics only');
$error(T::call($kernel, 'GET', '/management/production-intelligence/summary', null, [], null, $range), 401, 'SESSION_EXPIRED', 'D5 no session');
$error(T::call($kernel, 'GET', '/production-intelligence/mine', null, [], null, $range), 401, 'SESSION_EXPIRED', 'D6 no session for own work');
$error(T::call($kernel, 'POST', '/management/production-intelligence/summary', [], ['x-csrf-token' => $root['csrf']], $root['cookie']), 404, 'NOT_FOUND', 'D7 read only');
$error($get($root, '/management/production-intelligence/summary', $range + ['employeeId' => $cutter['employeeUuid']]), 400, 'INVALID_REQUEST', 'D8 unknown query parameter');
$error($get($cutter, '/production-intelligence/mine', $range + ['employeeId' => $labeler['employeeUuid']]), 400, 'INVALID_REQUEST', 'D9 an employee cannot ask for another employee');
$error($get($root, '/management/production-intelligence/summary', $range + ['source' => "trendhome' OR '1'='1"]), 400, 'INVALID_REQUEST', 'D10 source is an allow-list');
$error($get($root, '/management/production-intelligence/summary', ['from' => '2026-10-01', 'to' => '2026-10-10']), 400, 'BEFORE_ANALYSIS_START', 'D11 main analysis starts 2026-10-04 (K3)');
$error($get($root, '/management/production-intelligence/summary', ['from' => '2026-10-04', 'to' => '2027-12-01']), 400, 'INVALID_DATE_RANGE', 'D12 bounded range');
$error($get($root, '/management/production-intelligence/series', $range + ['bucket' => 'hour']), 400, 'INVALID_REQUEST', 'D13 bucket allow-list');
$error($get($root, '/management/production-intelligence/orders', $range), 404, 'NOT_FOUND', 'D14 unknown section');
$audits = (int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production_intelligence.read' AND actor_employee_uuid IN (" . $pdo->quote($ceo['employeeUuid']) . ')')->fetchColumn();
$check($audits >= 5, 'D15 every management read is audited');
$check((int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production_intelligence.read' AND actor_employee_uuid = " . $pdo->quote($reader['employeeUuid']))->fetchColumn() === 0, 'D16 a denied read writes no report audit');
$check((int) $pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'production_intelligence%'")->fetchColumn() === 0, 'D17 no permission or role grants it');

// ---- E. the automatic incremental ledger run (cron: bin/meter-ledger.php --changed --apply) -----------------------
$eStart = $clock->now()->format('Y-m-d H:i:s.u');
$pdo->exec('DELETE FROM production_meter_ledger_cursor');
$sumsOf = static fn (string $order): array => array_column($pdo->query('SELECT metric, SUM(delta_meters) total FROM production_meter_ledger WHERE order_uuid = ' . $pdo->quote($order) . ' GROUP BY metric ORDER BY metric')->fetchAll(PDO::FETCH_ASSOC), 'total', 'metric');
$ledgerRows = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM production_meter_ledger')->fetchColumn();
$cursorRow = static fn () => $pdo->query("SELECT watermark_at, last_orders, last_entries FROM production_meter_ledger_cursor WHERE cursor_key = 'sold_meters'")->fetch(PDO::FETCH_ASSOC);
$sendAs = static function (string $id, string $event, string $changedAt, array $lineItems, string $availability = 'active') use ($kernel): array {
    return T::ingest($kernel, 'trendhome', T::sourceOrder($id, "pi-{$event}-{$id}", $changedAt, null, 'processing', $availability, $lineItems));
};
$rowsBefore = $ledgerRows();
$dryFirst = $ledger->syncChanged(true);
$check($dryFirst['initialised'] && $dryFirst['orders'] === 0 && $cursorRow() === false, 'E1 a dry first run stores nothing');
$first = $ledger->syncChanged(false);
$check($first['initialised'] && $first['orders'] === 0 && $first['entries'] === 0 && $ledgerRows() === $rowsBefore && $cursorRow() !== false, 'E2 the first automatic run only stores the watermark: no implicit backfill');
// A new web-shop order: intake writes the fact, never the ledger.
$newId = "PIE{$suffix}";
$check($sendAs($newId, 'e1', $at(30), $items($basis))['body']['outcome'] === 'applied', 'E3 new order applied');
$newUuid = $orderUuid("trendhome:{$newId}");
$check($ledgerRows() === $rowsBefore, 'E4 order intake writes no ledger entry (the ledger never runs inside order processing)');
$auto = $ledger->syncChanged(false);
$check(!$auto['initialised'] && $sumsOf($newUuid) === ['sold_fabric' => '5.000', 'sold_rail' => '3.000'] && $auto['orders'] >= 1, 'E5 the next automatic run books the new order: ' . json_encode($auto));
$check($cursorRow()['watermark_at'] === substr($auto['watermark'], 0, 26) || $cursorRow()['watermark_at'] === $auto['watermark'], 'E6 the watermark moved to the start of the run');
$again = $ledger->syncChanged(false);
$check($again['entries'] === 0 && $sumsOf($newUuid) === ['sold_fabric' => '5.000', 'sold_rail' => '3.000'], 'E7 an automatic run without changes writes nothing (overlap re-visits are free)');
// Cancellation at the source: one dated reversal by the next run.
$check($sendAs($newId, 'e2-cancel', $at(20), $items($basis), 'cancelled')['body']['outcome'] === 'applied', 'E8 cancellation applied');
$cancelRun = $ledger->syncChanged(false);
$check(($cancelRun['byReason']['cancellation'] ?? 0) === 2 && $sumsOf($newUuid) === ['sold_fabric' => '0.000', 'sold_rail' => '0.000'], 'E9 the cancellation is reversed automatically');
$check($ledger->syncChanged(false)['entries'] === 0, 'E10 and only once');
// A fact-only change (no order version) is still seen: the fact table is part of the change set.
$factId = "PIF{$suffix}";
$check($sendAs($factId, 'f1', $at(15), $items())['body']['outcome'] === 'applied', 'E11 order without facts applied');
$factUuid = $orderUuid("trendhome:{$factId}");
$ledger->syncChanged(false);
$check($sumsOf($factUuid) === [], 'E12 unknown basis books nothing');
$versionBefore = (int) $pdo->query('SELECT version FROM operational_orders WHERE order_uuid = ' . $pdo->quote($factUuid))->fetchColumn();
$check($sendAs($factId, 'f2', $at(14), $items($basis))['body']['outcome'] === 'duplicate', 'E13 the fact arrives later as a duplicate');
$ledger->syncChanged(false);
$check($sumsOf($factUuid) === ['sold_fabric' => '5.000', 'sold_rail' => '3.000'] && (int) $pdo->query('SELECT version FROM operational_orders WHERE order_uuid = ' . $pdo->quote($factUuid))->fetchColumn() === $versionBefore,
    'E14 the later fact is booked without touching the order version');
// An exclusion (K7) is seen through its decision time.
$pdo->prepare("INSERT INTO production_meter_exclusions (order_uuid, reason, note, decided_by_label, decided_at) VALUES (?, 'test_order', NULL, 'integration', ?)")->execute([$factUuid, $clock->now()->format('Y-m-d H:i:s.u')]);
$excluded = $ledger->syncChanged(false);
$check(($excluded['byReason']['exclusion'] ?? 0) === 2 && $sumsOf($factUuid) === ['sold_fabric' => '0.000', 'sold_rail' => '0.000'], 'E15 a new exclusion is reversed automatically');
// A transaction that started before a run and committed after it is inside the overlap window and is not skipped.
$lateId = "PIL{$suffix}";
$check($sendAs($lateId, 'l1', $at(10), $items($basis))['body']['outcome'] === 'applied', 'E16 late order applied');
$lateUuid = $orderUuid("trendhome:{$lateId}");
$watermark = (string) $cursorRow()['watermark_at'];
$backdated = (new DateTimeImmutable($watermark, new DateTimeZone('UTC')))->modify('-' . (SoldMeterLedger::OVERLAP_SECONDS - 60) . ' seconds')->format('Y-m-d H:i:s.u');
$pdo->prepare('UPDATE operational_orders SET updated_at = ? WHERE order_uuid = ?')->execute([$backdated, $lateUuid]);
$pdo->prepare('UPDATE order_item_meter_facts SET updated_at = ? WHERE order_uuid = ?')->execute([$backdated, $lateUuid]);
$ledger->syncChanged(false);
$check($sumsOf($lateUuid) === ['sold_fabric' => '5.000', 'sold_rail' => '3.000'], 'E17 a change committed after the watermark but stamped before it is still booked');
// Never a lock on a canonical row: an order transaction holding the row does not delay the run, and the run takes no row lock.
$writer = Connection::create($config);
$writer->beginTransaction();
$writer->query('SELECT order_uuid FROM operational_orders WHERE order_uuid = ' . $writer->quote($lateUuid) . ' FOR UPDATE')->fetchAll();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
// With a 1-second lock wait timeout, any row lock the run took on the held order would end in error 1205 (the check
// fails when FOR UPDATE is put back). Wall time is not measured: the run's length depends on how many orders changed.
try {
    $busyRun = $ledger->syncChanged(false);
    $check($busyRun['orders'] >= 1, 'E18 the run completes while an order transaction holds a canonical row');
} catch (PDOException $wait) {
    $check(false, 'E18 the run waited on a canonical row lock: ' . $wait->getMessage());
}
$writer->rollBack();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
// One run at a time, the incremental and the full run share the lock.
$other->query("SELECT GET_LOCK('arasya_sold_meter_ledger', 0)")->fetchColumn();
try { $ledger->syncChanged(false); $check(false, 'E19 concurrent automatic run refused'); } catch (RuntimeException $busy) { $check(str_contains($busy->getMessage(), 'in progress'), 'E19 concurrent automatic run refused'); }
$other->query("SELECT RELEASE_LOCK('arasya_sold_meter_ledger')");
$synced = $ok($get($root, '/management/production-intelligence/summary', $range), 'E20a summary')['sold']['syncedThrough'] ?? null;
$check(is_string($synced) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $synced) === 1, 'E20a the report states how fresh the sold meters are: ' . json_encode($synced));
$cursorBefore = $cursorRow();
$ledger->syncChanged(true);
$check($cursorRow() === $cursorBefore, 'E20 a dry automatic run does not move the watermark');
$before = $fingerprint();
$ledger->syncChanged(false);
$check($fingerprint() === $before, 'E21 the automatic run leaves orders, versions, items, activity, cutting facts and meter facts unchanged');

// Disposable database hygiene: the rollback suites read recent meter facts, and other suites delete their own orders
// (the ledger's foreign key would block them), so this suite removes every ledger row and fact it caused.
$pdo->prepare('DELETE FROM production_meter_ledger WHERE recorded_at >= ?')->execute([$eStart]);
$pdo->exec('DELETE FROM production_meter_ledger_cursor');
foreach ([$newUuid, $factUuid, $lateUuid] as $own) {
    foreach (['production_meter_ledger', 'order_item_meter_facts', 'production_meter_exclusions'] as $table) $pdo->prepare("DELETE FROM {$table} WHERE order_uuid = ?")->execute([$own]);
}
foreach ([$uuid, $legacy] as $own) {
    foreach (['production_meter_ledger', 'order_item_meter_facts', 'production_meter_exclusions'] as $table) $pdo->prepare("DELETE FROM {$table} WHERE order_uuid = ?")->execute([$own]);
}
echo "PASS {$checks} production intelligence integration assertions\n";
