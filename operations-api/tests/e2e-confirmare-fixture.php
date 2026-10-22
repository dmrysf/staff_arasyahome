<?php

declare(strict_types=1);

// Confirmare Staff workspace E2E fixture (API 2.39.0), run by the Confirmare spec after every earlier suite, in the
// already prepared E2E database. Generic test people (never a real employee's name) receive Confirmare scopes from root
// through the API; both shop sources are activated with the TEST override of WORKFLOW_READY (refused outside tests:
// the production CLI has no such switch); orders arrive through the signed source contract exactly as YD SOFT sends
// them. Prints usernames and order numbers as JSON. Refuses any database that is not an E2E test database.
//
//   php e2e-confirmare-fixture.php                 seed (once per run)
//   php e2e-confirmare-fixture.php --change=<n>    the shop changes an option of order <n> (a new review is needed)
//
// API 2.40.0: the TEST shop (tests/e2e-source-shop.php) answers source access reads from ARASYA_E2E_SHOP_FILE, which
// this fixture writes for the Trend Home orders (same pricing fingerprints as their events, an invented telephone).

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
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
$config = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])), ['trendhome' => $full + ['paymentGate' => 'enforce'], 'outletperdele' => $full + ['paymentGate' => 'off']]);
$pdo = Connection::create($config);
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$password = 'e2e passphrase 2026';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$shopOrders = [];
$order = static function (string $number, ?DateTimeImmutable $submitted, array $extra = []) use ($iso, &$shopOrders): array {
    static $tick = 0;
    $tick++;
    $changed = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-60 seconds')->modify("+{$tick} milliseconds");
    $p = T::sourceOrder($number, "cf-e2e-{$number}-" . bin2hex(random_bytes(4)), $iso($changed), null, 'processing', 'active', [[
        'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'variant' => 'Wave', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'quantity' => 1,
        'options' => [['label' => 'Rejansă', 'value' => $extra['option'] ?? 'Inele']],
    ], [
        'id' => (int) $number * 10 + 2, 'line' => 2, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 400, 'height' => 250, 'unit' => 'cm', 'quantity' => 2,
    ]]);
    unset($p['order']['notes']);
    $priced = ($extra['pricing'] ?? true) === true;
    $fingerprint = 'sha256:' . hash('sha256', "cf-e2e-price-{$number}-" . ($extra['option'] ?? 'Inele'));
    if ($priced) {
        $p['order']['pricing'] = ['fingerprint' => $fingerprint];
    }
    // The TEST shop's own view of the same order (source access): same prices, the invented full telephone number.
    $shopOrders[$number] = ['id' => $number, 'number' => $number, 'phone' => '0700000123', 'pricing' => $priced ? $fingerprint : null, 'modifiedAt' => $changed->format('Y-m-d\TH:i:s\Z'),
        'payment' => ($extra['payment'] ?? 'online') === 'online'
            ? ['method' => 'netopiapayments', 'methodTitle' => 'Card online', 'cod' => false, 'paidRecorded' => true, 'financial' => ['state' => 'cleared', 'basis' => 'online_payment']]
            : ['method' => 'cod', 'methodTitle' => 'Ramburs', 'cod' => true, 'paidRecorded' => false, 'financial' => ['state' => 'waiting', 'basis' => null], 'advance' => ['percentage' => 30, 'required' => '215.70', 'collected' => null]]];
    $online = ($extra['payment'] ?? 'online') === 'online';
    $p['order']['payment'] = $online ? ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0] : ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $online && $priced ? ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $fingerprint] : ['state' => $priced ? 'waiting' : 'unknown', 'basis' => null, 'pricingFingerprint' => $priced ? $fingerprint : null];
    if (isset($extra['due'])) {
        $p['order']['dueDate'] = $extra['due'];
    }
    // A TEST customer: a fixed fictitious name and an invented number; the full number never leaves the source.
    $p['order']['delivery'] = ['name' => 'TEST Client Confirmare', 'street' => 'Str. Exemplu 7', 'city' => 'Brașov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000123'];
    if ($submitted !== null) {
        $p['order']['submission'] = ['submittedAt' => $iso($submitted), 'evidence' => 'checkout'];
    }
    return $p;
};
$ingest = static function (string $source, array $payload) use ($kernel): void {
    $r = T::ingest($kernel, $source, $payload);
    if ($r['status'] !== 200) {
        throw new RuntimeException('E2E Confirmare ingestion failed: ' . json_encode($r['body']));
    }
};

$writeShop = static function () use (&$shopOrders): void {
    $file = (string) getenv('ARASYA_E2E_SHOP_FILE');
    if ($file === '') {
        return;
    }
    $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    $orders = is_array($existing['orders'] ?? null) ? $existing['orders'] : [];
    foreach ($shopOrders as $number => $shopOrder) {
        if (str_starts_with((string) $number, '91')) {
            $orders[(string) $number] = $shopOrder;
        }
    }
    file_put_contents($file, json_encode(['orders' => $orders], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
$change = null;
foreach ($argv as $argument) {
    if (preg_match('/^--change=([0-9]{5})$/D', $argument, $m) === 1) {
        $change = $m[1];
    }
}
if ($change !== null) {
    $source = str_starts_with($change, '92') ? 'outletperdele' : 'trendhome';
    $submitted = $pdo->prepare('SELECT source_submitted_at FROM operational_orders WHERE global_order_id = ?');
    $submitted->execute(["{$source}:{$change}"]);
    $at = $submitted->fetchColumn();
    $ingest($source, $order($change, $at === false || $at === null ? null : new DateTimeImmutable((string) $at, new DateTimeZone('UTC')), ['option' => 'Rejansă creion', 'payment' => $change === '91002' ? 'cod' : 'online']));
    $writeShop();
    echo json_encode(['changed' => "{$source}:{$change}"]), "\n";
    exit(0);
}

$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    echo json_encode(['skip' => 'The clock is before the Confirmare cutover.']), "\n";
    exit(0);
}
$root = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
$asRoot = static function (string $method, string $path, ?array $body = null) use ($kernel, $root, $origin): array {
    $r = T::call($kernel, $method, $path, $body, ['origin' => $origin, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'cf-e2e-root-' . bin2hex(random_bytes(8))], $root['cookie']);
    if ($r['status'] !== 200) {
        throw new RuntimeException("E2E Confirmare root call {$path} failed: " . json_encode($r['body']));
    }
    return $r['body'];
};
$admin = $container->employeeAdmin();
$people = [];
foreach ([
    'operator' => ['Operator Confirmare', 'cf.operator.e2e', ['operate' => ['trendhome'], 'supervise' => []]],
    'colleague' => ['Coleg Confirmare', 'cf.colleague.e2e', ['operate' => ['trendhome'], 'supervise' => []]],
    'outlet' => ['Operator Outlet', 'cf.outlet.e2e', ['operate' => ['outletperdele'], 'supervise' => []]],
    'supervisor' => ['Supervizor Confirmare', 'cf.supervisor.e2e', ['operate' => [], 'supervise' => ['outletperdele', 'trendhome']]],
] as $role => [$name, $username, $scopes]) {
    // Generic TEST people; access only from the scopes root grants below, never from a name or a department.
    $employee = $admin->create($name, $username, null, 'pregatire-material', 'employee', $password, [], 'e2e');
    $asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]);
    $asRoot('PUT', "/management/employees/{$employee->employeeUuid}/confirmare-scopes", $scopes);
    $people[$role] = ['username' => $username, 'name' => $name, 'id' => $employee->employeeUuid];
}

$registry = SourceRegistry::fromConfig($config);
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $source) {
    // TEST override of WORKFLOW_READY (the last argument): the production CLI cannot pass it.
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($source), $activatedAt, 'E2E', 'e2e', 'cf-e2e', true);
    T::ingest($kernel, $source, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($source), 'E2E', 'e2e', 'cf-e2e', true);
}
$after = static fn (int $minutes): DateTimeImmutable => $activatedAt->modify("+{$minutes} minutes");
// The operator's work (due date first): 91001 online and due soonest, 91002 cash on delivery without its advance.
$ingest('trendhome', $order('91001', $after(5), ['due' => $now->modify('+3 days')->format('Y-m-d')]));
$ingest('trendhome', $order('91002', $after(6), ['payment' => 'cod', 'due' => $now->modify('+5 days')->format('Y-m-d')]));
$ingest('trendhome', $order('91003', $after(7)));
$ingest('trendhome', $order('91004', $after(8), ['pricing' => false]));
$ingest('outletperdele', $order('92001', $after(9)));
$service = $container->confirmareService();
// The server distributes (never the browser): two operators of Trend Home, one of Outlet Perdele.
$service->distribute();
// After the run: one order waits unassigned, one legacy order and one with an unknown submission instant.
$ingest('trendhome', $order('91005', $after(10)));
$ingest('trendhome', $order('91090', $activatedAt->modify('-1 hour')));
$ingest('trendhome', $order('91091', null));
$assignee = static function (string $globalId) use ($pdo): ?string {
    $s = $pdo->prepare('SELECT e.username FROM order_confirmations c JOIN employees e ON e.employee_uuid = c.assigned_employee_uuid WHERE c.global_order_id = ?');
    $s->execute([$globalId]);
    $value = $s->fetchColumn();
    return $value === false ? null : (string) $value;
};
// Make the fixture deterministic: 91001 and 91002 with the operator, 91003 and 91004 with the colleague.
foreach (['91001' => 'operator', '91002' => 'operator', '91003' => 'colleague', '91004' => 'colleague'] as $number => $role) {
    if ($assignee("trendhome:{$number}") !== $people[$role]['username']) {
        $version = (int) $pdo->query("SELECT version FROM order_confirmations WHERE global_order_id = 'trendhome:{$number}'")->fetchColumn();
        $asRoot('POST', '/confirmare/orders/' . rawurlencode("trendhome:{$number}") . '/reassign', ['expectedVersion' => $version, 'employeeId' => $people[$role]['id']]);
    }
}
$writeShop();
echo json_encode(['password' => $password, 'people' => $people, 'orders' => [
    'call' => 'trendhome:91001', 'cod' => 'trendhome:91002', 'colleague' => 'trendhome:91003', 'noPrice' => 'trendhome:91004', 'unassigned' => 'trendhome:91005',
    'outlet' => 'outletperdele:92001', 'legacy' => 'trendhome:91090', 'undetermined' => 'trendhome:91091',
], 'state' => ['91001' => $assignee('trendhome:91001'), '91003' => $assignee('trendhome:91003')]], JSON_UNESCAPED_UNICODE), "\n";
