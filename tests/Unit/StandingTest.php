<?php

namespace Tests\Unit;

use App\Services\Standing;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StandingTest extends TestCase
{
    private function row(string $id): object
    {
        return DB::table('user')->where('id', $id)->first();
    }

    public function test_first_strike_starts_the_period(): void
    {
        $p = $this->person();
        $s = Standing::addStrike($p->id, '2026-11-05', 'lunch');
        $this->assertSame(1, $s['strikes']);
        $this->assertSame('2026-11-05', $s['period_start']);
        $this->assertSame('', $s['blocked_until']);
        $this->assertSame(1, $this->strikes($p));
    }

    public function test_third_strike_pauses_booking_until_the_period_ends(): void
    {
        $p = $this->person();
        Standing::addStrike($p->id, '2026-11-03', 'lunch');
        Standing::addStrike($p->id, '2026-11-04', 'lunch');
        $s = Standing::addStrike($p->id, '2026-11-05', 'lunch');
        $this->assertSame(3, $s['strikes']);
        $this->assertSame('2026-12-03', $s['blocked_until'], '30 days after the first no-show (3 Nov)');
        $this->assertSame(1, DB::table('notifications')->where('title', 'like', 'Booking paused%')->where('recipients', 'like', '%' . $p->id . '%')->count(), 'the person is told');
    }

    public function test_the_pause_cancels_open_bookings_but_keeps_locked_days(): void
    {
        $p = $this->person();
        $open = $this->order($p, '2026-11-06', ['lunch']);   // Friday: still open
        $locked = $this->order($p, '2026-11-05', ['lunch']); // today: locked
        Standing::setStrikes($p->id, 3);
        $this->assertFalse(DB::table('oder')->where('id', $open)->exists(), 'open booking cancelled');
        $this->assertTrue(DB::table('oder')->where('id', $locked)->exists(), 'locked day stays (the kitchen already ordered it)');
    }

    public function test_a_strike_for_a_period_that_is_already_over_is_ignored(): void
    {
        $p = $this->person();
        $s = Standing::addStrike($p->id, '2026-09-01', 'lunch');
        $this->assertSame(0, $s['strikes']);
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_an_ended_period_resets_everything(): void
    {
        $p = $this->person(['Strikes' => 2, 'period_start' => '2026-09-01 12:00:00.000']);
        $s = Standing::read($p);
        $this->assertSame(0, $s['strikes']);
        $this->assertSame('', $s['period_start']);
        $this->assertSame(0, $this->strikes($p), 'and it is saved');
    }

    public function test_an_ended_pause_is_lifted_when_read(): void
    {
        $p = $this->person(['Strikes' => 3, 'period_start' => '2026-10-10 12:00:00.000', 'blocked_until' => '2026-11-05 12:00:00.000']);
        $s = Standing::read($p);
        $this->assertSame('', $s['blocked_until'], 'the pause ends today');
        $this->assertSame(3, $s['strikes'], 'the strikes stay until the period ends');
    }

    public function test_strikes_without_a_period_get_one(): void
    {
        $p = $this->person(['Strikes' => 1]);
        $s = Standing::read($p);
        $this->assertNotSame('', $s['period_start']);
    }

    public function test_taking_a_strike_back_lifts_the_pause(): void
    {
        $p = $this->person();
        Standing::setStrikes($p->id, 3);
        $this->assertNotSame('', Standing::read($this->row($p->id), false)['blocked_until']);
        $s = Standing::takeBackStrike($p->id);
        $this->assertSame(2, $s['strikes']);
        $this->assertSame('', $s['blocked_until']);
    }

    public function test_zero_strikes_end_the_period(): void
    {
        $p = $this->person();
        Standing::setStrikes($p->id, 2);
        $s = Standing::setStrikes($p->id, 0);
        $this->assertSame(['strikes' => 0, 'period_start' => '', 'blocked_until' => ''], $s);
    }

    public function test_the_limit_comes_from_the_rules(): void
    {
        $this->rules(['max_no_show' => 2]);
        $p = $this->person();
        Standing::addStrike($p->id, '2026-11-04', 'lunch');
        $s = Standing::addStrike($p->id, '2026-11-05', 'lunch');
        $this->assertNotSame('', $s['blocked_until'], 'two strikes pause when the limit is 2');
    }
}
