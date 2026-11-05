<?php

namespace App\Http\Controllers;

use App\Services\BookingRules;
use App\Services\MealPlan;
use App\Services\MealTimes;
use App\Services\Standing;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** The employee's profile: account details, no-show history, password and (responsibles) sandwich orders. */
class ProfileController extends Controller
{
    private const MEALS = ['breakfast', 'lunch', 'dinner'];

    public function show(Request $request): View
    {
        $user = $request->user();
        $rules = BookingRules::current();
        $plan = MealPlan::for($user, $rules);
        $standing = Standing::read($user, true, $rules);

        // e.g. "Lunch 11:30–12:30 (Group 1)"; meals without a group show their usual time.
        $groups = MealTimes::groups();
        $department = MealTimes::departmentRow((string) $user->Department);
        $mealTimes = collect($plan['meals'])->map(function (string $meal) use ($user, $groups, $department, $rules) {
            $group = MealTimes::resolve(null, $user, $meal, $department, $groups);
            $time = MealTimes::label($meal, $group, $rules);
            return $rules->mealLabel($meal) . ($time !== '' ? " {$time}" : '') . ($group && $group->name !== '' && $time !== '' ? " ({$group->name})" : '');
        })->implode(' · ');

        // Missed meals, newest first. Only the current period counts; once it resets, earlier ones are history.
        $all = [];
        foreach (DB::table('oder')->where('user', $user->id)->orderByDesc('date')->get() as $row) {
            foreach (self::MEALS as $meal) {
                $column = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'][$meal];
                if ((int) $row->{$column} === 1 && (int) $row->{'no_show_' . $meal} === 1) {
                    $all[] = ['date' => Days::keyOf($row->date), 'meal' => $meal];
                }
            }
        }
        $periodStart = $standing['period_start'];
        $noShows = $periodStart !== ''
            ? array_values(array_filter($all, fn ($n) => $n['date'] >= $periodStart))
            : ($standing['strikes'] > 0 ? $all : []);

        return view('pages.profile', [
            'rules' => $rules,
            'plan' => $plan,
            'mealTimes' => $mealTimes,
            'standing' => $standing,
            'resetsOn' => $periodStart !== '' ? Days::add($periodStart, $rules->periodDays) : '',
            'blockedUntil' => $standing['blocked_until'] > Days::today() ? $standing['blocked_until'] : '',
            'noShows' => $noShows,
            'earlierCount' => count($all) - count($noShows),
            'responsible' => $user->role() === 'responsible',
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();
        $current = (string) $request->input('current');
        $next = (string) $request->input('password');
        $confirm = (string) $request->input('password_confirmation');

        $error = match (true) {
            $next !== $confirm => 'New passwords do not match.',
            mb_strlen($next) < 8 => 'New password must be at least 8 characters.',
            $next === $current => 'New password must be different from the current one.',
            $next === (string) config('mealboard.default_password') => 'Choose a password other than the default one.',
            $current === '' || !password_verify($current, (string) $user->password) => 'Current password is incorrect.',
            default => null,
        };
        if ($error !== null) {
            return redirect('/profile#password')->with('password_status', ['tone' => 'error', 'text' => $error]);
        }

        DB::table('user')->where('id', $user->id)->update([
            'password' => Hash::make($next),
            'tokenKey' => bin2hex(random_bytes(15)),
            'updated' => Ids::now(),
        ]);
        // Signed out everywhere else (door app, other devices); this session stays.
        DB::table('auth_tokens')->where('user_id', $user->id)->delete();
        Auth::login($user->fresh());
        $request->session()->regenerate();

        return redirect('/profile#password')->with('password_status', ['tone' => 'success', 'text' => 'Password updated.']);
    }

    /** A responsible orders sandwiches for their team. */
    public function sandwiches(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role() === 'responsible', 403);

        $date = (string) $request->input('date');
        $quantity = $request->input('quantity');
        $rules = BookingRules::current();

        $error = match (true) {
            !is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity < 1 || (int) $quantity > 10 => 'Enter a whole number of sandwiches.',
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < Days::today() => 'The sandwich ordering deadline has passed.',
            Days::now() >= Days::fromKey($date)->setTime($rules->sandwichDeadlineHour, 0) => 'The sandwich ordering deadline has passed.',
            default => null,
        };
        if ($error !== null) {
            return redirect('/profile#sandwiches')->with('sandwich_status', $error);
        }

        $now = Ids::now();
        DB::table('sandwich_orders')->insert([
            'id' => Ids::new(), 'user' => $user->id, 'quantity' => (int) $quantity,
            'date' => Days::toDb($date), 'created' => $now, 'updated' => $now,
        ]);
        return redirect('/profile#sandwiches')->with('sandwich_status', 'Sandwich order submitted.');
    }
}
