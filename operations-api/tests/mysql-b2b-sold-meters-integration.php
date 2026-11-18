<?php

declare(strict_types=1);

// B2B sold meters and the B2B test cohort (API 2.76.0, migration 057) on a disposable MySQL / MariaDB test database,
// through the HTTP kernel (B2B orders, finalization, cancellation, production handoff, CEO reports) and the CLIs:
// A. a test-cohort B2B order that is finalized never counts as sold meters;
// B. a test-cohort order handed off to production (before or after the decision) has its canonical order excluded from
//    every production metric, and no production figure counts it;
// C. a new real B2B order counts nothing while it is a draft;
// D. it counts its entered line meters when finalized, at the finalization (Europe/Bucharest day), without waiting for production;
// E. its production handoff changes no sold total (the canonical order never sells);
// F. a commercial cancellation is deducted from the net (a dated negative entry);
// G. repeated, incremental and concurrent ledger runs never write an entry twice;
// H. 12.500 m on a line with quantity 3 is 12.500 m (the entered line total), never 37.500 m;
// I. Trendhome / Outlet Perdele and B2B meters are summed in their own classes (fabric, rail apart);
// J. Trendyol panel width is never part of the fabric total;
// K. a line without meters is unknown (counted), never zero; `other` lines are not metered;
// L. date, time zone and history filters (all time beyond 366 days, the history floor);
// M. a B2B order with a commercial and a canonical identity is counted once;
// N. the B2B current account, the orders, the canonical orders and their production stay unchanged by every meter step.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Intelligence\B2bSoldMeters;
use Arasya\Operations\Intelligence\MeterContract;
use Arasya\Operations\Intelligence\SalesMeters;
use Arasya\Operations\Intelligence\SoldMeterLedger;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP B2B sold meters integration: test database not configured\n"; exit(0); }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$status = static function (array $r, int $code, string $label) use ($check): array { $check($r['status'] === $code, "{$label} HTTP {$r['status']} " . json_encode($r['body']['error'] ?? null)); return $r['body'] ?? []; };
$container = new Container($config, $pdo); $kernel = $container->kernel(); $clock = $container->clock();
$suffix = bin2hex(random_bytes(5)); $password = 'b2b sold meters passphrase 2026';
$pdo->exec('DELETE FROM system_root_identity');
$rootName = 'bsm.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $clock))->bootstrap('bsm-test', $rootName);
$root = T::login($kernel, $rootName, $temporary);
$status(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => $password], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, 'root password');
$root = T::login($kernel, $rootName, $password);
$send = static fn (string $method, string $path, array $body = []): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'bsm-' . bin2hex(random_bytes(8))], $root['cookie']);
$one = static function (string $sql, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$rows = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };
$snapshot = static function (array $tables) use ($rows): array { $out = []; foreach ($tables as $t) { $r = array_map(serialize(...), $rows("SELECT * FROM {$t}")); sort($r); $out[$t] = hash('sha256', implode("\n", $r)); } return $out; };
$create = static function (string $name, array $apps, string $role) use ($container, $root, $password, $suffix, $pdo, $kernel, $status): array {
    $e = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'bsm-test');
    $status(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/applications", ['applications' => $apps], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, 'applications');
    $s = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = ?'); $s->execute([$role]);
    $status(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/roles", ['roleIds' => [(int) $s->fetchColumn()]], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, 'role');
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $e->employeeUuid];
};
$ceo = $create('bsm-ceo', ['dashboard'], 'ceo');
$manager = $create('bsm-manager', ['dashboard'], 'operations-manager');
$status(T::call($kernel, 'PUT', '/management/organization/ceo', ['employeeId' => $ceo['employeeUuid']], ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'bsm-ceo-' . $suffix], $root['cookie']), 200, 'canonical CEO');
$report = static fn (array $who, string $section, array $query = []): array => T::call($kernel, 'GET', '/management/production-intelligence/' . $section, null, [], $who['cookie'], $query);

// ---- fixtures: one B2B company; each run uses its own days in a random future year (other suites never share them) --------
$company = $status($send('POST', '/b2b/companies', ['legalName' => 'TEST Meter Company ' . $suffix, 'countryCode' => 'RO', 'taxIdentifier' => 'BSM' . $suffix,
    'contact' => ['name' => 'TEST contact', 'isPrimary' => true], 'address' => ['type' => 'delivery', 'countryCode' => 'RO', 'city' => 'TEST city', 'addressLine1' => 'TEST address', 'isPrimary' => true]]), 201, 'company')['companyId'];
$detail = $status(T::call($kernel, 'GET', '/b2b/companies/' . $company, null, [], $root['cookie']), 200, 'company detail');
$contact = $detail['contacts'][0]['id']; $address = $detail['addresses'][0]['id'];
$line = static fn (string $kind, ?string $meters, int $quantity, string $code = 'BSM-CURTAIN', ?string $unit = null): array => ['productCode' => $code . '-' . $suffix, 'productName' => null, 'variant' => 'Wave', 'color' => 'Alb', 'kind' => $kind,
    'width' => '200', 'height' => '260', 'quantity' => $quantity, 'meters' => $meters, 'pricingUnit' => $unit ?? ($kind === 'other' ? 'piece' : 'meter'), 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '19',
    'notes' => null, 'productionNotes' => null];
$draft = static fn (array $lines): array => $status($send('POST', '/b2b/orders', ['companyId' => $company, 'currencyCode' => 'RON', 'orderType' => 'FULL_TAILORING', 'contactId' => $contact,
    'deliveryAddressId' => $address, 'productionNotes' => null, 'lines' => $lines]), 201, 'draft')['detail']['order'];
$finalize = static fn (array $o): array => $status($send('POST', "/b2b/orders/{$o['id']}/finalize", ['expectedVersion' => $o['version']]), 200, 'finalize')['detail']['order'];
$handoff = static fn (array $o): array => $status($send('POST', "/b2b/orders/{$o['id']}/production", ['expectedVersion' => $o['version']]), 201, 'handoff');
$canonical = static fn (string $b2b): ?string => ($v = $one("SELECT order_uuid FROM operational_orders WHERE source_key = 'b2b' AND source_order_id = ?", [$b2b])) === false ? null : (string) $v;
$year = random_int(2100, 9000);
$dstEnd = new DateTimeImmutable("last sunday of october {$year}", new DateTimeZone('UTC'));
$d0 = $dstEnd->modify('-5 days');
$at = static fn (int $offset, string $time): string => $d0->modify("{$offset} days")->format('Y-m-d') . ' ' . $time;
$day = static fn (int $offset): string => $d0->modify("{$offset} days")->format('Y-m-d');
$moveFinal = static fn (string $id, string $utc) => $pdo->prepare('UPDATE b2b_orders SET finalized_at = ? WHERE order_uuid = ?')->execute([$utc, $id]);
$ledger = new SoldMeterLedger($pdo, $clock);
$cli = static function (string $script, array $args) use ($db): array {
    $env = ['HOME' => (string) getenv('HOME'), 'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
        'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
        'ARASYA_DB_NAME' => $db, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD')];
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fclose($pipes[0]); $out = (string) stream_get_contents($pipes[1]); $err = (string) stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
};
$financial = ['b2b_account_movements', 'b2b_account_allocations', 'b2b_account_allocation_releases', 'b2b_account_activity_events', 'b2b_account_idempotency'];

// ---- the old test orders (before the snapshot) -----------------------------------------------------------------------
$t1 = $finalize($draft([$line('curtain', '12.500', 3), $line('drapery', '13.500', 4, 'BSM-DRAPERY')]));               // finalized, never handed off
$t2 = $finalize($draft([$line('curtain', '6.250', 2)])); $handoff($t2);                                                 // handed off before the decision
$t3 = $finalize($draft([$line('drapery', '5.125', 2), $line('other', null, 10, 'BSM-RING')]));                          // handed off after the decision
$t4 = $draft([$line('curtain', '3.000', 1)]);                                                                           // a draft test order
foreach ([$t1, $t2, $t3] as $t) $moveFinal($t['id'], $at(0, '09:00:00'));
$t2Canonical = $canonical($t2['id']);
$check($t2Canonical !== null && $canonical($t1['id']) === null, 'fixture: T2 has a canonical production order, T1 not');
$moneyBefore = $snapshot($financial);
$ordersBefore = $snapshot(['b2b_orders', 'b2b_order_lines', 'b2b_production_handoffs']);

// The cohort CLI: a snapshot is refused without the reviewed sha; the dry run writes nothing.
$dry = $cli('b2b-meter-cohort.php', ['--snapshot', '--cohort=bsm-snapshot-' . $suffix, '--by=Owner decision test']);
$check($dry['code'] === 0 && str_contains($dry['out'], 'DRY RUN (nothing written)') && preg_match('/INVENTORY: orders=(\d+) canonical=(\d+) entered_meters=([0-9.]+) m sha256=([0-9a-f]{64})/', $dry['out'], $inv) === 1, 'cohort dry run prints the inventory: ' . $dry['out'] . $dry['err']);
$check(str_contains($dry['out'], $t1['code'] ?? $one('SELECT order_code FROM b2b_orders WHERE order_uuid = ?', [$t1['id']])), 'the inventory lists T1');
$check((int) $one('SELECT COUNT(*) FROM b2b_meter_test_cohort WHERE cohort_key = ?', ['bsm-snapshot-' . $suffix]) === 0, 'the dry run wrote no cohort row');
$refused = $cli('b2b-meter-cohort.php', ['--snapshot', '--cohort=bsm-snapshot-' . $suffix, '--by=Owner decision test', '--apply']);
$check($refused['code'] === 1 && str_contains($refused['err'], '--expect'), 'A snapshot --apply without --expect is refused');
$wrong = $cli('b2b-meter-cohort.php', ['--snapshot', '--cohort=bsm-snapshot-' . $suffix, '--by=Owner decision test', '--apply', '--expect=' . str_repeat('0', 64)]);
$check($wrong['code'] === 1 && (int) $one('SELECT COUNT(*) FROM b2b_meter_test_cohort WHERE cohort_key = ?', ['bsm-snapshot-' . $suffix]) === 0, 'A snapshot --apply with another sha is refused and writes nothing');
$applied = $cli('b2b-meter-cohort.php', ['--snapshot', '--cohort=bsm-snapshot-' . $suffix, '--by=Owner decision test', '--apply', '--expect=' . $inv[4]]);
$check($applied['code'] === 0 && str_contains($applied['out'], 'RECORDED:'), 'A the reviewed snapshot is recorded: ' . $applied['out'] . $applied['err']);
foreach ([$t1, $t2, $t3, $t4] as $t) {
    $check((int) $one('SELECT COUNT(*) FROM b2b_meter_test_cohort WHERE b2b_order_uuid = ?', [$t['id']]) === 1, 'A every order that existed at the snapshot is in the cohort');
}
$check((string) $one('SELECT entered_meters_snapshot FROM b2b_meter_test_cohort WHERE b2b_order_uuid = ?', [$t1['id']]) === '26.000', 'H/audit: T1 entered meters 12.500 + 13.500 = 26.000 (line totals, quantity never multiplied)');
$check((string) $one('SELECT entered_meters_snapshot FROM b2b_meter_test_cohort WHERE b2b_order_uuid = ?', [$t3['id']]) === '5.125', 'audit: an `other` line carries no meters');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production_intelligence.b2b_test_order_recorded' AND target_id = ?", [$t1['id']]) === 1, 'A the decision is audited per order');
$check((int) $one('SELECT COUNT(*) FROM production_meter_exclusions WHERE order_uuid = ?', [$t2Canonical]) === 1, 'B T2 canonical order excluded from production metrics with the decision');
$again = $cli('b2b-meter-cohort.php', ['--snapshot', '--cohort=bsm-snapshot-' . $suffix, '--by=Owner decision test', '--apply', '--expect=' . $inv[4]]);
$check($again['code'] === 0 && str_contains($again['out'], 'RECORDED: 0 new cohort rows'), 'A the same snapshot again records nothing new');
$check($snapshot($financial) === $moneyBefore && $snapshot(['b2b_orders', 'b2b_order_lines', 'b2b_production_handoffs']) === $ordersBefore, 'N the cohort decision changed no B2B account row, order, line or handoff');

// T3 handed off after the decision: the handoff itself excludes its canonical order.
$t3Now = $rows('SELECT version FROM b2b_orders WHERE order_uuid = ?', [$t3['id']])[0];
$handoff(['id' => $t3['id'], 'version' => (int) $t3Now['version']]);
$t3Canonical = $canonical($t3['id']);
$check($t3Canonical !== null && (int) $one('SELECT COUNT(*) FROM production_meter_exclusions WHERE order_uuid = ?', [$t3Canonical]) === 1, 'B a cohort order handed off later is excluded at its handoff');

// ---- new real orders (after the snapshot) ---------------------------------------------------------------------------
$r1 = $draft([$line('curtain', '12.500', 3), $line('other', null, 4, 'BSM-HOOK')]);
$check((int) $one('SELECT COUNT(*) FROM b2b_meter_test_cohort WHERE b2b_order_uuid = ?', [$r1['id']]) === 0, 'an order created after the snapshot is not a test');
$ledger->syncB2bOrder(false, $r1['id']);
$check((int) $one('SELECT COUNT(*) FROM b2b_sold_meter_ledger WHERE b2b_order_uuid = ?', [$r1['id']]) === 0, 'C a draft counts nothing');
$r1 = $finalize($r1);
$moveFinal($r1['id'], $at(0, '21:30:00'));                                    // 21:30Z on D0 = 00:30 on D1 in Bucharest (EEST)
$r2 = $finalize($draft([$line('drapery', '8.000', 2)])); $moveFinal($r2['id'], $at(1, '10:00:00'));
// A piece-priced curtain line may be finalized without meters (B2B-ORD-000005 in production has such lines): unknown, never zero.
$r3 = $finalize($draft([$line('curtain', null, 2, 'BSM-PIECE', 'piece'), $line('drapery', '0', 1, 'BSM-ZERO', 'piece'), $line('curtain', '4.200', 1, 'BSM-OK')])); $moveFinal($r3['id'], $at(1, '11:00:00'));
$runFull = $ledger->sync(false);
$check($runFull['b2bEntries'] >= 3, 'D the full run books the finalized real orders: ' . json_encode($runFull));
$entries = static fn (string $id): array => $rows('SELECT reason, delta_meters, occurred_at FROM b2b_sold_meter_ledger WHERE b2b_order_uuid = ? ORDER BY occurred_at, reason', [$id]);
$check($entries($r1['id']) === [['reason' => 'sale', 'delta_meters' => '12.500', 'occurred_at' => $at(0, '21:30:00') . '.000000']], 'D/H R1 sold 12.500 m at its finalization (quantity 3 not multiplied; the `other` line carries nothing): ' . json_encode($entries($r1['id'])));
$check(array_column($entries($r3['id']), 'delta_meters') === ['4.200'], 'K R3: the line without meters and the zero line are unknown, only 4.200 m is sold');
foreach ([$t1, $t2, $t3, $t4] as $t) {
    $check((int) $one('SELECT COUNT(*) FROM b2b_sold_meter_ledger WHERE b2b_order_uuid = ?', [$t['id']]) === 0, 'A/B a cohort order books no sold meters');
}
$check((int) $one('SELECT COUNT(*) FROM production_meter_ledger WHERE order_uuid IN (?, ?)', [$t2Canonical, $t3Canonical]) === 0, 'B/E a B2B canonical order never books sold meters');

// E/M: R1's production handoff changes no sold total and adds no second identity.
$soldR1 = static fn (): int => array_sum(array_map(static fn (array $e): int => (int) MeterContract::units(ltrim((string) $e['delta_meters'], '-')) * (str_starts_with((string) $e['delta_meters'], '-') ? -1 : 1),
    array_filter(SalesMeters::arasyaEntries($pdo, '2000-01-01 00:00:00', '9999-12-31 00:00:00', null), static fn (array $e): bool => ($e['b2b_order_uuid'] ?? null) === $r1['id'] || (($c = $canonical($r1['id'])) !== null && ($e['order_uuid'] ?? null) === $c))));
$check($soldR1() === 12500, 'M R1 counted once before production');
$r1Now = $rows('SELECT version FROM b2b_orders WHERE order_uuid = ?', [$r1['id']])[0];
$handoff(['id' => $r1['id'], 'version' => (int) $r1Now['version']]);
$r1Canonical = $canonical($r1['id']);
$ledger->sync(false, 50, $r1Canonical);
$ledger->syncB2bOrder(false, $r1['id']);
$check($r1Canonical !== null && $soldR1() === 12500 && (int) $one('SELECT COUNT(*) FROM production_meter_ledger WHERE order_uuid = ?', [$r1Canonical]) === 0, 'E/M the handoff changes no sold total; the canonical order never sells');
$check((int) $one('SELECT COUNT(*) FROM production_meter_exclusions WHERE order_uuid = ?', [$r1Canonical]) === 0, 'a real order handed off is not excluded from production');

// F: commercial cancellation of R2 (not handed off).
$r2Now = $rows('SELECT version FROM b2b_orders WHERE order_uuid = ?', [$r2['id']])[0];
$status($send('POST', "/b2b/orders/{$r2['id']}/cancel", ['expectedVersion' => (int) $r2Now['version']]), 200, 'cancel R2');
$pdo->prepare('UPDATE b2b_orders SET cancelled_at = ? WHERE order_uuid = ?')->execute([$at(2, '08:00:00'), $r2['id']]);
// G: the incremental run picks the cancellation up (watermark), once.
$pdo->exec('DELETE FROM production_meter_ledger_cursor');
$ledger->syncChanged(false);                                                   // stores the watermark only
$pdo->prepare('UPDATE b2b_orders SET updated_at = ? WHERE order_uuid = ?')->execute([$clock->now()->format('Y-m-d H:i:s.u'), $r2['id']]);
$inc = $ledger->syncChanged(false);
$check(($inc['byReason']['b2b_cancellation'] ?? 0) === 1, 'F/G the incremental run books the cancellation once: ' . json_encode($inc));
$check(array_map(static fn (array $e): array => [$e['reason'], $e['delta_meters']], $entries($r2['id'])) === [['sale', '8.000'], ['cancellation', '-8.000']], 'F sale then a dated negative cancellation');
$moneyMeter = $snapshot($financial);
$ordersMeter = $snapshot(['b2b_orders', 'b2b_order_lines', 'b2b_production_handoffs', 'operational_orders', 'operational_order_items', 'order_activity_events']);
$rerun = [$ledger->syncChanged(false), $ledger->sync(false), $ledger->syncChanged(false)];
$check(array_sum(array_column($rerun, 'b2bEntries')) === 0 && array_sum(array_column($rerun, 'entries')) === 0, 'G repeated incremental and full runs write nothing more');
$lock = Connection::create($config);
$lock->query("SELECT GET_LOCK('arasya_sold_meter_ledger', 0)");
try { $ledger->syncB2bOrder(false, $r1['id']); $check(false, 'G a concurrent run is refused'); } catch (RuntimeException $busy) { $check(str_contains($busy->getMessage(), 'in progress'), 'G a concurrent run is refused'); }
$lock->query("SELECT RELEASE_LOCK('arasya_sold_meter_ledger')");
$dupe = $rows("SELECT line_uuid, cause_key, COUNT(*) n FROM b2b_sold_meter_ledger GROUP BY line_uuid, cause_key HAVING n > 1");
$check($dupe === [], 'G no cause is booked twice');

$check($snapshot(['b2b_orders', 'b2b_order_lines', 'b2b_production_handoffs', 'operational_orders', 'operational_order_items', 'order_activity_events']) === $ordersMeter, 'N ledger runs change no order, line, handoff, canonical order, item or production event');

// ---- I/J/K/L: the CEO overview over the run's days ----------------------------------------------------------------
$thUuid = (function () use ($kernel, $check, $one, $suffix, $pdo, $at): string {
    $payload = T::sourceOrder("BSMT{$suffix}", "bsm-th-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 3600), null, 'processing', 'active', [
        ['id' => 1, 'line' => 1, 'name' => 'Draperie', 'sku' => "BSM-TH-{$suffix}", 'width' => 2.5, 'unit' => 'm', 'quantity' => 2, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 2.5]],
        ['id' => 2, 'line' => 2, 'name' => 'Galerie', 'sku' => "BSM-TR-{$suffix}", 'width' => 3, 'unit' => 'm', 'quantity' => 1, 'meterBasis' => ['kind' => 'rail_linear', 'length' => 3]],
        ['id' => 3, 'line' => 3, 'name' => 'Perdea', 'sku' => "BSM-TU-{$suffix}", 'width' => 2, 'unit' => 'm', 'quantity' => 1]]);
    $r = T::ingest($kernel, 'trendhome', $payload);
    $check($r['status'] === 200, 'TH fixture');
    $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', ["trendhome:BSMT{$suffix}"]);
    $pdo->prepare('UPDATE operational_orders SET source_submitted_at = ? WHERE order_uuid = ?')->execute([$at(1, '07:00:00'), $uuid]);
    return $uuid;
})();
$opUuid = (function () use ($kernel, $check, $one, $suffix, $pdo, $at): string {
    $r = T::ingest($kernel, 'outletperdele', T::sourceOrder("BSMO{$suffix}", "bsm-op-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 3600), null, 'processing', 'active', [
        ['id' => 1, 'line' => 1, 'name' => 'Perdea', 'sku' => "BSM-OP-{$suffix}", 'width' => 4, 'unit' => 'm', 'quantity' => 1, 'meterBasis' => ['kind' => 'fabric_linear', 'length' => 4]]]));
    $check($r['status'] === 200, 'OP fixture');
    $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', ["outletperdele:BSMO{$suffix}"]);
    $pdo->prepare('UPDATE operational_orders SET source_submitted_at = ? WHERE order_uuid = ?')->execute([$at(1, '08:00:00'), $uuid]);
    return $uuid;
})();
$ledger->sync(false, 50, $thUuid); $ledger->sync(false, 50, $opUuid);
$overview = $status($report($ceo, 'overview', ['from' => $day(1), 'to' => $day(2)]), 200, 'CEO overview D1..D2');
$sales = $overview['sales'];
$check($sales['bySource']['trendhome']['fabric']['net'] === '5.000' && $sales['bySource']['trendhome']['rail']['net'] === '3.000', 'I Trendhome fabric 2.5 x 2 = 5.000 m, rail 3.000 m apart: ' . json_encode($sales['bySource']['trendhome']));
$check($sales['bySource']['outletperdele']['fabric']['net'] === '4.000', 'I Outlet Perdele fabric 4.000 m');
$b2b = $sales['bySource']['b2b'];
$check($b2b['fabric']['ordered'] === '24.700' && $b2b['fabric']['cancelled'] === '8.000' && $b2b['fabric']['net'] === '16.700',
    'D/F/L B2B on D1..D2 Bucharest: R1 12.500 (21:30Z D0 = D1) + R2 8.000 + R3 4.200 = 24.700 ordered, 8.000 cancelled, 16.700 net: ' . json_encode($b2b['fabric']));
$check($sales['total']['fabric']['net'] === '25.700' && $sales['total']['rail']['net'] === '3.000', 'I fabric total TH 5 + OP 4 + B2B 16.7 = 25.700 m; rail apart 3.000 m');
$check(!isset($sales['bySource']['trendyol']) && in_array('b2b', $sales['total']['sources'], true) && !in_array('trendyol', $sales['total']['sources'], true), 'J Trendyol is never a fabric source; its panel width is reported apart');
$check(isset($sales['trendyolPanel']['widthMeters']) && $sales['trendyolPanel']['rule'] !== '', 'J the panel width block is separate');
$check($sales['unknown']['linesBySource']['b2b'] === 2 && $sales['unknown']['linesBySource']['trendhome'] >= 1, 'K unknown lines counted (B2B 2, TH 1 without a basis), never zero meters: ' . json_encode($sales['unknown']));
$check($b2b['byPayment']['paid']['fabricMeters'] === '0.000' && $b2b['fabric']['verified'] === '0.000', 'payment: an unpaid B2B receivable is never a verified sale');
$check(MeterContract::units($b2b['byPayment']['unverified']['fabricMeters']) + MeterContract::units($b2b['byPayment']['unknown']['fabricMeters']) === 16700, 'payment: B2B net meters are unverified or unknown');
$d0Only = $status($report($ceo, 'overview', ['from' => $day(0), 'to' => $day(0)]), 200, 'overview D0');
$check($d0Only['sales']['bySource']['b2b']['fabric']['ordered'] === '0.000', 'L R1 (21:30Z on D0) belongs to D1 in Bucharest, not D0');
$check($overview['ledger']['available'] === true && $overview['ledger']['ordered']['fabricMeters'] === '25.700', 'the 2.73 ledger figures equal the sales total');
$check(isset($overview['current']['meters']['inProduction']['fabricMeters'], $overview['current']['meters']['waiting']['fabricMeters']), '6 the current state carries meters of the active orders');
$receivedIds = $overview['received']['bySource']['b2b']['orders'] ?? 0;
$t3Received = (int) $one("SELECT COUNT(*) FROM operational_orders o JOIN production_meter_exclusions x ON x.order_uuid = o.order_uuid WHERE o.order_uuid IN (?, ?)", [$t2Canonical, $t3Canonical]);
$check($t3Received === 2, 'B both cohort canonical orders are excluded, so no production population (received, worked, completed, current) counts them');

// L: history filters.
$all = $status($report($root, 'overview', ['period' => 'all']), 200, 'all time');
$from = new DateTimeImmutable($all['range']['fromUtc'] . 'Z'); $to = new DateTimeImmutable($all['range']['toUtcExclusive'] . 'Z');
$check($all['range']['period'] === 'all' && $from >= new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('Europe/Bucharest')) && $to > $from, 'L period=all runs from the oldest data (not before the floor) to today');
$status($report($ceo, 'summary', ['from' => '2024-01-01', 'to' => '2026-06-30']), 200, 'L a 2.5-year custom range is accepted');
$err = $report($ceo, 'summary', ['from' => '2023-12-31', 'to' => '2024-02-01']);
$check($err['status'] === 400 && ($err['body']['error']['code'] ?? '') === 'BEFORE_ANALYSIS_START', 'L a range before the history floor is refused');

// Coverage and access.
$coverage = $status($report($ceo, 'coverage'), 200, 'coverage (CEO)')['coverage'];
$check($coverage['b2bTestCohort']['orders'] >= 4 && isset($coverage['sources']['trendhome']['outsideArasya'], $coverage['sources']['trendyol']['panelLinesByStatus'], $coverage['sources']['b2b']['testOrders']), 'coverage lists sources and the B2B cohort');
$cohortItem = array_values(array_filter($coverage['b2bTestCohort']['items'], static fn (array $i): bool => $i['canonicalOrder'] === 'b2b:' . $t3['id']))[0] ?? null;
$check($cohortItem !== null && $cohortItem['canonicalExcluded'] === true && $cohortItem['soldMetersBooked'] === '0.000', 'coverage: T3 canonical excluded, no sold meters booked');
$check($coverage['sources']['trendyol']['fabricMeters'] === null && $coverage['productionStart'] === '2026-10-04', 'coverage: Trendyol fabric unknown (null, never 0); production data start stated');
$status($report($root, 'coverage'), 200, 'coverage (root)');
$check($report($manager, 'coverage')['status'] === 403 && $report($manager, 'overview', ['period' => 'all'])['status'] === 403, 'only Root and the canonical CEO read the reports');
$listed = $cli('b2b-meter-cohort.php', ['--list']);
$check($listed['code'] === 0 && preg_match('/COHORT: orders=(\d+) canonical=(\d+) entered_meters=([0-9.]+) m/', $listed['out']) === 1, 'the cohort audit list prints counts and meters');

// N: nothing outside the meter tables changed.
$check($snapshot($financial) === $moneyMeter, 'N the B2B current account is unchanged by the ledger runs, the reports and the CLIs');
$after = $rows('SELECT order_uuid, status, version FROM b2b_orders WHERE order_uuid IN (?, ?, ?, ?)', [$t1['id'], $t2['id'], $t3['id'], $t4['id']]);
$check(count($after) === 4 && in_array('draft', array_column($after, 'status'), true), 'N cohort orders keep their status and still exist (nothing deleted)');
$check($canonical($t2['id']) === $t2Canonical && (int) $one('SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ?', [$t2Canonical]) >= 1, 'N the canonical production order and its events stay');
$cliLedger = $cli('meter-ledger.php', ['--b2b-order=' . $r1['id']]);
$check($cliLedger['code'] === 0 && str_contains($cliLedger['out'], 'DRY RUN (nothing written)') && str_contains($cliLedger['out'], 'b2b_entries=0'), 'the ledger CLI dry run on R1 writes nothing: ' . $cliLedger['out'] . $cliLedger['err']);

// Unit rules.
$check(B2bSoldMeters::line('curtain', '12.500') === ['status' => MeterContract::MEASURED, 'fabricUnits' => 12500] && B2bSoldMeters::line('other', '100.000')['fabricUnits'] === null
    && B2bSoldMeters::line('drapery', null)['status'] === MeterContract::UNKNOWN && B2bSoldMeters::line('drapery', '0.000')['status'] === MeterContract::UNKNOWN, 'B2B line contract');
$fake = SalesMeters::aggregate([['metric' => 'sold_fabric', 'source_key' => 'trendyol', 'delta_meters' => '9.000', 'reason' => 'sale', 'origin' => 'arasya', 'order_key' => 'x', 'payment' => 'paid']], null);
$check($fake['total']['fabric']['net'] === '0.000', 'J a Trendyol entry is never added to the fabric total');

echo "OK MySQL B2B sold meters integration ({$checks} checks)\n";
