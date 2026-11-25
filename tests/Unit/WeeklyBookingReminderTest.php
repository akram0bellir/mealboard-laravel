<?php

namespace Tests\Unit;

use App\Services\WeeklyBookingReminder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WeeklyBookingReminderTest extends TestCase
{
    public function test_it_queues_one_arabic_reminder_for_each_active_employee_address_only_once(): void
    {
        $this->at('2026-11-07 09:00');
        $employee = $this->person(['email' => 'weekly.employee@example.test']);
        $manager = $this->person(['email' => 'weekly.manager@example.test', 'User_Type' => 'manager']);
        $this->person(['email' => 'weekly.inactive@example.test', 'Status' => 0]);
        $this->person(['email' => 'weekly.admin@example.test', 'User_Type' => 'admin']);
        $this->person(['email' => 'weekly.door@example.test', 'User_Type' => 'door']);
        $this->person(['email' => 'not-an-email']);

        $result = WeeklyBookingReminder::run();

        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, DB::table('pending_emails')->where('to_address', $employee->email)->where('subject', 'تذكير بحجز وجبات الأسبوع القادم')->count());
        $this->assertSame(1, DB::table('pending_emails')->where('to_address', $manager->email)->where('subject', 'تذكير بحجز وجبات الأسبوع القادم')->count());
        $this->assertSame(0, DB::table('pending_emails')->whereIn('to_address', [
            'weekly.inactive@example.test',
            'weekly.admin@example.test',
            'weekly.door@example.test',
            'not-an-email',
        ])->where('subject', 'تذكير بحجز وجبات الأسبوع القادم')->count());
        $this->assertStringContainsString('نذكّرك بحجز وجباتك للأسبوع القادم', DB::table('pending_emails')->where('to_address', $employee->email)->value('body'));

        $again = WeeklyBookingReminder::run();

        $this->assertSame('already_queued', $again['status']);
        $this->assertSame(1, DB::table('pending_emails')->where('to_address', $employee->email)->where('subject', 'تذكير بحجز وجبات الأسبوع القادم')->count());
    }

    public function test_it_skips_other_days_and_dry_run_does_not_queue(): void
    {
        $employee = $this->person(['email' => 'weekly.preview@example.test']);

        $this->assertSame('skipped', WeeklyBookingReminder::run()['status']);
        $this->assertSame(0, DB::table('pending_emails')->where('to_address', $employee->email)->count());

        $this->at('2026-11-07 09:00');
        $preview = WeeklyBookingReminder::run(dryRun: true);

        $this->assertSame('preview', $preview['status']);
        $this->assertGreaterThanOrEqual(1, $preview['recipients']);
        $this->assertSame(0, DB::table('pending_emails')->where('to_address', $employee->email)->count());
    }
}
