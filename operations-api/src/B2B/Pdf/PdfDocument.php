<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B\Pdf;

/**
 * A small dependency-free PDF 1.4 writer for A4 text documents. Text uses embedded, subsetted TrueType fonts as
 * Type0/CIDFontType2 (Identity-H) with a ToUnicode map, so Romanian and Turkish characters render and can be copied.
 * Coordinates are PDF points with the origin at the bottom-left corner.
 */
final class PdfDocument
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** @var array<string, TrueTypeFont> */
    private array $fonts;
    /** @var array<string, array<int, int>> glyph => codepoint, per font */
    private array $used = [];
    /** @var list<string> */
    private array $pages = [];
    private string $current = '';

    /** @param array<string, TrueTypeFont> $fonts keyed by a short resource name, e.g. ['R' => regular, 'B' => bold] */
    public function __construct(array $fonts, private readonly string $title)
    {
        $this->fonts = $fonts;
        foreach (array_keys($fonts) as $key) $this->used[$key] = [];
    }

    public function font(string $key): TrueTypeFont
    {
        return $this->fonts[$key];
    }

    public function addPage(): void
    {
        if ($this->current !== '') $this->pages[] = $this->current;
        $this->current = "0 0 0 rg 0 0 0 RG\n";
    }

    /** Draws text; align is 'left' or 'right' (x is then the right edge). */
    public function text(float $x, float $y, string $text, string $font = 'R', float $size = 9, string $align = 'left', ?array $rgb = null): void
    {
        $f = $this->fonts[$font];
        if ($align === 'right') $x -= $f->textWidth($text, $size);
        $hex = '';
        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);
            $glyph = $f->glyph($code);
            $this->used[$font][$glyph] = $code;
            $hex .= sprintf('%04X', $glyph);
        }
        $color = $rgb === null ? '' : sprintf('%.3F %.3F %.3F rg ', ...$rgb);
        $this->current .= sprintf("BT %s/F%s %.2F Tf %.2F %.2F Td <%s> Tj ET 0 0 0 rg\n", $color, $font, $size, $x, $y, $hex);
    }

    /** Shortens text with an ellipsis so it fits the given width. */
    public function fit(string $text, float $width, string $font = 'R', float $size = 9): string
    {
        $f = $this->fonts[$font];
        if ($f->textWidth($text, $size) <= $width) return $text;
        while ($text !== '' && $f->textWidth($text . '…', $size) > $width) $text = mb_substr($text, 0, -1);
        return $text . '…';
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.6): void
    {
        $this->current .= sprintf("%.3F G %.2F w %.2F %.2F m %.2F %.2F l S 0 G\n", $gray, $width, $x1, $y1, $x2, $y2);
    }

    public function fillRect(float $x, float $y, float $w, float $h, float $gray = 0.94): void
    {
        $this->current .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $y, $w, $h);
    }

    public function output(): string
    {
        if ($this->current !== '') { $this->pages[] = $this->current; $this->current = ''; }
        $objects = [];
        $add = static function (string $body) use (&$objects): int { $objects[] = $body; return count($objects); };
        $catalog = $add(''); $pagesId = $add('');
        $fontRefs = [];
        foreach ($this->fonts as $key => $font) $fontRefs[$key] = $this->embedFont($font, $this->used[$key], $add);
        $resources = '<< /Font << ' . implode(' ', array_map(static fn (string $k, int $id): string => "/F{$k} {$id} 0 R", array_keys($fontRefs), $fontRefs)) . ' >> >>';
        $kids = [];
        foreach ($this->pages as $content) {
            $stream = $add(self::stream($content));
            $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', $pagesId, self::WIDTH, self::HEIGHT, $resources, $stream));
        }
        $objects[$catalog - 1] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId - 1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(static fn (int $id): string => "{$id} 0 R", $kids)), count($kids));
        $info = $add('<< /Title ' . self::utf16($this->title) . ' /Producer (Arasya Operations API) >>');
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        return $pdf . sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $catalog, $info, $xref);
    }

    /** @param array<int, int> $used */
    private function embedFont(TrueTypeFont $font, array $used, callable $add): int
    {
        ksort($used);
        $glyphs = array_keys($used);
        $file = $font->subset($glyphs);
        $fileId = $add(self::stream($file, '/Length1 ' . strlen($file)));
        $tag = substr(strtoupper(hash('crc32b', $font->name . implode(',', $glyphs)) . 'AAAAAA'), 0, 6);
        $tag = strtr($tag, '0123456789', 'GHIJKLMNOP');
        $name = $tag . '+' . $font->name;
        $scale = 1000 / $font->unitsPerEm;
        [$x1, $y1, $x2, $y2] = array_map(static fn (int $v): int => (int) round($v * $scale), $font->bbox);
        $descriptor = $add(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $name, $x1, $y1, $x2, $y2, (int) round($font->ascent * $scale), (int) round($font->descent * $scale), (int) round($font->ascent * $scale), $fileId));
        $widths = implode(' ', array_map(static fn (int $g): string => "{$g} [" . $font->width($g) . ']', $glyphs));
        $cid = $add(sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /W [%s] /DW 500 /CIDToGIDMap /Identity >>',
            $name, $descriptor, $widths));
        $pairs = [];
        foreach ($used as $glyph => $code) $pairs[] = sprintf('<%04X> <%s>', $glyph, strtoupper(bin2hex(mb_convert_encoding(mb_chr($code), 'UTF-16BE', 'UTF-8'))));
        $ranges = '';
        foreach (array_chunk($pairs, 100) as $chunk) $ranges .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $ranges . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        $toUnicode = $add(self::stream($cmap));
        return $add(sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $name, $cid, $toUnicode));
    }

    private static function stream(string $data, string $extra = ''): string
    {
        $filter = '';
        if (function_exists('gzcompress')) {
            $data = (string) gzcompress($data, 6);
            $filter = ' /Filter /FlateDecode';
        }
        return sprintf("<< /Length %d%s %s >>\nstream\n%s\nendstream", strlen($data), $filter, $extra, $data);
    }

    private static function utf16(string $text): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
