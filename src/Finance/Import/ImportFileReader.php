<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Import;

use Arasya\Operations\Http\ApiException;
use XMLReader;
use ZipArchive;

/**
 * Reads an untrusted CSV or XLSX upload into a header row and bounded data rows. Nothing is executed or evaluated:
 * formulas are never computed (only a cached value is read), macros and embedded objects are refused, XML with a
 * DOCTYPE is refused (no entity expansion), ZIP archives are checked for entry count, path traversal and expansion
 * ratio before anything is read, and every limit is enforced while streaming. The raw file is never kept.
 */
final class ImportFileReader
{
    public const MAX_BYTES = 4_194_304;
    public const MAX_ROWS = 20_000;
    public const MAX_COLUMNS = 60;
    public const MAX_CELL = 1_000;
    public const ENCODINGS = ['utf-8', 'windows-1250', 'windows-1252', 'iso-8859-2'];

    private const ZIP_MAX_ENTRIES = 400;
    private const ZIP_MAX_UNCOMPRESSED = 50_000_000;
    private const ZIP_MAX_RATIO = 150;
    private const XML_MAX_SHARED_STRINGS = 400_000;

    /** @return array{format: string, headers: list<string>, rows: list<list<string>>} */
    public static function read(string $body, string $format, string $encoding = 'utf-8'): array
    {
        if ($body === '') {
            throw self::refused('FILE_EMPTY', 'The file is empty.');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new ApiException(413, 'FILE_TOO_LARGE', 'The file exceeds the maximum import size.', ['maxBytes' => self::MAX_BYTES]);
        }
        $isZip = str_starts_with($body, "PK\x03\x04");
        if ($format === 'xlsx') {
            if (!$isZip) {
                throw self::refused('FILE_TYPE_MISMATCH', 'The file is not an XLSX workbook.');
            }
            $table = self::xlsx($body);
        } elseif ($format === 'csv') {
            if ($isZip || str_contains($body, "\0") || str_starts_with($body, "\xD0\xCF\x11\xE0") || str_starts_with($body, '%PDF')) {
                throw self::refused('FILE_TYPE_MISMATCH', 'The file is not a text CSV file.');
            }
            $table = self::csv(self::utf8($body, $encoding));
        } else {
            throw self::refused('FILE_TYPE_UNSUPPORTED', 'Only CSV and XLSX files are supported.');
        }
        return ['format' => $format] + self::shape($table);
    }

    /** @param list<list<string>> $table @return array{headers: list<string>, rows: list<list<string>>} */
    private static function shape(array $table): array
    {
        $headers = null;
        $rows = [];
        foreach ($table as $row) {
            $row = array_map(self::cell(...), $row);
            while ($row !== [] && end($row) === '') {
                array_pop($row);
            }
            if ($row === []) {
                continue;
            }
            if ($headers === null) {
                $headers = $row;
                if (count($headers) > self::MAX_COLUMNS) {
                    throw self::refused('TOO_MANY_COLUMNS', 'The file has too many columns.', ['maxColumns' => self::MAX_COLUMNS]);
                }
                continue;
            }
            if (count($row) > self::MAX_COLUMNS) {
                throw self::refused('TOO_MANY_COLUMNS', 'The file has too many columns.', ['maxColumns' => self::MAX_COLUMNS]);
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw self::refused('TOO_MANY_ROWS', 'The file has too many rows.', ['maxRows' => self::MAX_ROWS]);
            }
        }
        if ($headers === null || $rows === []) {
            throw self::refused('FILE_EMPTY', 'The file contains no data rows under a header row.');
        }
        foreach ($headers as $i => $header) {
            $headers[$i] = $header === '' ? 'Column ' . ($i + 1) : mb_substr($header, 0, 120);
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function cell(mixed $value): string
    {
        $value = (string) $value;
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value);
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        return mb_strlen($value) > self::MAX_CELL ? mb_substr($value, 0, self::MAX_CELL) : $value;
    }

    private static function utf8(string $body, string $encoding): string
    {
        if (!in_array($encoding, self::ENCODINGS, true)) {
            throw self::refused('ENCODING_UNSUPPORTED', 'The encoding is not supported.');
        }
        if (str_starts_with($body, "\xFF\xFE") || str_starts_with($body, "\xFE\xFF")) {
            throw self::refused('ENCODING_UNSUPPORTED', 'UTF-16 files are not supported. Save the file as UTF-8 CSV.');
        }
        if ($encoding === 'utf-8') {
            if (str_starts_with($body, "\xEF\xBB\xBF")) {
                $body = substr($body, 3);
            }
            if (!mb_check_encoding($body, 'UTF-8')) {
                throw self::refused('INVALID_ENCODING', 'The file is not valid UTF-8. Choose its original encoding.');
            }
            return $body;
        }
        $converted = @iconv($encoding, 'UTF-8', $body);
        if (!is_string($converted) || !mb_check_encoding($converted, 'UTF-8')) {
            throw self::refused('INVALID_ENCODING', 'The file could not be read in the chosen encoding.');
        }
        return $converted;
    }

    /** @return list<list<string>> */
    private static function csv(string $text): array
    {
        $firstLine = strtok($text, "\n") ?: '';
        $delimiter = ',';
        $best = -1;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Memory stream unavailable.');
        }
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        try {
            while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $rows[] = array_map(static fn ($v): string => (string) $v, $row);
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw self::refused('TOO_MANY_ROWS', 'The file has too many rows.', ['maxRows' => self::MAX_ROWS]);
                }
            }
        } finally {
            fclose($stream);
        }
        return $rows;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $body): array
    {
        if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class)) {
            throw new ApiException(503, 'XLSX_UNAVAILABLE', 'XLSX import is not available on this server. Use CSV.');
        }
        $path = tempnam(sys_get_temp_dir(), 'afx');
        if ($path === false) {
            throw new \RuntimeException('Temporary file unavailable.');
        }
        try {
            if (file_put_contents($path, $body) !== strlen($body)) {
                throw new \RuntimeException('Temporary file write failed.');
            }
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw self::refused('MALFORMED_WORKBOOK', 'The workbook could not be opened.');
            }
            try {
                return self::workbook($zip, $path);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /** @return list<list<string>> */
    private static function workbook(ZipArchive $zip, string $path): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::ZIP_MAX_ENTRIES) {
            throw self::refused('MALFORMED_WORKBOOK', 'The workbook has an unexpected structure.');
        }
        $total = 0;
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw self::refused('MALFORMED_WORKBOOK', 'The workbook has an unexpected structure.');
            }
            $name = (string) $stat['name'];
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, "\0")) {
                throw self::refused('MALFORMED_WORKBOOK', 'The workbook contains an unsafe entry name.');
            }
            $lower = strtolower($name);
            if (str_contains($lower, 'vbaproject') || str_starts_with($lower, 'xl/embeddings/') || str_starts_with($lower, 'xl/activex/') || str_ends_with($lower, '.bin') || str_ends_with($lower, '.exe') || str_ends_with($lower, '.dll')) {
                throw self::refused('EMBEDDED_CONTENT', 'The workbook contains macros or embedded objects and is refused.');
            }
            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);
            $total += $size;
            if ($total > self::ZIP_MAX_UNCOMPRESSED || ($size > 1_000_000 && $size / $compressed > self::ZIP_MAX_RATIO)) {
                throw self::refused('ARCHIVE_TOO_LARGE', 'The workbook expands beyond the allowed size.');
            }
            $names[$name] = $size;
        }
        $types = self::entry($zip, '[Content_Types].xml', 1_000_000);
        if ($types === null || stripos($types, 'macroEnabled') !== false) {
            throw self::refused('EMBEDDED_CONTENT', 'Macro-enabled workbooks are refused.');
        }
        $sheet = self::firstSheet($zip);
        if (!isset($names[$sheet])) {
            throw self::refused('MALFORMED_WORKBOOK', 'The first worksheet is missing.');
        }
        $shared = isset($names['xl/sharedStrings.xml']) ? self::sharedStrings($zip) : [];
        return self::sheetRows($path, $sheet, $shared);
    }

    private static function entry(ZipArchive $zip, string $name, int $max): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > $max) {
            throw self::refused('ARCHIVE_TOO_LARGE', 'The workbook expands beyond the allowed size.');
        }
        $content = $zip->getFromName($name);
        if (!is_string($content)) {
            throw self::refused('MALFORMED_WORKBOOK', 'The workbook could not be read.');
        }
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            throw self::refused('MALFORMED_WORKBOOK', 'The workbook contains a document type declaration.');
        }
        return $content;
    }

    private static function firstSheet(ZipArchive $zip): string
    {
        $workbook = self::entry($zip, 'xl/workbook.xml', 2_000_000);
        $rels = self::entry($zip, 'xl/_rels/workbook.xml.rels', 2_000_000);
        if ($workbook === null || $rels === null) {
            throw self::refused('MALFORMED_WORKBOOK', 'The workbook structure is incomplete.');
        }
        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]{1,64})"/', $workbook, $m) !== 1) {
            throw self::refused('MALFORMED_WORKBOOK', 'The workbook has no worksheet.');
        }
        if (preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*\bTarget="([^"]{1,200})"/', $rels, $t) !== 1
            && preg_match('/<Relationship\b[^>]*\bTarget="([^"]{1,200})"[^>]*\bId="' . preg_quote($m[1], '/') . '"/', $rels, $t) !== 1) {
            throw self::refused('MALFORMED_WORKBOOK', 'The worksheet relationship is missing.');
        }
        $target = ltrim($t[1], '/');
        $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        if (preg_match('#^xl/worksheets/[A-Za-z0-9_]{1,40}\.xml$#D', $target) !== 1) {
            throw self::refused('MALFORMED_WORKBOOK', 'The worksheet path is unexpected.');
        }
        return $target;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::entry($zip, 'xl/sharedStrings.xml', 30_000_000);
        $reader = new XMLReader();
        if (!$reader->XML((string) $xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw self::refused('MALFORMED_WORKBOOK', 'Shared strings could not be read.');
        }
        $strings = [];
        $current = null;
        $inText = false;
        $inPhonetic = false;
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw self::refused('MALFORMED_WORKBOOK', 'The workbook contains a document type declaration.');
            }
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'si') {
                    $current = '';
                } elseif ($reader->localName === 'rPh') {
                    $inPhonetic = !$reader->isEmptyElement;
                } elseif ($reader->localName === 't' && $current !== null && !$inPhonetic) {
                    $inText = !$reader->isEmptyElement;
                }
            } elseif (($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::SIGNIFICANT_WHITESPACE) && $inText && $current !== null) {
                if (strlen($current) < self::MAX_CELL * 4) {
                    $current .= $reader->value;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 't') {
                    $inText = false;
                } elseif ($reader->localName === 'rPh') {
                    $inPhonetic = false;
                } elseif ($reader->localName === 'si' && $current !== null) {
                    $strings[] = $current;
                    $current = null;
                    if (count($strings) > self::XML_MAX_SHARED_STRINGS) {
                        throw self::refused('ARCHIVE_TOO_LARGE', 'The workbook has too many strings.');
                    }
                }
            }
        }
        $reader->close();
        return $strings;
    }

    /** @param list<string> $shared @return list<list<string>> */
    private static function sheetRows(string $path, string $sheet, array $shared): array
    {
        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $sheet, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw self::refused('MALFORMED_WORKBOOK', 'The worksheet could not be read.');
        }
        $rows = [];
        $row = null;
        $column = 0;
        $type = '';
        $value = null;
        $capture = false;
        try {
            while (@$reader->read()) {
                $node = $reader->nodeType;
                if ($node === XMLReader::DOC_TYPE) {
                    throw self::refused('MALFORMED_WORKBOOK', 'The workbook contains a document type declaration.');
                }
                if ($node === XMLReader::ELEMENT) {
                    switch ($reader->localName) {
                        case 'row':
                            $row = [];
                            if ($reader->isEmptyElement) {
                                $rows[] = [];
                                $row = null;
                            }
                            break;
                        case 'c':
                            $reference = (string) $reader->getAttribute('r');
                            $column = $reference !== '' ? self::columnIndex($reference) : ($row === null ? 0 : count($row));
                            $type = (string) $reader->getAttribute('t');
                            $value = $reader->isEmptyElement ? '' : null;
                            if ($reader->isEmptyElement && $row !== null) {
                                self::put($row, $column, '');
                            }
                            break;
                        case 'v':
                        case 't':
                            $capture = !$reader->isEmptyElement;
                            if ($value === null) {
                                $value = '';
                            }
                            break;
                    }
                } elseif (($node === XMLReader::TEXT || $node === XMLReader::SIGNIFICANT_WHITESPACE || $node === XMLReader::CDATA) && $capture) {
                    if (strlen((string) $value) < self::MAX_CELL * 4) {
                        $value .= $reader->value;
                    }
                } elseif ($node === XMLReader::END_ELEMENT) {
                    switch ($reader->localName) {
                        case 'v':
                        case 't':
                            $capture = false;
                            break;
                        case 'c':
                            if ($row !== null) {
                                $text = (string) $value;
                                if ($type === 's') {
                                    $index = ctype_digit($text) ? (int) $text : -1;
                                    $text = $shared[$index] ?? '';
                                } elseif ($type === 'b') {
                                    $text = $text === '1' ? 'TRUE' : 'FALSE';
                                } elseif ($type === 'e') {
                                    $text = '';
                                }
                                self::put($row, $column, $text);
                            }
                            $value = null;
                            break;
                        case 'row':
                            if ($row !== null) {
                                $rows[] = $row;
                                if (count($rows) > self::MAX_ROWS + 1) {
                                    throw self::refused('TOO_MANY_ROWS', 'The file has too many rows.', ['maxRows' => self::MAX_ROWS]);
                                }
                            }
                            $row = null;
                            break;
                    }
                }
            }
        } finally {
            $reader->close();
        }
        return $rows;
    }

    /** @param array<int, string> $row */
    private static function put(array &$row, int $column, string $text): void
    {
        if ($column >= self::MAX_COLUMNS) {
            if ($text !== '') {
                throw self::refused('TOO_MANY_COLUMNS', 'The file has too many columns.', ['maxColumns' => self::MAX_COLUMNS]);
            }
            return;
        }
        for ($i = count($row); $i < $column; $i++) {
            $row[$i] = '';
        }
        $row[$column] = $text;
    }

    private static function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]{1,3})\d+$/D', $reference, $m) !== 1) {
            throw self::refused('MALFORMED_WORKBOOK', 'A cell reference is invalid.');
        }
        $index = 0;
        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return $index - 1;
    }

    /** @param array<string, mixed> $details */
    private static function refused(string $code, string $message, array $details = []): ApiException
    {
        return new ApiException(422, $code, $message, $details);
    }
}
