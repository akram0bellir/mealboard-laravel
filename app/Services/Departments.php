<?php

namespace App\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The list of departments. `department_rules` is that list: one row per department, holding the meals a new
 * person from it starts with. A user only stores the department's name as text.
 */
class Departments
{
    /** Every department name, in natural order. */
    public static function names(): array
    {
        $names = DB::table('department_rules')->pluck('department')->map(fn ($d) => (string) $d)->filter(fn ($d) => $d !== '')->all();
        natcasesort($names);
        return array_values($names);
    }

    /** Tidy a typed name: no stray spaces, no placeholder values. */
    public static function clean(?string $name): string
    {
        $name = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $name));
        return in_array(mb_strtolower($name), ['#n/a', 'n/a', '0', '-'], true) ? '' : mb_substr($name, 0, 100);
    }

    /** The department's row, created (lunch only, like the old default) when it is new. Null for an empty name. */
    public static function ensure(string $name): ?object
    {
        $name = self::clean($name);
        if ($name === '') {
            return null;
        }
        $row = DB::table('department_rules')->where('department', $name)->first();
        if ($row) {
            return $row;
        }
        $id = Ids::new();
        $now = Ids::now();
        DB::table('department_rules')->insert([
            'id' => $id, 'department' => $name, 'breakefast' => 0, 'lunch' => 1, 'dinner' => 0,
            'breakfast_group' => null, 'lunch_group' => null, 'dinner_group' => null, 'created' => $now, 'updated' => $now,
        ]);
        return DB::table('department_rules')->where('id', $id)->first();
    }
}
