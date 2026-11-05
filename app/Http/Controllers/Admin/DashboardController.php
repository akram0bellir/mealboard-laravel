<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminPage;
use App\Services\BookingRules;
use App\Services\DayOrders;
use App\Services\Mailer;
use App\Services\ManagerMeals;
use App\Services\Settings;
use App\Support\Days;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Admin dashboard: today's meals, people and the bookings of this week. */
class DashboardController extends Controller
{
    private const FIELDS = [
        'breakfast' => ['brakfast_title', 'Breakfast'],
        'lunch' => ['lunch_title', 'Lunch'],
        'dinner' => ['Dinner_title', 'Dinner'],
    ];

    public function show(Request $request): View
    {
        $today = Days::today();
        $monday = Days::mondayOf(Days::now());
        $keys = array_map(fn (int $i) => Days::key($monday->addDays($i)), range(0, 6));

        $people = DB::table('user')->selectRaw('COUNT(*) total, COALESCE(SUM(Status = 1), 0) active')->first();

        $week = [];
        foreach ($keys as $key) {
            $dishes = $this->dishes($key);
            $counts = $this->bookings($key);
            $manager = ManagerMeals::on($key, array_keys($dishes));
            $meals = [];
            foreach (BookingRules::MEALS as $meal) {
                $meals[$meal] = [
                    'name' => $dishes[$meal] ?? null,
                    'bookings' => isset($dishes[$meal]) ? ($counts[$meal] ?? 0) : 0,
                    'manager' => $manager[$meal],
                ];
            }
            $week[$key] = $meals;
        }

        $email = null;
        if ($request->boolean('email')) {
            $daily = Settings::get('daily_orders');
            $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('day')) ? (string) $request->query('day') : $today;
            $email = [
                'day' => $day,
                'recipients' => (string) ($request->old('recipients') ?? implode("\n", $daily['recipients'] ?? [])),
                'on' => Mailer::isOn(),
            ];
        }

        return view('admin.dashboard', [
            'email' => $email,
            'today' => $today,
            'total' => (int) $people->total,
            'active' => (int) $people->active,
            'unread' => (int) DB::table('reports')->where('read', 0)->count(),
            'week' => $week,
            'canUsers' => AdminPage::canOpen($request->user()->User_Type, '/users'),
        ]);
    }

    /** "Email orders": the day's order list as a CSV file to several people. */
    public function emailOrders(Request $request): RedirectResponse
    {
        $day = (string) $request->input('day');
        $valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
        $back = '/admin?email=1&day=' . ($valid ? $day : Days::today());
        if (!$valid) {
            return redirect($back)->withInput()->with('email_status', [false, t('Pick a day.')]);
        }
        try {
            $recipients = DayOrders::addresses((string) $request->input('recipients'));
            if (!Mailer::isOn()) {
                throw new \RuntimeException('E-mail is switched off: fill in the SMTP settings first (Settings → SMTP).');
            }
            $result = DayOrders::send($day, $recipients);
        } catch (\Throwable $e) {
            return redirect($back)->withInput()->with('email_status', [false, t($e->getMessage())]);
        }
        $totals = collect(BookingRules::MEALS)->map(fn ($m) => t(ucfirst($m)) . ' ' . $result['totals'][$m])->implode(' · ');
        return redirect($back)->withInput()->with('email_status', [true, t('Sent to {0} people: {1} bookings ({2}).', [0 => count($result['recipients']), 1 => $result['bookings'], 2 => $totals])]);
    }

    /** The dishes served on a day: meal => name. */
    private function dishes(string $day): array
    {
        $out = [];
        foreach (DB::table('meun')->where('day', '>=', "$day 00:00:00.000")->where('day', '<=', "$day 23:59:59.999")->get() as $row) {
            foreach (self::FIELDS as $meal => [$column]) {
                if (trim((string) $row->{$column}) !== '') {
                    $out[$meal] = trim((string) $row->{$column});
                }
            }
        }
        return $out;
    }

    /** Booked meals of a day, per meal (missed ones count, like the order list). */
    private function bookings(string $day): array
    {
        $row = DB::table('oder')
            ->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")
            ->selectRaw('COALESCE(SUM(bool), 0) breakfast, COALESCE(SUM(bool2), 0) lunch, COALESCE(SUM(bool3), 0) dinner')
            ->first();
        return ['breakfast' => (int) $row->breakfast, 'lunch' => (int) $row->lunch, 'dinner' => (int) $row->dinner];
    }
}
