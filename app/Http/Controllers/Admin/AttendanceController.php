<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\Standing;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin: mark who didn't show up. With a meal picked the button marks that meal; with "All meals" every booked
 * meal of the order. Each missed meal is one strike, added or taken back here (same rules as the PHP API).
 */
class AttendanceController extends Controller
{
    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function index(Request $request): View
    {
        $rules = BookingRules::current();
        $today = Days::today();
        $date = (string) $request->query('date', $today);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date <= $today ? $date : $today;
        $meal = (string) $request->query('meal', 'all');
        $meal = isset(self::COLUMN[$meal]) ? $meal : 'all';

        $users = DB::table('user')->get()->keyBy('id');
        $orders = [];
        foreach (DB::table('oder')->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")->get() as $row) {
            $booked = array_keys(array_filter(self::COLUMN, fn ($c) => (int) $row->{$c} === 1));
            if ($booked === []) {
                continue;
            }
            $person = $users->get($row->user);
            $orders[] = [
                'id' => $row->id,
                'userId' => (string) $row->user,
                'name' => $person ? (trim($person->firstname . ' ' . $person->lastname) ?: (string) $person->email) : t('Unknown user'),
                'meals' => $booked,
                'missed' => array_values(array_filter($booked, fn ($m) => (int) $row->{'no_show_' . $m} === 1)),
                'standing' => $person ? Standing::read($person, false, $rules) : null,
            ];
        }
        usort($orders, fn ($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        $visible = array_values(array_filter($orders, fn ($o) => $meal === 'all' || in_array($meal, $o['meals'], true)));
        $missedCount = 0;
        foreach ($visible as $o) {
            $target = $meal === 'all' ? $o['meals'] : [$meal];
            $missedCount += count(array_intersect($target, $o['missed']));
        }

        return view('admin.attendance', [
            'rules' => $rules,
            'today' => $today,
            'date' => $date,
            'meal' => $meal,
            'orders' => $visible,
            'missedCount' => $missedCount,
            'status' => session('attendance_status'),
        ]);
    }

    /** Marks (or, with undo, un-marks) the target meals of an order. */
    public function toggle(Request $request, string $id): RedirectResponse
    {
        $order = DB::table('oder')->where('id', $id)->first();
        abort_if($order === null, 404);
        $day = Days::keyOf($order->date);
        $back = "/attendance?date={$day}&meal=" . (string) $request->input('meal', 'all');
        abort_if($day > Days::today(), 422);

        $booked = array_keys(array_filter(self::COLUMN, fn ($c) => (int) $order->{$c} === 1));
        $asked = array_values(array_intersect($booked, array_filter(explode(',', (string) $request->input('meals')))));
        $undo = $request->boolean('undo');
        $change = $undo
            ? array_values(array_filter($asked, fn ($m) => (int) $order->{'no_show_' . $m} === 1))
            : array_values(array_filter($asked, fn ($m) => (int) $order->{'no_show_' . $m} === 0));
        if ($change === []) {
            return redirect($back);
        }

        $flags = ['updated' => Ids::now()];
        foreach ($change as $m) {
            $flags['no_show_' . $m] = $undo ? 0 : 1;
        }
        DB::table('oder')->where('id', $id)->update($flags);

        $userId = (string) $order->user;
        foreach ($change as $m) {
            $undo ? Standing::takeBackStrike($userId) : Standing::addStrike($userId, $day, $m, 'missed');
        }

        $person = User::find($userId);
        $name = $person?->fullName() ?? t('Unknown user');
        $standing = $person ? Standing::read($person, false) : ['strikes' => 0, 'blocked_until' => ''];
        $max = BookingRules::current()->maxNoShow;
        $text = match (true) {
            $undo => t('Removed the no-show for {0}.', [0 => $name]),
            $standing['blocked_until'] !== '' => t('{0} is now blocked until {1}.', [0 => $name, 1 => Days::fromKey($standing['blocked_until'])->translatedFormat('l j F')]),
            default => t('No-show recorded for {0} ({1} of {2}).', [0 => $name, 1 => $standing['strikes'], 2 => $max]),
        };
        return redirect($back)->with('attendance_status', $text);
    }
}
