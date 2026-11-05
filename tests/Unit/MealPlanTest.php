<?php

namespace Tests\Unit;

use App\Services\MealPlan;
use Tests\TestCase;

class MealPlanTest extends TestCase
{
    public function test_standard_plan_is_one_meal_a_day(): void
    {
        $plan = MealPlan::for($this->person());
        $this->assertSame(['breakfast', 'lunch', 'dinner'], $plan['meals']);
        $this->assertSame(1, $plan['max']);
    }

    public function test_ticks_narrow_the_plan(): void
    {
        $plan = MealPlan::for($this->person(['Breakfast' => 0, 'Dinner' => 0]));
        $this->assertSame(['lunch'], $plan['meals']);
    }

    public function test_several_meals_a_day_when_allowed(): void
    {
        $plan = MealPlan::for($this->person(['multiple_meals' => 1, 'Dinner' => 0]));
        $this->assertSame(['breakfast', 'lunch'], $plan['meals']);
        $this->assertSame(2, $plan['max']);
    }

    public function test_intern_gets_lunch_only(): void
    {
        $plan = MealPlan::for($this->person(['User_Type' => 'intern', 'multiple_meals' => 1]));
        $this->assertSame(['lunch'], $plan['meals']);
        $this->assertSame(1, $plan['max']);
        $this->assertSame('intern', $plan['plan']);
    }

    public function test_ramadan_gives_suhoor_and_iftar_to_everyone(): void
    {
        $this->rules(['ramadan_mode' => 1]);
        $plan = MealPlan::for($this->person(['Breakfast' => 0, 'Dinner' => 0]));
        $this->assertSame(['breakfast', 'dinner'], $plan['meals']);
        $this->assertSame(2, $plan['max']);
        $this->assertTrue($plan['ramadan']);
    }
}
