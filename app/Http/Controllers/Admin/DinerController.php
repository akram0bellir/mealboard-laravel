<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\Bookings;
use App\Services\Notifier;
use App\Support\Days;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin: put people on a day's meals whatever the rules say (past or locked days, paused people, meals outside
 * their plan, Ramadan, switched-off accounts), and take them off again. No strike is ever added here.
 */
class DinerController extends Controller
{
    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function index(Request $request): View
    {
        $rules = BookingRules::current();
        $date = $this->dateOf((string) $request->query('date', ''));
        $q = trim((string) $request->query('q', ''));
        $dept = (string) $request->query('dept', '');

        $users = DB::table('user')->orderBy('firstname')->orderBy('lastname')->get();
        $people = $users->filter(function ($u) use ($q, $dept) {
            if ($dept !== '' && (string) $u->Department !== $dept) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $hay = mb_strtolower(implode(' ', [$u->firstname, $u->lastname, $u->email, $u->username, $u->id_user, $u->card_id, $u->Position]));
            return str_contains($hay, mb_strtolower($q));
        })->take(200)->values();

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
            'matches' => $people->count(),
            'booked' => $booked,
            'totals' => $totals,
            'bookedIds' => collect($booked)->count(),
            'status' => session('diners_status'),
        ]);
    }

    /** Puts the ticked people on the ticked meals of the day. */
    public function store(Request $request): RedirectResponse
    {
        $date = $this->dateOf((string) $request->input('date', ''));
        $back = "/diners?date={$date}";
        $meals = array_values(array_intersect(array_keys(self::COLUMN), (array) $request->input('meals', [])));
        $ids = array_values(array_unique(array_filter(array_map('strval', (array) $request->input('users', [])))));
        if ($meals === [] || $ids === []) {
            return redirect($back)->with('diners_status', [false, t('Tick at least one person and one meal.')]);
        }
        $people = DB::table('user')->whereIn('id', $ids)->get()->keyBy('id');
        $label = fn (array $m) => implode(' + ', array_map(fn ($x) => t(ucfirst($x)), $m));
        $dayText = Days::fromKey($date)->translatedFormat('l j F');

        $added = 0;
        $already = 0;
        foreach ($ids as $id) {
            if (!$people->has($id)) {
                continue;
            }
            $r = Bookings::adminAdd($id, $date, $meals);
            $added += count($r['added']);
            $already += count($r['already']);
            if ($r['added'] && $request->boolean('notify', true)) {
                Notifier::send([$id], t('Meal booked for you'), t('An admin booked you for {0} on {1}.', [0 => $label($r['added']), 1 => $dayText]), 'Booking', $request->user()->id);
            }
        }
        $text = t('Added {0} meal(s) for {1} person(s) on {2}.', [0 => $added, 1 => count($people), 2 => $dayText])
            . ($already ? ' ' . t('{0} were already booked.', [0 => $already]) : '');
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

    private function dateOf(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : Days::today();
    }
}
