<?php

declare(strict_types=1);

// SmartBill invoicing E2E fixture (API 2.43.0), run by the invoice spec after the Confirmare spec, in the already prepared
// E2E database. Makes sure migration 041 (the invoicing schema) is in this TEST database (it is idempotent), creates two generic TEST production ticket employees (Romanian and Turkish Staff language) with
// the invoice issue scope of Trend Home, and one approved, paid Confirmare order whose production handoff is ready. The
// TEST shop (tests/e2e-source-shop.php) simulates the shop's SmartBill: nothing is ever issued or sent anywhere.
// Prints usernames and the order as JSON. Refuses any database that is not an E2E test database.
// API 2.44.0: the paid order's production ticket is generated and printed and the order is routed to the Romanian TEST
// employee (the QR is printed for the spec to type, like a hand scanner); two more TEST cash-on-delivery orders go to the
// Confirmare operator: 91021 waiting for its advance (the operator's proforma) and 91022 with the advance verified by the
// shop (the operator routes it).

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
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/041_smartbill_invoicing.sql'); // idempotent: the E2E database may predate 041
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
// A generic TEST finance employee with every finance scope of Trend Home and nothing else: reads the SmartBill state from
// the finance flow, never issues or sends (finance is not an invoice permission).
$finance = $container->employeeAdmin()->create('Finante Factura', 'inv.fin.e2e', null, 'pregatire-material', 'employee', $password, [], 'e2e');
$call($root, 'PUT', "/management/employees/{$finance->employeeUuid}/applications", ['applications' => ['staff']]);
$call($root, 'PUT', "/management/employees/{$finance->employeeUuid}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => ['trendhome']]);
$people['fin'] = ['username' => 'inv.fin.e2e', 'name' => 'Finante Factura', 'id' => $finance->employeeUuid];
// API 2.44.0: a second TEST finance employee, who closes what the first recorded (four eyes on unmatched transfers).
$finance2 = $container->employeeAdmin()->create('Finante Doi', 'inv.fin2.e2e', null, 'pregatire-material', 'employee', $password, [], 'e2e');
$call($root, 'PUT', "/management/employees/{$finance2->employeeUuid}/applications", ['applications' => ['staff']]);
$call($root, 'PUT', "/management/employees/{$finance2->employeeUuid}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => ['trendhome']]);
$people['fin2'] = ['username' => 'inv.fin2.e2e', 'name' => 'Finante Doi', 'id' => $finance2->employeeUuid];

// Three approved Confirmare orders (TEST prices), ingested like YD SOFT sends them, approved by the Confirmare operator.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$activated = (string) $pdo->query("SELECT effective_at FROM workflow_cohort_activations WHERE source_key = 'trendhome' ORDER BY effective_at LIMIT 1")->fetchColumn();
$submitted = (new DateTimeImmutable($activated !== '' ? $activated : 'now', new DateTimeZone('UTC')))->modify('+20 minutes');
$file = (string) getenv('ARASYA_E2E_SHOP_FILE');
$shop = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
$orders = ['91020' => 'online', '91021' => 'cod_waiting', '91022' => 'cod_paid',
    // API 2.45.0 master update: a smaller verified advance, an unpaid proforma to cancel, a new total to accept by telephone.
    '91023' => 'cod_partial', '91024' => 'cod_cancel', '91025' => 'cod_total'];
$waiting = static fn (string $kind): bool => in_array($kind, ['cod_waiting', 'cod_cancel', 'cod_total'], true);
foreach ($orders as $number => $kind) {
    $number = (string) $number; // numeric array keys are integers in PHP
    $pricing = 'sha256:' . hash('sha256', "inv-e2e-price-{$number}");
    $p = T::sourceOrder($number, "inv-e2e-{$number}-" . bin2hex(random_bytes(4)), $now->modify('-30 seconds')->format('Y-m-d\TH:i:s.u\Z'), null, 'processing', 'active', [[
        'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = $kind === 'online' ? ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0] : ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $waiting($kind) ? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing]
        : ($kind === 'cod_partial' ? ['state' => 'partial', 'basis' => null, 'pricingFingerprint' => $pricing]
        : ['state' => 'cleared', 'basis' => $kind === 'online' ? 'online_payment' : 'advance_collected', 'pricingFingerprint' => $pricing]);
    $p['order']['delivery'] = ['name' => 'TEST Client Factura', 'street' => 'Str. Exemplu 9', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000123'];
    $p['order']['submission'] = ['submittedAt' => $submitted->format('Y-m-d\TH:i:s.u\Z'), 'evidence' => 'checkout'];
    $ingest = T::ingest($kernel, 'trendhome', $p);
    if ($ingest['status'] !== 200) {
        throw new RuntimeException("E2E invoice ingestion {$number} failed: " . json_encode($ingest['body']));
    }
    $shop['orders'][$number] = ['id' => $number, 'number' => $number, 'phone' => '0700000123', 'pricing' => $pricing, 'modifiedAt' => $now->format('Y-m-d\TH:i:s\Z'),
        'payment' => $kind === 'online' ? ['method' => 'netopiapayments', 'methodTitle' => 'Card online', 'cod' => false, 'paidRecorded' => true, 'financial' => ['state' => 'cleared', 'basis' => 'online_payment']]
            : ['method' => 'cod', 'methodTitle' => 'Ramburs', 'cod' => true, 'paidRecorded' => false, 'financial' => $kind === 'cod_paid' ? ['state' => 'cleared', 'basis' => 'advance_collected'] : ['state' => $kind === 'cod_partial' ? 'partial' : 'waiting', 'basis' => null]],
        'invoice' => ['cleared' => !$waiting($kind) && $kind !== 'cod_partial', 'cod' => $kind !== 'online', 'documentType' => 'invoice', 'autoEmail' => false,
            'partial' => $kind === 'cod_partial', 'totalAccepted' => $kind !== 'cod_total']];
    if (in_array($kind, ['cod_partial', 'cod_cancel'], true)) {
        // An advance proforma already issued in the TEST shop's SmartBill.
        $shop['orders'][$number]['invoice']['pf'] = ['document' => ['series' => 'PRF', 'number' => '09' . substr($number, -2), 'at' => $now->getTimestamp() - 3600, 'by' => null], 'op' => 'issued', 'opKey' => null,
            'mail' => 'sent', 'mailCount' => 1, 'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => []];
    }
}
file_put_contents($file, json_encode($shop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$container->confirmareService()->distribute();
// One operator for the TEST orders (deterministic; test database only).
$operatorName = 'cf.operator.e2e';
$operatorUuid = (string) $pdo->query("SELECT employee_uuid FROM employees WHERE username = '{$operatorName}'")->fetchColumn();
$operator = T::login($kernel, $operatorName, $password);
foreach (array_keys($orders) as $number) {
    $global = 'trendhome:' . $number;
    $pdo->prepare('UPDATE order_confirmations SET assigned_employee_uuid = ?, assigned_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?')->execute([$operatorUuid, $global]);
    $path = '/confirmare/orders/' . rawurlencode($global);
    $detail = $call($operator, 'GET', $path);
    $call($operator, 'GET', "{$path}/source");
    $review = $detail['review'];
    $call($operator, 'POST', "{$path}/contact", ['expectedVersion' => (int) $detail['version'], 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]);
    $version = (int) $pdo->query("SELECT version FROM order_confirmations WHERE global_order_id = '{$global}'")->fetchColumn();
    $call($operator, 'POST', "{$path}/approve", ['expectedVersion' => $version, 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]);
}
// 91020: the Romanian TEST employee generates and prints its production ticket; the operator routes it to that employee.
$employee = T::login($kernel, $people['ro']['username'], $password);
$global = 'trendhome:91020';
$documentVersion = (int) $pdo->query("SELECT document_version FROM operational_orders WHERE global_order_id = '{$global}'")->fetchColumn();
$made = T::call($kernel, 'POST', '/production-documents/orders/' . rawurlencode($global) . '/generate', ['expectedDocumentVersion' => $documentVersion], ['origin' => $origin, 'x-csrf-token' => $employee['csrf'], 'idempotency-key' => 'inv-e2e-' . bin2hex(random_bytes(8))], $employee['cookie']);
if ($made['status'] !== 201) {
    throw new RuntimeException('E2E invoice ticket failed: ' . json_encode($made['body']));
}
// The print answers with the PDF itself (no JSON body).
$printed = T::call($kernel, 'POST', '/production-documents/orders/' . rawurlencode($global) . '/print', ['revisionNumber' => (int) ($made['body']['document']['revisionNumber'] ?? $made['body']['revisionNumber'] ?? 1)], ['origin' => $origin, 'x-csrf-token' => $employee['csrf'], 'idempotency-key' => 'inv-e2e-' . bin2hex(random_bytes(8))], $employee['cookie']);
if ($printed['status'] !== 200) {
    throw new RuntimeException('E2E invoice ticket print failed: HTTP ' . $printed['status']);
}
$call($operator, 'POST', '/confirmare/orders/' . rawurlencode($global) . '/shop-assignment', ['employeeId' => $people['ro']['id'], 'expectedAssigneeId' => null]);
$qr = 'ARASYA:Q1:' . (string) $pdo->query("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = '{$global}' AND q.status = 'active'")->fetchColumn();
echo json_encode(['password' => $password, 'people' => $people, 'order' => $global, 'number' => '91020', 'qr' => $qr, 'operator' => $operatorName,
    'waiting' => 'trendhome:91021', 'paid' => 'trendhome:91022', 'partial' => 'trendhome:91023', 'cancel' => 'trendhome:91024', 'total' => 'trendhome:91025'], JSON_UNESCAPED_UNICODE), "\n";
