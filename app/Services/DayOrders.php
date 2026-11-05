<?php

namespace App\Services;

use App\Models\User;
use App\Support\Days;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The order list of one day, e-mailed as a CSV file (by hand from the dashboard, or every day by the scheduler). */
class DayOrders
{
    private const BOOKED = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    /** @return array{0: array, 1: array} [lines, totals per meal] */
    public static function data(string $day): array
    {
        $orders = DB::table('oder')->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")->orderBy('date')->get();
        $users = User::whereIn('id', $orders->pluck('user')->unique()->all())->get()->keyBy('id');

        $lines = [];
        $totals = ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0];
        foreach ($orders as $order) {
            $states = [];
            $any = false;
            foreach (self::BOOKED as $meal => $column) {
                if ((int) $order->{$column} !== 1) {
                    $states[$meal] = '';
                    continue;
                }
                $any = true;
                $states[$meal] = (int) $order->{'no_show_' . $meal} === 1 ? 'missed' : ((int) $order->{'attended_' . $meal} === 1 ? 'served' : 'yes');
                $totals[$meal]++;
            }
            if (!$any) {
                continue;
            }
            $p = $users[$order->user] ?? null;
            $lines[] = [
                'name' => $p ? $p->fullName() : '',
                'employeeId' => $p && $p->id_user !== null ? (string) $p->id_user : '',
                'department' => (string) ($p->Department ?? ''),
                'position' => (string) ($p->Position ?? ''),
                'type' => Roles::normalize($p->User_Type ?? ''),
                'states' => $states,
            ];
        }
        usort($lines, fn ($a, $b) => [$a['department'], mb_strtolower($a['name'])] <=> [$b['department'], mb_strtolower($b['name'])]);
        return [$lines, $totals];
    }

    /** One CSV that opens correctly in Excel: BOM, every cell quoted, \r\n line ends. */
    public static function csv(string $day, array $lines): string
    {
        $cell = fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $out = "\xEF\xBB\xBF" . implode(',', array_map($cell, ['Date', 'Name', 'Employee ID', 'Department', 'Position', 'Type', 'Breakfast', 'Lunch', 'Dinner'])) . "\r\n";
        foreach ($lines as $l) {
            $out .= implode(',', array_map($cell, [$day, $l['name'], $l['employeeId'], $l['department'], $l['position'], $l['type'], $l['states']['breakfast'], $l['states']['lunch'], $l['states']['dinner']])) . "\r\n";
        }
        return $out;
    }

    /** Sends the day's list. @throws RuntimeException when the mail server refuses */
    public static function send(string $day, array $recipients): array
    {
        [$lines, $totals] = self::data($day);
        $long = Days::fromKey($day)->locale('en')->translatedFormat('l j F Y');
        $text = sprintf(
            "Orders for %s\n\n%d people booked\nBreakfast: %d\nLunch: %d\nDinner: %d\n\n"
            . "The attached file lists every booking of that day, one line per person, sorted by department and name.\n"
            . "In the three meal columns: empty = not booked, yes = booked, served = served at the door, missed = marked as missed.\n"
            . "\n--\nMealboard\n",
            $long, count($lines), $totals['breakfast'], $totals['lunch'], $totals['dinner']
        );
        Mailer::send($recipients, 'Orders for ' . $long, $text, ['filename' => "orders-{$day}.csv", 'content' => self::csv($day, $lines), 'mime' => 'text/csv']);
        return ['recipients' => $recipients, 'bookings' => count($lines), 'totals' => $totals];
    }

    /** Scheduler: sends the list once a day at the chosen hour (Settings → Daily order list). Returns a short note. */
    public static function runDaily(): string
    {
        $s = array_merge(['recipients' => [], 'auto' => false, 'hour' => 14, 'day' => 'tomorrow'], Settings::get('daily_orders'));
        if (!$s['auto'] || !$s['recipients']) {
            return 'Daily list is off.';
        }
        if ((int) Days::now()->format('G') < (int) $s['hour']) {
            return 'Not yet time.';
        }
        if (!Mailer::isOn()) {
            return 'E-mail is switched off.';
        }
        $day = $s['day'] === 'today' ? Days::today() : Days::add(Days::today(), 1);
        $sent = DB::table('_mealboard_daily_sent')->where('day', $day)->first();
        if ($sent && $sent->sent_at !== null) {
            return "Already sent for {$day}.";
        }
        $nowMs = (int) round(microtime(true) * 1000);
        if ($sent && $nowMs - (int) $sent->tried_at < 600000) {
            return 'Failed a moment ago: waiting.';
        }
        DB::table('_mealboard_daily_sent')->updateOrInsert(['day' => $day], ['tried_at' => $nowMs, 'error' => '']);
        try {
            self::send($day, $s['recipients']);
            DB::table('_mealboard_daily_sent')->where('day', $day)->update(['sent_at' => $nowMs, 'error' => '']);
            return "Sent the list for {$day}.";
        } catch (\Throwable $e) {
            DB::table('_mealboard_daily_sent')->where('day', $day)->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
            return 'Failed: ' . $e->getMessage();
        }
    }

    /** The addresses in a text ("a@x.com, b@y.com", commas / spaces / new lines) or an error message. */
    public static function addresses(string $text): array
    {
        $list = array_values(array_unique(array_filter(array_map(fn ($a) => mb_strtolower(trim($a)), preg_split('/[\s,;]+/', $text) ?: []))));
        foreach ($list as $a) {
            if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Not an e-mail address: {$a}");
            }
        }
        if (count($list) > 20) {
            throw new RuntimeException('At most 20 addresses at once.');
        }
        if (!$list) {
            throw new RuntimeException('Add at least one e-mail address.');
        }
        return $list;
    }
}
