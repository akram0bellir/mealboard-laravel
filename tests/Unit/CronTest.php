<?php

namespace Tests\Unit;

use App\Services\BookingRules;
use App\Services\Cron;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The automatic no-shows. The clock is Thursday 2026-11-05 10:00: yesterday is over, today's breakfast is over, today's lunch is not. */
class CronTest extends TestCase
{
    private function noShow(string $orderId, string $meal): int
    {
        return (int) DB::table('oder')->where('id', $orderId)->value("no_show_$meal");
    }

    private function startFrom(string $value): void
    {
        $this->rules(['auto_no_show_from' => $value]);
    }

    public function test_it_does_nothing_when_switched_off(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $this->assertSame('off', Cron::autoNoShows()['status']);
        $this->assertSame(0, $this->noShow($id, 'lunch'));
    }

    public function test_a_booked_meal_nobody_served_is_marked_missed_with_a_strike(): void
    {
        $this->startFrom('2026-11-04 lunch');
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $r = Cron::autoNoShows();
        $this->assertSame('ran', $r['status']);
        $this->assertSame(1, $this->noShow($id, 'lunch'));
        $this->assertSame(1, $this->strikes($p));
    }

    public function test_a_served_meal_is_never_marked(): void
    {
        $this->startFrom('2026-11-04 lunch');
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch'], ['attended_lunch' => 1]);
        Cron::autoNoShows();
        $this->assertSame(0, $this->noShow($id, 'lunch'));
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_a_meal_that_is_not_over_yet_is_left_alone(): void
    {
        $this->startFrom('2026-11-04 lunch');
        $p = $this->person();
        $id = $this->order($p, '2026-11-05', ['lunch']);   // ends 14:00, it is 10:00
        $dinner = $this->order($this->person(), '2026-11-05', ['dinner']);
        Cron::autoNoShows();
        $this->assertSame(0, $this->noShow($id, 'lunch'));
        $this->assertSame(0, $this->noShow($dinner, 'dinner'));
        $this->at('2026-11-05 15:00');
        Cron::autoNoShows();
        $this->assertSame(1, $this->noShow($id, 'lunch'), 'once lunch is over');
        $this->assertSame(0, $this->noShow($dinner, 'dinner'), 'dinner is still ahead');
    }

    public function test_it_only_starts_from_the_chosen_day_and_meal(): void
    {
        $this->startFrom('2026-11-05 lunch');
        $y = $this->order($this->person(), '2026-11-04', ['lunch']);
        $breakfast = $this->order($this->person(), '2026-11-05', ['breakfast']);
        Cron::autoNoShows();
        $this->assertSame(0, $this->noShow($y, 'lunch'), 'before the start day');
        $this->assertSame(0, $this->noShow($breakfast, 'breakfast'), 'before the start meal of the start day');
    }

    public function test_a_meal_is_processed_only_once(): void
    {
        $this->startFrom('2026-11-04 lunch');
        $p = $this->person();
        $this->order($p, '2026-11-04', ['lunch']);
        Cron::autoNoShows();
        $second = Cron::autoNoShows();
        $this->assertSame([], $second['marked']);
        $this->assertSame(1, $this->strikes($p), 'no second strike');
        $this->assertTrue(DB::table('_mealboard_marked')->where('day', '2026-11-04')->where('meal', 'lunch')->exists());
    }

    public function test_a_late_booking_for_a_processed_meal_is_not_struck(): void
    {
        $this->startFrom('2026-11-04 lunch');
        Cron::autoNoShows();
        $late = $this->order($this->person(), '2026-11-04', ['lunch']); // added by an admin afterwards
        Cron::autoNoShows();
        $this->assertSame(0, $this->noShow($late, 'lunch'));
    }

    public function test_lunch_is_skipped_in_ramadan(): void
    {
        $this->rules(['auto_no_show_from' => '2026-11-04 lunch', 'ramadan_mode' => 1]);
        $id = $this->order($this->person(), '2026-11-04', ['lunch']);
        Cron::autoNoShows();
        $this->assertSame(0, $this->noShow($id, 'lunch'));
    }

    public function test_a_door_that_has_not_reported_since_the_meal_ended_holds_everything_back(): void
    {
        $this->startFrom('2026-11-04 lunch');
        $id = $this->order($this->person(), '2026-11-04', ['lunch']);
        DB::table('_mealboard_doors')->insert(['door' => 'door-test', 'name' => 'T', 'last_seen' => Cron::nowMs() - 40 * 3600000, 'pending' => 0, 'oldest_pending' => null, 'close_after' => 0]);
        $this->assertSame([], Cron::autoNoShows()['marked']);
        $this->assertSame(0, $this->noShow($id, 'lunch'));
        DB::table('_mealboard_doors')->where('door', 'door-test')->update(['last_seen' => Cron::nowMs()]);
        Cron::autoNoShows();
        $this->assertSame(1, $this->noShow($id, 'lunch'), 'once the door reported');
    }

    public function test_a_door_still_holding_old_marks_blocks_the_meal(): void
    {
        $this->startFrom('2026-11-04 lunch');
        $id = $this->order($this->person(), '2026-11-04', ['lunch']);
        $oldest = Cron::mealEnd('2026-11-04', 'lunch', BookingRules::current())->getTimestamp() * 1000 - 60000; // a mark from before the end, not yet sent
        DB::table('_mealboard_doors')->insert(['door' => 'door-test', 'name' => 'T', 'last_seen' => Cron::nowMs(), 'pending' => 2, 'oldest_pending' => $oldest, 'close_after' => 0]);
        Cron::autoNoShows();
        $this->assertSame(0, $this->noShow($id, 'lunch'));
    }

    public function test_the_meal_end_follows_the_groups_and_the_doors_wait(): void
    {
        DB::table('meal_groups')->where('meal', 'like', 'lunch')->delete();
        $end = fn () => Cron::mealEnd('2026-11-04', 'lunch', BookingRules::current())->format('H:i');
        $this->assertSame('14:00', $end(), 'no group: the default lunch end');
        DB::table('meal_groups')->insert(['id' => 'tstgroup0000001', 'name' => 'A', 'meal' => 'lunch', 'start' => '11:00', 'end' => '12:30', 'ramadan_start' => '', 'ramadan_end' => '', 'created' => now(), 'updated' => now()]);
        DB::table('meal_groups')->insert(['id' => 'tstgroup0000002', 'name' => 'B', 'meal' => 'lunch', 'start' => '12:30', 'end' => '13:15', 'ramadan_start' => '', 'ramadan_end' => '', 'created' => now(), 'updated' => now()]);
        $this->assertSame('13:15', $end(), 'the latest group end');
        DB::table('_mealboard_doors')->insert(['door' => 'door-test', 'name' => 'T', 'last_seen' => Cron::nowMs(), 'pending' => 0, 'oldest_pending' => null, 'close_after' => 30]);
        $this->assertSame('13:45', $end(), '+ the 30 minutes the door waits');
    }

    public function test_a_full_run_is_logged(): void
    {
        $before = count(Cron::log());
        $r = Cron::run('test');
        $this->assertArrayHasKey('autoNoShow', $r);
        $this->assertArrayHasKey('emails', $r);
        $log = Cron::log();
        $this->assertSame('test', $log[0]['source']);
        $this->assertTrue($log[0]['ok']);
        $this->assertLessThanOrEqual(50, count($log));
        $this->assertGreaterThanOrEqual(min($before + 1, 50), count($log));
    }
}
