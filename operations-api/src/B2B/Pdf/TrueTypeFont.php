<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B\Pdf;

use RuntimeException;

/**
 * Minimal TrueType reader for PDF embedding: Unicode -> glyph mapping (cmap format 4), advance widths and metrics,
 * and a glyph subset that keeps glyph ids stable (unused glyph outlines are emptied, so no remapping is needed).
 * Only what the statement PDF needs; it never executes font programs.
 */
final class TrueTypeFont
{
    public readonly int $unitsPerEm;
    public readonly int $ascent;
    public readonly int $descent;
    /** @var array{0:int,1:int,2:int,3:int} */
    public readonly array $bbox;
    public readonly string $name;
    private string $data;
    /** @var array<string, array{offset:int,length:int}> */
    private array $tables = [];
    /** @var array<int, int> */
    private array $cmap = [];
    /** @var list<int> */
    private array $advances = [];
    private int $numGlyphs;
    private int $locFormat;

    public function __construct(string $path, string $name)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12) throw new RuntimeException('Font file is missing.');
        $this->data = $data;
        $this->name = $name;
        $count = $this->u16(4);
        for ($i = 0; $i < $count; $i++) {
            $entry = 12 + 16 * $i;
            $this->tables[substr($data, $entry, 4)] = ['offset' => $this->u32($entry + 8), 'length' => $this->u32($entry + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) throw new RuntimeException("Font table {$required} is missing.");
        }
        $head = $this->tables['head']['offset'];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->s16($head + 36), $this->s16($head + 38), $this->s16($head + 40), $this->s16($head + 42)];
        $this->locFormat = $this->s16($head + 50);
        $hhea = $this->tables['hhea']['offset'];
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $metrics = $this->u16($hhea + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp']['offset'] + 4);
        $hmtx = $this->tables['hmtx']['offset'];
        $last = 0;
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            if ($g < $metrics) $last = $this->u16($hmtx + 4 * $g);
            $this->advances[] = $last;
        }
        $this->readCmap();
    }

    public function glyph(int $codepoint): int
    {
        return $this->cmap[$codepoint] ?? 0;
    }

    /** Advance width in 1/1000 em, as PDF widths expect. */
    public function width(int $glyph): int
    {
        return (int) round(($this->advances[$glyph] ?? 0) * 1000 / $this->unitsPerEm);
    }

    public function textWidth(string $text, float $size): float
    {
        $sum = 0;
        foreach (mb_str_split($text) as $char) $sum += $this->width($this->glyph(mb_ord($char)));
        return $sum * $size / 1000;
    }

    /** A valid TrueType file holding only the given glyphs' outlines (plus composite components and .notdef). @param list<int> $glyphs */
    public function subset(array $glyphs): string
    {
        $keep = [];
        $queue = [0, ...$glyphs];
        while ($queue !== []) {
            $g = array_pop($queue);
            if ($g < 0 || $g >= $this->numGlyphs || isset($keep[$g])) continue;
            $keep[$g] = true;
            foreach ($this->components($g) as $component) $queue[] = $component;
        }
        $glyf = ''; $loca = '';
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$g])) {
                [$start, $end] = $this->glyphRange($g);
                $outline = substr($this->data, $this->tables['glyf']['offset'] + $start, $end - $start);
                $glyf .= $outline . str_repeat("\0", (4 - strlen($outline) % 4) % 4);
            }
        }
        $loca .= pack('N', strlen($glyf));
        $head = $this->table('head');
        $head = substr_replace($head, pack('N', 0), 8, 4);   // checkSumAdjustment
        $head = substr_replace($head, pack('n', 1), 50, 2);  // long loca offsets
        $tables = ['head' => $head, 'hhea' => $this->table('hhea'), 'maxp' => $this->table('maxp'), 'hmtx' => $this->table('hmtx'), 'loca' => $loca, 'glyf' => $glyf];
        foreach (['cvt ', 'fpgm', 'prep'] as $optional) if (isset($this->tables[$optional])) $tables[$optional] = $this->table($optional);
        ksort($tables, SORT_STRING);
        $count = count($tables);
        $power = 1; $log = 0;
        while ($power * 2 <= $count) { $power *= 2; $log++; }
        $out = pack('Nnnnn', 0x00010000, $count, $power * 16, $log, $count * 16 - $power * 16);
        $offset = 12 + 16 * $count; $body = '';
        foreach ($tables as $tag => $content) {
            $out .= $tag . pack('NNN', self::checksum($content), $offset, strlen($content));
            $padded = $content . str_repeat("\0", (4 - strlen($content) % 4) % 4);
            $body .= $padded; $offset += strlen($padded);
        }
        return $out . $body;
    }

    /** @return list<int> */
    private function components(int $glyph): array
    {
        [$start, $end] = $this->glyphRange($glyph);
        if ($end - $start < 10) return [];
        $p = $this->tables['glyf']['offset'] + $start;
        if ($this->s16($p) >= 0) return [];
        $p += 10; $out = [];
        do {
            $flags = $this->u16($p); $out[] = $this->u16($p + 2); $p += 4;
            $p += ($flags & 0x0001) ? 4 : 2;
            if ($flags & 0x0008) $p += 2; elseif ($flags & 0x0040) $p += 4; elseif ($flags & 0x0080) $p += 8;
        } while ($flags & 0x0020);
        return $out;
    }

    /** @return array{0:int,1:int} */
    private function glyphRange(int $glyph): array
    {
        $loca = $this->tables['loca']['offset'];
        return $this->locFormat === 0
            ? [$this->u16($loca + 2 * $glyph) * 2, $this->u16($loca + 2 * $glyph + 2) * 2]
            : [$this->u32($loca + 4 * $glyph), $this->u32($loca + 4 * $glyph + 4)];
    }

    private function readCmap(): void
    {
        $base = $this->tables['cmap']['offset'];
        $count = $this->u16($base + 2);
        $chosen = null;
        for ($i = 0; $i < $count; $i++) {
            $platform = $this->u16($base + 4 + 8 * $i); $encoding = $this->u16($base + 6 + 8 * $i);
            $offset = $base + $this->u32($base + 8 + 8 * $i);
            if ($this->u16($offset) === 4 && ($platform === 3 && $encoding === 1 || $platform === 0)) { $chosen = $offset; break; }
        }
        if ($chosen === null) throw new RuntimeException('Font has no Unicode BMP cmap.');
        $segments = $this->u16($chosen + 6) / 2;
        $ends = $chosen + 14; $starts = $ends + 2 * $segments + 2; $deltas = $starts + 2 * $segments; $ranges = $deltas + 2 * $segments;
        for ($s = 0; $s < $segments; $s++) {
            $end = $this->u16($ends + 2 * $s); $start = $this->u16($starts + 2 * $s);
            $delta = $this->s16($deltas + 2 * $s); $rangeOffset = $this->u16($ranges + 2 * $s);
            if ($start === 0xFFFF) continue;
            for ($c = $start; $c <= $end; $c++) {
                if ($rangeOffset === 0) { $g = ($c + $delta) & 0xFFFF; }
                else {
                    $g = $this->u16($ranges + 2 * $s + $rangeOffset + 2 * ($c - $start));
                    if ($g !== 0) $g = ($g + $delta) & 0xFFFF;
                }
                if ($g !== 0) $this->cmap[$c] = $g;
            }
        }
    }

    private function table(string $tag): string
    {
        return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) as $word) $sum = ($sum + $word) & 0xFFFFFFFF;
        return $sum;
    }

    private function u16(int $o): int { return unpack('n', $this->data, $o)[1]; }
    private function s16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $o): int { return unpack('N', $this->data, $o)[1]; }
}
