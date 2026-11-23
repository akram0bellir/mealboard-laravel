<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a table file into rows of strings, whatever its type:
 *   .xlsx .xlsm  Excel (first sheet)      .ods  OpenDocument spreadsheet (first sheet)
 *   .csv .tsv .txt  delimited text (delimiter and encoding are detected)
 * Result: rows keyed by their real row number minus 1, each a list of cells by column position.
 */
class TableReader
{
    public const EXTENSIONS = ['xlsx', 'xlsm', 'ods', 'csv', 'tsv', 'txt'];

    /** For an <input accept="..."> attribute. */
    public const ACCEPT = '.xlsx,.xlsm,.ods,.csv,.tsv,.txt';

    public static function supports(?string $filename): bool
    {
        return in_array(self::extension($filename), self::EXTENSIONS, true);
    }

    public static function extension(?string $filename): string
    {
        return strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    }

    /** @return array<int, list<string>> */
    public static function rows(string $path, ?string $filename = null): array
    {
        return match (self::extension($filename ?? $path)) {
            'xlsx', 'xlsm' => XlsxReader::rows($path),
            'ods' => self::ods($path),
            'csv', 'tsv', 'txt' => self::delimited($path),
            'xls' => throw new RuntimeException('Old .xls files are not supported: save it as .xlsx or .csv first.'),
            default => throw new RuntimeException('Use one of: .' . implode(', .', self::EXTENSIONS) . '.'),
        };
    }

    /** The first non-empty line of a text file, for telling one text format from another. */
    public static function firstLine(string $path): string
    {
        $text = self::decode((string) @file_get_contents($path, false, null, 0, 65536));
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) !== '') {
                return $line;
            }
        }
        return '';
    }

    private static function delimited(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read the file.');
        }
        $text = self::decode($raw);
        $head = strtok($text, "\r\n") ?: '';
        $best = ',';
        $most = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($head, $d);
            if ($n > $most) {
                [$best, $most] = [$d, $n];
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        $i = 0;
        while (($cells = fgetcsv($stream, 0, $best, '"', '')) !== false) {
            if ($cells === [null]) {
                $i++;
                continue; // blank line
            }
            $rows[$i++] = array_map(fn ($c) => (string) $c, $cells);
        }
        fclose($stream);
        return $rows;
    }

    /** UTF-8 text without a BOM; Windows-1252 files (a common Excel "CSV") are converted. */
    private static function decode(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }
        return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    private static function ods(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not a valid .ods file.');
        }
        $content = $zip->getFromName('content.xml');
        $zip->close();
        if ($content === false) {
            throw new RuntimeException('No sheet found in this .ods file.');
        }

        $xml = new SimpleXMLElement($content);
        $ns = $xml->getNamespaces(true);
        $tableNs = $ns['table'] ?? '';
        $textNs = $ns['text'] ?? '';
        $officeNs = $ns['office'] ?? '';
        $spreadsheet = $xml->children($officeNs)->body->spreadsheet ?? null;
        $table = $spreadsheet ? ($spreadsheet->children($tableNs)->table[0] ?? null) : null;
        if ($table === null) {
            throw new RuntimeException('No sheet found in this .ods file.');
        }

        $rows = [];
        $r = 0;
        foreach ($table->children($tableNs) as $name => $row) {
            if ($name === 'table-row') {
                $repeat = (int) ($row->attributes($tableNs)['number-rows-repeated'] ?? 1);
                $cells = [];
                foreach ($row->children($tableNs) as $cname => $cell) {
                    if ($cname !== 'table-cell' && $cname !== 'covered-table-cell') {
                        continue;
                    }
                    $cellRepeat = min(50, (int) ($cell->attributes($tableNs)['number-columns-repeated'] ?? 1));
                    $office = $cell->attributes($officeNs);
                    $value = (string) ($office['date-value'] ?? $office['value'] ?? '');
                    if ($value === '') {
                        $parts = [];
                        foreach ($cell->children($textNs)->p ?? [] as $p) {
                            $parts[] = (string) $p;
                        }
                        $value = implode("\n", $parts);
                    }
                    for ($k = 0; $k < $cellRepeat; $k++) {
                        $cells[] = $value;
                    }
                }
                while ($cells && end($cells) === '') {
                    array_pop($cells);
                }
                if ($cells !== []) {
                    for ($k = 0; $k < min($repeat, 100); $k++) {
                        $rows[$r + $k] = $cells;
                    }
                }
                $r += $repeat;
            }
        }
        return $rows;
    }
}
