<?php

declare(strict_types=1);

// Production ticket: commercial quantity, per-product dimensions and physical splitting stay three separate
// things. A schema 2 Trendyol line prints `quantity` separate products of the variant's size each, never one
// product divided; Trendhome, OutletPerdele and B2B keep their explicit split meaning; a schema 1 revision
// renders byte for byte as it was printed. No database: snapshots are built in memory (TEST data only).

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\QrCode;
use Arasya\Operations\Document\ProductionTicketPdf;

require dirname(__DIR__) . '/bootstrap.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

$fixtures = require __DIR__ . '/fixtures/ticket-snapshots.php';
$line = static fn (int $number, array $overrides = []): array => $overrides + [
    'line' => $number, 'code' => null, 'name' => 'Produs', 'kind' => null, 'variant' => null, 'color' => null,
    'width' => null, 'height' => null, 'unit' => null, 'meters' => null, 'quantity' => 1,
    'notes' => null, 'productionNotes' => null, 'options' => [], 'project' => null,
];
$trendyol = static fn (array $lines, int $schema = 2): array => [
    'schema' => $schema,
    'order' => ['number' => '10000000001', 'lookupCode' => '10000000001', 'source' => 'trendyol'],
    'customer' => ['name' => 'TEST Client Trendyol', 'company' => null, 'contact' => null, 'addressLines' => ['Str. Test 1', 'București'], 'phoneMasked' => null],
    'notes' => null,
    'lines' => $lines,
];
$variant = static fn (int $number, string $code, string $width, string $height, int $quantity, array $overrides = []): array => $line($number, $overrides + [
    'code' => $code, 'name' => 'Draperie catifea TEST', 'kind' => 'drapery', 'variant' => rtrim(rtrim($width, '0'), '.') . 'x' . rtrim(rtrim($height, '0'), '.'),
    'width' => $width, 'height' => $height, 'unit' => 'cm', 'quantity' => $quantity,
]);
$payload = 'ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ';
$revision = ['number' => 1, 'status' => 'active', 'generatedAt' => '2026-10-16 09:00:00', 'qrPayload' => $payload, 'sourceDisplayName' => null];

/** Drawing operators of every page, read from the laid-out document (test only; the PDF streams are compressed). @return list<string> */
$operators = static function (PdfDocument $document): array {
    return (fn (): array => [...$this->pages, ...($this->current === '' ? [] : [$this->current])])->call($document);
};
/** @return array{texts: list<array<string, mixed>>, all: string, pages: int, ops: string, document: PdfDocument} */
$render = static function (array $snapshot) use ($revision, $operators): array {
    $document = ProductionTicketPdf::document($snapshot, $revision);
    $texts = $document->textLog();
    return ['texts' => $texts, 'all' => implode("\n", array_column($texts, 'text')), 'pages' => max(array_column($texts, 'page')), 'ops' => implode("\n", $operators($document)), 'document' => $document];
};
// Product rectangles of the Trendyol sketch are the only 1.2-wide black strokes; a panel split is a 0.9-wide line.
$productRectangles = static fn (string $ops): int => preg_match_all('/^0\.000 G 1\.20 w [-0-9.]+ [-0-9.]+ [-0-9.]+ [-0-9.]+ re S/m', $ops);
$splitLines = static fn (string $ops): int => preg_match_all('/^0\.200 G 0\.90 w /m', $ops);

$layout = static function (string $name, array $rendered) use ($payload): void {
    $texts = $rendered['texts'];
    $outside = array_filter($texts, static fn (array $t): bool => !($t['x'] >= 28 && $t['x'] + $t['width'] <= 595.28 - 28 && $t['y'] >= 20 && $t['y'] + $t['size'] <= 841.89 - 20));
    check($outside === [], "{$name}: every text inside the printable A4 area " . json_encode(array_column($outside, 'text'), JSON_UNESCAPED_UNICODE));
    check(array_filter($texts, static fn (array $t): bool => $t['size'] < 6.5) === [], "{$name}: no unreadably small text");
    $overlaps = [];
    $byPage = [];
    foreach ($texts as $t) {
        $byPage[$t['page']][] = $t;
    }
    foreach ($byPage as $page => $items) {
        foreach ($items as $i => $a) {
            foreach (array_slice($items, $i + 1) as $b) {
                $overlapX = min($a['x'] + $a['width'], $b['x'] + $b['width']) - max($a['x'], $b['x']);
                $overlapY = min($a['y'] + $a['size'] * 0.72, $b['y'] + $b['size'] * 0.72) - max($a['y'], $b['y']);
                if ($overlapX > 1.5 && $overlapY > 1.5) {
                    $overlaps[] = "p{$page}: {$a['text']} / {$b['text']}";
                }
            }
        }
    }
    check($overlaps === [], "{$name}: no overlapping text " . json_encode($overlaps, JSON_UNESCAPED_UNICODE));
    $matrix = QrCode::matrix($payload, 'Q');
    for ($page = 1; $page <= $rendered['pages']; $page++) {
        $onPage = array_column(array_filter($texts, static fn (array $t): bool => $t['page'] === $page), 'text');
        check(in_array("Pagina {$page} / {$rendered['pages']}", $onPage, true) && in_array('TRENDYOL', $onPage, true) && in_array('REVIZIA 1', $onPage, true), "{$name}: page {$page} carries source, revision and page number");
        $qrs = array_values(array_filter($rendered['document']->qrLog(), static fn (array $q): bool => $q['page'] === $page && $q['size'] >= 80));
        check(count($qrs) === 1 && $qrs[0]['matrix'] === $matrix, "{$name}: one canonical order QR on page {$page}, same payload");
    }
    check(preg_match('/@|RON|EUR|\bLei\b|preț|TVA|plată/u', $rendered['all']) !== 1, "{$name}: no email, price or payment data");
};

// ---- T1: the owner's reference (order shaped like 11709027615: 12754-21, 2 × 250 × 260 cm, drapery) ------
$t1 = $render($trendyol([$variant(1, '12754-21', '250.000', '260.000', 2)]));
$layout('T1', $t1);
foreach (['PRODUSE DE EXECUTAT · 1 POZIȚIE · 2 PRODUSE COMANDATE', '2 ×', 'DRAPERIE', '2 produse comandate · 250 × 260 cm fiecare', 'DIMENSIUNI PER PRODUS',
    'LĂȚIME', 'ÎNĂLȚIME', 'Produs 1', 'Produs 2', '250 × 260 cm fiecare', 'poziția', '12754-21'] as $text) {
    check(in_array($text, array_column($t1['texts'], 'text'), true), "T1: shows \"{$text}\"");
}
check(str_contains(str_replace("\n", ' ', $t1['all']), 'DOUĂ PRODUSE SEPARATE — NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN DOUĂ.'), 'T1: says the two products are separate, not one drapery divided in two');
$figure = array_values(array_filter($t1['texts'], static fn (array $t): bool => $t['text'] === '2 ×'))[0];
check($figure['size'] >= 24 && $figure['size'] > max(array_column(array_filter($t1['texts'], static fn (array $t): bool => !in_array($t['text'], ['2 ×', '#10000000001'], true)), 'size')), 'T1: the ordered quantity is the largest figure of the product block');
check($productRectangles($t1['ops']) === 2 && $splitLines($t1['ops']) === 0, 'T1: two separate product rectangles, no dividing line');
check(!str_contains($t1['all'], '125') && !str_contains($t1['all'], '500') && !str_contains($t1['all'], 'buc.') && !in_array('BUCĂȚI', array_column($t1['texts'], 'text'), true), 'T1: no halved or doubled measurement, no piece split');
check(in_array('METRI (LINIE)', array_column($t1['texts'], 'text'), true) && !preg_match('/\d+(,\d+)? m\b/u', $t1['all']), 'T1: unknown meters stay unknown (no invented consumption)');
check($t1['pages'] === 1, 'T1: one page');

// ---- T2: one product -----------------------------------------------------------------------------------
$t2 = $render($trendyol([$variant(1, '12754-20', '200.000', '260.000', 1)]));
$layout('T2', $t2);
check(str_contains($t2['all'], 'PRODUSE DE EXECUTAT · 1 POZIȚIE · 1 PRODUS COMANDAT') && str_contains($t2['all'], '1 produs comandat · 200 × 260 cm') && !str_contains($t2['all'], 'fiecare'), 'T2: one ordered product of 200 × 260 cm');
check(str_contains($t2['all'], 'Un singur produs comandat. Comanda nu conține o împărțire în bucăți.') && !str_contains($t2['all'], 'SEPARATE'), 'T2: no split is inferred from a 200 cm width');
check($productRectangles($t2['ops']) === 1 && $splitLines($t2['ops']) === 0 && !str_contains($t2['all'], '100 cm'), 'T2: one rectangle, not divided');

// ---- T3: three products of 200 × 260 cm --------------------------------------------------------------
$t3 = $render($trendyol([$variant(1, '12754-20', '200.000', '260.000', 3)]));
$layout('T3', $t3);
check(str_contains($t3['all'], '1 POZIȚIE · 3 PRODUSE COMANDATE') && str_contains($t3['all'], '3 produse comandate · 200 × 260 cm fiecare') && str_contains(str_replace("\n", ' ', $t3['all']), 'TREI PRODUSE SEPARATE — NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN TREI.'), 'T3: three separate products, 200 × 260 cm each');
check($productRectangles($t3['ops']) === 3 && $splitLines($t3['ops']) === 0 && !preg_match('/66[,.]6|86[,.]6/u', $t3['all']), 'T3: three rectangles; width and height are never divided by 3');
check(in_array('Produs 3', array_column($t3['texts'], 'text'), true), 'T3: products labelled 1 to 3');

// ---- T4: two variants in one package -----------------------------------------------------------------
$t4 = $render($trendyol([$variant(1, 'A-150', '150.000', '250.000', 2), $variant(2, 'B-300', '300.000', '270.000', 1, ['kind' => 'curtain', 'name' => 'Perdea voal ăâîșț TEST'])]));
$layout('T4', $t4);
check(str_contains($t4['all'], 'PRODUSE DE EXECUTAT · 2 POZIȚII · 3 PRODUSE COMANDATE'), 'T4: two positions, three ordered products');
check(str_contains($t4['all'], '2 produse comandate · 150 × 250 cm fiecare') && str_contains($t4['all'], '1 produs comandat · 300 × 270 cm') && in_array('PERDEA', array_column($t4['texts'], 'text'), true), 'T4: each position keeps its own quantity, size and product type');
check(str_contains($t4['all'], 'Perdea voal ăâîșț TEST'), 'T4: Romanian diacritics are printed');
check($productRectangles($t4['ops']) === 3 && $splitLines($t4['ops']) === 0, 'T4: 2 + 1 product rectangles');

// ---- T5: quantity known, dimensions missing ------------------------------------------------------------
$t5 = $render($trendyol([$line(1, ['code' => '12754-99', 'name' => 'Draperie TEST', 'kind' => 'drapery', 'quantity' => 2])]));
$layout('T5', $t5);
check(str_contains($t5['all'], '2 produse comandate · dimensiuni necunoscute') && str_contains($t5['all'], 'Dimensiunile lipsesc la sursă — verifică înainte de croire. Nu se ghicesc.'), 'T5: quantity visible, missing dimensions said honestly');
check($productRectangles($t5['ops']) === 0 && !str_contains($t5['all'], ' × 2') && !preg_match('/\d+ × \d+/u', $t5['all']), 'T5: no sketch and no guessed size');

// ---- Many identical products, a long name and a source option ----------------------------------------
$many = $render($trendyol([$variant(1, '12754-21', '250.000', '260.000', 20, [
    'name' => 'Draperie blackout catifea premium cu rejansă ascunsă și tiv dublu, culoare gri antracit, pentru dormitor și living — ediție ăâîșț ĂÂÎȘȚ TEST',
    'meters' => '5.200', 'productionNotes' => 'Atenție la sensul catifelei.', 'options' => [['label' => 'Bucăți', 'value' => '2 buc.']],
])]));
$layout('20 products', $many);
check(str_contains($many['all'], '1 POZIȚIE · 20 DE PRODUSE COMANDATE') && str_contains($many['all'], '20 de produse comandate · 250 × 260 cm fiecare') && str_contains(str_replace("\n", ' ', $many['all']), '20 DE PRODUSE SEPARATE — NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN 20.'), '20 products: Romanian "de" agreement');
check($productRectangles($many['ops']) === 1 && in_array('× 20', array_column($many['texts'], 'text'), true) && in_array('IDENTICE', array_column($many['texts'], 'text'), true), '20 products: one drawing with a × 20 multiplier, not 20 drawings');
check(str_contains($many['all'], 'Bucăți: 2 buc.') && $splitLines($many['ops']) === 0 && !in_array('BUCĂȚI', array_column($many['texts'], 'text'), true) && !in_array('egale', array_column($many['texts'], 'text'), true), 'a Trendyol option is printed as source text only, never turned into a panel split');
check(str_contains($many['all'], '5,2') && str_contains(str_replace("\n", ' ', $many['all']), 'pentru dormitor și living — ediție ăâîșț ĂÂÎȘȚ TEST · Variantă: 250x260'), 'meters as entered and the whole long name are printed');
foreach ([101 => '101 PRODUSE COMANDATE', 119 => '119 PRODUSE COMANDATE', 120 => '120 DE PRODUSE COMANDATE', 200 => '200 DE PRODUSE COMANDATE'] as $quantity => $expected) {
    check(str_contains($render($trendyol([$variant(1, 'X-1', '140.000', '245.000', $quantity)]))['all'], '1 POZIȚIE · ' . $expected), "count agreement for {$quantity}");
}
$pages = $render($trendyol(array_map(static fn (int $i): array => $variant($i, 'C-' . $i, '140.000', '245.000', 1 + $i % 4), range(1, 9))));
$layout('9 positions', $pages);
check($pages['pages'] > 1 && count(array_filter($pages['texts'], static fn (array $t): bool => $t['text'] === 'poziția')) === 9 && str_contains($pages['all'], '9 POZIȚII · 22 DE PRODUSE COMANDATE'), '9 positions paginate; every position printed once');
foreach ($pages['texts'] as $text) {
    if ($text['text'] === 'poziția') {
        $block = array_values(array_filter($pages['texts'], static fn (array $t): bool => $t['page'] === $text['page'] && $t['text'] === 'DIMENSIUNI PER PRODUS' && $t['y'] < $text['y'] && $t['y'] > $text['y'] - 160));
        check(count($block) === 1, 'a position block is never split across pages');
    }
}

// ---- T6–T9: other sources keep their own meaning, schema 2 renders exactly as schema 1 -------------------
foreach (['trendhome', 'outletperdele', 'b2b', 'b2b-project'] as $name) {
    $schema2 = $fixtures[$name];
    $schema2['schema'] = 2;
    check(ProductionTicketPdf::render($schema2, $revision) === ProductionTicketPdf::render($fixtures[$name], $revision), "{$name}: schema 2 renders byte for byte as schema 1");
    check(!str_contains($render($schema2)['all'], 'PRODUSE COMANDATE'), "{$name}: no Trendyol quantity wording");
}
$trendhome = $render(['schema' => 2] + $fixtures['trendhome']);
check(in_array('2 buc.', array_column($trendhome['texts'], 'text'), true) && $splitLines($trendhome['ops']) === 1 && str_contains($trendhome['all'], 'Confecționare: 2 bucăți'), 'T6: Trendhome explicit "2 bucăți" keeps its two-panel schematic');
$outlet = $render(['schema' => 2] + $fixtures['outletperdele']);
check(in_array('3 buc.', array_column($outlet['texts'], 'text'), true) && $splitLines($outlet['ops']) === 2 && str_contains($outlet['all'], 'Segmentare: 3 bucăți'), 'T7: OutletPerdele explicit "3 bucăți" keeps its three-panel schematic');
$both = $fixtures['trendhome'];
$both['schema'] = 2;
$both['lines'] = [$line(1, ['code' => 'DV-302', 'name' => 'Draperie Velvet', 'kind' => 'drapery', 'width' => '500.000', 'height' => '260.000', 'unit' => 'cm', 'quantity' => 2, 'options' => [['label' => 'Confecționare', 'value' => '2 bucăți']]])];
$t8 = $render($both);
$quantityBox = array_values(array_filter($t8['texts'], static fn (array $t): bool => $t['text'] === 'CANTITATE'))[0];
$quantityValue = array_values(array_filter($t8['texts'], static fn (array $t): bool => $t['size'] >= 9 && abs($t['x'] - $quantityBox['x']) < 1 && $t['y'] < $quantityBox['y']))[0]['text'] ?? null;
check($quantityValue === '2' && in_array('BUCĂȚI', array_column($t8['texts'], 'text'), true) && in_array('egale', array_column($t8['texts'], 'text'), true) && $splitLines($t8['ops']) === 1, 'T8: WooCommerce quantity 2 and split 2 per item stay separate');
check(!str_contains($t8['all'], '4 buc') && !preg_match('/(?<![\d,])4(?![\d,])/u', $t8['all']), 'T8: the quantity never becomes 4');
$b2b = $render(['schema' => 2] + $fixtures['b2b-project']);
check($splitLines($b2b['ops']) > 0 && str_contains($b2b['all'], 'Corp A · Etaj 0  ›  Camera 100') && in_array('linia', array_column($b2b['texts'], 'text'), true), 'T9: B2B project keeps its panel layout and line wording');

// ---- T10: a schema 1 revision (every revision printed before 2.34.2) is historically stable --------------
// Golden SHA-256 of the schema 1 fixtures, rendered by 2.34.1 before this change. A different hash means a
// printed historical ticket would change on screen and on reprint.
$golden = [
    'trendhome' => 'a76b631d57ef25f4fdf70a206227d527c091fa0bb8c47caa5cb5b3179bacdb1c',
    'outletperdele' => '9ef0c697adefb11fde690bc51e158fed11cf0a8c8eec891549baf34fcef98e8e',
    'trendyol' => '8bcf6a84183c6abbcc05a7b14ba84e8a56666d31175a2dfdf89c7b0b88f29d88',
    'b2b' => 'd4040cc59748c6c3e84606fd1c35c456bb4a02e13e2fc454774a7c13613324a1',
    'b2b-project' => '4f0c61092f06920f031bc70ac59b505e759b0429473d27b6765ada317b37b130',
];
foreach ($golden as $name => $hash) {
    check($fixtures[$name]['schema'] === 1 && hash('sha256', ProductionTicketPdf::render($fixtures[$name], $revision)) === $hash, "T10: schema 1 {$name} renders byte for byte as before");
}
$legacy = $render($trendyol([$variant(1, '12754-21', '250.000', '260.000', 2)], 1));
check(hash('sha256', ProductionTicketPdf::render($trendyol([$variant(1, '12754-21', '250.000', '260.000', 2, ['name' => 'Draperie catifea TEST'])], 1), $revision)) === '40041e88bba0ab025dcd57e05be2ededab2b7dd76373c03dd08d39dc0cd4ac5d', 'T10: the R1-shaped schema 1 Trendyol ticket renders byte for byte as before');
check(str_contains($legacy['all'], 'PRODUSE DE EXECUTAT · 1 linie') && in_array('CANTITATE', array_column($legacy['texts'], 'text'), true) && !str_contains($legacy['all'], 'PRODUSE COMANDATE'), 'T10: a schema 1 Trendyol revision keeps its original layout');
check(ProductionTicketPdf::filename($trendyol([]), 1) === 'ARASYA-10000000001-R1.pdf', 'the filename is unchanged');

fwrite(STDOUT, "PASS production ticket quantity ({$checks} checks)\n");
