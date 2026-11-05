<?php

namespace App\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** A notification in the app, plus one queued e-mail per active recipient with an address. */
class Notifier
{
    public static function send(array $recipients, string $title, string $message, string $audience, ?string $sentBy = null): string
    {
        $recipients = array_values(array_unique(array_filter(array_map('strval', $recipients))));
        if ($recipients === []) {
            return '';
        }

        $id = Ids::new();
        $now = Ids::now();
        DB::table('notifications')->insert([
            'id' => $id,
            'title' => $title,
            'message' => $message,
            'audience' => $audience,
            'recipients' => json_encode($recipients),
            'read_by' => json_encode([]),
            'sent_by' => $sentBy,
            'created' => $now,
            'updated' => $now,
        ]);

        self::queueEmails($title, $message, $recipients);
        // The reply goes out first, then the waiting e-mails are sent (the cron sends what is left).
        if (Mailer::isOn()) {
            dispatch(fn () => Mailer::flushQueue(10))->afterResponse();
        }
        return $id;
    }

    private static function queueEmails(string $title, string $message, array $recipients): void
    {
        $subject = trim((string) preg_replace('/\s+/', ' ', $title));
        $users = DB::table('user')->whereIn('id', $recipients)->get(['id', 'email', 'firstname', 'Status']);

        foreach ($users as $user) {
            $email = trim((string) $user->email);
            if ($email === '' || (int) $user->Status !== 1) {
                continue;
            }
            $body = sprintf(
                "Hello %s,\n\n%s\n\n--\nMealboard\nThis message was also sent to your notifications in the app.",
                $user->firstname !== '' ? $user->firstname : 'there',
                $message
            );
            DB::table('pending_emails')->insert([
                'id' => Ids::new(),
                'to_address' => $email,
                'subject' => mb_substr($subject, 0, 255),
                'body' => $body,
                'created' => Ids::now(),
                'attempts' => 0,
                'sent_at' => null,
                'error' => '',
            ]);
        }
    }
}
