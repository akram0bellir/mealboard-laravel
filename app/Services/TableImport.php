<?php

namespace App\Services;

use App\Support\ControlRows;
use App\Support\TableReader;
use Illuminate\Support\Facades\DB;

/**
 * Generic import of a table from the old app: a file (.csv, .tsv, .txt, .xlsx, .ods) whose first row holds the
 * database column names (what an export of the old app's table gives). Works for every table in ControlRows::TABLES.
 *   - extra columns "id", "created", "updated" keep the old ids and dates;
 *   - a person in a link column (user, sent_by, ...) may be an id, username, e-mail, card number, employee number or "first last";
 *   - a row that already exists (same id, or same natural key such as user + day) is skipped, or updated with $update;
 *   - $dry reads everything, saves nothing, and says what would happen.
 */
class TableImport
{
    /** Tables that can be imported, and what makes a row "the same row" when no id is given. */
    public const KEYS = [
        'user' => ['username'],            // or e-mail, see exists()
        'oder' => ['user', 'date'],
        'meun' => ['day'],
        'food' => ['title', 'type'],
        'day_info' => ['day'],
        'sandwich_orders' => ['user', 'date'],
        'special_meals' => ['Day', 'Meal', 'type'],
        'manager_meals' => ['date'],
        'meal_groups' => ['name', 'meal'],
        'department_rules' => ['department'],
        'record_penalties' => ['date_of_it', 'meal'],
        'notifications' => ['title', 'message'],
        'reports' => ['user', 'title', 'meal_date'],
        'price_of_meal' => [],             // one row: updated in place
        'booking_rules' => [],             // one row: updated in place
    ];

    /** Columns that point to a person. */
    private const PEOPLE = ['user', 'sent_by', 'switched_by'];

    /** For the page: every table with its columns and key. */
    public static function describe(): array
    {
        $out = [];
        foreach (self::KEYS as $table => $key) {
            $out[$table] = ['columns' => self::columns($table), 'key' => $key];
        }
        return $out;
    }

    public static function columns(string $table): array
    {
        return array_values(array_unique(array_merge(['id'], ControlRows::def($table)['write'], ['created', 'updated'])));
    }

    public static function run(string $table, string $path, string $filename, bool $dry, bool $update): array
    {
        set_time_limit(0);
        if (!isset(self::KEYS[$table])) {
            throw new \InvalidArgumentException('This table cannot be imported.');
        }
        $rows = TableReader::rows($path, $filename);
        if ($rows === []) {
            throw new \InvalidArgumentException('The file is empty.');
        }
        $headRow = array_key_first($rows);

        // header -> canonical column name (any letter case)
        $canon = [];
        foreach (self::columns($table) as $c) {
            $canon[mb_strtolower($c)] = $c;
        }
        $map = [];
        $ignored = [];
        foreach ($rows[$headRow] as $i => $h) {
            $h = trim(str_replace("\u{FEFF}", '', (string) $h));
            if ($h === '') {
                continue;
            }
            if (isset($canon[mb_strtolower($h)])) {
                $map[$i] = $canon[mb_strtolower($h)];
            } else {
                $ignored[] = $h;
            }
        }
        $used = array_values($map);
        if (array_diff($used, ['id', 'created', 'updated']) === []) {
            throw new \InvalidArgumentException('No column of the file matches the table "' . $table . '". Expected names like: ' . implode(', ', array_slice(self::columns($table), 0, 8)) . ', ...');
        }
        $key = self::KEYS[$table];
        $keyOk = in_array('id', $used, true) || $key === [] || ($table === 'user' && (in_array('username', $used, true) || in_array('email', $used, true))) || array_diff($key, $used) === [];
        if (!$keyOk) {
            throw new \InvalidArgumentException('The file needs the column "id" or all of: ' . implode(', ', $key) . ' (to recognise rows that already exist).');
        }

        $people = null; // loaded on first use
        $findPerson = function (string $v) use (&$people): ?string {
            if ($people === null) {
                $people = ['id' => [], 'username' => [], 'email' => [], 'card' => [], 'emp' => [], 'name' => []];
                foreach (DB::table('user')->get(['id', 'username', 'email', 'card_id', 'id_user', 'firstname', 'lastname']) as $u) {
                    $people['id'][$u->id] = $u->id;
                    foreach ([['username', $u->username], ['email', $u->email]] as [$k, $val]) {
                        if (trim((string) $val) !== '') {
                            $people[$k][mb_strtolower(trim($val))] = $u->id;
                        }
                    }
                    if ((int) $u->card_id > 0) {
                        $people['card'][(string) (int) $u->card_id] = $u->id;
                    }
                    if ((int) $u->id_user > 0) {
                        $people['emp'][(string) (int) $u->id_user] = $u->id;
                    }
                    $people['name'][mb_strtolower(trim(preg_replace('/\s+/', ' ', $u->firstname . ' ' . $u->lastname)))] = $u->id;
                }
            }
            $l = mb_strtolower(trim($v));
            return $people['id'][trim($v)] ?? $people['username'][$l] ?? $people['email'][$l] ?? $people['card'][ltrim($l, '0')] ?? $people['emp'][ltrim($l, '0')]
                ?? $people['name'][preg_replace('/\s+/', ' ', $l)] ?? null;
        };

        $def = ControlRows::def($table);
        $added = $updated = $existing = 0;
        $rejected = [];
        $newDepartments = [];
        $work = function () use ($rows, $headRow, $map, $table, $def, $key, $update, $findPerson, &$added, &$updated, &$existing, &$rejected, &$newDepartments) {
            foreach ($rows as $n => $row) {
                if ($n === $headRow) {
                    continue;
                }
                $line = $n + 1;
                $data = [];
                foreach ($map as $i => $column) {
                    $v = trim((string) ($row[$i] ?? ''));
                    if ($v !== '') {
                        $data[$column] = $v;
                    }
                }
                if ($data === []) {
                    continue;
                }
                try {
                    // id / created / updated are kept as they are in the old app
                    $extra = [];
                    $id = $data['id'] ?? null;
                    if ($id !== null && !preg_match('/^[A-Za-z0-9_-]{1,15}$/', $id)) {
                        $rejected[] = ['line' => $line, 'reason' => 'id "' . $id . '" is not valid (letters and digits, 15 at most)'];
                        continue;
                    }
                    foreach (['created', 'updated'] as $c) {
                        if (isset($data[$c]) && ($stamp = self::stamp($data[$c])) !== null) {
                            $extra[$c] = $stamp;
                        }
                    }
                    unset($data['id'], $data['created'], $data['updated']);

                    foreach ($data as $column => $v) {
                        if (in_array($column, self::PEOPLE, true)) {
                            $found = $findPerson($v);
                            if ($found === null) {
                                throw new \RuntimeException('person not found: "' . $v . '"');
                            }
                            $data[$column] = $found;
                        } elseif (in_array($column, $def['date'], true)) {
                            $d = self::day($v);
                            if ($d === null) {
                                throw new \RuntimeException('date not understood in "' . $column . '": "' . $v . '"');
                            }
                            $data[$column] = $d;
                        } elseif (in_array($column, $def['json'], true) && !str_starts_with($v, '[')) {
                            $data[$column] = array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $v))));
                        }
                    }

                    // the same row already in the database?
                    $found = self::exists($table, $id, $data, $key);
                    if ($found !== null) {
                        if ($update) {
                            $extraUpdate = $table === 'user' && isset($data['password']) ? ['password' => self::hash($data['password'])] : [];
                            unset($data['password']);
                            ControlRows::update($table, $found, $data, $extraUpdate);
                            if (isset($extra['created'])) {
                                DB::table($table)->where('id', $found)->update($extra);
                            }
                            $updated++;
                        } else {
                            $existing++;
                        }
                        continue;
                    }

                    if ($table === 'user') {
                        if (!isset($data['username']) && !isset($data['email'])) {
                            throw new \RuntimeException('a person needs a username or an e-mail');
                        }
                        $data += ['User_Type' => 'employee'];
                        $extra['password'] = self::hash($data['password'] ?? bin2hex(random_bytes(12)));
                        unset($data['password']);
                        if (isset($data['Department']) && ($dept = Departments::clean($data['Department'])) !== '') {
                            $data['Department'] = $dept;
                            if (!DB::table('department_rules')->where('department', $dept)->exists()) {
                                $newDepartments[$dept] = true;
                                Departments::ensure($dept);
                            }
                        }
                    }
                    if ($id !== null) {
                        $extra['id'] = $id;
                    }
                    if ($key !== [] && array_diff($key, array_keys($data)) !== [] && $table !== 'user' && $id === null) {
                        throw new \RuntimeException('missing ' . implode(', ', array_diff($key, array_keys($data))));
                    }
                    ControlRows::insert($table, $data, $extra);
                    $added++;
                } catch (\Throwable $e) {
                    $rejected[] = ['line' => $line, 'reason' => mb_substr($e->getMessage(), 0, 200)];
                }
            }
        };

        DB::beginTransaction();
        try {
            $work();
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'table' => $table,
            'mode' => $dry ? 'preview' : 'imported',
            'added' => $added,
            'updated' => $updated,
            'alreadyThere' => $existing,
            'rejected' => count($rejected),
            'rejectedRows' => array_slice($rejected, 0, 25),
            'newDepartments' => array_keys($newDepartments),
            'columnsUsed' => array_values($map),
            'ignoredColumns' => $ignored,
        ];
    }

    /** The id of the row that is "the same" as this one, or null. */
    private static function exists(string $table, ?string $id, array $data, array $key): ?string
    {
        if ($id !== null && ($row = DB::table($table)->where('id', $id)->first(['id'])) !== null) {
            return (string) $row->id;
        }
        if ($table === 'user') {
            foreach (['username', 'email'] as $c) {
                if (isset($data[$c]) && ($row = DB::table('user')->where($c, $data[$c])->first(['id'])) !== null) {
                    return (string) $row->id;
                }
            }
            return null;
        }
        if ($key === []) {
            $row = DB::table($table)->first(['id']);
            return $row === null ? null : (string) $row->id;
        }
        if (array_diff($key, array_keys($data)) !== []) {
            return null;
        }
        $q = DB::table($table);
        $dates = ControlRows::def($table)['date'];
        foreach ($key as $c) {
            in_array($c, $dates, true)
                ? $q->whereRaw("LEFT(`$c`, 10) = ?", [substr((string) ControlRows::dateIn($data[$c]), 0, 10)])
                : $q->where($c, $data[$c]);
        }
        $row = $q->first(['id']);
        return $row === null ? null : (string) $row->id;
    }

    private static function hash(string $password): string
    {
        // A hash exported from the old app (bcrypt) is kept; a plain password is hashed.
        return preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $password) ? $password : password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /** Any usual date -> "Y-m-d" (accepts 2026-11-05, 05/11/2026, 5.11.2026, Excel numbers, with or without a time). */
    private static function day(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 20000 && (float) $v < 80000) {
            return gmdate('Y-m-d', (int) (((float) $v - 25569) * 86400));
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** A date with its time of day (created / updated), or null. */
    private static function stamp(string $v): ?string
    {
        $day = self::day($v);
        if ($day === null) {
            return null;
        }
        $time = preg_match('/[T ](\d{2}):(\d{2})(?::(\d{2}))?/', $v, $m) ? sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00') : '12:00:00';
        return "$day $time.000";
    }
}
