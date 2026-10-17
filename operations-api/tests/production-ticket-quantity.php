<?php

declare(strict_types=1);

// Production ticket: commercial quantity, per-product dimensions and physical splitting stay three separate
// things. A schema 2 Trendyol line prints `quantity` separate products of the variant's size each, never one
// product divided; Trendhome, OutletPerdele and B2B keep their explicit split meaning; a schema 1 revision
// renders byte for byte as it was printed. No database: snapshots are built in memory (TEST data only).

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\QrCode;
use Arasya\Operations\Document\OrderedProductsTicket;
use Arasya\Operations\Document\ProductionTicketPdf;
use Arasya\Operations\Document\TicketLayoutOverflow;
use Arasya\Operations\Document\TicketSnapshot;

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
// A panel split of the schema 1 schematic is a 0.9-wide grey line; a Trendyol drawing never has one.
$splitLines = static fn (string $ops): int => preg_match_all('/^0\.200 G 0\.90 w /m', $ops);
/**
 * Vector shapes of the Trendyol ticket, read from the drawing operators: product rectangles (2.5 black
 * strokes), dimension lines (1.8), small direction icons (1.2), arrowheads and warning signs (filled
 * triangles), position frames (1.4), black badges and every coloured text operator.
 */
$shapes = static function (array $rendered) use ($operators): array {
    $result = ['rects' => [], 'dims' => [], 'icons' => 0, 'heads' => [], 'signs' => [], 'frames' => [], 'badges' => [], 'colored' => []];
    foreach ($operators($rendered['document']) as $index => $ops) {
        $page = $index + 1;
        foreach (['rects' => '2\.50', 'frames' => '1\.40'] as $key => $stroke) {
            preg_match_all('/^0\.000 G ' . $stroke . ' w ([-\d.]+) ([-\d.]+) ([-\d.]+) ([-\d.]+) re S/m', $ops, $m, PREG_SET_ORDER);
            foreach ($m as $r) {
                $result[$key][] = ['page' => $page, 'x' => (float) $r[1], 'y' => (float) $r[2], 'w' => (float) $r[3], 'h' => (float) $r[4]];
            }
        }
        preg_match_all('/^0\.000 g ([-\d.]+) ([-\d.]+) ([-\d.]+) ([-\d.]+) re f/m', $ops, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $result['badges'][] = ['page' => $page, 'x' => (float) $r[1], 'y' => (float) $r[2], 'w' => (float) $r[3], 'h' => (float) $r[4]];
        }
        preg_match_all('/^0\.000 G 1\.80 w ([-\d.]+) ([-\d.]+) m ([-\d.]+) ([-\d.]+) l S/m', $ops, $m, PREG_SET_ORDER);
        foreach ($m as $d) {
            $result['dims'][] = ['page' => $page, 'x1' => (float) $d[1], 'y1' => (float) $d[2], 'x2' => (float) $d[3], 'y2' => (float) $d[4]];
        }
        $result['icons'] += preg_match_all('/^0\.000 G 1\.20 w [-\d.]+ [-\d.]+ m/m', $ops);
        preg_match_all('/^0\.000 g ([-\d.]+) ([-\d.]+) m ([-\d.]+) ([-\d.]+) l ([-\d.]+) ([-\d.]+) l h f/m', $ops, $m, PREG_SET_ORDER);
        foreach ($m as $p) {
            $xs = [(float) $p[1], (float) $p[3], (float) $p[5]];
            $ys = [(float) $p[2], (float) $p[4], (float) $p[6]];
            $box = ['page' => $page, 'x' => min($xs), 'y' => min($ys), 'w' => max($xs) - min($xs), 'h' => max($ys) - min($ys), 'tipX' => $xs[0], 'tipY' => $ys[0]];
            $result[max($box['w'], $box['h']) > 17 ? 'signs' : 'heads'][] = $box;
        }
        preg_match_all('/^BT ([\d.]+) ([\d.]+) ([\d.]+) rg \/F\w+ [\d.]+ Tf ([-\d.]+) ([-\d.]+) Td/m', $ops, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            $result['colored'][] = ['page' => $page, 'rgb' => [(float) $t[1], (float) $t[2], (float) $t[3]], 'x' => (float) $t[4], 'y' => (float) $t[5]];
        }
    }
    return $result;
};
$texts = static fn (array $rendered, string $text): array => array_values(array_filter($rendered['texts'], static fn (array $t): bool => $t['text'] === $text));
$intersects = static fn (array $a, array $b): bool => min($a['x'] + $a['w'], $b['x'] + $b['w']) - max($a['x'], $b['x']) > 0.5 && min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']) > 0.5;
$textBox = static fn (array $t): array => ['x' => $t['x'], 'y' => $t['y'] - 0.22 * $t['size'], 'w' => $t['width'], 'h' => 0.94 * $t['size']];
$inside = static fn (array $point, array $box): bool => $point['page'] === $box['page'] && $point['x'] >= $box['x'] - 0.01 && $point['x'] <= $box['x'] + $box['w'] + 0.01 && $point['y'] >= $box['y'] - 0.01 && $point['y'] <= $box['y'] + $box['h'] + 0.01;

/**
 * Every schema 2 Trendyol ticket: the expected number of A4 pages, nothing outside, no overlap, black text,
 * every page identified (QR, order, revision, "Pagina X / Y"), no prices.
 */
$layout = static function (string $name, array $rendered, int $pages = 1) use ($payload, $shapes, $intersects, $textBox, $inside): void {
    $texts = $rendered['texts'];
    check($rendered['pages'] === $pages, "{$name}: exactly {$pages} A4 page(s), got {$rendered['pages']}");
    $outside = array_filter($texts, static fn (array $t): bool => !($t['x'] >= 28 && $t['x'] + $t['width'] <= 595.28 - 28 && $t['y'] >= 20 && $t['y'] + $t['size'] <= 841.89 - 20));
    check($outside === [], "{$name}: every text inside the printable A4 area " . json_encode(array_column($outside, 'text'), JSON_UNESCAPED_UNICODE));
    check(array_filter($texts, static fn (array $t): bool => $t['size'] < 6.5) === [], "{$name}: no unreadably small text");
    check(!str_contains($rendered['all'], '…'), "{$name}: nothing shortened with an ellipsis");
    $overlaps = [];
    foreach ($texts as $i => $a) {
        foreach (array_slice($texts, $i + 1) as $b) {
            if ($a['page'] !== $b['page']) {
                continue;
            }
            $overlapX = min($a['x'] + $a['width'], $b['x'] + $b['width']) - max($a['x'], $b['x']);
            $overlapY = min($a['y'] + $a['size'] * 0.72, $b['y'] + $b['size'] * 0.72) - max($a['y'], $b['y']);
            if ($overlapX > 1.5 && $overlapY > 1.5) {
                $overlaps[] = "{$a['text']} / {$b['text']}";
            }
        }
    }
    check($overlaps === [], "{$name}: no overlapping text " . json_encode($overlaps, JSON_UNESCAPED_UNICODE));
    $matrix = QrCode::matrix($payload, 'Q');
    for ($page = 1; $page <= $rendered['pages']; $page++) {
        $onPage = array_column(array_filter($texts, static fn (array $t): bool => $t['page'] === $page), 'text');
        check(in_array("Pagina {$page} / {$rendered['pages']}", $onPage, true) && in_array('#10000000001', $onPage, true) && in_array('TRENDYOL', $onPage, true) && in_array('REVIZIA 1', $onPage, true) && in_array('Cod manual: 10000000001', $onPage, true),
            "{$name}: page {$page} carries the order number, source, revision, manual code and page number");
        check($rendered['pages'] === 1 || array_filter($onPage, static fn (string $t): bool => str_starts_with($t, "PAGINA {$page} / {$rendered['pages']} · POZIȚI")) !== [], "{$name}: page {$page} names its positions");
        $qrs = array_values(array_filter($rendered['document']->qrLog(), static fn (array $q): bool => $q['page'] === $page));
        check(count($qrs) === 1 && $qrs[0]['size'] >= 100 && $qrs[0]['matrix'] === $matrix, "{$name}: page {$page} has the one canonical order QR, same payload");
    }
    check(preg_match('/@|RON|EUR|\bLei\b|preț|TVA|plată/u', $rendered['all']) !== 1, "{$name}: no email, price or payment data");

    // The drawing: every shape inside a position frame, clear of the footer; no text crosses a frame, a
    // dimension line, an arrowhead or a product outline (only the product number sits inside its rectangle).
    $shape = $shapes($rendered);
    $dims = array_map(static fn (array $d): array => ['page' => $d['page'], 'x' => min($d['x1'], $d['x2']) - 0.9, 'y' => min($d['y1'], $d['y2']) - 0.9, 'w' => abs($d['x2'] - $d['x1']) + 1.8, 'h' => abs($d['y2'] - $d['y1']) + 1.8], $shape['dims']);
    $clipped = array_filter([...$shape['rects'], ...$shape['heads'], ...$dims], static fn (array $part): bool => $part['y'] <= 40 || array_filter($shape['frames'], static fn (array $f): bool => $f['page'] === $part['page']
        && $part['x'] >= $f['x'] && $part['x'] + $part['w'] <= $f['x'] + $f['w'] && $part['y'] >= $f['y'] && $part['y'] + $part['h'] <= $f['y'] + $f['h']) === []);
    check($clipped === [], "{$name}: every drawing element inside its position frame, never clipped or under the footer " . json_encode(array_values($clipped)));
    $edges = [];
    foreach ([...$shape['rects'], ...$shape['frames']] as $r) {
        foreach ([[$r['x'], $r['y'], $r['w'], 0.0], [$r['x'], $r['y'] + $r['h'], $r['w'], 0.0], [$r['x'], $r['y'], 0.0, $r['h']], [$r['x'] + $r['w'], $r['y'], 0.0, $r['h']]] as [$ex, $ey, $ew, $eh]) {
            $edges[] = ['page' => $r['page'], 'x' => $ex - 1.25, 'y' => $ey - 1.25, 'w' => $ew + 2.5, 'h' => $eh + 2.5];
        }
    }
    $collisions = [];
    foreach ($texts as $t) {
        foreach ([...$edges, ...$dims, ...$shape['heads'], ...$shape['signs']] as $part) {
            if ($part['page'] === $t['page'] && !($t['text'] === '!' && $part['w'] > 17) && $intersects($textBox($t), $part)) {
                $collisions[] = $t['text'];
            }
        }
    }
    check($collisions === [], "{$name}: no text crosses a frame, a dimension line, an arrowhead or a product outline " . json_encode($collisions, JSON_UNESCAPED_UNICODE));
    // Black-and-white: inside the positions, text is black only; white only on a black badge or warning sign.
    $coloured = array_filter($shape['colored'], static fn (array $c): bool => array_filter($shape['frames'], static fn (array $f): bool => $inside($c, $f)) !== []
        && !($c['rgb'] === [1.0, 1.0, 1.0] && array_filter([...$shape['signs'], ...$shape['badges']], static fn (array $s): bool => $inside($c, $s)) !== []));
    check($coloured === [], "{$name}: no grey or coloured text inside a position (black-and-white print)");
};

/**
 * One drawn product: the horizontal dimension arrow spans exactly the rectangle's width just above it and is
 * labelled with the width; the vertical one spans exactly its height just left of it and is labelled with
 * the height; the rectangle has the proportions of width × height (never inverted). `$sizes`: the smallest
 * accepted width label, height value and height caption.
 */
$measured = static function (string $name, array $rendered, array $rect, string $width, string $height, float $ratio, array $sizes = [13, 14, 12]) use ($shapes, $texts): void {
    $shape = $shapes($rendered);
    $page = $rect['page'];
    $top = $rect['y'] + $rect['h'];
    $tip = static fn (float $x, float $y): bool => array_filter($shape['heads'], static fn (array $h): bool => $h['page'] === $page && abs($h['tipX'] - $x) < 0.02 && abs($h['tipY'] - $y) < 0.02) !== [];
    $horizontal = array_values(array_filter($shape['dims'], static fn (array $d): bool => $d['page'] === $page && $d['y1'] === $d['y2'] && $d['y1'] > $top + 4 && $d['y1'] < $top + 20 && $d['x1'] > $rect['x'] && $d['x2'] < $rect['x'] + $rect['w']));
    check(count($horizontal) === 1 && $tip($rect['x'], $horizontal[0]['y1']) && $tip($rect['x'] + $rect['w'], $horizontal[0]['y1']), "{$name}: a horizontal double arrow spans exactly the product width");
    $vertical = array_values(array_filter($shape['dims'], static fn (array $d): bool => $d['page'] === $page && $d['x1'] === $d['x2'] && $d['x1'] < $rect['x'] - 4 && $d['x1'] > $rect['x'] - 20 && $d['y1'] > $rect['y'] && $d['y2'] < $top));
    check(count($vertical) === 1 && $tip($vertical[0]['x1'], $rect['y']) && $tip($vertical[0]['x1'], $top), "{$name}: a vertical double arrow spans exactly the product height");
    $widthLabel = array_values(array_filter($texts($rendered, 'LĂȚIME: ' . $width), static fn (array $t): bool => $t['page'] === $page && $t['y'] > $horizontal[0]['y1'] && $t['y'] < $horizontal[0]['y1'] + 25
        && min($t['x'] + $t['width'], $rect['x'] + $rect['w']) - max($t['x'], $rect['x']) > min($t['width'], $rect['w']) * 0.5));
    check(count($widthLabel) === 1 && $widthLabel[0]['size'] >= $sizes[0], "{$name}: \"LĂȚIME: {$width}\" sits on its horizontal arrow, large");
    $heightLabel = array_values(array_filter($texts($rendered, 'ÎNĂLȚIME:'), static fn (array $t): bool => $t['page'] === $page && $t['x'] + $t['width'] <= $vertical[0]['x1'] - 4 && $t['x'] + $t['width'] > $vertical[0]['x1'] - 30 && $t['y'] > $rect['y'] - 25 && $t['y'] < $top));
    $heightValue = array_values(array_filter($texts($rendered, $height), static fn (array $t): bool => $t['page'] === $page && abs($t['x'] + $t['width'] - $vertical[0]['x1'] + 8) < 0.1 && $t['y'] > $rect['y'] - 25 && $t['y'] < $top));
    check(count($heightLabel) === 1 && count($heightValue) === 1 && $heightValue[0]['size'] >= $sizes[1] && $heightLabel[0]['size'] >= $sizes[2], "{$name}: \"ÎNĂLȚIME: {$height}\" sits on its vertical arrow, large");
    if ($rect['w'] > 24.01 && $rect['h'] > 24.01) {
        check(abs($rect['w'] / $rect['h'] - $ratio) < 0.02, "{$name}: drawn to the proportions width × height, never inverted");
    }
    check(($ratio > 1) === ($rect['w'] > $rect['h']) || abs($ratio - 1) < 0.05, "{$name}: the longer side is drawn longer");
};
$cardSizes = [10, 10, 8];

// ---- One position: the large layout (owner's reference shaped like 11709027615: 12754-21, Crem, 2 × 250 × 260 cm)
$reference = static fn (int $quantity, array $overrides = []): array => $trendyol([$variant(1, '12754-21', '250.000', '260.000', $quantity, $overrides + ['color' => 'Crem'])]);
$t1 = $render($reference(2));
$layout('one position, 2 products', $t1);
foreach (['PRODUSE DE EXECUTAT · 1 POZIȚIE · 2 PRODUSE COMANDATE', '2 ×', 'DRAPERIE', '2 PRODUSE COMANDATE · FIECARE: 250 × 260 CM', 'DIMENSIUNI PER PRODUS',
    'LĂȚIME', 'ÎNĂLȚIME', 'PRODUS 1', 'PRODUS 2', 'poziția', '12754-21', 'DOUĂ PRODUSE SEPARATE.', 'NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN DOUĂ.'] as $text) {
    check($texts($t1, $text) !== [], "one position: shows \"{$text}\"");
}
check(str_contains($t1['all'], 'Draperie catifea TEST · Culoare: Crem · Variantă: 250x260'), 'one position: code, name, colour and variant');
check($texts($t1, OrderedProductsTicket::SHARED_RULE) === [], 'one position: its own full warning, not the shared rule');
$figure = $texts($t1, '2 ×')[0];
check($figure['size'] >= 28 && $figure['size'] > max(array_column(array_filter($t1['texts'], static fn (array $t): bool => !in_array($t['text'], ['2 ×', '#10000000001'], true)), 'size')), 'one position: the ordered quantity is the largest figure of the block');
foreach (['DOUĂ PRODUSE SEPARATE.' => 14, 'NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN DOUĂ.' => 14, 'PRODUS 1' => 15, 'PRODUS 2' => 15, 'DIMENSIUNI PER PRODUS' => 10.5, 'DRAPERIE' => 21] as $text => $size) {
    check($texts($t1, $text)[0]['size'] >= $size, "one position: \"{$text}\" printed at {$size} pt or more");
}
$t1Shapes = $shapes($t1);
$frame = $t1Shapes['frames'][0];
check(array_filter($t1['texts'], static fn (array $t): bool => $t['y'] > $frame['y'] && $t['y'] < $frame['y'] + $frame['h'] && $t['size'] < 8) === [], 'one position: nothing in the block under 8 pt');
check(count($t1Shapes['rects']) === 2 && $splitLines($t1['ops']) === 0, 'one position: two separate product rectangles, no dividing line');
[$first, $second] = $t1Shapes['rects'];
check($second['x'] - ($first['x'] + $first['w']) > 60 && $first['y'] === $second['y'] && $first['w'] === $second['w'] && $first['h'] === $second['h'], 'one position: the two products are the same size, side by side, clearly apart');
check($first['h'] >= 110, 'one position: each product is drawn large (at least 110 pt high)');
$measured('one position, product 1', $t1, $first, '250 CM', '260 CM', 250 / 260);
$measured('one position, product 2', $t1, $second, '250 CM', '260 CM', 250 / 260);
foreach ([[$first, '1'], [$second, '2']] as [$rect, $number]) {
    $in = array_filter($texts($t1, $number), static fn (array $t): bool => $t['x'] > $rect['x'] && $t['x'] + $t['width'] < $rect['x'] + $rect['w'] && $t['y'] > $rect['y'] && $t['y'] + $t['size'] < $rect['y'] + $rect['h']);
    $label = array_filter($texts($t1, 'PRODUS ' . $number), static fn (array $t): bool => $t['y'] > $rect['y'] + $rect['h'] && min($t['x'] + $t['width'], $rect['x'] + $rect['w']) - max($t['x'], $rect['x']) > 0);
    check(count($in) === 1 && count($label) === 1, "one position: product {$number} is numbered inside and labelled above its own rectangle");
}
check(count($t1Shapes['heads']) === 8 + 4 && count($t1Shapes['dims']) === 4 && $t1Shapes['icons'] === 2, 'one position: two arrows per product, plus the width and height icons');
check(count($t1Shapes['signs']) === 1, 'one position: one warning sign, on the separation rule');
check(!str_contains($t1['all'], '125') && !str_contains($t1['all'], '500') && !str_contains($t1['all'], '130') && !str_contains($t1['all'], 'buc.') && $texts($t1, 'BUCĂȚI') === [], 'one position: no halved or doubled measurement, no piece split');
check($texts($t1, 'METRI (LINIE)') !== [] && !preg_match('/\d+(,\d+)? m\b/u', $t1['all']), 'one position: unknown meters stay unknown (no invented consumption)');
check(ProductionTicketPdf::render($reference(2), $revision) === ProductionTicketPdf::render($reference(2), $revision), 'the same snapshot always renders the same bytes');

$t2 = $render($trendyol([$variant(1, '12754-20', '200.000', '260.000', 1)]));
$layout('one product', $t2);
check(str_contains($t2['all'], 'PRODUSE DE EXECUTAT · 1 POZIȚIE · 1 PRODUS COMANDAT') && str_contains($t2['all'], '1 PRODUS COMANDAT · 200 × 260 CM') && !str_contains($t2['all'], 'FIECARE'), 'one product of 200 × 260 cm');
check($texts($t2, 'Un singur produs comandat.') !== [] && $texts($t2, 'Comanda nu conține o împărțire în bucăți.') !== [] && !str_contains($t2['all'], 'SEPARATE'), 'one product: no split is inferred from a 200 cm width');
$t2Shapes = $shapes($t2);
check(count($t2Shapes['rects']) === 1 && $splitLines($t2['ops']) === 0 && !str_contains($t2['all'], '100 CM') && $t2Shapes['signs'] === [], 'one product: one rectangle, not divided, no warning');
$measured('one product', $t2, $t2Shapes['rects'][0], '200 CM', '260 CM', 200 / 260);

$t3 = $render($reference(3));
$layout('one position, 3 products', $t3);
check(str_contains($t3['all'], '3 PRODUSE COMANDATE · FIECARE: 250 × 260 CM') && $texts($t3, 'TREI PRODUSE SEPARATE.') !== [] && $texts($t3, 'NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN TREI.') !== [], 'three separate products, 250 × 260 cm each');
$t3Shapes = $shapes($t3);
check(count($t3Shapes['rects']) === 1 && $texts($t3, 'FIECARE PRODUS') !== [] && count($texts($t3, '3 ×')) === 2 && $texts($t3, '3 ×')[1]['size'] >= 28 && $texts($t3, 'PRODUSE IDENTICE') !== [], 'three products: one large drawing with a 3 × multiplier as large as the quantity');
$measured('one position, 3 products', $t3, $t3Shapes['rects'][0], '250 CM', '260 CM', 250 / 260);
check(!preg_match('/83[,.]3|86[,.]6/u', $t3['all']), 'three products: width and height are never divided by 3');

foreach (['wide' => ['400.000', '150.000', '400 CM', '150 CM'], 'narrow' => ['100.000', '300.000', '100 CM', '300 CM'], 'decimal' => ['247.500', '262.500', '247,5 CM', '262,5 CM']] as $name => [$w, $h, $wl, $hl]) {
    $sized = $render($trendyol([$variant(1, 'S-1', $w, $h, 2)]));
    $layout("one position, {$name}", $sized);
    foreach ($shapes($sized)['rects'] as $i => $rect) {
        $measured("one position, {$name} product {$i}", $sized, $rect, $wl, $hl, ProductionTicketPdf::units($w) / ProductionTicketPdf::units($h));
    }
}

$t5 = $render($trendyol([$line(1, ['code' => '12754-99', 'name' => 'Draperie TEST', 'kind' => 'drapery', 'color' => 'Crem', 'quantity' => 2])]));
$layout('one position, no dimensions', $t5);
check(str_contains($t5['all'], '2 PRODUSE COMANDATE · DIMENSIUNI NECONFIRMATE') && $texts($t5, 'DIMENSIUNI NECONFIRMATE')[0]['size'] >= 14 && str_contains($t5['all'], 'Dimensiunile lipsesc la sursă — verifică înainte de croire.'), 'no dimensions: quantity visible, missing dimensions said prominently');
check($texts($t5, 'LĂȚIME') !== [] && $texts($t5, 'ÎNĂLȚIME') !== [] && count($texts($t5, '—')) === 3, 'no dimensions: the width and height fields stay, each showing "—"');
$t5Shapes = $shapes($t5);
check($t5Shapes['rects'] === [] && $t5Shapes['dims'] === [] && $t5Shapes['heads'] === [] && $t5Shapes['icons'] === 0 && !preg_match('/\d+ × \d+|LĂȚIME: \d|\d+ CM/u', $t5['all']), 'no dimensions: no drawing, no arrows and no guessed size');

$many = $render($reference(20, [
    'name' => 'Draperie blackout catifea premium cu rejansă ascunsă și tiv dublu, culoare gri antracit, pentru dormitor și living — ediție ăâîșț ĂÂÎȘȚ TEST',
    'meters' => '5.200', 'productionNotes' => 'Atenție la sensul catifelei.', 'options' => [['label' => 'Bucăți', 'value' => '2 buc.']],
]));
$layout('20 products', $many);
check(str_contains($many['all'], '1 POZIȚIE · 20 DE PRODUSE COMANDATE') && $texts($many, '20 DE PRODUSE SEPARATE.') !== [] && $texts($many, 'NU O SINGURĂ DRAPERIE ÎMPĂRȚITĂ ÎN 20.') !== [], '20 products: Romanian "de" agreement');
check(count($shapes($many)['rects']) === 1 && count($texts($many, '20 ×')) === 2 && $texts($many, 'SCHIȚĂ · UNUL DIN CELE 20 DE PRODUSE IDENTICE') !== [], '20 products: one drawing with a 20 × multiplier, not 20 drawings');
$measured('20 products', $many, $shapes($many)['rects'][0], '250 CM', '260 CM', 250 / 260);
check(str_contains($many['all'], 'Bucăți: 2 buc.') && $splitLines($many['ops']) === 0 && $texts($many, 'BUCĂȚI') === [] && $texts($many, 'egale') === [], 'a Trendyol option is printed as source text only, never turned into a panel split');
check(str_contains($many['all'], '5,2') && str_contains(str_replace("\n", ' ', $many['all']), 'pentru dormitor și living — ediție ăâîșț ĂÂÎȘȚ TEST · Culoare: Crem · Variantă: 250x260'), 'meters as entered and the whole long name are printed');
foreach ([101 => '101 PRODUSE COMANDATE', 119 => '119 PRODUSE COMANDATE', 120 => '120 DE PRODUSE COMANDATE', 200 => '200 DE PRODUSE COMANDATE'] as $quantity => $expected) {
    check(str_contains($render($trendyol([$variant(1, 'X-1', '140.000', '245.000', $quantity)]))['all'], '1 POZIȚIE · ' . $expected), "count agreement for {$quantity}");
}

// ---- Acceptance matrix: every order of 1 to 9 positions is ONE A4 page with every position complete -----
$catalog = [
    ['12754-21', 'Draperie catifea blackout TEST', 'drapery', 'Crem', '250.000', '260.000'],
    ['12754-31', 'Draperie in natural TEST', 'drapery', 'Gri', '300.000', '270.000'],
    ['30411-05', 'Perdea voal fin ăâîșț TEST', 'curtain', 'Alb', '400.000', '150.000'],
    ['12754-08', 'Draperie dim-out TEST', 'drapery', 'Bej', '100.000', '300.000'],
    ['30411-12', 'Perdea in cu tiv plumb TEST', 'curtain', 'Ivoire', '247.500', '262.500'],
    ['12754-44', 'Draperie catifea TEST', 'drapery', 'Verde smarald', '140.000', '245.000'],
    ['55120-02', 'Produs decorativ jacquard TEST', 'other', 'Auriu', '200.000', '250.000'],
    ['30411-19', 'Perdea tul TEST', 'curtain', 'Alb optic', '500.000', '260.000'],
    ['12754-60', 'Draperie bumbac TEST', 'drapery', 'Antracit', '150.000', '230.000'],
];
$position = static function (int $number, int $quantity, array $overrides = []) use ($line, $catalog): array {
    [$code, $name, $kind, $color, $width, $height] = $catalog[($number - 1) % count($catalog)];
    return $line($number, $overrides + ['code' => $code, 'name' => $name, 'kind' => $kind, 'color' => $color, 'variant' => rtrim(rtrim($width, '0'), '.') . 'x' . rtrim(rtrim($height, '0'), '.'),
        'width' => $width, 'height' => $height, 'unit' => 'cm', 'quantity' => $quantity]);
};
$label = static fn (string $decimal): string => mb_strtoupper((string) TicketSnapshot::measure($decimal, 'cm'));
$squash = static fn (string $text): string => preg_replace('/\s+/u', ' ', $text) ?? $text;
// Smallest quantity and name sizes per number of positions (the owner's typography targets, lower bounds).
$floors = [1 => [26, 10], 2 => [22, 11], 3 => [17, 10], 4 => [17, 10], 5 => [15, 9], 6 => [15, 9], 7 => [12, 8], 8 => [12, 8], 9 => [12, 8]];
$matrix = ['T1' => [1], 'T2' => [2], 'T3' => [1, 2], 'T4' => [1, 2, 1], 'T5' => [2, 1, 3, 1], 'T6' => [1, 2, 1, 1, 3], 'T7' => [2, 1, 1, 3, 1, 2],
    'T8' => [1, 1, 2, 1, 1, 2, 1], 'T9' => [2, 1, 1, 1, 3, 1, 1, 2], 'T10' => [2, 1, 3, 1, 1, 2, 1, 1, 2]];
foreach ($matrix as $test => $quantities) {
    $count = count($quantities);
    $lines = array_map(static fn (int $i): array => $position($i + 1, $quantities[$i], $i === 1 ? ['productionNotes' => 'Atenție la sensul catifelei; tiv dublu 10 cm.'] : []), array_keys($quantities));
    $order = $trendyol($lines);
    $rendered = $render($order);
    $name = "{$test} ({$count} positions)";
    $layout($name, $rendered);
    $ordered = array_sum($quantities);
    check(str_contains($rendered['all'], 'PRODUSE DE EXECUTAT · ' . ($count === 1 ? '1 POZIȚIE' : "{$count} POZIȚII") . ' · ' . ($ordered === 1 ? '1 PRODUS COMANDAT' : "{$ordered} PRODUSE COMANDATE")), "{$name}: positions and ordered products counted separately");
    check(count($texts($rendered, OrderedProductsTicket::SHARED_RULE)) === ($count > 1 ? 1 : 0), "{$name}: the separation rule is printed once, not in every card");
    $shape = $shapes($rendered);
    $frames = $shape['frames'];
    check(count($frames) === $count, "{$name}: one frame per position");
    $all = $squash(implode(' ', array_column($rendered['texts'], 'text')));
    foreach ($lines as $i => $item) {
        $frameTexts = array_values(array_filter($rendered['texts'], static fn (array $t): bool => $inside(['page' => $t['page'], 'x' => $t['x'], 'y' => $t['y']], $frames[$i])));
        $inFrame = $squash(implode(' ', array_column($frameTexts, 'text')));
        check(str_contains($inFrame, $squash(ProductionTicketPdf::nameText($item))) && str_contains($inFrame, (string) $item['code']), "{$name}: position {$item['line']} prints its code and complete name in its own card");
        check(in_array("{$item['quantity']} ×", array_column($frameTexts, 'text'), true), "{$name}: position {$item['line']} prints its own quantity");
        $figure = array_values(array_filter($frameTexts, static fn (array $t): bool => $t['text'] === "{$item['quantity']} ×"))[0];
        check($figure['size'] >= $floors[$count][0], "{$name}: position {$item['line']} quantity at {$floors[$count][0]} pt or more");
        check(array_filter($frameTexts, static fn (array $t): bool => $t['size'] < 8) === [], "{$name}: nothing in position {$item['line']} under 8 pt");
        if ($count > 1) {
            check(in_array('#' . $item['line'], array_column($frameTexts, 'text'), true), "{$name}: position {$item['line']} carries its number");
            check(($item['quantity'] > 1) === in_array("{$item['quantity']} PRODUSE SEPARATE", array_column($frameTexts, 'text'), true), "{$name}: position {$item['line']} says its products are separate when there are several");
            $names = array_filter($frameTexts, static fn (array $t): bool => str_contains($squash(ProductionTicketPdf::nameText($item)), $t['text']) && $t['font'] === 'R');
            check($names !== [] && min(array_column($names, 'size')) >= $floors[$count][1], "{$name}: position {$item['line']} name at {$floors[$count][1]} pt or more");
        }
        $rects = array_values(array_filter($shape['rects'], static fn (array $r): bool => $inside($r, $frames[$i])));
        check($rects !== [] && count($rects) <= min(2, $item['quantity']) && ($count === 1 || count($rects) === 1 || $item['quantity'] === 2), "{$name}: position {$item['line']} drawn once per product up to two, else once for all");
        foreach ($rects as $r => $rect) {
            $measured("{$name}: position {$item['line']}, drawing {$r}", $rendered, $rect, $label($item['width']), $label($item['height']),
                ProductionTicketPdf::units($item['width']) / ProductionTicketPdf::units($item['height']), $count === 1 ? [13, 14, 12] : $cardSizes);
        }
    }
    check($splitLines($rendered['ops']) === 0 && !str_contains($all, 'buc.'), "{$name}: no panel split anywhere");
    $columns = count(array_unique(array_map(static fn (array $f): string => (string) $f['x'], $frames)));
    $expected = match (true) { $count === 1 => 1, $count <= 3 => 1, $count === 4 => 2, $count === 9 => 3, default => null };
    check($expected === null || $columns === $expected, "{$name}: layout of {$columns} column(s)");
    check($count === 1 || abs(min(array_column($frames, 'y')) - 46) < 1, "{$name}: the cards use the page down to the footer");
}

// ---- Content edge cases on one page ---------------------------------------------------------------------
$missing = $render($trendyol([$line(1, ['code' => '12754-99', 'name' => 'Draperie TEST fără dimensiuni', 'kind' => 'drapery', 'color' => 'Crem', 'quantity' => 2]), $position(2, 1), $position(3, 2)]));
$layout('three positions, one without dimensions', $missing);
$missingFrame = $shapes($missing)['frames'][0];
$missingTexts = array_column(array_filter($missing['texts'], static fn (array $t): bool => $inside(['page' => 1, 'x' => $t['x'], 'y' => $t['y']], $missingFrame)), 'text');
check(in_array('DIMENSIUNI NECONFIRMATE', $missingTexts, true) && in_array('LĂȚIME: —', $missingTexts, true) && in_array('ÎNĂLȚIME: —', $missingTexts, true), 'card without dimensions: the width and height fields stay, marked unconfirmed');
check(array_filter($shapes($missing)['rects'], static fn (array $r): bool => $inside($r, $missingFrame)) === [] && !preg_match('/\d+ CM/u', implode(' ', $missingTexts)), 'card without dimensions: no drawing and no guessed number');
$longName = 'Draperie blackout catifea premium cu rejansă ascunsă și tiv dublu, culoare gri antracit, pentru dormitor și living — ediție ăâîșț ĂÂÎȘȚ TEST';
$longFour = $render($trendyol([$position(1, 2, ['name' => $longName, 'productionNotes' => 'Tiv dublu la 10 cm, rejansă ascunsă de 8 cm, fără pliuri suplimentare.', 'options' => [['label' => 'Bucăți', 'value' => '2 buc.']]]), $position(2, 1), $position(3, 3), $position(4, 1)]));
$layout('four positions, long name, notes and option', $longFour);
check(str_contains($squash(implode(' ', array_column($longFour['texts'], 'text'))), $squash($longName)) && str_contains($longFour['all'], 'Bucăți: 2 buc.') && $splitLines($longFour['ops']) === 0, 'four positions: the long name, notes and option text are complete; the option is never a split');
$fallback = $render($trendyol([$variant(1, 'T-1', '250.000', '260.000', 2, ['name' => $longName, 'notes' => str_repeat('Notă lungă de test, cu diacritice ăâîșț. ', 14), 'productionNotes' => str_repeat('Producție: verifică sensul catifelei. ', 14)])]));
$layout('one position with long notes', $fallback);
check(str_contains($squash(implode(' ', array_column($fallback['texts'], 'text'))), trim($squash(str_repeat('Producție: verifică sensul catifelei. ', 14)))), 'one position with long notes: every note is printed in full on the one page');

// ---- More than one page: at most PER_PAGE positions a page, nothing lost, never refused for its length ----
$normal = static fn (int $count): array => $trendyol(array_map(static fn (int $i): array => $position($i, 1 + ($i * 7) % 3, ['code' => explode('-', $catalog[($i - 1) % count($catalog)][0])[0] . '-' . (10 + $i)]
    + ($i % 9 === 2 ? ['productionNotes' => 'Atenție la sensul catifelei; tiv dublu 10 cm.'] : [])), range(1, $count)));
$cardsOf = static function (array $rendered) use ($shapes, $inside): array {
    // Per page: the positions printed there (white badge "#n" inside a card frame).
    $shape = $shapes($rendered);
    $pages = [];
    foreach ($rendered['texts'] as $t) {
        if (preg_match('/^#(\d+)$/', $t['text'], $m) === 1 && array_filter($shape['frames'], static fn (array $f): bool => $inside(['page' => $t['page'], 'x' => $t['x'], 'y' => $t['y']], $f)) !== []) {
            $pages[$t['page']][] = (int) $m[1];
        }
    }
    return $pages;
};
foreach ([1 => 1, 4 => 1, 9 => 1, 10 => 2, 18 => 2, 19 => 3, 27 => 3, 36 => 4, 40 => 5] as $count => $expectedPages) {
    $order = $normal($count);
    $rendered = $render($order);
    $name = "{$count} positions";
    $layout($name, $rendered, $expectedPages);
    check(ProductionTicketPdf::layoutError($order) === null && ProductionTicketPdf::pageCount($order, $revision) === $expectedPages, "{$name}: printable, {$expectedPages} page(s), never refused for its length");
    if ($count === 1) {
        continue;
    }
    $perPage = $cardsOf($rendered);
    check(max(array_map('count', $perPage)) <= OrderedProductsTicket::PER_PAGE && max(array_map('count', $perPage)) - min(array_map('count', $perPage)) <= 1, "{$name}: at most nine positions a page, spread evenly");
    check(array_merge(...array_values($perPage)) === range(1, $count), "{$name}: every position printed exactly once, in order");
    $shape = $shapes($rendered);
    foreach ($order['lines'] as $item) {
        $frame = array_values(array_filter($shape['frames'], static fn (array $f): bool => array_filter($rendered['texts'], static fn (array $t): bool => $t['text'] === '#' . $item['line'] && $inside(['page' => $t['page'], 'x' => $t['x'], 'y' => $t['y']], $f)) !== []))[0];
        $inFrame = array_values(array_filter($rendered['texts'], static fn (array $t): bool => $inside(['page' => $t['page'], 'x' => $t['x'], 'y' => $t['y']], $frame)));
        $joined = $squash(implode(' ', array_column($inFrame, 'text')));
        $rects = array_values(array_filter($shape['rects'], static fn (array $r): bool => $inside($r, $frame)));
        check(str_contains($joined, (string) $item['code']) && str_contains($joined, $squash(ProductionTicketPdf::nameText($item))) && in_array("{$item['quantity']} ×", array_column($inFrame, 'text'), true)
            && ($item['productionNotes'] === null || str_contains($joined, $item['productionNotes'])) && $rects !== [], "{$name}: position {$item['line']} complete in its card (code, name, variant, quantity, notes, drawing)");
        $measured("{$name}: position {$item['line']}", $rendered, $rects[0], $label($item['width']), $label($item['height']), ProductionTicketPdf::units($item['width']) / ProductionTicketPdf::units($item['height']), $cardSizes);
    }
    check(count($texts($rendered, OrderedProductsTicket::SHARED_RULE)) === $expectedPages, "{$name}: the separation rule once per page");
}
check(ProductionTicketPdf::render($normal(40), $revision) === ProductionTicketPdf::render($normal(40), $revision), 'pagination is deterministic for the same snapshot');

// Long names and notes move positions to the next page before nine: nothing is shortened.
$heavy = $trendyol(array_map(static fn (int $i): array => $position($i, 1 + $i % 3, ['name' => $longName, 'productionNotes' => 'Tiv dublu la 10 cm, rejansă ascunsă de 8 cm. Atenție la sensul catifelei la croire. ' . $i]), range(1, 9)));
$heavyRendered = $render($heavy);
$layout('9 positions with long names and notes', $heavyRendered, 2);
check(array_merge(...array_values($cardsOf($heavyRendered))) === range(1, 9) && str_contains($squash($heavyRendered['all']), $squash('Atenție la sensul catifelei la croire. 9')), '9 positions with long names and notes: two pages, every position and note complete');

// A card taller than a page continues: every continuation repeats identity, quantity and dimensions.
$clientNote = str_repeat('Notă de client foarte lungă, păstrată integral, cu diacritice ăâîșț. ', 40);
$productionNote = str_repeat('Producție: verifică sensul catifelei și tivul dublu de 10 cm înainte de croire. ', 60);
$giant = $render($trendyol([$variant(1, '12754-21', '250.000', '260.000', 2, ['color' => 'Crem', 'name' => $longName, 'notes' => $clientNote, 'productionNotes' => $productionNote])]));
$layout('one position with notes longer than a page', $giant, $giant['pages']);
check($giant['pages'] >= 2 && ProductionTicketPdf::layoutError($trendyol([$variant(1, '12754-21', '250.000', '260.000', 2, ['notes' => $clientNote, 'productionNotes' => $productionNote])])) === null, 'notes longer than a page: printed over several pages, never refused');
$giantShapes = $shapes($giant);
check(count(array_unique(array_column($giantShapes['rects'], 'page'))) === 1 && $giantShapes['rects'][0]['page'] === 1 && count($giantShapes['rects']) === 2, 'notes longer than a page: the two products are drawn on the first page');
foreach ($giantShapes['rects'] as $r => $rect) {
    $measured("notes longer than a page, product {$r}", $giant, $rect, '250 CM', '260 CM', 250 / 260, [13, 14, 12]);
}
for ($page = 2; $page <= $giant['pages']; $page++) {
    $onPage = array_column(array_filter($giant['texts'], static fn (array $t): bool => $t['page'] === $page), 'text');
    check(in_array(OrderedProductsTicket::CONTINUED, $onPage, true) && in_array('#1', $onPage, true) && in_array('12754-21', $onPage, true) && in_array('2 × DRAPERIE · LĂȚIME: 250 CM · ÎNĂLȚIME: 260 CM', $onPage, true), "notes longer than a page: page {$page} repeats position, code, quantity and dimensions");
}
check(count($texts($giant, OrderedProductsTicket::CONTINUES)) === $giant['pages'] - 1, 'notes longer than a page: every page but the last says it continues');
$noteLines = $squash(implode(' ', array_column(array_filter($giant['texts'], static fn (array $t): bool => abs($t['size'] - 10.5) < 0.01
    && array_filter($giantShapes['frames'], static fn (array $f): bool => $inside(['page' => $t['page'], 'x' => $t['x'], 'y' => $t['y']], $f)) !== []), 'text')));
check(str_contains($noteLines, trim($squash($clientNote))) && str_contains($noteLines, trim($squash($productionNote))), 'notes longer than a page: both notes printed in full, in order, across the pages');

// Only a technical impossibility is still refused (a product name longer than a whole page): explicitly.
$absurd = $trendyol([$variant(1, 'A-1', '250.000', '260.000', 1, ['name' => str_repeat('Denumire imposibil de lungă ', 2500)])]);
check(str_contains((string) ProductionTicketPdf::layoutError($absurd), 'nu încap nici pe o pagină întreagă'), 'a product name longer than a page is reported, not hidden or cut');
try {
    ProductionTicketPdf::render($absurd, $revision);
    check(false, 'no document from a technically impossible snapshot');
} catch (TicketLayoutOverflow) {
    check(true, 'no document from a technically impossible snapshot');
}
foreach (['trendhome', 'outletperdele', 'b2b', 'b2b-project'] as $name) {
    check(ProductionTicketPdf::layoutError(['schema' => 2] + $fixtures[$name]) === null, "{$name}: no page limit (multi-page layout unchanged)");
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
