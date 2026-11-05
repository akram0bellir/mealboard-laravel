<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Failed-login lock on the existing `login_attempts` table: after 5 wrong tries
 * the e-mail/username + IP is blocked for 15 minutes (same as the PHP API).
 */
class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public static function key(string $login, ?string $ip): string
    {
        return mb_substr(strtolower(trim($login)) . '|' . ($ip ?? ''), 0, 190);
    }

    public static function isBlocked(string $key): bool
    {
        $row = DB::table('login_attempts')->where('attempt_key', $key)->first();
        if ($row === null || $row->blocked_until === null) {
            return false;
        }
        return strtotime($row->blocked_until . ' UTC') > time();
    }

    public static function recordFailure(string $key): void
    {
        $row = DB::table('login_attempts')->where('attempt_key', $key)->first();
        if ($row === null) {
            DB::table('login_attempts')->insert(['attempt_key' => $key, 'fails' => 1, 'first_at' => self::now(), 'blocked_until' => null]);
            return;
        }
        $fails = (int) $row->fails + 1;
        if ($fails >= self::MAX_ATTEMPTS) {
            $until = gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) . '.000';
            DB::table('login_attempts')->where('attempt_key', $key)->update(['fails' => 0, 'first_at' => self::now(), 'blocked_until' => $until]);
            return;
        }
        DB::table('login_attempts')->where('attempt_key', $key)->update(['fails' => $fails]);
    }

    public static function clear(string $key): void
    {
        DB::table('login_attempts')->where('attempt_key', $key)->delete();
    }

    /** UTC "Y-m-d H:i:s.mmm", the way every datetime is stored. */
    public static function now(): string
    {
        $t = microtime(true);
        return gmdate('Y-m-d H:i:s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000));
    }
}
