<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\Bookings;
use App\Services\Files;
use App\Services\Standing;
use App\Services\GateRelay;
use App\Services\MealTimes;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The canteen door: scan a badge (phone NFC) or find a person, see ALLOWED / NOT ALLOWED for the meal being
 * served, and mark it served (oder.attended_<meal>). A badge belongs to the person whose card_id is its ZKTeco
 * number. Same rules as the React serve page (lib/serve-api.ts).
 *
 * Every action answers with the whole page, or only the result card when the page's script asks (?partial=1),
 * so NFC scanning keeps running between people.
 */
class ServeController extends Controller
{
    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    /** Meal ends (minutes after midnight) used to pick the meal being served now. */
    private const ENDS = ['breakfast' => 570, 'lunch' => 840, 'dinner' => 1230];

    public function show(Request $request): View
    {
        return $this->page($request, null);
    }

    /** A badge was read: its chip id (e.g. "B4:41:1C:8F"). */
    public function badge(Request $request): View
    {
        $uid = strtoupper((string) preg_replace('/[^0-9a-f]/i', '', (string) $request->input('uid')));
        $number = self::cardNumber($uid);
        $person = $number === null ? null : User::where('card_id', $number)->orderBy('firstname')->first();
        if ($person === null) {
            return $this->page($request, ['kind' => 'unknown', 'uid' => $uid, 'cardNumber' => $number]);
        }
        return $this->page($request, $this->check($person, $this->meal($request)) + ['cardNumber' => $number]);
    }

    /** A person picked by hand (search result). */
    public function person(Request $request): View
    {
        $person = User::find((string) $request->input('user'));
        if ($person === null) {
            return $this->page($request, ['kind' => 'error', 'message' => t("Couldn't check this person. Try again.")]);
        }
        return $this->page($request, $this->check($person, $this->meal($request)));
    }

    /** An unknown badge is given to a person (saved as their card_id), then they are checked. */
    public function link(Request $request): View
    {
        $person = User::find((string) $request->input('user'));
        $number = (int) $request->input('cardNumber');
        if ($person === null || $number <= 0) {
            return $this->page($request, ['kind' => 'error', 'message' => t("Couldn't save the card number to this person. Try again.")]);
        }
        DB::table('user')->where('id', $person->id)->update(['card_id' => $number, 'updated' => Ids::now()]);
        return $this->page($request, $this->check($person->fresh(), $this->meal($request)) + ['cardNumber' => $number]);
    }

    /**
     * Admin override at the door: this person eats whatever the rules say (no booking, paused, account off, late
     * cancel, already served). The meal is booked if needed, a "missed" mark is cleared (its strike given back),
     * it is marked served and the gate opens.
     */
    public function force(Request $request): View
    {
        $person = User::find((string) $request->input('user'));
        if ($person === null) {
            return $this->page($request, ['kind' => 'error', 'message' => t("Couldn't check this person. Try again.")]);
        }
        $meal = $this->meal($request);
        $day = Days::today();
        Bookings::adminAdd($person->id, $day, [$meal]);
        $order = DB::table('oder')->where('user', $person->id)->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")->first();
        $again = (int) $order->{'attended_' . $meal} === 1;
        if ((int) $order->{'no_show_' . $meal} === 1) {
            Standing::takeBackStrike($person->id);
        }
        DB::table('oder')->where('id', $order->id)->update(['attended_' . $meal => 1, 'no_show_' . $meal => 0, 'updated' => Ids::now()]);

        $gate = GateRelay::open();
        return $this->page($request, [
            'kind' => 'result', 'person' => $this->personView($person), 'allowed' => true, 'served' => true, 'reason' => t('Booked'),
            'note' => $again ? t('Let through again by an admin') : t('Served by an admin (override)'),
            'warning' => $gate['ok'] ? null : t('Meal accepted, but the gate did not open: {error}', ['error' => t($gate['error'])]),
        ]);
    }

    /** "Find relay": try the open message on one COM port. */
    public function relayTest(Request $request): RedirectResponse
    {
        $port = (string) $request->input('port');
        $result = GateRelay::pulse($port, $this->relayDraft($request));
        $draft = GateRelay::config($this->relayDraft($request));
        if (!$result['ok']) {
            $note = [false, t('Failed: {error}', ['error' => $result['error']]), null];
        } else {
            $note = [true, $draft['message'] === 'text'
                ? t('Sent "{text}" to {port}. Did the gate open?', ['text' => $draft['text'], 'port' => $port])
                : t('Pulse sent on channel {relay}. Did the gate click?', ['relay' => $draft['relay']]), $result['reply']];
        }
        return $this->relayBack($request)->with('relay_note', $note);
    }

    /** "Find relay": this port (and message) is the gate from now on. */
    public function relayChoose(Request $request): RedirectResponse
    {
        $saved = GateRelay::save(['port' => (string) $request->input('port')] + $this->relayDraft($request));
        $what = $saved['message'] === 'text' ? '"' . $saved['text'] . '"' : t('relay bytes, channel {relay}', ['relay' => $saved['relay']]);
        return $this->relayBack($request)->with('relay_note', [true, t('Saved. The gate now uses {name}: {what}.', ['name' => $saved['port'], 'what' => $what]), null]);
    }

    private function relayDraft(Request $request): array
    {
        $ending = ['n' => "\n", 'rn' => "\r\n"][(string) $request->input('ending')] ?? '';
        return ['relay' => (int) $request->input('relay', 1), 'message' => $request->input('message'), 'text' => (string) $request->input('text', '1'), 'ending' => $ending];
    }

    private function relayBack(Request $request): RedirectResponse
    {
        $meal = (string) $request->input('meal');
        return redirect('/serve?relay=1' . (isset(self::COLUMN[$meal]) ? "&meal={$meal}" : ''))->withInput($request->only(['message', 'text', 'ending', 'relay']));
    }

    /* ------------------------------------------------------------------ */

    /** "B4411C8F" -> 8F1C41B4 -> 2400993716: the 4-byte chip id read last byte first, as one number. */
    public static function cardNumber(string $hex): ?int
    {
        return strlen($hex) === 8 ? (int) hexdec(implode('', array_reverse(str_split($hex, 2)))) : null;
    }

    private function meal(Request $request): string
    {
        $rules = BookingRules::current();
        $asked = (string) $request->input('meal', $request->query('meal', ''));
        if (isset(self::COLUMN[$asked]) && !$rules->mealClosed($asked)) {
            return $asked;
        }
        // The meal being served now: the first that hasn't ended more than an hour ago.
        $now = Days::now();
        $minutes = $now->hour * 60 + $now->minute;
        $open = array_values(array_filter(array_keys(self::COLUMN), fn ($m) => !$rules->mealClosed($m)));
        foreach ($open as $m) {
            if ($minutes <= self::ENDS[$m] + 60) {
                return $m;
            }
        }
        return end($open);
    }

    /** May this person eat $meal today? An allowed person is marked served straight away. */
    private function check(User $person, string $meal): array
    {
        $rules = BookingRules::current();
        $base = ['kind' => 'result', 'person' => $this->personView($person), 'served' => false];
        if (!$person->isActive()) {
            return $base + ['allowed' => false, 'reason' => t('Account is switched off')];
        }
        if ($rules->mealClosed($meal)) {
            return $base + ['allowed' => false, 'reason' => t('Lunch is closed during Ramadan')];
        }
        $day = Days::today();
        $order = DB::table('oder')->where('user', $person->id)->where(self::COLUMN[$meal], 1)
            ->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")->first();
        if ($order === null) {
            return $base + ['allowed' => false, 'reason' => t('No {meal} booking today', ['meal' => mb_strtolower($rules->mealLabel($meal))])];
        }
        if ((int) $order->{'no_show_' . $meal} === 1) {
            return $base + ['allowed' => false, 'reason' => t('Booking was cancelled late (marked no-show)')];
        }
        if ((int) $order->{'attended_' . $meal} === 1) {
            return $base + ['allowed' => false, 'reason' => t('Already served')];
        }

        // Group time: shown, with a note when they come outside it (they are still served).
        $group = MealTimes::resolve($order->day_group, $person, $meal, MealTimes::departmentRow((string) $person->Department), MealTimes::groups());
        $time = $group ? MealTimes::groupTime($group, $rules) : '';
        $note = null;
        if ($group && $time !== '') {
            [$start, $end] = $rules->ramadan ? [$group->ramadan_start, $group->ramadan_end] : [$group->start, $group->end];
            $now = Days::now()->format('H:i');
            if ($now < $start) {
                $note = t('Early — their time is {time}', ['time' => $time]);
            } elseif ($now > $end) {
                $note = t('Late — their time was {time}', ['time' => $time]);
            }
        }

        DB::table('oder')->where('id', $order->id)->update(['attended_' . $meal => 1, 'updated' => Ids::now()]);

        // Open the tripod gate (the relay on this PC). A failure does not undo the meal.
        $gate = GateRelay::open();
        $warning = $gate['ok'] ? null : t('Meal accepted, but the gate did not open: {error}', ['error' => t($gate['error'])]);

        return array_merge($base, ['allowed' => true, 'served' => true, 'reason' => t('Booked'), 'groupTime' => $time, 'note' => $note, 'warning' => $warning]);
    }

    private function personView(User $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->fullName(),
            'department' => (string) $p->Department,
            'picture' => $p->picter ? Files::url('user', $p->id, $p->picter, '200x200') : null,
            'cardId' => $p->card_id ? (int) $p->card_id : null,
        ];
    }

    private function page(Request $request, ?array $result): View
    {
        $rules = BookingRules::current();
        $meal = $this->meal($request);
        $day = Days::today();
        $counts = DB::table('oder')->where(self::COLUMN[$meal], 1)->where('date', '>=', "$day 00:00:00.000")->where('date', '<=', "$day 23:59:59.999")
            ->selectRaw('COUNT(*) booked, COALESCE(SUM(attended_' . $meal . '), 0) served')->first();

        $data = [
            'rules' => $rules,
            'meal' => $meal,
            'mealLabel' => $rules->mealLabel($meal),
            'booked' => (int) $counts->booked,
            'served' => (int) $counts->served,
            'result' => $result,
        ];
        if ($request->boolean('partial')) {
            return view('admin.serve-result', $data);
        }

        // Search: a card number typed by hand, or a name / e-mail.
        $q = trim((string) $request->query('q', ''));
        $matches = null;
        if ($q !== '') {
            $matches = ctype_digit($q)
                ? User::where('card_id', (int) $q)->get()
                : User::where(fn ($w) => $w->where('firstname', 'like', "%{$q}%")->orWhere('lastname', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%"))
                    ->orderBy('firstname')->limit(8)->get();
            $matches = $matches->map(fn ($u) => $this->personView($u))->all();
        }
        // "Find relay" dialog: the COM ports of this PC, the message being tried, the answer of the last test.
        $relay = null;
        if ($request->boolean('relay')) {
            $config = GateRelay::config();
            $error = null;
            try {
                $devices = GateRelay::ports($config);
            } catch (\Throwable $e) {
                $devices = [];
                $error = t("Couldn't list COM ports: {error}", ['error' => t($e->getMessage())]);
            }
            $draft = $config;
            if ($request->old('message') !== null) {
                $draft = GateRelay::config([
                    'relay' => $request->old('relay'), 'message' => $request->old('message'), 'text' => $request->old('text'),
                    'ending' => ['n' => "\n", 'rn' => "\r\n"][(string) $request->old('ending')] ?? '',
                ]);
            }
            $relay = ['devices' => $devices, 'config' => $config, 'draft' => $draft, 'error' => $error, 'note' => session('relay_note')];
        }
        return view('admin.serve', $data + ['q' => $q, 'matches' => $matches, 'linkNumber' => $request->query('link'), 'relay' => $relay]);
    }
}
