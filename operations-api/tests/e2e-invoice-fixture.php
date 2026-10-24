<?php

declare(strict_types=1);

// SmartBill invoicing E2E fixture (API 2.43.0), run by the invoice spec after the Confirmare spec, in the already prepared
// E2E database. Applies the DRAFT invoicing schema to this TEST database only (database/drafts/smartbill-invoicing.sql;
// never a migration), creates two generic TEST production ticket employees (Romanian and Turkish Staff language) with
// the invoice issue scope of Trend Home, and one approved, paid Confirmare order whose production handoff is ready. The
// TEST shop (tests/e2e-source-shop.php) simulates the shop's SmartBill: nothing is ever issued or sent anywhere.
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
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/drafts/smartbill-invoicing.sql');
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$call = static function (array $who, string $method, string $path, ?array $body = null) use ($kernel, $origin): array {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'inv-e2e-' . bin2hex(random_bytes(8))], $who['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E invoice call {$method} {$path} failed: " . json_encode($r['body']));
    }
    return $r['body'];
};
$documentRole = null;
foreach ($call($root, 'GET', '/management/roles')['items'] as $role) {
    if ($role['key'] === 'production-documents-operator') $documentRole = (int) $role['id'];
}
$people = [];
foreach (['ro' => ['Facturare Fisa', 'inv.docs.e2e', 'ro'], 'tr' => ['Fatura Fisi', 'inv.docs.tr.e2e', 'tr']] as $key => [$name, $username, $locale]) {
    // Generic TEST people: the production document role and scope of Trend Home, and the invoice issue scope.
    $employee = $container->employeeAdmin()->create($name, $username, null, 'pregatire-material', 'employee', $password, [], 'e2e');
    $call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]);
    $call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$documentRole ?? throw new RuntimeException('E2E: no document operator role')]]);
    $call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]);
    $call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/invoice-scopes", ['issue' => ['trendhome'], 'manage' => []]);
    $call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/staff-locale", ['locale' => $locale]);
    $people[$key] = ['username' => $username, 'name' => $name, 'id' => $employee->employeeUuid];
}

// One approved, paid Confirmare order (TEST prices): ingested like YD SOFT sends it, approved by a Confirmare operator.
$number = '91020';
$global = "trendhome:{$number}";
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$activated = (string) $pdo->query("SELECT effective_at FROM workflow_cohort_activations WHERE source_key = 'trendhome' ORDER BY effective_at LIMIT 1")->fetchColumn();
$submitted = (new DateTimeImmutable($activated !== '' ? $activated : 'now', new DateTimeZone('UTC')))->modify('+20 minutes');
$pricing = 'sha256:' . hash('sha256', "inv-e2e-price-{$number}");
$p = T::sourceOrder($number, "inv-e2e-{$number}-" . bin2hex(random_bytes(4)), $now->modify('-30 seconds')->format('Y-m-d\TH:i:s.u\Z'), null, 'processing', 'active', [[
    'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'quantity' => 1,
]]);
unset($p['order']['notes']);
$p['order']['pricing'] = ['fingerprint' => $pricing];
$p['order']['payment'] = ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
$p['order']['financial'] = ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $pricing];
$p['order']['delivery'] = ['name' => 'TEST Client Factura', 'street' => 'Str. Exemplu 9', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000123'];
$p['order']['submission'] = ['submittedAt' => $submitted->format('Y-m-d\TH:i:s.u\Z'), 'evidence' => 'checkout'];
$ingest = T::ingest($kernel, 'trendhome', $p);
if ($ingest['status'] !== 200) {
    throw new RuntimeException('E2E invoice ingestion failed: ' . json_encode($ingest['body']));
}
$file = (string) getenv('ARASYA_E2E_SHOP_FILE');
$shop = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
$shop['orders'][$number] = ['id' => $number, 'number' => $number, 'phone' => '0700000123', 'pricing' => $pricing, 'modifiedAt' => $now->format('Y-m-d\TH:i:s\Z'),
    'payment' => ['method' => 'netopiapayments', 'methodTitle' => 'Card online', 'cod' => false, 'paidRecorded' => true, 'financial' => ['state' => 'cleared', 'basis' => 'online_payment']],
    'invoice' => ['cleared' => true, 'documentType' => 'invoice', 'autoEmail' => false]];
file_put_contents($file, json_encode($shop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$container->confirmareService()->distribute();
$assignee = (string) $pdo->query("SELECT e.username FROM order_confirmations c JOIN employees e ON e.employee_uuid = c.assigned_employee_uuid WHERE c.global_order_id = '{$global}'")->fetchColumn();
$operator = T::login($kernel, $assignee !== '' ? $assignee : 'cf.operator.e2e', $password);
$path = '/confirmare/orders/' . rawurlencode($global);
$detail = $call($operator, 'GET', $path);
$call($operator, 'GET', "{$path}/source");
$review = $detail['review'];
$call($operator, 'POST', "{$path}/contact", ['expectedVersion' => (int) $detail['version'], 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]);
$version = (int) $pdo->query("SELECT version FROM order_confirmations WHERE global_order_id = '{$global}'")->fetchColumn();
$call($operator, 'POST', "{$path}/approve", ['expectedVersion' => $version, 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]);
echo json_encode(['password' => $password, 'people' => $people, 'order' => $global, 'number' => $number], JSON_UNESCAPED_UNICODE), "\n";
