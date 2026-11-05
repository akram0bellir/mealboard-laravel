<?php

namespace App\Support;

/**
 * Rows as the JSON API sends them (same shapes as the PHP API, so the door app needs no change):
 * TINYINT(1) -> true/false, numbers -> numbers, dates -> "YYYY-MM-DD HH:MM:SS.mmmZ" (NULL -> ""), JSON lists -> arrays,
 * and password / tokenKey are never sent.
 */
class ApiRows
{
    private const TABLES = [
        'user' => [
            'bool' => ['Breakfast', 'Lunch', 'Dinner', 'multiple_meals', 'Status', 'emailVisibility', 'verified', 'sex'],
            'int' => ['Strikes', 'card_id', 'id_user'],
            'float' => ['days_30'],
            'date' => ['period_start', 'blocked_until', 'No_show_date1', 'No_show_date2', 'No_show_date3', 'No_show_date4', 'created', 'updated'],
            'hidden' => ['password', 'tokenKey'],
        ],
        'oder' => [
            'bool' => ['bool', 'bool2', 'bool3', 'attended_breakfast', 'attended_lunch', 'attended_dinner', 'no_show_breakfast', 'no_show_lunch', 'no_show_dinner'],
            'date' => ['date', 'created', 'updated'],
        ],
        'meun' => ['date' => ['day', 'created', 'updated']],
        'meal_groups' => ['date' => ['created', 'updated']],
        'department_rules' => ['bool' => ['breakefast', 'lunch', 'dinner'], 'date' => ['created', 'updated']],
        'booking_rules' => [
            'bool' => ['ramadan_mode'],
            'int' => ['max_no_show', 'period_days', 'cancel_deadline_hour', 'cancel_deadline_days_before', 'late_cancel_counts', 'cancel_deadline_hour_for_sandwitch'],
            'date' => ['created', 'updated'],
        ],
    ];

    /** Columns a client may write, per table (everything else is server-owned). */
    public const WRITE = [
        'user' => ['card_id'],
        'oder' => ['attended_breakfast', 'attended_lunch', 'attended_dinner', 'no_show_breakfast', 'no_show_lunch', 'no_show_dinner'],
        'department_rules' => ['department', 'breakefast', 'lunch', 'dinner', 'breakfast_group', 'lunch_group', 'dinner_group'],
    ];

    public static function out(string $table, object|array|null $row, ?array $fields = null): ?array
    {
        if ($row === null) {
            return null;
        }
        $def = self::TABLES[$table] ?? [];
        $out = [];
        foreach ((array) $row as $column => $value) {
            if (($fields !== null && !in_array($column, $fields, true)) || in_array($column, $def['hidden'] ?? [], true)) {
                continue;
            }
            if (in_array($column, $def['bool'] ?? [], true)) {
                $out[$column] = (bool) (int) $value;
            } elseif (in_array($column, $def['int'] ?? [], true)) {
                $out[$column] = $value === null ? null : (int) $value;
            } elseif (in_array($column, $def['float'] ?? [], true)) {
                $out[$column] = $value === null ? null : (float) $value;
            } elseif (in_array($column, $def['date'] ?? [], true)) {
                $out[$column] = self::date($value);
            } else {
                $out[$column] = $value;
            }
        }
        return $out;
    }

    public static function many(string $table, iterable $rows, ?array $fields = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::out($table, $row, $fields);
        }
        return $out;
    }

    /** "2026-10-29 12:00:00.000" -> "2026-10-29 12:00:00.000Z"; NULL -> "". */
    public static function date(mixed $value): string
    {
        $s = (string) ($value ?? '');
        if ($s === '') {
            return '';
        }
        return (str_contains($s, '.') ? str_pad(substr($s, 0, 23), 23, '0') : $s . '.000') . 'Z';
    }
}
