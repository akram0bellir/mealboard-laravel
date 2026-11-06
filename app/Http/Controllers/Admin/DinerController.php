<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\Bookings;
use App\Services\Notifier;
use App\Services\Standing;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin, full power over who eats: put people on any day's meals whatever the rules say (past or locked days,
 * paused people, meals outside their plan, Ramadan, switched-off accounts, people who never booked), for one day
 * or a range of days, for chosen people or everyone in the list; take them off again; mark meals served or not.
 * No strike is ever added here; clearing a "missed" mark gives its strike back.
 */
class DinerController extends Controller
{
    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    /** Longest range booked at once. */
    public const MAX_DAYS = 62;

    public function index(Request $request): View
    {
        $rules = BookingRules::current();
        $date = $this->dateOf((string) $request->query('date', ''));
        $q = trim((string) $request->query('q', ''));
        $dept = (string) $request->query('dept', '');

        $users = DB::table('user')->orderBy('firstname')->orderBy('lastname')->get();
        $all = $this->matching($users, $q, $dept);
        $people = $all->take(200)->values(); // the list shows 200 at most; "everyone" books all of them

        $byId = $users->keyBy('id');
        $booked = [];
        foreach (DB::table('oder')->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")->get() as $o) {
            $meals = array_keys(array_filter(self::COLUMN, fn ($c) => (int) $o->{$c} === 1));
            if ($meals === []) {
                continue;
            }
            $u = $byId->get($o->user);
            $booked[] = [
                'id' => $o->id,
                'name' => $u ? (trim($u->firstname . ' ' . $u->lastname) ?: (string) $u->email) : t('Unknown user'),
                'dept' => (string) ($u->Department ?? ''),
                'meals' => $meals,
                'served' => array_values(array_filter($meals, fn ($m) => (int) $o->{'attended_' . $m} === 1)),
                'missed' => array_values(array_filter($meals, fn ($m) => (int) $o->{'no_show_' . $m} === 1)),
            ];
        }
        usort($booked, fn ($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
        $totals = array_fill_keys(array_keys(self::COLUMN), 0);
        foreach ($booked as $b) {
            foreach ($b['meals'] as $m) {
                $totals[$m]++;
            }
        }

        return view('admin.diners', [
            'rules' => $rules,
            'date' => $date,
            'q' => $q,
            'dept' => $dept,
            'departments' => $users->pluck('Department')->map(fn ($d) => (string) $d)->filter()->unique()->sort()->values()->all(),
            'people' => $people,
            'matches' => $all->count(),
            'booked' => $booked,
            'totals' => $totals,
            'status' => session('diners_status'),
            'maxDays' => self::MAX_DAYS,
        ]);
    }

    /**
     * Puts the ticked people (or everyone in the current list) on the ticked meals, for the day or every day up to
     * "until". With "only days with a menu", a meal is booked only on days whose menu serves it.
     */
    public function store(Request $request): RedirectResponse
    {
        $date = $this->dateOf((string) $request->input('date', ''));
        $back = "/diners?date={$date}";
        $meals = array_values(array_intersect(array_keys(self::COLUMN), (array) $request->input('meals', [])));
        if ($request->boolean('everyone')) {
            $ids = $this->matching(DB::table('user')->get(), trim((string) $request->input('q', '')), (string) $request->input('dept', ''))->pluck('id')->all();
        } else {
            $ids = array_values(array_unique(array_filter(array_map('strval', (array) $request->input('users', [])))));
        }
        if ($meals === [] || $ids === []) {
            return redirect($back)->with('diners_status', [false, t('Tick at least one person and one meal.')]);
        }

        $until = trim((string) $request->input('until', ''));
        $until = $until !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) ? $until : $date;
        if ($until < $date) {
            return redirect($back)->with('diners_status', [false, t('The last day must be on or after the first day.')]);
        }
        $days = [];
        for ($d = $date; $d <= $until; $d = Days::add($d, 1)) {
            $days[] = $d;
            if (count($days) > self::MAX_DAYS) {
                return redirect($back)->with('diners_status', [false, t('At most {0} days at once.', [0 => self::MAX_DAYS])]);
            }
        }
        $served = $request->boolean('menu_only') ? $this->servedMeals($days[0], end($days)) : null;

        $people = DB::table('user')->whereIn('id', $ids)->pluck('id')->flip();
        $label = fn (array $m) => implode(' + ', array_map(fn ($x) => t(ucfirst($x)), $m));
        $added = 0;
        $already = 0;
        $skipped = 0;
        $told = [];
        foreach ($ids as $id) {
            if (!isset($people[$id])) {
                continue;
            }
            $mine = [];
            foreach ($days as $day) {
                $wanted = $served === null ? $meals : array_values(array_intersect($meals, $served[$day] ?? []));
                $skipped += count($meals) - count($wanted);
                if ($wanted === []) {
                    continue;
                }
                $r = Bookings::adminAdd($id, $day, $wanted);
                $added += count($r['added']);
                $already += count($r['already']);
                if ($r['added']) {
                    $mine[] = [$day, $r['added']];
                }
            }
            if ($mine && $request->boolean('notify', true)) {
                $text = count($mine) === 1
                    ? t('An admin booked you for {0} on {1}.', [0 => $label($mine[0][1]), 1 => Days::fromKey($mine[0][0])->translatedFormat('l j F')])
                    : t('An admin booked you for {0} meals between {1} and {2}.', [0 => array_sum(array_map(fn ($x) => count($x[1]), $mine)), 1 => Days::fromKey($mine[0][0])->translatedFormat('j F'), 2 => Days::fromKey(end($mine)[0])->translatedFormat('j F')]);
                Notifier::send([$id], t('Meal booked for you'), $text, 'Booking', $request->user()->id);
                $told[] = $id;
            }
        }
        $when = count($days) === 1 ? Days::fromKey($date)->translatedFormat('l j F') : t('{0} days', [0 => count($days)]);
        $text = t('Added {0} meal(s) for {1} person(s) on {2}.', [0 => $added, 1 => count($people), 2 => $when])
            . ($already ? ' ' . t('{0} were already booked.', [0 => $already]) : '')
            . ($skipped ? ' ' . t('{0} skipped: no menu for that meal.', [0 => $skipped]) : '');
        return redirect($back)->with('diners_status', [true, $text]);
    }

    /** Takes a person (or one meal) off the day's bookings. */
    public function remove(Request $request, string $id): RedirectResponse
    {
        $order = DB::table('oder')->where('id', $id)->first();
        abort_if($order === null, 404);
        $date = Days::keyOf($order->date);
        $meals = array_values(array_filter(explode(',', (string) $request->input('meals'))));
        $removed = Bookings::adminRemove($id, $meals ?: null);
        if ($removed !== [] && $request->boolean('notify', true)) {
            Notifier::send([(string) $order->user], t('Meal cancelled'), t('An admin cancelled your {0} on {1}.', [0 => implode(' + ', array_map(fn ($x) => t(ucfirst($x)), $removed)), 1 => Days::fromKey($date)->translatedFormat('l j F')]), 'Booking', $request->user()->id);
        }
        return redirect("/diners?date={$date}")->with('diners_status', [true, $removed ? t('Removed.') : t('Nothing to remove.')]);
    }

    /** Marks a booked meal served (also clearing a "missed" mark and giving its strike back), or not served. */
    public function served(Request $request, string $id): RedirectResponse
    {
        $order = DB::table('oder')->where('id', $id)->first();
        abort_if($order === null, 404);
        $date = Days::keyOf($order->date);
        $meal = (string) $request->input('meal');
        if (!isset(self::COLUMN[$meal]) || (int) $order->{self::COLUMN[$meal]} !== 1) {
            return redirect("/diners?date={$date}")->with('diners_status', [false, t('That meal is not booked.')]);
        }
        $on = $request->boolean('served');
        if ($on && (int) $order->{'no_show_' . $meal} === 1) {
            Standing::takeBackStrike((string) $order->user);
        }
        DB::table('oder')->where('id', $id)->update($on
            ? ['attended_' . $meal => 1, 'no_show_' . $meal => 0, 'updated' => Ids::now()]
            : ['attended_' . $meal => 0, 'updated' => Ids::now()]);
        return redirect("/diners?date={$date}")->with('diners_status', [true, $on ? t('Marked served.') : t('Served mark removed.')]);
    }

    /** The people of the list: search text (name, e-mail, username, numbers, position) and department. */
    private function matching(Collection $users, string $q, string $dept): Collection
    {
        $needle = mb_strtolower($q);
        return $users->filter(function ($u) use ($needle, $dept) {
            if ($dept !== '' && (string) $u->Department !== $dept) {
                return false;
            }
            return $needle === '' || str_contains(mb_strtolower(implode(' ', [$u->firstname, $u->lastname, $u->email, $u->username, $u->id_user, $u->card_id, $u->Position])), $needle);
        })->sortBy(fn ($u) => mb_strtolower($u->firstname . ' ' . $u->lastname));
    }

    /** The meals each day's menu serves: [day => [meal, ...]]. */
    private function servedMeals(string $from, string $to): array
    {
        $out = [];
        foreach (DB::table('meun')->where('day', '>=', "$from 00:00:00.000")->where('day', '<=', "$to 23:59:59.999")->get() as $r) {
            foreach (['breakfast' => 'brakfast_title', 'lunch' => 'lunch_title', 'dinner' => 'Dinner_title'] as $meal => $column) {
                if (trim((string) $r->{$column}) !== '') {
                    $out[Days::keyOf($r->day)][] = $meal;
                }
            }
        }
        return $out;
    }

    private function dateOf(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : Days::today();
    }
}
