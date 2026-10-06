<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\QrCode;
use Arasya\Operations\B2B\Pdf\TrueTypeFont;
use Arasya\Operations\B2B\ProjectLabels;

/**
 * The canonical Arasya workshop production ticket (A4, Romanian only), for every source.
 *
 * It renders exactly one stored revision snapshot: every page comes from the same immutable data, so a
 * document can never mix two database states. The renderer is source-neutral; the source only selects
 * the visible stamp. It prints no prices, payment, balances or email, and the phone only masked.
 *
 * Every page repeats the order number, source stamp, revision, the canonical QR and "Pagina X / Y", so
 * separated pages are never anonymous. Line blocks are never split across pages. Line schematics are
 * deterministic workshop drawings from the printed measurements only; nothing is inferred.
 */
final class ProductionTicketPdf
{
    private const LEFT = 32.0;
    private const BOTTOM = 52.0;
    private const MUTED = [0.36, 0.38, 0.43];
    private const GOLD = [0.62, 0.43, 0.15];
    private const KIND = ['curtain' => 'Perdea', 'drapery' => 'Draperie', 'other' => 'Alt produs'];

    /**
     * @param array<string, mixed> $snapshot the stored revision snapshot
     * @param array{number: int, status: string, generatedAt: string, qrPayload: string|null, sourceDisplayName?: string|null} $revision
     */
    public static function render(array $snapshot, array $revision): string
    {
        $pages = self::layout($snapshot, $revision, 0)[1];
        return self::layout($snapshot, $revision, $pages)[0]->output();
    }

    /**
     * The laid-out document (with its final page count) for layout, content and QR tests.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $revision
     */
    public static function document(array $snapshot, array $revision): PdfDocument
    {
        $pages = self::layout($snapshot, $revision, 0)[1];
        return self::layout($snapshot, $revision, $pages)[0];
    }

    /** @param array<string, mixed> $snapshot */
    public static function filename(array $snapshot, int $revision): string
    {
        $number = preg_replace('/[^A-Za-z0-9-]+/', '-', ltrim((string) ($snapshot['order']['number'] ?? 'comanda'), '#')) ?? 'comanda';
        return 'ARASYA-' . trim($number, '-') . '-R' . $revision . '.pdf';
    }

    /** Number of physical pages (used by tests and the print screen). @param array<string, mixed> $snapshot @param array<string, mixed> $revision */
    public static function pageCount(array $snapshot, array $revision): int
    {
        return self::layout($snapshot, $revision, 0)[1];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $revision
     * @return array{0: PdfDocument, 1: int}
     */
    private static function layout(array $snapshot, array $revision, int $total): array
    {
        $fonts = dirname(__DIR__) . '/B2B/Pdf/fonts/';
        $number = (string) $snapshot['order']['number'];
        $pdf = new PdfDocument([
            'R' => new TrueTypeFont($fonts . 'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed'),
            'B' => new TrueTypeFont($fonts . 'DejaVuSansCondensed-Bold.ttf', 'DejaVuSansCondensed-Bold'),
        ], 'Document de producție ' . $number . ' · Revizia ' . $revision['number']);
        $right = PdfDocument::WIDTH - self::LEFT;
        $width = $right - self::LEFT;
        $qr = $revision['qrPayload'] === null ? null : QrCode::matrix((string) $revision['qrPayload'], 'Q');
        $stamp = SourceStamp::label((string) $snapshot['order']['source'], $revision['sourceDisplayName'] ?? null);
        $generated = (new \DateTimeImmutable((string) $revision['generatedAt'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i');
        $active = $revision['status'] === 'active';
        $page = 0;
        $y = 0.0;

        $newPage = function () use ($pdf, $snapshot, $revision, $qr, $stamp, $generated, $active, $right, $width, $number, $total, &$page, &$y): void {
            $pdf->addPage();
            $page++;
            $first = $page === 1;
            $top = PdfDocument::HEIGHT - 30;
            $qrSize = $first ? 118.0 : 86.0;
            if ($qr !== null) {
                $pdf->qr($right - $qrSize, $top - $qrSize + 6, $qrSize, $qr);
            } else {
                $pdf->text($right, $top - 20, 'Cod QR indisponibil — folosește codul manual.', 'B', 8, 'right');
            }
            // Source stamp: the channel is visible at a glance on every page.
            $stampSize = $first ? 14.0 : 12.0;
            $stampWidth = $pdf->font('B')->textWidth($stamp, $stampSize) + 20;
            $pdf->fillRect(self::LEFT, $top - 22, $stampWidth, 24, 0.1);
            $pdf->text(self::LEFT + 10, $top - 15, $stamp, 'B', $stampSize, 'left', [1, 1, 1]);
            $pdf->text(self::LEFT + $stampWidth + 10, $top - 15, 'ARASYA HOME · DOCUMENT DE PRODUCȚIE', 'B', 8.5, 'left', self::GOLD);
            $pdf->text(self::LEFT, $top - ($first ? 44 : 40), 'COMANDA', 'B', 7.5, 'left', self::MUTED);
            // Revision mark: impossible to confuse revision 1 with revision 2.
            $boxWidth = 132.0;
            $boxX = $right - $qrSize - 14 - $boxWidth;
            // The order number is never cut: it shrinks to stay clear of the revision mark.
            $numberText = '#' . ltrim($number, '#');
            $numberSize = $first ? 30.0 : 22.0;
            while ($numberSize > 12 && $pdf->font('B')->textWidth($numberText, $numberSize) > $boxX - self::LEFT - 12) {
                $numberSize -= 1;
            }
            $pdf->text(self::LEFT, $top - ($first ? 74 : 64), $numberText, 'B', $numberSize);
            $boxTop = $top - 34;
            $boxHeight = $first ? 54.0 : 44.0;
            $pdf->strokeRect($boxX, $boxTop - $boxHeight, $boxWidth, $boxHeight, 2.4, 0.0);
            $pdf->text($boxX + $boxWidth / 2 - $pdf->font('B')->textWidth('REVIZIA ' . $revision['number'], $first ? 20 : 16) / 2, $boxTop - ($first ? 26 : 22), 'REVIZIA ' . $revision['number'], 'B', $first ? 20 : 16);
            $state = $active ? 'DOCUMENT ACTIV' : 'DOCUMENT ÎNLOCUIT — NU SE FOLOSEȘTE';
            $stateSize = $active ? 9.0 : 6.5;
            $pdf->text($boxX + $boxWidth / 2 - $pdf->font('B')->textWidth($state, $stateSize) / 2, $boxTop - $boxHeight + 9, $state, 'B', $stateSize);
            $pdf->text($right, $top - $qrSize - 2, 'Cod manual: ' . (string) $snapshot['order']['lookupCode'], 'B', $first ? 10 : 8.5, 'right');
            $meta = 'Generat: ' . $generated . '   ·   Pagina ' . $page . ($total > 0 ? ' / ' . $total : '');
            $metaY = $top - ($first ? 92 : 80);
            $pdf->text(self::LEFT, $metaY, $meta, 'R', 8.5, 'left', self::MUTED);
            if ($first) {
                $pdf->text($right, $top - $qrSize - 14, 'Scanează cu Arasya Staff', 'R', 7.5, 'right', self::MUTED);
            }
            $rule = min($metaY - 10, $top - $qrSize - ($first ? 22 : 12));
            $pdf->line(self::LEFT, $rule, $right, $rule, 1.6, 0.0);
            $y = $rule - 20;
            $pdf->line(self::LEFT, 38, $right, 38, 0.4, 0.75);
            $pdf->text(self::LEFT, 26, 'Arasya Home · #' . ltrim($number, '#') . ' · REVIZIA ' . $revision['number'] . ' · Document de atelier — nu conține date financiare.', 'R', 7.5, 'left', self::MUTED);
            $pdf->text($right, 26, 'Pagina ' . $page . ($total > 0 ? ' / ' . $total : ''), 'B', 8, 'right');
        };
        $newPage();

        $y = self::customerBlock($pdf, $snapshot, $y, $width);
        if (is_string($snapshot['notes'] ?? null) && trim($snapshot['notes']) !== '') {
            $lines = $pdf->wrap($snapshot['notes'], $width - 24, 'B', 11, 10);
            $height = 24 + 14 * count($lines);
            $pdf->strokeRect(self::LEFT, $y - $height + 12, $width, $height, 1.4, 0.0);
            $pdf->text(self::LEFT + 12, $y - 3, 'INSTRUCȚIUNI COMANDĂ', 'B', 8, 'left', self::MUTED);
            $yy = $y - 19;
            foreach ($lines as $line) {
                $pdf->text(self::LEFT + 12, $yy, $line, 'B', 11);
                $yy -= 14;
            }
            $y -= $height + 10;
        }
        $lines = self::sorted($snapshot['lines'] ?? []);
        $pdf->text(self::LEFT, $y, 'PRODUSE DE EXECUTAT · ' . count($lines) . ' ' . (count($lines) === 1 ? 'linie' : 'linii') . self::totalMeters($lines), 'B', 9, 'left', self::MUTED);
        $y -= 18;

        $hasProject = array_filter($lines, static fn (array $line): bool => ($line['project'] ?? null) !== null) !== [];
        $group = $hasProject ? null : '';
        $opening = null;
        foreach ($lines as $line) {
            $project = $line['project'] ?? null;
            $groupKey = $project === null ? '' : ($project['zone']['id'] ?? '') . '|' . ($project['room']['id'] ?? '');
            $openingKey = $project === null ? '' : (string) ($project['opening']['id'] ?? '');
            $block = self::blockHeight($pdf, $line, $width);
            $header = ($groupKey !== $group ? 30 : 0) + ($project !== null && $openingKey !== $opening ? 22 : 0);
            if ($y - $block - $header < self::BOTTOM) {
                $newPage();
                $group = $hasProject ? null : '';
                $opening = null;
            }
            if ($groupKey !== $group) {
                $title = $project === null ? 'Produse fără locație de proiect' : self::zoneTitle($project) . '  ›  ' . ($project['room']['name'] ?? '');
                $pdf->fillRect(self::LEFT, $y - 7, $width, 22, 0.12);
                $pdf->text(self::LEFT + 10, $y, $pdf->fit($title, $width - 20, 'B', 12.5), 'B', 12.5, 'left', [1, 1, 1]);
                $y -= 30;
                $group = $groupKey;
                $opening = null;
            }
            if ($project !== null && $openingKey !== $opening) {
                $pdf->text(self::LEFT, $y, $pdf->fit((string) ($project['opening']['name'] ?? ''), 160, 'B', 11), 'B', 11);
                $pdf->text(self::LEFT + 166, $y, $pdf->fit(self::openingMeta($project), $width - 166, 'R', 10), 'R', 10);
                $y -= 22;
                $opening = $openingKey;
            }
            self::lineBlock($pdf, $line, $y, $width);
            $y -= $block + 10;
        }
        return [$pdf, $page];
    }

    /** @param array<string, mixed> $snapshot */
    private static function customerBlock(PdfDocument $pdf, array $snapshot, float $y, float $width): float
    {
        $customer = $snapshot['customer'] ?? null;
        $projects = [];
        foreach ($snapshot['lines'] ?? [] as $line) {
            $code = $line['project']['project']['code'] ?? null;
            if (is_string($code)) {
                $projects[$code] = $code . ' · ' . ($line['project']['project']['name'] ?? '');
            }
        }
        $leftWidth = $projects === [] ? $width : $width * 0.6;
        $rows = [];
        if ($customer === null) {
            $rows[] = ['Date de livrare indisponibile la sursă.', 'R', 10];
        } else {
            if ($customer['name'] !== null) {
                $rows[] = [$customer['name'], 'B', 13];
            }
            if ($customer['company'] !== null) {
                $rows[] = [($snapshot['order']['source'] === 'b2b' ? 'Cod companie: ' : '') . $customer['company'], 'R', 10];
            }
            if (($customer['contact'] ?? null) !== null) {
                $rows[] = ['Persoană de contact: ' . $customer['contact'], 'R', 10];
            }
            $address = $customer['addressLines'];
            $compact = $address === [] ? [] : [$address[0], ...(count($address) > 1 ? [implode(', ', array_slice($address, 1))] : [])];
            foreach ($compact as $address) {
                foreach ($pdf->wrap($address, $leftWidth - 16, 'R', 10, 2) as $part) {
                    $rows[] = [$part, 'R', 10];
                }
            }
            if ($customer['addressLines'] === []) {
                $rows[] = ['Adresă de livrare indisponibilă la sursă.', 'R', 9];
            }
            if ($customer['phoneMasked'] !== null) {
                $rows[] = ['Telefon: ' . $customer['phoneMasked'], 'B', 10];
            }
        }
        $height = 18 + array_sum(array_map(static fn (array $row): float => $row[2] + 4, $rows)) + 6;
        $height = max($height, $projects === [] ? 0 : 18 + 14 * count($projects) + 6);
        $pdf->fillRect(self::LEFT, $y - $height + 12, $width, $height, 0.96);
        $pdf->text(self::LEFT + 8, $y - 2, 'CLIENT / LIVRARE', 'B', 7.5, 'left', self::MUTED);
        $yy = $y - 18;
        foreach ($rows as [$text, $font, $size]) {
            $pdf->text(self::LEFT + 8, $yy, $pdf->fit($text, $leftWidth - 16, $font, $size), $font, $size);
            $yy -= $size + 4;
        }
        if ($projects !== []) {
            $x = self::LEFT + $leftWidth + 8;
            $pdf->text($x, $y - 2, 'PROIECT', 'B', 7.5, 'left', self::MUTED);
            $yy = $y - 18;
            foreach ($projects as $label) {
                $pdf->text($x, $yy, $pdf->fit($label, $width - $leftWidth - 16, 'B', 10.5), 'B', 10.5);
                $yy -= 14;
            }
        }
        return $y - $height - 8;
    }

    /** @param array<string, mixed> $line */
    private static function blockHeight(PdfDocument $pdf, array $line, float $width): float
    {
        $textWidth = self::textWidth($line, $width);
        $height = 100.0;
        if ($line['options'] !== []) {
            $height += 14 + 13 * count($pdf->wrap(TicketSnapshot::optionsText($line['options']) ?? '', $textWidth, 'B', 10, 4));
        }
        foreach (['notes', 'productionNotes'] as $key) {
            if ($line[$key] !== null) {
                $height += 14 + 13 * count($pdf->wrap($line[$key], $textWidth, 'R', 10, 6));
            }
        }
        return $height;
    }

    /** @param array<string, mixed> $line */
    private static function lineBlock(PdfDocument $pdf, array $line, float $y, float $width): void
    {
        $height = self::blockHeight($pdf, $line, $width);
        $x = self::LEFT;
        $right = $x + $width;
        $pdf->strokeRect($x, $y - $height + 12, $width, $height, 1.1, 0.0);
        $pdf->fillRect($x, $y - $height + 12, 50, $height, 0.92);
        $label = '#' . $line['line'];
        $pdf->text($x + 25 - $pdf->font('B')->textWidth($label, 18) / 2, $y - 20, $label, 'B', 18);
        $pdf->text($x + 25 - $pdf->font('R')->textWidth('linia', 7) / 2, $y - 32, 'linia', 'R', 7, 'left', self::MUTED);
        $cx = $x + 62;
        $schematic = self::hasSchematic($line);
        $contentRight = $schematic ? $right - 116 : $right - 10;
        $kind = self::kindLabel($line);
        $pdf->text($contentRight, $y - 8, $kind, 'B', 10, 'right', self::GOLD);
        $pdf->text($cx, $y - 8, $pdf->fit($line['code'] ?? '—', $contentRight - $cx - $pdf->font('B')->textWidth($kind, 10) - 12, 'B', 13), 'B', 13);
        $name = implode('  ·  ', array_filter([$line['name'], $line['color'] === null ? null : 'Culoare: ' . $line['color'], $line['variant'] === null ? null : 'Variantă: ' . $line['variant']]));
        $pdf->text($cx, $y - 24, $pdf->fit($name, $contentRight - $cx, 'R', 10), 'R', 10);
        $unit = $line['unit'];
        $values = [
            ['LĂȚIME', TicketSnapshot::measure($line['width'], null), $unit],
            ['ÎNĂLȚIME', TicketSnapshot::measure($line['height'], null), $unit],
            ['CANTITATE', (string) $line['quantity'], 'buc.'],
            ['METRI (LINIE)', TicketSnapshot::measure($line['meters'], null), 'm'],
        ];
        $boxWidth = ($contentRight - $cx) / 4;
        $boxY = $y - 78;
        foreach ($values as $i => [$caption, $value, $suffix]) {
            $bx = $cx + $i * $boxWidth;
            $pdf->strokeRect($bx, $boxY, $boxWidth - 8, 42, 0.7, 0.5);
            $pdf->text($bx + 6, $boxY + 31, $caption, 'B', 7, 'left', self::MUTED);
            $shown = $value ?? '—';
            // Measurements are never shortened: the figure shrinks to fit its box instead.
            $room = $boxWidth - 20 - ($value !== null && $suffix !== null ? $pdf->font('R')->textWidth($suffix, 9) + 4 : 0);
            $size = 17.0;
            while ($size > 9 && $pdf->font('B')->textWidth($shown, $size) > $room) {
                $size -= 0.5;
            }
            $pdf->text($bx + 6, $boxY + 9, $shown, 'B', $size);
            if ($value !== null && $suffix !== null) {
                $pdf->text($bx + 8 + $pdf->font('B')->textWidth($shown, $size), $boxY + 9, $suffix, 'R', 9, 'left', self::MUTED);
            }
        }
        $yy = $boxY - 14;
        $textWidth = self::textWidth($line, $width);
        if ($line['options'] !== []) {
            $pdf->text($cx, $yy, 'OPȚIUNI DE CONFECȚIONARE', 'B', 7.5, 'left', self::MUTED);
            $yy -= 13;
            foreach ($pdf->wrap(TicketSnapshot::optionsText($line['options']) ?? '', $textWidth, 'B', 10, 4) as $text) {
                $pdf->text($cx, $yy, $text, 'B', 10);
                $yy -= 13;
            }
        }
        foreach (['notes' => 'NOTE', 'productionNotes' => 'NOTE DE PRODUCȚIE'] as $key => $caption) {
            if ($line[$key] === null) {
                continue;
            }
            $pdf->text($cx, $yy, $caption, 'B', 7.5, 'left', self::MUTED);
            $yy -= 13;
            foreach ($pdf->wrap($line[$key], $textWidth, 'R', 10, 6) as $text) {
                $pdf->text($cx, $yy, $text, $key === 'productionNotes' ? 'B' : 'R', 10);
                $yy -= 13;
            }
        }
        if ($schematic) {
            self::schematic($pdf, $line, $right - 108, $y - 6, 100, $height - 20);
        }
    }

    /**
     * Deterministic workshop schematic: the finished width × height to scale, with panel splits only
     * when the printed data states them (panel layout or an explicit piece count option).
     *
     * @param array<string, mixed> $line
     */
    private static function schematic(PdfDocument $pdf, array $line, float $x, float $top, float $boxWidth, float $boxHeight): void
    {
        $w = self::units((string) $line['width']);
        $h = self::units((string) $line['height']);
        $maxW = $boxWidth - 24;
        $maxH = max(30.0, min($boxHeight - 30, 70.0));
        $scale = min($maxW / $w, $maxH / $h);
        $rw = max(14.0, $w * $scale);
        $rh = max(14.0, $h * $scale);
        $rx = $x + 12 + ($maxW - $rw) / 2;
        $ry = $top - 14 - $rh;
        $pdf->text($x + $boxWidth / 2 - $pdf->font('B')->textWidth('SCHIȚĂ', 6.5) / 2, $top, 'SCHIȚĂ', 'B', 6.5, 'left', self::MUTED);
        $pdf->fillRect($rx, $ry, $rw, $rh, 0.95);
        $pdf->strokeRect($rx, $ry, $rw, $rh, 1.2, 0.0);
        $pdf->line($rx, $ry + $rh + 3, $rx + $rw, $ry + $rh + 3, 0.6, 0.0);
        $panels = self::panels($line);
        for ($i = 1; $i < $panels; $i++) {
            $px = $rx + $rw * $i / $panels;
            $pdf->line($px, $ry, $px, $ry + $rh, 0.9, 0.2);
        }
        $unit = $line['unit'] ?? '';
        $widthLabel = TicketSnapshot::measure((string) $line['width'], $unit === '' ? null : $unit) ?? '';
        $heightLabel = TicketSnapshot::measure((string) $line['height'], $unit === '' ? null : $unit) ?? '';
        $pdf->text($rx + $rw / 2 - $pdf->font('B')->textWidth($widthLabel, 7.5) / 2, $ry + $rh + 6, $widthLabel, 'B', 7.5);
        $pdf->text($rx + $rw / 2 - $pdf->font('B')->textWidth('↕ ' . $heightLabel, 7.5) / 2, $ry - 11, '↕ ' . $heightLabel, 'B', 7.5);
        if ($panels > 1) {
            $caption = $panels . ' buc.';
            $captionWidth = $pdf->font('B')->textWidth($caption, 7);
            $pdf->fillRect($rx + $rw / 2 - $captionWidth / 2 - 2, $ry + 2, $captionWidth + 4, 10, 1.0);
            $pdf->text($rx + $rw / 2 - $captionWidth / 2, $ry + 4, $caption, 'B', 7);
        }
    }

    /** @param array<string, mixed> $line */
    private static function hasSchematic(array $line): bool
    {
        return $line['width'] !== null && $line['height'] !== null && self::units((string) $line['width']) > 0 && self::units((string) $line['height']) > 0;
    }

    /** Panel count only from printed data: project panel layout, or an explicit piece/segment option. @param array<string, mixed> $line */
    private static function panels(array $line): int
    {
        if (($line['project']['treatment']['panelLayout'] ?? null) === 'pair') {
            return 2;
        }
        foreach ($line['options'] as $option) {
            if (preg_match('/buc|bucăți|bucati|segment|panou|panouri|pieces/iu', $option['label'] . ' ' . $option['value']) === 1 && preg_match('/\b([1-8])\b/', $option['value'], $m) === 1) {
                return (int) $m[1];
            }
        }
        return 1;
    }

    /** @param array<string, mixed> $line */
    private static function kindLabel(array $line): string
    {
        $types = ProjectLabels::TYPES['ro'];
        $project = $line['project'] ?? null;
        $kind = $project !== null && isset($types['treatment'][$project['treatment']['treatmentType'] ?? ''])
            ? $types['treatment'][$project['treatment']['treatmentType']]
            : (self::KIND[$line['kind'] ?? ''] ?? '');
        $layout = $project['treatment']['panelLayout'] ?? null;
        return $kind . ($layout !== null && isset($types['layout'][$layout]) ? ' · ' . $types['layout'][$layout] : '');
    }

    /** @param array<string, mixed> $project */
    private static function zoneTitle(array $project): string
    {
        $types = ProjectLabels::TYPES['ro'];
        $zone = $project['zone'];
        $prefix = isset($types['zone'][$zone['zoneType'] ?? '']) ? $types['zone'][$zone['zoneType']] . ' ' : '';
        return trim(($zone['building'] !== null ? $zone['building'] . ' · ' : '') . ($zone['name'] ?? $prefix));
    }

    /** @param array<string, mixed> $project */
    private static function openingMeta(array $project): string
    {
        $types = ProjectLabels::TYPES['ro'];
        $opening = $project['opening'];
        $meta = [];
        if (isset($types['opening'][$opening['openingType'] ?? ''])) {
            $meta[] = $types['opening'][$opening['openingType']];
        }
        if ($opening['width'] !== null || $opening['height'] !== null) {
            $meta[] = 'gol ' . ProjectLabels::dimensions($opening['width'], $opening['height']);
        }
        if (isset($types['mounting'][$opening['mounting'] ?? ''])) {
            $meta[] = $types['mounting'][$opening['mounting']];
        }
        if ($opening['railType'] !== null) {
            $meta[] = 'șină ' . $opening['railType'];
        }
        return implode(' · ', $meta);
    }

    /** @param list<array<string, mixed>> $lines */
    private static function totalMeters(array $lines): string
    {
        $units = 0;
        $known = false;
        foreach ($lines as $line) {
            if ($line['meters'] !== null) {
                $known = true;
                $units += self::units((string) $line['meters']);
            }
        }
        if (!$known) {
            return '';
        }
        $text = intdiv($units, 1000) . '.' . str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT);
        return ' · total ' . TicketSnapshot::measure($text, 'm');
    }

    /** @param array<string, mixed> $line */
    private static function textWidth(array $line, float $width): float
    {
        return (self::hasSchematic($line) ? $width - 116 : $width - 10) - 62 - 10;
    }

    private static function units(string $decimal): int
    {
        [$int, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        return (int) $int * 1000 + (int) substr(str_pad($fraction, 3, '0'), 0, 3);
    }

    /** Project lines in room order, others after, then by line number. @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    private static function sorted(array $lines): array
    {
        usort($lines, static fn (array $a, array $b): int => [($a['project'] ?? null) === null ? 1 : 0, $a['line']] <=> [($b['project'] ?? null) === null ? 1 : 0, $b['line']]);
        return $lines;
    }
}
