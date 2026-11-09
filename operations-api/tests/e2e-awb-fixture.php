<?php

declare(strict_types=1);

// Trendyol AWB station E2E fixture (API 2.65.0, Staff 2.47.0), run by the AWB spec in the already prepared E2E database.
// Creates generic TEST identities (the functional station account, a named packer who also prints, an employee without
// the permission) and synthetic Trendyol orders at stage 13 with their Arasya QR and document, and merges official-shaped
// package, label and PDF answers into the fixture file the test API reads (ARASYA_TRENDYOL_FIXTURE_FILE, refused in
// production). Nothing reaches Trendyol or a courier. `php e2e-awb-fixture.php add` adds one more order at stage 13.
// Prints usernames and orders as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) {
    fwrite(STDERR, "ARASYA_E2E_DB_NAME must be a dedicated database whose name contains both 'e2e' and 'test'.\n");
    exit(2);
}
$origin = (string) (getenv('ARASYA_E2E_ORIGIN') ?: 'http://127.0.0.1:4174');
$config = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])));
$pdo = Connection::create($config);
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$rootIdentity = $container->employeeRepository()->findByUuid($root['employeeUuid']) ?? throw new LogicException('root');
$fixtureFile = dirname(__DIR__, 2) . '/e2e/.runtime/trendyol-fixture.json';
$fixture = json_decode((string) @file_get_contents($fixtureFile), true);
$fixture = is_array($fixture) ? $fixture : ['packages' => []];
unset($fixture['fail'], $fixture['status'], $fixture['body'], $fixture['labelStatus']);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$writer = $container->projectionWriter();
$documents = $container->documentService();
$pdfFor = static fn (string $tag): string => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 298 420]/Contents 4 0 R/Resources<</Font<</F1<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>>>>>>>endobj\n"
    . ($stream = "BT /F1 18 Tf 40 300 Td (TEST LABEL {$tag}) Tj ET") . "\n"
    . '4 0 obj<</Length ' . strlen($stream) . ">>stream\n{$stream}\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

/** One released synthetic Trendyol package at a stage, with its QR, document and marketplace answers. */
$make = static function (string $stage, string $status, array $labels) use (&$fixture, $pdo, $writer, $documents, $rootIdentity, $root, $now, $pdfFor): array {
    $packageId = random_int(10_000_000, 90_000_000) * 100 + random_int(1, 99);
    $number = (string) ($packageId + 7);
    $tracking = (string) (7330000000000000 + $packageId);
    $at = $now->format('Y-m-d H:i:s.u');
    $ms = $now->getTimestamp() * 1000;
    $pdo->prepare("INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, channel_id, lines_hash, first_seen_at, last_seen_at) VALUES (?, ?, 'pending', 'Picking', ?, ?, 1, ?, ?, ?)")
        ->execute([$packageId, $number, $ms, $ms, random_bytes(32), $at, $at]);
    $pdo->beginTransaction();
    $item = new OperationalOrderItem(Uuid::v4(), (string) ($packageId + 1), 1, 'Perdea test', 'E2E-AWB', '300x260', 'Alb', 300.0, 260.0, 'cm', null, 1, ['kind' => 'curtain', 'notes' => null, 'productionNotes' => null]);
    $uuid = $writer->createInternal(new SourceOrderSnapshot('trendyol', (string) $packageId, 'approval-' . $packageId, 1, $now, $number, 'waiting', 'Picking', 'Picking', null, 'in_progress', $now, [$item]), []);
    $documents->generateInitialInTransaction($rootIdentity, $uuid, 'e2e-awb-' . $packageId);
    $pdo->prepare("UPDATE trendyol_packages SET intake_status = 'released', released_order_uuid = ?, released_by_employee_uuid = ?, released_at = ? WHERE package_id = ?")->execute([$uuid, $root['employeeUuid'], $at, $packageId]);
    $pdo->commit();
    // TEST fixture only: place the order at the stage as if the workflow had moved it there.
    $pdo->prepare('UPDATE operational_orders SET production_stage_id = ?, production_changed_at = ?, production_version = production_version + 1 WHERE order_uuid = ?')->execute([$stage, $at, $uuid]);
    $pdo->prepare("INSERT INTO order_activity_events (event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action, workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot, production_version_before, production_version_after, request_id, idempotency_key, occurred_at)
        VALUES (?, ?, ?, ?, 'trendyol', ?, 'stage_completed', 'curtain-production', 1, 'quality-control', 'fixture', ?, 'fixture', 1, 2, 'e2e-awb', ?, ?)")
        ->execute([Uuid::v4(), $root['employeeUuid'], $uuid, 'trendyol:' . $packageId, $number, $stage, 'e2e-awb-' . Uuid::v4(), $at]);
    $fixture['packages'][] = ['shipmentPackageId' => $packageId, 'orderNumber' => $number, 'status' => $status, 'shipmentPackageStatus' => $status, 'lastModifiedDate' => 0, 'orderDate' => $ms, 'cargoTrackingNumber' => (int) $tracking, 'cargoProviderName' => 'FANCOURIER',
        'shipmentAddress' => ['fullName' => 'TEST NEVER SHOWN', 'address1' => 'Str. Test 1', 'city' => 'Iasi', 'phone' => '0744000111'], 'lines' => [['lineId' => $packageId + 1, 'quantity' => 1, 'productName' => 'Perdea test']]];
    if ($labels === []) {
        $fixture['labels'][$tracking] = ['status' => 400];
    } else {
        $data = [];
        foreach ($labels as $index => $tag) {
            // The shape of the real account (2026-11-09): a gateway label URL, one 302 to a presigned S3 URL, an A6 PDF.
            $url = "https://apigw.trendyol.com/e2elabel/{$packageId}/{$index}/0e1d2c3b-ecbe-4a5f-b9f9-0123456789ab";
            $signed = "https://s3.trendyol.com/label-bucket-prod/e2e/label/{$packageId}-{$index}/label-data.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=300&X-Amz-Signature=" . str_repeat('ab', 32);
            $data[] = ['format' => 'PDF', 'label' => $url];
            $fixture['documents'][$url] = ['status' => 302, 'location' => $signed];
            $fixture['documents'][$signed] = ['status' => 200, 'contentType' => 'application/pdf', 'base64' => base64_encode($pdfFor($tag . ' ' . $number))];
        }
        $fixture['labels'][$tracking] = ['status' => 200, 'data' => $data];
    }
    $qr = $pdo->prepare("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'");
    $qr->execute([$uuid]);
    return ['id' => $uuid, 'number' => $number, 'packageId' => (string) $packageId, 'qr' => 'ARASYA:Q1:' . $qr->fetchColumn()];
};
$save = static function () use (&$fixture, $fixtureFile): void { file_put_contents($fixtureFile, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); };

if (($argv[1] ?? '') === 'add') {
    $order = $make('packing', 'Picking', ['NEW']);
    $save();
    echo json_encode($order, JSON_THROW_ON_ERROR);
    exit;
}

$call = static function (string $method, string $path, array $body) use ($kernel, $origin, $root): array {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'awb-e2e-' . bin2hex(random_bytes(8))], $root['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E AWB call {$method} {$path} failed: " . json_encode($r['body']));
    }
    return $r['body'];
};
$roleId = null;
foreach ((T::call($kernel, 'GET', '/management/roles', null, [], $root['cookie'])['body']['items'] ?? []) as $role) {
    if ($role['key'] === 'trendyol-awb-station') $roleId = (int) $role['id'];
}
$roleId ?? throw new RuntimeException('The station template is missing.');
$suffix = bin2hex(random_bytes(3));
$person = static function (string $name, string $username, array $stages, array $roles) use ($container, $password, $call, $suffix): string {
    $username .= ".{$suffix}";
    $id = $container->employeeAdmin()->create($name, $username, null, 'pregatire-material', 'employee', $password, $stages, 'e2e')->employeeUuid;
    $call('PUT', "/management/employees/{$id}/applications", ['applications' => ['staff']]);
    $call('PUT', "/management/employees/{$id}/roles", ['roleIds' => $roles]);
    return $username;
};
$people = [
    'station' => $person('ARASYA AWB STATION', 'awb.station', [], [$roleId]),
    'packer' => $person('Ambalator Test', 'awb.packer', ['packing'], [$roleId]),
    'outsider' => $person('Fara Acces Test', 'awb.outsider', ['packing'], []),
];
$orders = [
    'single' => $make('packing', 'Picking', ['A']),
    'parcels' => $make('packing', 'Picking', ['B1', 'B2']),
    'unavailable' => $make('packing', 'Picking', []),
    'cancelled' => $make('packing', 'Cancelled', ['C']),
    'delivery' => $make('delivery', 'Shipped', ['D']),
];
$save();
echo json_encode(['password' => $password, 'people' => $people, 'orders' => $orders], JSON_THROW_ON_ERROR);
