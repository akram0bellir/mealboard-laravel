<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\Files;
use App\Services\MealTimes;
use App\Services\Standing;
use App\Support\Days;
use App\Support\Ids;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Admin: every account. Create and edit, which meals each person may book, strikes and pauses, active
 * switch, delete, import. Same rules as the PHP API (users.php) and the React Users page.
 */
class UserController extends Controller
{
    private const DEPARTMENTS = ['1', '2', '3'];

    private const MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];

    public function index(Request $request): View
    {
        $rules = BookingRules::current();
        $me = $request->user();
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $all = DB::table('user')->orderBy('firstname')->orderBy('lastname')->get();
        $groups = MealTimes::groups();
        $deptRows = DB::table('department_rules')->get()->keyBy('department');

        $rows = $all->map(function ($u) use ($groups, $deptRows, $rules) {
            $standing = Standing::read($u, false, $rules);
            $group = $u->Lunch ? MealTimes::resolve(null, $u, 'lunch', $deptRows->get($u->Department), $groups) : null;
            $own = trim((string) $u->lunch_group) !== '' && $group && $group->id === $u->lunch_group;
            return [
                'u' => $u,
                'name' => trim($u->firstname . ' ' . $u->lastname) ?: (string) $u->email,
                'role' => Roles::normalize($u->User_Type),
                'protected' => Roles::isProtected($u->User_Type),
                'standing' => $standing,
                'periodEnd' => $standing['period_start'] !== '' ? Days::add($standing['period_start'], $rules->periodDays) : '',
                'lunch' => $group ? "{$group->start}–{$group->end}" . ($own ? ' · ' . t('own') : '') : '',
            ];
        });

        $words = array_filter(preg_split('/\s+/', mb_strtolower($q)));
        $visible = $rows->filter(function ($r) use ($status, $words) {
            $active = (int) $r['u']->Status === 1;
            if (($status === 'active' && !$active) || ($status === 'inactive' && $active)) {
                return false;
            }
            if (!$words) {
                return true;
            }
            $haystack = mb_strtolower(implode(' ', [$r['name'], $r['u']->email, $r['u']->username, $r['u']->Department, $r['u']->Position]));
            return collect($words)->every(fn ($w) => str_contains($haystack, $w));
        })->values();

        $dialog = null;
        $edit = null;
        if ($request->query('new')) {
            $dialog = 'new';
        } elseif ($request->query('import')) {
            $dialog = 'import';
        } elseif ($id = $request->query('edit')) {
            $edit = $all->firstWhere('id', $id);
            $dialog = $edit ? 'edit' : null;
        } elseif ($id = $request->query('delete')) {
            $edit = $all->firstWhere('id', $id);
            $dialog = $edit ? 'delete' : null;
        }

        $deptMeals = collect(self::DEPARTMENTS)->mapWithKeys(function ($d) use ($deptRows) {
            $row = $deptRows->get($d);
            return [$d => $row
                ? ['breakfast' => (int) $row->breakefast === 1, 'lunch' => (int) $row->lunch === 1, 'dinner' => (int) $row->dinner === 1]
                : ['breakfast' => false, 'lunch' => true, 'dinner' => false]];
        });

        return view('admin.users', [
            'rules' => $rules,
            'rows' => $visible,
            'total' => $all->count(),
            'active' => $all->where('Status', 1)->count(),
            'strikes' => $all->sum('Strikes'),
            'q' => $q,
            'status' => $status,
            'isSuper' => Roles::isSuper($me->User_Type),
            'myId' => $me->id,
            'dialog' => $dialog,
            'edit' => $edit,
            'departments' => self::DEPARTMENTS,
            'deptMeals' => $deptMeals,
            'groups' => $groups->values(),
            'deptGroups' => $deptRows,
            'roles' => ['employee', 'responsible', 'intern', 'manager', 'admin', 'super_admin', 'door'],
            'meals' => self::MEALS,
            'defaultPassword' => (string) config('mealboard.default_password'),
            'flash' => session('users_flash'),
            'errors' => session('users_errors', []),
            'imported' => session('users_imported'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $user = User::find($id);
        abort_if($user === null, 404);
        return $this->save($request, $user);
    }

    /** A row's quick controls. */
    public function strike(Request $request, string $id): RedirectResponse
    {
        $user = User::findOrFail($id);
        $delta = (int) $request->input('delta') >= 0 ? 1 : -1;
        $current = Standing::read($user)['strikes'];
        Standing::setStrikes($id, max(0, $current + $delta));
        return $this->back($id);
    }

    public function lift(string $id): RedirectResponse
    {
        User::findOrFail($id);
        Standing::liftBlock($id);
        return $this->back($id);
    }

    public function allow(Request $request, string $id): RedirectResponse
    {
        User::findOrFail($id);
        $meal = (string) $request->input('meal');
        $column = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'multiple' => 'multiple_meals'][$meal] ?? null;
        abort_if($column === null, 422);
        DB::table('user')->where('id', $id)->update([$column => $request->boolean('value') ? 1 : 0, 'updated' => Ids::now()]);
        return $this->back($id);
    }

    public function active(Request $request, string $id): RedirectResponse
    {
        $user = User::findOrFail($id);
        $this->abortIfLocked($request, $user);
        DB::table('user')->where('id', $id)->update(['Status' => (int) $user->Status === 1 ? 0 : 1, 'updated' => Ids::now()]);
        return $this->back($id);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $user = User::findOrFail($id);
        $this->abortIfLocked($request, $user);
        if ($user->id === $request->user()->id) {
            return redirect('/users')->with('users_flash', "You can't delete your own account.");
        }
        DB::table('auth_tokens')->where('user_id', $id)->delete();
        DB::table('user')->where('id', $id)->delete();
        return redirect('/users')->with('users_flash', $user->fullName() . ' was deleted.');
    }

    /** One user per line: first, last, email, department, position. */
    public function import(Request $request): RedirectResponse
    {
        $text = (string) $request->input('list');
        if ($file = $request->file('file')) {
            $text .= "\n" . file_get_contents($file->getRealPath());
        }

        $deptRows = DB::table('department_rules')->get()->keyBy('department');
        $taken = DB::table('user')->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip()->all();
        $done = 0;
        $skipped = [];

        foreach (preg_split('/\r?\n/', $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^first\s*name/i', $line)) {
                continue;
            }
            [$first, $last, $email, $department, $position] = array_pad(array_map('trim', preg_split('/[,;\t]/', $line)), 5, '');
            $key = mb_strtolower($email);
            $reason = match (true) {
                $first === '' || $last === '' || $email === '' || $position === '' => 'Needs first name, last name, email, department, position',
                !filter_var($email, FILTER_VALIDATE_EMAIL) => "Invalid email \"{$email}\"",
                $department !== '' && !in_array($department, self::DEPARTMENTS, true) => 'Department must be one of ' . implode(', ', self::DEPARTMENTS),
                isset($taken[$key]) => "Duplicate email \"{$email}\"",
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['line' => $i + 1, 'reason' => $reason];
                continue;
            }
            $taken[$key] = true;
            $department = $department ?: self::DEPARTMENTS[0];
            $meals = $this->mealsOf($deptRows->get($department));
            $this->insertUser([
                'firstname' => $first, 'lastname' => $last, 'email' => $key, 'Department' => $department, 'Position' => $position,
                'Breakfast' => (int) $meals['breakfast'], 'Lunch' => (int) $meals['lunch'], 'Dinner' => (int) $meals['dinner'],
                'emailVisibility' => 1, 'Status' => 1, 'User_Type' => 'employee',
            ], (string) config('mealboard.default_password'));
            $done++;
        }

        return redirect('/users')->with('users_imported', ['done' => $done, 'skipped' => $skipped]);
    }

    private function save(Request $request, ?User $user): RedirectResponse
    {
        $me = $request->user();
        $isSuper = Roles::isSuper($me->User_Type);
        $back = $user ? "/users?edit={$user->id}" : '/users?new=1';
        $in = fn (string $key) => trim((string) $request->input($key));

        $first = $in('firstname');
        $last = $in('lastname');
        $position = $in('position');
        $email = mb_strtolower($in('email'));
        $username = $in('username');
        $department = $in('department');
        $password = (string) $request->input('password');
        $confirm = (string) $request->input('password_confirmation');

        $filled = fn (string $value, string $column) => $value !== '' || ($user !== null && (string) $user->{$column} === $value);
        $errors = [];
        if (!$filled($first, 'firstname')) {
            $errors['firstname'] = 'Required.';
        }
        if (!$filled($last, 'lastname')) {
            $errors['lastname'] = 'Required.';
        }
        if (!$filled($position, 'Position')) {
            $errors['position'] = 'Required.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Not an e-mail address: {$email}";
        }
        if ($email !== '' && DB::table('user')->whereRaw('LOWER(email) = ?', [$email])->when($user, fn ($q) => $q->where('id', '<>', $user->id))->exists()) {
            $errors['email'] = 'A user with this email already exists.';
        }
        if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
            $errors['username'] = '3 to 32 letters, digits, dots, dashes or underscores.';
        } elseif ($username !== '' && DB::table('user')->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->when($user, fn ($q) => $q->where('id', '<>', $user->id))->exists()) {
            $errors['username'] = 'A user with this username already exists.';
        }
        if ($email === '' && $username === '' && !($user && (string) $user->email !== '')) {
            $errors['email'] = 'Add an email or a username: the person signs in with one of them.';
        }
        if (!in_array($department, self::DEPARTMENTS, true)) {
            $errors['department'] = 'Pick a department.';
        }
        if ($user === null && $password === '') {
            $password = (string) config('mealboard.default_password');
            $confirm = $password;
        }
        if ($password !== '' && mb_strlen($password) < 8) {
            $errors['password'] = 'At least 8 characters.';
        } elseif ($password !== '' && $password !== $confirm) {
            $errors['password'] = "Passwords don't match.";
        }

        $role = $user ? Roles::normalize($user->User_Type) : 'employee';
        $asked = Roles::normalize((string) $request->input('User_Type', $role));
        if ($isSuper) {
            $role = $asked;
        }

        if ($errors !== []) {
            return redirect($back)->withInput($request->except('_token', 'password', 'password_confirmation'))->with('users_errors', $errors);
        }

        $groups = DB::table('meal_groups')->pluck('meal', 'id');
        $data = [
            'firstname' => $first, 'lastname' => $last, 'Position' => $position, 'Department' => $department,
            'email' => $email === '' ? null : $email,
            'username' => $username === '' ? null : $username,
            'sex' => $request->input('sex') === 'female' ? 1 : 0,
            'card_id' => $in('card_id') === '' ? null : (int) $in('card_id'),
            'id_user' => $in('id_user') === '' ? null : (int) $in('id_user'),
            'Breakfast' => $request->boolean('Breakfast') ? 1 : 0,
            'Lunch' => $request->boolean('Lunch') ? 1 : 0,
            'Dinner' => $request->boolean('Dinner') ? 1 : 0,
            'multiple_meals' => $request->boolean('multiple_meals') ? 1 : 0,
            'Status' => $request->boolean('Status') ? 1 : 0,
            'emailVisibility' => $request->boolean('emailVisibility') ? 1 : 0,
            'User_Type' => $role,
        ];
        if ($isSuper) {
            foreach (['breakfast', 'lunch', 'dinner'] as $meal) {
                $id = $in("{$meal}_group");
                $data["{$meal}_group"] = $id !== '' && $groups->has($id) && MealTimes::mealOf((string) $groups[$id]) === $meal ? $id : null;
            }
        }

        if ($user === null) {
            $id = $this->insertUser($data, $password);
        } else {
            $id = $user->id;
            $data['updated'] = Ids::now();
            if ($password !== '') {
                $data['password'] = Hash::make($password);
                $data['tokenKey'] = bin2hex(random_bytes(15));
                DB::table('auth_tokens')->where('user_id', $id)->delete();
            }
            DB::table('user')->where('id', $id)->update($data);
        }

        if ($file = $request->file('picter')) {
            try {
                DB::table('user')->where('id', $id)->update(['picter' => Files::store('user', $id, $file)]);
            } catch (\RuntimeException $e) {
                return redirect("/users?edit={$id}")->with('users_errors', ['picter' => $e->getMessage()]);
            }
        }

        $name = trim("{$first} {$last}");
        return redirect('/users#u-' . $id)->with('users_flash', $user ? "{$name} was updated." : "{$name} was created.");
    }

    /** Inserts an account with every column filled; returns its id. */
    private function insertUser(array $data, string $password): string
    {
        $id = Ids::new();
        $now = Ids::now();
        DB::table('user')->insert($data + [
            'id' => $id,
            'password' => Hash::make($password),
            'tokenKey' => bin2hex(random_bytes(15)),
            'Strikes' => 0,
            'multiple_meals' => 0,
            'sex' => 0,
            'created' => $now,
            'updated' => $now,
        ]);
        return $id;
    }

    private function mealsOf(?object $row): array
    {
        return $row
            ? ['breakfast' => (int) $row->breakefast === 1, 'lunch' => (int) $row->lunch === 1, 'dinner' => (int) $row->dinner === 1]
            : ['breakfast' => false, 'lunch' => true, 'dinner' => false];
    }

    /** Only the super admin may switch off or delete a manager, admin, super admin or door account. */
    private function abortIfLocked(Request $request, User $user): void
    {
        abort_if(Roles::isProtected($user->User_Type) && !Roles::isSuper($request->user()->User_Type), 403);
    }

    private function back(string $id): RedirectResponse
    {
        return redirect()->back()->withFragment('u-' . $id);
    }
}
