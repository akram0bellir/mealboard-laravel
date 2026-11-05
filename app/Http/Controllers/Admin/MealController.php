<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin: the menu (one `meun` row per day with a dish per meal), meal templates (`food`) and special meals
 * (`special_meals`). Past days are read only. Same data and rules as the PHP API and the React Meals page.
 */
class MealController extends Controller
{
    /** Users can book anything inside this many days from today; slots further out are drafts. */
    public const WINDOW_DAYS = 15;

    public const SPECIAL_KINDS = [
        'enhanced' => 'Enhanced Meals',
        'vip' => 'VIP Meals',
        'special' => 'Special Meals',
        'snacks' => 'Snacks',
        'vip_snacks' => 'VIP Snacks',
    ];

    private const FIELDS = [
        'breakfast' => ['brakfast_title', 'brakfast_description'],
        'lunch' => ['lunch_title', 'lunch_description'],
        'dinner' => ['Dinner_title', 'Dinner_description'],
    ];

    private const BOOKED = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function index(Request $request): View
    {
        $today = Days::today();
        $currentWeek = Days::key(Days::mondayOf(Days::now()));
        $week = $this->weekOf((string) $request->query('week', $currentWeek));
        $days = array_map(fn (int $i) => Days::add($week, $i), range(0, 6));

        $menu = $this->menu($days[0], $days[6]);
        $bookings = $this->bookings($days[0], $days[6]);

        $windowDays = array_map(fn (int $i) => Days::add($today, $i), range(0, self::WINDOW_DAYS - 1));
        $windowMenu = $this->menu($windowDays[0], end($windowDays));
        $filled = 0;
        foreach ($windowDays as $d) {
            $filled += count($windowMenu[$d] ?? []);
        }

        $templates = DB::table('food')->orderBy('title')->get();
        $armed = $templates->firstWhere('id', (string) $request->query('arm', ''));

        $dialog = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2}):(breakfast|lunch|dinner)$/', (string) $request->query('slot', ''), $m) && $m[1] >= $today) {
            $dish = $menu[$m[1]][$m[2]] ?? $this->menu($m[1], $m[1])[$m[1]][$m[2]] ?? null;
            $use = $templates->firstWhere('id', (string) $request->query('use', ''));
            $dialog = [
                'date' => $m[1],
                'meal' => $m[2],
                'dish' => $dish,
                'bookings' => $this->bookings($m[1], $m[1])[$m[1]][$m[2]] ?? 0,
                'use' => $use,
            ];
        }

        $specials = DB::table('special_meals')
            ->where('Day', '>=', "{$days[0]} 00:00:00.000")->where('Day', '<=', "{$days[6]} 23:59:59.999")
            ->orderBy('Day')->orderBy('Meal')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'date' => Days::keyOf($s->Day),
                'meal' => strtolower((string) $s->Meal) === 'dinner' ? 'dinner' : 'lunch',
                'kind' => array_search($s->type, self::SPECIAL_KINDS, true) ?: 'enhanced',
                'count' => (int) $s->How_many,
                'note' => (string) $s->Note,
            ])->all();

        return view('admin.meals', [
            'rules' => BookingRules::current(),
            'today' => $today,
            'currentWeek' => $currentWeek,
            'week' => $week,
            'days' => $days,
            'menu' => $menu,
            'bookings' => $bookings,
            'weekOffset' => (int) round((Days::fromKey($week)->diffInDays(Days::fromKey($currentWeek), false) / -7)),
            'windowEnd' => Days::add($today, self::WINDOW_DAYS - 1),
            'windowFilled' => $filled,
            'templates' => $templates,
            'armed' => $armed,
            'dialog' => $dialog,
            'specials' => $specials,
            'specialKinds' => self::SPECIAL_KINDS,
            'editSpecial' => collect($specials)->firstWhere('id', (string) $request->query('special', '')),
            'status' => session('meals_status'),
        ]);
    }

    /** Saves one slot (meal name + description); optionally keeps it as a template too. */
    public function saveSlot(Request $request): RedirectResponse
    {
        [$date, $meal] = $this->slotOf($request);
        $name = trim((string) $request->input('name'));
        $week = $this->weekOf($date);
        if ($name === '') {
            return redirect("/meals?week={$week}&slot={$date}:{$meal}");
        }

        $this->setDish($date, $meal, $name, trim((string) $request->input('description')));
        if ($request->boolean('template')) {
            $now = Ids::now();
            DB::table('food')->insert([
                'id' => Ids::new(), 'title' => $name, 'description' => trim((string) $request->input('description')),
                'type' => $this->kindOf($meal), 'created' => $now, 'updated' => $now,
            ]);
        }
        return redirect("/meals?week={$week}")->with('meals_status', 'Saved.');
    }

    public function removeSlot(Request $request): RedirectResponse
    {
        [$date, $meal] = $this->slotOf($request);
        $this->setDish($date, $meal, '', '');
        return redirect('/meals?week=' . $this->weekOf($date))->with('meals_status', 'Meal removed.');
    }

    /** A template is armed: clicking a slot puts it there. */
    public function place(Request $request): RedirectResponse
    {
        [$date, $meal] = $this->slotOf($request);
        $template = DB::table('food')->where('id', (string) $request->input('template'))->first();
        $week = $this->weekOf($date);
        abort_if($template === null, 404);

        if ($template->type !== $this->kindOf($meal)) {
            $text = "\"{$template->title}\" is a " . strtolower($template->type) . " template, so it can't go in " . $meal . '.';
            return redirect("/meals?week={$week}&arm={$template->id}")->with('meals_status', $text);
        }
        $this->setDish($date, $meal, (string) $template->title, (string) $template->description);
        return redirect("/meals?week={$week}&arm={$template->id}")->with('meals_status', "Placed \"{$template->title}\" in {$meal}.");
    }

    /** Copies the menu of a week to the following week(s). Past days and existing bookings are never touched. */
    public function copy(Request $request): RedirectResponse
    {
        $from = $this->weekOf((string) $request->input('week'));
        $count = max(1, min(6, (int) $request->input('count', 1)));
        $copied = 0;
        for ($w = 1; $w <= $count; $w++) {
            $copied += $this->copyWeek($from, Days::add($from, $w * 7));
        }
        $text = $count === 1
            ? "Copied {$copied} meals to the next week."
            : "Applied this week's menu to the next {$count} weeks ({$copied} meals).";
        return redirect("/meals?week={$from}")->with('meals_status', $text);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $name = trim((string) $request->input('name'));
        if ($name !== '') {
            $kind = in_array($request->input('type'), ['Breakfast', 'Lunch/dinner'], true) ? $request->input('type') : 'Lunch/dinner';
            $now = Ids::now();
            DB::table('food')->insert([
                'id' => Ids::new(), 'title' => $name, 'description' => trim((string) $request->input('description')),
                'type' => $kind, 'created' => $now, 'updated' => $now,
            ]);
        }
        return redirect('/meals?week=' . $this->weekOf((string) $request->input('week')) . '#templates');
    }

    public function destroyTemplate(Request $request, string $id): RedirectResponse
    {
        DB::table('food')->where('id', $id)->delete();
        return redirect('/meals?week=' . $this->weekOf((string) $request->input('week')) . '#templates');
    }

    public function saveSpecial(Request $request, ?string $id = null): RedirectResponse
    {
        $week = $this->weekOf((string) $request->input('week'));
        $date = (string) $request->input('date');
        $kind = (string) $request->input('kind');
        $meal = $request->input('meal') === 'dinner' ? 'dinner' : 'lunch';
        $count = (int) $request->input('count');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !isset(self::SPECIAL_KINDS[$kind]) || $count < 1) {
            return redirect("/meals?week={$week}#special")->with('meals_status', "Couldn't add the special meal. Please try again.");
        }

        $data = [
            'type' => self::SPECIAL_KINDS[$kind], 'Day' => Days::toDb($date), 'Meal' => $meal,
            'How_many' => $count, 'Note' => trim((string) $request->input('note')), 'updated' => Ids::now(),
        ];
        if ($id !== null) {
            DB::table('special_meals')->where('id', $id)->update($data);
        } else {
            DB::table('special_meals')->insert($data + ['id' => Ids::new(), 'created' => Ids::now()]);
        }
        return redirect("/meals?week={$week}#special");
    }

    public function destroySpecial(Request $request, string $id): RedirectResponse
    {
        DB::table('special_meals')->where('id', $id)->delete();
        return redirect('/meals?week=' . $this->weekOf((string) $request->input('week')) . '#special');
    }

    /* ------------------------------------------------------------------ */

    /** The Monday of the week that holds a day key (today's week when the key is not a date). */
    private function weekOf(string $key): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) ? Days::fromKey($key) : Days::now();
        return Days::key(Days::mondayOf($date));
    }

    /** @return array{0: string, 1: string} date and meal of the posted slot; past days are refused. */
    private function slotOf(Request $request): array
    {
        $date = (string) $request->input('date');
        $meal = (string) $request->input('meal');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && isset(self::FIELDS[$meal]), 422);
        abort_if($date < Days::today(), 403, 'Past days are read-only.');
        return [$date, $meal];
    }

    private function kindOf(string $meal): string
    {
        return $meal === 'breakfast' ? 'Breakfast' : 'Lunch/dinner';
    }

    /** The dishes from $from to $to: date => meal => ['name', 'description']. */
    private function menu(string $from, string $to): array
    {
        $out = [];
        foreach (DB::table('meun')->where('day', '>=', "$from 00:00:00.000")->where('day', '<=', "$to 23:59:59.999")->get() as $row) {
            foreach (self::FIELDS as $meal => [$title, $description]) {
                if (trim((string) $row->{$title}) !== '') {
                    $out[Days::keyOf($row->day)][$meal] = ['name' => trim((string) $row->{$title}), 'description' => (string) $row->{$description}];
                }
            }
        }
        return $out;
    }

    /** Booked meals per day: date => meal => count (missed ones count, like the order list). */
    private function bookings(string $from, string $to): array
    {
        $rows = DB::table('oder')
            ->where('date', '>=', "$from 00:00:00.000")->where('date', '<=', "$to 23:59:59.999")
            ->selectRaw('LEFT(`date`, 10) d, SUM(bool) breakfast, SUM(bool2) lunch, SUM(bool3) dinner')
            ->groupBy('d')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->d] = ['breakfast' => (int) $r->breakfast, 'lunch' => (int) $r->lunch, 'dinner' => (int) $r->dinner];
        }
        return $out;
    }

    /** Sets (or, with an empty name, clears) one dish in the day's menu row. */
    private function setDish(string $day, string $meal, string $name, string $description): void
    {
        [$title, $desc] = self::FIELDS[$meal];
        $row = DB::table('meun')->where('day', '>=', "$day 00:00:00.000")->where('day', '<=', "$day 23:59:59.999")->orderBy('created')->first();
        $now = Ids::now();

        if ($row === null) {
            if ($name === '') {
                return;
            }
            $blank = ['brakfast_title' => '', 'brakfast_description' => '', 'lunch_title' => '', 'lunch_description' => '', 'Dinner_title' => '', 'Dinner_description' => ''];
            DB::table('meun')->insert(array_merge($blank, [$title => $name, $desc => $description, 'id' => Ids::new(), 'day' => Days::toDb($day), 'created' => $now, 'updated' => $now]));
            return;
        }

        DB::table('meun')->where('id', $row->id)->update([$title => $name, $desc => $description, 'updated' => $now]);
        $left = DB::table('meun')->where('id', $row->id)->first();
        if (collect(self::FIELDS)->every(fn ($f) => trim((string) $left->{$f[0]}) === '')) {
            DB::table('meun')->where('id', $row->id)->delete(); // an empty day is no day
        }
    }

    /** Copies every dish of one week onto another, skipping past target days. Returns how many dishes were copied. */
    private function copyWeek(string $from, string $to): int
    {
        $source = $this->menu($from, Days::add($from, 6));
        $copied = 0;
        for ($i = 0; $i < 7; $i++) {
            $target = Days::add($to, $i);
            if ($target < Days::today()) {
                continue;
            }
            foreach ($source[Days::add($from, $i)] ?? [] as $meal => $dish) {
                $this->setDish($target, $meal, $dish['name'], $dish['description']);
                $copied++;
            }
        }
        return $copied;
    }
}
