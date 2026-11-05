<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Day keys ("2026-11-05") and local dates. Everything runs on the canteen's
 * time zone (config app.timezone), like the React app and the PHP API.
 */
class Days
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function fromKey(string $key): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $key, config('app.timezone'))->startOfDay();
    }

    public static function key(CarbonImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    public static function add(string $key, int $days): string
    {
        return self::fromKey($key)->addDays($days)->format('Y-m-d');
    }

    /** Monday = 0 ... Sunday = 6. */
    public static function mondayIndex(CarbonImmutable $date): int
    {
        return $date->dayOfWeekIso - 1;
    }

    public static function mondayOf(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay()->subDays(self::mondayIndex($date));
    }

    /** The day part of a stored datetime ("2026-11-05 12:00:00.000" -> "2026-11-05"). */
    public static function keyOf(mixed $value): string
    {
        $s = (string) ($value ?? '');
        return strlen($s) >= 10 ? substr($s, 0, 10) : '';
    }

    /** A day as it is stored: noon, so it stays the same day in every time zone. */
    public static function toDb(string $key): string
    {
        return $key . ' 12:00:00.000';
    }

    /** "Thu, Nov 5" (en) or "jeu. 5 nov." (fr, ar), weekday short or long. */
    public static function format(string $key, bool $long = false): string
    {
        $date = self::fromKey($key);
        $weekday = $long ? 'l' : 'D';
        return app()->getLocale() === 'en' ? $date->translatedFormat("{$weekday}, M j") : $date->translatedFormat("{$weekday} j M");
    }

    /** "Nov 5" (en) or "5 nov." — a short date without the weekday. */
    public static function short(CarbonImmutable $date): string
    {
        return app()->getLocale() === 'en' ? $date->translatedFormat('M j') : $date->translatedFormat('j M');
    }

    /** Keeps a left-to-right piece (e.g. "12:30–13:30") in order inside Arabic text. */
    public static function ltr(string $text): string
    {
        return "\u{2066}{$text}\u{2069}";
    }
}
