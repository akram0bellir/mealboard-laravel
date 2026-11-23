<?php

namespace App\Services;

use App\Support\Days;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The menu: one row per day in `meun`, holding a title and a description for each of the three meals.
 * Shared by the Meals page and the control panel's menu import.
 */
class Menu
{
    /** meal => [title column, description column] */
    public const FIELDS = [
        'breakfast' => ['brakfast_title', 'brakfast_description'],
        'lunch' => ['lunch_title', 'lunch_description'],
        'dinner' => ['Dinner_title', 'Dinner_description'],
    ];

    /** The day's row, or null. */
    private static function row(string $day): ?object
    {
        return DB::table('meun')->where('day', '>=', "$day 00:00:00.000")->where('day', '<=', "$day 23:59:59.999")->orderBy('created')->first();
    }

    /** The dish already set for a meal of a day: ['name', 'description'], or null. */
    public static function dish(string $day, string $meal): ?array
    {
        $row = self::row($day);
        [$title, $desc] = self::FIELDS[$meal];
        return $row !== null && trim((string) $row->{$title}) !== '' ? ['name' => trim((string) $row->{$title}), 'description' => (string) $row->{$desc}] : null;
    }

    /** Sets (or, with an empty name, clears) one dish in the day's menu row. */
    public static function set(string $day, string $meal, string $name, string $description): void
    {
        [$title, $desc] = self::FIELDS[$meal];
        $row = self::row($day);
        $now = Ids::now();

        if ($row === null) {
            if ($name === '') {
                return;
            }
            $blank = ['brakfast_title' => '', 'brakfast_description' => '', 'lunch_title' => '', 'lunch_description' => '', 'Dinner_title' => '', 'Dinner_description' => ''];
            DB::table('meun')->insert(array_merge($blank, [$title => $name, $desc => $description, 'id' => Ids::new(), 'day' => Days::toDb($day), 'created' => $now, 'updated' => $now]));
            return;
        }

        DB::table('meun')->where('id', $row->id)->update([$title => $name, $desc => $description, 'updated' => $now]);
        $left = DB::table('meun')->where('id', $row->id)->first();
        if (collect(self::FIELDS)->every(fn ($f) => trim((string) $left->{$f[0]}) === '')) {
            DB::table('meun')->where('id', $row->id)->delete(); // an empty day is no day
        }
    }
}
