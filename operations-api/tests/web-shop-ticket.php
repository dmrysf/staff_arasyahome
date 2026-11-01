<?php

declare(strict_types=1);

// Production ticket schema 3: the web shop (Trendhome, OutletPerdele) made-to-measure layout. The shop's width is the
// TOTAL width and "2 buc." divides it into two equal pieces; a standard product is marked and never drawn; nothing is
// guessed. Trendyol (schema 2) and every schema 1 revision render byte for byte as before. No database: snapshots are
// built in memory (TEST data only).

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\Document\ProductionTicketPdf;
use Arasya\Operations\Document\TicketSnapshot;
use Arasya\Operations\Document\WebShopTicket;

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

$line = static fn (int $number, array $overrides = []): array => $overrides + [
    'line' => $number, 'code' => null, 'name' => 'Produs', 'kind' => null, 'variant' => null, 'color' => null,
    'width' => null, 'height' => null, 'unit' => null, 'meters' => null, 'quantity' => 1,
    'notes' => null, 'productionNotes' => null, 'options' => [], 'project' => null,
];
// A made-to-measure line exactly as the web shops send it: metres, the "buc" and "Manopera" options.
$custom = static fn (int $number, string $name, ?string $width, ?string $height, ?string $pieces, ?string $sewing, int $quantity = 1, array $overrides = []): array => $line($number, $overrides + [
    'code' => 'TH-' . (1000 + $number), 'name' => $name, 'variant' => 'Culoare: Alb', 'color' => 'Alb', 'width' => $width, 'height' => $height, 'unit' => 'm', 'quantity' => $quantity,
    'options' => array_values(array_filter([$pieces === null ? null : ['label' => 'buc', 'value' => $pieces], $sewing === null ? null : ['label' => 'Manopera', 'value' => $sewing]])),
]);
$accessory = static fn (int $number, int $quantity): array => $line($number, ['code' => 'ACC-77', 'name' => 'Set Accesoriu Carlige TEST', 'variant' => 'Cantitate: 1 SET(100BUC)', 'quantity' => $quantity]);
$web = static fn (array $lines, string $source = 'trendhome', int $schema = 3, ?string $notes = null): array => [
    'schema' => $schema,
    'order' => ['number' => '84521', 'lookupCode' => 'AB12CD', 'source' => $source],
    'customer' => ['name' => 'TEST Ioana Popescu', 'company' => null, 'contact' => null, 'addressLines' => ['Str. Test 1', 'Cluj-Napoca'], 'phoneMasked' => '07** *** ***'],
    'notes' => $notes,
    'lines' => $lines,
];
$voal = 'Perdea Voal Simplu Alb TEST';
$velvet = 'Draperie Velvet Blackout TEST';
$normal = 'Manopera Rejansa Normal 6cm';
$payload = 'ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ';
$revision = ['number' => 1, 'status' => 'active', 'generatedAt' => '2026-11-02 09:00:00', 'qrPayload' => $payload, 'sourceDisplayName' => null];
$operators = static fn (PdfDocument $document): array => (fn (): array => [...$this->pages, ...($this->current === '' ? [] : [$this->current])])->call($document);
/** `all`: every text joined by spaces, so wrapped sentences read whole. @return array{texts: list<array<string, mixed>>, all: string, pages: int, ops: string} */
$render = static function (array $snapshot) use ($revision, $operators): array {
    $document = ProductionTicketPdf::document($snapshot, $revision);
    $texts = $document->textLog();
    return ['texts' => $texts, 'all' => implode(' ', array_column($texts, 'text')), 'pages' => max(array_column($texts, 'page')), 'ops' => implode("\n", $operators($document))];
};
// Product rectangles are the 2.5-wide black strokes of the drawing.
$rects = static fn (string $ops): int => preg_match_all('/^0\.000 G 2\.50 w [-\d.]+ [-\d.]+ [-\d.]+ [-\d.]+ re S/m', $ops);
$count = static fn (array $rendered, string $text): int => count(array_filter($rendered['texts'], static fn (array $t): bool => $t['text'] === $text));

// ---- T1: which layout a new document gets -----------------------------------------------------------------------
check(TicketSnapshot::schemaFor('trendhome') === 3 && TicketSnapshot::schemaFor('outletperdele') === 3, 'T1: new web shop documents are issued in schema 3');
check(TicketSnapshot::schemaFor('trendyol') === 2 && TicketSnapshot::schemaFor('b2b') === 1, 'T1: Trendyol stays in schema 2, B2B in schema 1');
check(ProductionTicketPdf::webShop($web([])) && ProductionTicketPdf::webShop($web([], 'outletperdele')) && !ProductionTicketPdf::webShop($web([], 'trendhome', 2)) && !ProductionTicketPdf::webShop($web([], 'trendyol')) && !ProductionTicketPdf::webShop($web([], 'b2b')), 'T1: only schema 3 web shop snapshots use the made-to-measure layout');

// ---- T2: the shop's data, read without guessing ----------------------------------------------------------------
check(WebShopTicket::pieces($custom(1, $voal, '5.000', '2.600', '2 buc.', $normal)) === 2 && WebShopTicket::pieces($custom(1, $voal, '5.000', '2.600', '1 buc.', $normal)) === 1, 'T2: "1 buc." and "2 buc." are the piece counts');
check(WebShopTicket::pieces($custom(1, $voal, '5.000', '2.600', null, $normal)) === null, 'T2: no "buc" option: the count is unknown, never 1 by default');
check(WebShopTicket::pieces($line(1, ['options' => [['label' => 'buc', 'value' => '1 buc.'], ['label' => 'Alt', 'value' => '2 buc.']]])) === null, 'T2: two piece counts that disagree are unknown');
check(WebShopTicket::pieceWidth($custom(1, $voal, '5.000', '2.600', '2 buc.', $normal)) === '2,5 m', 'T2: 5 m in 2 pieces is 2,5 m each (the owner\'s rule)');
check(WebShopTicket::pieceWidth($custom(1, $voal, '1.100', '1.350', '2 buc.', $normal)) === '0,55 m' && WebShopTicket::pieceWidth($custom(1, $voal, '1.550', '2.450', '2 buc.', $normal)) === '0,775 m', 'T2: piece widths are exact to the millimetre');
check(WebShopTicket::pieceWidth($custom(1, $voal, '2.335', '2.300', '2 buc.', $normal)) === '≈ 1,168 m', 'T2: a width that does not divide to the millimetre is rounded and marked');
check(WebShopTicket::pieceWidth($custom(1, $voal, '5.000', '2.600', null, $normal)) === null && WebShopTicket::pieceWidth($custom(1, $voal, null, '2.600', '2 buc.', $normal)) === null, 'T2: no piece width without a width and a count');
check(WebShopTicket::sewing($custom(1, $voal, '5.000', '2.600', '1 buc.', $normal)) === ['CONFECȚIE: Rejansa Normal 6cm', false], 'T2: the sewing is the shop\'s heading tape');
check(WebShopTicket::sewing($custom(1, $voal, '5.000', '2.600', '1 buc.', 'Nu (Doar material / Fara cusut)')) === [WebShopTicket::NO_SEWING, true], 'T2: "Nu (Doar material)" is fabric only, nothing sewn');
check(WebShopTicket::sewing($custom(1, $voal, '5.000', '2.600', '1 buc.', null)) === null, 'T2: no sewing option: nothing is printed for it');
check(WebShopTicket::kind($line(1, ['name' => 'Perdea Voal'])) === 'curtain' && WebShopTicket::kind($line(1, ['name' => 'Draperie Velvet'])) === 'drapery' && WebShopTicket::kind($line(1, ['name' => 'Set Accesoriu'])) === 'other', 'T2: the noun comes from the product name the shop sent');
check(WebShopTicket::kind($line(1, ['name' => 'Perdea Voal', 'kind' => 'drapery'])) === 'drapery', 'T2: a source classification wins over the name');
check(WebShopTicket::measured($custom(1, $voal, '5.000', null, '2 buc.', $normal)) && !WebShopTicket::measured($accessory(1, 3)), 'T2: a width or a height makes a made-to-measure line; none makes a standard product');

// ---- T3: one made-to-measure curtain, 5 m in 2 pieces ------------------------------------------------------------
$two = $render($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal)]));
check($two['pages'] === 1 && in_array('1 ×', array_column($two['texts'], 'text'), true) && in_array('PERDEA', array_column($two['texts'], 'text'), true), 'T3: the ordered quantity "1 × PERDEA"');
check(str_contains($two['all'], '2 BUCĂȚI × 2,5 M') && str_contains($two['all'], 'LĂȚIMEA TOTALĂ DE 5 M SE ÎMPARTE ÎN 2 BUCĂȚI EGALE.'), 'T3: the pieces and how the total width is divided');
check(str_contains($two['all'], 'LĂȚIME TOTALĂ: 5 M') && $count($two, '2,5 M') === 2 && $count($two, '1') >= 1 && $count($two, '2') >= 1, 'T3: the drawing has the total width and each piece\'s own width');
check($rects($two['ops']) === 2, 'T3: two piece rectangles are drawn: ' . $rects($two['ops']));
check(str_contains($two['all'], 'CONFECȚIE: Rejansa Normal 6cm') && str_contains($two['all'], 'PERDEA PE MĂSURĂ') && !str_contains($two['all'], 'Manopera'), 'T3: the sewing is printed once, as sewing');
check(!str_contains($two['all'], 'buc: 2 buc.') && !str_contains($two['all'], 'PRODUS STANDARD'), 'T3: the "buc" option is not repeated as raw text');
check(ProductionTicketPdf::layoutError($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal)])) === null, 'T3: a web shop ticket passes the layout check');

// ---- T4: one piece, several products, no sewing, unknown and incomplete -----------------------------------------
$one = $render($web([$custom(1, $voal, '3.000', '2.500', '1 buc.', $normal)], 'outletperdele'));
check(str_contains($one['all'], '1 BUCATĂ ÎNTREAGĂ × 3 M') && str_contains($one['all'], 'NU SE ÎMPARTE') && $rects($one['ops']) === 1 && str_contains($one['all'], 'OUTLETPERDELE.RO'), 'T4: "1 buc." is one whole piece, one rectangle');
$pair = $render($web([$custom(1, $velvet, '4.000', '2.640', '2 buc.', 'Manopera cu Capse', 2)]));
check(str_contains($pair['all'], '2 BUCĂȚI × 2 M') && str_contains($pair['all'], '2 PRODUSE = 4 BUCĂȚI ÎN TOTAL') && str_contains($pair['all'], '2 PRODUSE SEPARATE, IDENTICE.') && in_array('DRAPERIE', array_column($pair['texts'], 'text'), true), 'T4: 2 products of 2 pieces are 4 pieces in total');
$bare = $render($web([$custom(1, $velvet, '10.000', '2.750', '1 buc.', 'Nu (Doar material / Fara cusut)')]));
check(str_contains($bare['all'], WebShopTicket::NO_SEWING) && !str_contains($bare['all'], 'CONFECȚIE:'), 'T4: fabric only is a warning, never a heading tape');
$unknown = $render($web([$custom(1, $voal, '2.335', '2.300', null, $normal)]));
check(str_contains($unknown['all'], WebShopTicket::PIECES_UNKNOWN) && str_contains($unknown['all'], 'Verifică înainte de croire') && $rects($unknown['ops']) === 1, 'T4: an unknown piece count is said, never assumed');
$partial = $render($web([$custom(1, $voal, '4.000', null, '2 buc.', $normal)]));
check(str_contains($partial['all'], 'DIMENSIUNI INCOMPLETE') && $rects($partial['ops']) === 0 && str_contains($partial['all'], '2 BUCĂȚI × 2 M'), 'T4: a missing height prints a warning and no drawing');

// ---- T5: standard products -----------------------------------------------------------------------------------
$standard = $render($web([$accessory(1, 3)], 'outletperdele'));
check(str_contains($standard['all'], WebShopTicket::STANDARD) && str_contains($standard['all'], 'FĂRĂ MĂSURI PERSONALIZATE') && $rects($standard['ops']) === 0, 'T5: a standard product is marked and never drawn');
check(!str_contains($standard['all'], 'LĂȚIME') && !str_contains($standard['all'], 'BUCĂȚI ×') && str_contains($standard['all'], '3 PRODUSE COMANDATE'), 'T5: no measurement or piece is printed for a standard product');

// ---- T6: several positions and pages --------------------------------------------------------------------------
$mixed = $render($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal), $custom(2, $velvet, '3.300', '2.580', '1 buc.', 'Manopera Pliu-pauza-pliu'), $accessory(3, 2)], 'trendhome', 3, 'Livrare împreună TEST'));
check($mixed['pages'] === 1 && str_contains($mixed['all'], WebShopTicket::SHARED_RULE) && str_contains($mixed['all'], 'INSTRUCȚIUNI COMANDĂ') && str_contains($mixed['all'], '3 POZIȚII · 4 PRODUSE COMANDATE'), 'T6: a mixed order has one shared rule, the order instructions and the totals');
check(str_contains($mixed['all'], '2 BUCĂȚI × 2,5 M') && str_contains($mixed['all'], '1 BUCATĂ ÎNTREAGĂ × 3,3 M') && str_contains($mixed['all'], WebShopTicket::STANDARD), 'T6: every position keeps its own pieces or mark');
$onlyStandard = $render($web([$accessory(1, 1), $accessory(2, 4)]));
check(str_contains($onlyStandard['all'], WebShopTicket::STANDARD_PAGE_RULE) && !str_contains($onlyStandard['all'], WebShopTicket::SHARED_RULE), 'T6: a page of standard products only does not explain measurements');
$extra = $render($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal, 1, ['options' => [['label' => 'buc', 'value' => '2 buc.'], ['label' => 'Manopera', 'value' => $normal], ['label' => 'Galerie', 'value' => 'Da TEST']], 'notes' => 'Notă client TEST', 'productionNotes' => 'Tiv 5 cm TEST'])]));
check(str_contains($extra['all'], 'Galerie: Da TEST') && str_contains($extra['all'], 'Notă client TEST') && str_contains($extra['all'], 'Tiv 5 cm TEST'), 'T6: every other option and note is printed');
$many = static fn (int $n): array => $web(array_map(static fn (int $i): array => $i % 5 === 0 ? $accessory($i, 2) : $custom($i, $i % 2 ? $voal : $velvet, number_format(1 + $i * 0.55, 3, '.', ''), '2.450', $i % 3 ? '2 buc.' : '1 buc.', $normal, 1 + $i % 2), range(1, $n)));
foreach ([2, 4, 6, 9, 10, 25, 40] as $n) {
    $r = $render($many($n));
    $pages = $r['pages'];
    $footers = array_filter($r['texts'], static fn (array $t): bool => str_starts_with($t['text'], 'Pagina ') && str_ends_with($t['text'], ' / ' . $pages));
    check(count($footers) === $pages && count(array_filter($r['texts'], static fn (array $t): bool => str_starts_with($t['text'], 'Cod manual: AB12CD'))) === $pages, "T6: {$n} positions: every page has its number and the manual code");
    foreach (range(1, $n) as $i) {
        check(in_array('#' . $i, array_column($r['texts'], 'text'), true), "T6: {$n} positions: position #{$i} is printed");
    }
    check(ProductionTicketPdf::layoutError($many($n)) === null, "T6: {$n} positions pass the layout check");
}
$long = $render($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal, 1, ['productionNotes' => str_repeat('Instrucțiune lungă de producție TEST. ', 160)])]));
check($long['pages'] >= 2 && str_contains($long['all'], WebShopTicket::CONTINUED) && str_contains($long['all'], WebShopTicket::CONTINUES), 'T6: a position longer than a page continues on the next page');

// ---- T7: Trendyol and every historical revision are unchanged -------------------------------------------------
// Golden SHA-256 of schema 2 Trendyol renders made by API 2.52.0 before this change.
$trendyolLine = static fn (int $n, string $w, string $h, int $q, array $o = []): array => $line($n, $o + ['code' => '12754-' . $n, 'name' => 'Draperie catifea TEST ' . $n, 'kind' => 'drapery', 'variant' => $w . 'x' . $h, 'width' => $w . '.000', 'height' => $h . '.000', 'unit' => 'cm', 'quantity' => $q]);
$trendyol = static fn (array $lines, ?string $notes = null): array => ['schema' => 2, 'order' => ['number' => '10000000001', 'lookupCode' => '10000000001', 'source' => 'trendyol'], 'customer' => ['name' => 'TEST Client', 'company' => null, 'contact' => null, 'addressLines' => ['Str. Test 1', 'București'], 'phoneMasked' => null], 'notes' => $notes, 'lines' => $lines];
$trendyolRevision = ['number' => 1, 'status' => 'active', 'generatedAt' => '2026-10-16 09:00:00', 'qrPayload' => $payload, 'sourceDisplayName' => null];
$golden = [
    '18c5e00c0ca3a761ff0925fa84b001c95f700d557197f67f59e003ca2935c1f0' => $trendyol([$trendyolLine(1, '250', '260', 1)]),
    'cb7b3647acfe9c45a326bda30589d3e70d56065ee054b93e547da373561cd166' => $trendyol([$trendyolLine(1, '250', '260', 2)]),
    'cc0222b76c1add29a3fbdf9a68fe72ebb4ea1689bb1c3b573dfb68e2dc6e8cc5' => $trendyol([$trendyolLine(1, '140', '260', 5, ['kind' => 'curtain'])]),
    '130e449bda211cd92cde38510f5e413dde1e617d2c4529b81fce1c8b6e14dd31' => $trendyol([$line(1, ['code' => 'X', 'name' => 'Fara masuri', 'kind' => 'other', 'quantity' => 3])]),
    '9694e1d8825859f7af3e7270c62d62800aa7d1594cb9487967018420c19241c2' => $trendyol(array_map(static fn (int $i): array => $trendyolLine($i, (string) (100 + 20 * $i), '250', $i), range(1, 4)), 'Livrare rapidă TEST'),
    '85a2cc67536aac45ac551aa44b5274037d6cb1051cb2915125723b939ed0058f' => $trendyol(array_map(static fn (int $i): array => $trendyolLine($i, (string) (100 + 10 * $i), '240', 1 + $i % 3, ['notes' => $i % 4 === 0 ? str_repeat('Notă lungă TEST ', 12) : null]), range(1, 12))),
];
foreach ($golden as $hash => $snapshot) {
    check(hash('sha256', ProductionTicketPdf::render($snapshot, $trendyolRevision)) === $hash, 'T7: a schema 2 Trendyol ticket renders byte for byte as before ' . substr($hash, 0, 8));
}
$fixtures = require __DIR__ . '/fixtures/ticket-snapshots.php';
check(hash('sha256', ProductionTicketPdf::render($fixtures['trendhome'], $trendyolRevision)) === hash('sha256', ProductionTicketPdf::render(['schema' => 2] + $fixtures['trendhome'], $trendyolRevision)), 'T7: a web shop revision of schema 1 or 2 keeps the original layout');
$legacy = $render($fixtures['outletperdele']);
check(in_array('linia', array_column($legacy['texts'], 'text'), true) && !str_contains($legacy['all'], 'PE MĂSURĂ'), 'T7: a schema 1 OutletPerdele revision keeps its original wording');
check(hash('sha256', ProductionTicketPdf::render($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal)]), $revision)) === hash('sha256', ProductionTicketPdf::render($web([$custom(1, $voal, '5.000', '2.600', '2 buc.', $normal)]), $revision)), 'T7: the same revision always renders the same bytes');

fwrite(STDOUT, "PASS web shop ticket ({$checks} checks)\n");
