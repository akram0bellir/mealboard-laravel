<?php

namespace Tests\Feature;

use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The control panel (/control): sign-in, its own CSRF token, and every action on the test database. */
class ControlPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mealboard.control_user' => 'Admin', 'mealboard.control_password' => 'Admin-Long-Secret']);
    }

    private function signIn(string $login = 'Admin', string $password = 'Admin-Long-Secret'): TestResponse
    {
        return $this->postJson('/control?a=login', ['login' => $login, 'password' => $password]);
    }

    /** An action with the panel's token, the way app.js sends it. */
    private function act(string $action, array $body = []): TestResponse
    {
        return $this->postJson('/control?a=' . $action, $body, ['X-CSRF' => (string) session('ctl_csrf')]);
    }

    public function test_the_page_shows_the_sign_in_form_then_the_panel(): void
    {
        $this->get('/control')->assertOk()->assertSee('id="login-form"', false)->assertDontSee('data-panel="health"', false);
        $this->signIn()->assertOk()->assertJson(['ok' => true, 'name' => 'Admin']);
        $this->get('/control')->assertOk()->assertSee('data-panel="health"', false)->assertSee('mealboard_test')
            ->assertHeader('X-Frame-Options', 'DENY')->assertSee('/control-assets/app.js', false);
    }

    public function test_who_may_sign_in(): void
    {
        $this->signIn('Admin', 'wrong')->assertStatus(400);
        $this->signIn($this->person()->email, 'test12345')->assertStatus(403)->assertJson(['message' => 'Only an active super admin can open the control panel.']);
        $this->signIn($this->admin('admin')->email, 'test12345')->assertStatus(403);
        $this->signIn($this->admin('super_admin')->email, 'test12345')->assertOk();

        $this->flushSession();
        config(['mealboard.control_user' => '', 'mealboard.control_password' => '']);
        $this->signIn('', '')->assertStatus(400);
        $this->signIn('Admin', '')->assertStatus(400);
    }

    public function test_five_wrong_passwords_lock_the_sign_in(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->signIn('Admin', 'wrong' . $i)->assertStatus(400);
        }
        $this->signIn()->assertStatus(429);
    }

    public function test_actions_need_the_sign_in_and_the_token(): void
    {
        $this->postJson('/control?a=health')->assertStatus(401);
        $this->signIn();
        $this->postJson('/control?a=health', [], ['X-CSRF' => 'wrong'])->assertStatus(403);
        $this->act('health')->assertOk();
        $this->act('nope')->assertStatus(404);
        $this->act('logout')->assertOk();
        $this->postJson('/control?a=health', [], ['X-CSRF' => 'x'])->assertStatus(401);
    }

    public function test_a_super_admin_who_loses_the_role_is_signed_out(): void
    {
        $super = $this->admin('super_admin');
        $this->signIn($super->email, 'test12345')->assertOk();
        $this->act('health')->assertOk();
        DB::table('user')->where('id', $super->id)->update(['User_Type' => 'admin']);
        $this->act('health')->assertStatus(401);
    }

    public function test_the_read_actions_answer(): void
    {
        $this->signIn();
        $checks = $this->act('health')->assertOk()->json('checks');
        $names = array_column($checks, 'name');
        foreach (['PHP version', 'Connection', 'Tables', 'Last run', 'Last database backup', 'SMTP settings', 'Queue', 'Automatic daily list'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertSame('ok', collect($checks)->firstWhere('name', 'Tables')['status'], json_encode(collect($checks)->firstWhere('name', 'Tables')));

        $this->act('cron_state')->assertOk()->assertJsonStructure(['log', 'marked', 'daily' => ['settings', 'last'], 'cronUrl', 'cliCron', 'cliBackup']);
        $this->act('emails_list')->assertOk()->assertJsonStructure(['rows']);
        $this->act('injected')->assertOk()->assertJsonStructure(['test people (by username)']);
        $this->act('activity')->assertOk()->assertJsonStructure(['rows']);
        $tables = collect($this->act('tables')->assertOk()->json('tables'));
        $this->assertSame(DB::table('oder')->count(), $tables->firstWhere('name', 'oder')['count']);
        $this->assertTrue($tables->firstWhere('name', '_mealboard_settings')['system']);
        $this->act('table_columns', ['table' => 'user'])->assertOk()->assertJsonFragment(['name' => 'password', 'type' => 'password']);
        $this->act('table_columns', ['table' => 'sessions'])->assertStatus(400);
    }

    public function test_the_cron_dry_run_follows_the_meal_end_and_writes_nothing(): void
    {
        $this->rules(['auto_no_show_from' => '2026-11-01 breakfast']);
        $p = $this->person();
        $this->order($p, '2026-11-05', ['lunch']);
        $this->signIn();

        $before = $this->act('cron_preview', ['at' => '2026-11-05T10:00'])->assertOk()->json();
        $lunch = collect($before['items'])->first(fn ($i) => $i['day'] === '2026-11-05' && $i['meal'] === 'lunch');
        $this->assertSame('wait', $lunch['state']);
        $this->assertTrue($before['simulated']);

        $after = $this->act('cron_preview', ['at' => '2026-11-05T23:30'])->json();
        $lunch = collect($after['items'])->first(fn ($i) => $i['day'] === '2026-11-05' && $i['meal'] === 'lunch');
        $this->assertSame('ready', $lunch['state']);
        $this->assertGreaterThanOrEqual(1, $lunch['open']);
        $this->assertSame(0, $this->strikes($p), 'a dry run never marks anyone');

        $this->act('cron_preview', ['at' => 'not a date'])->assertStatus(400);
    }

    public function test_inject_people_bookings_door_marks_and_remove_it_all(): void
    {
        $this->signIn();
        $made = $this->act('inject_users', ['count' => 3, 'role' => 'employee', 'department' => 'QA', 'meals' => ['lunch']])->assertOk()->json();
        $this->assertCount(3, $made['usernames']);
        $one = DB::table('user')->where('username', $made['usernames'][0])->first();
        $this->assertSame(0, (int) $one->Breakfast);
        $this->assertSame(1, (int) $one->Lunch);
        $this->assertTrue(password_verify((string) config('mealboard.default_password'), $one->password), 'the test people can sign in with the default password');

        // Thu 5 to Mon 9 Nov without the weekend = 3 days x 3 people.
        $this->act('inject_bookings', ['from' => '2026-11-05', 'to' => '2026-11-09', 'scope' => 'test', 'count' => 10, 'meals' => ['lunch']])->assertOk()->assertJson(['created' => 9, 'people' => 3]);
        $this->act('inject_bookings', ['from' => '2026-11-05', 'to' => '2026-11-05', 'scope' => 'test', 'meals' => ['dinner']])->assertOk()->assertJson(['created' => 0, 'mergedIntoExisting' => 3]);
        $this->act('inject_bookings', ['from' => '2026-11-09', 'to' => '2026-11-05', 'meals' => ['lunch']])->assertStatus(400);
        $this->act('inject_bookings', ['from' => '2026-11-05', 'meals' => []])->assertStatus(400);

        $marks = $this->act('inject_door', ['day' => '2026-11-05', 'meal' => 'lunch', 'served' => 0, 'missed' => 100, 'scope' => 'test'])->assertOk()->json();
        $this->assertSame(3, $marks['missed']);
        $this->assertSame(1, $this->strikes($one->id), 'a missed meal adds a strike');

        $this->act('inject_heartbeat', ['door' => 'test-door-1', 'name' => 'Test door', 'pending' => 2, 'oldestMinutes' => 90])->assertOk();
        $this->act('inject_heartbeat', ['door' => 'x'])->assertStatus(400);
        $this->assertSame(2, (int) DB::table('_mealboard_doors')->where('door', 'test-door-1')->value('pending'));
        $doors = collect($this->act('health')->json('checks'))->where('group', 'Doors');
        $this->assertSame('fail', $doors->firstWhere('name', 'Door Test door')['status'], 'unsent marks older than an hour');

        $removed = $this->act('cleanup')->assertOk()->json();
        $this->assertSame(3, $removed['people']);
        $this->assertSame(0, DB::table('user')->where('username', 'like', 'test\_%')->count());
        $this->assertFalse(DB::table('_mealboard_doors')->where('door', 'test-door-1')->exists());
        $this->assertSame([], Settings::get('control_injected'));

        $log = collect($this->act('activity')->json('rows'));
        $this->assertTrue($log->contains(fn ($r) => $r['action'] === 'cleanup' && $r['ok'] && $r['by'] === 'Admin'));
        $this->assertFalse($log->contains(fn ($r) => $r['action'] === 'health'), 'read actions are not logged');
    }

    public function test_inject_a_row_from_json(): void
    {
        $this->signIn();
        $food = $this->act('inject_row', ['table' => 'food', 'data' => ['title' => 'Ctl soup', 'description' => 'hot', 'type' => 'lunch']])->assertOk()->json();
        $this->assertSame('Ctl soup', DB::table('food')->where('id', $food['id'])->value('title'));
        $this->act('inject_row', ['table' => 'sessions', 'data' => []])->assertStatus(400);
        $this->act('inject_row', ['table' => 'food', 'data' => 'not json'])->assertStatus(400);
        $this->act('cleanup');
        $this->assertFalse(DB::table('food')->where('id', $food['id'])->exists());
    }

    public function test_the_table_editor_saves_searches_and_keeps_the_strikes_right(): void
    {
        $p = $this->person();
        $id = $this->order($p, '2026-11-04', ['lunch']);
        $this->signIn();

        $this->person(['username' => 'ctl-x-' . $p->id]);
        $this->act('table_rows', ['table' => 'user', 'search' => 'ctl_x_'])->assertOk()->assertJson(['total' => 0]); // _ is not a wildcard
        $rows = $this->act('table_rows', ['table' => 'oder', 'search' => $id])->assertOk()->json();
        $this->assertSame(1, $rows['total']);
        $this->assertTrue($rows['rows'][0]['bool2']);
        $this->assertSame('Test ' . $p->lastname, $rows['people'][$p->id]);

        $this->act('table_save', ['table' => 'oder', 'id' => $id, 'data' => ['no_show_lunch' => true]])->assertOk();
        $this->assertSame(1, $this->strikes($p), 'marking a meal missed adds a strike');
        $this->act('table_save', ['table' => 'oder', 'id' => $id, 'data' => ['no_show_lunch' => false]])->assertOk();
        $this->assertSame(0, $this->strikes($p), 'and un-marking gives it back');
        $this->act('table_save', ['table' => 'oder', 'id' => $id, 'data' => ['no_show_lunch' => true]]);
        $this->act('table_delete', ['table' => 'oder', 'ids' => [$id]])->assertOk()->assertJson(['deleted' => 1]);
        $this->assertSame(0, $this->strikes($p), 'deleting a missed booking gives the strike back');

        // People: password rules, e-mail check, duplicate.
        $this->act('table_save', ['table' => 'user', 'id' => $p->id, 'data' => ['password' => 'short']])->assertStatus(400);
        $this->act('table_save', ['table' => 'user', 'id' => $p->id, 'data' => ['email' => 'nope']])->assertStatus(400);
        $this->act('table_save', ['table' => 'user', 'id' => $p->id, 'data' => ['password' => 'a-new-password', 'firstname' => 'Renamed']])->assertOk()->assertJsonPath('row.firstname', 'Renamed');
        $this->assertTrue(password_verify('a-new-password', DB::table('user')->where('id', $p->id)->value('password')));
        $other = $this->person();
        $this->act('table_save', ['table' => 'user', 'id' => $other->id, 'data' => ['email' => $p->email]])->assertStatus(400)->assertJson(['message' => 'Another row already uses that value (duplicate e-mail, username or key).']);
        $new = $this->act('table_save', ['table' => 'user', 'id' => '', 'data' => ['username' => 'ctl_new_person', 'firstname' => 'New']])->assertOk()->json('row');
        $this->assertTrue($new['Status']);
        $this->assertArrayNotHasKey('password', $new);

        // System tables: read only, some rows deletable by their key.
        DB::table('_mealboard_marked')->insert(['day' => '2026-11-04', 'meal' => 'lunch', 'at' => 1, 'marked' => 0]);
        $this->act('table_rows', ['table' => '_mealboard_marked'])->assertOk()->assertJson(['editable' => false, 'deletable' => true]);
        $this->act('table_delete', ['table' => '_mealboard_marked', 'id' => '2026-11-04|lunch'])->assertJson(['deleted' => 1]);
        $this->act('table_delete', ['table' => '_mealboard_settings', 'id' => 'cron_log'])->assertStatus(400);
        $this->act('table_save', ['table' => '_mealboard_settings', 'data' => ['key' => 'x']])->assertStatus(400);
        $this->act('table_rows', ['table' => 'user', 'perPage' => 5, 'page' => 9999, 'sort' => 'firstname', 'dir' => 'asc'])->assertOk()->assertJson(['perPage' => 5]);
    }

    public function test_e_mail_actions_respect_the_switch_and_queue_test_mails(): void
    {
        $this->signIn();
        $this->act('smtp_test', ['to' => 'me@example.com'])->assertStatus(400)->assertJsonFragment(['message' => 'E-mail failed: E-mail is switched off: fill in the SMTP settings first (Settings → SMTP).']);
        $this->act('smtp_test', ['to' => 'not-an-address'])->assertStatus(400);
        $this->act('email_queue_test', ['to' => 'me@example.com'])->assertOk()->assertJson(['queued' => 1]);
        $row = DB::table('pending_emails')->where('to_address', 'me@example.com')->first();
        $this->assertNotNull($row);
        DB::table('pending_emails')->where('id', $row->id)->update(['attempts' => 3, 'error' => 'x']);
        $this->act('email_retry', ['id' => $row->id])->assertOk();
        $this->assertSame(0, (int) DB::table('pending_emails')->where('id', $row->id)->value('attempts'));
        $this->act('emails_flush')->assertOk()->assertJson(['sent' => 0, 'smtpConfigured' => false]);
        $this->act('daily_list_send', ['day' => '2026-11-05', 'to' => 'me@example.com'])->assertStatus(400);
        $this->act('cleanup');
        $this->assertFalse(DB::table('pending_emails')->where('id', $row->id)->exists());
    }

    public function test_run_the_cron_and_a_backup_from_the_panel(): void
    {
        $this->signIn();
        $this->act('cron_run')->assertOk()->assertJsonStructure(['autoNoShow', 'emails']);
        $this->assertSame('control', \App\Services\Cron::log()[0]['source']);

        $r = $this->act('backup_run')->assertOk()->json();
        $this->assertFileExists($r['file']);
        foreach (glob(config('mealboard.backup_dir') . '/*') as $f) {
            @unlink($f);
        }
        @rmdir(config('mealboard.backup_dir'));
    }
}
