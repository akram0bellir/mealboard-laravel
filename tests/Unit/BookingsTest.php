<?php

namespace Tests\Unit;

use App\Services\Bookings;
use App\Services\Standing;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    public function test_an_open_day_can_be_booked(): void
    {
        $p = $this->person();
        $r = Bookings::apply($p, ['2026-11-06' => ['book' => ['lunch']]]);
        $this->assertSame(['2026-11-06'], $r['done']);
        $this->assertSame(['lunch'], $this->meals($p, '2026-11-06'));
    }

    public function test_one_meal_a_day_replaces_the_booked_one(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-06', ['lunch']);
        Bookings::apply($p, ['2026-11-06' => ['book' => ['dinner']]]);
        $this->assertSame(['dinner'], $this->meals($p, '2026-11-06'));
    }

    public function test_several_meals_a_day_when_the_account_allows_it(): void
    {
        $p = $this->person(['multiple_meals' => 1]);
        Bookings::apply($p, ['2026-11-06' => ['book' => ['breakfast', 'lunch']]]);
        $this->assertSame(['breakfast', 'lunch'], $this->meals($p, '2026-11-06'));
    }

    public function test_locked_and_past_days_cannot_be_booked(): void
    {
        $p = $this->person();
        $r = Bookings::apply($p, ['2026-11-05' => ['book' => ['lunch']], '2026-11-03' => ['book' => ['lunch']]]);
        $this->assertSame([], $r['done']);
        $this->assertCount(2, $r['skipped']);
        $this->assertSame([], $this->meals($p, '2026-11-05'));
    }

    public function test_the_deadline_is_checked_again_on_the_server(): void
    {
        $p = $this->person();
        $this->at('2026-11-05 14:01'); // after the deadline of tomorrow
        $r = Bookings::apply($p, ['2026-11-06' => ['book' => ['lunch']]]);
        $this->assertSame([], $r['done']);
    }

    public function test_a_meal_outside_the_plan_is_refused(): void
    {
        $p = $this->person(['Dinner' => 0]);
        $r = Bookings::apply($p, ['2026-11-06' => ['book' => ['dinner']]]);
        $this->assertSame([], $r['done']);
        $this->assertStringContainsString('not included', $r['skipped'][0]['reason']);
    }

    public function test_an_intern_can_only_book_lunch(): void
    {
        $p = $this->person(['User_Type' => 'intern']);
        $this->assertSame([], Bookings::apply($p, ['2026-11-06' => ['book' => ['dinner']]])['done']);
        $this->assertSame(['2026-11-06'], Bookings::apply($p, ['2026-11-06' => ['book' => ['lunch']]])['done']);
    }

    public function test_a_paused_person_cannot_book_before_the_pause_ends(): void
    {
        $p = $this->person(['Strikes' => 3, 'period_start' => '2026-11-01 12:00:00.000', 'blocked_until' => '2026-12-01 12:00:00.000']);
        $r = Bookings::apply($p, ['2026-11-06' => ['book' => ['lunch']]]);
        $this->assertSame([], $r['done']);
        $this->assertStringContainsString('paused', $r['skipped'][0]['reason']);
    }

    public function test_lunch_is_closed_in_ramadan(): void
    {
        $this->rules(['ramadan_mode' => 1]);
        $p = $this->person();
        $this->assertSame([], Bookings::apply($p, ['2026-11-06' => ['book' => ['lunch']]])['done']);
        $this->assertSame(['2026-11-06'], Bookings::apply($p, ['2026-11-06' => ['book' => ['breakfast', 'dinner']]])['done']);
        $this->assertSame(['breakfast', 'dinner'], $this->meals($p, '2026-11-06'), 'suhoor and iftar together');
    }

    public function test_cancelling_the_last_meal_removes_the_row(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-06', ['lunch']);
        Bookings::apply($p, ['2026-11-06' => ['cancel' => ['lunch']]]);
        $this->assertFalse(DB::table('oder')->where('id', $id)->exists());
    }

    public function test_a_late_cancellation_is_refused_unless_the_rules_count_it(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch']); // today: locked
        $r = Bookings::cancelDay($p, '2026-11-05', null);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->strikes($p));
        $this->assertSame(['lunch'], $this->meals($p, '2026-11-05'));
    }

    public function test_a_late_cancellation_counts_as_a_no_show_when_the_rules_say_so(): void
    {
        $this->rules(['late_cancel_counts' => 1]);
        $p = $this->person();
        $id = $this->order($p, '2026-11-05', ['lunch']);
        $r = Bookings::cancelDay($p, '2026-11-05', null);
        $this->assertTrue($r['ok']);
        $row = DB::table('oder')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->no_show_lunch, 'the order stays for the kitchen, marked missed');
        $this->assertSame(1, $this->strikes($p));
    }

    public function test_a_late_cancelled_booking_is_frozen(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-06', ['lunch'], ['no_show_lunch' => 1]);
        $r = Bookings::apply($p, ['2026-11-06' => ['cancel' => ['lunch']]]);
        $this->assertSame([], $r['done']);
        $this->assertStringContainsString('cancelled late', $r['skipped'][0]['reason']);
    }

    public function test_an_invalid_date_is_ignored(): void
    {
        $p = $this->person();
        $this->assertSame(['done' => [], 'skipped' => []], Bookings::apply($p, ['not-a-date' => ['book' => ['lunch']]]));
    }

    /* ---- the admin override: no rule applies ---- */

    public function test_an_admin_can_add_a_person_to_a_past_day(): void
    {
        $p = $this->person();
        $r = Bookings::adminAdd($p->id, '2026-10-20', ['lunch']);
        $this->assertSame(['lunch'], $r['added']);
        $this->assertSame(['lunch'], $this->meals($p, '2026-10-20'));
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_an_admin_can_add_a_person_to_a_locked_day(): void
    {
        $p = $this->person();
        Bookings::adminAdd($p->id, '2026-11-05', ['dinner']);
        $this->assertSame(['dinner'], $this->meals($p, '2026-11-05'));
    }

    public function test_an_admin_can_add_a_paused_person(): void
    {
        $p = $this->person(['Strikes' => 3, 'period_start' => '2026-11-01 12:00:00.000', 'blocked_until' => '2026-12-01 12:00:00.000']);
        Bookings::adminAdd($p->id, '2026-11-06', ['lunch']);
        $this->assertSame(['lunch'], $this->meals($p, '2026-11-06'));
    }

    public function test_an_admin_can_book_outside_the_plan_and_in_ramadan_and_for_an_account_that_is_off(): void
    {
        $this->rules(['ramadan_mode' => 1]);
        $p = $this->person(['Status' => 0, 'User_Type' => 'intern']);
        Bookings::adminAdd($p->id, '2026-11-06', ['lunch', 'dinner', 'breakfast']);
        $this->assertSame(['breakfast', 'lunch', 'dinner'], $this->meals($p, '2026-11-06'), 'intern, Ramadan lunch, and three meals: nothing stops an admin');
    }

    public function test_adding_keeps_what_is_already_booked_and_served(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch'], ['attended_lunch' => 1]);
        $r = Bookings::adminAdd($p->id, '2026-11-04', ['lunch', 'dinner']);
        $this->assertSame(['dinner'], $r['added']);
        $this->assertSame(['lunch'], $r['already']);
        $row = DB::table('oder')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->attended_lunch, 'the served mark is kept');
        $this->assertSame(1, DB::table('oder')->where('user', $p->id)->count(), 'one row per person per day');
    }

    public function test_an_admin_can_remove_a_meal_or_the_whole_booking(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-06', ['lunch', 'dinner']);
        $this->assertSame(['lunch'], Bookings::adminRemove($id, ['lunch']));
        $this->assertSame(['dinner'], $this->meals($p, '2026-11-06'));
        $this->assertSame(['dinner'], Bookings::adminRemove($id));
        $this->assertFalse(DB::table('oder')->where('id', $id)->exists());
        $this->assertSame([], Bookings::adminRemove($id), 'nothing left to remove');
    }

    public function test_removing_a_missed_meal_gives_the_strike_back(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch'], ['no_show_lunch' => 1]);
        Standing::setStrikes($p->id, 1);
        Bookings::adminRemove($id);
        $this->assertSame(0, $this->strikes($p));
    }
}
