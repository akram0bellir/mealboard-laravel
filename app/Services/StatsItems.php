<?php

namespace App\Services;

use App\Support\Days;
use App\Support\Roles;

/**
 * The statistics engine (port of the React lib/stats.ts): every section and the Explore tool work on the same
 * "meal items" (one per booked meal), so all numbers on the page agree.
 *
 * An item: ['date', 'user', 'dept', 'sex' (male|female), 'position', 'type' (User_Type), 'meal', 'dish', 'missed' (bool),
 * 'lead' (days between booking and the meal)].
 */
class StatsItems
{
    /** Smallest group shown for sensitive splits (sex, position), so nobody can be singled out. */
    public const PRIVACY_MIN_PEOPLE = 3;

    /** Below this many meals a value is shown but marked as "small sample". */
    public const SMALL_SAMPLE_MEALS = 10;

    public const DEPARTMENTS = ['1', '2', '3'];

    /** key => [label, goodWhenHigh, unit]. */
    public const METRICS = [
        'meals' => ['Meals booked', null, 'count'],
        'people' => ['People who booked', true, 'count'],
        'perPerson' => ['Meals per person', null, 'decimal'],
        'attendance' => ['Attendance rate', true, 'percent'],
        'noShowRate' => ['No-show rate', false, 'percent'],
        'noShows' => ['No-shows (meals)', false, 'count'],
        'cost' => ['Cost', null, 'money'],
        'waste' => ['Money wasted on no-shows', false, 'money'],
        'leadTime' => ['Days booked ahead (average)', true, 'decimal'],
        'lastMinute' => ['Booked last-minute (≤1 day)', false, 'percent'],
    ];

    /** key => [label, ordered, sensitive]. Ordered splits keep their natural order; the others are sorted by value. */
    public const DIMENSIONS = [
        'department' => ['Department', false, false],
        'sex' => ['Sex', false, true],
        'position' => ['Position', false, true],
        'userType' => ['User type', false, false],
        'meal' => ['Meal', true, false],
        'dish' => ['Dish', false, false],
        'weekday' => ['Weekday', true, false],
        'week' => ['Week', true, false],
        'month' => ['Month', true, false],
        'leadBand' => ['How early they booked', true, false],
    ];

    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    private const LEAD_BANDS = ['Same day', '1 day', '2–3 days', '4–7 days', '8+ days'];
    private const MEAL_LABEL = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
    private const ROLE_LABEL = ['employee' => 'Employee', 'responsible' => 'Responsible', 'intern' => 'Intern', 'manager' => 'Manager', 'admin' => 'Admin', 'super_admin' => 'Super admin', 'door' => 'Door'];

    public static function isStaff(?string $type): bool
    {
        return in_array(Roles::normalize($type), ['admin', 'super_admin', 'door'], true);
    }

    public static function ratio(float $a, float $b): float
    {
        return $b ? $a / $b : 0.0;
    }

    public static function missedCount(array $items): int
    {
        return count(array_filter($items, fn ($i) => $i['missed']));
    }

    public static function people(array $items): int
    {
        return count(array_unique(array_column($items, 'user')));
    }

    /** Days between the booking and the meal. */
    public static function leadDays(string $mealDate, string $created): int
    {
        if ($created === '') {
            return 0;
        }
        return max(0, (int) round((strtotime(substr($mealDate, 0, 10) . ' UTC') - strtotime(substr($created, 0, 10) . ' UTC')) / 86400));
    }

    /* ------------------------------------------------------------------ */
    /* Measures                                                            */
    /* ------------------------------------------------------------------ */

    public static function metricLabel(string $key): string
    {
        return t(self::METRICS[$key][0]);
    }

    /** @param array{breakfast: float, lunch: float, dinner: float} $prices */
    public static function compute(string $key, array $items, array $prices): float
    {
        $n = count($items);
        return match ($key) {
            'meals' => (float) $n,
            'people' => (float) self::people($items),
            'perPerson' => self::ratio($n, self::people($items)),
            'attendance' => self::ratio($n - self::missedCount($items), $n),
            'noShowRate' => self::ratio(self::missedCount($items), $n),
            'noShows' => (float) self::missedCount($items),
            'cost' => array_sum(array_map(fn ($i) => $prices[$i['meal']], $items)),
            'waste' => array_sum(array_map(fn ($i) => $i['missed'] ? $prices[$i['meal']] : 0, $items)),
            'leadTime' => self::ratio(array_sum(array_column($items, 'lead')), $n),
            'lastMinute' => self::ratio(count(array_filter($items, fn ($i) => $i['lead'] <= 1)), $n),
        };
    }

    public static function format(float $value, string $unit, string $currency): string
    {
        return match ($unit) {
            'percent' => round($value * 100) . '%',
            'money' => number_format($value, 0, '.', ',') . ' ' . $currency,
            'decimal', 'days' => number_format($value, 1, '.', ''),
            default => (string) round($value),
        };
    }

    /* ------------------------------------------------------------------ */
    /* Ways to split                                                       */
    /* ------------------------------------------------------------------ */

    public static function dimLabel(string $key): string
    {
        return t(self::DIMENSIONS[$key][0]);
    }

    private static function weekdayIndex(string $date): int
    {
        return Days::fromKey($date)->dayOfWeekIso - 1;
    }

    private static function leadBand(int $d): int
    {
        return $d <= 0 ? 0 : ($d === 1 ? 1 : ($d <= 3 ? 2 : ($d <= 7 ? 3 : 4)));
    }

    /** Group key of an item for a dimension (a string, so groups sort like the React ones). */
    public static function dimOf(string $dim, array $i): string
    {
        return match ($dim) {
            'department' => $i['dept'],
            'sex' => $i['sex'],
            'position' => mb_strtolower($i['position']),
            'userType' => Roles::normalize($i['type']),
            'meal' => $i['meal'],
            'dish' => $i['dish'],
            'weekday' => (string) self::weekdayIndex($i['date']),
            'week' => Days::key(Days::mondayOf(Days::fromKey($i['date']))),
            'month' => substr($i['date'], 0, 7),
            'leadBand' => (string) self::leadBand($i['lead']),
        };
    }

    /** Readable label of a group key. */
    public static function dimName(string $dim, string $key): string
    {
        return match ($dim) {
            'department' => $key !== '' ? t('Department {0}', [0 => $key]) : t('No department'),
            'sex' => $key === 'female' ? t('Women') : t('Men'),
            'position' => $key !== '' ? mb_strtoupper(mb_substr($key, 0, 1)) . mb_substr($key, 1) : t('No position'),
            'userType' => t(self::ROLE_LABEL[Roles::normalize($key)]),
            'meal' => t(self::MEAL_LABEL[$key] ?? $key),
            'dish' => $key !== '' ? $key : t('No dish on the menu'),
            'weekday' => t(self::WEEKDAYS[(int) $key] ?? ''),
            'week' => t('Week of {0}', [0 => substr($key, 8, 2) . '/' . substr($key, 5, 2)]),
            'month' => Days::fromKey($key . '-01')->translatedFormat('F Y'),
            'leadBand' => t(self::LEAD_BANDS[(int) $key] ?? ''),
        };
    }

    /**
     * Splits items by a dimension and measures each group.
     *
     * @return array<int, array{key: string, name: string, value: float, meals: int, people: int, hidden: bool, small: bool}>
     */
    public static function splitBy(array $items, string $dim, string $metric, array $prices): array
    {
        [, $ordered, $sensitive] = self::DIMENSIONS[$dim];
        $groups = [];
        foreach ($items as $i) {
            $groups[self::dimOf($dim, $i)][] = $i;
        }
        $rows = [];
        foreach ($groups as $key => $list) {
            $key = (string) $key;
            $p = self::people($list);
            $rows[] = [
                'key' => $key,
                'name' => self::dimName($dim, $key),
                'value' => self::compute($metric, $list, $prices),
                'meals' => count($list),
                'people' => $p,
                'hidden' => $sensitive && $p < self::PRIVACY_MIN_PEOPLE,
                'small' => count($list) < self::SMALL_SAMPLE_MEALS,
            ];
        }
        $ordered
            ? usort($rows, fn ($a, $b) => strnatcmp($a['key'], $b['key']))
            : usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        return $rows;
    }

    /** Distinct values of a dimension in the data (for the "A vs B" pickers). */
    public static function valuesOf(array $items, string $dim): array
    {
        $keys = array_values(array_unique(array_map(fn ($i) => self::dimOf($dim, $i), $items)));
        sort($keys, SORT_STRING);
        return array_map(fn ($k) => ['key' => (string) $k, 'name' => self::dimName($dim, (string) $k)], $keys);
    }
}
