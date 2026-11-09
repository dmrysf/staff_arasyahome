<?php

declare(strict_types=1);

// Trendyol AWB print station (migration 051, API 2.65.0) through the real HTTP kernel on its own disposable database:
// the automatic stage 13 queue (derived, never enqueued), Trendyol-only isolation, exact QR / number search, the official
// label from the Trendyol common label service (fixture transport shaped like the documented answer), unlimited reprints,
// the minimal print history, every refusal and failure category, privacy, and the independence of the 14-stage workflow
// (stage 14 never waits for a print). Synthetic people, packages and labels only; no request leaves the machine.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP MySQL Trendyol AWB station: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }
function ok(array $r, int $status, string $message): array { check($r['status'] === $status, $message . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $status, string $code, string $message): array { ok($r, $status, $message); check(($r['body']['error']['code'] ?? null) === $code, $message . ' code ' . json_encode($r['body'])); return $r['body']; }

$db = substr($db, 0, 40) . '_awb';
$server = new PDO('mysql:host=' . (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('ARASYA_TEST_DB_PORT') ?: '3306'), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$fixtureFile = (string) tempnam(sys_get_temp_dir(), 'arasya-awb-');
$config = new Config(...[...(array) T::config($db), 'trendyolFixtureFile' => $fixtureFile, 'trendyolStoreFrontCode' => 'RO']);
$pdo = Connection::create($config);
$dir = dirname(__DIR__) . '/database/migrations';
$applied = (new MigrationRunner($pdo))->migrate($dir);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $file) (new SqlFileRunner($pdo))->run($file);
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
$scalar = static function (string $sql, array $params = []) use ($rows): mixed { $r = $rows($sql, $params); return $r === [] ? null : array_values($r[0])[0]; };
$fixture = static function (array $value) use ($fixtureFile): void { file_put_contents($fixtureFile, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); };
$fixtureNow = static fn (): array => json_decode((string) file_get_contents($fixtureFile), true);

// ---- 1. Migration 051: additive, one table, two permissions, one template assigned to nobody ---------------------------
check(in_array('051_trendyol_awb_station.sql', $applied, true), 'a fresh install applies 051');
check((int) $scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'trendyol_awb_print_events'") === 1, '051 creates the print history table');
$template = array_column($rows("SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = 'trendyol-awb-station' ORDER BY p.permission_key"), 'permission_key');
check($template === ['trendyol.awb.print', 'trendyol.awb.view'], 'the station template holds exactly view and print: ' . json_encode($template));
check((int) $scalar("SELECT COUNT(*) FROM employee_role_assignments era JOIN roles r ON r.role_id = era.role_id WHERE r.role_key = 'trendyol-awb-station'") === 0, '051 assigns the template to nobody');
check((int) $scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id JOIN roles r ON r.role_id = rp.role_id WHERE p.permission_key LIKE 'trendyol.awb.%' AND r.role_key <> 'trendyol-awb-station'") === 0, 'no existing role receives an AWB permission');
$grants = $rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id');
$stagesBefore = $rows('SELECT * FROM production_stages ORDER BY stage_id');
(new SqlFileRunner($pdo))->run($dir . '/051_trendyol_awb_station.sql');
check($rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id') === $grants && $rows('SELECT * FROM production_stages ORDER BY stage_id') === $stagesBefore, 're-running 051 changes nothing (grants, stages)');
check((new MigrationRunner($pdo))->migrate($dir) === [], '051 is recorded once');

// ---- 2. People --------------------------------------------------------------------------------------------------------
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('awb-test', 'awb.root.' . $suffix);
$first = T::login($kernel, 'awb.root.' . $suffix, $temporary);
ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'AWB station root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($kernel, 'awb.root.' . $suffix, 'AWB station root passphrase 2026!');
$roleIds = [];
foreach (ok(T::call($kernel, 'GET', '/management/roles', null, [], $root['cookie']), 200, 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
check(isset($roleIds['trendyol-awb-station']), 'central IAM lists the station template');
$password = 'awb station test passphrase 2026';
$admin = $container->employeeAdmin();
$person = static function (string $name, string $display, array $stages, array $applications, array $roles) use ($admin, $kernel, $root, $password, $suffix): array {
    $employee = $admin->create($display, "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'awb-test');
    ok(T::call($kernel, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, "{$name} applications");
    ok(T::call($kernel, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid, 'name' => "{$name}.{$suffix}"];
};
// The functional station identity: Staff only, the station template only, no stage. Not a person.
$station = $person('awbstation', 'ARASYA AWB STATION', [], ['staff'], [$roleIds['trendyol-awb-station']]);
// A named employee who also prints labels (the same template), and works at packing.
$packer = $person('awbpacker', 'Synthetic packer', ['packing'], ['staff'], [$roleIds['trendyol-awb-station']]);
$quality = $person('awbqc', 'Synthetic quality', ['quality-control'], ['staff'], []);
$delivery = $person('awbdelivery', 'Synthetic delivery', ['delivery'], ['staff'], []);
$outsider = $person('awbout', 'Synthetic outsider', ['packing'], ['staff'], []);
$dashboardOnly = $person('awbdash', 'Synthetic dashboard', [], ['dashboard'], [$roleIds['trendyol-awb-station']]);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $csrf = null, ?string $key = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $csrf ?? $who['csrf'], 'idempotency-key' => $key ?? ('awb-' . bin2hex(random_bytes(10)))], $who['cookie']);

// ---- 3. Orders: released Trendyol packages with their Arasya QR and document revision 1 --------------------------------
$writer = $container->projectionWriter();
$documents = $container->documentService();
$rootIdentity = $container->employeeRepository()->findByUuid($root['employeeUuid']) ?? throw new LogicException('root');
$base = random_int(10_000_000, 90_000_000) * 100;
$t0 = new DateTimeImmutable('2026-11-09 08:00:00', new DateTimeZone('UTC'));
$pdf = static fn (string $tag): string => "%PDF-1.4\n% Synthetic Trendyol label {$tag}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$packages = [];
$tracking = static fn (int $offset): string => (string) (7330000000000000 + $offset);
$rawPackage = static function (int $offset, string $status = 'Picking', ?string $trackingNumber = 'auto', ?string $number = null) use ($base, $tracking, $t0): array {
    $raw = ['shipmentPackageId' => $base + $offset, 'orderNumber' => $number ?? (string) ($base + $offset + 7), 'status' => $status, 'shipmentPackageStatus' => $status,
        'lastModifiedDate' => $t0->getTimestamp() * 1000, 'orderDate' => $t0->getTimestamp() * 1000, 'cargoProviderName' => 'FANCOURIER', 'channelId' => 1,
        'shipmentAddress' => ['fullName' => 'NEVER STORED Client', 'address1' => 'Str. Secretă 9', 'city' => 'Iași', 'phone' => '0744999888'], 'customerEmail' => 'never@example.invalid',
        'lines' => [['lineId' => $base + $offset + 1, 'quantity' => 1, 'productName' => 'Perdea tul', 'stockCode' => 'PT-1']]];
    if ($trackingNumber !== null) $raw['cargoTrackingNumber'] = $trackingNumber === 'auto' ? (int) $tracking($offset) : $trackingNumber;
    return $raw;
};
$labelUrl = static fn (int $offset, int $parcel = 1): string => "https://cdn.dsmcdn.com/public-delivery-partner/product-label/test_{$offset}_{$parcel}.pdf";
$release = static function (int $offset, ?string $number = null) use ($pdo, $writer, $documents, $rootIdentity, $base, $t0, $root): string {
    $packageId = $base + $offset;
    $number ??= (string) ($packageId + 7);
    $now = $t0->format('Y-m-d H:i:s.u');
    $pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, channel_id, lines_hash, first_seen_at, last_seen_at)
        VALUES (?, ?, 'pending', 'Picking', ?, ?, 1, ?, ?, ?)")->execute([$packageId, $number, $t0->getTimestamp() * 1000, $t0->getTimestamp() * 1000, random_bytes(32), $now, $now]);
    $pdo->beginTransaction();
    $item = new OperationalOrderItem(Uuid::v4(), (string) ($packageId + 1), 1, 'Perdea tul', 'PT-1', '300x260', 'Alb', 300.0, 260.0, 'cm', null, 1, ['kind' => 'curtain', 'notes' => null, 'productionNotes' => null]);
    $uuid = $writer->createInternal(new SourceOrderSnapshot('trendyol', (string) $packageId, 'approval-' . $packageId, 1, $t0, $number, 'waiting', 'Picking', 'Picking', null, 'in_progress', $t0, [$item]), []);
    $documents->generateInitialInTransaction($rootIdentity, $uuid, 'awb-release-' . $packageId);
    $pdo->prepare("UPDATE trendyol_packages SET intake_status = 'released', released_order_uuid = ?, released_by_employee_uuid = ?, released_at = ? WHERE package_id = ?")->execute([$uuid, $root['employeeUuid'], $now, $packageId]);
    $pdo->commit();
    return $uuid;
};
// Test fixture only: place an order at a stage as if the workflow had moved it there (one activity row records the arrival).
$placeAt = static function (string $uuid, string $stage, DateTimeImmutable $at, bool $event = true) use ($pdo, $root): void {
    $order = $pdo->query("SELECT global_order_id, order_number, production_version, production_stage_id FROM operational_orders WHERE order_uuid = " . $pdo->quote($uuid))->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE operational_orders SET production_stage_id = ?, production_owner_employee_uuid = NULL, production_claimed_at = NULL, production_changed_at = ?, production_version = production_version + 1 WHERE order_uuid = ?")
        ->execute([$stage, $at->format('Y-m-d H:i:s.u'), $uuid]);
    if ($event) {
        $pdo->prepare("INSERT INTO order_activity_events (event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action, workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot,
            to_stage_id, to_stage_label_snapshot, production_version_before, production_version_after, request_id, idempotency_key, occurred_at) VALUES (?, ?, ?, ?, 'trendyol', ?, 'stage_completed', 'curtain-production', 1, ?, 'fixture', ?, 'fixture', ?, ?, 'awb-fixture', ?, ?)")
            ->execute([Uuid::v4(), $root['employeeUuid'], $uuid, $order['global_order_id'], $order['order_number'], $order['production_stage_id'], $stage, (int) $order['production_version'], (int) $order['production_version'] + 1, 'awb-fixture-' . Uuid::v4(), $at->format('Y-m-d H:i:s.u')]);
    }
};
$o = [];
$o['a'] = $release(10); $placeAt($o['a'], 'packing', $t0->modify('+10 minutes'));
$o['b'] = $release(20); $placeAt($o['b'], 'packing', $t0->modify('+30 minutes'));
$o['c'] = $release(30); $placeAt($o['c'], 'packing', $t0->modify('+20 minutes'));
$o['qc'] = $release(40); $placeAt($o['qc'], 'quality-control', $t0->modify('+5 minutes'));
$o['done'] = $release(50); $placeAt($o['done'], 'delivery', $t0->modify('+1 minutes'));
$o['old'] = $release(60); $placeAt($o['old'], 'packing', $t0->modify('-2 days'), false);
$o['stopped'] = $release(70); $placeAt($o['stopped'], 'packing', $t0->modify('+40 minutes'));
$pdo->prepare("UPDATE operational_orders SET cancellation_state = 'held', production_stopped_at = ? WHERE order_uuid = ?")->execute([$t0->modify('+41 minutes')->format('Y-m-d H:i:s.u'), $o['stopped']]);
$o['twinA'] = $release(80, 'TWIN' . $suffix); $placeAt($o['twinA'], 'packing', $t0->modify('+3 minutes'));
$o['twinB'] = $release(90, 'TWIN' . $suffix); $placeAt($o['twinB'], 'packing', $t0->modify('+4 minutes'));
// A Trendhome order at packing next to them: never in the Trendyol station.
$homeNumber = 'AWBHOME' . $suffix;
check((T::ingest($kernel, 'trendhome', T::sourceOrder($homeNumber, "evt-{$homeNumber}", gmdate('Y-m-d\TH:i:s\Z', time() - 60), T::stage('packing')))['body']['outcome'] ?? null) === 'applied', 'a Trendhome order is at packing too');
$homeUuid = (string) $scalar('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', ['trendhome:' . $homeNumber]);
$qrOf = static fn (string $uuid): string => 'ARASYA:Q1:' . $scalar("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]);
$fixture(['packages' => array_map(fn (int $offset): array => $rawPackage($offset), [10, 20, 30, 40, 50, 60, 70, 80, 90]),
    'labels' => [$tracking(10) => ['status' => 200, 'data' => [['format' => 'PDF', 'label' => $labelUrl(10)]]], $tracking(50) => ['status' => 200, 'data' => [['format' => 'PDF', 'label' => $labelUrl(50)]]],
        $tracking(20) => ['status' => 200, 'data' => [['format' => 'PDF', 'label' => $labelUrl(20, 1)], ['format' => 'PDF', 'label' => $labelUrl(20, 2)]]]],
    'documents' => [$labelUrl(10) => ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdf('10'))], $labelUrl(50) => ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdf('50'))],
        $labelUrl(20, 1) => ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdf('20-1'))], $labelUrl(20, 2) => ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdf('20-2'))]]]);
$production = static fn (): string => hash('sha256', serialize($pdo->query('SELECT order_uuid, production_stage_id, production_version, production_owner_employee_uuid, production_completed_at, operational_status, cancellation_state, document_status, version FROM operational_orders ORDER BY order_uuid')->fetchAll(PDO::FETCH_ASSOC))
    . serialize($pdo->query('SELECT * FROM trendyol_packages ORDER BY package_id')->fetchAll(PDO::FETCH_ASSOC)) . serialize($pdo->query('SELECT qr_reference, status FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC))
    . (string) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn());
$productionBefore = $production();

// ---- 4. Access: only AWB permission holders inside Staff ----------------------------------------------------------------
err(T::call($kernel, 'GET', '/trendyol/awb/queue'), 401, 'SESSION_EXPIRED', 'no session');
err($get($outsider, '/trendyol/awb/queue'), 403, 'UNAUTHORIZED_ACTION', 'a Staff employee without the AWB permission');
err($get($outsider, "/trendyol/awb/orders/{$o['a']}/label"), 403, 'UNAUTHORIZED_ACTION', 'the label needs the print permission (direct URL)');
err($post($outsider, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => Uuid::v4()]), 403, 'UNAUTHORIZED_ACTION', 'a print record needs the print permission');
err($get($outsider, "/trendyol/awb/resolve", ['code' => $qrOf($o['a'])]), 403, 'UNAUTHORIZED_ACTION', 'search needs the view permission');
err($get($dashboardOnly, '/trendyol/awb/queue'), 403, 'APPLICATION_ACCESS_DENIED', 'AWB permissions work only with Staff access');
err($get($station, '/trendyol/overview'), 403, 'UNAUTHORIZED_ACTION', 'the station cannot open the Trendyol intake workspace');
err($get($station, '/finance/companies'), 403, 'APPLICATION_ACCESS_DENIED', 'the station has no Finance access');
check(in_array($get($station, '/management/roles')['status'], [401, 403], true), 'the station has no IAM administration');
err($get($station, '/orders/stage-queue', ['stage' => 'packing']), 403, 'STAGE_NOT_ALLOWED', 'the station has no production stage');
$claim = T::call($kernel, 'POST', '/orders/' . rawurlencode('trendyol:' . ($base + 10)) . '/claim', ['expectedVersion' => (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$o['a']])], ['x-csrf-token' => $station['csrf'], 'idempotency-key' => 'awb-claim-' . $suffix], $station['cookie']);
check(in_array($claim['status'], [403, 404], true), 'the station cannot claim an order: ' . json_encode($claim['body']));
$transition = T::call($kernel, 'POST', '/orders/' . rawurlencode('trendyol:' . ($base + 10)) . '/transition', ['expectedVersion' => 1], ['x-csrf-token' => $station['csrf'], 'idempotency-key' => 'awb-transition-' . $suffix], $station['cookie']);
check(in_array($transition['status'], [403, 404], true), 'the station cannot move a stage');
$stationIdentity = $container->employeeRepository()->findByUuid($station['id']);
check($stationIdentity !== null && $stationIdentity->allowedStageIds === [] && !array_filter($stationIdentity->permissions, static fn (string $p): bool => str_starts_with($p, 'finance.') || str_starts_with($p, 'b2b.') || str_starts_with($p, 'trendyol.orders.') || str_starts_with($p, 'production.') || str_starts_with($p, 'employees.') || str_starts_with($p, 'roles.')), 'the station identity carries no stage, Finance, B2B, intake, production or IAM permission: ' . json_encode($stationIdentity?->permissions));

// ---- 5. The stage 13 queue: derived, Trendyol only, newest arrival first -------------------------------------------------
$queue = ok($get($station, '/trendyol/awb/queue'), 200, 'station queue');
$ids = array_column($queue['items'], 'orderId');
check($ids === [$o['b'], $o['c'], $o['a'], $o['twinB'], $o['twinA'], $o['old']], 'queue: open Trendyol orders at packing, newest arrival first: ' . json_encode(array_column($queue['items'], 'orderNumber')));
check($queue['total'] === 6 && $queue['stage'] === ['id' => 'packing', 'ordinal' => 13] && $queue['capabilities'] === ['print' => true] && $queue['nextCursor'] === null, 'queue total, stage and capabilities');
check(!in_array($o['qc'], $ids, true) && !in_array($o['done'], $ids, true) && !in_array($o['stopped'], $ids, true) && !in_array($homeUuid, $ids, true), 'stage 12, stage 14, a stopped order and another source never appear');
$itemA = $queue['items'][2];
check($itemA['orderNumber'] === (string) ($base + 17) && $itemA['packageId'] === (string) ($base + 10) && $itemA['globalOrderId'] === 'trendyol:' . ($base + 10) && $itemA['stage'] === ['id' => 'packing', 'ordinal' => 13]
    && $itemA['inQueue'] === true && $itemA['printed'] === ['count' => 0, 'lastAt' => null] && $itemA['enteredStageAt'] === '2026-11-09T08:10:00.000Z', 'queue row: number, package, stage 13, arrival, not printed: ' . json_encode($itemA));
check(array_keys($itemA) === ['orderId', 'globalOrderId', 'orderNumber', 'packageId', 'stage', 'inQueue', 'productionCompleted', 'productionStopped', 'enteredStageAt', 'printed'], 'queue rows carry no customer, price or finance field');
check($queue['items'][5]['orderId'] === $o['old'] && $queue['items'][5]['enteredStageAt'] === '2026-11-07T08:00:00.000Z', 'an order already at stage 13 before the station existed is included (arrival falls back to its last production change)');
$page1 = ok($get($station, '/trendyol/awb/queue', ['limit' => '2']), 200, 'page 1');
$page2 = ok($get($station, '/trendyol/awb/queue', ['limit' => '2', 'cursor' => (string) $page1['nextCursor']]), 200, 'page 2');
$page3 = ok($get($station, '/trendyol/awb/queue', ['limit' => '2', 'cursor' => (string) $page2['nextCursor']]), 200, 'page 3');
check(array_merge(array_column($page1['items'], 'orderId'), array_column($page2['items'], 'orderId'), array_column($page3['items'], 'orderId')) === $ids && $page3['nextCursor'] === null, 'keyset pagination walks the queue exactly once');
err($get($station, '/trendyol/awb/queue', ['cursor' => 'bm90LWEtY3Vyc29y']), 400, 'INVALID_REQUEST', 'a forged cursor is refused');
err($get($station, '/trendyol/awb/queue', ['limit' => '0']), 400, 'INVALID_REQUEST', 'limit 0 is refused');
check(count(ok($get($station, '/trendyol/awb/queue', ['limit' => '500']), 200, 'limit capped')['items']) === 6, 'a large limit is capped, never an error');
check(array_column(ok($get($station, '/trendyol/awb/queue', ['q' => (string) intdiv($base + 27, 10)]), 200, 'search prefix')['items'], 'orderId') === [$o['b']], 'the search narrows by order number prefix');
check(array_column(ok($get($station, '/trendyol/awb/queue', ['q' => '%']), 200, 'wildcard')['items'], 'orderId') === [], 'LIKE wildcards are literal');
check(ok($get($packer, '/trendyol/awb/queue'), 200, 'named employee queue')['items'] === $queue['items'], 'a named employee with the same permission sees the same queue');

// A real stage 12 -> 13 transition makes the order appear, with no write by the station.
$qcGlobal = 'trendyol:' . ($base + 40);
$v = (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$o['qc']]);
ok(T::call($kernel, 'POST', '/orders/' . rawurlencode($qcGlobal) . '/claim', ['expectedVersion' => $v], ['x-csrf-token' => $quality['csrf'], 'idempotency-key' => 'awb-qc-claim-' . $suffix], $quality['cookie']), 200, 'quality control claims the order');
$v = (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$o['qc']]);
ok(T::call($kernel, 'POST', '/orders/' . rawurlencode($qcGlobal) . '/transition', ['expectedVersion' => $v], ['x-csrf-token' => $quality['csrf'], 'idempotency-key' => 'awb-qc-done-' . $suffix], $quality['cookie']), 200, 'quality control completes stage 12');
check($scalar('SELECT production_stage_id FROM operational_orders WHERE order_uuid = ?', [$o['qc']]) === 'packing', 'the canonical workflow moved the order to stage 13');
$after = ok($get($station, '/trendyol/awb/queue'), 200, 'queue after the transition');
check($after['items'][0]['orderId'] === $o['qc'] && $after['total'] === 7, 'the order reaches the station automatically, newest first');
check((int) $scalar('SELECT COUNT(*) FROM trendyol_awb_print_events') === 0, 'reading the queue writes nothing');
$productionBefore = $production();

// ---- 6. Exact search: QR, order number, package id ----------------------------------------------------------------------
$found = ok($get($station, '/trendyol/awb/resolve', ['code' => $qrOf($o['a'])]), 200, 'QR scan');
check(array_column($found['items'], 'orderId') === [$o['a']] && $found['items'][0]['inQueue'] === true, 'the active Arasya QR finds the order');
check(array_column(ok($get($station, '/trendyol/awb/resolve', ['code' => '  ' . strtolower($qrOf($o['a'])) . ' ']), 200, 'QR lower case')['items'], 'orderId') === [$o['a']], 'a scanner in lower case still resolves');
check(array_column(ok($get($station, '/trendyol/awb/resolve', ['code' => (string) ($base + 17)]), 200, 'number')['items'], 'orderId') === [$o['a']], 'the Trendyol order number finds the order');
check(array_column(ok($get($station, '/trendyol/awb/resolve', ['code' => '#' . ($base + 10)]), 200, 'package id')['items'], 'orderId') === [$o['a']], 'the package id finds the order');
check(array_column(ok($get($station, '/trendyol/awb/resolve', ['code' => 'trendyol:' . ($base + 10)]), 200, 'global id')['items'], 'orderId') === [$o['a']], 'the global id finds the order');
$twins = ok($get($station, '/trendyol/awb/resolve', ['code' => 'TWIN' . $suffix]), 200, 'two packages of one marketplace order');
check(count($twins['items']) === 2 && array_unique(array_column($twins['items'], 'orderNumber')) === ['TWIN' . $suffix] && count(array_unique(array_column($twins['items'], 'packageId'))) === 2, 'every package of a marketplace order is listed, each with its own package id');
$done = ok($get($station, '/trendyol/awb/resolve', ['code' => $qrOf($o['done'])]), 200, 'stage 14 order by QR');
check($done['items'][0]['inQueue'] === false && $done['items'][0]['stage']['ordinal'] === 14, 'a stage 14 order is found by exact search, outside the queue');
err($get($station, '/trendyol/awb/resolve', ['code' => $qrOf($homeUuid)]), 404, 'AWB_ORDER_NOT_FOUND', 'another source reads as not found (no leak)');
err($get($station, '/trendyol/awb/resolve', ['code' => $homeNumber]), 404, 'AWB_ORDER_NOT_FOUND', 'another source by number reads as not found');
err($get($station, '/trendyol/awb/resolve', ['code' => 'ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA']), 404, 'AWB_ORDER_NOT_FOUND', 'an unknown QR');
err($get($station, '/trendyol/awb/resolve', ['code' => '<script>']), 400, 'AWB_CODE_INVALID', 'garbage is refused');
err($get($station, '/trendyol/awb/resolve', ['code' => '']), 400, 'AWB_CODE_INVALID', 'an empty scan is refused');
$revoked = (string) $scalar("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$o['c']]);
$pdo->prepare("UPDATE order_qr_references SET status = 'revoked', revoked_at = UTC_TIMESTAMP(6) WHERE qr_reference = ?")->execute([$revoked]);
err($get($station, '/trendyol/awb/resolve', ['code' => 'ARASYA:Q1:' . $revoked]), 409, 'AWB_QR_INACTIVE', 'an old revision QR is refused');
$pdo->prepare("UPDATE order_qr_references SET status = 'active', revoked_at = NULL WHERE qr_reference = ?")->execute([$revoked]);
check($production() === $productionBefore, 'searching changes no stage, owner, QR or package');

// ---- 7. Print: the official label, then the print request -------------------------------------------------------------
$label = $get($station, "/trendyol/awb/orders/{$o['a']}/label");
check($label['status'] === 200 && $label['raw'] === $pdf('10'), 'the station receives the official PDF bytes unchanged');
check(($label['headers']['Content-Type'] ?? '') === 'application/pdf' && ($label['headers']['Cache-Control'] ?? '') === 'private, no-store' && ($label['headers']['X-Content-Type-Options'] ?? '') === 'nosniff', 'PDF, private no-store, nosniff');
check(($label['headers']['X-Arasya-Label-Parcel'] ?? '') === '1' && ($label['headers']['X-Arasya-Label-Parcels'] ?? '') === '1' && preg_match('/^[0-9a-f-]{36}$/', $label['headers']['X-Arasya-Label-Retrieval'] ?? '') === 1, 'parcel and retrieval headers');
$calls = $fixtureNow();
check(count($calls['labelCalls'] ?? []) === 1 && $calls['labelCalls'][0] === ['id' => $tracking(10), 'storeFrontCode' => 'RO', 'authorized' => true], 'one common label read for the package\'s own tracking number, RO storefront: ' . json_encode($calls['labelCalls'] ?? null));
check(($calls['documentCalls'] ?? []) === [$labelUrl(10)] && !isset($calls['putCalls']), 'one document download from the Trendyol CDN; nothing is written to Trendyol');
$retrieval = (string) $label['headers']['X-Arasya-Label-Retrieval'];
$event = $rows('SELECT * FROM trendyol_awb_print_events WHERE event_id = ?', [$retrieval])[0];
check($event['event_type'] === 'label_retrieved' && $event['outcome'] === 'ok' && $event['print_kind'] === null && (int) $event['package_id'] === $base + 10 && $event['employee_uuid'] === $station['id']
    && $event['cargo_provider'] === 'FANCOURIER' && $event['tracking_suffix'] === substr($tracking(10), -4) && (int) $event['parcel_number'] === 1, 'the retrieval is recorded with the station identity, never as a print');
check($production() === $productionBefore, 'retrieving a label changes no stage, owner, QR or package');
check(ok($get($station, '/trendyol/awb/queue'), 200, 'queue after retrieval')['items'][3]['printed']['count'] === 0, 'a retrieval alone is not "printed before"');
$attempt = Uuid::v4();
$print = ok($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => $attempt, 'retrievalId' => $retrieval]), 201, 'print request');
check($print['print']['kind'] === 'initial' && $print['printed']['count'] === 1, 'the first print request is initial');
check(ok($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['retrievalId' => $retrieval, 'attemptId' => $attempt]), 201, 'replay') === $print, 'a replayed print request returns the same record');
err($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => $attempt, 'retrievalId' => Uuid::v4()]), 409, 'IDEMPOTENCY_KEY_REUSED', 'the same attempt for another label');
err($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => $retrieval], 'invalid-token'), 403, 'CSRF_INVALID', 'CSRF is required');
err($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => $retrieval, 'stage' => 'delivery']), 400, 'INVALID_REQUEST', 'strict body');
err($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => 'x', 'retrievalId' => $retrieval]), 400, 'INVALID_REQUEST', 'the attempt id is a UUID');
err($post($packer, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => $retrieval]), 409, 'AWB_RETRIEVAL_UNKNOWN', 'a print record names the caller\'s own retrieval');
err($post($station, "/trendyol/awb/orders/{$o['b']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => $retrieval]), 409, 'AWB_RETRIEVAL_UNKNOWN', 'a retrieval of another order cannot be printed here');
// Unlimited reprints, no reason, no approval.
for ($i = 0; $i < 3; $i++) {
    $again = $get($station, "/trendyol/awb/orders/{$o['a']}/label");
    check($again['status'] === 200 && $again['raw'] === $pdf('10'), "reprint {$i}: the same official label");
    $reprint = ok($post($station, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => (string) $again['headers']['X-Arasya-Label-Retrieval']]), 201, "reprint {$i}");
    check($reprint['print']['kind'] === 'reprint' && $reprint['printed']['count'] === 2 + $i, "reprint {$i} is recorded as a reprint, never refused");
}
$packerLabel = $get($packer, "/trendyol/awb/orders/{$o['a']}/label");
check(ok($post($packer, "/trendyol/awb/orders/{$o['a']}/print-attempts", ['attemptId' => Uuid::v4(), 'retrievalId' => (string) $packerLabel['headers']['X-Arasya-Label-Retrieval']]), 201, 'named employee reprint')['print']['kind'] === 'reprint', 'a named employee may reprint the same label');
$row = array_values(array_filter(ok($get($station, '/trendyol/awb/queue'), 200, 'queue printed')['items'], static fn (array $item): bool => $item['orderId'] === $o['a']))[0];
check($row['printed']['count'] === 5 && $row['printed']['lastAt'] !== null && $row['inQueue'] === true, 'the queue shows "printed before" and keeps the order at stage 13');
check(count(array_unique(array_column($fixtureNow()['labelCalls'], 'id'))) === 1, 'every reprint read the same tracking number: no new shipment, no new number');
check($production() === $productionBefore, 'printing and reprinting change no stage, owner, QR or package');

// Two parcels under one shipment.
$p1 = $get($station, "/trendyol/awb/orders/{$o['b']}/label");
$p2 = $get($station, "/trendyol/awb/orders/{$o['b']}/label", ['parcel' => '2']);
check($p1['raw'] === $pdf('20-1') && $p2['raw'] === $pdf('20-2') && $p1['headers']['X-Arasya-Label-Parcels'] === '2' && $p2['headers']['X-Arasya-Label-Parcel'] === '2', 'each parcel prints its own official label');
err($get($station, "/trendyol/awb/orders/{$o['b']}/label", ['parcel' => '3']), 404, 'AWB_PARCEL_NOT_FOUND', 'a parcel Trendyol does not list');
err($get($station, "/trendyol/awb/orders/{$o['b']}/label", ['parcel' => 'x']), 400, 'INVALID_REQUEST', 'parcel is a number');

// A stage 14 order (exact search) may still be reprinted; nothing about stage 14 changes.
check($get($station, "/trendyol/awb/orders/{$o['done']}/label")['raw'] === $pdf('50'), 'an order already at stage 14 can be reprinted through search');

// ---- 8. Refusals and failures: short codes, a safe category recorded, never a fabricated label ---------------------------
$failures = static fn (string $uuid): array => array_column($rows("SELECT outcome FROM trendyol_awb_print_events WHERE order_uuid = ? AND event_type = 'label_failed' ORDER BY created_at, event_id", [$uuid]), 'outcome');
$labelCount = static fn (): int => count($fixtureNow()['labelCalls'] ?? []);
$documentCount = static fn (): int => count($fixtureNow()['documentCalls'] ?? []);
$scenario = static function (array $patch) use ($fixtureNow, $fixture): void { $f = $fixtureNow(); foreach ($patch as $key => $value) { if ($value === null) unset($f[$key]); else $f[$key] = $value; } $fixture($f); };
$withPackage = static function (array $raw) use ($fixtureNow, $fixture, $base): void { $f = $fixtureNow(); foreach ($f['packages'] as $i => $p) if ($p['shipmentPackageId'] === $raw['shipmentPackageId']) $f['packages'][$i] = $raw; $fixture($f); };
$labels = static function (string $trackingNumber, array $answer) use ($fixtureNow, $fixture): void { $f = $fixtureNow(); $f['labels'][$trackingNumber] = $answer; $fixture($f); };
$docs = static function (string $url, array $answer) use ($fixtureNow, $fixture): void { $f = $fixtureNow(); $f['documents'][$url] = $answer; $fixture($f); };
$c = $o['c'];
$labels($tracking(30), ['status' => 400]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_LABEL_NOT_AVAILABLE', 'Trendyol has no label yet');
foreach ([[401, 503, 'AWB_TRENDYOL_AUTH', 'auth_failed'], [403, 503, 'AWB_TRENDYOL_AUTH', 'auth_failed'], [429, 429, 'AWB_TRENDYOL_RATE_LIMITED', 'rate_limited'], [500, 503, 'AWB_TRENDYOL_UNAVAILABLE', 'unavailable'], [404, 409, 'AWB_LABEL_NOT_AVAILABLE', 'not_available']] as [$upstream, $status, $code, $outcome]) {
    $labels($tracking(30), ['status' => $upstream]);
    $body = err($get($station, "/trendyol/awb/orders/{$c}/label"), $status, $code, "Trendyol HTTP {$upstream}");
    check(($body['error']['details']['reason'] ?? null) === $outcome, "HTTP {$upstream} is recorded as {$outcome}");
}
$labels($tracking(30), ['fail' => true]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 503, 'AWB_TRENDYOL_UNAVAILABLE', 'a timeout or network failure');
$labels($tracking(30), ['status' => 200, 'body' => '{"data":"x"}']);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_TRENDYOL_INVALID', 'a malformed answer');
$labels($tracking(30), ['status' => 200, 'body' => 'not json']);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_TRENDYOL_INVALID', 'a non-JSON answer');
$labels($tracking(30), ['status' => 200, 'data' => []]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_LABEL_NOT_AVAILABLE', 'an empty label list');
$labels($tracking(30), ['status' => 200, 'data' => [['format' => 'ZPL', 'label' => $labelUrl(30)]]]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_FORMAT', 'a ZPL-only answer is not printed by the browser station');
$before = $documentCount();
foreach (['https://evil.example/label.pdf', 'http://cdn.dsmcdn.com/x.pdf', 'https://cdn.dsmcdn.com.evil.example/x.pdf', 'https://user:pw@cdn.dsmcdn.com/x.pdf', 'https://cdn.dsmcdn.com:8443/x.pdf',
    'https://cdn.dsmcdn.com/a/../../etc/passwd', 'https://cdn.dsmcdn.com/a/%2e%2e/b.pdf', 'file:///etc/passwd', 'https://127.0.0.1/x.pdf', 'https://cdn.dsmcdn.com/x.pdf#frag'] as $evil) {
    $labels($tracking(30), ['status' => 200, 'data' => [['format' => 'PDF', 'label' => $evil]]]);
    err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_INVALID', "a label URL outside the Trendyol CDN is refused: {$evil}");
}
check($documentCount() === $before, 'no refused URL is ever downloaded (no SSRF)');
$labels($tracking(30), ['status' => 200, 'data' => [['format' => 'PDF', 'label' => $labelUrl(30)]]]);
$docs($labelUrl(30), ['status' => 404]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_LABEL_EXPIRED', 'an expired label URL');
$docs($labelUrl(30), ['status' => 302]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_INVALID', 'a redirect is never followed');
$docs($labelUrl(30), ['status' => 200, 'contentType' => 'text/html', 'text' => '<html>login</html>']);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_INVALID', 'an HTML page instead of the label');
$docs($labelUrl(30), ['status' => 200, 'contentType' => 'application/pdf', 'text' => str_repeat('A', 200)]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_INVALID', 'a malformed PDF (content, not the file name)');
$docs($labelUrl(30), ['status' => 200, 'contentType' => 'application/pdf', 'text' => substr($pdf('30'), 0, 60)]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_INVALID', 'a truncated PDF (no end of file)');
$docs($labelUrl(30), ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode("%PDF-1.4\n" . str_repeat('0', 5 * 1024 * 1024) . "\n%%EOF\n")]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 502, 'AWB_LABEL_INVALID', 'a label larger than 5 MiB');
$docs($labelUrl(30), ['fail' => true]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 503, 'AWB_TRENDYOL_UNAVAILABLE', 'a failed download');
check($failures($c) === ['not_available', 'auth_failed', 'auth_failed', 'rate_limited', 'unavailable', 'not_available', 'unavailable', 'malformed', 'malformed', 'not_available', 'format_unsupported',
    'url_refused', 'url_refused', 'url_refused', 'url_refused', 'url_refused', 'url_refused', 'url_refused', 'url_refused', 'url_refused', 'url_refused', 'expired', 'url_refused', 'invalid_document', 'invalid_document', 'invalid_document', 'invalid_document', 'download_failed'],
    'every failure is recorded with its safe category only: ' . json_encode($failures($c)));
$docs($labelUrl(30), ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdf('30'))]);
check($get($station, "/trendyol/awb/orders/{$c}/label")['raw'] === $pdf('30'), 'after a failure the next try retrieves the label (Tekrar Dene)');

// Package conditions are checked on a fresh read of the exact package, before any label call.
$calls = $labelCount();
$withPackage($rawPackage(30, 'Picking', null));
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_LABEL_NOT_READY', 'no tracking number yet');
$withPackage($rawPackage(30, 'Picking', '12'));
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_LABEL_NOT_READY', 'an implausible tracking number is never sent');
foreach (['Cancelled', 'UnSupplied', 'UnPacked', 'Returned', 'UnDeliveredAndReturned'] as $closed) {
    $withPackage($rawPackage(30, $closed));
    err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_PACKAGE_CLOSED', "a {$closed} package never gets its old label");
}
$withPackage($rawPackage(30, 'Picking', 'auto', 'OTHER-NUMBER'));
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_PACKAGE_MISMATCH', 'a package whose order number changed is refused (no cross-order label)');
check($labelCount() === $calls, 'none of these asked Trendyol for a label');
$f = $fixtureNow(); $f['packages'] = array_values(array_filter($f['packages'], static fn (array $p): bool => $p['shipmentPackageId'] !== $base + 30)); $fixture($f);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 409, 'AWB_PACKAGE_UNAVAILABLE', 'a package Trendyol no longer returns');
$f = $fixtureNow(); $f['packages'][] = $rawPackage(30, 'Picking', '7330999988887777'); $f['labels']['7330999988887777'] = ['status' => 200, 'data' => [['format' => 'PDF', 'label' => $labelUrl(30, 9)]]];
$f['documents'][$labelUrl(30, 9)] = ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdf('30-new'))]; $fixture($f);
check($get($station, "/trendyol/awb/orders/{$c}/label")['raw'] === $pdf('30-new') && end($fixtureNow()['labelCalls'])['id'] === '7330999988887777', 'a tracking number changed by Trendyol: the current one is used');
$scenario(['fail' => true]);
err($get($station, "/trendyol/awb/orders/{$c}/label"), 503, 'AWB_TRENDYOL_UNAVAILABLE', 'Trendyol unreachable for the package read');
$scenario(['fail' => null]);

// Local refusals: no Trendyol call at all.
$calls = $labelCount();
err($get($station, "/trendyol/awb/orders/{$homeUuid}/label"), 404, 'AWB_ORDER_NOT_FOUND', 'another source can never print a Trendyol label');
err($get($station, '/trendyol/awb/orders/' . Uuid::v4() . '/label'), 404, 'AWB_ORDER_NOT_FOUND', 'an unknown order');
err($get($station, "/trendyol/awb/orders/{$o['stopped']}/label"), 409, 'AWB_ORDER_STOPPED', 'a cancellation hold stops the label');
$pdo->prepare('UPDATE trendyol_packages SET released_order_uuid = NULL, intake_status = ?, released_at = NULL, released_by_employee_uuid = NULL WHERE package_id = ?')->execute(['pending', $base + 60]);
err($get($station, "/trendyol/awb/orders/{$o['old']}/label"), 409, 'AWB_PACKAGE_MISMATCH', 'an order without its exact package link is refused');
check($labelCount() === $calls && (int) $scalar("SELECT COUNT(*) FROM trendyol_awb_print_events WHERE order_uuid IN (?, ?, ?)", [$homeUuid, $o['stopped'], $o['old']]) === 0, 'local refusals call nobody and record nothing');
$withoutCredentials = new Container(T::config($db), $pdo);
err(T::call($withoutCredentials->kernel(), 'GET', "/trendyol/awb/orders/{$o['a']}/label", null, [], $station['cookie']), 503, 'AWB_LABELS_UNAVAILABLE', 'without Trendyol credentials the label is refused');
check(ok(T::call($withoutCredentials->kernel(), 'GET', '/trendyol/awb/queue', null, [], $station['cookie']), 200, 'queue without credentials')['total'] === 7, 'the queue works without credentials (no Trendyol call)');
err($post($station, "/trendyol/awb/orders/{$o['a']}/label", []), 405, 'METHOD_NOT_ALLOWED', 'the label is a GET only');
err($get($station, '/trendyol/awb/orders/not-a-uuid/label'), 404, 'NOT_FOUND', 'an invalid order id');

// ---- 9. History and privacy ----------------------------------------------------------------------------------------------
$history = ok($get($station, "/trendyol/awb/orders/{$o['a']}/history"), 200, 'history');
$types = array_count_values(array_column($history['events'], 'type'));
check($types === ['print_requested' => 5, 'label_retrieved' => 5] && $history['order']['orderId'] === $o['a'], 'history: five retrievals and five print requests: ' . json_encode($types));
check(array_values(array_filter(array_column($history['events'], 'kind'))) === ['reprint', 'reprint', 'reprint', 'reprint', 'initial'], 'history kinds, newest first');
check(in_array('ARASYA AWB STATION', array_column($history['events'], 'by'), true) && in_array('Synthetic packer', array_column($history['events'], 'by'), true), 'history names the station identity and the named employee');
$everything = json_encode([$rows('SELECT * FROM trendyol_awb_print_events'), $history, ok($get($station, '/trendyol/awb/queue'), 200, 'queue')], JSON_UNESCAPED_UNICODE);
foreach (['0744999888', 'NEVER STORED', 'Secretă', 'never@example.invalid', $tracking(10), 'dsmcdn', 'fixture'] as $secret) check(!str_contains($everything, $secret), "no {$secret} in the history, the queue or the table");
check(array_keys($rows('SELECT * FROM trendyol_awb_print_events LIMIT 1')[0]) === ['event_id', 'order_uuid', 'package_id', 'employee_uuid', 'event_type', 'print_kind', 'outcome', 'parcel_number', 'parcel_count',
    'retrieval_event_id', 'client_attempt_id', 'cargo_provider', 'tracking_suffix', 'request_id', 'created_at'], 'the table has no address, name, telephone, label or URL column');
check(refused($pdo, "INSERT INTO trendyol_awb_print_events (event_id, order_uuid, package_id, employee_uuid, event_type, print_kind, outcome, request_id, created_at) VALUES (UUID(), ?, 1, ?, 'print_requested', NULL, 'ok', 'x', UTC_TIMESTAMP(6))", [$o['a'], $station['id']]), 'a print record without kind or attempt is refused by the database');
check(refused($pdo, "INSERT INTO trendyol_awb_print_events (event_id, order_uuid, package_id, employee_uuid, event_type, outcome, request_id, created_at) VALUES (UUID(), ?, 1, ?, 'label_failed', 'ok', 'x', UTC_TIMESTAMP(6))", [$o['a'], $station['id']]), 'a failure needs a category');
check(refused($pdo, "INSERT INTO trendyol_awb_print_events (event_id, order_uuid, package_id, employee_uuid, event_type, outcome, request_id, created_at) VALUES (UUID(), ?, 1, ?, 'printed_physically', 'ok', 'x', UTC_TIMESTAMP(6))", [$o['a'], $station['id']]), 'no "physically printed" event exists');
function refused(PDO $pdo, string $sql, array $params = []): bool { try { $pdo->prepare($sql)->execute($params); return false; } catch (PDOException) { return true; } }

// ---- 10. The 14-stage workflow never waits for a print -------------------------------------------------------------------
// Order c was never printed: the packer still completes stage 13 and Livrare completes stage 14.
$cGlobal = 'trendyol:' . ($base + 30);
check((int) $scalar("SELECT COUNT(*) FROM trendyol_awb_print_events WHERE order_uuid = ? AND event_type = 'print_requested'", [$c]) === 0, 'order c has no print request');
$v = (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$c]);
ok(T::call($kernel, 'POST', '/orders/' . rawurlencode($cGlobal) . '/claim', ['expectedVersion' => $v], ['x-csrf-token' => $packer['csrf'], 'idempotency-key' => 'awb-pk-claim-' . $suffix], $packer['cookie']), 200, 'the packer claims stage 13');
$v = (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$c]);
ok(T::call($kernel, 'POST', '/orders/' . rawurlencode($cGlobal) . '/transition', ['expectedVersion' => $v], ['x-csrf-token' => $packer['csrf'], 'idempotency-key' => 'awb-pk-done-' . $suffix], $packer['cookie']), 200, 'stage 13 completes without any AWB print');
check($scalar('SELECT production_stage_id FROM operational_orders WHERE order_uuid = ?', [$c]) === 'delivery', 'the order is at stage 14');
check(!in_array($c, array_column(ok($get($station, '/trendyol/awb/queue'), 200, 'queue after packing')['items'], 'orderId'), true), 'it leaves the active queue automatically');
$v = (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$c]);
ok(T::call($kernel, 'POST', '/orders/' . rawurlencode($cGlobal) . '/claim', ['expectedVersion' => $v], ['x-csrf-token' => $delivery['csrf'], 'idempotency-key' => 'awb-dl-claim-' . $suffix], $delivery['cookie']), 200, 'Livrare claims stage 14');
$v = (int) $scalar('SELECT production_version FROM operational_orders WHERE order_uuid = ?', [$c]);
ok(T::call($kernel, 'POST', '/orders/' . rawurlencode($cGlobal) . '/transition', ['expectedVersion' => $v], ['x-csrf-token' => $delivery['csrf'], 'idempotency-key' => 'awb-dl-done-' . $suffix], $delivery['cookie']), 200, 'stage 14 completes without any AWB check');
check($scalar('SELECT production_completed_at FROM operational_orders WHERE order_uuid = ?', [$c]) !== null, 'production completes; no AWB gate anywhere');
check(!isset($fixtureNow()['putCalls']), 'no request ever wrote to Trendyol');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
@unlink($fixtureFile);
echo "PASS {$checks} Trendyol AWB station checks\n";
