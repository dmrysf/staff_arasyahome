<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\QrCode;
use Arasya\Operations\B2B\Pdf\TrueTypeFont;

/**
 * The schema 2 Trendyol production ticket, laid out for older workers and a black-and-white office printer:
 * one A4 page for an ordinary order of up to nine positions, more pages when the order needs them.
 *
 * A Trendyol line (a "position") is a fixed-size variant bought `quantity` times: it prints as `quantity`
 * separate products of the variant's width × height each, never one product divided. No panel split,
 * meters or physical piece plan is derived from the quantity, the measurements, the variant or an option.
 *
 * Everything is planned before anything is drawn, from the snapshot only (the same revision always renders
 * the same pages):
 *  1. measure: every position card is measured (wrapped text, required drawing) without drawing;
 *  2. paginate and compose: an order of one position gets the large layout when it fits. Otherwise each page
 *     takes at most PER_PAGE positions, spread evenly over the pages the order needs; a page that cannot hold
 *     its share (long names or notes) takes fewer and the rest moves on. For each page, typography tiers go
 *     from the largest to the smallest legible one, and for each tier the grid candidates are tried; the
 *     first that fits is used and its spare height goes to the drawings. A single card taller than a page
 *     continues on the next page, which repeats the product's identity, quantity and dimensions;
 *  3. validate: after drawing, every text must lie on its page and inside its own card.
 * Nothing is shortened or dropped. Every page repeats the order QR, number, revision and "Pagina X / Y".
 * TicketLayoutOverflow is left only for a technical impossibility (a product name longer than a page).
 */
final class OrderedProductsTicket
{
    private const LEFT = 32.0;
    private const RIGHT = PdfDocument::WIDTH - 32.0;
    private const TOP = PdfDocument::HEIGHT - 28.0;
    /** Lowest content line; the footer sits below it. */
    private const BOTTOM = 46.0;
    private const QR = 108.0;
    private const GAP = 8.0;
    /** At most this many positions on one page. */
    public const PER_PAGE = 9;
    private const MUTED = [0.36, 0.38, 0.43];
    private const GOLD = [0.62, 0.43, 0.15];
    /** Ordered-product nouns: [noun, "one single <noun> divided"] (Romanian gender agreement). */
    private const PRODUCT = [
        'curtain' => ['PERDEA', 'O SINGURĂ PERDEA ÎMPĂRȚITĂ'],
        'drapery' => ['DRAPERIE', 'O SINGURĂ DRAPERIE ÎMPĂRȚITĂ'],
        'other' => ['PRODUS', 'UN SINGUR PRODUS ÎMPĂRȚIT'],
    ];
    private const NUMBER_WORDS = [2 => 'DOUĂ', 3 => 'TREI', 4 => 'PATRU', 5 => 'CINCI', 6 => 'ȘASE', 7 => 'ȘAPTE', 8 => 'OPT', 9 => 'NOUĂ', 10 => 'ZECE'];
    /** Printed once per page of cards, instead of a warning in every card. */
    public const SHARED_RULE = 'CANTITATEA REPREZINTĂ PRODUSE SEPARATE, NU ÎMPĂRȚIREA UNUI PRODUS.';
    public const CONTINUED = 'CONTINUARE DE PE PAGINA ANTERIOARĂ';
    public const CONTINUES = 'CONTINUĂ PE PAGINA URMĂTOARE';
    /**
     * Card typography tiers (points), largest first: quantity, product noun, dimension labels, product code,
     * name, supporting text, card padding and the tallest product drawing. The last tier is the smallest
     * legible one.
     */
    private const STYLES = [
        ['qty' => 28.0, 'noun' => 19.0, 'dim' => 16.0, 'code' => 15.0, 'name' => 12.5, 'note' => 11.0, 'pad' => 10.0, 'rect' => 150.0],
        ['qty' => 24.0, 'noun' => 17.0, 'dim' => 15.0, 'code' => 14.0, 'name' => 11.5, 'note' => 10.5, 'pad' => 9.0, 'rect' => 120.0],
        ['qty' => 20.0, 'noun' => 15.0, 'dim' => 13.5, 'code' => 12.5, 'name' => 10.5, 'note' => 10.0, 'pad' => 8.0, 'rect' => 100.0],
        ['qty' => 17.0, 'noun' => 13.0, 'dim' => 12.0, 'code' => 11.5, 'name' => 9.5, 'note' => 9.0, 'pad' => 7.0, 'rect' => 80.0],
        ['qty' => 15.0, 'noun' => 12.0, 'dim' => 11.0, 'code' => 10.5, 'name' => 9.0, 'note' => 8.5, 'pad' => 6.0, 'rect' => 70.0],
    ];
    /** A card split over pages always uses this tier, alone and full width on its page. */
    private const SPLIT_STYLE = 1;
    /** Extra drawing height of the first part of a split card, above the smallest legible drawing. */
    private const SPLIT_DRAWING = 92.0;
    /** Dimension labels never shrink below this size. */
    private const LABEL_MIN = 10.0;
    private const RECT_MIN = 28.0;
    /** The one-position layout: tallest product drawing, and how far it may shrink to keep the page whole. */
    private const HERO_RECT = 160.0;
    private const HERO_RECT_MIN = 60.0;

    /**
     * @param array<string, mixed> $snapshot a schema 2 Trendyol snapshot
     * @param array<string, mixed> $revision
     * @throws TicketLayoutOverflow only for a technical impossibility; never because of the page count
     */
    public static function document(array $snapshot, array $revision): PdfDocument
    {
        $fonts = dirname(__DIR__) . '/B2B/Pdf/fonts/';
        $pdf = new PdfDocument([
            'R' => new TrueTypeFont($fonts . 'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed'),
            'B' => new TrueTypeFont($fonts . 'DejaVuSansCondensed-Bold.ttf', 'DejaVuSansCondensed-Bold'),
        ], 'Document de producție ' . $snapshot['order']['number'] . ' · Revizia ' . $revision['number']);
        $lines = ProductionTicketPdf::sorted($snapshot['lines'] ?? []);
        $width = self::RIGHT - self::LEFT;

        // Pass 1 and 2: measure, paginate and compose. Nothing is drawn before every page is planned.
        $customer = self::customerRows($pdf, $snapshot);
        $rule = min(self::TOP - self::QR - 18, self::TOP - 100 - (count($customer) - 1) * 13.5 - 9);
        $heading = $rule - 18;
        $notes = is_string($snapshot['notes'] ?? null) && trim($snapshot['notes']) !== '' ? $pdf->wrap($snapshot['notes'], $width - 24, 'B', 11) : [];
        $notesHeight = $notes === [] ? 0.0 : 24 + 14 * count($notes);
        // Content top of a page: below the heading (and its page line when there are several pages), and on
        // page 1 below the order instructions.
        $content = static fn (bool $first, bool $several): float => $heading - 9 - ($several ? 18 : 0) - ($first && $notes !== [] ? $notesHeight + 8 : 0);
        $pages = self::paginate($pdf, $lines, $content, false);
        if (count($pages) > 1) {
            $pages = self::paginate($pdf, $lines, $content, true);
        }
        $total = count($pages);

        // Draw.
        $ordered = array_sum(array_map(static fn (array $line): int => (int) $line['quantity'], $lines));
        $title = 'PRODUSE DE EXECUTAT · ' . self::count(count($lines), 'POZIȚIE', 'POZIȚII', 'DE') . ' · '
            . self::count($ordered, 'PRODUS COMANDAT', 'PRODUSE COMANDATE', 'DE') . ProductionTicketPdf::totalMeters($lines);
        $titleSize = 12.5;
        while ($titleSize > 9 && $pdf->font('B')->textWidth($title, $titleSize) > $width) {
            $titleSize -= 0.5;
        }
        $regions = [];
        foreach ($pages as $p => $page) {
            $number = $p + 1;
            $top = $content($number === 1, $total > 1);
            $pdf->addPage();
            self::header($pdf, $snapshot, $revision, $customer, $rule, $number, $total);
            $pdf->text(self::LEFT, $heading, $title, 'B', $titleSize);
            if ($total > 1) {
                $pdf->text(self::LEFT, $heading - 18, self::pageLine($lines, $page, $number, $total), 'B', 10.5);
            }
            if ($number === 1 && $notes !== []) {
                $box = $heading - 9 - ($total > 1 ? 18 : 0);
                $pdf->strokeRect(self::LEFT, $box - $notesHeight, $width, $notesHeight, 1.4, 0.0);
                $pdf->text(self::LEFT + 12, $box - 12, 'INSTRUCȚIUNI COMANDĂ', 'B', 8.5);
                foreach ($notes as $i => $text) {
                    $pdf->text(self::LEFT + 12, $box - 28 - 14 * $i, $text, 'B', 11);
                }
            }
            if ($page['hero']) {
                $from = count($pdf->textLog());
                $height = self::hero($pdf, $lines[0], $top - 12, $page['rect'], null);
                self::hero($pdf, $lines[0], $top - 12, $page['rect'], $height);
                $regions[] = [$number, self::LEFT, $top - $height, $width, $height, $from, count($pdf->textLog())];
                continue;
            }
            // One shared rule per page, not a warning repeated in every card.
            $pdf->strokeRect(self::LEFT, $top - 28, $width, 28, 2.0, 0.0);
            self::warningSign($pdf, self::LEFT + 8, $top - 25, 22.0);
            $ruleSize = 11.5;
            while ($ruleSize > 9 && $pdf->font('B')->textWidth(self::SHARED_RULE, $ruleSize) > $width - 48) {
                $ruleSize -= 0.5;
            }
            $pdf->text(self::LEFT + 40, $top - 18, self::SHARED_RULE, 'B', $ruleSize);
            foreach ($page['cards'] as $i => [$index, $part]) {
                $box = $page['boxes'][$i];
                $from = count($pdf->textLog());
                self::card($pdf, $lines[$index], $page['style'], $box[2], $box, $part);
                $regions[] = [$number, ...$box, $from, count($pdf->textLog())];
            }
        }

        // Pass 3: validate before the document is handed out.
        self::validate($pdf, $regions, $total);
        return $pdf;
    }

    /**
     * Splits the order into planned pages. One position: the large layout when it fits one page. Otherwise
     * each page takes its even share of the remaining positions (at most PER_PAGE), fewer when they do not
     * fit, and a card taller than a whole page is split into parts that each fill a page alone.
     *
     * @param list<array<string, mixed>> $lines
     * @param callable(bool, bool): float $content
     * @return list<array{hero: bool, rect?: float, style?: array<string, float>, boxes?: list<array{0: float, 1: float, 2: float, 3: float}>, cards?: list<array{0: int, 1: array<string, mixed>|null}>}>
     */
    private static function paginate(PdfDocument $pdf, array $lines, callable $content, bool $several): array
    {
        $count = count($lines);
        if ($count === 1 && !$several) {
            $top = $content(true, false) - 12;
            $available = $top - self::BOTTOM + 12;
            $rect = self::HERO_RECT;
            $height = self::hero($pdf, $lines[0], $top, $rect, null);
            if ($height > $available) {
                $rect = max(self::HERO_RECT_MIN, $rect - ($height - $available));
                $height = self::hero($pdf, $lines[0], $top, $rect, null);
            }
            if ($height <= $available) {
                return [['hero' => true, 'rect' => $rect]];
            }
        }
        $pages = [];
        $next = 0;
        $split = null;
        while ($next < $count || $split !== null) {
            // Cards start below the page's shared rule.
            $top = $content($pages === [], $several) - 36;
            if ($split === null) {
                $remaining = $count - $next;
                $share = (int) ceil($remaining / (int) ceil($remaining / self::PER_PAGE));
                for ($take = $share; $take >= 1; $take--) {
                    $grid = self::grid($pdf, array_slice($lines, $next, $take), $top);
                    if ($grid !== null) {
                        $grid['cards'] = array_map(static fn (int $i): array => [$i, null], range($next, $next + $take - 1));
                        $pages[] = $grid;
                        $next += $take;
                        continue 2;
                    }
                }
                // One position taller than a page: it continues on the following pages.
                $split = [$next, 0];
            }
            [$index, $from] = $split;
            $part = self::part($pdf, $lines[$index], $from, $top - self::BOTTOM);
            $style = self::STYLES[self::SPLIT_STYLE];
            $pages[] = ['hero' => false, 'style' => $style, 'boxes' => [[self::LEFT, self::BOTTOM, self::RIGHT - self::LEFT, $top - self::BOTTOM]], 'cards' => [[$index, $part]]];
            $split = $part['more'] ? [$index, $part['to']] : null;
            if (!$part['more']) {
                $next = $index + 1;
            }
        }
        return $pages;
    }

    /**
     * The best grid for some positions on one page: from the largest tier allowed for their number down to
     * the smallest legible one, every grid candidate is measured; the first that fits wins and its rows share
     * the spare height. Null when they do not fit.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{hero: false, style: array<string, float>, boxes: list<array{0: float, 1: float, 2: float, 3: float}>}|null
     */
    private static function grid(PdfDocument $pdf, array $lines, float $top): ?array
    {
        $count = count($lines);
        $available = $top - self::BOTTOM;
        $first = $count <= 2 ? 0 : ($count <= 4 ? 1 : ($count <= 6 ? 2 : 3));
        foreach (array_slice(self::STYLES, $first) as $style) {
            foreach (self::grids($count) as $columns) {
                $cardWidth = (self::RIGHT - self::LEFT - self::GAP * ($columns - 1)) / $columns;
                $rows = [];
                foreach (array_chunk($lines, $columns) as $row) {
                    $rows[] = max(array_map(static fn (array $line): float => self::card($pdf, $line, $style, $cardWidth, null), $row));
                }
                $needed = array_sum($rows) + self::GAP * (count($rows) - 1);
                if ($needed > $available) {
                    continue;
                }
                $spare = ($available - $needed) / count($rows);
                $boxes = [];
                $y = $top;
                foreach (array_chunk(array_keys($lines), $columns) as $r => $row) {
                    $height = $rows[$r] + $spare;
                    foreach ($row as $c => $_) {
                        $boxes[] = [self::LEFT + $c * ($cardWidth + self::GAP), $y - $height, $cardWidth, $height];
                    }
                    $y -= $height + self::GAP;
                }
                return ['hero' => false, 'style' => $style, 'boxes' => $boxes];
            }
        }
        return null;
    }

    /**
     * The next part of a card that is taller than a page, from text row `$from`: as many rows as fit a whole
     * page alone. The first part carries the drawing; every later part repeats the product's identity,
     * quantity and dimensions.
     *
     * @param array<string, mixed> $line
     * @return array{from: int, to: int, first: bool, more: bool}
     */
    private static function part(PdfDocument $pdf, array $line, int $from, float $available): array
    {
        $style = self::STYLES[self::SPLIT_STYLE];
        $width = self::RIGHT - self::LEFT;
        $rows = count(self::cardRows($pdf, $line, $style, $width - 2 * $style['pad']));
        $first = $from === 0;
        $fits = static fn (int $to): bool => self::card($pdf, $line, $style, $width, null, ['from' => $from, 'to' => $to, 'first' => $first, 'more' => $to < $rows]) <= $available;
        // The largest number of rows that fits (binary search: the height grows with the rows).
        [$low, $high] = [$first ? $from : $from + 1, $rows];
        if (!$fits($low)) {
            throw new TicketLayoutOverflow('Documentul de producție nu poate fi așezat: datele poziției #' . $line['line'] . ' (cod, denumire și desen) nu încap nici pe o pagină întreagă. Verifică datele produsului.');
        }
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $fits($middle) ? $low = $middle : $high = $middle - 1;
        }
        return ['from' => $from, 'to' => $low, 'first' => $first, 'more' => $low < $rows];
    }

    /** "Pagina 2 / 5 · pozițiile #10–#18 · continuă pe pagina următoare". @param list<array<string, mixed>> $lines @param array<string, mixed> $page */
    private static function pageLine(array $lines, array $page, int $number, int $total): string
    {
        $positions = $page['hero'] ? [$lines[0]['line']] : array_map(static fn (array $card): int => (int) $lines[$card[0]]['line'], $page['cards']);
        $continued = !$page['hero'] && count($page['cards']) === 1 && $page['cards'][0][1] !== null && !$page['cards'][0][1]['first'];
        $range = count($positions) === 1 ? 'POZIȚIA #' . $positions[0] . ($continued ? ' (CONTINUARE)' : '') : 'POZIȚIILE #' . $positions[0] . '–#' . end($positions);
        return 'PAGINA ' . $number . ' / ' . $total . ' · ' . $range . ($number < $total ? ' · CONTINUĂ PE PAGINA ' . ($number + 1) : ' · ULTIMA PAGINĂ');
    }

    /** Grid column counts to try, best first, for a number of positions. @return list<int> */
    private static function grids(int $count): array
    {
        return match (true) {
            $count <= 3 => [1, 2],
            $count === 4 => [2, 1],
            $count <= 6 => [2, 3],
            default => [3, 2],
        };
    }

    /**
     * Order identification, compact and on every page: source stamp, order number, revision mark, canonical
     * QR, manual code, page number and the delivery details beside them.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $revision
     * @param list<array{0: string, 1: string, 2: float}> $customer
     */
    private static function header(PdfDocument $pdf, array $snapshot, array $revision, array $customer, float $rule, int $page, int $total): void
    {
        $top = self::TOP;
        $right = self::RIGHT;
        $bold = $pdf->font('B');
        $preview = ($revision['preview'] ?? false) === true;
        $active = $revision['status'] === 'active';
        if ($revision['qrPayload'] !== null) {
            $pdf->qr($right - self::QR, $top - self::QR + 4, self::QR, QrCode::matrix((string) $revision['qrPayload'], 'Q'));
        } else {
            $pdf->text($right, $top - 20, $preview ? 'PREVIZUALIZARE — fără cod QR' : 'Cod QR indisponibil — folosește codul manual.', 'B', 8, 'right');
        }
        $stamp = SourceStamp::label((string) $snapshot['order']['source'], $revision['sourceDisplayName'] ?? null);
        $stampWidth = $bold->textWidth($stamp, 14) + 20;
        $pdf->fillRect(self::LEFT, $top - 22, $stampWidth, 24, 0.1);
        $pdf->text(self::LEFT + 10, $top - 15, $stamp, 'B', 14, 'left', [1, 1, 1]);
        $pdf->text(self::LEFT + $stampWidth + 10, $top - 15, 'ARASYA HOME · DOCUMENT DE PRODUCȚIE', 'B', 8.5, 'left', self::GOLD);
        $pdf->text(self::LEFT, $top - 40, 'COMANDA', 'B', 7.5, 'left', self::MUTED);
        $boxWidth = 128.0;
        $boxX = $right - self::QR - 12 - $boxWidth;
        $number = '#' . ltrim((string) $snapshot['order']['number'], '#');
        $numberSize = 28.0;
        while ($numberSize > 12 && $bold->textWidth($number, $numberSize) > $boxX - self::LEFT - 12) {
            $numberSize -= 1;
        }
        $pdf->text(self::LEFT, $top - 68, $number, 'B', $numberSize);
        $boxTop = $top - 30;
        $pdf->strokeRect($boxX, $boxTop - 50, $boxWidth, 50, 2.4, 0.0);
        $mark = 'REVIZIA ' . $revision['number'];
        $pdf->text($boxX + $boxWidth / 2 - $bold->textWidth($mark, 19) / 2, $boxTop - 24, $mark, 'B', 19);
        $state = $preview ? 'PREVIZUALIZARE — NU SE TIPĂREȘTE' : ($active ? 'DOCUMENT ACTIV' : ($revision['status'] === 'revoked' ? 'DOCUMENT ANULAT — NU SE FOLOSEȘTE' : 'DOCUMENT ÎNLOCUIT — NU SE FOLOSEȘTE'));
        $stateSize = $active && !$preview ? 9.0 : 6.5;
        $pdf->text($boxX + $boxWidth / 2 - $bold->textWidth($state, $stateSize) / 2, $boxTop - 42, $state, 'B', $stateSize);
        $generated = (new \DateTimeImmutable((string) $revision['generatedAt'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i');
        $pdf->text(self::LEFT, $top - 84, 'Generat: ' . $generated . '   ·   Pagina ' . $page . ' / ' . $total, 'R', 8.5, 'left', self::MUTED);
        $pdf->text($right, $top - self::QR - 8, 'Cod manual: ' . (string) $snapshot['order']['lookupCode'], 'B', 10, 'right');
        foreach ($customer as $i => [$text, $font, $size]) {
            $pdf->text(self::LEFT, $top - 100 - 13.5 * $i, $text, $font, $size);
        }
        $pdf->line(self::LEFT, $rule, $right, $rule, 1.6, 0.0);
        $pdf->line(self::LEFT, 38, $right, 38, 0.4, 0.75);
        $pdf->text(self::LEFT, 26, 'Arasya Home · ' . $number . ' · ' . $mark . ' · Document de atelier — nu conține date financiare.', 'R', 7.5, 'left', self::MUTED);
        $pdf->text($right, 26, 'Pagina ' . $page . ' / ' . $total, 'B', 8, 'right');
    }

    /** Delivery details, complete and wrapped beside the order number. @param array<string, mixed> $snapshot @return list<array{0: string, 1: string, 2: float}> */
    private static function customerRows(PdfDocument $pdf, array $snapshot): array
    {
        $width = self::RIGHT - 150 - self::LEFT;
        $customer = $snapshot['customer'] ?? null;
        if ($customer === null) {
            return [['Date de livrare indisponibile la sursă.', 'R', 10.0]];
        }
        $rows = [];
        $add = static function (string $text, string $font, float $size) use ($pdf, $width, &$rows): void {
            foreach ($pdf->wrap($text, $width, $font, $size) as $part) {
                $rows[] = [$part, $font, $size];
            }
        };
        $first = implode('  ·  ', array_filter([$customer['name'] ?? null, ($customer['phoneMasked'] ?? null) === null ? null : 'Telefon: ' . $customer['phoneMasked']]));
        if ($first !== '') {
            $add($first, 'B', 10.5);
        }
        if (($customer['company'] ?? null) !== null) {
            $add((string) $customer['company'], 'R', 9.5);
        }
        if (($customer['contact'] ?? null) !== null) {
            $add('Persoană de contact: ' . $customer['contact'], 'R', 9.5);
        }
        $add($customer['addressLines'] === [] ? 'Adresă de livrare indisponibilă la sursă.' : 'Livrare: ' . implode(', ', $customer['addressLines']), 'R', 9.5);
        return $rows;
    }

    /**
     * One position card: badge and code, the complete name, the quantity ("2 × DRAPERIE", "2 PRODUSE
     * SEPARATE"), supporting text, then the drawing with its width and height arrows. A wide card puts the
     * drawing beside the text, a narrow one below it. Returns the height it needs (`$box` null: measure only)
     * or draws into `$box` [x, bottom, width, height], giving the drawing all remaining height.
     *
     * A `$part` is one page of a card taller than a page (always full width, drawing below the text): rows
     * `from`..`to` of its supporting text. The first part has the quantity and the drawing; a later part
     * says it continues and repeats the quantity and dimensions in one line. `more` adds the line saying it
     * continues on the next page.
     *
     * @param array<string, mixed> $line
     * @param array<string, float> $s
     * @param array{0: float, 1: float, 2: float, 3: float}|null $box
     * @param array{from: int, to: int, first: bool, more: bool}|null $part
     */
    private static function card(PdfDocument $pdf, array $line, array $s, float $width, ?array $box, ?array $part = null): float
    {
        $draw = $box !== null;
        $bold = $pdf->font('B');
        $pad = $s['pad'];
        $inner = $width - 2 * $pad;
        $wide = $part === null && $width >= 360;
        $textWidth = $wide ? round($inner * 0.5, 2) : $inner;
        $drawWidth = $wide ? $inner - $textWidth - 14 : $inner;
        $first = $part === null || $part['first'];
        $x = $box[0] ?? 0.0;
        $top = $draw ? $box[1] + $box[3] : 0.0;
        $tx = $x + $pad;
        $cur = $top - $pad;
        $quantity = (int) $line['quantity'];
        $noun = (self::PRODUCT[$line['kind'] ?? ''] ?? self::PRODUCT['other'])[0];

        // Position badge (white on black) and the product code, never shortened.
        $badge = '#' . $line['line'];
        $badgeWidth = $bold->textWidth($badge, $s['code']) + 10;
        $badgeHeight = $s['code'] + 6;
        $codes = $pdf->wrap((string) ($line['code'] ?? '—'), $textWidth - $badgeWidth - 8, 'B', $s['code']);
        $base = $cur - $badgeHeight + 3 + 0.13 * $s['code'];
        if ($draw) {
            $pdf->fillRect($tx, $cur - $badgeHeight, $badgeWidth, $badgeHeight, 0.0);
            $pdf->text($tx + 5, $base, $badge, 'B', $s['code'], 'left', [1, 1, 1]);
            foreach ($codes as $i => $code) {
                $pdf->text($tx + $badgeWidth + 8, $base - $i * ($s['code'] + 2), $code, 'B', $s['code']);
            }
        }
        $cur -= $badgeHeight + (count($codes) - 1) * ($s['code'] + 2) + 5;
        $head = [];
        if (!$first) {
            $head[] = [self::CONTINUED, 'B', $s['name'] + 1];
        }
        // Name, colour and variant: complete, wrapped, never shortened.
        foreach ($pdf->wrap(ProductionTicketPdf::nameText($line), $textWidth, 'R', $s['name']) as $text) {
            $head[] = [$text, 'R', $s['name']];
        }
        if (!$first) {
            // The identity, quantity and dimensions again, so a continuation page is never anonymous.
            $identity = $quantity . ' × ' . $noun . ' · ' . (ProductionTicketPdf::hasSchematic($line)
                ? 'LĂȚIME: ' . mb_strtoupper((string) TicketSnapshot::measure((string) $line['width'], $line['unit'])) . ' · ÎNĂLȚIME: ' . mb_strtoupper((string) TicketSnapshot::measure((string) $line['height'], $line['unit']))
                : 'DIMENSIUNI NECONFIRMATE');
            foreach ($pdf->wrap($identity, $textWidth, 'B', $s['dim']) as $text) {
                $head[] = [$text, 'B', $s['dim']];
            }
        }
        foreach ($head as [$text, $font, $size]) {
            if ($draw) {
                $pdf->text($tx, $cur - 0.8 * $size, $text, $font, $size);
            }
            $cur -= $size + 2.5;
        }
        $cur -= 3;
        if ($first) {
            // Commercial quantity: the largest figure of the card.
            $figure = $quantity . ' ×';
            $figureWidth = $bold->textWidth($figure, $s['qty']);
            $oneRow = $figureWidth + 6 + $bold->textWidth($noun, $s['noun']) <= $textWidth;
            if ($draw) {
                $pdf->text($tx, $cur - 0.78 * $s['qty'], $figure, 'B', $s['qty']);
                $oneRow
                    ? $pdf->text($tx + $figureWidth + 6, $cur - 0.78 * $s['qty'], $noun, 'B', $s['noun'])
                    : $pdf->text($tx, $cur - $s['qty'] - 2 - 0.8 * $s['noun'], $noun, 'B', $s['noun']);
            }
            $cur -= $s['qty'] + 3 + ($oneRow ? 0 : $s['noun'] + 2);
        }
        $split = $part !== null && $first;
        if ($split) {
            // A card split over pages shows its drawing right after the quantity, at a working size, and the
            // long text after it.
            $sketch = self::cardSketch($pdf, $line, $s, $drawWidth, null);
            if (!is_finite($sketch)) {
                return INF;
            }
            $sketch += self::SPLIT_DRAWING;
            if ($draw) {
                self::cardSketch($pdf, $line, $s, $drawWidth, [$tx, $cur - 6, $sketch]);
            }
            $cur -= 6 + $sketch + 8;
        }
        $rows = self::cardRows($pdf, $line, $s, $textWidth);
        if ($part !== null) {
            $rows = array_slice($rows, $part['from'], $part['to'] - $part['from']);
            if ($part['more']) {
                $rows[] = [self::CONTINUES, 'B', $s['name'] + 1];
            }
        }
        foreach ($rows as [$text, $font, $size]) {
            if ($draw) {
                $pdf->text($tx, $cur - 0.8 * $size, $text, $font, $size);
            }
            $cur -= $size + 2.5;
        }
        $textHeight = ($top - $pad) - $cur;
        $sketch = $first && !$split ? self::cardSketch($pdf, $line, $s, $drawWidth, null) : 0.0;
        if (!is_finite($sketch)) {
            return INF;
        }
        if (!$draw) {
            return ($wide ? max($textHeight, $sketch) : $textHeight + ($first && !$split ? 6 + $sketch : 0)) + 2 * $pad;
        }
        $pdf->strokeRect($x, $box[1], $width, $box[3], 1.4, 0.0);
        if ($first && !$split) {
            $wide
                ? self::cardSketch($pdf, $line, $s, $drawWidth, [$tx + $textWidth + 14, $top - $pad, $box[3] - 2 * $pad])
                : self::cardSketch($pdf, $line, $s, $drawWidth, [$tx, $cur - 6, $cur - 6 - $box[1] - $pad]);
        }
        return $box[3];
    }

    /**
     * The supporting text of a card, wrapped to `$width`: "N PRODUSE SEPARATE", meters, source options and
     * notes, complete.
     *
     * @param array<string, mixed> $line
     * @param array<string, float> $s
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private static function cardRows(PdfDocument $pdf, array $line, array $s, float $width): array
    {
        $quantity = (int) $line['quantity'];
        $rows = [];
        if ($quantity > 1) {
            foreach ($pdf->wrap(self::count($quantity, 'PRODUS', 'PRODUSE SEPARATE', 'DE'), $width, 'B', $s['name'] + 1) as $text) {
                $rows[] = [$text, 'B', $s['name'] + 1];
            }
        }
        if ($line['meters'] !== null) {
            foreach ($pdf->wrap('METRI (LINIE): ' . TicketSnapshot::measure($line['meters'], 'm'), $width, 'R', $s['note']) as $text) {
                $rows[] = [$text, 'R', $s['note']];
            }
        }
        if ($line['options'] !== []) {
            // Printed as source text only; a Trendyol option never becomes a panel split.
            foreach ($pdf->wrap(TicketSnapshot::optionsText($line['options']) ?? '', $width, 'B', $s['note']) as $text) {
                $rows[] = [$text, 'B', $s['note']];
            }
        }
        foreach (['notes' => 'NOTE', 'productionNotes' => 'NOTE DE PRODUCȚIE'] as $key => $caption) {
            if ($line[$key] !== null) {
                $rows[] = [$caption, 'B', max(8.0, $s['note'] - 1)];
                $font = $key === 'productionNotes' ? 'B' : 'R';
                foreach ($pdf->wrap($line[$key], $width, $font, $s['note']) as $text) {
                    $rows[] = [$text, $font, $s['note']];
                }
            }
        }
        return $rows;
    }

    /**
     * The drawing of a card, to scale from the printed width × height: the product with its own horizontal
     * width arrow ("LĂȚIME: 250 CM") above and vertical height arrow ("ÎNĂLȚIME:" / "260 CM") on its left, the
     * arrow tips exactly on its edges. Two products are drawn side by side when both stay clear ("PRODUS 1",
     * "PRODUS 2"); otherwise one product stands for all ("FIECARE PRODUS"). Without both measurements the
     * fields print "—" under "DIMENSIUNI NECONFIRMATE": nothing is guessed. Returns the height it needs
     * (`$area` null) or draws into `$area` [x, top, height]; INF when the width cannot hold it legibly.
     *
     * @param array<string, mixed> $line
     * @param array<string, float> $s
     * @param array{0: float, 1: float, 2: float}|null $area
     */
    private static function cardSketch(PdfDocument $pdf, array $line, array $s, float $width, ?array $area): float
    {
        $draw = $area !== null;
        $bold = $pdf->font('B');
        if (!ProductionTicketPdf::hasSchematic($line)) {
            $sign = min(22.0, $s['dim'] + 8);
            $textWidth = $width - $sign - 22;
            // [text, size, space below]
            $rows = [];
            $title = $s['dim'];
            while ($title > self::LABEL_MIN && $bold->textWidth('DIMENSIUNI NECONFIRMATE', $title) > $textWidth) {
                $title -= 0.5;
            }
            foreach ($pdf->wrap('DIMENSIUNI NECONFIRMATE', $textWidth, 'B', $title) as $text) {
                $rows[] = [$text, $title, 3.0];
            }
            $rows[count($rows) - 1][2] = 8.0;
            foreach (['LĂȚIME: —', 'ÎNĂLȚIME: —'] as $text) {
                $rows[] = [$text, $s['dim'] - 1, 5.0];
            }
            $rows[count($rows) - 1][2] = 8.0;
            foreach ($pdf->wrap('Nu se ghicesc. Verifică înainte de croire.', $textWidth, 'B', $s['note']) as $text) {
                $rows[] = [$text, $s['note'], 3.0];
            }
            $height = 16 + array_sum(array_map(static fn (array $row): float => $row[1] + $row[2], $rows));
            if ($bold->textWidth('ÎNĂLȚIME: —', $s['dim'] - 1) > $textWidth) {
                return INF;
            }
            if ($draw) {
                [$x, $top] = $area;
                $pdf->strokeRect($x, $top - $height, $width, $height, 2.0, 0.0);
                self::warningSign($pdf, $x + 8, $top - $height / 2 - $sign * 0.45, $sign);
                $cur = $top - 8;
                foreach ($rows as [$text, $size, $after]) {
                    $pdf->text($x + $sign + 16, $cur - 0.8 * $size, $text, 'B', $size);
                    $cur -= $size + $after;
                }
            }
            return $height;
        }
        $quantity = max(1, (int) $line['quantity']);
        $unit = $line['unit'];
        $w = ProductionTicketPdf::units((string) $line['width']);
        $h = ProductionTicketPdf::units((string) $line['height']);
        $widthLabel = 'LĂȚIME: ' . mb_strtoupper((string) TicketSnapshot::measure((string) $line['width'], $unit));
        $heightValue = mb_strtoupper((string) TicketSnapshot::measure((string) $line['height'], $unit));
        $head = min(9.0, $s['dim'] * 0.7);
        foreach ($quantity === 2 ? [2, 1] : [1] as $drawn) {
            $gap = 18.0;
            $slot = ($width - $gap * ($drawn - 1)) / $drawn;
            $widthSize = $s['dim'];
            while ($widthSize > self::LABEL_MIN && $bold->textWidth($widthLabel, $widthSize) > $slot) {
                $widthSize -= 0.5;
            }
            $column = static fn (float $size): float => max($bold->textWidth('ÎNĂLȚIME:', $size - 2), $bold->textWidth($heightValue, $size));
            $valueSize = $s['dim'];
            while ($valueSize > self::LABEL_MIN && $column($valueSize) > $slot * 0.45) {
                $valueSize -= 0.5;
            }
            $captionSize = $valueSize - 2;
            $zone = $slot - $column($valueSize) - 22;
            if ($bold->textWidth($widthLabel, $widthSize) > $slot || $column($valueSize) > $slot * 0.45 || $zone < ($drawn === 2 ? 48.0 : 28.0)) {
                continue;
            }
            $label = $drawn === 2 ? 'PRODUS' : ($quantity > 1 ? 'FIECARE PRODUS' : null);
            $labelSize = max(self::LABEL_MIN, $s['dim'] - 1);
            $labelHeight = $label === null ? 0.0 : $labelSize + 4;
            $chrome = $labelHeight + $widthSize + 3 + 12;
            $labels = $captionSize + $valueSize + 5;
            $minimum = max(self::RECT_MIN, $labels);
            if (!$draw) {
                return $chrome + $minimum + 3;
            }
            [$x, $top, $height] = $area;
            $scale = min($zone / $w, max($minimum, min($s['rect'], $height - $chrome - 3)) / $h);
            $rw = max(24.0, $w * $scale);
            $rh = max(24.0, $h * $scale);
            $y = $top - max(0.0, ($height - ($chrome + max($rh, $labels) + 3)) / 2);
            $col = $column($valueSize);
            for ($i = 0; $i < $drawn; $i++) {
                $sx = $x + $i * ($slot + $gap);
                $rx = $sx + ($slot - $col - 22 - $rw) / 2 + $col + 22;
                $centre = static fn (string $text, float $size): float => max($sx, min($rx + $rw / 2 - $bold->textWidth($text, $size) / 2, $sx + $slot - $bold->textWidth($text, $size)));
                $cur = $y;
                if ($label !== null) {
                    $text = $drawn === 2 ? 'PRODUS ' . ($i + 1) : $label;
                    $pdf->text($centre($text, $labelSize), $cur - 0.8 * $labelSize, $text, 'B', $labelSize);
                    $cur -= $labelHeight;
                }
                $pdf->text($centre($widthLabel, $widthSize), $cur - 0.78 * $widthSize, $widthLabel, 'B', $widthSize);
                $dimY = $cur - $widthSize - 3 - 4;
                $rectTop = $dimY - 8;
                $ry = $rectTop - $rh;
                // Width: extension lines up from both vertical edges, arrow tips exactly on them.
                $pdf->line($rx, $rectTop + 3, $rx, $dimY + 5, 0.8, 0.0);
                $pdf->line($rx + $rw, $rectTop + 3, $rx + $rw, $dimY + 5, 0.8, 0.0);
                self::dimension($pdf, $rx, $dimY, $rx + $rw, $dimY, 1.8, $head, $head * 0.45);
                // Height: extension lines left from both horizontal edges, arrow tips exactly on them.
                $dimX = $rx - 10;
                $pdf->line($rx - 3, $ry, $dimX - 5, $ry, 0.8, 0.0);
                $pdf->line($rx - 3, $ry + $rh, $dimX - 5, $ry + $rh, 0.8, 0.0);
                self::dimension($pdf, $dimX, $ry, $dimX, $ry + $rh, 1.8, $head, $head * 0.45);
                // The height label is centred on the product, never above its top edge.
                $caption = min($ry + $rh / 2 + $labels / 2, $rectTop) - 0.78 * $captionSize;
                $pdf->text($dimX - 8, $caption, 'ÎNĂLȚIME:', 'B', $captionSize, 'right');
                $pdf->text($dimX - 8, $caption - $valueSize - 2, $heightValue, 'B', $valueSize, 'right');
                $pdf->strokeRect($rx, $ry, $rw, $rh, 2.5, 0.0);
                $numberSize = min($s['qty'] * 0.8, $rh * 0.55, $rw * 0.6);
                if ($drawn === 2 && $numberSize >= 10) {
                    $number = (string) ($i + 1);
                    $pdf->text($rx + $rw / 2 - $bold->textWidth($number, $numberSize) / 2, $ry + $rh / 2 - 0.36 * $numberSize, $number, 'B', $numberSize);
                }
            }
            return $chrome + $minimum + 3;
        }
        return INF;
    }

    /**
     * The one-position layout, using the page: code and name; the ordered quantity in the largest type
     * ("2 × DRAPERIE"); the dimensions of EACH product; a large drawing with real dimension arrows on every
     * drawn product; and a framed rule saying the products are separate. Returns the block height; with
     * `$height` null nothing is drawn.
     *
     * @param array<string, mixed> $line
     */
    private static function hero(PdfDocument $pdf, array $line, float $y, float $rect, ?float $height): float
    {
        $draw = $height !== null;
        $x = self::LEFT;
        $width = self::RIGHT - self::LEFT;
        $right = self::RIGHT;
        $cx = $x + 62;
        $contentRight = $right - 12;
        $textWidth = $contentRight - $cx;
        $bold = $pdf->font('B');
        $quantity = (int) $line['quantity'];
        [$noun, $single] = self::PRODUCT[$line['kind'] ?? ''] ?? self::PRODUCT['other'];
        $unit = $line['unit'];
        $sketch = ProductionTicketPdf::hasSchematic($line);
        $each = $sketch ? mb_strtoupper(TicketSnapshot::measure($line['width'], null) . ' × ' . TicketSnapshot::measure($line['height'], $unit)) : null;
        if ($draw) {
            $pdf->strokeRect($x, $y - $height + 12, $width, $height, 1.4, 0.0);
            $pdf->fillRect($x, $y - $height + 12, 50, $height, 0.92);
            $label = '#' . $line['line'];
            $pdf->text($x + 25 - $bold->textWidth($label, 18) / 2, $y - 20, $label, 'B', 18);
            // Black only: no grey or gold text in the position block (it dithers on a black-and-white printer).
            $pdf->text($x + 25 - $pdf->font('R')->textWidth('poziția', 8) / 2, $y - 33, 'poziția', 'R', 8);
            $kind = ProductionTicketPdf::kindLabel($line);
            $codes = $pdf->wrap((string) ($line['code'] ?? '—'), $contentRight - $cx - $bold->textWidth($kind, 11) - 12, 'B', 15);
            $pdf->text($contentRight, $y - 10, $kind, 'B', 11, 'right');
            foreach ($codes as $i => $code) {
                $pdf->text($cx, $y - 10 - 17 * $i, $code, 'B', 15);
            }
        }
        $codeLines = count($pdf->wrap((string) ($line['code'] ?? '—'), $contentRight - $cx - $bold->textWidth(ProductionTicketPdf::kindLabel($line), 11) - 12, 'B', 15));
        $yy = $y - 27 - 17 * ($codeLines - 1);
        foreach ($pdf->wrap(ProductionTicketPdf::nameText($line), $textWidth, 'R', 10.5) as $text) {
            if ($draw) {
                $pdf->text($cx, $yy, $text, 'R', 10.5);
            }
            $yy -= 13;
        }

        // Commercial quantity: the largest figure of the block, white with a heavy black frame.
        $top = $yy + 13 - 10;
        $band = 66.0;
        if ($draw) {
            $pdf->strokeRect($cx, $top - $band, $textWidth, $band, 2.4, 0.0);
            $figure = $quantity . ' ×';
            $pdf->text($cx + 12, $top - 34, $figure, 'B', 30);
            $pdf->text($cx + 20 + $bold->textWidth($figure, 30), $top - 34, $noun, 'B', 21);
            $summary = mb_strtoupper(self::count($quantity, 'produs comandat', 'produse comandate', 'de')) . ' · '
                . ($each === null ? 'DIMENSIUNI NECONFIRMATE' : ($quantity > 1 ? 'FIECARE: ' : '') . $each);
            $size = 13.0;
            while ($size > 10 && $bold->textWidth($summary, $size) > $textWidth - 24) {
                $size -= 0.5;
            }
            $pdf->text($cx + 12, $top - 56, $summary, 'B', $size);
        }
        $top -= $band + 14;

        // Dimensions of ONE product, never multiplied or divided by the quantity.
        if ($draw) {
            $pdf->text($cx, $top - 9, 'DIMENSIUNI PER PRODUS', 'B', 10.5);
        }
        $top -= 15;
        $values = [
            ['LĂȚIME', TicketSnapshot::measure($line['width'], null), $unit, 'width'],
            ['ÎNĂLȚIME', TicketSnapshot::measure($line['height'], null), $unit, 'height'],
            ['METRI (LINIE)', TicketSnapshot::measure($line['meters'], null), 'm', null],
        ];
        $boxWidth = $textWidth / 3;
        $boxHeight = 44.0;
        $boxY = $top - $boxHeight;
        foreach ($draw ? $values : [] as $i => [$caption, $value, $suffix, $icon]) {
            $bx = $cx + $i * $boxWidth;
            $bw = $boxWidth - ($i === count($values) - 1 ? 0 : 8);
            $pdf->strokeRect($bx, $boxY, $bw, $boxHeight, 1.0, 0.0);
            $pdf->text($bx + 8, $boxY + $boxHeight - 13, $caption, 'B', 9.5);
            if ($value !== null && $icon === 'width') {
                self::dimension($pdf, $bx + $bw - 42, $boxY + 15, $bx + $bw - 10, $boxY + 15, 1.2, 6.0, 3.5);
            } elseif ($value !== null && $icon === 'height') {
                self::dimension($pdf, $bx + $bw - 18, $boxY + 6, $bx + $bw - 18, $boxY + $boxHeight - 6, 1.2, 6.0, 3.5);
            }
            $shown = $value ?? '—';
            $room = $bw - 16 - ($icon === null || $value === null ? 0 : 40) - ($value !== null && $suffix !== null ? $pdf->font('R')->textWidth($suffix, 11) + 4 : 0);
            $size = 20.0;
            while ($size > 10 && $bold->textWidth($shown, $size) > $room) {
                $size -= 0.5;
            }
            $pdf->text($bx + 8, $boxY + 9, $shown, 'B', $size);
            if ($value !== null && $suffix !== null) {
                $pdf->text($bx + 11 + $bold->textWidth($shown, $size), $boxY + 9, $suffix, 'R', 11);
            }
        }
        $top = $boxY - 14;

        if ($sketch) {
            $top = self::heroSketch($pdf, $line, $cx, $top, $textWidth, $rect, $draw);
        } else {
            // Nothing is guessed: no numbers, no arrows, no drawing.
            $lines = $pdf->wrap('Dimensiunile lipsesc la sursă — verifică înainte de croire. Nu se ghicesc.', $textWidth - 56, 'B', 11);
            $box = 34 + 14 * count($lines);
            if ($draw) {
                $pdf->strokeRect($cx, $top - $box, $textWidth, $box, 2.4, 0.0);
                self::warningSign($pdf, $cx + 10, $top - $box / 2 - 13, 28.0);
                $pdf->text($cx + 48, $top - 21, 'DIMENSIUNI NECONFIRMATE', 'B', 15);
                foreach ($lines as $i => $text) {
                    $pdf->text($cx + 48, $top - 38 - 14 * $i, $text, 'B', 11);
                }
            }
            $top -= $box + 12;
        }

        // The rule, framed: the quantity is separate products, never a split of one product.
        if ($quantity > 1) {
            $count = self::NUMBER_WORDS[$quantity] ?? (string) $quantity;
            $rules = [
                [$count . ($quantity >= 20 && ($quantity % 100 === 0 || $quantity % 100 >= 20) ? ' DE' : '') . ' PRODUSE SEPARATE.', 14.0],
                ['NU ' . $single . ' ÎN ' . $count . '.', 14.0],
            ];
            $indent = 48.0;
        } else {
            $rules = [['Un singur produs comandat.', 12.0], ['Comanda nu conține o împărțire în bucăți.', 12.0]];
            $indent = 12.0;
        }
        $ruleLines = [];
        foreach ($rules as [$text, $size]) {
            foreach ($pdf->wrap($text, $textWidth - $indent - 12, 'B', $size) as $part) {
                $ruleLines[] = [$part, $size];
            }
        }
        $box = 16 + array_sum(array_map(static fn (array $rule): float => $rule[1] + 5, $ruleLines));
        if ($draw) {
            $pdf->strokeRect($cx, $top - $box, $textWidth, $box, $quantity > 1 ? 2.4 : 1.0, 0.0);
            if ($quantity > 1) {
                self::warningSign($pdf, $cx + 10, $top - $box / 2 - 13, 28.0);
            }
            $ly = $top - 8;
            foreach ($ruleLines as [$text, $size]) {
                $ly -= $size + 1;
                $pdf->text($cx + $indent, $ly, $text, 'B', $size);
                $ly -= 4;
            }
        }
        $yy = $top - $box - 16;

        if ($line['options'] !== []) {
            // Printed as source text only; a Trendyol option never becomes a panel split.
            foreach ($pdf->wrap(TicketSnapshot::optionsText($line['options']) ?? '', $textWidth, 'B', 10.5) as $text) {
                if ($draw) {
                    $pdf->text($cx, $yy, $text, 'B', 10.5);
                }
                $yy -= 14;
            }
        }
        foreach (['notes' => 'NOTE', 'productionNotes' => 'NOTE DE PRODUCȚIE'] as $key => $caption) {
            if ($line[$key] === null) {
                continue;
            }
            if ($draw) {
                $pdf->text($cx, $yy, $caption, 'B', 9);
            }
            $yy -= 14;
            // Wrapped in the font it is printed in: production notes are bold.
            $font = $key === 'productionNotes' ? 'B' : 'R';
            foreach ($pdf->wrap($line[$key], $textWidth, $font, 10.5) as $text) {
                if ($draw) {
                    $pdf->text($cx, $yy, $text, $font, 10.5);
                }
                $yy -= 14;
            }
        }
        return $y - $yy + 8;
    }

    /**
     * The drawing of the one-position layout: one or two products drawn one by one ("PRODUS 1", "PRODUS 2"),
     * each its own rectangle with its own width arrow above and height arrow on the left; from three products
     * one drawing ("FIECARE PRODUS") next to a large "N ×" multiplier. Returns the y below the drawing.
     *
     * @param array<string, mixed> $line
     */
    private static function heroSketch(PdfDocument $pdf, array $line, float $x, float $top, float $area, float $maxHeight, bool $draw): float
    {
        $bold = $pdf->font('B');
        $quantity = max(1, (int) $line['quantity']);
        $one = $quantity <= 2;
        $drawn = $one ? $quantity : 1;
        $unit = $line['unit'];
        $w = ProductionTicketPdf::units((string) $line['width']);
        $h = ProductionTicketPdf::units((string) $line['height']);
        $widthLabel = 'LĂȚIME: ' . mb_strtoupper((string) TicketSnapshot::measure((string) $line['width'], $unit));
        $heightValue = mb_strtoupper((string) TicketSnapshot::measure((string) $line['height'], $unit));
        $heightSize = 16.0;
        while ($heightSize > 10 && $bold->textWidth($heightValue, $heightSize) > 90) {
            $heightSize -= 0.5;
        }
        // Height label column, dimension line lane, then the rectangle.
        $column = max($bold->textWidth('ÎNĂLȚIME:', 13), $bold->textWidth($heightValue, $heightSize));
        $gap = 32.0;
        $slot = $one ? ($area - $gap * ($drawn - 1)) / $drawn : 236.0;
        $zone = $slot - $column - 22;
        $scale = min($zone / $w, $maxHeight / $h, ($quantity === 1 ? 260.0 : $zone) / $w);
        $rw = max(30.0, $w * $scale);
        $rh = max(30.0, $h * $scale);
        $caption = $quantity === 1 ? 'SCHIȚĂ · PRODUSUL COMANDAT'
            : ($one ? 'SCHIȚĂ · FIECARE DREPTUNGHI ESTE UN PRODUS SEPARAT' : 'SCHIȚĂ · UNUL DIN CELE ' . self::count($quantity, 'PRODUS IDENTIC', 'PRODUSE IDENTICE', 'DE'));
        $productY = $top - 31;
        $widthY = $productY - 19;
        $dimY = $widthY - 9;
        $rectTop = $dimY - 9;
        $ry = $rectTop - $rh;
        $mid = $ry + $rh / 2;
        $bottom = min($ry, $mid - 19) - 14;
        if (!$draw) {
            return $bottom;
        }
        $pdf->text($x, $top - 9, $caption, 'B', 10.5);
        // A single product is centred; two sit side by side with a clear gap.
        $offset = $quantity === 1 ? max(0.0, ($slot - ($column + 22 + $rw)) / 2) : 0.0;
        for ($i = 0; $i < $drawn; $i++) {
            $sx = $x + $i * ($slot + $gap) + $offset;
            $rx = $sx + $column + 22;
            $span = $quantity === 1 ? $column + 22 + $rw : $slot;
            $label = $one ? 'PRODUS ' . ($i + 1) : 'FIECARE PRODUS';
            $centre = static fn (string $text, float $size): float => max($sx, min($rx + $rw / 2 - $bold->textWidth($text, $size) / 2, $sx + $span - $bold->textWidth($text, $size)));
            $pdf->text($centre($label, 15), $productY, $label, 'B', 15);
            $size = 14.0;
            while ($size > 10 && $bold->textWidth($widthLabel, $size) > $span) {
                $size -= 0.5;
            }
            $pdf->text($centre($widthLabel, $size), $widthY, $widthLabel, 'B', $size);
            $pdf->line($rx, $rectTop + 3, $rx, $dimY + 6, 0.8, 0.0);
            $pdf->line($rx + $rw, $rectTop + 3, $rx + $rw, $dimY + 6, 0.8, 0.0);
            self::dimension($pdf, $rx, $dimY, $rx + $rw, $dimY);
            $dimX = $rx - 11;
            $pdf->line($rx - 3, $ry, $dimX - 6, $ry, 0.8, 0.0);
            $pdf->line($rx - 3, $ry + $rh, $dimX - 6, $ry + $rh, 0.8, 0.0);
            self::dimension($pdf, $dimX, $ry, $dimX, $ry + $rh);
            $pdf->text($dimX - 8, $mid + 3, 'ÎNĂLȚIME:', 'B', 13, 'right');
            $pdf->text($dimX - 8, $mid - 15, $heightValue, 'B', $heightSize, 'right');
            $pdf->strokeRect($rx, $ry, $rw, $rh, 2.5, 0.0);
            if ($one && $rw >= 40 && $rh >= 44) {
                $number = (string) ($i + 1);
                $pdf->text($rx + $rw / 2 - $bold->textWidth($number, 26) / 2, $mid - 9, $number, 'B', 26);
            }
        }
        if (!$one) {
            $px = $x + $slot + 18;
            $pw = $area - $slot - 18;
            $figure = $quantity . ' ×';
            $each = 'FIECARE: ' . mb_strtoupper(TicketSnapshot::measure((string) $line['width'], null) . ' × ' . TicketSnapshot::measure((string) $line['height'], $unit));
            $eachSize = 13.0;
            while ($eachSize > 9 && $bold->textWidth($each, $eachSize) > $pw - 20) {
                $eachSize -= 0.5;
            }
            $pdf->strokeRect($px, $mid - 42, $pw, 86, 2.4, 0.0);
            $pdf->text($px + 12, $mid + 12, $figure, 'B', 30);
            $pdf->text($px + 12, $mid - 10, 'PRODUSE IDENTICE', 'B', 14);
            $pdf->text($px + 12, $mid - 30, $each, 'B', $eachSize);
        }
        return $bottom;
    }

    /**
     * Every text on its page and inside its own position block. A failure means the measurement and the
     * drawing disagree: nothing is handed out.
     *
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: int, 6: int}> $regions
     */
    private static function validate(PdfDocument $pdf, array $regions, int $total): void
    {
        $log = $pdf->textLog();
        $fail = static fn (array $text): TicketLayoutOverflow => new TicketLayoutOverflow('Documentul de producție nu a putut fi așezat corect pe pagină („' . $text['text'] . '”). Nu se generează.');
        foreach ($log as $text) {
            if ($text['page'] < 1 || $text['page'] > $total || $text['x'] < 28 || $text['x'] + $text['width'] > PdfDocument::WIDTH - 28 || $text['y'] < 20 || $text['y'] + $text['size'] > PdfDocument::HEIGHT - 20) {
                throw $fail($text);
            }
        }
        foreach ($regions as [$page, $x, $y, $width, $height, $from, $to]) {
            foreach (array_slice($log, $from, $to - $from) as $text) {
                if ($text['page'] !== $page || $text['x'] < $x - 0.5 || $text['x'] + $text['width'] > $x + $width + 0.5 || $text['y'] - 0.22 * $text['size'] < $y - 0.5 || $text['y'] + 0.75 * $text['size'] > $y + $height + 0.5 || $text['y'] < self::BOTTOM - 6) {
                    throw $fail($text);
                }
            }
        }
    }

    /**
     * A measurement line with a filled arrowhead at each end; the tips sit exactly on the measured points,
     * so the arrow spans the whole dimension.
     */
    private static function dimension(PdfDocument $pdf, float $x1, float $y1, float $x2, float $y2, float $stroke = 1.8, float $head = 11.0, float $half = 5.0): void
    {
        $length = hypot($x2 - $x1, $y2 - $y1);
        $head = min($head, $length / 3);
        $ux = ($x2 - $x1) / $length;
        $uy = ($y2 - $y1) / $length;
        $pdf->line($x1 + $ux * ($head - 1), $y1 + $uy * ($head - 1), $x2 - $ux * ($head - 1), $y2 - $uy * ($head - 1), $stroke, 0.0);
        foreach ([[$x1, $y1, 1.0], [$x2, $y2, -1.0]] as [$tx, $ty, $direction]) {
            $bx = $tx + $direction * $ux * $head;
            $by = $ty + $direction * $uy * $head;
            $pdf->fillPolygon([[$tx, $ty], [$bx - $uy * $half, $by + $ux * $half], [$bx + $uy * $half, $by - $ux * $half]], 0.0);
        }
    }

    /** A solid black warning triangle with a white "!" ($x, $y: bottom-left corner). */
    private static function warningSign(PdfDocument $pdf, float $x, float $y, float $size): void
    {
        $pdf->fillPolygon([[$x, $y], [$x + $size, $y], [$x + $size / 2, $y + $size * 0.9]], 0.0);
        $mark = $size * 0.6;
        $pdf->text($x + $size / 2 - $pdf->font('B')->textWidth('!', $mark) / 2, $y + $size * 0.14, '!', 'B', $mark, 'left', [1, 1, 1]);
    }

    /** "1 POZIȚIE", "3 POZIȚII", "20 DE POZIȚII": Romanian count agreement (de from 20, except 101–119 etc.). */
    private static function count(int $number, string $one, string $many, string $of): string
    {
        if ($number === 1) {
            return '1 ' . $one;
        }
        return $number . ' ' . ($number > 0 && ($number % 100 === 0 || $number % 100 >= 20) ? $of . ' ' : '') . $many;
    }
}
