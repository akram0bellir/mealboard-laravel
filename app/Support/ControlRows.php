<?php

namespace App\Support;

use App\Http\Controllers\Control\ControlError;
use Illuminate\Support\Facades\DB;

/**
 * Column types and writable columns of every app table, plus plain insert / update / delete, for the control panel
 * (port of the PHP API's lib/json.php TABLES, insert_row, update_row, row_out). Table names are the Laravel ones.
 */
class ControlRows
{
    public const TABLES = [
        'user' => [
            'bool' => ['Breakfast', 'Lunch', 'Dinner', 'multiple_meals', 'Status', 'emailVisibility', 'verified', 'sex'],
            'int' => ['Strikes', 'card_id', 'id_user'],
            'float' => ['days_30'],
            'date' => ['period_start', 'blocked_until', 'No_show_date1', 'No_show_date2', 'No_show_date3', 'No_show_date4', 'created', 'updated'],
            'json' => [],
            'hidden' => ['password', 'tokenKey'],
            'write' => [
                'email', 'username', 'password', 'firstname', 'lastname', 'Department', 'Position',
                'Breakfast', 'Lunch', 'Dinner', 'multiple_meals', 'Strikes',
                'period_start', 'blocked_until', 'Status', 'sex', 'card_id', 'id_user',
                'picter', 'User_Type', 'breakfast_group', 'lunch_group', 'dinner_group',
                'No_show_date1', 'No_show_date2', 'No_show_date3', 'No_show_date4',
            ],
        ],
        'oder' => [
            'bool' => ['bool', 'bool2', 'bool3', 'attended_breakfast', 'attended_lunch', 'attended_dinner', 'no_show_breakfast', 'no_show_lunch', 'no_show_dinner'],
            'int' => [], 'float' => [],
            'date' => ['date', 'created', 'updated'],
            'json' => [], 'hidden' => [],
            'write' => [
                'user', 'food', 'bool', 'bool2', 'bool3', 'date',
                'attended_breakfast', 'attended_lunch', 'attended_dinner',
                'no_show_breakfast', 'no_show_lunch', 'no_show_dinner',
                'day_group', 'switched_by',
            ],
        ],
        'meun' => [
            'bool' => [], 'int' => [], 'float' => [],
            'date' => ['day', 'created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['brakfast_title', 'brakfast_description', 'lunch_title', 'lunch_description', 'Dinner_title', 'Dinner_description', 'day'],
        ],
        'food' => [
            'bool' => [], 'int' => [], 'float' => [],
            'date' => ['created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['title', 'description', 'type'],
        ],
        'sandwich_orders' => [
            'bool' => [], 'int' => ['quantity'], 'float' => [],
            'date' => ['date', 'created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['user', 'quantity', 'date'],
        ],
        'special_meals' => [
            'bool' => [], 'int' => ['How_many'], 'float' => [],
            'date' => ['Day', 'created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['type', 'Day', 'Meal', 'How_many', 'Note'],
        ],
        'manager_meals' => [
            'bool' => [], 'int' => ['weekday', 'breakfast', 'lunch', 'dinner'], 'float' => [],
            'date' => ['date', 'created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['weekday', 'date', 'breakfast', 'lunch', 'dinner'],
        ],
        'meal_groups' => [
            'bool' => [], 'int' => [], 'float' => [],
            'date' => ['created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['name', 'meal', 'start', 'end', 'ramadan_start', 'ramadan_end'],
        ],
        'department_rules' => [
            'bool' => ['breakefast', 'lunch', 'dinner'], 'int' => [], 'float' => [],
            'date' => ['created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['department', 'breakefast', 'lunch', 'dinner', 'breakfast_group', 'lunch_group', 'dinner_group'],
        ],
        'booking_rules' => [
            'bool' => ['ramadan_mode'],
            'int' => ['max_no_show', 'period_days', 'cancel_deadline_hour', 'cancel_deadline_days_before', 'late_cancel_counts', 'cancel_deadline_hour_for_sandwitch'],
            'float' => [],
            'date' => ['created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['max_no_show', 'period_days', 'cancel_deadline_hour', 'cancel_deadline_days_before', 'late_cancel_counts', 'ramadan_mode', 'cancel_deadline_hour_for_sandwitch', 'auto_no_show_from'],
        ],
        'price_of_meal' => [
            'bool' => [], 'int' => [],
            'float' => ['brakfast', 'lunch', 'dinner', 'Guest_meal', 'VIP_snacks', 'Sandwich', 'Enhanced_meal', 'Special_meal', 'Snacks'],
            'date' => ['created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['brakfast', 'lunch', 'dinner', 'Guest_meal', 'VIP_snacks', 'Sandwich', 'Enhanced_meal', 'Special_meal', 'Snacks'],
        ],
        'record_penalties' => [
            'bool' => [], 'int' => [],
            'float' => ['Hygiene_and_safety_KPIs', 'Delivery_delay', 'Failure_to_meet_ordered_quantities', 'Qualitative_nonconformity_of_meals'],
            'date' => ['date_of_it', 'created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => [
                'meal', 'Hygiene_and_safety_KPIs', 'Hygiene_and_safety_KPIs_resone', 'Delivery_delay', 'Delivery_delay_resone',
                'Failure_to_meet_ordered_quantities', 'Failure_to_meet_ordered_quantities_resone',
                'Qualitative_nonconformity_of_meals', 'Qualitative_nonconformity_of_meals_resone', 'date_of_it', 'note',
            ],
        ],
        'notifications' => [
            'bool' => [], 'int' => [], 'float' => [],
            'date' => ['created', 'updated'], 'json' => ['recipients', 'read_by'], 'hidden' => [],
            'write' => ['title', 'message', 'audience', 'recipients', 'read_by', 'sent_by'],
        ],
        'reports' => [
            'bool' => ['read'], 'int' => [], 'float' => [],
            'date' => ['meal_date', 'created', 'updated'], 'json' => [], 'hidden' => [],
            'write' => ['title', 'message', 'picter', 'user', 'meal_date', 'meal', 'read', 'reply'],
        ],
        'smtp' => [
            'bool' => [], 'int' => [], 'float' => [],
            'date' => ['created', 'updated'], 'json' => [], 'hidden' => ['Password'],
            'write' => ['Sender_name', 'Sender_address', 'SMTP_server_host', 'Port', 'Username', 'Password'],
        ],
    ];

    /** Link / optional columns that stay NULL when empty (unique keys allow several NULLs). */
    private const NULLABLE_TEXT = ['email', 'username', 'user', 'food', 'day_group', 'switched_by', 'breakfast_group', 'lunch_group', 'dinner_group', 'sent_by'];

    public static function known(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    public static function def(string $table): array
    {
        return self::TABLES[$table] ?? throw new ControlError(400, 'Unknown table.');
    }

    /** One database row -> JSON shape (booleans, numbers, "…Z" dates, JSON lists; hidden columns left out). */
    public static function out(string $table, object|array|null $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $def = self::def($table);
        $out = [];
        foreach ((array) $row as $column => $value) {
            if (in_array($column, $def['hidden'], true)) {
                continue;
            }
            $out[$column] = match (true) {
                in_array($column, $def['bool'], true) => (bool) (int) $value,
                in_array($column, $def['int'], true) => $value === null ? null : (int) $value,
                in_array($column, $def['float'], true) => $value === null ? null : (float) $value,
                in_array($column, $def['date'], true) => ApiRows::date($value),
                in_array($column, $def['json'], true) => self::jsonList($value),
                default => $value,
            };
        }
        return $out;
    }

    public static function find(string $table, string $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();
        return $row === null ? null : (array) $row;
    }

    /** INSERT; returns the fresh row. $extra = server-only columns (password hash, tokenKey). */
    public static function insert(string $table, array $data, array $extra = []): array
    {
        $data = self::complete($table, self::writable($table, $data));
        if ($table === 'user' && !array_key_exists('tokenKey', $extra)) {
            $extra['tokenKey'] = bin2hex(random_bytes(15));
        }
        $now = Ids::now();
        // $extra first: the password hash must win over the empty 'password' that complete() adds.
        $row = $extra + $data + ['id' => Ids::new(), 'created' => $now, 'updated' => $now];
        DB::table($table)->insert($row);
        return self::find($table, $row['id']) ?? throw new ControlError(500, 'The row was not saved.');
    }

    /** UPDATE by id (only the given columns); returns the fresh row. */
    public static function update(string $table, string $id, array $data, array $extra = []): array
    {
        $data = $extra + self::writable($table, $data);
        unset($data['id']);
        if ($data !== []) {
            $data['updated'] = Ids::now();
            DB::table($table)->where('id', $id)->update($data);
        }
        return self::find($table, $id) ?? throw new ControlError(404, 'This record was not found.');
    }

    public static function delete(string $table, string $id): void
    {
        DB::table($table)->where('id', $id)->delete();
    }

    /** "2026-11-05", "2026-11-05T10:00Z" ... -> "2026-11-05 12:00:00.000" (a day is stored at noon); anything else -> null. */
    public static function dateIn(mixed $value): ?string
    {
        $s = str_replace(['T', 'Z', 'z'], [' ', '', ''], trim((string) ($value ?? '')));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} 12:00:00.000";
    }

    private static function writable(string $table, array $data): array
    {
        $out = [];
        foreach (self::def($table)['write'] as $column) {
            if (array_key_exists($column, $data)) {
                $out[$column] = self::normalize($table, $column, $data[$column]);
            }
        }
        return $out;
    }

    private static function normalize(string $table, string $column, mixed $value): mixed
    {
        $def = self::def($table);
        if (in_array($column, $def['bool'], true)) {
            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
            }
            return is_bool($value) ? (int) $value : ((float) $value !== 0.0 ? 1 : 0);
        }
        if (in_array($column, $def['int'], true)) {
            return $value === null || $value === '' ? null : (int) round((float) $value);
        }
        if (in_array($column, $def['float'], true)) {
            return $value === null || $value === '' ? 0.0 : (float) $value;
        }
        if (in_array($column, $def['date'], true)) {
            return self::dateIn($value);
        }
        if (in_array($column, $def['json'], true)) {
            return json_encode(is_array($value) ? array_values($value) : self::jsonList($value), JSON_UNESCAPED_UNICODE);
        }
        if ($value === null && in_array($column, self::NULLABLE_TEXT, true)) {
            return null;
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /** Missing columns get '' / 0 / NULL: MySQL in strict mode refuses a NOT NULL column without a value. */
    private static function complete(string $table, array $data): array
    {
        $def = self::def($table);
        foreach ($def['write'] as $column) {
            if (array_key_exists($column, $data)) {
                continue;
            }
            $data[$column] = match (true) {
                in_array($column, $def['bool'], true), in_array($column, $def['int'], true) => 0,
                in_array($column, $def['float'], true) => 0.0,
                in_array($column, $def['date'], true) => null,
                in_array($column, $def['json'], true) => '[]',
                default => in_array($column, self::NULLABLE_TEXT, true) ? null : '',
            };
        }
        return $data;
    }

    private static function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = $value === null || $value === '' ? null : json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
