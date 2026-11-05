<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\ManagerMeals;
use App\Services\MealTimes;
use App\Services\Notifier;
use App\Services\Settings;
use App\Support\Days;
use App\Support\Ids;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Super admin: prices and invoice terms, invoice details, meal times and Ramadan mode, the no-show rules,
 * department rules, SMTP, the daily order list and manager meals. Each card is its own form and saves to the
 * same tables as the PHP API (price_of_meal, _mealboard_settings, meal_groups, booking_rules, ...).
 */
class SettingsController extends Controller
{
    private const DEPARTMENTS = ['1', '2', '3'];

    private const PRICE_COLUMNS = [
        'breakfast' => 'brakfast', 'lunch' => 'lunch', 'dinner' => 'dinner',
        'enhanced' => 'Enhanced_meal', 'vip' => 'vip', 'special' => 'Special_meal', 'snacks' => 'Snacks', 'vip_snacks' => 'VIP_snacks',
    ];

    public function index(Request $request): View
    {
        if (!Roles::isSuper($request->user()->User_Type)) {
            return view('admin.soon', ['page' => 'Settings']);
        }

        $rules = BookingRules::current();
        $ruleRow = DB::table('booking_rules')->first();
        $byId = MealTimes::groups();
        $groups = $byId->values();
        $deptRows = DB::table('department_rules')->get()->keyBy('department');
        $users = DB::table('user')->where('Status', 1)->get();

        // How many active people each group has (their own group, else their department's).
        $groupCounts = [];
        foreach ($groups as $g) {
            $meal = MealTimes::mealOf($g->meal);
            $groupCounts[$g->id] = $users->filter(fn ($u) => (int) $u->{ucfirst($meal)} === 1
                && MealTimes::resolve(null, $u, $meal, $deptRows->get($u->Department), $byId)?->id === $g->id)->count();
        }

        $auto = trim((string) ($ruleRow->auto_no_show_from ?? ''));
        preg_match('/^(\d{4}-\d{2}-\d{2})\s+(breakfast|brakfast|lunch|dinner)$/i', $auto, $m);
        $daily = array_merge(['recipients' => [], 'auto' => false, 'hour' => 14, 'day' => 'tomorrow'], Settings::get('daily_orders'));
        $daily['last'] = DB::table('_mealboard_daily_sent')->orderByDesc('tried_at')->first();
        $smtp = DB::table('smtp')->first();
        $managers = ManagerMeals::load();

        return view('admin.settings', [
            'rules' => $rules,
            'autoOn' => $m !== [],
            'autoDay' => $m[1] ?? Days::add(Days::today(), 1),
            'autoMeal' => isset($m[2]) ? (MealTimes::mealOf($m[2]) ?? 'breakfast') : 'breakfast',
            'prices' => Settings::prices(),
            'hasVip' => Schema::hasColumn('price_of_meal', 'vip'),
            'invoice' => Settings::invoice(),
            'groups' => $groups,
            'groupCounts' => $groupCounts,
            'deptRows' => $deptRows,
            'departments' => self::DEPARTMENTS,
            'openLunch' => $rules->ramadan ? $this->openLunch()->count() : 0,
            'smtp' => $smtp,
            'daily' => $daily,
            'managers' => $managers,
            'today' => Days::today(),
            'status' => session('settings_status'),
        ]);
    }

    public function prices(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $currency = mb_strtoupper(trim((string) $request->input('currency')));
        $vat = (float) $request->input('vat');
        $days = $request->input('paymentDays');
        $error = match (true) {
            $currency === '' => 'Give a currency, e.g. DZD.',
            $vat < 0 || $vat > 100 => 'VAT must be between 0 and 100.',
            !is_numeric($days) || (int) $days != (float) $days || (int) $days < 0 || (int) $days > 365 => 'Payment days must be a whole number between 0 and 365.',
            default => null,
        };
        if ($error) {
            return $this->back('prices', $error, false);
        }

        $data = ['updated' => Ids::now()];
        foreach (self::PRICE_COLUMNS as $key => $column) {
            if (Schema::hasColumn('price_of_meal', $column)) {
                $data[$column] = max(0, round((float) $request->input("price.{$key}"), 2));
            }
        }
        $row = DB::table('price_of_meal')->first();
        $row ? DB::table('price_of_meal')->where('id', $row->id)->update($data) : DB::table('price_of_meal')->insert($data + ['id' => Ids::new(), 'created' => Ids::now()]);

        Settings::put('invoice', array_merge(Settings::invoice(), ['currency' => mb_substr($currency, 0, 8), 'vat' => round($vat, 2), 'paymentDays' => (int) $days]));
        return $this->back('prices', 'Prices saved. Invoices and penalties use them straight away.');
    }

    public function invoice(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $current = Settings::invoice();
        foreach (['contractorName' => 120, 'companyName' => 120, 'contractorAddress' => 400, 'companyAddress' => 400] as $field => $max) {
            $current[$field] = mb_substr(trim((string) $request->input($field)), 0, $max);
        }
        foreach (['contractorEmail', 'companyEmail'] as $field) {
            $email = mb_strtolower(trim((string) $request->input($field)));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->back('invoice', "Not an e-mail address: {$email}", false);
            }
            $current[$field] = $email;
        }
        Settings::put('invoice', $current);
        return $this->back('invoice', 'Saved. Every invoice uses these details now.');
    }

    public function rules(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $int = fn (string $k) => is_numeric($request->input($k)) && (int) $request->input($k) == (float) $request->input($k) ? (int) $request->input($k) : null;
        $max = $int('maxNoShow');
        $period = $int('periodDays');
        $hour = $int('deadlineHour');
        $before = $int('deadlineDaysBefore');
        $sandwich = $int('sandwichDeadlineHour');
        $autoDay = (string) $request->input('autoDay');
        $error = match (true) {
            $max === null || $max < 1 => 'No-shows allowed must be a whole number of at least 1.',
            $period === null || $period < 1 => 'The period must be at least 1 day.',
            $hour === null || $hour < 0 || $hour > 23 => 'The deadline hour must be between 0 and 23.',
            $before === null || $before < 0 => 'Days before must be 0 or more.',
            $sandwich === null || $sandwich < 0 || $sandwich > 23 => 'The sandwich deadline hour must be between 0 and 23.',
            $request->boolean('autoNoShow') && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $autoDay) => 'Pick the day automatic no-shows start from.',
            default => null,
        };
        if ($error) {
            return $this->back('rules', $error, false);
        }

        $meal = in_array($request->input('autoMeal'), BookingRules::MEALS, true) ? $request->input('autoMeal') : 'breakfast';
        $row = DB::table('booking_rules')->first();
        $data = [
            'max_no_show' => $max,
            'period_days' => $period,
            'cancel_deadline_hour' => $hour,
            'cancel_deadline_days_before' => $before,
            'cancel_deadline_hour_for_sandwitch' => $sandwich,
            'late_cancel_counts' => $request->boolean('lateCancelCounts') ? 1 : 0,
            'auto_no_show_from' => $request->boolean('autoNoShow') ? "{$autoDay} {$meal}" : '',
            'updated' => Ids::now(),
        ];
        $row ? DB::table('booking_rules')->where('id', $row->id)->update($data) : DB::table('booking_rules')->insert($data + ['id' => Ids::new(), 'created' => Ids::now()]);
        return $this->back('rules', 'Rules saved. They apply to everyone straight away.');
    }

    /** Ramadan mode for everyone. Turning it on cancels the lunch bookings still to come and tells the people. */
    public function ramadan(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $on = $request->boolean('on');
        DB::table('booking_rules')->update(['ramadan_mode' => $on ? 1 : 0, 'updated' => Ids::now()]);
        if (!$on) {
            return $this->back('times', 'Ramadan mode is off. Normal meals and times are back.');
        }

        $perUser = [];
        foreach ($this->openLunch() as $row) {
            if ((int) $row->bool === 1 || (int) $row->bool3 === 1) {
                DB::table('oder')->where('id', $row->id)->update(['bool2' => 0, 'updated' => Ids::now()]);
            } else {
                DB::table('oder')->where('id', $row->id)->delete();
            }
            $perUser[(string) $row->user] = ($perUser[(string) $row->user] ?? 0) + 1;
        }
        foreach ($perUser as $user => $count) {
            Notifier::send([$user], 'Lunch is closed during Ramadan',
                $count . ' upcoming lunch booking' . ($count === 1 ? ' was' : 's were') . ' cancelled. You can book suhoor or iftar instead.', 'Ramadan');
        }
        $text = 'Ramadan mode is on for everyone.' . ($perUser ? ' Cancelled ' . array_sum($perUser) . ' lunch booking(s) and told ' . count($perUser) . ' person(s).' : '');
        return $this->back('times', $text);
    }

    public function addGroup(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $meal = (string) $request->input('meal');
        $start = (string) $request->input('start');
        $end = (string) $request->input('end');
        if (!in_array($meal, BookingRules::MEALS, true) || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            return $this->back('times', 'Give the group a start and an end time.', false);
        }
        if ($end <= $start) {
            return $this->back('times', 'The end time must be after the start time.', false);
        }
        $count = DB::table('meal_groups')->get()->filter(fn ($g) => MealTimes::mealOf($g->meal) === $meal)->count();
        $now = Ids::now();
        DB::table('meal_groups')->insert([
            'id' => Ids::new(), 'name' => trim((string) $request->input('name')) ?: 'Group ' . ($count + 1),
            'meal' => $meal === 'breakfast' ? 'brakfast' : $meal, 'start' => $start, 'end' => $end,
            'ramadan_start' => '', 'ramadan_end' => '', 'created' => $now, 'updated' => $now,
        ]);
        return $this->back('times', 'Saved.');
    }

    /** Deleting a group sends its departments to the normal time and its people back to their department's group. */
    public function deleteGroup(Request $request, string $id): RedirectResponse
    {
        $this->superOnly($request);
        $group = DB::table('meal_groups')->where('id', $id)->first();
        abort_if($group === null, 404);
        $column = MealTimes::mealOf($group->meal) . '_group';
        DB::table('department_rules')->where($column, $id)->update([$column => null, 'updated' => Ids::now()]);
        DB::table('user')->where($column, $id)->update([$column => null, 'updated' => Ids::now()]);
        DB::table('meal_groups')->where('id', $id)->delete();
        return $this->back('times', 'Saved.');
    }

    public function ramadanTimes(Request $request, string $id): RedirectResponse
    {
        $this->superOnly($request);
        $start = (string) $request->input('ramadan_start');
        $end = (string) $request->input('ramadan_end');
        if (($start === '') !== ($end === '')) {
            return $this->back('times', 'Pick both the Ramadan start and end, or neither.', false);
        }
        if ($start !== '' && $end <= $start) {
            return $this->back('times', 'The Ramadan end time must be after its start.', false);
        }
        DB::table('meal_groups')->where('id', $id)->update(['ramadan_start' => $start, 'ramadan_end' => $end, 'updated' => Ids::now()]);
        return $this->back('times', 'Saved.');
    }

    /** Each department's meal-time group, and the meals its new users start with (the "department rules"). */
    public function departments(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $card = $request->input('card') === 'departments' ? 'departments' : 'times';
        $groups = DB::table('meal_groups')->pluck('meal', 'id');
        foreach (self::DEPARTMENTS as $d) {
            $data = ['updated' => Ids::now()];
            if ($card === 'departments') {
                foreach (['breakefast' => 'breakfast', 'lunch' => 'lunch', 'dinner' => 'dinner'] as $column => $meal) {
                    $data[$column] = $request->boolean("meals.{$d}.{$meal}") ? 1 : 0;
                }
            } else {
                foreach (BookingRules::MEALS as $meal) {
                    $g = (string) $request->input("groups.{$d}.{$meal}");
                    $data["{$meal}_group"] = $g !== '' && $groups->has($g) && MealTimes::mealOf((string) $groups[$g]) === $meal ? $g : null;
                }
            }
            $row = DB::table('department_rules')->where('department', $d)->first();
            $row
                ? DB::table('department_rules')->where('id', $row->id)->update($data)
                : DB::table('department_rules')->insert($data + ['id' => Ids::new(), 'department' => $d, 'created' => Ids::now()]);
        }
        return $this->back($card, $card === 'departments' ? 'Saved. New users from these departments start with these meals.' : 'Saved.');
    }

    public function smtp(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $address = trim((string) $request->input('senderAddress'));
        if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return $this->back('smtp', "Not an e-mail address: {$address}", false);
        }
        $data = [
            'Sender_name' => trim((string) $request->input('senderName')),
            'Sender_address' => $address,
            'SMTP_server_host' => trim((string) $request->input('serverHost')),
            'Port' => mb_substr(trim((string) $request->input('port')), 0, 8),
            'Username' => trim((string) $request->input('username')),
            'updated' => Ids::now(),
        ];
        if (trim((string) $request->input('password')) !== '') {
            $data['Password'] = (string) $request->input('password'); // an empty password keeps the old one
        }
        $row = DB::table('smtp')->first();
        $row ? DB::table('smtp')->where('id', $row->id)->update($data) : DB::table('smtp')->insert($data + ['id' => Ids::new(), 'Password' => '', 'created' => Ids::now()]);
        return $this->back('smtp', 'SMTP settings saved. New notifications are e-mailed with them straight away.');
    }

    public function daily(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $addresses = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', mb_strtolower((string) $request->input('recipients')))))));
        foreach ($addresses as $a) {
            if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
                return $this->back('daily', "Not an e-mail address: {$a}", false);
            }
        }
        $hour = max(0, min(23, (int) $request->input('hour', 14)));
        $day = $request->input('day') === 'today' ? 'today' : 'tomorrow';
        $auto = $request->boolean('auto');
        Settings::put('daily_orders', array_merge(Settings::get('daily_orders'), ['recipients' => $addresses, 'auto' => $auto, 'hour' => $hour, 'day' => $day]));
        $text = $auto
            ? sprintf('Saved. Every day at %02d:00 the list for %s goes to %d address(es).', $hour, $day === 'tomorrow' ? 'the next day' : 'that day', count($addresses))
            : 'Saved.';
        return $this->back('daily', $text);
    }

    public function managerWeekly(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $existing = DB::table('manager_meals')->whereNull('date')->get()->keyBy('weekday');
        for ($w = 1; $w <= 7; $w++) {
            $counts = [];
            foreach (BookingRules::MEALS as $meal) {
                $counts[$meal] = max(0, (int) round((float) $request->input("week.{$w}.{$meal}", ManagerMeals::DEFAULT_COUNT)));
            }
            $row = $existing->get($w);
            $row
                ? DB::table('manager_meals')->where('id', $row->id)->update($counts + ['updated' => Ids::now()])
                : DB::table('manager_meals')->insert($counts + ['id' => Ids::new(), 'weekday' => $w, 'date' => null, 'created' => Ids::now(), 'updated' => Ids::now()]);
        }
        ManagerMeals::reset();
        return $this->back('managers', 'Saved. Manager meals are booked automatically with these numbers.');
    }

    public function managerDay(Request $request): RedirectResponse
    {
        $this->superOnly($request);
        $date = (string) $request->input('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->back('managers', 'Pick the day.', false);
        }
        $counts = [];
        foreach (BookingRules::MEALS as $meal) {
            $counts[$meal] = max(0, (int) round((float) $request->input("day.{$meal}")));
        }
        $row = DB::table('manager_meals')->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")->first();
        $row
            ? DB::table('manager_meals')->where('id', $row->id)->update($counts + ['updated' => Ids::now()])
            : DB::table('manager_meals')->insert($counts + ['id' => Ids::new(), 'weekday' => 0, 'date' => Days::toDb($date), 'created' => Ids::now(), 'updated' => Ids::now()]);
        ManagerMeals::reset();
        return $this->back('managers', 'Saved for ' . Days::fromKey($date)->locale('en')->translatedFormat('l j F') . '.');
    }

    public function managerDayDelete(Request $request, string $id): RedirectResponse
    {
        $this->superOnly($request);
        DB::table('manager_meals')->where('id', $id)->whereNotNull('date')->delete();
        ManagerMeals::reset();
        return $this->back('managers', 'Removed. That day uses the weekly number again.');
    }

    /* ------------------------------------------------------------------ */

    /** Lunch bookings still to come (from today on) that were not cancelled late. */
    private function openLunch()
    {
        return DB::table('oder')->where('date', '>=', Days::today() . ' 00:00:00.000')->where('bool2', 1)->where('no_show_lunch', 0)->get();
    }

    private function superOnly(Request $request): void
    {
        abort_unless(Roles::isSuper($request->user()->User_Type), 403);
    }

    private function back(string $card, string $text, bool $ok = true): RedirectResponse
    {
        return redirect('/settings#' . $card)->with('settings_status', ['card' => $card, 'text' => $text, 'ok' => $ok]);
    }
}
