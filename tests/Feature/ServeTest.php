<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServeController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The canteen door: ALLOWED / NOT ALLOWED for the meal being served (clock: Thursday 10:00). */
class ServeTest extends TestCase
{
    private function check($person, string $meal = 'lunch')
    {
        return $this->actingAs($this->admin())->post('/serve/person?partial=1', ['user' => $person->id, 'meal' => $meal]);
    }

    public function test_a_booked_person_is_allowed_and_marked_served(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-05', ['lunch']);
        $this->check($p)->assertOk()->assertSee('Allowed')->assertSee('Marked served');
        $this->assertSame(1, (int) DB::table('oder')->where('id', $id)->value('attended_lunch'));
    }

    public function test_the_same_person_cannot_be_served_twice(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch']);
        $this->check($p);
        $this->check($p)->assertSee('Not allowed')->assertSee('Already served');
    }

    public function test_nobody_without_a_booking_gets_in(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch']);
        $this->check($p, 'dinner')->assertSee('Not allowed')->assertSee('No dinner booking today');
        $this->check($this->person())->assertSee('No lunch booking today');
    }

    public function test_a_late_cancelled_meal_is_refused(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch'], ['no_show_lunch' => 1]);
        $this->check($p)->assertSee('Not allowed')->assertSee('cancelled late');
    }

    public function test_a_switched_off_account_is_refused(): void
    {
        $p = $this->person(['Status' => 0]);
        $id = $this->order($p, '2026-11-05', ['lunch']);
        $this->check($p)->assertSee('Not allowed')->assertSee('switched off');
        $this->assertSame(0, (int) DB::table('oder')->where('id', $id)->value('attended_lunch'));
    }

    public function test_yesterdays_booking_does_not_count_today(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-04', ['lunch']);
        $this->check($p)->assertSee('Not allowed');
    }

    public function test_the_gate_failing_does_not_undo_the_meal(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-05', ['lunch']);
        $this->check($p)->assertSee('the gate did not open');
        $this->assertSame(1, (int) DB::table('oder')->where('id', $id)->value('attended_lunch'));
    }

    public function test_a_badge_is_turned_into_the_card_number(): void
    {
        $this->assertSame(2400993716, ServeController::cardNumber('B4411C8F'));
        $this->assertNull(ServeController::cardNumber('B4411C'), 'not a 4-byte card');
        $this->assertNull(ServeController::cardNumber('AABBCCDDEEFF00'), '7-byte badges have no card number');
    }

    public function test_a_known_badge_is_checked(): void
    {
        $p = $this->person(['card_id' => 2400993716]);
        $this->order($p, '2026-11-05', ['lunch']);
        $this->actingAs($this->admin())->post('/serve/badge?partial=1', ['uid' => 'b4:41:1c:8f', 'meal' => 'lunch'])->assertSee('Allowed')->assertSee('2400993716');
    }

    public function test_an_unknown_badge_can_be_given_to_a_person(): void
    {
        $this->actingAs($this->admin())->post('/serve/badge?partial=1', ['uid' => '01020304', 'meal' => 'lunch'])->assertSee('Card not in the user list');
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch']);
        $this->actingAs($this->admin())->post('/serve/link?partial=1', ['user' => $p->id, 'cardNumber' => 123456789, 'meal' => 'lunch'])->assertSee('Allowed');
        $this->assertSame(123456789, (int) DB::table('user')->where('id', $p->id)->value('card_id'));
    }

    public function test_search_finds_people_by_name_and_by_card_number(): void
    {
        $this->person(['firstname' => 'Zebulon', 'lastname' => 'Quark', 'card_id' => 555000111]);
        $admin = $this->admin();
        $this->actingAs($admin)->get('/serve?q=quark')->assertSee('Zebulon Quark');
        $this->actingAs($admin)->get('/serve?q=555000111')->assertSee('Zebulon Quark');
        $this->actingAs($admin)->get('/serve?q=zzzznobody')->assertSee('Nobody found');
    }

    public function test_the_nfc_check_panel_and_relay_dialog_are_on_the_page(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/serve')->assertSee('data-nfc-check', false)->assertSee('Find relay');
        $this->actingAs($admin)->get('/serve?relay=1')->assertSee('Plug the USB relay');
    }

    public function test_only_admins_serve(): void
    {
        $this->actingAs($this->admin('manager'))->get('/serve')->assertForbidden();
        $this->actingAs($this->person())->get('/serve')->assertRedirect('/week');
    }
}
