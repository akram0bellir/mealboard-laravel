<?php

namespace Tests\Feature;

use App\Services\Standing;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The admin's full power over who eats: ranges, everyone at once, served marks, and "Serve anyway" at the door. */
class AdminPowerTest extends TestCase
{
    private function menu(string $day, array $meals): void
    {
        DB::table('meun')->insert([
            'id' => Ids::new(), 'day' => "$day 12:00:00.000", 'created' => Ids::now(), 'updated' => Ids::now(),
            'brakfast_title' => in_array('breakfast', $meals, true) ? 'Bread' : '', 'brakfast_description' => '',
            'lunch_title' => in_array('lunch', $meals, true) ? 'Couscous' : '', 'lunch_description' => '',
            'Dinner_title' => in_array('dinner', $meals, true) ? 'Soup' : '', 'Dinner_description' => '',
        ]);
    }

    public function test_a_range_of_days_is_booked_in_one_go_with_one_notification(): void
    {
        $p = $this->person();
        $this->actingAs($this->admin())->post('/diners', ['date' => '2031-03-03', 'until' => '2031-03-07', 'users' => [$p->id], 'meals' => ['lunch']])
            ->assertSessionHas('diners_status', fn ($s) => $s[0] === true && str_contains($s[1], 'Added 5 meal(s)'));
        foreach (['2031-03-03', '2031-03-04', '2031-03-05', '2031-03-06', '2031-03-07'] as $d) {
            $this->assertSame(['lunch'], $this->meals($p, $d), $d);
        }
        $this->assertSame([], $this->meals($p, '2031-03-08'));
        $this->assertSame(1, DB::table('notifications')->where('recipients', 'like', '%' . $p->id . '%')->count(), 'one message, not five');
        $this->assertStringContainsString('5 meals between', DB::table('notifications')->where('recipients', 'like', '%' . $p->id . '%')->value('message'));
    }

    public function test_only_days_with_that_meal_on_the_menu(): void
    {
        $p = $this->person();
        $this->menu('2031-04-01', ['lunch']);
        $this->menu('2031-04-02', ['dinner']);
        $this->actingAs($this->admin())->post('/diners', ['date' => '2031-04-01', 'until' => '2031-04-03', 'users' => [$p->id], 'meals' => ['lunch'], 'menu_only' => 1])
            ->assertSessionHas('diners_status', fn ($s) => str_contains($s[1], 'Added 1 meal(s)') && str_contains($s[1], '2 skipped'));
        $this->assertSame(['lunch'], $this->meals($p, '2031-04-01'));
        $this->assertSame([], $this->meals($p, '2031-04-02'));
        $this->assertSame([], $this->meals($p, '2031-04-03'));
    }

    public function test_bad_ranges_are_refused(): void
    {
        $p = $this->person();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/diners', ['date' => '2031-05-10', 'until' => '2031-05-01', 'users' => [$p->id], 'meals' => ['lunch']])->assertSessionHas('diners_status', fn ($s) => $s[0] === false);
        $this->actingAs($admin)->post('/diners', ['date' => '2031-01-01', 'until' => '2031-12-31', 'users' => [$p->id], 'meals' => ['lunch']])->assertSessionHas('diners_status', fn ($s) => $s[0] === false && str_contains($s[1], 'At most'));
        $this->assertSame(0, DB::table('oder')->where('user', $p->id)->count());
    }

    public function test_everyone_in_the_list_at_once(): void
    {
        $a = $this->person(['lastname' => 'Zzbatch']);
        $b = $this->person(['lastname' => 'Zzbatch']);
        $c = $this->person(['lastname' => 'Zzbatch', 'Status' => 0]);
        $other = $this->person(['lastname' => 'Elsewhere']);
        $this->actingAs($this->admin())->post('/diners', ['date' => '2031-06-01', 'everyone' => 1, 'q' => 'zzbatch', 'meals' => ['dinner']]);
        foreach ([$a, $b, $c] as $p) {
            $this->assertSame(['dinner'], $this->meals($p, '2031-06-01'));
        }
        $this->assertSame([], $this->meals($other, '2031-06-01'), 'people outside the list are left alone');
    }

    public function test_marking_served_and_undoing_it(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch'], ['no_show_lunch' => 1]);
        Standing::setStrikes($p->id, 1);
        $admin = $this->admin();
        $this->actingAs($admin)->post("/diners/{$id}/served", ['meal' => 'lunch', 'served' => 1])->assertRedirect('/diners?date=2026-11-04');
        $row = DB::table('oder')->where('id', $id)->first();
        $this->assertSame([1, 0], [(int) $row->attended_lunch, (int) $row->no_show_lunch], 'served, and no longer missed');
        $this->assertSame(0, $this->strikes($p), 'the strike is given back');
        $this->actingAs($admin)->post("/diners/{$id}/served", ['meal' => 'lunch', 'served' => 0]);
        $this->assertSame(0, (int) DB::table('oder')->where('id', $id)->value('attended_lunch'));
        $this->actingAs($admin)->post("/diners/{$id}/served", ['meal' => 'dinner', 'served' => 1])->assertSessionHas('diners_status', fn ($s) => $s[0] === false);
    }

    /* ---- the door: Serve anyway ---- */

    private function force($person, string $meal = 'lunch')
    {
        return $this->actingAs($this->admin())->post('/serve/force?partial=1', ['user' => $person->id, 'meal' => $meal]);
    }

    public function test_someone_who_did_not_book_can_be_served_anyway(): void
    {
        $p = $this->person();
        $this->actingAs($this->admin())->post('/serve/person?partial=1', ['user' => $p->id, 'meal' => 'lunch'])->assertSee('Not allowed')->assertSee('Serve anyway');
        $this->force($p)->assertSee('Allowed')->assertSee('Served by an admin');
        $row = DB::table('oder')->where('user', $p->id)->where('date', '>=', '2026-11-05 00:00:00')->first();
        $this->assertSame([1, 1], [(int) $row->bool2, (int) $row->attended_lunch], 'booked and served');
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_paused_or_switched_off_people_can_be_served_anyway(): void
    {
        $paused = $this->person(['Strikes' => 3, 'period_start' => '2026-11-01 12:00:00.000', 'blocked_until' => '2026-12-01 12:00:00.000']);
        $off = $this->person(['Status' => 0]);
        $this->force($paused)->assertSee('Allowed');
        $this->force($off)->assertSee('Allowed');
        $this->assertSame(1, (int) DB::table('oder')->where('user', $off->id)->value('attended_lunch'));
    }

    public function test_a_late_cancelled_meal_served_anyway_gives_the_strike_back(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-05', ['lunch'], ['no_show_lunch' => 1]);
        Standing::setStrikes($p->id, 1);
        $this->force($p)->assertSee('Allowed');
        $this->assertSame([1, 0], [(int) DB::table('oder')->where('id', $id)->value('attended_lunch'), (int) DB::table('oder')->where('id', $id)->value('no_show_lunch')]);
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_someone_already_served_can_be_let_through_again(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch'], ['attended_lunch' => 1]);
        $this->force($p)->assertSee('Allowed')->assertSee('Let through again');
        $this->assertSame(1, DB::table('oder')->where('user', $p->id)->count(), 'no second booking');
    }

    public function test_only_admins_have_these_powers(): void
    {
        $p = $this->person();
        $this->actingAs($this->admin('manager'))->post('/serve/force', ['user' => $p->id, 'meal' => 'lunch'])->assertForbidden();
        $this->actingAs($this->person())->post('/serve/force', ['user' => $p->id, 'meal' => 'lunch'])->assertRedirect('/week');
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $this->actingAs($this->admin('manager'))->post("/diners/{$id}/served", ['meal' => 'lunch', 'served' => 1])->assertForbidden();
        $this->assertSame(0, DB::table('oder')->where('user', $p->id)->where('attended_lunch', 1)->count());
    }
}
