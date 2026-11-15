<?php

declare(strict_types=1);

// Product category facts (API 2.74.0, orderFacts `productCategory`) against a disposable MySQL test database:
// A. the heartbeat announces the fact; an order event that carries order.items[].category keeps the shop catalog category
//    of its product codes current in product_catalog_categories (migration 055), and the CEO category reads it;
// B. the fact never changes an order, its version, its hash, its items, its QR or its document (a resend is a duplicate);
// C. unchanged categories are not rewritten, a changed category follows the newest event, the catalog name is kept;
// D. lines without a code and codes stated twice with different categories are left out; invalid shapes are refused (422)
//    and change nothing;
// E. before migration 055 the fact is ignored and ingestion keeps working.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Intelligence\ProductCatalog;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP product category facts integration: test database not configured\n"; exit(0); }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$container = new Container($config, $pdo); $kernel = $container->kernel();
$suffix = bin2hex(random_bytes(5));
$at = static fn (int $secondsAgo): string => gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);

$canonical = static fn (string $orderUuid): array => [
    'order' => $pdo->query('SELECT order_uuid, version, production_version, production_stage_id, projection_hash, document_status, document_version, operational_status FROM operational_orders WHERE order_uuid = ' . $pdo->quote($orderUuid))->fetch(PDO::FETCH_ASSOC),
    'items' => $pdo->query('SELECT item_uuid, source_item_id, line_number, name, product_code, variant, meters, quantity, production_context, updated_at FROM operational_order_items WHERE order_uuid = ' . $pdo->quote($orderUuid) . ' ORDER BY line_number')->fetchAll(PDO::FETCH_ASSOC),
    'qr' => $pdo->query('SELECT qr_reference, status FROM order_qr_references WHERE order_uuid = ' . $pdo->quote($orderUuid) . ' ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC),
    'confirmation' => $pdo->query('SELECT status, version FROM order_confirmations WHERE order_uuid = ' . $pdo->quote($orderUuid))->fetch(PDO::FETCH_ASSOC),
];
$row = static function (string $source, string $code) use ($pdo): array|false {
    $s = $pdo->prepare('SELECT product_code, category_label, subcategory_label, product_name, category_rule, import_ref, imported_at FROM product_catalog_categories WHERE source_key = ? AND product_code = ?');
    $s->execute([$source, $code]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$codeA = "DV-CAT-{$suffix}"; $codeB = "PR-CAT-{$suffix}"; $codeC = "ACC-CAT-{$suffix}";
$drapery = ['label' => 'Draperii', 'subcategory' => 'Draperii Velvet', 'rule' => 'single_main_category'];
$curtain = ['label' => 'Perdele', 'subcategory' => null, 'rule' => 'primary_category'];
$items = static fn (array $extra = []): array => [
    ($extra[1] ?? []) + ['id' => 1, 'line' => 1, 'name' => 'Draperie Velvet Bej', 'sku' => $codeA, 'width' => 2.5, 'height' => 2.6, 'unit' => 'm', 'quantity' => 2],
    ($extra[2] ?? []) + ['id' => 2, 'line' => 2, 'name' => 'Perdea Voal', 'sku' => $codeB, 'width' => 3, 'unit' => 'm', 'quantity' => 1],
    ($extra[3] ?? []) + ['id' => 3, 'line' => 3, 'name' => 'Inele', 'quantity' => 10],
];
$orderId = "CAT{$suffix}";
$send = static fn (string $event, string $changedAt, array $lines, string $source = 'trendhome', ?string $id = null): array
    => T::ingest($kernel, $source, T::sourceOrder($id ?? $orderId, "cat-{$event}-{$suffix}", $changedAt, null, 'processing', 'active', $lines));
$ref = static fn (string $source, string $event): string => hash('sha256', 'order-event' . "\x1f" . $source . "\x1f" . "cat-{$event}-{$suffix}");

// ---- A. the fact keeps the catalog current --------------------------------------------------------------------
$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => $at(0)], null, null, 'heartbeat');
$check(in_array('productCategory', $heartbeat['body']['orderFacts'] ?? [], true), 'A1 heartbeat announces productCategory');
$check($send('v1', $at(600), $items())['body']['outcome'] === 'applied', 'A2 order without categories applied');
$check($row('trendhome', $codeA) === false, 'A3 no category without the fact');
$uuid = (string) $pdo->query('SELECT order_uuid FROM operational_orders WHERE global_order_id = ' . $pdo->quote("trendhome:{$orderId}"))->fetchColumn();
$pdo->prepare("UPDATE operational_orders SET document_status = 'active' WHERE order_uuid = ?")->execute([$uuid]);
$before = $canonical($uuid);
$withCategory = $send('v1-cat', $at(590), $items([1 => ['category' => $drapery], 2 => ['category' => $curtain], 3 => ['category' => $drapery]]));
$check($withCategory['status'] === 200 && $withCategory['body']['outcome'] === 'duplicate', 'A4 the same order with categories is a duplicate: ' . json_encode($withCategory['body']));
$a = $row('trendhome', $codeA); $b = $row('trendhome', $codeB);
$check($a !== false && [$a['category_label'], $a['subcategory_label'], $a['product_name'], $a['category_rule'], $a['import_ref']] === ['Draperii', 'Draperii Velvet', 'Draperie Velvet Bej', 'single_main_category', $ref('trendhome', 'v1-cat')], 'A5 new code stored with its category: ' . json_encode($a));
$check($b !== false && [$b['category_label'], $b['subcategory_label'], $b['category_rule']] === ['Perdele', null, 'primary_category'], 'A6 second code stored');
$check((int) $pdo->query('SELECT COUNT(*) FROM product_catalog_categories WHERE product_code LIKE ' . $pdo->quote("%CAT-{$suffix}"))->fetchColumn() === 2, 'A7 the line without a code stored nothing');
$catalog = new ProductCatalog($pdo);
$check($catalog->classify(['source_key' => 'trendhome', 'product_code' => strtolower($codeA)])['category'] === 'Draperii', 'A8 the CEO category reads the new code');
$check($catalog->classify(['source_key' => 'trendyol', 'product_code' => $codeB])['category'] === 'Perdele', 'A9 a Trendyol seller code matching one catalog category follows it');

// ---- B. invisible to every production path ------------------------------------------------------------------------
$check($canonical($uuid) === $before, 'B1 order, version, hash, items, QR and Confirmare are byte-identical');

// ---- C. unchanged, changed, catalog name kept ----------------------------------------------------------------------
$check($send('v1-cat-again', $at(580), $items([1 => ['category' => $drapery], 2 => ['category' => $curtain]]))['body']['outcome'] === 'duplicate', 'C1 resend applied as duplicate');
$check($row('trendhome', $codeA) === $a, 'C2 an unchanged category is not rewritten (ref and time kept)');
$moved = ['label' => 'Perdele', 'subcategory' => 'Perdele Velvet', 'rule' => 'primary_category'];
$check($send('v1-moved', $at(570), $items([1 => ['category' => $moved]]))['body']['outcome'] === 'duplicate', 'C3 moved category resend is a duplicate');
$a2 = $row('trendhome', $codeA);
$check([$a2['category_label'], $a2['subcategory_label'], $a2['category_rule'], $a2['import_ref'], $a2['product_name']] === ['Perdele', 'Perdele Velvet', 'primary_category', $ref('trendhome', 'v1-moved'), 'Draperie Velvet Bej'], 'C4 the newest event moves the category: ' . json_encode($a2));
$check($row('trendhome', $codeB) === $b, 'C5 a code absent from the event is untouched');
// A catalog export names the product (the row as bin/product-category-import.php writes it); an order later keeps that name.
$pdo->prepare("INSERT INTO product_catalog_categories (source_key, product_code, category_label, subcategory_label, product_name, category_rule, import_ref, imported_at)
    VALUES ('outletperdele', ?, 'Accesorii', NULL, 'Catalog Accessory', 'single_main_category', ?, UTC_TIMESTAMP(6))")->execute([$codeC, hash('sha256', 'export-' . $suffix)]);
$opId = "CATOP{$suffix}";
$check($send('op-v1', $at(560), [['id' => 9, 'line' => 1, 'name' => 'Ring Set', 'sku' => $codeC, 'quantity' => 4, 'category' => ['label' => 'Accesorii', 'subcategory' => 'Inele', 'rule' => 'primary_category']]], 'outletperdele', $opId)['body']['outcome'] === 'applied', 'C6 Outlet Perdele order applied');
$c = $row('outletperdele', $codeC);
$check([$c['category_label'], $c['subcategory_label'], $c['category_rule'], $c['product_name']] === ['Accesorii', 'Inele', 'primary_category', 'Catalog Accessory'], 'C7 the catalog name is kept when an order changes the category: ' . json_encode($c));
$check($row('trendhome', $codeC) === false, 'C8 categories stay per shop');

// ---- D. left out and refused ---------------------------------------------------------------------------------------
$codeD = "DUP-CAT-{$suffix}";
$dup = $send('dup', $at(550), $items([1 => ['sku' => $codeD, 'category' => $drapery], 2 => ['sku' => strtolower($codeD), 'category' => $curtain]]), 'trendhome', "CATDUP{$suffix}");
$check($dup['status'] === 200 && $dup['body']['outcome'] === 'applied', 'D1 order with an ambiguous code accepted: ' . json_encode($dup['body']));
$check($row('trendhome', $codeD) === false, 'D2 one code with two categories in one order is left out');
$snapshot = $pdo->query('SELECT COUNT(*), MAX(imported_at) FROM product_catalog_categories')->fetch(PDO::FETCH_NUM);
foreach (['bad rule' => ['label' => 'Draperii', 'rule' => 'title_guess'], 'extra key' => ['label' => 'Draperii', 'rule' => 'primary_category', 'score' => 1],
    'empty label' => ['label' => '  ', 'rule' => 'primary_category'], 'long label' => ['label' => str_repeat('x', 121), 'rule' => 'primary_category'],
    'control char' => ['label' => "Dra\x07perii", 'rule' => 'primary_category'], 'list' => ['Draperii'], 'string' => 'Draperii'] as $label => $bad) {
    $r = $send('bad-' . md5($label), $at(540), $items([1 => ['category' => $bad]]));
    $check($r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'SOURCE_PAYLOAD_INVALID', "D3 invalid category refused ({$label}): " . json_encode($r['body']));
}
$check($pdo->query('SELECT COUNT(*), MAX(imported_at) FROM product_catalog_categories')->fetch(PDO::FETCH_NUM) === $snapshot && $canonical($uuid) === $before, 'D4 a refused payload changed nothing');
$check($send('null-cat', $at(530), $items([1 => ['category' => null]]))['body']['outcome'] === 'duplicate' && $row('trendhome', $codeA) == $a2, 'D5 a null category is no fact and erases nothing');

// ---- E. before migration 055 ---------------------------------------------------------------------------------------
$pdo->exec('RENAME TABLE product_catalog_categories TO product_catalog_categories_hold_test');
try {
    $r = $send('no-055', $at(520), $items([1 => ['category' => $drapery]]), 'trendhome', "CATNO{$suffix}");
    $check($r['status'] === 200 && $r['body']['outcome'] === 'applied', 'E1 ingestion works before migration 055: ' . json_encode($r['body']));
} finally {
    $pdo->exec('RENAME TABLE product_catalog_categories_hold_test TO product_catalog_categories');
}

// Clean only this run's catalog rows (codes unique to the run).
$pdo->prepare('DELETE FROM product_catalog_categories WHERE product_code IN (?, ?, ?, ?)')->execute([$codeA, $codeB, $codeC, $codeD]);
echo "Product category facts integration passed ({$checks} checks).\n";
