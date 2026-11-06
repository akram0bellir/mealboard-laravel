<?php

namespace App\Services;

use App\Support\Days;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Everything the scheduler does, in order: the automatic no-shows, the daily order list, the waiting e-mails
 * (port of the PHP API's routes/cron.php). Each step runs even when an earlier one fails.
 */
class Cron
{
    public const MEALS = ['breakfast', 'lunch', 'dinner'];

    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    private const DEFAULT_END = ['breakfast' => 570, 'lunch' => 840, 'dinner' => 1230];

    /** Doors that reported in the last 3 days. */
    private const DOOR_DAYS = 3;

    /** Runs all the jobs. $source says who started it (cli / web / system) and is kept in the log. */
    public static function run(string $source = 'cli'): array
    {
        $started = microtime(true);
        $out = [];
        $errors = [];

        try {
            $out['autoNoShow'] = self::autoNoShows();
        } catch (\Throwable $e) {
            $out['autoNoShow'] = ['status' => 'error'];
            $errors[] = 'Auto no-show: ' . $e->getMessage();
        }

        try {
            if (Mailer::isOn()) {
                $out['dailyList'] = DayOrders::runDaily();
            }
        } catch (\Throwable $e) {
            $errors[] = 'Daily list: ' . $e->getMessage();
        }

        try {
            [$sent, $failed] = Mailer::flushQueue(50);
            $out['emails'] = ['sent' => $sent, 'failed' => $failed];
        } catch (\Throwable $e) {
            $out['emails'] = ['sent' => 0, 'failed' => 0];
            $errors[] = 'E-mails: ' . $e->getMessage();
        }

        $out['at'] = gmdate('c');
        if ($errors) {
            $out['errors'] = $errors;
        }
        self::log([
            'at' => self::nowMs(),
            'source' => $source,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'ok' => !$errors,
            'result' => $out,
        ]);
        return $out;
    }

    /** The last 50 runs (newest first), kept in the settings table. Never breaks the cron. */
    public static function log(?array $entry = null): array
    {
        try {
            $log = Settings::get('cron_log');
            if ($entry !== null) {
                array_unshift($log, $entry);
                $log = array_slice($log, 0, 50);
                Settings::put('cron_log', $log);
            }
            return $log;
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Automatic no-shows                                                  */
    /* ------------------------------------------------------------------ */

    /** Marks the booked-but-not-served meals of yesterday and today once the meal is over and every door has sent its data. */
    public static function autoNoShows(): array
    {
        $rules = BookingRules::current();
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+([a-zA-Z]+)$/', $rules->autoNoShowFrom, $m)) {
            return ['status' => 'off', 'marked' => []];
        }
        $from = ['day' => $m[1], 'meal' => MealTimes::mealOf($m[2])];
        if ($from['meal'] === null) {
            return ['status' => 'off', 'marked' => []];
        }
        $rank = array_flip(self::MEALS);

        $results = [];
        foreach ([Days::add(Days::today(), -1), Days::today()] as $day) {
            if ($day < $from['day']) {
                continue;
            }
            foreach (self::MEALS as $meal) {
                if ($rules->ramadan && $meal === 'lunch') {
                    continue;
                }
                if ($day === $from['day'] && $rank[$meal] < $rank[$from['meal']]) {
                    continue;
                }
                if (DB::table('_mealboard_marked')->where('day', $day)->where('meal', $meal)->exists()) {
                    continue;
                }
                $endAt = self::mealEnd($day, $meal, $rules)->getTimestamp();
                if (Days::now()->getTimestamp() < $endAt) {
                    continue; // the meal is not over yet
                }
                if (!self::doorsReady($endAt)) {
                    continue; // a door has not sent everything yet: next run
                }
                $count = self::markMeal($day, $meal);
                DB::table('_mealboard_marked')->updateOrInsert(['day' => $day, 'meal' => $meal], ['at' => self::nowMs(), 'marked' => $count]);
                $results[] = ['day' => $day, 'meal' => $meal, 'marked' => $count];
            }
        }
        return ['status' => 'ran', 'marked' => $results];
    }

    /** When the meal ends on that day: the latest group end, plus the longest wait the doors asked for. */
    public static function mealEnd(string $day, string $meal, BookingRules $rules): \Carbon\CarbonImmutable
    {
        $end = null;
        foreach (DB::table('meal_groups')->get() as $group) {
            if (MealTimes::mealOf((string) $group->meal) !== $meal) {
                continue;
            }
            $value = $rules->ramadan && trim((string) $group->ramadan_end) !== '' ? (string) $group->ramadan_end : (string) $group->end;
            if (preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $t) && (int) $t[1] <= 23 && (int) $t[2] <= 59) {
                $minutes = (int) $t[1] * 60 + (int) $t[2];
                $end = $end === null ? $minutes : max($end, $minutes);
            }
        }
        $end ??= self::DEFAULT_END[$meal];
        $grace = (int) (DB::table('_mealboard_doors')->where('last_seen', '>=', self::nowMs() - self::DOOR_DAYS * 86400000)->max('close_after') ?? 0);
        $end += max(0, min(180, $grace));
        return Days::fromKey($day)->setTime(intdiv($end, 60), $end % 60);
    }

    /** Doors seen in the last 3 days. */
    public static function activeDoors(): array
    {
        return DB::table('_mealboard_doors')->where('last_seen', '>=', self::nowMs() - self::DOOR_DAYS * 86400000)->orderBy('name')->get()->all();
    }

    /** Has every active door sent its data up to the meal end? */
    public static function doorsReady(int $mealEndTimestamp): bool
    {
        $limit = $mealEndTimestamp * 1000;
        foreach (self::activeDoors() as $door) {
            if ((int) $door->last_seen < $limit) {
                return false;
            }
            if ($door->oldest_pending !== null && (int) $door->oldest_pending < $limit) {
                return false;
            }
        }
        return true;
    }

    /** Marks every booked-not-served meal of that day and meal as missed and adds the strikes. */
    private static function markMeal(string $day, string $meal): int
    {
        $rows = DB::table('oder')
            ->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")
            ->where(self::COLUMN[$meal], 1)->where("attended_$meal", 0)->where("no_show_$meal", 0)
            ->get();
        foreach ($rows as $row) {
            DB::table('oder')->where('id', $row->id)->update(["no_show_$meal" => 1, 'updated' => Ids::now()]);
            Standing::addStrike((string) $row->user, $day, $meal, 'missed');
        }
        return $rows->count();
    }

    /** Now in milliseconds, on the app's clock (the same clock as the meal times; tests can move it). */
    public static function nowMs(): int
    {
        return (int) round(Days::now()->getPreciseTimestamp(3));
    }
}
