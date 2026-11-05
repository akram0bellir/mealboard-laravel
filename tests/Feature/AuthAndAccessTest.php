<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthAndAccessTest extends TestCase
{
    public function test_an_employee_signs_in_and_lands_on_the_week(): void
    {
        $p = $this->person();
        $this->post('/login', ['email' => $p->email, 'password' => 'test12345'])->assertRedirect('/week');
        $this->assertAuthenticatedAs($p);
    }

    public function test_sign_in_works_with_a_username_and_any_letter_case(): void
    {
        $p = $this->person();
        $this->post('/login', ['email' => strtoupper($p->username), 'password' => 'test12345'])->assertRedirect('/week');
    }

    public function test_an_admin_lands_on_the_dashboard(): void
    {
        $a = $this->admin();
        $this->post('/login', ['email' => $a->email, 'password' => 'test12345'])->assertRedirect('/admin');
    }

    public function test_wrong_password_is_refused(): void
    {
        $p = $this->person();
        $this->post('/login', ['email' => $p->email, 'password' => 'nope'])->assertRedirect('/')->assertSessionHas('login_error');
        $this->assertGuest();
    }

    public function test_five_wrong_tries_lock_the_account_for_a_while(): void
    {
        $p = $this->person();
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => $p->email, 'password' => 'wrong'.$i]);
        }
        $this->post('/login', ['email' => $p->email, 'password' => 'test12345'])->assertSessionHas('login_error');
        $this->assertGuest();
        $this->assertStringContainsString('Too many', session('login_error'));
    }

    public function test_a_switched_off_account_cannot_sign_in(): void
    {
        $p = $this->person(['Status' => 0]);
        $this->post('/login', ['email' => $p->email, 'password' => 'test12345'])->assertSessionHas('login_error');
        $this->assertGuest();
    }

    public function test_the_door_account_cannot_use_the_website(): void
    {
        $p = $this->person(['User_Type' => 'door']);
        $this->post('/login', ['email' => $p->email, 'password' => 'test12345'])->assertSessionHas('login_error');
        $this->assertGuest();
    }

    public function test_the_default_password_must_be_changed_first(): void
    {
        $p = $this->person(['password' => password_hash(config('mealboard.default_password'), PASSWORD_BCRYPT)]);
        $this->post('/login', ['email' => $p->email, 'password' => config('mealboard.default_password')])->assertRedirect('/');
        $this->assertGuest();
        $this->assertSame($p->id, session('first_login'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach (['/week', '/profile', '/admin', '/users', '/system'] as $url) {
            $this->get($url)->assertRedirect('/');
        }
    }

    public function test_sign_out_ends_the_session(): void
    {
        $this->actingAs($this->person())->post('/logout');
        $this->assertGuest();
    }

    public function test_an_employee_never_sees_the_admin_side(): void
    {
        $e = $this->person();
        foreach (['/week', '/notifications', '/report', '/profile'] as $url) {
            $this->actingAs($e)->get($url)->assertOk();
        }
        foreach (['/admin', '/users', '/diners', '/settings', '/system', '/invoices'] as $url) {
            $this->actingAs($e)->get($url)->assertRedirect('/week');
        }
    }

    public function test_the_admin_side_never_shows_the_employee_pages(): void
    {
        $this->actingAs($this->admin())->get('/week')->assertRedirect('/admin');
    }

    public function test_a_manager_opens_only_four_pages(): void
    {
        $m = $this->admin('manager');
        foreach (['/admin', '/meals', '/reports', '/statistics'] as $url) {
            $this->actingAs($m)->get($url)->assertOk();
        }
        foreach (['/users', '/diners', '/attendance', '/invoices', '/penalties', '/settings', '/system', '/serve', '/admain_notifications'] as $url) {
            $this->actingAs($m)->get($url)->assertForbidden();
        }
    }

    public function test_an_admin_opens_everything_but_the_super_admin_pages(): void
    {
        $a = $this->admin('admin');
        foreach (['/admin', '/meals', '/users', '/diners', '/attendance', '/serve', '/reports', '/statistics', '/invoices', '/penalties', '/admain_notifications'] as $url) {
            $this->actingAs($a)->get($url)->assertOk();
        }
        $this->actingAs($a)->get('/system')->assertForbidden();
        $this->actingAs($a)->post('/system/cron')->assertForbidden();
        $this->actingAs($a)->post('/settings/prices', ['price' => ['lunch' => 1]])->assertForbidden();
    }

    public function test_the_super_admin_opens_settings_and_system(): void
    {
        $s = $this->admin('super_admin');
        foreach (['/settings', '/system'] as $url) {
            $this->actingAs($s)->get($url)->assertOk();
        }
    }

    public function test_only_staff_can_use_the_admin_actions(): void
    {
        $e = $this->person();
        $order = $this->order($e, '2026-11-04', ['lunch']);
        $this->actingAs($e)->post("/attendance/{$order}", ['meals' => 'lunch'])->assertRedirect('/week');
        $this->assertSame(0, (int) DB::table('oder')->where('id', $order)->value('no_show_lunch'));
        $this->actingAs($e)->post('/diners', ['date' => '2026-11-06', 'users' => [$e->id], 'meals' => ['lunch']])->assertRedirect('/week');
        $this->assertSame([], $this->meals($e, '2026-11-06'));
    }
}
