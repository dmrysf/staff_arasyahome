<?php

declare(strict_types=1);

// Finance verification requests E2E fixture (API 2.50.0, Staff 2.41.0), run by the finance requests spec after the
// Confirmare and invoice specs, in the already prepared E2E database. Makes sure migration 044 is in this TEST database
// (idempotent), creates generic TEST people: a finance recorder (view + record of Trend Home), a second finance employee
// who approves (view + approve), and a finance supervisor (view, observing both); and three approved TEST cash-on-delivery
// orders of Trend Home whose advance proforma exists in the TEST shop and whose advance is still waiting. The TEST shop
// (tests/e2e-source-shop.php) records the transfer; nothing reaches a real shop, bank or customer.
// Prints usernames and the order as JSON. Refuses any database that is not an E2E test database.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
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
$shopKey = 'e2e-shop-access-key-0123456789abcdef-test';
$config = new \Arasya\Operations\Config\Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => 'http://127.0.0.1:8796', 'key' => 'th-e2e:' . $shopKey, 'finance' => 'record', 'invoice' => 'enforce'],
]]);
$pdo = Connection::create($config);
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/044_finance_verification_requests.sql'); // idempotent
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$call = static function (array $who, string $method, string $path, ?array $body = null) use ($kernel, $origin): array {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'fr-e2e-' . bin2hex(random_bytes(8))], $who['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E finance requests call {$method} {$path} failed: " . json_encode($r['body']));
    }
    return $r['body'];
};
$people = [];
foreach (['rec' => ['Contabil Unu', 'fr.rec.e2e', ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => []]],
    'app' => ['Contabil Doi', 'fr.app.e2e', ['view' => ['trendhome'], 'record' => [], 'approve' => ['trendhome']]],
    'sup' => ['Director Financiar', 'fr.sup.e2e', ['view' => ['trendhome'], 'record' => [], 'approve' => []]]] as $key => [$name, $username, $scopes]) {
    $existing = $pdo->prepare('SELECT employee_uuid FROM employees WHERE username_normalized = ?');
    $existing->execute([$username]);
    $id = $existing->fetchColumn();
    if (!is_string($id)) {
        $id = $container->employeeAdmin()->create($name, $username, null, 'pregatire-material', 'employee', $password, [], 'e2e')->employeeUuid;
    }
    $call($root, 'PUT', "/management/employees/{$id}/applications", ['applications' => ['staff']]);
    $call($root, 'PUT', "/management/employees/{$id}/finance-scopes", $scopes);
    $people[$key] = ['username' => $username, 'name' => $name, 'id' => $id];
}
// Only these two TEST finance employees receive requests and second checks of Trend Home here: the other TEST finance
// people of earlier specs lose their record and approve scopes of Trend Home (test database only), so the assignment and
// the named checker are deterministic.
$others = $pdo->prepare("SELECT DISTINCT s.employee_uuid FROM employee_finance_scopes s WHERE s.source_key = 'trendhome' AND s.capability IN ('record', 'verify', 'approve') AND s.employee_uuid NOT IN (?, ?, ?)");
$others->execute([$people['rec']['id'], $people['app']['id'], $people['sup']['id']]);
foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $other) {
    $call($root, 'PUT', "/management/employees/{$other}/finance-scopes", ['view' => [], 'record' => [], 'approve' => []]);
}
$call($root, 'PUT', "/management/employees/{$people['sup']['id']}/finance-supervision", ['employeeIds' => [$people['rec']['id'], $people['app']['id']]]);

// Three approved TEST cash-on-delivery orders with their advance proforma issued in the TEST shop; each advance is
// still waiting. 91040: verified under four eyes; 91041: not received (and the operator's polling timing); 91042: a
// clarification the operator answers.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$activated = (string) $pdo->query("SELECT effective_at FROM workflow_cohort_activations WHERE source_key = 'trendhome' ORDER BY effective_at LIMIT 1")->fetchColumn();
$submitted = (new DateTimeImmutable($activated !== '' ? $activated : 'now', new DateTimeZone('UTC')))->modify('+25 minutes');
$file = (string) getenv('ARASYA_E2E_SHOP_FILE');
$operatorName = 'cf.operator.e2e';
$operatorUuid = (string) $pdo->query("SELECT employee_uuid FROM employees WHERE username = '{$operatorName}'")->fetchColumn();
$operator = T::login($kernel, $operatorName, $password);
$orders = [];
foreach (['91040' => '0940', '91041' => '0941', '91042' => '0942'] as $number => $proformaNumber) {
    $number = (string) $number;
    $global = 'trendhome:' . $number;
    $shop = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $pricing = 'sha256:' . hash('sha256', "fr-e2e-price-{$number}");
    $p = T::sourceOrder($number, "fr-e2e-{$number}-" . bin2hex(random_bytes(4)), $now->modify('-30 seconds')->format('Y-m-d\TH:i:s.u\Z'), null, 'processing', 'active', [[
        'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Plata', 'street' => 'Str. Exemplu 11', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000456'];
    $p['order']['submission'] = ['submittedAt' => $submitted->format('Y-m-d\TH:i:s.u\Z'), 'evidence' => 'checkout'];
    $ingest = T::ingest($kernel, 'trendhome', $p);
    if ($ingest['status'] !== 200) {
        throw new RuntimeException("E2E finance requests ingestion failed: " . json_encode($ingest['body']));
    }
    $shop['orders'][$number] = ['id' => $number, 'number' => $number, 'phone' => '0700000456', 'pricing' => $pricing, 'modifiedAt' => $now->format('Y-m-d\TH:i:s\Z'),
        'payment' => ['method' => 'cod', 'methodTitle' => 'Ramburs', 'cod' => true, 'paidRecorded' => false, 'financial' => ['state' => 'waiting', 'basis' => null]],
        'finance' => ['cod' => true, 'total' => '719.00', 'pricing' => $pricing],
        'invoice' => ['cleared' => false, 'cod' => true, 'documentType' => 'invoice', 'autoEmail' => false, 'partial' => false, 'totalAccepted' => true,
            'pf' => ['document' => ['series' => 'PRF', 'number' => $proformaNumber, 'at' => $now->getTimestamp() - 3600, 'by' => null], 'op' => 'issued', 'opKey' => null,
                'mail' => 'sent', 'mailCount' => 1, 'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => []]]];
    file_put_contents($file, json_encode($shop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $container->confirmareService()->distribute();
    $pdo->prepare('UPDATE order_confirmations SET assigned_employee_uuid = ?, assigned_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?')->execute([$operatorUuid, $global]);
    $path = '/confirmare/orders/' . rawurlencode($global);
    $detail = $call($operator, 'GET', $path);
    $call($operator, 'GET', "{$path}/source");
    $review = $detail['review'];
    $call($operator, 'POST', "{$path}/contact", ['expectedVersion' => (int) $detail['version'], 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]);
    $version = $pdo->prepare('SELECT version FROM order_confirmations WHERE global_order_id = ?');
    $version->execute([$global]);
    $call($operator, 'POST', "{$path}/approve", ['expectedVersion' => (int) $version->fetchColumn(), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]);
    $orders[$number] = ['order' => $global, 'number' => $number, 'reference' => 'TH' . $number];
}
// Owner decision 2026-10-29: every order submitted from 22.10.2026 00:00 Bucharest is covered, whatever its workflow.
// 91043: a COD order submitted on 23.10.2026 (before this TEST shop's Confirmare activation: legacy, the old workflow;
// the TEST shop reads it for finance only and never records its payment). 91044: submitted on 21.10.2026 23:59:59
// Bucharest (outside the scope). Neither is moved into Confirmare.
foreach (['91043' => ['2026-10-23T08:00:00.000000Z', '0943', true], '91044' => ['2026-10-21T20:59:59.000000Z', '0944', false]] as $number => [$submittedAt, $proformaNumber, $covered]) {
    $number = (string) $number;
    $shop = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $pricing = 'sha256:' . hash('sha256', "fr-e2e-price-{$number}");
    $p = T::sourceOrder($number, "fr-e2e-{$number}-" . bin2hex(random_bytes(4)), $now->modify('-30 seconds')->format('Y-m-d\TH:i:s.u\Z'), null, 'processing', 'active', [[
        'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Plata', 'street' => 'Str. Exemplu 11', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000456'];
    $p['order']['submission'] = ['submittedAt' => $submittedAt, 'evidence' => 'checkout'];
    $ingest = T::ingest($kernel, 'trendhome', $p);
    if ($ingest['status'] !== 200) {
        throw new RuntimeException("E2E finance requests ingestion failed: " . json_encode($ingest['body']));
    }
    $shop['financeVerification'] = 'four_eyes';
    $shop['orders'][$number] = ['id' => $number, 'number' => $number, 'eligible' => false, 'legacyFinance' => $covered, 'phone' => '0700000456', 'pricing' => $pricing, 'modifiedAt' => $now->format('Y-m-d\TH:i:s\Z'),
        'payment' => ['method' => 'cod', 'methodTitle' => 'Ramburs', 'cod' => true, 'paidRecorded' => false, 'financial' => ['state' => 'waiting', 'basis' => null]],
        'finance' => ['cod' => true, 'total' => '719.00', 'pricing' => $pricing],
        'invoice' => ['cleared' => false, 'cod' => true, 'documentType' => 'invoice', 'autoEmail' => false, 'partial' => false, 'totalAccepted' => true,
            'pf' => ['document' => ['series' => 'PRF', 'number' => $proformaNumber, 'at' => $now->getTimestamp() - 3600, 'by' => null], 'op' => 'issued', 'opKey' => null,
                'mail' => 'sent', 'mailCount' => 1, 'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => []]]];
    file_put_contents($file, json_encode($shop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
$cohort = $pdo->prepare('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?');
foreach (['91043', '91044'] as $number) {
    $cohort->execute(['trendhome:' . $number]);
    if ($cohort->fetchColumn() === 'confirmare') {
        throw new RuntimeException("E2E finance requests: order {$number} must stay outside Confirmare.");
    }
}
echo json_encode(['password' => $password, 'people' => $people, 'operator' => $operatorName, 'order' => $orders['91040']['order'], 'number' => '91040', 'reference' => $orders['91040']['reference'],
    'notReceived' => $orders['91041'], 'clarify' => $orders['91042'], 'legacy' => ['order' => 'trendhome:91043', 'number' => '91043', 'reference' => 'TH91043'],
    'before' => ['order' => 'trendhome:91044', 'number' => '91044', 'reference' => 'TH91044'], 'customer' => 'TEST Client Plata', 'phone' => '0700000456'], JSON_UNESCAPED_UNICODE), "\n";
