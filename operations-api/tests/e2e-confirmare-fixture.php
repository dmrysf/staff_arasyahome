<?php

declare(strict_types=1);

// Confirmare Staff workspace E2E fixture (API 2.39.0), run by the Confirmare spec after every earlier suite, in the
// already prepared E2E database. Generic test people (never a real employee's name) receive Confirmare scopes from root
// through the API; both shop sources are activated with the TEST override of the source readiness (refused outside tests:
// the production CLI has no such switch); orders arrive through the signed source contract exactly as YD SOFT sends
// them. Prints usernames and order numbers as JSON. Refuses any database that is not an E2E test database.
//
//   php e2e-confirmare-fixture.php                 seed (once per run)
//   php e2e-confirmare-fixture.php --change=<n>    the shop changes an option of order <n> (a new review is needed)
//
// API 2.40.0: the TEST shop (tests/e2e-source-shop.php) answers source access reads from ARASYA_E2E_SHOP_FILE, which
// this fixture writes for the Trend Home orders (same pricing fingerprints as their events, an invented telephone).
// API 2.41.0: two finance people (record, approve) and the TEST money record of order 91005 (paid 500, now 650).

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
    // Staff 2.58.0: a workspace order brings its own TEST shop view (lines, totals, catalog, customer).
    if (isset($extra['shop'])) {
        $shopOrders[$number] = array_merge($shopOrders[$number], $extra['shop']);
    }
    $online = ($extra['payment'] ?? 'online') === 'online';
    $p['order']['payment'] = $online ? ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0] : ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $online && $priced ? ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $fingerprint] : ['state' => $priced ? 'waiting' : 'unknown', 'basis' => null, 'pricingFingerprint' => $priced ? $fingerprint : null];
    if (isset($extra['financial'])) {
        // API 2.50.0: the money state the shop sends with the order (a finance scope reaches the order only through it).
        $p['order']['financial'] = $extra['financial'] + ['pricingFingerprint' => $fingerprint];
    }
    if (isset($extra['due'])) {
        $p['order']['dueDate'] = $extra['due'];
    }
    // A TEST customer: a fixed fictitious name and an invented number; the full number never leaves the source.
    $p['order']['delivery'] = ['name' => $extra['name'] ?? 'TEST Client Confirmare', 'street' => 'Str. Exemplu 7', 'city' => 'Brașov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0700000123'];
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

// Staff 2.57.0 (Operation Simplification V1): `--desk` adds three fresh cash-on-delivery orders (91201-91203) for the desk
// spec, after the main seed (the people and the activation exist). Unassigned: opening an order on the desk takes it.
if (in_array('--desk', $argv, true)) {
    foreach (['91201', '91202', '91203'] as $index => $deskNumber) {
        $ingest('trendhome', $order($deskNumber, $now->modify('-' . (40 - $index) . ' minutes'), ['payment' => 'cod']));
    }
    $writeShop();
    // The finance requests fixture of an earlier spec takes Trend Home away from the other TEST finance people (test
    // database only); the desk spec's finance employee gets her record scope back, through root, like the main seed.
    $finrec = $pdo->prepare('SELECT employee_uuid FROM employees WHERE username = ?');
    $finrec->execute(['cf.finrec.e2e']);
    $finrecId = $finrec->fetchColumn();
    if (is_string($finrecId)) {
        $deskRoot = T::login($kernel, RootBootstrapService::ROOT_USERNAME, 'staff root fixture permanent 2026');
        $granted = T::call($kernel, 'PUT', "/management/employees/{$finrecId}/finance-scopes", ['view' => [], 'record' => ['trendhome'], 'approve' => []], ['origin' => $origin, 'x-csrf-token' => $deskRoot['csrf'], 'idempotency-key' => 'cf-e2e-desk-' . bin2hex(random_bytes(8))], $deskRoot['cookie']);
        if ($granted['status'] !== 200) {
            throw new RuntimeException('E2E desk finance scope failed: ' . json_encode($granted['body']));
        }
    }
    echo json_encode(['orders' => ['first' => 'trendhome:91201', 'second' => 'trendhome:91202', 'third' => 'trendhome:91203']]), "\n";
    exit(0);
}

// Staff 2.58.0 (Operator Workspace V2): `--workspace` adds the visual test orders after `--desk`: 91301 is TH 65794's
// shape (one curtain, 6 x 2.50 m, 2 panels, rejansă on a pole, prices with 21 % VAT, flat shipping, total 355.32, advance
// 106.60), 91302 has 4 products (one out of stock, a coupon, free shipping, a possible duplicate, no postcode), 91303 has 10
// and 91304 has 20 products with long names. Invented TEST customers and products only; amounts are coherent TEST figures.
if (in_array('--workspace', $argv, true)) {
    $cents = static fn (string $amount): int => (int) round((float) $amount * 100);
    $money = static fn (int $c): string => number_format($c / 100, 2, '.', '');
    $sewing = [['value' => '8:Manopera Rejansa Bara (Țeavă)', 'title' => 'Manopera Rejansa Bara (Țeavă)'], ['value' => '13:Manopera Rejansa Normal 6cm', 'title' => 'Manopera Rejansa Normal 6cm'],
        ['value' => '18:Manopera Rejansa Creion cu Inele Metalice Argintii 10 cm', 'title' => 'Manopera Rejansa Creion cu Inele Metalice Argintii 10 cm'], ['value' => '0:Fără manoperă', 'title' => 'Fără manoperă']];
    $pieces = [['value' => '1:1 buc.', 'title' => '1 buc.'], ['value' => '2:2 buc.', 'title' => '2 buc.'], ['value' => '3:3 buc.', 'title' => '3 buc.'], ['value' => '4:4 buc.', 'title' => '4 buc.']];
    // One TEST line: the gross price (with VAT) splits into net + 21 % VAT exactly like a shop stores it.
    $line = static function (string $orderNo, int $n, array $spec) use ($cents, $money, $sewing, $pieces): array {
        $gross = $cents($spec['gross']);
        $net = (int) round($gross / 1.21);
        $id = (string) ((int) $orderNo * 100 + $n);
        $curtain = $spec['curtain'] ?? true;
        $details = [['label' => 'Culoare', 'value' => $spec['color'] ?? 'Alb']];
        if ($curtain) {
            $details = array_merge($details, [['label' => 'Lungimea (L)', 'value' => $spec['w']], ['label' => 'Inaltime (H)', 'value' => $spec['h']],
                ['label' => 'Manopera', 'value' => $spec['sewing'] ?? 'Manopera Rejansa Bara (Țeavă)'], ['label' => 'buc', 'value' => ($spec['buc'] ?? '2') . ' buc.'],
                ['label' => 'trendhome-tools_monopera_cost', 'value' => '8']]);
        }
        $sewTitle = $spec['sewing'] ?? 'Manopera Rejansa Bara (Țeavă)';
        $sewValue = array_values(array_filter($sewing, static fn (array $o): bool => $o['title'] === $sewTitle))[0]['value'] ?? $sewing[0]['value'];
        $shop = [
            'id' => $id, 'line' => $n, 'productId' => (string) (700 + $n), 'variationId' => (string) (7000 + $n), 'name' => $spec['name'], 'sku' => $spec['sku'],
            'width' => $curtain ? (float) $spec['w'] : null, 'height' => $curtain ? (float) $spec['h'] : null, 'unit' => $curtain ? 'm' : null, 'details' => $details,
            'quantity' => $spec['qty'] ?? 1, 'unitPrice' => $money(intdiv($net, $spec['qty'] ?? 1)), 'subtotal' => $money($net), 'discount' => '0.00', 'tax' => $money($gross - $net), 'total' => $money($net),
            'calculator' => $curtain, 'image' => ['url' => 'https://127.0.0.1/wp-content/uploads/e2e/' . ($spec['image'] ?? 'draperie') . '.png', 'width' => 600, 'height' => 600, 'kind' => 'variation'],
            'stock' => ($spec['stock'] ?? 'instock') === 'instock' ? ['status' => 'instock', 'managed' => true, 'quantity' => 24] : ['status' => $spec['stock'], 'managed' => false, 'quantity' => null],
        ];
        $catalog = ['id' => $id, 'productId' => $shop['productId'], 'variationId' => $shop['variationId'], 'name' => $spec['name'], 'quantity' => $shop['quantity'], 'variations' => [], 'editable' => true, 'reason' => null,
            'calculator' => $curtain ? ['fields' => [
                ['name' => 'lungimea', 'title' => 'Lungimea (L)', 'type' => 'number', 'editable' => true, 'required' => true, 'min' => 0.5, 'max' => 12.0, 'value' => $spec['w']],
                ['name' => 'inaltime', 'title' => 'Inaltime (H)', 'type' => 'number', 'editable' => true, 'required' => true, 'min' => 0.5, 'max' => 3.2, 'value' => $spec['h']],
                ['name' => 'buc', 'title' => 'buc', 'type' => 'select', 'editable' => true, 'required' => true, 'min' => null, 'max' => null, 'options' => $pieces, 'value' => ($spec['buc'] ?? '2') . ':' . ($spec['buc'] ?? '2') . ' buc.'],
                ['name' => 'manopera', 'title' => 'Manopera', 'type' => 'select', 'editable' => true, 'required' => true, 'min' => null, 'max' => null, 'options' => $sewing, 'value' => $sewValue],
            ]] : null];
        return [$shop, $catalog];
    };
    $fields = static fn (array $over = []): array => array_merge(['firstName' => 'Test', 'lastName' => 'Client', 'company' => '', 'address1' => 'Strada Exemplu', 'streetNo' => '7', 'building' => 'A2', 'entrance' => '1',
        'floor' => '3', 'apartment' => '12', 'address2' => '', 'city' => 'Brașov', 'state' => 'BV', 'postcode' => '500000', 'country' => 'RO'], $over);
    $build = static function (string $no, array $specs, array $opts) use ($line, $cents, $money, $fields): array {
        $lines = []; $catalog = []; $net = 0; $tax = 0;
        foreach ($specs as $i => $spec) {
            [$l, $c] = $line($no, $i + 1, $spec);
            $lines[] = $l; $catalog[] = $c; $net += $cents($l['total']); $tax += $cents($l['tax']);
        }
        $subtotal = $net; $discount = 0; $discountTax = 0;
        if (isset($opts['coupon'])) {
            // A fixed TEST coupon with VAT, taken from the first line (as WooCommerce spreads it, here on one line).
            $gross = $cents($opts['coupon']); $discount = (int) round($gross / 1.21); $discountTax = $gross - $discount;
            $lines[0]['discount'] = $money($discount); $lines[0]['total'] = $money($cents($lines[0]['total']) - $discount); $lines[0]['tax'] = $money($cents($lines[0]['tax']) - $discountTax);
            $net -= $discount; $tax -= $discountTax;
        }
        $free = $net + $tax >= 60000;
        $shipNet = $free ? 0 : 1597; $shipTax = $free ? 0 : 335;
        $total = $net + $tax + $shipNet + $shipTax;
        $advance = intdiv($total * 30 + 50, 100);
        $shop = [
            'lines' => $lines, 'catalogLines' => $catalog, 'email' => 'client.test' . $no . '@example.test',
            'coupons' => isset($opts['coupon']) ? [['code' => 'TEST50', 'discount' => $money($discount), 'discountTax' => $money($discountTax)]] : [],
            'shipping' => [$free ? ['id' => (string) ((int) $no * 10 + 9), 'methodId' => 'free_shipping', 'instanceId' => '4', 'title' => 'Livrare gratuită', 'total' => '0.00', 'tax' => '0.00']
                : ['id' => (string) ((int) $no * 10 + 9), 'methodId' => 'flat_rate', 'instanceId' => '3', 'title' => 'Cost de expediere', 'total' => '15.97', 'tax' => '3.35']],
            'totals' => ['subtotal' => $money($subtotal), 'discount' => $money($discount), 'discountTax' => $money($discountTax), 'shipping' => $money($shipNet), 'shippingTax' => $money($shipTax),
                'fees' => '0.00', 'tax' => $money($tax + $shipTax), 'total' => $money($total), 'refunded' => '0.00'],
            'payment' => ['method' => 'cod', 'methodTitle' => 'Ramburs', 'cod' => true, 'paidRecorded' => false, 'financial' => ['state' => 'waiting', 'basis' => null],
                'advance' => ['percentage' => 30, 'required' => $money($advance), 'collected' => null]],
            'shippingFields' => $fields($opts['address'] ?? []), 'billingFields' => $fields(),
            'shippingLines' => $opts['lines'] ?? ['Strada Exemplu 7, Bl. A2, Sc. 1, Et. 3, Ap. 12', '500000 Brașov', 'Brașov, RO'],
            'customer' => $opts['customer'] ?? ['orders' => [], 'possibleDuplicates' => []],
            'name' => $opts['name'],
        ];
        return $shop;
    };
    $long = ['Draperie Catifea Premium Blackout Dublu Strat cu Inele Metalice Argintii - Bej Nisip Deschis', 'Perdea Voal Alb Fin', 'Draperie Diamond Blackout 90% Gri Antracit', 'Perdea In Natural Texturat',
        'Draperie Velvet Verde Smarald', 'Perdea Tul Brodat Floral Crem cu Margine Dantelă', 'Draperie Blackout Termoizolantă Albastru Marin', 'Perdea Organza Ivoire', 'Draperie Jacquard Auriu Clasic', 'Perdea Voal Gri Perlă'];
    $colors = ['Bej', 'Alb', 'Gri', 'Natur', 'Verde', 'Crem', 'Bleumarin', 'Ivoire', 'Auriu', 'Gri perlă'];
    $many = static function (int $count) use ($long, $colors): array {
        $specs = [];
        for ($i = 0; $i < $count; $i++) {
            $specs[] = ['name' => $long[$i % 10] . ($i >= 10 ? ' (cameră ' . ($i - 9) . ')' : ''), 'sku' => sprintf('TST-%03d', $i + 1), 'color' => $colors[$i % 10], 'w' => (string) (2 + ($i % 5)), 'h' => '2.60',
                'gross' => number_format(120 + 37.5 * ($i % 7), 2, '.', ''), 'buc' => (string) (1 + $i % 3), 'image' => ['draperie', 'voal', 'gri', 'in'][$i % 4],
                'sewing' => ['Manopera Rejansa Bara (Țeavă)', 'Manopera Rejansa Normal 6cm', 'Manopera Rejansa Creion cu Inele Metalice Argintii 10 cm'][$i % 3], 'stock' => $i === 3 ? 'onbackorder' : 'instock'];
        }
        return $specs;
    };
    $orders = [
        '91301' => $build('91301', [['name' => 'Draperie Diamond Blackout 90% Alb - Alb', 'sku' => 'DDB-ALB-90', 'color' => 'Alb', 'w' => '6', 'h' => '2.50', 'gross' => '336.00', 'buc' => '2']],
            ['name' => 'TEST Ioana Exemplu', 'customer' => ['orders' => [['number' => '90877', 'createdAt' => '2026-10-02T09:00:00Z', 'status' => ['code' => 'completed', 'label' => 'Finalizată'], 'total' => '412.50', 'currency' => 'RON']], 'possibleDuplicates' => []]]),
        '91302' => $build('91302', [
            ['name' => 'Draperie Velvet Bej', 'sku' => 'DV-302', 'color' => 'Bej', 'w' => '3', 'h' => '2.60', 'gross' => '336.00', 'buc' => '1', 'sewing' => 'Manopera Rejansa Normal 6cm'],
            ['name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'w' => '4', 'h' => '2.50', 'gross' => '242.00', 'qty' => 2, 'buc' => '2', 'image' => 'voal'],
            ['name' => 'Draperie Blackout Gri Antracit', 'sku' => 'DBG-210', 'color' => 'Gri', 'w' => '2', 'h' => '2.40', 'gross' => '181.50', 'stock' => 'outofstock', 'image' => 'gri'],
            ['name' => 'Șină aluminiu dublă 3 m', 'sku' => 'SA-300', 'color' => 'Argintiu', 'gross' => '96.80', 'curtain' => false, 'image' => 'sina'],
        ], ['name' => 'TEST Mihai Exemplu', 'coupon' => '50.00', 'address' => ['postcode' => ''], 'lines' => ['Strada Exemplu 7, Bl. A2, Sc. 1, Et. 3, Ap. 12', 'Brașov', 'Brașov, RO'],
            'customer' => ['orders' => [['number' => '91299', 'createdAt' => '2026-11-24T10:00:00Z', 'status' => ['code' => 'processing', 'label' => 'În procesare'], 'total' => '336.00', 'currency' => 'RON'],
                ['number' => '89012', 'createdAt' => '2026-08-12T10:00:00Z', 'status' => ['code' => 'completed', 'label' => 'Finalizată'], 'total' => '219.90', 'currency' => 'RON']], 'possibleDuplicates' => ['91299']]]),
        '91303' => $build('91303', $many(10), ['name' => 'TEST Alexandra-Maria Popescu-Ionescu Exemplu']),
        '91304' => $build('91304', $many(20), ['name' => 'TEST Hotel Exemplu SRL']),
    ];
    foreach ($orders as $wsNumber => $shop) {
        $ingest('trendhome', $order((string) $wsNumber, $now->modify('-2 minutes'), ['payment' => 'cod', 'name' => $shop['name'], 'shop' => $shop]));
        // The waiting times the desk weighs (display only): an order older than the TEST activation would be legacy, so
        // the submission instant is moved back in this TEST database after the order entered Confirmare.
        $minutes = [91301 => 95, 91302 => 26 * 60, 91303 => 4 * 24 * 60 + 30, 91304 => 15][(int) $wsNumber];
        $pdo->prepare('UPDATE operational_orders SET source_submitted_at = ? WHERE global_order_id = ?')
            ->execute([$now->modify("-{$minutes} minutes")->format('Y-m-d H:i:s.u'), "trendhome:{$wsNumber}"]);
    }
    $writeShop();
    echo json_encode(['orders' => ['one' => 'trendhome:91301', 'four' => 'trendhome:91302', 'ten' => 'trendhome:91303', 'twenty' => 'trendhome:91304']]), "\n";
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
foreach ([
    'finrec' => ['Finante Inregistrare', 'cf.finrec.e2e', ['view' => [], 'record' => ['trendhome'], 'approve' => []]],
    'finapp' => ['Finante Aprobare', 'cf.finapp.e2e', ['view' => [], 'record' => [], 'approve' => ['trendhome']]],
] as $role => [$name, $username, $scopes]) {
    // Generic TEST people with finance scopes only (no Confirmare scope): money is a separate permission.
    $employee = $admin->create($name, $username, null, 'pregatire-material', 'employee', $password, [], 'e2e');
    $asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]);
    $asRoot('PUT', "/management/employees/{$employee->employeeUuid}/finance-scopes", $scopes);
    $people[$role] = ['username' => $username, 'name' => $name, 'id' => $employee->employeeUuid];
}
// API 2.42.0 (Phase 6): the authorized shop employee who hands cleared orders to production: the production document
// operator role and the Trend Home document scope, and NO Confirmare scope (never part of the customer confirmation pool
// or of the automatic distribution).
$documentRole = null;
foreach ($asRoot('GET', '/management/roles')['items'] as $role) {
    if ($role['key'] === 'production-documents-operator') $documentRole = (int) $role['id'];
}
$employee = $admin->create('Predare Productie', 'cf.handoff.e2e', null, 'pregatire-material', 'employee', $password, [], 'e2e');
$asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]);
$asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$documentRole ?? throw new RuntimeException('E2E: no document operator role')]]);
$asRoot('PUT', "/management/employees/{$employee->employeeUuid}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]);
$people['handoff'] = ['username' => 'cf.handoff.e2e', 'name' => 'Predare Productie', 'id' => $employee->employeeUuid];

$registry = SourceRegistry::fromConfig($config);
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $source) {
    // TEST override of the source readiness (the last argument): the production CLI cannot pass it.
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
// The shop reports the raised total as an additional payment due (the event YD SOFT sends; finance work on its own).
$ingest('trendhome', $order('91005', $after(10), ['financial' => ['state' => 'waiting', 'basis' => null, 'attention' => 'additional_payment_due']]));
// The TEST shop's money record of 91005: paid 500 by card, the total is now 650 (TEST amounts).
$shopOrders['91005']['finance'] = ['cod' => false, 'total' => '650.00', 'paid' => '500.00', 'original' => '500.00', 'pricing' => $shopOrders['91005']['pricing']];
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
// API 2.50.0: the additional payment of 91005 becomes a finance task of named employees through the task inventory (the
// same command as production: dry run first, then apply); record or approve alone reaches no order.
$tasks = $container->financeTaskInventory()->apply(['trendhome'], 'cf-e2e');
$task = $pdo->query("SELECT request_uuid, assigned_employee_uuid, checker_employee_uuid FROM finance_verification_requests WHERE global_order_id = 'trendhome:91005' AND purpose = 'exception' AND open_order_uuid IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
if (!is_array($task) || $tasks['created'] < 1 || $task['assigned_employee_uuid'] !== $people['finrec']['id'] || $task['checker_employee_uuid'] !== $people['finapp']['id']) {
    throw new RuntimeException('E2E: the inventory did not give 91005 to the finance recorder with the approver as checker: ' . json_encode([$tasks, $task]));
}
echo json_encode(['password' => $password, 'people' => $people, 'orders' => [
    'call' => 'trendhome:91001', 'cod' => 'trendhome:91002', 'colleague' => 'trendhome:91003', 'noPrice' => 'trendhome:91004', 'unassigned' => 'trendhome:91005',
    'outlet' => 'outletperdele:92001', 'legacy' => 'trendhome:91090', 'undetermined' => 'trendhome:91091', 'finance' => 'trendhome:91005',
], 'state' => ['91001' => $assignee('trendhome:91001'), '91003' => $assignee('trendhome:91003')]], JSON_UNESCAPED_UNICODE), "\n";
