<?php

namespace Tests\Unit;

use App\Services\BookingRules;
use App\Services\MealPlan;
use App\Support\Days;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BookingRulesTest extends TestCase
{
    private function dayState(string $day): string
    {
        return BookingRules::current()->dayStatus($day);
    }

    public function test_deadline_is_14h_the_day_before(): void
    {
        // Thursday 10:00
        $this->assertSame('past', $this->dayState('2026-11-04'));
        $this->assertSame('locked', $this->dayState('2026-11-05'), 'today: the deadline was yesterday 14:00');
        $this->assertSame('open', $this->dayState('2026-11-06'), "tomorrow's deadline is today 14:00");
        $this->at('2026-11-05 13:59');
        $this->assertSame('open', $this->dayState('2026-11-06'));
        $this->at('2026-11-05 14:00');
        $this->assertSame('locked', $this->dayState('2026-11-06'), 'locks exactly at the deadline');
    }

    public function test_other_deadline_settings(): void
    {
        $this->rules(['cancel_deadline_hour' => 8, 'cancel_deadline_days_before' => 0]);
        $this->at('2026-11-05 07:00');
        $this->assertSame('open', $this->dayState('2026-11-05'), 'same-day deadline at 08:00');
        $this->at('2026-11-05 08:00');
        $this->assertSame('locked', $this->dayState('2026-11-05'));

        $this->rules(['cancel_deadline_hour' => 10, 'cancel_deadline_days_before' => 2]);
        $this->at('2026-11-05 09:00');
        $this->assertSame('open', $this->dayState('2026-11-07'));
        $this->assertSame('locked', $this->dayState('2026-11-06'));
    }

    public function test_next_week_opens_on_tuesday(): void
    {
        $this->at('2026-11-02 10:00'); // Monday
        $this->assertSame('locked', $this->dayState('2026-11-09'), 'next week is closed on Monday');
        $this->assertSame('open', $this->dayState('2026-11-06'), 'but this week is open');
        $this->at('2026-11-03 10:00'); // Tuesday
        $this->assertSame('open', $this->dayState('2026-11-09'));
        $this->assertSame('locked', $this->dayState('2026-11-16'), 'two weeks ahead stays closed');
    }

    public function test_garbage_rule_values_keep_their_defaults(): void
    {
        $r = BookingRules::fromRow((object) ['max_no_show' => 0, 'period_days' => -4, 'cancel_deadline_hour' => 99, 'cancel_deadline_days_before' => 'abc']);
        $this->assertSame(3, $r->maxNoShow);
        $this->assertSame(30, $r->periodDays);
        $this->assertSame(14, $r->deadlineHour);
        $this->assertSame(1, $r->deadlineDaysBefore);
    }

    public function test_ramadan_mode_closes_lunch_and_renames_meals(): void
    {
        $r = BookingRules::current();
        $this->assertFalse($r->mealClosed('lunch'));
        $this->assertSame('Breakfast', $r->mealLabel('breakfast'));
        $this->rules(['ramadan_mode' => 1]);
        $r = BookingRules::current();
        $this->assertTrue($r->mealClosed('lunch'));
        $this->assertFalse($r->mealClosed('dinner'));
        $this->assertSame('Suhoor', $r->mealLabel('breakfast'));
        $this->assertSame('Iftar', $r->mealLabel('dinner'));
    }

    public function test_the_two_week_window_moves_on_tuesday(): void
    {
        $r = BookingRules::current();
        $thursday = $r->upcomingDays(Days::now());
        $this->assertCount(14, $thursday);
        $this->assertSame('2026-11-02', $thursday[0]->format('Y-m-d'));
        $monday = $r->upcomingDays(CarbonImmutable::parse('2026-11-02 10:00', config('app.timezone')));
        $this->assertSame('2026-10-26', $monday[0]->format('Y-m-d'), 'on Monday last week is still shown');
    }
}
