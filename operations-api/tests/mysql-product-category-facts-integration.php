<?php

declare(strict_types=1);

// Product category facts (API 2.74.0, orderFacts `productCategory`) against a disposable MySQL test database:
// A. the heartbeat announces the fact; an order event that carries order.items[].category keeps the shop catalog category
//    of its product codes current in product_catalog_categories (migration 055), and the CEO category reads it;
// B. the fact never changes an order, its version, its hash, its items, its QR or its document (a resend is a duplicate);
// C. unchanged categories are not rewritten, a changed category follows the newest event, the catalog name is kept;
// D. lines without a code and codes stated twice with different categories are left out; an invalid category is dropped
//    and never refuses or changes the order (its other facts still count);
// E. before migration 055 the fact is ignored and ingestion keeps working;
// F. ordering (owner review 2026-11-15): only a NEWER source change moves a category. A delayed older event of another
//    order, a replayed event, a queue drained out of order and an equal instant never turn a category back; a catalog
//    import is not undone by older events; the same code keeps separate categories per shop.

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
    VALUES ('outletperdele', ?, 'Accesorii', NULL, 'Catalog Accessory', 'single_main_category', ?, UTC_TIMESTAMP(6) - INTERVAL 1 HOUR)")->execute([$codeC, hash('sha256', 'export-' . $suffix)]);
$opId = "CATOP{$suffix}";
$check($send('op-v1', $at(560), [['id' => 9, 'line' => 1, 'name' => 'Ring Set', 'sku' => $codeC, 'quantity' => 4, 'category' => ['label' => 'Accesorii', 'subcategory' => 'Inele', 'rule' => 'primary_category']]], 'outletperdele', $opId)['body']['outcome'] === 'applied', 'C6 Outlet Perdele order applied');
$c = $row('outletperdele', $codeC);
$check([$c['category_label'], $c['subcategory_label'], $c['category_rule'], $c['product_name']] === ['Accesorii', 'Inele', 'primary_category', 'Catalog Accessory'], 'C7 the catalog name is kept when an order changes the category: ' . json_encode($c));
$check($row('trendhome', $codeC) === false, 'C8 categories stay per shop');

// ---- D. left out, dropped, never refused ---------------------------------------------------------------------------------------
$codeD = "DUP-CAT-{$suffix}";
$dup = $send('dup', $at(550), $items([1 => ['sku' => $codeD, 'category' => $drapery], 2 => ['sku' => strtolower($codeD), 'category' => $curtain]]), 'trendhome', "CATDUP{$suffix}");
$check($dup['status'] === 200 && $dup['body']['outcome'] === 'applied', 'D1 order with an ambiguous code accepted: ' . json_encode($dup['body']));
$check($row('trendhome', $codeD) === false, 'D2 one code with two categories in one order is left out');
$snapshot = $pdo->query('SELECT COUNT(*), MAX(imported_at) FROM product_catalog_categories')->fetch(PDO::FETCH_NUM);
foreach (['bad rule' => ['label' => 'Draperii', 'rule' => 'title_guess'], 'extra key' => ['label' => 'Draperii', 'rule' => 'primary_category', 'score' => 1],
    'empty label' => ['label' => '  ', 'rule' => 'primary_category'], 'long label' => ['label' => str_repeat('x', 121), 'rule' => 'primary_category'],
    'control char' => ['label' => "Dra\x07perii", 'rule' => 'primary_category'], 'list' => ['Draperii'], 'string' => 'Draperii'] as $label => $bad) {
    $r = $send('bad-' . md5($label), $at(540), $items([1 => ['category' => $bad]]));
    $check($r['status'] === 200 && $r['body']['outcome'] === 'duplicate', "D3 an invalid category never refuses the order ({$label}): " . json_encode($r['body']));
}
$check($pdo->query('SELECT COUNT(*), MAX(imported_at) FROM product_catalog_categories')->fetch(PDO::FETCH_NUM) === $snapshot && $canonical($uuid) === $before, 'D4 an invalid category stored nothing and changed no order');
$badNew = $send('bad-new', $at(535), [['id' => 7, 'line' => 1, 'name' => 'Draperie', 'sku' => "BAD-CAT-{$suffix}", 'quantity' => 1, 'category' => ['label' => 'Draperii', 'rule' => 'guess'],
    'meterBasis' => ['kind' => 'fabric_linear', 'length' => 2.5]]], 'trendhome', "CATBAD{$suffix}");
$badUuid = (string) $pdo->query('SELECT order_uuid FROM operational_orders WHERE global_order_id = ' . $pdo->quote("trendhome:CATBAD{$suffix}"))->fetchColumn();
$check($badNew['status'] === 200 && $badNew['body']['outcome'] === 'applied' && $row('trendhome', "BAD-CAT-{$suffix}") === false
    && (int) $pdo->query('SELECT COUNT(*) FROM order_item_meter_facts WHERE order_uuid = ' . $pdo->quote($badUuid))->fetchColumn() === 1, 'D6 a new order with an invalid category is applied and keeps its meter basis');
$check($send('null-cat', $at(530), $items([1 => ['category' => null]]))['body']['outcome'] === 'duplicate' && $row('trendhome', $codeA) == $a2, 'D5 a null category is no fact and erases nothing');

// ---- E. before migration 055 ---------------------------------------------------------------------------------------
$pdo->exec('RENAME TABLE product_catalog_categories TO product_catalog_categories_hold_test');
try {
    $r = $send('no-055', $at(520), $items([1 => ['category' => $drapery]]), 'trendhome', "CATNO{$suffix}");
    $check($r['status'] === 200 && $r['body']['outcome'] === 'applied', 'E1 ingestion works before migration 055: ' . json_encode($r['body']));
} finally {
    $pdo->exec('RENAME TABLE product_catalog_categories_hold_test TO product_catalog_categories');
}

// ---- F. ordering ---------------------------------------------------------------------------------------------------
$codeE = "NEW-CAT-{$suffix}";
$line = static fn (array $category, int $id = 1): array => [['id' => $id, 'line' => 1, 'name' => 'Produs Nou', 'sku' => $codeE, 'quantity' => 1, 'category' => $category]];
$iso = static fn (int $secondsAgo): string => gmdate('Y-m-d\TH:i:s', time() - $secondsAgo) . '.000000Z';
$validFrom = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', time() - $secondsAgo) . '.000000';
$e = $row('trendhome', $codeE);
$check($e === false, 'F0 new code not known yet');
$check($send('f-first', $iso(3000), $line($drapery), 'trendhome', "CATF1{$suffix}")['body']['outcome'] === 'applied', 'F1 first order of a new SKU applied');
$e1 = $row('trendhome', $codeE);
$check($e1 !== false && $e1['category_label'] === 'Draperii' && substr((string) $e1['imported_at'], 0, 19) === substr($validFrom(3000), 0, 19), 'F1 new SKU gets its category, valid from the source change: ' . json_encode($e1));
$check($send('f-moved', $iso(2000), $line($curtain), 'trendhome', "CATF2{$suffix}")['body']['outcome'] === 'applied', 'F2 later order after the category moved in WooCommerce');
$e2 = $row('trendhome', $codeE);
$check($e2['category_label'] === 'Perdele' && $e2['import_ref'] === $ref('trendhome', 'f-moved'), 'F2 the moved category follows the newer order');
$late = $send('f-late', $iso(2500), $line($drapery), 'trendhome', "CATF3{$suffix}");
$check($late['body']['outcome'] === 'applied' && $row('trendhome', $codeE) === $e2, 'F3 a delayed older event of another order is applied as an order but never turns the category back');
$check($send('f-first-replay', $iso(3000), $line($drapery), 'trendhome', "CATF1{$suffix}")['body']['outcome'] === 'out_of_order' || $row('trendhome', $codeE) === $e2, 'F4 the first order resent (older) changes nothing');
$check($row('trendhome', $codeE) === $e2, 'F4 the category is unchanged after a resend of an older order');
$replay = T::ingest($kernel, 'trendhome', T::sourceOrder("CATF1{$suffix}", "cat-f-first-{$suffix}", $iso(3000), null, 'processing', 'active', $line($drapery)));
$check($replay['status'] === 200 && $row('trendhome', $codeE) === $e2, 'F5 the exact same event replayed (same event id) changes nothing: ' . json_encode($replay['body']));
$check($send('f-tie', $iso(2000), $line(['label' => 'Alte', 'subcategory' => null, 'rule' => 'single_main_category']), 'trendhome', "CATF4{$suffix}")['body']['outcome'] === 'applied'
    && $row('trendhome', $codeE) === $e2, 'F6 an equal instant keeps the stored category');
// A queue drained out of order: the newer event is processed first, the older one afterwards.
$codeQ = "Q-CAT-{$suffix}";
$q = static fn (array $category): array => [['id' => 1, 'line' => 1, 'name' => 'Coada', 'sku' => $codeQ, 'quantity' => 1, 'category' => $category]];
$send('q-new', $iso(1000), $q($curtain), 'trendhome', "CATQ2{$suffix}");
$send('q-old', $iso(1500), $q($drapery), 'trendhome', "CATQ1{$suffix}");
$check($row('trendhome', $codeQ)['category_label'] === 'Perdele', 'F7 newer event first, older event later: the newer category stays');
$send('q-newest', $iso(500), $q($drapery), 'trendhome', "CATQ3{$suffix}");
$check($row('trendhome', $codeQ)['category_label'] === 'Draperii', 'F8 a still newer event moves it again');
// A catalog import is newer than events from before it.
$pdo->prepare("UPDATE product_catalog_categories SET category_label = 'Accesorii', subcategory_label = NULL, category_rule = 'single_main_category', imported_at = UTC_TIMESTAMP(6) WHERE source_key = 'trendhome' AND product_code = ?")->execute([$codeQ]);
$send('q-before-import', $iso(100), $q($curtain), 'trendhome', "CATQ4{$suffix}");
$check($row('trendhome', $codeQ)['category_label'] === 'Accesorii', 'F9 an event older than the catalog import does not undo it');
// The same code in both shops keeps two categories; a Trendyol seller code matching both is not guessed.
$check($send('f-op', $iso(400), [['id' => 1, 'line' => 1, 'name' => 'Produs Nou', 'sku' => $codeE, 'quantity' => 1, 'category' => ['label' => 'Accesorii', 'subcategory' => null, 'rule' => 'single_main_category']]], 'outletperdele', "CATFOP{$suffix}")['body']['outcome'] === 'applied', 'F10 Outlet Perdele order with the same code');
$catalog = new ProductCatalog($pdo);
$check($row('outletperdele', $codeE)['category_label'] === 'Accesorii' && $row('trendhome', $codeE) === $e2
    && $catalog->classify(['source_key' => 'trendhome', 'product_code' => $codeE])['category'] === 'Perdele'
    && $catalog->classify(['source_key' => 'outletperdele', 'product_code' => $codeE])['category'] === 'Accesorii', 'F10 one code, two shops, two categories');
$check($catalog->classify(['source_key' => 'trendyol', 'product_code' => $codeE, 'ty_prepared_kind' => null])['category'] === ProductCatalog::UNKNOWN, 'F11 a Trendyol code matching two shop categories is unknown, never guessed');

// Clean only this run's catalog rows (codes unique to the run).
$pdo->prepare('DELETE FROM product_catalog_categories WHERE product_code IN (?, ?, ?, ?, ?, ?, ?)')->execute([$codeA, $codeB, $codeC, $codeD, $codeE, $codeQ, "BAD-CAT-{$suffix}"]);
echo "Product category facts integration passed ({$checks} checks).\n";
