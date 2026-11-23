<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/** Reads the first sheet of an .xlsx file into rows of strings (no extra package: an .xlsx is a zip of XML). */
class XlsxReader
{
    /** @return array<int, list<string>> rows keyed by their real row number minus 1, cells by column position (A = 0) */
    public static function rows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not a valid .xlsx file.');
        }
        $strings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ((new SimpleXMLElement($xml))->si as $si) {
                $strings[] = self::text($si);
            }
        }
        $sheetName = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($sheetName) === false) {
            $sheetName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
                    $sheetName = $n;
                    break;
                }
            }
        }
        $sheet = $sheetName ? $zip->getFromName($sheetName) : false;
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('No sheet found in this .xlsx file.');
        }

        $rows = [];
        foreach ((new SimpleXMLElement($sheet))->sheetData->row ?? [] as $row) {
            $out = [];
            foreach ($row->c as $c) {
                $col = self::column((string) $c['r']);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    default => (string) $c->v,
                };
                if ($type === '' && preg_match('/^-?\d+(\.\d+)?[eE]\+?\d+$/', $value)) {
                    $value = sprintf('%.0f', (float) $value); // 6.7E+13 -> 67000000000000
                }
                $out[$col] = $value;
            }
            $line = [];
            for ($i = 0, $width = $out ? max(array_keys($out)) + 1 : 0; $i < $width; $i++) {
                $line[$i] = $out[$i] ?? '';
            }
            $rows[(int) $row['r'] - 1] = $line;
        }
        return $rows;
    }

    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r ?? [] as $run) {
            $s .= (string) $run->t;
        }
        return $s;
    }

    private static function column(string $ref): int
    {
        preg_match('/^[A-Z]+/', $ref, $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $n = $n * 26 + ord($ch) - 64;
        }
        return $n - 1;
    }
}
