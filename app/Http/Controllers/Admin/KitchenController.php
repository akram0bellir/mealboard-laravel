<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagerMeals;
use App\Services\Settings;
use App\Support\Days;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class KitchenController extends Controller
{
    private const MEALS = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function index(Request $request): View
    {
        $today = Days::today();
        $from = (string) $request->query('from', substr($today, 0, 7).'-01');
        $to = (string) $request->query('to', CarbonImmutable::createFromFormat('Y-m-d', substr($today, 0, 7).'-01')->endOfMonth()->format('Y-m-d'));
        $validator = Validator::make(
            ['from' => $from, 'to' => $to, 'meal' => $request->query('meal', 'all'), 'department' => $request->query('department', '')],
            [
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function ($attribute, $value, $fail) use ($from) {
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $start)
                        && checkdate((int) $start[2], (int) $start[3], (int) $start[1])
                        && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $end)
                        && checkdate((int) $end[2], (int) $end[3], (int) $end[1])
                        && CarbonImmutable::createFromFormat('Y-m-d', $from)->diffInDays(CarbonImmutable::createFromFormat('Y-m-d', $value)) > 365) {
                        $fail(t('Choose a date range of one year or less.'));
                    }
                }],
                'meal' => ['required', 'in:all,breakfast,lunch,dinner'],
                'department' => ['nullable', 'string', 'max:100'],
            ],
        );
        $filters = $validator->validate();

        $departments = DB::table('user')->whereNotNull('Department')->where('Department', '<>', '')
            ->distinct()->orderBy('Department')->pluck('Department')->map(fn ($d) => (string) $d)->all();
        if ($filters['department'] !== '' && ! in_array($filters['department'], $departments, true)) {
            $filters['department'] = '';
        }

        $daily = [];
        for ($date = $filters['from']; $date <= $filters['to']; $date = Days::add($date, 1)) {
            $daily[$date] = ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0];
        }

        $startAt = $filters['from'].' 00:00:00.000';
        $endAt = $filters['to'].' 23:59:59.999';
        $orders = DB::table('oder as o')
            ->leftJoin('user as u', 'u.id', '=', 'o.user')
            ->where('o.date', '>=', $startAt)
            ->where('o.date', '<=', $endAt)
            ->when($filters['department'] !== '', fn ($q) => $q->where('u.Department', $filters['department']))
            ->get(['o.date', 'o.bool', 'o.bool2', 'o.bool3']);

        foreach ($orders as $order) {
            $date = Days::keyOf($order->date);
            if (! isset($daily[$date])) {
                continue;
            }
            foreach (self::MEALS as $meal => $column) {
                if (($filters['meal'] === 'all' || $filters['meal'] === $meal) && (int) $order->{$column} === 1) {
                    $daily[$date][$meal]++;
                }
            }
        }

        if ($filters['department'] === '') {
            $menus = DB::table('meun')->where('day', '>=', $startAt)->where('day', '<=', $endAt)->get();
            $served = [];
            foreach ($menus as $menu) {
                $date = Days::keyOf($menu->day);
                foreach (['breakfast' => 'brakfast_title', 'lunch' => 'lunch_title', 'dinner' => 'Dinner_title'] as $meal => $column) {
                    if (trim((string) $menu->{$column}) !== '') {
                        $served[$date][] = $meal;
                    }
                }
            }
            foreach ($daily as $date => &$counts) {
                foreach (ManagerMeals::on($date, $served[$date] ?? []) as $meal => $count) {
                    if ($filters['meal'] === 'all' || $filters['meal'] === $meal) {
                        $counts[$meal] += $count;
                    }
                }
            }
            unset($counts);
        }

        $prices = Settings::prices()['meals'];
        $months = [];
        foreach ($daily as $date => $counts) {
            $month = substr($date, 0, 7);
            $months[$month] ??= ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'total' => 0];
            foreach ($counts as $meal => $count) {
                $months[$month][$meal] += $count;
                $months[$month]['total'] += $count;
            }
        }

        $totals = ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'total' => 0, 'cost' => 0.0];
        foreach ($daily as $counts) {
            foreach ($counts as $meal => $count) {
                $totals[$meal] += $count;
                $totals['total'] += $count;
                $totals['cost'] += $count * $prices[$meal];
            }
        }

        return view('admin.kitchen', [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'meal' => $filters['meal'],
            'department' => $filters['department'],
            'departments' => $departments,
            'daily' => $daily,
            'months' => $months,
            'totals' => $totals,
            'currency' => Settings::invoice()['currency'],
        ]);
    }
}
