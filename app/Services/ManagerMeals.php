<?php

namespace App\Services;

use App\Support\Days;
use Illuminate\Support\Facades\DB;

/**
 * Manager meals: a fixed number of meals booked automatically every day for the department directors
 * (no account, just portions). Table `manager_meals`: weekday (1 = Monday ... 7 = Sunday) + breakfast /
 * lunch / dinner amounts, or one exact date that wins over the weekday. A weekday without a row uses
 * DEFAULT_COUNT for every meal. Meals only count on days whose menu serves that meal.
 */
class ManagerMeals
{
    public const DEFAULT_COUNT = 20;

    /** @var array{weekly: array<int, array<string, int>>, days: array<string, array<string, int>>}|null */
    private static ?array $data = null;

    public static function reset(): void
    {
        self::$data = null;
    }

    /** @return array{weekly: array<int, array<string, int>>, days: array<string, array<string, int>>} */
    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $weekly = [];
        $days = [];
        foreach (DB::table('manager_meals')->get() as $row) {
            $counts = ['breakfast' => max(0, (int) $row->breakfast), 'lunch' => max(0, (int) $row->lunch), 'dinner' => max(0, (int) $row->dinner)];
            $date = Days::keyOf($row->date);
            if ($date !== '') {
                $days[$date] = $counts + ['id' => $row->id];
            } elseif ((int) $row->weekday >= 1 && (int) $row->weekday <= 7) {
                $weekly[(int) $row->weekday] = $counts + ['id' => $row->id];
            }
        }
        for ($w = 1; $w <= 7; $w++) {
            $weekly[$w] ??= self::defaults();
        }
        return self::$data = ['weekly' => $weekly, 'days' => $days];
    }

    public static function defaults(): array
    {
        return ['breakfast' => self::DEFAULT_COUNT, 'lunch' => self::DEFAULT_COUNT, 'dinner' => self::DEFAULT_COUNT];
    }

    /** Manager meals planned for a day (before checking the menu). */
    public static function planned(string $day): array
    {
        $data = self::load();
        $own = $data['days'][$day] ?? $data['weekly'][Days::fromKey($day)->dayOfWeekIso] ?? self::defaults();
        return ['breakfast' => $own['breakfast'], 'lunch' => $own['lunch'], 'dinner' => $own['dinner']];
    }

    /**
     * Manager meals booked for a day: the planned number for each meal that the menu serves.
     *
     * @param  string[]  $served  the meals the day's menu has a dish for
     */
    public static function on(string $day, array $served): array
    {
        $planned = self::planned($day);
        $out = [];
        foreach (BookingRules::MEALS as $meal) {
            $out[$meal] = in_array($meal, $served, true) ? $planned[$meal] : 0;
        }
        return $out;
    }
}
