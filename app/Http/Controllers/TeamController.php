<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BookingRules;
use App\Services\MealTimes;
use App\Services\Notifier;
use App\Support\Days;
use App\Support\Ids;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A responsible's team for tomorrow: switch someone's meal (and its group) for that day only.
 * Open until 23:59 the day before. Same rules as the PHP API's mealboard/my-team.
 */
class TeamController extends Controller
{
    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function show(Request $request): View
    {
        $me = $request->user();
        $rules = BookingRules::current();
        $day = (string) $request->query('day', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = Days::add(Days::today(), 1);
        }

        $isResponsible = $me->role() === 'responsible';
        $groups = MealTimes::groups();
        $people = [];

        if ($isResponsible) {
            $orders = DB::table('oder')
                ->where('date', '>=', $day . ' 00:00:00.000')->where('date', '<=', $day . ' 23:59:59.999')
                ->get()->keyBy('user');
            $department = MealTimes::departmentRow((string) $me->Department);
            $query = mb_strtolower(trim((string) $request->query('q', '')));

            $rows = User::where('Status', 1)->where('Department', $me->Department)->get()
                ->reject(fn (User $p) => in_array($p->role(), ['admin', 'super_admin', 'door'], true))
                ->sortBy(fn (User $p) => mb_strtolower($p->firstname . ' ' . $p->lastname));

            foreach ($rows as $person) {
                $order = $orders->get($person->id);
                $meals = [];
                if ($order !== null) {
                    foreach (self::COLUMN as $meal => $column) {
                        if ((int) $order->{$column} === 1) {
                            $meals[] = ['meal' => $meal, 'group' => MealTimes::resolve($order->day_group, $person, $meal, $department, $groups)];
                        }
                    }
                }
                $entry = [
                    'id' => $person->id,
                    'name' => $person->fullName(),
                    'employeeId' => $person->id_user === null ? '' : (string) $person->id_user,
                    'position' => (string) $person->Position,
                    'booking' => $meals === [] ? null : [
                        'orderId' => $order->id,
                        'meals' => $meals,
                        'switched' => trim((string) $order->day_group) !== '',
                        'switchedBy' => $order->switched_by ? (User::find($order->switched_by)?->fullName() ?? '') : '',
                    ],
                ];
                if ($query === '' || str_contains(mb_strtolower("{$entry['name']} {$entry['employeeId']} {$entry['position']}"), $query)) {
                    $people[] = $entry;
                }
            }
            // Booked first, then the rest, by name.
            usort($people, fn ($a, $b) => ($b['booking'] !== null) <=> ($a['booking'] !== null));
        }

        return view('pages.team', [
            'rules' => $rules,
            'isResponsible' => $isResponsible,
            'day' => $day,
            'open' => $day > Days::today(),
            'people' => $people,
            'booked' => count(array_filter($people, fn ($p) => $p['booking'] !== null)),
            'groups' => $groups->values(),
            'edit' => (string) $request->query('edit', ''),
            'q' => (string) $request->query('q', ''),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $me = $request->user();
        $back = '/team' . ($request->input('q') ? '?q=' . urlencode((string) $request->input('q')) : '');

        $order = DB::table('oder')->where('id', (string) $request->input('orderId'))->first();
        $person = $order === null ? null : User::find($order->user);
        if ($order === null || $person === null) {
            return $this->fail($back, $request, 'This booking was not found.');
        }
        if ($me->role() !== 'responsible') {
            abort(403);
        }
        if ((string) $person->Department !== (string) $me->Department) {
            return $this->fail($back, $request, 'You can only switch bookings of people in your department.');
        }

        $day = Days::keyOf($order->date);
        if ($day === '' || $day <= Days::today()) {
            return $this->fail($back, $request, sprintf('Too late: bookings for %s could be switched until 23:59 the day before.', $day === '' ? 'that day' : self::fmtDay($day)));
        }

        $meals = array_keys(array_filter(self::COLUMN, fn ($column) => (int) $order->{$column} === 1));
        if ($meals === []) {
            return $this->fail($back, $request, 'This booking has no meal to switch.');
        }
        $to = MealTimes::mealOf((string) $request->input('to'));
        if ($to === null) {
            return $this->fail($back, $request, 'Pick the new meal: breakfast, lunch or dinner.');
        }
        $from = MealTimes::mealOf((string) $request->input('from')) ?? (count($meals) === 1 ? $meals[0] : null);
        if ($from === null) {
            return $this->fail($back, $request, 'This booking has several meals: say which one to switch.');
        }
        if (!in_array($from, $meals, true)) {
            return $this->fail($back, $request, ucfirst($from) . ' is not booked that day.');
        }
        if ($to !== $from && in_array($to, $meals, true)) {
            return $this->fail($back, $request, ucfirst($to) . ' is already booked that day.');
        }

        $rules = BookingRules::current();
        if ($rules->ramadan && $to === 'lunch') {
            return $this->fail($back, $request, 'Lunch is closed during Ramadan.');
        }

        $groups = MealTimes::groups();
        $groupId = trim((string) $request->input('groupId'));
        if ($groupId !== '') {
            if (!$groups->has($groupId)) {
                return $this->fail($back, $request, 'This group was not found.');
            }
            $groupMeal = MealTimes::mealOf((string) $groups[$groupId]->meal);
            if ($groupMeal !== $to) {
                return $this->fail($back, $request, sprintf('"%s" is a %s group, not a %s group.', $groups[$groupId]->name, $groupMeal ?? 'meal', $to));
            }
        }

        $department = MealTimes::departmentRow((string) $person->Department);
        $current = MealTimes::resolve($order->day_group, $person, $from, $department, $groups);
        $usual = $groupId !== '' ? $groups[$groupId] : MealTimes::resolve(null, $person, $to, $department, $groups);
        if ($to === $from && ($current->id ?? '') === ($usual->id ?? '')) {
            return $this->fail($back, $request, 'Nothing to change.');
        }

        $data = ['day_group' => $groupId === '' ? null : $groupId, 'switched_by' => $me->id, 'updated' => Ids::now()];
        if ($to !== $from) {
            $data[self::COLUMN[$from]] = 0;
            $data[self::COLUMN[$to]] = 1;
        }
        DB::table('oder')->where('id', $order->id)->update($data);

        // Tell the person.
        $name = 'the usual group';
        $start = $end = '';
        if ($usual !== null) {
            $name = (string) $usual->name;
            $start = (string) ($rules->ramadan && $usual->ramadan_start !== '' ? $usual->ramadan_start : $usual->start);
            $end = (string) ($rules->ramadan && $usual->ramadan_end !== '' ? $usual->ramadan_end : $usual->end);
        }
        $when = self::fmtDay($day);
        $message = $to !== $from
            ? sprintf('%s changed your booking for %s: your meal is now %s (%s, %s–%s) instead of %s. This is for that day only.', $me->fullName(), $when, $to, $name, $start, $end, $from)
            : sprintf('%s changed your booking for %s: your %s time is now %s (%s–%s). This is for that day only.', $me->fullName(), $when, $to, $name, $start, $end);
        Notifier::send([$person->id], 'Meal switched for ' . $when, $message, 'Meal switch', $me->id);

        return redirect($back)->with('team_status', ['tone' => 'ok', 'text' => 'Saved. ' . $person->fullName() . ' was told.']);
    }

    private function fail(string $back, Request $request, string $message): RedirectResponse
    {
        $edit = $request->input('orderId') . ':' . $request->input('from');
        return redirect($back . (str_contains($back, '?') ? '&' : '?') . 'edit=' . urlencode($edit))->with('team_status', ['tone' => 'error', 'text' => $message]);
    }

    private static function fmtDay(string $day): string
    {
        return Days::fromKey($day)->locale('en')->format('l j M');
    }
}
