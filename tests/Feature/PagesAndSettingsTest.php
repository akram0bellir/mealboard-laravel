<?php

namespace Tests\Feature;

use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PagesAndSettingsTest extends TestCase
{
    /* ---- invoices and statistics: the figures must match the data ---- */

    public function test_the_september_invoice_total(): void
    {
        $this->actingAs($this->admin())->get('/invoices?month=2026-09')->assertOk()->assertSee('1,213,086.00')->assertSee('INV-202609');
    }

    public function test_the_invoice_csv_and_the_sent_mark(): void
    {
        $admin = $this->admin();
        $r = $this->actingAs($admin)->get('/invoices/csv?month=2026-09');
        $r->assertOk();
        $this->assertStringContainsString('csv', $r->headers->get('Content-Type'));
        $this->actingAs($admin)->post('/invoices/sent', ['month' => '2026-09'])->assertRedirect();
        $this->assertContains('2026-09', array_keys(Settings::get('invoices_sent')) ?: Settings::get('invoices_sent'));
    }

    public function test_the_statistics_figures_match_the_database(): void
    {
        $r = $this->actingAs($this->admin())->get('/statistics?period=month&anchor=2026-09-15')->assertOk();
        $this->assertMatchesRegularExpression('/data-stat-booked>1823</', $r->getContent());
        $this->assertMatchesRegularExpression('/data-stat-missed>9</', $r->getContent());
        // independent count
        $staff = DB::table('user')->whereIn('User_Type', ['admin', 'admain', 'super_admin', 'superadmin', 'super admin', 'super_admain', 'door'])->pluck('id')->all();
        $row = DB::table('oder')->where('date', '>=', '2026-09-01 00:00:00')->where('date', '<=', '2026-09-30 23:59:59.999')->whereNotIn('user', $staff)
            ->selectRaw('sum(bool)+sum(bool2)+sum(bool3) booked')->first();
        $this->assertSame(1823, (int) $row->booked);
    }

    public function test_every_statistics_view_renders(): void
    {
        $admin = $this->admin();
        foreach (['period=day&anchor=2026-09-15', 'period=week&meal=lunch&anchor=2026-09-15', 'period=year&department=2&sex=female&anchor=2026-09-15', 'period=month&anchor=2026-01-05', 'period=month&admins=1', 'x_metric=cost&x_split=weekday&x_mode=previous', 'x_mode=groups&x_cdim=position&x_a=a&x_b=b', 'x_metric=zzz&x_split=zzz'] as $q) {
            $this->actingAs($admin)->get('/statistics?' . $q)->assertOk();
        }
        $this->actingAs($admin)->get('/statistics/csv?period=month&anchor=2026-09-15')->assertOk();
        $this->actingAs($admin)->get('/statistics/explore.csv?x_metric=cost&x_split=department')->assertOk();
    }

    public function test_explore_views_are_saved_per_admin(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $this->actingAs($a)->post('/statistics/explore/save?x_metric=cost&x_split=department&x_mode=none')->assertRedirect();
        $this->assertCount(1, Settings::get('explore_views_' . $a->id));
        $this->assertSame([], Settings::get('explore_views_' . $b->id));
        $name = Settings::get('explore_views_' . $a->id)[0]['name'];
        $this->actingAs($a)->post('/statistics/explore/delete', ['name' => $name]);
        $this->assertSame([], Settings::get('explore_views_' . $a->id));
    }

    /* ---- dashboard: the order list e-mail ---- */

    public function test_the_order_list_dialog_and_its_refusals(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin?email=1')->assertOk()->assertSee('Email the order list');
        $this->actingAs($admin)->post('/admin/orders-email', ['day' => '2026-11-05', 'recipients' => 'not-an-address'])->assertSessionHas('email_status', fn ($s) => $s[0] === false && str_contains($s[1], 'Not an e-mail address'));
        $this->actingAs($admin)->post('/admin/orders-email', ['day' => '2026-11-05', 'recipients' => 'a@x.com'])->assertSessionHas('email_status', fn ($s) => $s[0] === false && str_contains($s[1], 'switched off'));
        $this->actingAs($admin)->post('/admin/orders-email', ['day' => 'bad', 'recipients' => 'a@x.com'])->assertSessionHas('email_status', fn ($s) => $s[0] === false);
    }

    /* ---- System page ---- */

    public function test_the_system_page_and_its_buttons(): void
    {
        $dir = base_path((string) config('mealboard.backup_dir'));
        $super = $this->admin('super_admin');
        $this->actingAs($super)->get('/system')->assertOk()->assertSee('What works')->assertSee('Switched off in .env');
        $this->actingAs($super)->post('/system/cron')->assertRedirect('/system')->assertSessionHas('system_status', fn ($s) => $s[0] === true && str_starts_with($s[1], 'Cron ran'));
        $this->actingAs($super)->post('/system/emails')->assertSessionHas('system_status', fn ($s) => $s[0] === false);
        $this->actingAs($super)->post('/system/backup')->assertRedirect('/system')->assertSessionHas('system_status', fn ($s) => str_starts_with($s[1], 'Backup written'));
        $this->assertNotEmpty(glob(config('mealboard.backup_dir') . '/mealboard-*.sql.gz'));
        foreach (glob(config('mealboard.backup_dir') . '/*') as $f) {
            @unlink($f);
        }
        @rmdir(config('mealboard.backup_dir'));
    }

    /* ---- Settings: the rules a super admin sets ---- */

    public function test_turning_ramadan_on_cancels_future_lunches_and_tells_the_people(): void
    {
        $lunchOnly = $this->person();
        $lunchAndDinner = $this->person(['multiple_meals' => 1]);
        $a = $this->order($lunchOnly, '2026-11-06', ['lunch']);
        $b = $this->order($lunchAndDinner, '2026-11-06', ['lunch', 'dinner']);
        $this->actingAs($this->admin('super_admin'))->post('/settings/ramadan', ['on' => 1])->assertRedirect();
        $this->assertFalse(DB::table('oder')->where('id', $a)->exists(), 'a lunch-only booking disappears');
        $this->assertSame(['dinner'], $this->meals($lunchAndDinner, '2026-11-06'), 'only the lunch is taken off');
        $this->assertSame(1, DB::table('notifications')->where('title', 'Lunch is closed during Ramadan')->where('recipients', 'like', '%' . $lunchOnly->id . '%')->count());
        $this->assertSame(1, (int) DB::table('booking_rules')->value('ramadan_mode'));
    }

    public function test_rules_are_validated(): void
    {
        $super = $this->admin('super_admin');
        $good = ['maxNoShow' => 4, 'periodDays' => 20, 'deadlineHour' => 12, 'deadlineDaysBefore' => 1, 'sandwichDeadlineHour' => 12];
        foreach ([['maxNoShow' => 0], ['periodDays' => 0], ['deadlineHour' => 24], ['deadlineDaysBefore' => -1], ['sandwichDeadlineHour' => 30], ['maxNoShow' => 1.5], ['autoNoShow' => 1, 'autoDay' => 'soon']] as $bad) {
            $before = DB::table('booking_rules')->value('max_no_show');
            $this->actingAs($super)->post('/settings/rules', array_merge($good, $bad))->assertSessionHas('settings_status', fn ($s) => $s['ok'] === false);
            $this->assertEquals($before, DB::table('booking_rules')->value('max_no_show'), 'nothing saved: ' . json_encode($bad));
        }
        $this->actingAs($super)->post('/settings/rules', $good)->assertSessionHas('settings_status', fn ($s) => $s['ok'] === true);
        $this->assertSame(4, (int) DB::table('booking_rules')->value('max_no_show'));
        $this->assertSame(12, (int) DB::table('booking_rules')->value('cancel_deadline_hour'));
    }

    public function test_the_daily_list_settings(): void
    {
        $super = $this->admin('super_admin');
        $this->actingAs($super)->post('/settings/daily', ['recipients' => "A@x.com\nb@y.com", 'auto' => 1, 'hour' => 30, 'day' => 'today']);
        $s = Settings::get('daily_orders');
        $this->assertSame(['a@x.com', 'b@y.com'], $s['recipients']);
        $this->assertSame(23, $s['hour'], 'the hour is kept between 0 and 23');
        $this->assertSame('today', $s['day']);
        $this->actingAs($super)->post('/settings/daily', ['recipients' => 'broken']);
        $this->assertSame(['a@x.com', 'b@y.com'], Settings::get('daily_orders')['recipients'], 'a bad address changes nothing');
    }

    public function test_only_the_super_admin_changes_settings(): void
    {
        $before = DB::table('booking_rules')->value('max_no_show');
        $this->actingAs($this->admin('admin'))->post('/settings/rules', ['maxNoShow' => 9, 'periodDays' => 9, 'deadlineHour' => 9, 'deadlineDaysBefore' => 1, 'sandwichDeadlineHour' => 9])->assertForbidden();
        $this->actingAs($this->admin('manager'))->post('/settings/ramadan', ['on' => 1])->assertForbidden();
        $this->assertEquals($before, DB::table('booking_rules')->value('max_no_show'));
        $this->assertSame(0, (int) DB::table('booking_rules')->value('ramadan_mode'));
    }

    public function test_dashboard_numbers_follow_the_data(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Total users');
        $total = DB::table('user')->count();
        $this->actingAs($this->admin())->get('/admin')->assertSee((string) ($total + 1)); // +1: the admin just created
    }
}
