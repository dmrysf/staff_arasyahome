<?php

declare(strict_types=1);

// Store Workspace V1 E2E fixture (API 2.83.0, Staff 2.59.0), run by the store spec after the Confirmare, invoice and
// operations specs, in the already prepared E2E database (the Confirmare cohort is active there). TEST ONLY: invented
// customers, the TEST shop (tests/e2e-source-shop.php) simulates FAN Courier, nothing leaves the machine.
//
// Creates one generic TEST shop employee of Trend Home (invoice issue + document operate scopes, the production document
// operator role) and TEST orders 914xx:
//   91401..91403  approved, production cleared, stage 1: documents to print (ready)
//   91404         approved, stage 1, document generated and printed once today (reprint scenario)
//   91410         stage 12, routed to the employee, ready for the AWB (350.00 to collect)
//   91411         stage 12, routed, the delivery locality unknown to FAN Courier (address correction)
//   91412         stage 12, routed, final invoice missing (red blocker)
//   91413         stage 13, routed, ready (0.00 to collect: card order)
//   91414         stage 14, routed: AWB made, cancelled for a correction and made again today (history link)
// Prints the username, password and the orders as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
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
$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on'];
$base = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])), ['trendhome' => $full + ['paymentGate' => 'enforce'], 'outletperdele' => $full + ['paymentGate' => 'off']]);
// The same loopback TEST shop and TEST key as the test API (playwright.real-api.config.ts), so the AWB history below is
// made through the real relay; never a real shop.
$shopUrl = (string) (getenv('ARASYA_E2E_SHOP_URL') ?: 'http://127.0.0.1:8796');
$config = new \Arasya\Operations\Config\Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => $shopUrl, 'key' => 'th-e2e:e2e-shop-access-key-0123456789abcdef-test', 'finance' => 'record', 'invoice' => 'enforce'],
]]);
$pdo = Connection::create($config);
foreach (['043_confirmare_dual_store.sql', '059_awb_reopened.sql', '064_store_workspace.sql'] as $migration) {
    (new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/' . $migration); // idempotent
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$as = static function (array $who, string $method, string $path, ?array $body = null, int $expect = 200, ?string $key = null) use ($kernel, $origin): array {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'store-e2e-' . bin2hex(random_bytes(8))], $who['cookie']);
    if ($r['status'] !== $expect) {
        throw new RuntimeException("E2E store call {$method} {$path} failed: " . json_encode($r['body']));
    }
    return is_array($r['body']) ? $r['body'] : [];
};
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

// The Confirmare cohort of Trend Home (the Confirmare fixture activates it too; this spec may also run on its own).
$registry = SourceRegistry::fromConfig($config);
if ((int) $pdo->query("SELECT COUNT(*) FROM workflow_cohort_activations WHERE source_key = 'trendhome' AND action = 'activated'")->fetchColumn() === 0) {
    // Activation needs one working Confirmare operator of the source (a generic TEST operator).
    $operator = $container->employeeAdmin()->create('Operator Magazin', 'store.op.e2e', null, 'pregatire-material', 'employee', $password, [], 'e2e')->employeeUuid;
    $as($root, 'PUT', "/management/employees/{$operator}/applications", ['applications' => ['staff']]);
    $as($root, 'PUT', "/management/employees/{$operator}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]);
    $now0 = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $activatedAt = max(\Arasya\Operations\Production\WorkflowCohort::cutover()->modify('+1 second'), $now0->modify('-2 hours'));
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find('trendhome'), $activatedAt, 'E2E', 'e2e', 'store-e2e', true);
    T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find('trendhome'), 'E2E', 'e2e', 'store-e2e', true);
}

// The TEST shop employee (never a Confirmare operator).
$username = 'store.th.e2e';
$existing = $pdo->prepare('SELECT employee_uuid FROM employees WHERE username = ?');
$existing->execute([$username]);
$uuid = $existing->fetchColumn();
if (!is_string($uuid)) {
    $uuid = $container->employeeAdmin()->create('Angajat Magazin', $username, null, 'pregatire-material', 'employee', $password, [], 'e2e')->employeeUuid;
}
$roles = [];
foreach ($as($root, 'GET', '/management/roles')['items'] as $role) {
    $roles[$role['key']] = (int) $role['id'];
}
$as($root, 'PUT', "/management/employees/{$uuid}/applications", ['applications' => ['staff']]);
$as($root, 'PUT', "/management/employees/{$uuid}/roles", ['roleIds' => [$roles['production-documents-operator']]]);
$as($root, 'PUT', "/management/employees/{$uuid}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]);
$as($root, 'PUT', "/management/employees/{$uuid}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]);

// TEST orders through the real ingestion (same products as the TEST shop view).
$names = ['91401' => ['TEST Maria', 'Ionescu', 'Brașov'], '91402' => ['TEST Andrei', 'Pop', 'Cluj-Napoca'], '91403' => ['TEST Elena', 'Dumitru', 'Sector 3'], '91404' => ['TEST Ioana', 'Marin', 'Iași'],
    '91410' => ['TEST Mihai', 'Stan', 'Brașov'], '91411' => ['TEST Ana', 'Radu', 'Necunoscut'], '91412' => ['TEST Vlad', 'Georgescu', 'Constanța'], '91413' => ['TEST Irina', 'Toma', 'Sibiu'],
    '91414' => ['TEST Dan', 'Matei', 'Timișoara'], '91405' => ['TEST Sorin', 'Neagu', 'Ploiești']];
$file = (string) getenv('ARASYA_E2E_SHOP_FILE');
$shop = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$tick = 0;
foreach ($names as $number => [$first, $last, $city]) {
    $number = (string) $number;
    $tick++;
    $p = T::sourceOrder((string) $number, "store-e2e-{$number}-" . bin2hex(random_bytes(4)), $iso($now->modify('-90 seconds')->modify("+{$tick} milliseconds")), null, 'processing', 'active', [[
        'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $fingerprint = 'sha256:' . hash('sha256', "store-e2e-price-{$number}");
    $p['order']['pricing'] = ['fingerprint' => $fingerprint];
    $p['order']['payment'] = ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
    $p['order']['financial'] = ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $fingerprint];
    $p['order']['delivery'] = ['name' => "{$first} {$last}", 'street' => 'Str. Exemplu 7', 'city' => $city, 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000123'];
    $p['order']['submission'] = ['submittedAt' => $iso($now->modify('-80 seconds')->modify("+{$tick} milliseconds")), 'evidence' => 'checkout'];
    $r = T::ingest($kernel, 'trendhome', $p);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E store ingestion {$number} failed: " . json_encode($r['body']));
    }
    $county = $city === 'Sector 3' ? 'B' : 'BV';
    $owed = $number === '91413' ? '0.00' : '350.00';
    $shop['orders'][$number] = ['id' => $number, 'number' => $number, 'phone' => '0700000123', 'pricing' => $fingerprint, 'eligible' => true,
        'payment' => ['method' => 'netopiapayments', 'methodTitle' => 'Card online', 'cod' => false, 'paidRecorded' => true, 'financial' => ['state' => 'cleared', 'basis' => 'online_payment']],
        'ops' => ['status' => 'processing', 'owed' => $owed, 'invoice' => $number !== '91412', 'awb' => null,
            'delivery' => ['target' => 'shipping', 'firstName' => $first, 'lastName' => $last, 'company' => '', 'phone' => '0722 111 2' . substr($number, -2), 'county' => $county, 'city' => $city,
                'street' => 'Str. Exemplu', 'streetNo' => '7', 'building' => '', 'entrance' => '', 'floor' => '', 'apartment' => '', 'postcode' => '500000', 'note' => '']]];
}
file_put_contents($file, json_encode($shop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// TEST ONLY: the operator's decisions and the factory's stages are tested elsewhere; here they are set directly.
$global = static fn (string|int $n): string => "trendhome:{$n}";
$approve = $pdo->prepare("UPDATE order_confirmations SET status = 'approved', decided_at = UTC_TIMESTAMP(6), approved_review_fingerprint = REPEAT('e', 64), call_confirmed_at = UTC_TIMESTAMP(6), cleared_at = UTC_TIMESTAMP(6), production_cleared = 1, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE global_order_id = ? AND status = 'pending'");
foreach (array_keys($names) as $number) {
    if (!in_array((string) $number, ['91401', '91405'], true)) $approve->execute([$global((string) $number)]);
}
// 91401 and 91405 (Staff 2.60.0): verified online payments the system approved without a call (API 2.82.0), routed to the
// employee, as on the live shops. The real system approval is used (no direct UPDATE of the decision). 91401's customer
// check is recorded below by the employee (so its production, document and final invoice follow the real chain); 91405
// still waits for it.
$container->confirmareService()->approveVerifiedOnline(500, 'store-e2e-online');
// The routing is recorded by another person (an assignment is never to oneself): the TEST root.
$assigner = (string) $pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
$stage = $pdo->prepare('UPDATE operational_orders SET production_stage_id = ?, production_changed_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?');
// 91401 (Staff 2.60.0) and 91405 are routed to the employee at the first stage: the first gets its final invoice right after
// "Pregătește și tipărește", the second waits for the customer check.
foreach (['91401' => null, '91405' => null, '91410' => 'quality-control', '91411' => 'quality-control', '91412' => 'quality-control', '91413' => 'packing', '91414' => 'delivery'] as $number => $id) {
    if ($id !== null) $stage->execute([$id, $global($number)]);
    $pdo->prepare("INSERT INTO order_shop_assignments (assignment_uuid, order_uuid, global_order_id, source_key, employee_uuid, assigned_by_employee_uuid, assigned_role, idempotency_key, request_hmac, state, assigned_at)
        SELECT ?, o.order_uuid, o.global_order_id, 'trendhome', ?, ?, 'manager', ?, ?, 'active', UTC_TIMESTAMP(6) FROM operational_orders o WHERE o.global_order_id = ?
          AND NOT EXISTS (SELECT 1 FROM order_shop_assignments a WHERE a.active_order_uuid = o.order_uuid)")
        ->execute([\Arasya\Operations\Support\Uuid::v4(), $uuid, $assigner, 'store-e2e-route-' . bin2hex(random_bytes(8)), random_bytes(32), $global($number)]);
}
$alina = T::login($kernel, $username, $password);
if (\Arasya\Operations\Confirmare\ConfirmareService::awaitingCustomerCheck($pdo, (string) $pdo->query("SELECT order_uuid FROM operational_orders WHERE global_order_id = 'trendhome:91401'")->fetchColumn())) {
    $as($alina, 'POST', '/confirmare/orders/' . rawurlencode($global('91401')) . '/customer-check', ['outcome' => 'confirmed']);
}
// 91404: revision 1 generated and printed once today by the employee (the reprint scenario).
$doc = $as($alina, 'GET', '/production-documents/orders/' . rawurlencode($global('91404')));
if (($doc['status'] ?? '') === 'none') {
    $doc = $as($alina, 'POST', '/production-documents/orders/' . rawurlencode($global('91404')) . '/generate', ['expectedDocumentVersion' => (int) $doc['version']], 201);
}
if ((int) ($doc['activeRevision']['prints'] ?? 0) === 0) {
    $r = T::call($kernel, 'POST', '/production-documents/orders/' . rawurlencode($global('91404')) . '/print', ['revisionNumber' => 1], ['origin' => $origin, 'x-csrf-token' => $alina['csrf'], 'idempotency-key' => 'store-e2e-print-' . bin2hex(random_bytes(8))], $alina['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException('E2E store print failed: ' . json_encode($r['body']));
    }
}
// 91414: an AWB made, cancelled for a correction (wrong parcels) and made again with 2 parcels, today.
$ops = static fn (string $path, array $body): array => $as($alina, 'POST', '/confirmare/orders/' . rawurlencode($global('91414')) . '/' . $path, $body);
$state = $as($alina, 'GET', '/confirmare/orders/' . rawurlencode($global('91414')) . '/operations');
if (($state['shop']['awb']['state'] ?? 'none') === 'none' && (int) $pdo->query("SELECT COUNT(*) FROM order_source_operations WHERE global_order_id = 'trendhome:91414' AND kind = 'awb_cancel'")->fetchColumn() === 0) {
    $ops('awb', ['parcels' => 1, 'expectedCod' => '350.00']);
    $ops('awb/cancel', ['reason' => 'wrong_parcels']);
    $ops('awb', ['parcels' => 2, 'expectedCod' => '350.00']);
}
echo json_encode(['password' => $password, 'username' => $username, 'docs' => ['91401', '91402', '91403'], 'printed' => '91404', 'check' => '91405',
    'ready' => '91410', 'address' => '91411', 'blocked' => '91412', 'card' => '91413', 'history' => '91414'], JSON_UNESCAPED_UNICODE), "\n";
