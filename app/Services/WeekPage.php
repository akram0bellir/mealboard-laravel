<?php

namespace App\Services;

use App\Models\User;
use App\Support\Days;
use Illuminate\Support\Facades\DB;

/** Everything the week page shows for one person: the 14 days, their menu, bookings and limits. */
class WeekPage
{
    public static function build(User $user): array
    {
        $rules = BookingRules::current();
        $now = Days::now();
        $plan = MealPlan::for($user, $rules);
        $standing = Standing::read($user, true, $rules);
        $days = $rules->upcomingDays($now);
        [$bookings] = Bookings::forUser($user->id, Days::key($days[0])); // only the two weeks shown, not the whole history
        $menu = self::menu(Days::key($days[0]), Days::key($days[13]));
        $mealTimes = MealTimes::for($user, $plan['meals'], $rules);

        // While paused for no-shows, days before blocked_until can't be booked.
        $blockedUntil = $standing['blocked_until'] > Days::today() ? $standing['blocked_until'] : '';

        $cards = [];
        foreach ($days as $day) {
            $key = Days::key($day);
            $booked = $bookings[$key] ?? [];
            $cards[] = [
                'key' => $key,
                'date' => $day,
                'status' => $rules->dayStatus($key, $now),
                'booked' => $booked,
                'menu' => $menu[$key] ?? [],
                'today' => $key === Days::today(),
                'outOfAccess' => $blockedUntil !== '' && $key < $blockedUntil,
                'full' => $plan['max'] > 1 && count($booked) >= $plan['max'],
                'hint' => $rules->deadlineHint($day, $now),
            ];
        }

        return [
            'rules' => $rules,
            'now' => $now,
            'plan' => $plan,
            'single' => $plan['max'] === 1,
            'cards' => $cards,
            'blockedUntil' => $blockedUntil,
            'mealTimes' => $mealTimes,
            'bookedCount' => count(array_filter($cards, fn ($c) => $c['booked'] !== [])),
            'openCount' => count(array_filter($cards, fn ($c) => $c['status'] === 'open')),
            'firstName' => explode(' ', $user->fullName())[0],
            'rangeLabel' => Days::short($days[0]) . ' – ' . Days::short($days[13]),
        ];
    }

    /**
     * The dishes served each day: date => meal => ['name', 'description'] (days without a dish are left out).
     *
     * @return array<string, array<string, array{name: string, description: string}>>
     */
    private static function menu(string $from, string $to): array
    {
        $fields = [
            'breakfast' => ['brakfast_title', 'brakfast_description'],
            'lunch' => ['lunch_title', 'lunch_description'],
            'dinner' => ['Dinner_title', 'Dinner_description'],
        ];
        $rows = DB::table('meun')
            ->where('day', '>=', $from . ' 00:00:00.000')
            ->where('day', '<=', $to . ' 23:59:59.999')
            ->get();

        $menu = [];
        foreach ($rows as $row) {
            $date = Days::keyOf($row->day);
            $dishes = [];
            foreach ($fields as $meal => [$title, $description]) {
                $name = trim((string) $row->{$title});
                if ($name !== '') {
                    $dishes[$meal] = ['name' => $name, 'description' => (string) $row->{$description}];
                }
            }
            $menu[$date] = $dishes;
        }
        return $menu;
    }
}
