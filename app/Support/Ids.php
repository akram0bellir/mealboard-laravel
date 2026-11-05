<?php

namespace App\Support;

class Ids
{
    /** A 15-character id like the existing rows (lowercase letters and digits). */
    public static function new(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < 15; $i++) {
            $out .= $alphabet[random_int(0, 35)];
        }
        return $out;
    }

    /** Timestamp for created / updated: UTC with milliseconds. */
    public static function now(): string
    {
        $t = microtime(true);
        return gmdate('Y-m-d H:i:s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000));
    }
}
