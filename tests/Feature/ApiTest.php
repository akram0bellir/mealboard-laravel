<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The JSON API the desktop door app and the gate call. */
class ApiTest extends TestCase
{
    private function token(User $user): string
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'test12345'])->assertOk()->json('token');
    }

    private function as(User $user): array
    {
        return ['Authorization' => $this->token($user)];
    }

    private function door(): User
    {
        return $this->person(['User_Type' => 'door', 'Department' => '']);
    }

    public function test_sign_in_gives_a_token_and_a_typed_record_without_secrets(): void
    {
        $d = $this->door();
        $r = $this->postJson('/api/auth/login', ['email' => $d->email, 'password' => 'test12345'])->assertOk();
        $this->assertSame(64, strlen($r->json('token')));
        $this->assertTrue($r->json('record.Status'));
        $this->assertMatchesRegularExpression('/Z$/', $r->json('record.created'));
        $r->assertJsonMissingPath('record.password')->assertJsonMissingPath('record.tokenKey');
    }

    public function test_wrong_password_or_switched_off_account_is_refused(): void
    {
        $d = $this->door();
        $this->postJson('/api/auth/login', ['email' => $d->email, 'password' => 'x'])->assertStatus(400)->assertJson(['message' => 'Failed to authenticate.']);
        DB::table('user')->where('id', $d->id)->update(['Status' => 0]);
        $this->postJson('/api/auth/login', ['email' => $d->email, 'password' => 'test12345'])->assertStatus(400);
    }

    public function test_refresh_logout_and_bad_tokens(): void
    {
        $d = $this->door();
        $h = $this->as($d);
        $this->postJson('/api/auth/refresh', [], $h)->assertOk()->assertJson(['token' => $h['Authorization']]);
        $this->postJson('/api/auth/refresh', [], ['Authorization' => 'nope'])->assertStatus(401);
        $this->getJson('/api/users', [])->assertStatus(401)->assertJson(['message' => 'Sign in to continue.']);
        $this->postJson('/api/auth/logout', [], $h)->assertOk();
        $this->getJson('/api/users', $h)->assertStatus(401);
    }

    public function test_an_expired_token_stops_working(): void
    {
        $d = $this->door();
        $h = $this->as($d);
        DB::table('auth_tokens')->where('user_id', $d->id)->update(['expires' => '2020-01-01 00:00:00.000']);
        $this->getJson('/api/users', $h)->assertStatus(401);
    }

    public function test_unknown_addresses_answer_json(): void
    {
        $this->getJson('/api/nothing')->assertStatus(404)->assertExactJson(['status' => 404, 'message' => 'Not found.']);
        $this->deleteJson('/api/booking-rules')->assertStatus(405);
    }

    public function test_the_door_reads_all_people_but_an_employee_only_themselves(): void
    {
        $d = $this->door();
        $e = $this->person();
        $all = $this->getJson('/api/users?fields=id,firstname', $this->as($d))->assertOk()->json('items');
        $this->assertSame(DB::table('user')->count(), count($all));
        $this->assertSame(['id', 'firstname'], array_keys($all[0]));
        $own = $this->getJson('/api/users', $this->as($e))->json('items');
        $this->assertCount(1, $own);
        $this->assertSame($e->id, $own[0]['id']);
        $this->getJson('/api/users/search?q=a', $this->as($e))->assertForbidden();
        $this->getJson('/api/users/' . $d->id, $this->as($e))->assertForbidden();
    }

    public function test_badge_lookup(): void
    {
        $d = $this->door();
        $p = $this->person(['card_id' => 777000111]);
        $this->getJson('/api/users/by-badge?card_id=777000111', $this->as($d))->assertOk()->assertJson(['id' => $p->id]);
        $unknown = $this->getJson('/api/users/by-badge?card_id=999999999999', $this->as($d))->assertOk();
        $this->assertSame('null', $unknown->getContent(), 'nobody has it: JSON null');
        $this->getJson('/api/users/by-badge', $this->as($d))->assertStatus(400);
    }

    public function test_the_door_may_only_link_a_badge(): void
    {
        $d = $this->door();
        $p = $this->person();
        $this->patchJson('/api/users/' . $p->id, ['card_id' => 321654987], $this->as($d))->assertOk()->assertJson(['card_id' => 321654987]);
        $this->patchJson('/api/users/' . $p->id, ['firstname' => 'Hacked'], $this->as($d))->assertForbidden()->assertJson(['message' => 'The door can only link badges.']);
        $this->patchJson('/api/users/' . $p->id, ['card_id' => 5], $this->as($this->person()))->assertForbidden();
        $this->assertNotSame('Hacked', DB::table('user')->where('id', $p->id)->value('firstname'));
    }

    public function test_the_days_bookings_with_filters_and_paging(): void
    {
        $d = $this->door();
        $a = $this->person();
        $this->order($a, '2031-03-03', ['lunch']);
        $this->order($this->person(), '2031-03-03', ['dinner']);
        $this->order($this->person(), '2031-03-04', ['lunch']);
        $h = $this->as($d);
        $this->assertCount(2, $this->getJson('/api/orders?day=2031-03-03', $h)->json('items'));
        $lunch = $this->getJson('/api/orders?day=2031-03-03&meal=lunch&fields=id,bool2', $h)->json('items');
        $this->assertCount(1, $lunch);
        $this->assertTrue($lunch[0]['bool2']);
        $this->assertCount(3, $this->getJson('/api/orders?from=2031-03-03&to=2031-03-04', $h)->json('items'));
        $paged = $this->getJson('/api/orders?since=2031-03-03&perPage=2&page=2', $h)->json();
        $this->assertSame(2, $paged['page']);
        $this->assertCount(1, $paged['items']);
        $this->getJson('/api/orders?day=garbage', $h)->assertStatus(400);
    }

    public function test_an_employee_reads_only_their_own_bookings(): void
    {
        $e = $this->person();
        $other = $this->person();
        $mine = $this->order($e, '2031-03-03', ['lunch']);
        $theirs = $this->order($other, '2031-03-03', ['lunch']);
        $h = $this->as($e);
        $ids = array_column($this->getJson('/api/orders?day=2031-03-03', $h)->json('items'), 'id');
        $this->assertSame([$mine], $ids);
        $this->getJson('/api/orders?user=' . $other->id, $h)->assertForbidden();
        $this->getJson('/api/orders/' . $theirs, $h)->assertForbidden();
        $this->getJson('/api/orders/' . $mine, $h)->assertOk();
    }

    public function test_the_door_marks_meals_served_and_nothing_else(): void
    {
        $d = $this->door();
        $p = $this->person();
        $id = $this->order($p, '2026-11-05', ['lunch']);
        $h = $this->as($d);
        $this->patchJson("/api/orders/$id", ['attended_lunch' => true], $h)->assertOk()->assertJson(['attended_lunch' => true]);
        $this->patchJson("/api/orders/$id", ['bool3' => true], $h)->assertForbidden()->assertJson(['message' => 'The door can only mark meals as served or missed.']);
        $this->patchJson("/api/orders/$id", ['attended_lunch' => false], $this->as($p))->assertForbidden();
        $this->deleteJson("/api/orders/$id", [], $h)->assertStatus(405);
    }

    public function test_marking_missed_from_the_door_adds_a_strike_and_undoing_takes_it_back(): void
    {
        $d = $this->door();
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $h = $this->as($d);
        $this->patchJson("/api/orders/$id", ['no_show_lunch' => true], $h)->assertOk();
        $this->assertSame(1, $this->strikes($p));
        $this->patchJson("/api/orders/$id", ['no_show_lunch' => true], $h);
        $this->assertSame(1, $this->strikes($p), 'sending it again does not add a second strike');
        $this->patchJson("/api/orders/$id", ['no_show_lunch' => false], $h);
        $this->assertSame(0, $this->strikes($p));
    }

    public function test_the_first_missed_meal(): void
    {
        $p = $this->person();
        $this->order($p, '2026-11-03', ['lunch'], ['no_show_lunch' => 1]);
        $this->order($p, '2026-11-04', ['lunch'], ['no_show_lunch' => 1]);
        $this->getJson('/api/orders/first-missed?user=' . $p->id, $this->as($this->admin()))->assertOk()->assertJsonPath('date', '2026-11-03 12:00:00.000Z');
        $this->getJson('/api/orders/first-missed?user=' . $p->id . '&since=2026-11-04', $this->as($this->admin()))->assertJsonPath('date', '2026-11-04 12:00:00.000Z');
    }

    public function test_the_heartbeat_is_stored_and_checked(): void
    {
        $d = $this->door();
        $h = $this->as($d);
        $this->postJson('/api/mealboard/door-heartbeat', ['door' => 'hb-test-1', 'name' => 'Canteen', 'pending' => 3, 'oldestPendingAt' => 1760000000000, 'closeAfterMinutes' => 500], $h)->assertOk();
        $row = DB::table('_mealboard_doors')->where('door', 'hb-test-1')->first();
        $this->assertSame(3, (int) $row->pending);
        $this->assertSame(180, (int) $row->close_after, 'capped at 180 minutes');
        $this->assertEqualsWithDelta(\App\Services\Cron::nowMs(), (int) $row->last_seen, 5000);
        $this->postJson('/api/mealboard/door-heartbeat', ['door' => 'hb-test-1', 'pending' => 0], $h);
        $this->assertNull(DB::table('_mealboard_doors')->where('door', 'hb-test-1')->value('oldest_pending'), 'nothing pending: no oldest mark');
        $this->assertSame(1, DB::table('_mealboard_doors')->where('door', 'hb-test-1')->count(), 'one row per door');
        $this->postJson('/api/mealboard/door-heartbeat', ['door' => 'x'], $h)->assertStatus(400);
        $this->postJson('/api/mealboard/door-heartbeat', ['door' => 'hb-test-2'], $this->as($this->person()))->assertForbidden();
    }

    public function test_public_and_read_only_rules(): void
    {
        $this->getJson('/api/booking-rules')->assertOk()->assertJsonStructure(['max_no_show', 'ramadan_mode']);
        $this->getJson('/api/menu?day=2026-11-05')->assertOk()->assertJsonStructure(['items']);
        $d = $this->door();
        $this->getJson('/api/meal-groups', $this->as($d))->assertOk()->assertJsonStructure(['items' => [['id', 'name', 'meal', 'start', 'end']]]);
        $this->getJson('/api/department-rules', $this->as($d))->assertOk();
        $this->getJson('/api/meal-groups')->assertStatus(401);
    }

    public function test_only_a_super_admin_edits_meal_groups_and_department_rules(): void
    {
        $door = $this->as($this->door());
        $admin = $this->as($this->admin('admin'));
        $super = $this->as($this->admin('super_admin'));
        foreach ([$door, $admin] as $h) {
            $this->postJson('/api/meal-groups', ['name' => 'X'], $h)->assertForbidden();
            $this->putJson('/api/department-rules/7', ['lunch' => true], $h)->assertForbidden();
        }
        $id = $this->postJson('/api/meal-groups', ['name' => 'Test group', 'meal' => 'lunch', 'start' => '11:00', 'end' => '12:00'], $super)->assertStatus(201)->json('id');
        $this->patchJson("/api/meal-groups/$id", ['ramadan_start' => '12:00', 'ramadan_end' => '13:00'], $super)->assertOk()->assertJson(['start' => '11:00', 'ramadan_end' => '13:00']);
        $this->putJson('/api/department-rules/7', ['lunch_group' => $id], $super)->assertStatus(201)->assertJson(['department' => '7', 'lunch' => true, 'lunch_group' => $id]);
        $this->putJson('/api/department-rules/7', ['dinner' => true], $super)->assertOk()->assertJson(['dinner' => true, 'lunch_group' => $id]);
        $this->assertSame(1, DB::table('department_rules')->where('department', '7')->count(), 'updated, not duplicated');
        $this->deleteJson("/api/meal-groups/$id", [], $super)->assertOk();
        $this->deleteJson('/api/meal-groups/doesnotexist', [], $super)->assertStatus(404);
    }

    public function test_the_gate_address_is_for_admins(): void
    {
        $this->postJson('/api/gate', ['action' => 'devices'], $this->as($this->door()))->assertForbidden()->assertJson(['ok' => false]);
        $h = $this->as($this->admin());
        $this->postJson('/api/gate', ['action' => 'devices'], $h)->assertOk()->assertJsonStructure(['devices', 'config']);
        $this->postJson('/api/gate', ['action' => 'open'], $h)->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/api/gate', ['action' => 'test'], $h)->assertStatus(400);
        $this->postJson('/api/gate', ['action' => 'dance'], $h)->assertStatus(400);
        $this->postJson('/api/gate', ['action' => 'choose', 'config' => ['port' => 'COM5', 'message' => 'text', 'text' => '1', 'ending' => "\r\n"]], $h)
            ->assertOk()->assertJson(['port' => 'COM5', 'ending' => "\r\n"]);
    }

    public function test_the_cron_address_needs_its_key(): void
    {
        $this->getJson('/api/cron-run')->assertForbidden();
        $this->getJson('/api/cron-run?key=wrong')->assertForbidden();
        $this->getJson('/api/cron-run?key=test-cron-key')->assertOk()->assertJsonStructure(['autoNoShow', 'emails', 'at']);
        $this->postJson('/api/cron-run?key=test-cron-key')->assertOk();
        $this->withHeaders(['X-Cron-Key' => 'test-cron-key'])->getJson('/api/cron')->assertOk();
    }

    public function test_other_addresses_may_call_the_api(): void
    {
        $this->call('OPTIONS', '/api/orders', [], [], [], ['HTTP_ORIGIN' => 'http://10.0.0.5', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
