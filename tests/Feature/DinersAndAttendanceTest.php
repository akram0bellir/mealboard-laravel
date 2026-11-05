<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DinersAndAttendanceTest extends TestCase
{
    /* ---- Book people: an admin adds people to meals under any case ---- */

    public function test_the_page_lists_who_is_booked_on_the_day(): void
    {
        $p = $this->person(['firstname' => 'Zakaria', 'lastname' => 'Listed']);
        $this->order($p, '2031-05-05', ['lunch']);
        $this->actingAs($this->admin())->get('/diners?date=2031-05-05')->assertOk()->assertSee('Zakaria Listed');
    }

    public function test_the_people_list_can_be_searched(): void
    {
        $this->person(['firstname' => 'Quentin', 'lastname' => 'Rareman']);
        $r = $this->actingAs($this->admin())->get('/diners?q=rareman');
        $r->assertSee('Quentin Rareman');
        $this->assertSame(1, substr_count($r->getContent(), 'name="users[]"'));
    }

    public function test_an_admin_adds_several_people_to_a_meal(): void
    {
        $a = $this->person();
        $b = $this->person();
        $this->actingAs($this->admin())->post('/diners', ['date' => '2026-11-06', 'users' => [$a->id, $b->id], 'meals' => ['lunch']])
            ->assertRedirect('/diners?date=2026-11-06')->assertSessionHas('diners_status', fn ($s) => $s[0] === true);
        $this->assertSame(['lunch'], $this->meals($a, '2026-11-06'));
        $this->assertSame(['lunch'], $this->meals($b, '2026-11-06'));
    }

    public function test_it_works_in_every_case_the_employee_rules_refuse(): void
    {
        $admin = $this->admin();
        $paused = $this->person(['Strikes' => 3, 'period_start' => '2026-11-01 12:00:00.000', 'blocked_until' => '2026-12-01 12:00:00.000']);
        $off = $this->person(['Status' => 0]);
        $intern = $this->person(['User_Type' => 'intern']);
        // a locked day (today), a past day, a far-future day, a paused person, an account that is off, a meal outside the plan
        foreach (['2026-11-05', '2026-10-01', '2027-02-01'] as $day) {
            $this->actingAs($admin)->post('/diners', ['date' => $day, 'users' => [$paused->id, $off->id, $intern->id], 'meals' => ['lunch', 'dinner']]);
            foreach ([$paused, $off, $intern] as $p) {
                $this->assertSame(['lunch', 'dinner'], $this->meals($p, $day), "{$p->User_Type} on {$day}");
            }
        }
        $this->rules(['ramadan_mode' => 1]);
        $this->actingAs($admin)->post('/diners', ['date' => '2026-11-10', 'users' => [$intern->id], 'meals' => ['lunch']]);
        $this->assertSame(['lunch'], $this->meals($intern, '2026-11-10'), 'lunch in Ramadan');
        $this->assertSame(0, $this->strikes($paused) - 3, 'no strike is ever added');
    }

    public function test_people_are_told_unless_the_admin_says_not_to(): void
    {
        $a = $this->person();
        $b = $this->person();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/diners', ['date' => '2026-11-06', 'users' => [$a->id], 'meals' => ['dinner']]);
        $this->assertSame(1, DB::table('notifications')->where('title', 'Meal booked for you')->where('recipients', 'like', '%' . $a->id . '%')->count());
        $this->actingAs($admin)->post('/diners', ['date' => '2026-11-06', 'users' => [$b->id], 'meals' => ['dinner'], 'notify' => '0']);
        $this->assertSame(0, DB::table('notifications')->where('recipients', 'like', '%' . $b->id . '%')->count());
        $this->assertSame(['dinner'], $this->meals($b, '2026-11-06'), 'still booked');
    }

    public function test_already_booked_people_are_not_duplicated(): void
    {
        $a = $this->person();
        $this->order($a, '2026-11-06', ['lunch']);
        $this->actingAs($this->admin())->post('/diners', ['date' => '2026-11-06', 'users' => [$a->id], 'meals' => ['lunch']]);
        $this->assertSame(1, DB::table('oder')->where('user', $a->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('recipients', 'like', '%' . $a->id . '%')->count(), 'nothing new, nobody told');
    }

    public function test_nothing_ticked_changes_nothing(): void
    {
        $a = $this->person();
        $this->actingAs($this->admin())->post('/diners', ['date' => '2026-11-06', 'users' => [$a->id], 'meals' => []])->assertSessionHas('diners_status', fn ($s) => $s[0] === false);
        $this->actingAs($this->admin())->post('/diners', ['date' => '2026-11-06', 'users' => [], 'meals' => ['lunch']])->assertSessionHas('diners_status', fn ($s) => $s[0] === false);
        $this->assertSame([], $this->meals($a, '2026-11-06'));
    }

    public function test_unknown_people_and_meals_are_ignored(): void
    {
        $a = $this->person();
        $this->actingAs($this->admin())->post('/diners', ['date' => '2026-11-06', 'users' => [$a->id, 'nobody-here'], 'meals' => ['lunch', 'brunch']]);
        $this->assertSame(['lunch'], $this->meals($a, '2026-11-06'));
        $this->assertSame(0, DB::table('oder')->where('user', 'nobody-here')->count());
    }

    public function test_an_admin_can_take_a_meal_off_and_a_missed_meal_gives_its_strike_back(): void
    {
        $a = $this->person();
        $id = $this->order($a, '2026-11-04', ['lunch', 'dinner'], ['no_show_lunch' => 1]);
        \App\Services\Standing::setStrikes($a->id, 1);
        $this->actingAs($this->admin())->post("/diners/{$id}/remove", ['meals' => 'lunch'])->assertRedirect('/diners?date=2026-11-04');
        $this->assertSame(['dinner'], $this->meals($a, '2026-11-04'));
        $this->assertSame(0, $this->strikes($a));
        $this->assertSame(1, DB::table('notifications')->where('title', 'Meal cancelled')->count());
    }

    /* ---- Attendance ---- */

    public function test_marking_a_no_show_adds_a_strike_and_undo_takes_it_back(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $admin = $this->admin();
        $this->actingAs($admin)->post("/attendance/{$id}", ['meals' => 'lunch', 'meal' => 'lunch', 'undo' => 0])->assertRedirect();
        $this->assertSame(1, (int) DB::table('oder')->where('id', $id)->value('no_show_lunch'));
        $this->assertSame(1, $this->strikes($p));
        $this->actingAs($admin)->post("/attendance/{$id}", ['meals' => 'lunch', 'meal' => 'lunch', 'undo' => 1]);
        $this->assertSame(0, (int) DB::table('oder')->where('id', $id)->value('no_show_lunch'));
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_marking_the_same_no_show_twice_counts_once(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $admin = $this->admin();
        $this->actingAs($admin)->post("/attendance/{$id}", ['meals' => 'lunch']);
        $this->actingAs($admin)->post("/attendance/{$id}", ['meals' => 'lunch']);
        $this->assertSame(1, $this->strikes($p));
    }

    public function test_the_third_no_show_pauses_booking(): void
    {
        $p = $this->person();
        $admin = $this->admin();
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $day) {
            $id = $this->order($p, $day, ['lunch']);
            $this->actingAs($admin)->post("/attendance/{$id}", ['meals' => 'lunch']);
        }
        $this->assertNotNull(DB::table('user')->where('id', $p->id)->value('blocked_until'));
    }

    public function test_a_future_day_cannot_be_marked(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-06', ['lunch']);
        $this->actingAs($this->admin())->post("/attendance/{$id}", ['meals' => 'lunch'])->assertStatus(422);
        $this->assertSame(0, $this->strikes($p));
    }
}
