<?php

namespace App\Services;

use App\Models\User;
use App\Support\Days;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Meal-time groups: the group a person belongs to for a meal (the day's switch, else their own, else their
 * department's) and the time it gives them, e.g. "11:30–12:30". A meal without a group has no own time.
 */
class MealTimes
{
    /** Usual times, when a meal has no group. */
    private const USUAL = ['breakfast' => '07:30–09:30', 'lunch' => '12:00–14:00', 'dinner' => '18:30–20:30'];

    /** The old data spells breakfast "brakfast". */
    public static function mealOf(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'brakfast', 'breakfast' => 'breakfast',
            'lunch' => 'lunch',
            'dinner' => 'dinner',
            default => null,
        };
    }

    /** All groups by id. */
    public static function groups(): Collection
    {
        return DB::table('meal_groups')->orderBy('name')->get()->keyBy('id');
    }

    public static function departmentRow(string $department): ?object
    {
        return $department === '' ? null : DB::table('department_rules')->where('department', $department)->first();
    }

    /** The group of a person for one meal, or null. */
    public static function resolve(?string $dayGroup, object|array $person, string $meal, ?object $departmentRow, Collection $groups): ?object
    {
        $person = (array) $person;
        foreach ([(string) $dayGroup, (string) ($person[$meal . '_group'] ?? ''), (string) ($departmentRow->{$meal . '_group'} ?? '')] as $id) {
            $id = trim($id);
            if ($id !== '' && $groups->has($id) && self::mealOf((string) $groups[$id]->meal) === $meal) {
                return $groups[$id];
            }
        }
        return null;
    }

    /** A group's time right now: its Ramadan time in Ramadan mode ('' when not set), else its normal time. */
    public static function groupTime(object $group, BookingRules $rules): string
    {
        [$start, $end] = $rules->ramadan ? [$group->ramadan_start, $group->ramadan_end] : [$group->start, $group->end];
        return $start !== '' && $end !== '' ? Days::ltr("{$start}–{$end}") : '';
    }

    /**
     * "11:30–12:30" for someone's meal, or the meal's usual time when no group applies
     * ('' in Ramadan mode without one).
     */
    public static function label(string $meal, ?object $group, BookingRules $rules): string
    {
        if ($rules->mealClosed($meal)) {
            return t('closed for Ramadan');
        }
        $own = $group ? self::groupTime($group, $rules) : '';
        if ($own !== '' || $rules->ramadan) {
            return $own;
        }
        return Days::ltr(self::USUAL[$meal]);
    }

    /**
     * The person's own time per meal (only meals that have a group with a time).
     *
     * @param  string[]  $meals
     * @return array<string, string>
     */
    public static function for(User $user, array $meals, BookingRules $rules): array
    {
        $groups = self::groups();
        $department = self::departmentRow((string) $user->Department);

        $out = [];
        foreach ($meals as $meal) {
            $group = self::resolve(null, $user, $meal, $department, $groups);
            $time = $group ? self::groupTime($group, $rules) : '';
            if ($time !== '') {
                $out[$meal] = $time;
            }
        }
        return $out;
    }
}
