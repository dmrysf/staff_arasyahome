<?php

declare(strict_types=1);

// CEO Production Intelligence (API 2.73.0) against a disposable MySQL test database:
// A. product categories: the import validates the whole file, leaves ambiguous codes out, is a dry run by default and
//    idempotent; reports classify lines by shop catalog, Trendyol code match / prepared kind, or unknown;
// B. completions by date (Europe/Bucharest): only the FIRST production completion counts, a recompletion is apart and no
//    line is counted twice; excluded orders never count; cancellation after completion is flagged; every breakdown
//    (source, category, SKU, employee, series) sums to the totals; stage work stays apart from completions;
// C. Trendyol panel width: verified lines only (size agrees in every source, marketplace quantity equals the line);
//    conflicts, missing sizes and quantity mismatches are unknown; never added to TH / OP fabric;
// D. overview: received, worked, completed, current state (waiting / in production / delayed / stopped / legacy) and
//    ledger-dependent figures unavailable (never zero) while the ledger is empty;
// E. SKU analysis: filters (source, category, code, variant, status), populations by their own event date, detail series;
// F. security: Root and the canonical CEO only, read only, no customer data, strict query validation.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Intelligence\MeterContract;
use Arasya\Operations\Intelligence\ProductCategoryImport;
use Arasya\Operations\Intelligence\SoldMeterLedger;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP CEO production intelligence integration: test database not configured\n"; exit(0); }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label) use ($check): array { $check($r['status'] === 200, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body']['error'] ?? null)); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): void { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body']['error'] ?? null)); };
$container = new Container($config, $pdo); $kernel = $container->kernel(); $clock = $container->clock();
$suffix = bin2hex(random_bytes(5)); $password = 'ceo intelligence passphrase 2026';
$pdo->exec('DELETE FROM system_root_identity');
$rootName = 'ceo.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('ceo-test', $rootName);
$root = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => $password], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'root password');
$root = T::login($kernel, $rootName, $password);
$get = static fn (array $who, string $section, array $query = []): array => T::call($kernel, 'GET', '/management/production-intelligence/' . $section, null, [], $who['cookie'], $query);
$create = static function (string $name, array $stages, array $apps, string $role = 'employee') use ($container, $root, $password, $suffix, $pdo, $kernel, $ok): array {
    $e = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'ceo-test');
    $ok(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/applications", ['applications' => $apps], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'application access');
    if ($role !== 'employee') {
        $s = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = ?'); $s->execute([$role]);
        $ok(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/roles", ['roleIds' => [(int) $s->fetchColumn()]], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'role ' . $role);
    }
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $e->employeeUuid];
};
$cutter = $create('ceo-cutter', ['material-preparation'], ['staff']);
$labeler = $create('ceo-labeler', ['labeling'], ['staff']);
$packer = $create('ceo-packer', ['delivery'], ['staff']);
$packer2 = $create('ceo-packer-two', ['delivery'], ['staff']);
$ceo = $create('ceo-ceo', [], ['dashboard'], 'ceo');
$ceoRoleOnly = $create('ceo-role-only', [], ['dashboard'], 'ceo');
$reader = $create('ceo-analytics-reader', [], ['dashboard'], 'analytics-reader');
$manager = $create('ceo-ops-manager', [], ['dashboard'], 'operations-manager');
$ok(T::call($kernel, 'PUT', '/management/organization/ceo', ['employeeId' => $ceo['employeeUuid']], ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'ceo-ceo-' . $suffix], $root['cookie']), 'canonical CEO');

// ---- fixtures (disposable database) -------------------------------------------------------------------------
$orderUuid = static function (string $globalId) use ($pdo): string { $s = $pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?'); $s->execute([$globalId]); return (string) $s->fetchColumn(); };
$ingest = static function (string $source, string $id, array $items, string $availability = 'active', ?array $delivery = null) use ($kernel, $check, $orderUuid): string {
    $payload = T::sourceOrder($id, "ceo-{$id}-" . bin2hex(random_bytes(3)), gmdate('Y-m-d\TH:i:s\Z', time() - 3600), null, 'processing', $availability, $items);
    if ($delivery !== null) {
        $payload['order']['delivery'] = $delivery;
    }
    $r = T::ingest($kernel, $source, $payload);
    $check($r['status'] === 200 && in_array($r['body']['outcome'] ?? '', ['applied', 'duplicate'], true), "fixture order {$id}: " . json_encode($r['body']));
    return $orderUuid("{$source}:{$id}");
};
$set = static function (string $order, array $fields) use ($pdo): void {
    $sql = implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($fields)));
    $pdo->prepare("UPDATE operational_orders SET {$sql} WHERE order_uuid = ?")->execute([...array_values($fields), $order]);
};
$event = static function (array $who, string $order, string $action, string $from, ?string $to, int $versionAfter, string $utc) use ($pdo): void {
    $o = $pdo->query('SELECT global_order_id, source_key, order_number FROM operational_orders WHERE order_uuid = ' . $pdo->quote($order))->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('INSERT INTO order_activity_events (event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action, workflow_key, workflow_version, from_stage_id,
        from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot, production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)')
        ->execute([Uuid::v4(), $who['employeeUuid'], $order, $o['global_order_id'], $o['source_key'], $o['order_number'], $action, 'curtain-production', $from, $from, $to, $to, $versionAfter - 1, $versionAfter,
            'ceo-test', 'ceo-' . bin2hex(random_bytes(8)), $utc]);
};
$basis = static fn (string $kind, ?float $length = null): array => ['meterBasis' => $length === null ? ['kind' => $kind] : ['kind' => $kind, 'length' => $length]];
$secretName = 'TEST Client Ceo ' . $suffix;
// Each run uses its own October/November in a random future year, so reruns and other suites sharing the database never
// fall into the same days. D0 is five days before the end of summer time (EEST, UTC+3); POST is eight days after it (EET, UTC+2).
$year = random_int(2100, 9000);
$dstEnd = new DateTimeImmutable("last sunday of october {$year}", new DateTimeZone('UTC'));
$d0 = $dstEnd->modify('-5 days'); $post = $dstEnd->modify('+8 days');
$at = static fn (int $offset, string $time, ?DateTimeImmutable $base = null): string => ($base ?? $d0)->modify("{$offset} days")->format('Y-m-d') . ' ' . $time;
$day = static fn (int $offset, ?DateTimeImmutable $base = null): string => ($base ?? $d0)->modify("{$offset} days")->format('Y-m-d');

// TH1: received D0 10:00Z, cut + labeled on D0, first completion D0 21:30Z (= D1 00:30 Bucharest, EEST), back to production
// and completed again on D2.
$th1 = $ingest('trendhome', "CEOT1{$suffix}", [
    ['id' => 11, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => "SKU-A{$suffix}", 'variant' => 'Bej', 'width' => 2.5, 'height' => 2.6, 'unit' => 'm', 'quantity' => 2] + $basis('fabric_linear', 2.5),
    ['id' => 12, 'line' => 2, 'name' => 'Galerie', 'sku' => "SKU-R{$suffix}", 'width' => 3, 'unit' => 'm', 'quantity' => 1] + $basis('rail_linear', 3),
    ['id' => 13, 'line' => 3, 'name' => 'Inele', 'sku' => "SKU-U{$suffix}", 'quantity' => 10] + $basis('unit'),
], 'active', ['name' => $secretName, 'street' => 'Str. Secreta 9', 'city' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0722999888']);
$set($th1, ['source_submitted_at' => $at(0, '10:00:00'), 'production_completed_at' => $at(0, '21:30:00')]);
$event($cutter, $th1, 'stage_completed', 'material-preparation', 'workshop-receiving', 3, $at(0, '12:00:00'));
$event($labeler, $th1, 'stage_completed', 'labeling', 'material-straightening', 4, $at(0, '13:00:00'));
$event($packer, $th1, 'production_completed', 'delivery', null, 9, $at(0, '21:30:00'));
$event($packer, $th1, 'production_completed', 'delivery', null, 14, $at(2, '09:00:00'));
// OP1: received and completed on D1; one fabric line (code not in any catalog), one line without a pricing basis.
$op1 = $ingest('outletperdele', "CEOO1{$suffix}", [
    ['id' => 21, 'line' => 1, 'name' => 'Perdea In', 'sku' => "SKU-B{$suffix}", 'width' => 4, 'unit' => 'm', 'quantity' => 1] + $basis('fabric_linear', 4),
    ['id' => 22, 'line' => 2, 'name' => 'Perdea Fara Baza', 'sku' => "SKU-N{$suffix}", 'width' => 2, 'unit' => 'm', 'quantity' => 1],
]);
$set($op1, ['source_submitted_at' => $at(1, '08:00:00'), 'production_completed_at' => $at(1, '15:00:00')]);
$event($packer, $op1, 'production_completed', 'delivery', null, 9, $at(1, '15:00:00'));
// OP2: completed on D1, cancelled by the shop afterwards (still a completion, flagged).
$op2 = $ingest('outletperdele', "CEOO2{$suffix}", [['id' => 41, 'line' => 1, 'name' => 'Perdea Voal', 'sku' => "SKU-B{$suffix}", 'width' => 1.5, 'unit' => 'm', 'quantity' => 2] + $basis('fabric_linear', 1.5)]);
$set($op2, ['source_submitted_at' => $at(1, '07:00:00'), 'production_completed_at' => $at(1, '10:00:00'), 'operational_status' => 'unavailable']);
$event($packer2, $op2, 'production_completed', 'delivery', null, 9, $at(1, '10:00:00'));
// OP3: received D1 and cancelled without production.
$op3 = $ingest('outletperdele', "CEOO3{$suffix}", [['id' => 51, 'line' => 1, 'name' => 'Perdea Anulata', 'sku' => "SKU-X{$suffix}", 'width' => 2, 'unit' => 'm', 'quantity' => 1] + $basis('fabric_linear', 2)]);
$set($op3, ['source_submitted_at' => $at(1, '09:00:00'), 'operational_status' => 'unavailable']);
// TY1: a Trendyol order (fixture: ingested through a web shop, then moved to the trendyol source with its released package).
$ty1 = $ingest('trendhome', "CEOY1{$suffix}", [
    ['id' => 31, 'line' => 1, 'name' => 'Velvet Curtain', 'sku' => "TY-RAW{$suffix}", 'variant' => '140x260', 'width' => 140, 'height' => 260, 'unit' => 'cm', 'quantity' => 2],
    ['id' => 32, 'line' => 2, 'name' => 'Tulle Curtain', 'sku' => "TY-C{$suffix}", 'width' => 300, 'height' => 250, 'unit' => 'cm', 'quantity' => 1],
    ['id' => 33, 'line' => 3, 'name' => 'Curtain Without Size', 'sku' => "TY-N{$suffix}", 'quantity' => 1],
    ['id' => 34, 'line' => 4, 'name' => 'Blackout Curtain', 'sku' => "TY-Q{$suffix}", 'width' => 200, 'height' => 260, 'unit' => 'cm', 'quantity' => 3],
]);
$set($ty1, ['source_key' => 'trendyol', 'source_submitted_at' => $at(1, '06:00:00'), 'production_completed_at' => $at(1, '18:00:00')]);
$package = random_int(10_000_000, 99_999_999);
$pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, lines_hash, first_seen_at, last_seen_at, released_order_uuid, released_by_employee_uuid, released_at)
    VALUES (?, ?, 'released', 'Picking', 1, 1, ?, ?, ?, ?, ?, ?)")->execute([$package, "TY{$suffix}", random_bytes(32), $at(1, '05:00:00'), $at(1, '05:00:00'), $ty1, $cutter['employeeUuid'], $at(1, '06:00:00')]);
$tyLine = $pdo->prepare('INSERT INTO trendyol_package_lines (package_id, line_id, line_number, product_name, stock_code, product_code, product_size, quantity, prepared_kind, prepared_width_cm, prepared_height_cm, prepared_by_employee_uuid, prepared_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$prep = $at(1, '05:30:00');
$tyLine->execute([$package, 31, 1, 'Velvet Curtain', 'merchantSku', "SKU-A{$suffix}", '140x260', 2, 'drapery', '140.000', '260.000', $cutter['employeeUuid'], $prep]);
$tyLine->execute([$package, 32, 2, 'Tulle Curtain', 'merchantSku', null, '300x250', 1, 'curtain', '300.000', '240.000', $cutter['employeeUuid'], $prep]);
$tyLine->execute([$package, 33, 3, 'Curtain Without Size', 'merchantSku', null, null, 1, 'curtain', null, null, $cutter['employeeUuid'], $prep]);
$tyLine->execute([$package, 34, 4, 'Blackout Curtain', 'merchantSku', null, '200x260', 2, 'other', '200.000', '260.000', $cutter['employeeUuid'], $prep]);
$event($packer2, $ty1, 'production_completed', 'delivery', null, 9, $at(1, '18:00:00'));
// TY2: a Trendyol order completed on D3 and returned by the customer afterwards (still a completion, reported apart).
$ty2 = $ingest('trendhome', "CEOY2{$suffix}", [['id' => 91, 'line' => 1, 'name' => 'Returned Curtain', 'sku' => "TY-R{$suffix}", 'variant' => '100x250', 'width' => 100, 'height' => 250, 'unit' => 'cm', 'quantity' => 1]]);
$set($ty2, ['source_key' => 'trendyol', 'source_commerce_status_code' => 'UnDeliveredAndReturned', 'source_submitted_at' => $at(3, '06:00:00'), 'production_completed_at' => $at(3, '12:00:00')]);
$package2 = $package + 1;
$pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, lines_hash, first_seen_at, last_seen_at, released_order_uuid, released_by_employee_uuid, released_at)
    VALUES (?, ?, 'released', 'UnDeliveredAndReturned', 1, 1, ?, ?, ?, ?, ?, ?)")->execute([$package2, "TY2{$suffix}", random_bytes(32), $at(3, '05:00:00'), $at(3, '05:00:00'), $ty2, $cutter['employeeUuid'], $at(3, '06:00:00')]);
$tyLine->execute([$package2, 91, 1, 'Returned Curtain', 'merchantSku', null, '100x250', 1, 'curtain', '100.000', '250.000', $cutter['employeeUuid'], $at(3, '05:30:00')]);
$event($packer2, $ty2, 'production_completed', 'delivery', null, 9, $at(3, '12:00:00'));
// TH2: an excluded test order completed the same day; it must never appear.
$th2 = $ingest('trendhome', "CEOT2{$suffix}", [['id' => 61, 'line' => 1, 'name' => 'Draperie Test', 'sku' => "SKU-A{$suffix}", 'width' => 9, 'unit' => 'm', 'quantity' => 9] + $basis('fabric_linear', 9)]);
$set($th2, ['source_submitted_at' => $at(1, '11:00:00'), 'production_completed_at' => $at(1, '12:00:00')]);
$event($packer, $th2, 'production_completed', 'delivery', null, 9, $at(1, '12:00:00'));
$pdo->prepare("INSERT INTO production_meter_exclusions (order_uuid, reason, note, decided_by_label, decided_at) VALUES (?, 'test_order', NULL, 'integration', UTC_TIMESTAMP(6))")->execute([$th2]);
// TH3: completed after the DST change (POST 22:30Z = POST+1 00:30 Bucharest, EET).
$th3 = $ingest('trendhome', "CEOT3{$suffix}", [['id' => 71, 'line' => 1, 'name' => 'Draperie Iarna', 'sku' => "SKU-A{$suffix}", 'width' => 2, 'unit' => 'm', 'quantity' => 1] + $basis('fabric_linear', 2)]);
$set($th3, ['source_submitted_at' => $at(0, '08:00:00', $post), 'production_completed_at' => $at(0, '22:30:00', $post)]);
$event($packer, $th3, 'production_completed', 'delivery', null, 9, $at(0, '22:30:00', $post));

// ---- A. product categories ------------------------------------------------------------------------------------
$catalog = static fn (string $source, array $products): string => json_encode(['format' => 'arasya-product-categories', 'formatVersion' => 1, 'source' => $source, 'generatedAt' => '2026-11-15T08:00:00Z', 'products' => $products], JSON_THROW_ON_ERROR);
$thCatalog = $catalog('trendhome', [
    ['code' => "SKU-A{$suffix}", 'name' => 'Draperie Velvet Premium', 'category' => 'Draperii', 'subcategory' => 'Velvet', 'rule' => 'single_main_category'],
    ['code' => "SKU-R{$suffix}", 'name' => 'Galerie Aluminiu', 'category' => 'Galerii', 'subcategory' => null, 'rule' => 'primary_category'],
    ['code' => "SKU-U{$suffix}", 'name' => 'Inele', 'category' => 'Accesorii', 'rule' => 'single_main_category'],
    ['code' => "SKU-AMB{$suffix}", 'name' => 'Ambiguu', 'category' => 'Perdele', 'rule' => 'single_main_category'],
    ['code' => "sku-amb{$suffix}", 'name' => 'Ambiguu', 'category' => 'Draperii', 'rule' => 'single_main_category'],
]);
$importer = new ProductCategoryImport($pdo, $clock);
foreach (['bad format' => str_replace('arasya-product-categories', 'other', $thCatalog), 'wrong source' => $catalog('outletperdele', []),
    'bad rule' => $catalog('trendhome', [['code' => 'X', 'category' => 'Y', 'rule' => 'guess']]), 'extra key' => $catalog('trendhome', [['code' => 'X', 'category' => 'Y', 'rule' => 'primary_category', 'title' => 'z']]),
    'empty category' => $catalog('trendhome', [['code' => 'X', 'category' => ' ', 'rule' => 'primary_category']])] as $label => $bad) {
    try { $importer->run('trendhome', $bad, false); $check(false, "A1 rejects {$label}"); } catch (InvalidArgumentException) { $check(true, "A1 rejects {$label}"); }
}
$catalogRows = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM product_catalog_categories WHERE product_code LIKE '%{$suffix}'")->fetchColumn();
$dry = $importer->run('trendhome', $thCatalog, true);
$check($dry['dryRun'] && $dry['products'] === 3 && $dry['ambiguous'] === 1 && $dry['added'] === 3 && $catalogRows() === 0, 'A2 dry run counts 3 products, 1 ambiguous code, writes nothing: ' . json_encode($dry));
$applied = $importer->run('trendhome', $thCatalog, false);
$check($applied['added'] === 3 && $catalogRows() === 3 && $applied['byCategory'] === ['Accesorii' => 1, 'Draperii' => 1, 'Galerii' => 1], 'A3 apply writes 3 categories');
$again = $importer->run('trendhome', $thCatalog, false);
$check($again['added'] === 0 && $again['changed'] === 0 && $again['unchanged'] === 3 && $catalogRows() === 3, 'A4 the same file again writes nothing');
$fingerprint = static fn (): string => (string) $pdo->query("SELECT CONCAT((SELECT COUNT(*) FROM operational_orders), ':', (SELECT COALESCE(SUM(version), 0) FROM operational_orders), ':', (SELECT COUNT(*) FROM operational_order_items), ':',
    (SELECT COUNT(*) FROM order_activity_events), ':', (SELECT COUNT(*) FROM order_item_meter_facts), ':', (SELECT COUNT(*) FROM production_meter_ledger), ':', (SELECT COUNT(*) FROM production_meter_exclusions), ':',
    (SELECT COUNT(*) FROM trendyol_package_lines), ':', (SELECT COUNT(*) FROM product_catalog_categories))")->fetchColumn();

// ---- B. completions by date --------------------------------------------------------------------------------
$sum = static fn (array $rows, string $field): string => MeterContract::meters(array_sum(array_map(static fn (array $r): int => (int) MeterContract::units($r[$field]), $rows)));
$day21 = $ok($get($ceo, 'completions', ['from' => $day(1), 'to' => $day(1)]), 'B0 completions D1');
$t = $day21['totals'];
$check($t['orders'] === 4, 'B1 four orders completed on D1 Bucharest (TH1 at 21:30Z on D0, OP1, OP2, TY1; excluded TH2 left out): ' . json_encode($t));
$check($t['units'] === 13 + 2 + 2 + 7, 'B2 completed units 24');
$check($t['fabricMeters'] === '12.000' && $t['railMeters'] === '3.000', 'B3 fabric TH 5 + OP 4 + OP2 3 = 12.000 m, rail 3.000 m apart: ' . json_encode([$t['fabricMeters'], $t['railMeters']]));
$check($t['panelWidthMeters'] === '2.800' && $t['panelLines'] === 1 && $t['panelUnknownLines'] === 3 && $t['panelUnknownUnits'] === 5, 'C1 Trendyol panel 1.40 m x 2 = 2.800 m; conflict, no size and quantity mismatch unknown (5 units)');
$check($t['unknownLines'] === 1 && $t['unknownUnits'] === 1 && $t['notMeteredLines'] === 1, 'B4 the OP line without a basis is unknown, the unit line not metered');
$check($t['recompletions'] === ['orders' => 0, 'events' => 0] && $t['cancelledAfterCompletion'] === 1, 'B5 no recompletion that day; OP2 flagged as cancelled after completion');
$after1 = $day21['afterCompletion'];
$check($after1['cancelled']['orders'] === 1 && $after1['cancelled']['fabricMeters'] === '3.000' && $after1['returned']['orders'] === 0 && $after1['net']['fabricMeters'] === '9.000' && $after1['net']['panelWidthMeters'] === '2.800',
    'B5b OP2 cancelled after completion reported apart; net leaves it out: ' . json_encode($after1));
$day23 = $ok($get($ceo, 'completions', ['from' => $day(3), 'to' => $day(3)]), 'B5c completions D3');
$check($day23['totals']['panelWidthMeters'] === '1.000' && $day23['afterCompletion']['returned']['panelWidthMeters'] === '1.000' && $day23['afterCompletion']['net']['panelWidthMeters'] === '0.000'
    && $day23['afterCompletion']['returned']['orders'] === 1, 'B5c a Trendyol order returned after completion: completed 1.000 m, returned 1.000 m, net 0');
foreach ([$day21, $day23] as $report) {
    $parts = [$report['afterCompletion']['net'], $report['afterCompletion']['cancelled'], $report['afterCompletion']['returned']];
    $check($sum($parts, 'fabricMeters') === $report['totals']['fabricMeters'] && $sum($parts, 'panelWidthMeters') === $report['totals']['panelWidthMeters'] && array_sum(array_column($parts, 'orders')) === $report['totals']['orders'],
        'B5d net + cancelled + returned = completed');
}
$day20 = $ok($get($ceo, 'completions', ['from' => $day(0), 'to' => $day(0)]), 'B6 completions D0');
$check($day20['totals']['orders'] === 0, 'B6 a completion at 21:30Z on the 20th belongs to the 21st in Bucharest');
$day22 = $ok($get($ceo, 'completions', ['from' => $day(2), 'to' => $day(2)]), 'B7 completions 2026-10-22');
$check($day22['totals']['orders'] === 0 && $day22['totals']['fabricMeters'] === '0.000' && $day22['totals']['recompletions'] === ['orders' => 1, 'events' => 1], 'B7 the second completion of TH1 is a recompletion with no meters');
$span = $ok($get($ceo, 'completions', ['from' => $day(0), 'to' => $day(2), 'source' => 'trendhome']), 'B8 completions 20-22 trendhome');
$check($span['totals']['orders'] === 1 && $span['totals']['fabricMeters'] === '5.000' && $span['totals']['recompletions']['events'] === 1, 'B8 TH1 counted once over the span (5.000 m, not 10.000)');
$nov = $ok($get($ceo, 'completions', ['from' => $day(1, $post), 'to' => $day(1, $post)]), 'B9 completions 2026-11-03');
$check($nov['totals']['orders'] === 1 && $nov['totals']['fabricMeters'] === '2.000', 'B9 after the DST change 22:30Z belongs to the next Bucharest day (EET)');
$check($ok($get($ceo, 'completions', ['from' => $day(0, $post), 'to' => $day(0, $post)]), 'B9b')['totals']['orders'] === 0, 'B9b and not to the UTC day');
// Breakdowns sum to the totals.
foreach (['bySource' => array_values($day21['bySource']), 'byCategory' => $day21['byCategory'], 'bySku' => $day21['bySku']['items'], 'completionCredits' => $day21['completionCredits'], 'series' => $day21['series']] as $name => $rows) {
    $check($sum($rows, 'fabricMeters') === $t['fabricMeters'] && $sum($rows, 'railMeters') === $t['railMeters'] && $sum($rows, 'panelWidthMeters') === $t['panelWidthMeters']
        && array_sum(array_column($rows, 'units')) === $t['units'] && array_sum(array_column($rows, 'unknownLines')) === $t['unknownLines'], "B10 {$name} sums to the totals");
}
$check(array_sum(array_column(array_values($day21['bySource']), 'orders')) === $t['orders'] && array_sum(array_column($day21['series'], 'orders')) === $t['orders'], 'B11 orders per source and per day sum to the total');
$check(count($day21['series']) === 1 && $day21['series'][0]['period'] === $day(1), 'B12 one daily point labelled with the Bucharest day');
$check($day21['bySource']['trendhome']['fabricMeters'] === '5.000' && $day21['bySource']['outletperdele']['fabricMeters'] === '7.000' && $day21['bySource']['trendyol']['fabricMeters'] === '0.000'
    && $day21['bySource']['trendyol']['panelWidthMeters'] === '2.800' && $day21['bySource']['trendhome']['panelWidthMeters'] === '0.000', 'C2 panel width only on Trendyol; Trendyol carries no fabric');
$credits = array_column($day21['completionCredits'], null, 'employeeId');
$check($credits[$packer['employeeUuid']]['orders'] === 2 && $credits[$packer2['employeeUuid']]['orders'] === 2, 'B13 completion credits per employee (first completion only)');
$check($day21['stageWork'] === [], 'B14 no intermediate stage work on the 21st');
$stage20 = array_column($day20['stageWork'], null, 'stageId');
$check(count($day20['stageWork']) === 2 && $stage20['material-preparation']['completions'] === 1 && $stage20['labeling']['completions'] === 1 && $day20['completionCredits'] === [], 'B15 stage work reported apart from completions');
$cats = [];
foreach ($day21['byCategory'] as $row) { $cats[$row['source'] . '|' . $row['category']] = $row; }
$check($cats['trendhome|Draperii']['fabricMeters'] === '5.000' && $cats['trendhome|Galerii']['railMeters'] === '3.000' && $cats['trendhome|Accesorii']['units'] === 10, 'A5 shop catalog categories');
$check($cats['outletperdele|unknown']['fabricMeters'] === '7.000' && $cats['outletperdele|unknown']['categorySources'] === ['none'], 'A6 a code without a catalog row is unknown, never guessed');
$check($cats['trendyol|Draperii']['panelWidthMeters'] === '2.800' && $cats['trendyol|Draperii']['categorySources'] === ['shop_catalog_code_match'], 'A7 Trendyol seller code matched to one catalog category');
$check($cats['trendyol|Perdele']['lines'] === 2 && $cats['trendyol|Alte']['lines'] === 1 && $cats['trendyol|Perdele']['categorySources'] === ['trendyol_prepared_kind'], 'A8 otherwise the prepared kind');
$skus = [];
foreach ($day21['bySku']['items'] as $row) { $skus[$row['source'] . '|' . $row['productCode']] = $row; }
$check($skus["trendhome|SKU-A{$suffix}"]['name'] === 'Draperie Velvet Premium' && $skus["trendhome|SKU-A{$suffix}"]['evidence'] === ['shop_fact_event' => 1], 'A9 SKU named from the catalog with its meter evidence');
$check($skus["trendyol|SKU-A{$suffix}"]['evidence'] === ['trendyol_panel_verified' => 1] && !isset($skus["trendyol|TY-RAW{$suffix}"]), 'C3 the resolved Trendyol seller code names the line, not the raw marketplace code');
$check($day21['quality']['panelLinesByStatus'] === ['conflict' => 1, 'no_measure' => 1, 'quantity_mismatch' => 1, 'verified' => 1], 'C4 panel status per line: ' . json_encode($day21['quality']['panelLinesByStatus']));
$check($day21['quality']['meterLinesByStatus'] === ['measured_source' => 4, 'not_metered' => 1, 'unknown_basis' => 1], 'B16 meter status per shop line: ' . json_encode($day21['quality']['meterLinesByStatus']));
$weekly = $ok($get($ceo, 'completions', ['from' => $day(-1), 'to' => $day(3, $post), 'bucket' => 'week']), 'B17 weekly');
$weeks = array_values(array_unique([$d0->modify('+1 day')->format('o-\\WW'), $post->modify('+1 day')->format('o-\\WW')]));
$check(array_column($weekly['series'], 'period') === $weeks && $sum($weekly['series'], 'fabricMeters') === $weekly['totals']['fabricMeters'], 'B17 weekly buckets sum to the totals');

// ---- D. overview -------------------------------------------------------------------------------------------
$over = $ok($get($ceo, 'overview', ['from' => $day(1), 'to' => $day(1)]), 'D0 overview 2026-10-21');
$check($over['received']['orders'] === 4 && $over['received']['bySource']['outletperdele']['orders'] === 3 && $over['received']['bySource']['trendyol']['orders'] === 1 && $over['received']['cancelled'] === 2,
    'D1 received on the 21st: OP1, OP2, OP3, TY1 (TH2 excluded); two cancelled: ' . json_encode($over['received']));
$check($over['received']['units'] === 2 + 2 + 1 + 7, 'D2 received units');
$check($over['worked']['orders'] === 4 && $over['worked']['byArea']['production'] === 4 && $over['worked']['byArea']['trendyol'] === 1, 'D3 orders with a real employee action on the 21st: ' . json_encode($over['worked']));
$check($over['completed']['orders'] === 4 && $over['completed']['fabricMeters'] === '12.000' && $over['completed']['bySource']['trendyol']['panelWidthMeters'] === '2.800'
    && $over['completed']['bySource']['trendhome']['fabricMeters'] === '5.000' && $over['completed']['bySource']['outletperdele']['fabricMeters'] === '7.000', 'D4 completed meters per channel, panel apart');
// API 2.76.0: the ledger is empty only when neither the shop ledger nor the B2B ledger holds an entry and no automatic run exists.
if ((int) $pdo->query('SELECT COUNT(*) FROM production_meter_ledger')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM b2b_sold_meter_ledger')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM production_meter_ledger_cursor')->fetchColumn() === 0) {
    $check($over['ledger'] === ['state' => 'empty', 'available' => false, 'syncedThrough' => null, 'ordered' => null, 'verifiedSale' => null], 'D5 empty ledger: unavailable, never zero');
} else {
    $check($over['ledger']['available'] === true && $over['ledger']['ordered']['fabricMeters'] === '0.000', 'D5 a ledger with entries (shared database) is available; nothing booked in this range yet');
}
$check(isset($over['generatedAt'], $over['lastActivityAt'], $over['refresh']['suggestedSeconds']) && $over['definitions']['delayHours'] === 48 && $over['definitions']['categoryCatalog'] === true, 'D6 freshness and definitions');
// Current state: deltas, because other suites share the database.
$before = $over['current'];
$waitingOld = $ingest('outletperdele', "CEOW1{$suffix}", [['id' => 81, 'line' => 1, 'name' => 'Perdea', 'quantity' => 1]]);
$set($waitingOld, ['workflow_cohort' => 'confirmare', 'workflow_cohort_reason' => 'ceo-test', 'workflow_cohort_at' => '2026-11-01 00:00:00', 'production_stage_id' => 'waiting', 'production_changed_at' => gmdate('Y-m-d H:i:s', time() - 72 * 3600)]);
$working = $ingest('outletperdele', "CEOW2{$suffix}", [['id' => 82, 'line' => 1, 'name' => 'Perdea', 'quantity' => 1]]);
$set($working, ['workflow_cohort' => 'confirmare', 'workflow_cohort_reason' => 'ceo-test', 'workflow_cohort_at' => '2026-11-01 00:00:00', 'production_stage_id' => 'labeling', 'production_changed_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
$stuck = $ingest('outletperdele', "CEOW3{$suffix}", [['id' => 83, 'line' => 1, 'name' => 'Perdea', 'quantity' => 1]]);
$set($stuck, ['workflow_cohort' => 'confirmare', 'workflow_cohort_reason' => 'ceo-test', 'workflow_cohort_at' => '2026-11-01 00:00:00', 'production_stage_id' => 'side-hem', 'production_changed_at' => gmdate('Y-m-d H:i:s', time() - 50 * 3600)]);
$stopped = $ingest('outletperdele', "CEOW4{$suffix}", [['id' => 84, 'line' => 1, 'name' => 'Perdea', 'quantity' => 1]]);
$set($stopped, ['workflow_cohort' => 'confirmare', 'workflow_cohort_reason' => 'ceo-test', 'workflow_cohort_at' => '2026-11-01 00:00:00', 'production_stage_id' => 'labeling', 'production_stopped_at' => gmdate('Y-m-d H:i:s')]);
$legacy = $ingest('outletperdele', "CEOW5{$suffix}", [['id' => 85, 'line' => 1, 'name' => 'Perdea', 'quantity' => 1]]);
$set($legacy, ['workflow_cohort' => 'legacy', 'workflow_cohort_reason' => 'ceo-test', 'workflow_cohort_at' => '2026-11-01 00:00:00', 'production_stage_id' => 'waiting']);
$after = $ok($get($ceo, 'overview', ['period' => 'today']), 'D7 overview today')['current'];
$d = static fn (string $k): int => $after[$k] - $before[$k];
$check($d('waiting') === 1 && $d('inProduction') === 2 && $d('delayed') === 2 && $d('delayedWaiting') === 1 && $d('delayedInProduction') === 1 && $d('stopped') === 1 && $d('legacyActive') === 1,
    'D7 current: +1 waiting (delayed), +2 in production (1 delayed past 48 h), +1 stopped, +1 legacy apart: ' . json_encode([$before, $after]));
$check(($after['delayedByStage']['side-hem'] ?? 0) - ($before['delayedByStage']['side-hem'] ?? 0) === 1 && array_key_first($after['byStage']) === 'waiting', 'D8 delayed by stage, stages in workflow order');
// Once the ledger holds entries, ledger figures are available (here one order booked by an explicit run).
$fpBefore = $fingerprint();
(new SoldMeterLedger($pdo, $clock))->sync(false, 50, $op1);
$withLedger = $ok($get($ceo, 'overview', ['from' => $day(1), 'to' => $day(1)]), 'D9 overview with ledger');
$check(in_array($withLedger['ledger']['state'], ['manual', 'automatic'], true) && $withLedger['ledger']['available'] === true && $withLedger['ledger']['ordered']['fabricMeters'] === '4.000' && $withLedger['ledger']['verifiedSale']['fabricMeters'] === '0.000',
    'D9 manual ledger: ordered 4.000 m, verified sale 0 (payment unknown is not paid): ' . json_encode($withLedger['ledger']));
$check($fingerprint() !== $fpBefore, 'D9b the fixture ledger run wrote (test setup only)');

// ---- E. SKU analysis ---------------------------------------------------------------------------------------
$range = ['from' => $day(0), 'to' => $day(1)];
$all = $ok($get($ceo, 'skus', $range), 'E0 skus');
$rows = [];
foreach ($all['items'] as $row) { $rows[$row['source'] . '|' . $row['productCode']] = $row; }
$a = $rows["trendhome|SKU-A{$suffix}"];
$check($a['orders'] === 1 && $a['units'] === 2 && $a['processedFabricMeters'] === '5.000' && $a['completedFabricMeters'] === '5.000' && $a['category'] === 'Draperii' && $a['completedOrders'] === 1,
    'E1 TH SKU-A: received on the 20th, processed on the 20th, completed on the 21st, counted once each: ' . json_encode($a));
$b = $rows["outletperdele|SKU-B{$suffix}"];
$check($b['orders'] === 2 && $b['units'] === 3 && $b['completedFabricMeters'] === '7.000' && $b['processedFabricMeters'] === '0.000' && $b['category'] === 'unknown', 'E2 OP SKU-B over two orders');
$check($rows["outletperdele|SKU-N{$suffix}"]['unknownByPopulation'] === ['received' => 1, 'processed' => 0, 'completed' => 1], 'E3 unknown lines per population');
$check($rows["trendyol|SKU-A{$suffix}"]['completedPanelWidthMeters'] === '2.800' && $rows["trendyol|SKU-A{$suffix}"]['completedFabricMeters'] === '0.000', 'C5 SKU panel width apart from fabric');
$check($a['units'] === 2 && $a['lines'] === 1, 'E4 excluded TH2 (9 units of the same code) never in the SKU');
$check(in_array('Draperii', $all['categories'], true) && in_array('unknown', $all['categories'], true), 'E5 category filter values listed');
$draperii = $ok($get($ceo, 'skus', $range + ['category' => 'Draperii']), 'E6 category filter');
$check(array_map(static fn ($r) => $r['source'] . '|' . $r['productCode'], array_values(array_filter($draperii['items'], static fn ($r) => str_ends_with((string) $r['productCode'], $suffix)))) === ["trendhome|SKU-A{$suffix}", "trendyol|SKU-A{$suffix}"], 'E6 Draperii: TH SKU-A and Trendyol SKU-A');
foreach ($draperii['items'] as $row) { $check($row['category'] === 'Draperii', 'E6b only Draperii rows'); }
$rail = $ok($get($ceo, 'skus', $range + ['code' => "sku-r{$suffix}"]), 'E7 code filter');
$check(count($rail['items']) === 1 && $rail['items'][0]['processedRailMeters'] === '3.000' && $rail['items'][0]['processedFabricMeters'] === '0.000', 'E7 code filter, rail apart');
$cancelled = $ok($get($ceo, 'skus', $range + ['status' => 'cancelled', 'source' => 'outletperdele']), 'E8 status filter');
$codes = array_column($cancelled['items'], 'productCode');
$check(in_array("SKU-X{$suffix}", $codes, true) && !in_array("SKU-B{$suffix}", $codes, true), 'E8 cancelled without production: OP3 only (OP2 was completed before its cancellation)');
$variant = $ok($get($ceo, 'skus', $range + ['variant' => '140x26']), 'E9 variant filter');
$check(count(array_filter($variant['items'], static fn ($r) => str_ends_with((string) $r['productCode'], $suffix))) === 1, 'E9 variant filter');
$detail = $ok($get($ceo, 'skus', $range + ['sku' => "SKU-A{$suffix}", 'source' => 'trendhome']), 'E10 SKU detail');
$days = array_column($detail['detail']['day'], null, 'period');
$check(count($detail['items']) === 1 && $days[$day(0)]['received']['units'] === 2 && $days[$day(0)]['processed']['fabricMeters'] === '5.000' && $days[$day(1)]['completed']['fabricMeters'] === '5.000'
    && $detail['detail']['month'][0]['period'] === substr($day(0), 0, 7), 'E10 SKU detail by day and month');
$error($get($ceo, 'skus', $range + ['status' => 'shipped']), 400, 'INVALID_REQUEST', 'E11 unknown status');
$error($get($ceo, 'summary', $range + ['category' => 'Draperii']), 400, 'INVALID_REQUEST', 'E12 SKU filters only on skus');
$error($get($ceo, 'overview', $range + ['bucket' => 'day']), 400, 'INVALID_REQUEST', 'E13 no bucket on overview');
$error($get($ceo, 'completions', $range + ['bucket' => 'year']), 400, 'INVALID_REQUEST', 'E14 completions buckets day/week/month');
$error($get($ceo, 'skus', $range + ['code' => str_repeat('x', 121)]), 400, 'INVALID_REQUEST', 'E15 long filter');
$error($get($ceo, 'completions', ['from' => '2023-12-30', 'to' => '2024-01-02']), 400, 'BEFORE_ANALYSIS_START', 'E16 no range before the history floor (API 2.76.0)');

// ---- F. security -------------------------------------------------------------------------------------------
foreach (['overview', 'completions', 'skus'] as $section) {
    $ok($get($root, $section, $range), "F1 root reads {$section}");
    foreach (['ceo role without the canonical principal' => $ceoRoleOnly, 'analytics reader' => $reader, 'operations manager' => $manager, 'staff employee' => $packer] as $label => $who) {
        $r = $get($who, $section, $range);
        $check(in_array($r['status'], [403], true), "F2 {$label} refused on {$section}: {$r['status']}");
    }
    $check(T::call($kernel, 'GET', '/management/production-intelligence/' . $section, null, [], null, $range)['status'] === 401, "F3 anonymous refused on {$section}");
    $check(T::call($kernel, 'POST', '/management/production-intelligence/' . $section, [], ['x-csrf-token' => $ceo['csrf']], $ceo['cookie'])['status'] === 404, "F4 {$section} is read only");
}
$fp = $fingerprint();
$bodies = [];
foreach (['overview', 'completions', 'skus'] as $section) {
    $bodies[] = json_encode($ok($get($ceo, $section, ['from' => $day(0), 'to' => $day(2)]), "F5 {$section}"), JSON_UNESCAPED_UNICODE);
}
$check($fingerprint() === $fp, 'F5 reads change no order, item, activity, fact, ledger, exclusion, Trendyol line or category');
foreach ($bodies as $body) {
    $check(!str_contains($body, $secretName) && !str_contains($body, 'Secreta') && !str_contains($body, '0722999888') && !str_contains($body, 'orderNumber'), 'F6 no customer data or order numbers in the reports');
}
$audit = (int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production_intelligence.read' AND target_id IN ('overview', 'completions', 'skus')")->fetchColumn();
$check($audit >= 9, 'F7 every read is audited');

echo "PASS {$checks} CEO production intelligence integration assertions\n";
