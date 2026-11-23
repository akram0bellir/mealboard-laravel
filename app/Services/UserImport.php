<?php

namespace App\Services;

use App\Support\Ids;
use App\Support\TableReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creating accounts: one row inserted, and the spreadsheet import used by the Users page and the control panel.
 */
class UserImport
{
    /** Inserts an account with every column filled; returns its id. */
    public static function insertUser(array $data, string $password, ?int $rounds = null): string
    {
        $id = Ids::new();
        $now = Ids::now();
        DB::table('user')->insert($data + [
            'id' => $id,
            'password' => Hash::make($password, $rounds ? ['rounds' => $rounds] : []),
            'tokenKey' => bin2hex(random_bytes(15)),
            'Strikes' => 0,
            'multiple_meals' => 0,
            'sex' => 0,
            'created' => $now,
            'updated' => $now,
        ]);
        return $id;
    }

    /** The meals a department's new people start with (lunch only when the department has no rule). */
    public static function mealsOf(?object $row): array
    {
        return $row
            ? ['breakfast' => (int) $row->breakefast === 1, 'lunch' => (int) $row->lunch === 1, 'dinner' => (int) $row->dinner === 1]
            : ['breakfast' => false, 'lunch' => true, 'dinner' => false];
    }

    /**
     * Import from a table file (.xlsx, .ods, .csv, .tsv, .txt) with the columns immatricule, nom, prenom, n_carte, dept, poste, user_name, PW:
     * immatricule -> employee id, n_carte -> card id, dept -> department (added to the list when new, kept as text),
     * user_name -> username, PW -> password (stored hashed). Everyone is an employee; roles are edited by hand later.
     * With $dry nothing is written: the result says what would happen.
     */
    public static function sheet(string $path, bool $dry = false, ?string $filename = null): array
    {
        set_time_limit(0);
        try {
            $rows = TableReader::rows($path, $filename);
        } catch (\RuntimeException $e) {
            return ['done' => 0, 'skipped' => [['line' => 1, 'reason' => $e->getMessage()]]];
        }
        if ($rows === []) {
            return ['done' => 0, 'skipped' => [['line' => 1, 'reason' => 'The sheet is empty.']]];
        }
        $headRow = array_key_first($rows);
        $col = [];
        foreach ($rows[$headRow] as $i => $h) {
            $col[mb_strtolower(trim(str_replace("\u{FEFF}", '', $h)))] = $i;
        }
        $missing = array_values(array_filter(['nom', 'prenom', 'user_name'], fn ($c) => !isset($col[$c])));
        if ($missing) {
            return ['done' => 0, 'skipped' => [['line' => $headRow + 1, 'reason' => 'Missing column: ' . implode(', ', $missing)]]];
        }

        $taken = DB::table('user')->whereNotNull('username')->pluck('username')->map(fn ($n) => mb_strtolower($n))->flip()->all();
        $known = array_flip(array_map('mb_strtolower', Departments::names()));
        $newDepartments = [];
        $default = (string) config('mealboard.default_password');
        $clean = fn (string $v) => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $v));
        $number = function (string $v): ?int {
            $v = trim($v);
            return preg_match('/^\d{1,18}$/', $v) && (int) $v > 0 ? (int) $v : null;
        };
        $done = 0;
        $skipped = [];
        $fixed = 0;

        foreach ($rows as $n => $row) {
            if ($n === $headRow) {
                continue;
            }
            $get = fn (string $c) => isset($col[$c]) ? (string) ($row[$col[$c]] ?? '') : '';
            $last = $clean($get('nom'));
            $first = $clean($get('prenom'));
            $raw = $get('user_name');
            if ($last === '' && $first === '' && trim($raw) === '') {
                continue; // blank line
            }
            $username = preg_replace('/[\s\x{FFFD}]+/u', '', $raw); // spaces and lost characters can't be typed at sign-in
            $key = mb_strtolower($username);
            $reason = match (true) {
                $first === '' || $last === '' => 'Needs a first name (prenom) and last name (nom)',
                !preg_match('/^[\p{L}\p{N}._-]{3,32}$/u', $username) => "Invalid username \"{$raw}\"",
                isset($taken[$key]) => "Duplicate username \"{$username}\"",
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['line' => $n + 1, 'reason' => $reason];
                continue;
            }
            $taken[$key] = true;
            $fixed += $username !== trim($raw) ? 1 : 0;

            $department = self::repairDepartment($get('dept'));
            if ($dry) {
                if ($department !== '' && !isset($known[mb_strtolower($department)])) {
                    $known[mb_strtolower($department)] = true;
                    $newDepartments[] = $department;
                }
                $done++;
                continue;
            }

            $dept = Departments::ensure($department);
            $meals = self::mealsOf($dept);
            $password = trim($get('pw'));
            self::insertUser([
                'firstname' => $first, 'lastname' => $last, 'username' => $username, 'email' => null,
                'Department' => $dept ? (string) $dept->department : '', 'Position' => $clean($get('poste')),
                'card_id' => $number($get('n_carte')), 'id_user' => $number($get('immatricule')),
                'Breakfast' => (int) $meals['breakfast'], 'Lunch' => (int) $meals['lunch'], 'Dinner' => (int) $meals['dinner'],
                'emailVisibility' => 1, 'Status' => 1, 'User_Type' => 'employee',
            ], $password !== '' ? $password : $default, 10);
            $done++;
        }

        if ($dry) {
            return ['preview' => true, 'done' => $done, 'skipped' => $skipped, 'newDepartments' => count($newDepartments), 'fixedUsernames' => $fixed, 'departmentsToAdd' => $newDepartments];
        }
        return ['done' => $done, 'skipped' => $skipped, 'newDepartments' => max(0, count(Departments::names()) - count($known)), 'fixedUsernames' => $fixed];
    }

    /** The source file can lose its accents (they come through as U+FFFD); put back the words we know. */
    private static function repairDepartment(string $name): string
    {
        $name = Departments::clean($name);
        if (!str_contains($name, "\u{FFFD}") && !preg_match('/^CONTRLE/i', $name)) {
            return $name;
        }
        $words = [
            'CONTRLE QUALIT' => 'Contrôle Qualité', "Contr\u{FFFD}le" => 'Contrôle', "Qualit\u{FFFD}" => 'Qualité',
            "R\u{FFFD}glementaires" => 'Réglementaires', "G\u{FFFD}n\u{FFFD}rale" => 'Générale',
            "D\u{FFFD}veloppement" => 'Développement', "Comptabilit\u{FFFD}" => 'Comptabilité', "M\u{FFFD}dical" => 'Médical',
        ];
        return Departments::clean(str_ireplace(array_keys($words), array_values($words), $name));
    }
}
