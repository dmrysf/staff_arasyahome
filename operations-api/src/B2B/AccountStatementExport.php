<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\TrueTypeFont;

/**
 * Renders the statement dataset from AccountQueries::statement as CSV or PDF. It only lays out the server figures
 * (opening, debit, credit, running and closing balances); it never adds amounts. Romanian is the default language.
 * Output contains business data of one company only: no internal ids, notes, permissions or session data.
 */
final class AccountStatementExport
{
    private const LABELS = [
        'ro' => [
            'title' => 'Extras de cont curent', 'issuer' => 'Arasya — cont curent B2B (evidență comercială internă)', 'company' => 'Companie', 'tax' => 'Cod fiscal',
            'currency' => 'Monedă', 'period' => 'Perioadă', 'from_start' => 'de la început', 'generated' => 'Generat', 'opening' => 'Sold la începutul perioadei',
            'closing' => 'Sold la sfârșitul perioadei', 'totals' => 'Total perioadă', 'date' => 'Data', 'document' => 'Document', 'type' => 'Tip', 'reference' => 'Referință',
            'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Sold', 'none' => 'Nicio mișcare în perioada selectată.', 'page' => 'Pagina',
            'meaning' => 'Sold pozitiv: suma datorată de companie către Arasya. Sold negativ: credit al companiei.',
            'types' => ['order_receivable' => 'Comandă finalizată', 'payment' => 'Plată', 'opening_balance' => 'Sold inițial', 'adjustment' => 'Ajustare', 'reversal' => 'Stornare'],
            'methods' => ['bank_transfer' => 'Transfer bancar', 'cash' => 'Numerar', 'card' => 'Card', 'compensation' => 'Compensare', 'other' => 'Altă metodă'],
            'reverses' => 'stornează', 'reversed' => 'stornată prin', 'order_cancelled' => 'comandă anulată',
            'csv' => ['date', 'document', 'type', 'reference', 'debit', 'credit', 'balance', 'currency', 'posted_at', 'posted_by'],
        ],
        'tr' => [
            'title' => 'Cari hesap ekstresi', 'issuer' => 'Arasya — B2B cari hesap (şirket içi ticari kayıt)', 'company' => 'Şirket', 'tax' => 'Vergi numarası',
            'currency' => 'Para birimi', 'period' => 'Dönem', 'from_start' => 'başlangıçtan', 'generated' => 'Oluşturulma', 'opening' => 'Dönem başı bakiye',
            'closing' => 'Dönem sonu bakiye', 'totals' => 'Dönem toplamı', 'date' => 'Tarih', 'document' => 'Belge', 'type' => 'Tür', 'reference' => 'Referans',
            'debit' => 'Borç', 'credit' => 'Alacak', 'balance' => 'Bakiye', 'none' => 'Seçilen dönemde hareket yok.', 'page' => 'Sayfa',
            'meaning' => 'Pozitif bakiye: şirketin Arasya\'ya borcu. Negatif bakiye: şirketin alacağı.',
            'types' => ['order_receivable' => 'Kesinleşen sipariş', 'payment' => 'Ödeme', 'opening_balance' => 'Açılış bakiyesi', 'adjustment' => 'Düzeltme', 'reversal' => 'Ters kayıt'],
            'methods' => ['bank_transfer' => 'Banka havalesi', 'cash' => 'Nakit', 'card' => 'Kart', 'compensation' => 'Mahsup', 'other' => 'Diğer yöntem'],
            'reverses' => 'iptal eder', 'reversed' => 'iptal eden', 'order_cancelled' => 'sipariş iptal edildi',
            'csv' => ['tarih', 'belge', 'tur', 'referans', 'borc', 'alacak', 'bakiye', 'para_birimi', 'kayit_zamani', 'kaydeden'],
        ],
    ];

    public static function language(mixed $value): string
    {
        return $value === 'tr' ? 'tr' : 'ro';
    }

    public static function filename(array $statement, string $extension): string
    {
        return sprintf('extras-%s-%s-%s.%s', $statement['company']['code'], $statement['currencyCode'], $statement['to'], $extension);
    }

    /** UTF-8 CSV (with BOM, semicolon separated, dot decimals) that opens in spreadsheet tools without losing diacritics. */
    public static function csv(array $statement, string $lang): string
    {
        $l = self::LABELS[$lang];
        $rows = [[$l['title']], [$l['company'], $statement['company']['legalName'], $statement['company']['code']], [$l['tax'], $statement['company']['countryCode'] . ' ' . $statement['company']['taxIdentifier']],
            [$l['currency'], $statement['currencyCode']], [$l['period'], ($statement['from'] ?? $l['from_start']) . ' — ' . $statement['to']],
            [$l['opening'], $statement['openingBalance']], [], $l['csv']];
        foreach ($statement['movements'] as $m) {
            $rows[] = [$m['valueDate'], $m['code'], $l['types'][$m['type']], self::reference($m, $l), $m['debit'] ?? '', $m['credit'] ?? '', $m['runningBalance'],
                $statement['currencyCode'], $m['createdAt'], $m['createdBy']];
        }
        $rows[] = [];
        $rows[] = [$l['totals'], '', '', '', $statement['totals']['debit'], $statement['totals']['credit']];
        $rows[] = [$l['closing'], $statement['closingBalance']];
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $row) $out .= implode(';', array_map(self::csvCell(...), $row)) . "\r\n";
        return $out;
    }

    public static function pdf(array $statement, string $lang): string
    {
        $l = self::LABELS[$lang];
        $fonts = __DIR__ . '/Pdf/fonts/';
        $pdf = new PdfDocument(['R' => new TrueTypeFont($fonts . 'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed'),
            'B' => new TrueTypeFont($fonts . 'DejaVuSansCondensed-Bold.ttf', 'DejaVuSansCondensed-Bold')], $l['title'] . ' ' . $statement['company']['code']);
        $left = 40.0; $right = PdfDocument::WIDTH - 40.0;
        // Column right edges for amounts, left edges for text.
        $cols = ['date' => $left, 'document' => $left + 58, 'type' => $left + 128, 'reference' => $left + 214, 'debit' => $right - 128, 'credit' => $right - 64, 'balance' => $right];
        $page = 0; $y = 0.0;
        $header = function () use ($pdf, $l, $statement, $left, $right, $cols, &$page, &$y): void {
            $pdf->addPage(); $page++;
            $y = PdfDocument::HEIGHT - 48;
            $pdf->text($left, $y, $l['title'], 'B', 15);
            $pdf->text($right, $y, $l['page'] . ' ' . $page, 'R', 8, 'right');
            $y -= 15; $pdf->text($left, $y, $l['issuer'], 'R', 8.5, 'left', [0.35, 0.35, 0.35]);
            if ($page === 1) {
                $c = $statement['company'];
                $y -= 22; $pdf->text($left, $y, $l['company'] . ':', 'R', 9); $pdf->text($left + 90, $y, $pdf->fit($c['legalName'], 300, 'B', 10), 'B', 10);
                $y -= 13; $pdf->text($left + 90, $y, $c['code'] . ' · ' . $l['tax'] . ': ' . $c['countryCode'] . ' ' . $c['taxIdentifier'], 'R', 9);
                $y -= 15; $pdf->text($left, $y, $l['currency'] . ':', 'R', 9); $pdf->text($left + 90, $y, $statement['currencyCode'], 'B', 9);
                $y -= 13; $pdf->text($left, $y, $l['period'] . ':', 'R', 9); $pdf->text($left + 90, $y, ($statement['from'] ?? $l['from_start']) . ' — ' . $statement['to'], 'R', 9);
                $y -= 13; $pdf->text($left, $y, $l['generated'] . ':', 'R', 9); $pdf->text($left + 90, $y, str_replace(['T', 'Z'], [' ', ' UTC'], $statement['generatedAt']), 'R', 9);
                $y -= 20; $pdf->fillRect($left, $y - 4, $right - $left, 16);
                $pdf->text($left + 4, $y, $l['opening'], 'B', 9.5);
                $pdf->text($right - 4, $y, $statement['openingBalance'] . ' ' . $statement['currencyCode'], 'B', 9.5, 'right');
                $y -= 10;
            }
            $y -= 18;
            foreach (['date', 'document', 'type', 'reference'] as $k) $pdf->text($cols[$k], $y, $l[$k], 'B', 8.5);
            foreach (['debit', 'credit', 'balance'] as $k) $pdf->text($cols[$k], $y, $l[$k], 'B', 8.5, 'right');
            $y -= 5; $pdf->line($left, $y, $right, $y, 0.7, 0.3); $y -= 12;
        };
        $header();
        if ($statement['movements'] === []) { $pdf->text($left, $y, $l['none'], 'R', 9); $y -= 14; }
        foreach ($statement['movements'] as $m) {
            if ($y < 90) $header();
            $pdf->text($cols['date'], $y, $m['valueDate'], 'R', 8.5);
            $pdf->text($cols['document'], $y, $m['code'], 'R', 8.5);
            $pdf->text($cols['type'], $y, $pdf->fit($l['types'][$m['type']], 82, 'R', 8.5), 'R', 8.5);
            $pdf->text($cols['reference'], $y, $pdf->fit(self::reference($m, $l), $cols['debit'] - 60 - $cols['reference'], 'R', 8.5), 'R', 8.5);
            if ($m['debit'] !== null) $pdf->text($cols['debit'], $y, $m['debit'], 'R', 8.5, 'right');
            if ($m['credit'] !== null) $pdf->text($cols['credit'], $y, $m['credit'], 'R', 8.5, 'right');
            $pdf->text($cols['balance'], $y, $m['runningBalance'], 'R', 8.5, 'right');
            $y -= 4; $pdf->line($left, $y, $right, $y, 0.3, 0.85); $y -= 11;
        }
        if ($y < 110) $header();
        $y -= 4; $pdf->line($left, $y + 10, $right, $y + 10, 0.7, 0.3);
        $pdf->text($cols['reference'], $y, $l['totals'], 'B', 9);
        $pdf->text($cols['debit'], $y, $statement['totals']['debit'], 'B', 9, 'right');
        $pdf->text($cols['credit'], $y, $statement['totals']['credit'], 'B', 9, 'right');
        $y -= 22; $pdf->fillRect($left, $y - 4, $right - $left, 16);
        $pdf->text($left + 4, $y, $l['closing'], 'B', 9.5);
        $pdf->text($right - 4, $y, $statement['closingBalance'] . ' ' . $statement['currencyCode'], 'B', 9.5, 'right');
        $y -= 22; $pdf->text($left, $y, $l['meaning'], 'R', 7.5, 'left', [0.35, 0.35, 0.35]);
        return $pdf->output();
    }

    /** @param array<string, mixed> $l */
    private static function reference(array $m, array $l): string
    {
        $parts = [];
        if ($m['orderCode'] !== null) $parts[] = $m['orderCode'];
        if ($m['method'] !== null) $parts[] = $l['methods'][$m['method']];
        if ($m['externalReference'] !== null) $parts[] = $m['externalReference'];
        if ($m['reversesCode'] !== null) $parts[] = $l['reverses'] . ' ' . $m['reversesCode'];
        if ($m['reasonCode'] === 'order_cancelled') $parts[] = $l['order_cancelled'];
        if ($m['reversedByCode'] !== null) $parts[] = $l['reversed'] . ' ' . $m['reversedByCode'];
        return implode(' · ', $parts);
    }

    /** Quotes every cell and neutralizes spreadsheet formulas in text (never in our numeric amounts). */
    private static function csvCell(mixed $value): string
    {
        $text = (string) $value;
        if ($text !== '' && preg_match('/^-?\d+\.\d{2}$/D', $text) !== 1 && str_contains('=+-@', $text[0])) $text = "'" . $text;
        if ($text !== '' && ($text[0] === "\t" || $text[0] === "\r")) $text = "'" . $text;
        return '"' . str_replace('"', '""', $text) . '"';
    }
}
