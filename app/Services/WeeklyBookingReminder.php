<?php

namespace App\Services;

use App\Support\Days;
use App\Support\Ids;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;

class WeeklyBookingReminder
{
    private const MARKER_KEY = 'weekly_booking_reminder';
    private const SUBJECT = 'تذكير بحجز وجبات الأسبوع القادم';
    private const BODY = "مرحبًا،\n\nنذكّرك بحجز وجباتك للأسبوع القادم عبر تطبيق Mealboard. يُرجى إتمام الحجز قبل الموعد النهائي الموضّح في التطبيق.\n\nنتمنى لك أسبوعًا موفقًا.\n\nفريق Mealboard";

    /** Queue one reminder per unique address on Saturday. */
    public static function run(bool $dryRun = false): array
    {
        $today = Days::today();
        if (!Days::now()->isSaturday()) {
            return ['status' => 'skipped', 'date' => $today, 'recipients' => 0];
        }

        $recipients = self::recipients();
        if ($dryRun) {
            return ['status' => 'preview', 'date' => $today, 'recipients' => count($recipients)];
        }

        return DB::transaction(function () use ($today, $recipients): array {
            DB::table('_mealboard_settings')->insertOrIgnore([
                'key' => self::MARKER_KEY,
                'value' => '{}',
            ]);

            $row = DB::table('_mealboard_settings')
                ->where('key', self::MARKER_KEY)
                ->lockForUpdate()
                ->first();
            $state = json_decode((string) $row->value, true);
            if (($state['last_queued_on'] ?? null) === $today) {
                return ['status' => 'already_queued', 'date' => $today, 'recipients' => 0];
            }

            foreach ($recipients as $email) {
                DB::table('pending_emails')->insert([
                    'id' => Ids::new(),
                    'to_address' => $email,
                    'subject' => self::SUBJECT,
                    'body' => self::BODY,
                    'created' => Ids::now(),
                    'attempts' => 0,
                    'sent_at' => null,
                    'error' => '',
                ]);
            }

            DB::table('_mealboard_settings')
                ->where('key', self::MARKER_KEY)
                ->update(['value' => json_encode(['last_queued_on' => $today])]);

            return ['status' => 'queued', 'date' => $today, 'recipients' => count($recipients)];
        });
    }

    /** One valid address per active employee, excluding administrator and door accounts. */
    private static function recipients(): array
    {
        $emails = [];
        foreach (DB::table('user')->where('Status', 1)->get(['email', 'User_Type']) as $user) {
            if (in_array(Roles::normalize((string) $user->User_Type), ['admin', 'super_admin', 'door'], true)) {
                continue;
            }

            $email = mb_strtolower(trim((string) $user->email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }
}
