<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\Notifier;
use App\Support\Days;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Admin: send a notification to everyone, a department, the people booked on a meal, or one person. */
class NotifyController extends Controller
{
    private const DEPARTMENTS = ['1', '2', '3'];

    public function index(Request $request): View
    {
        $sent = DB::table('notifications')->orderByDesc('created')->limit(100)->get()->map(fn ($n) => [
            'title' => (string) $n->title,
            'message' => (string) $n->message,
            'audience' => (string) $n->audience,
            'recipients' => count((array) json_decode((string) $n->recipients, true)),
            'sentAt' => CarbonImmutable::parse($n->created . ' UTC')->setTimezone(config('app.timezone')),
        ])->all();

        // "One user": the search narrows the list.
        $q = trim((string) $request->query('q', ''));
        $words = array_filter(preg_split('/\s+/', self::normalize($q)));
        $users = DB::table('user')->where('Status', 1)->orderBy('firstname')->orderBy('lastname')->get()
            ->filter(fn ($u) => !$words || collect($words)->every(fn ($w) => str_contains(
                self::normalize(implode(' ', [$u->firstname, $u->lastname, $u->email, $u->Department, $u->Position, $u->id_user, $u->card_id])),
                $w
            )))
            ->values();

        return view('admin.notify', [
            'sent' => $sent,
            'users' => $users,
            'q' => $q,
            'departments' => self::DEPARTMENTS,
            'rules' => BookingRules::current(),
            'today' => Days::today(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $title = trim((string) $request->input('title'));
        $message = trim((string) $request->input('message'));
        $audience = (string) $request->input('audience', 'everyone');
        $keep = $request->except('_token');
        $fail = fn (string $text) => redirect('/admain_notifications')->withInput($keep)->with('notify_status', $text);

        if ($title === '' || $message === '') {
            return $fail("Couldn't send the notification. Please try again.");
        }

        $active = DB::table('user')->where('Status', 1);
        switch ($audience) {
            case 'department':
                $department = (string) $request->input('department');
                $ids = (clone $active)->where('Department', $department)->pluck('id')->all();
                $label = "Department {$department}";
                break;
            case 'meal':
                $date = (string) $request->input('meal_date');
                $meal = (string) $request->input('meal');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($meal, BookingRules::MEALS, true)) {
                    return $fail("Couldn't send the notification. Please try again.");
                }
                $column = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'][$meal];
                $ids = DB::table('oder')->where($column, 1)->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")
                    ->distinct()->pluck('user')->filter()->all();
                $label = ucfirst($meal) . ' · ' . Days::fromKey($date)->locale('en')->translatedFormat('j M') . ' bookings';
                break;
            case 'user':
                $person = DB::table('user')->where('id', (string) $request->input('user'))->first();
                $ids = $person ? [$person->id] : [];
                $label = $person ? trim($person->firstname . ' ' . $person->lastname) : 'No user';
                break;
            default:
                $ids = (clone $active)->pluck('id')->all();
                $label = 'Everyone';
        }

        if ($ids === []) {
            return $fail('Nobody matches this audience, so nothing was sent.');
        }

        Notifier::send($ids, mb_substr($title, 0, 60), mb_substr($message, 0, 240), $label, $request->user()->id);
        return redirect('/admain_notifications')->with('notify_status', count($ids) === 1 ? 'Sent to 1 person.' : 'Sent to ' . count($ids) . ' people.');
    }

    /** Lower case without accents, so "helene" finds "Hélène". */
    private static function normalize(string $text): string
    {
        $plain = \Normalizer::normalize($text, \Normalizer::FORM_D);
        return mb_strtolower((string) preg_replace('/\p{Mn}/u', '', $plain === false ? $text : $plain));
    }
}
