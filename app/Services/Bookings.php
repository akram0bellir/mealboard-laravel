<?php

namespace App\Services;

use App\Models\User;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * A person's bookings (the `oder` table: one row per person per day, a flag per meal)
 * and the rules for changing them. Same rules as the React store and the PHP API's guards.
 */
class Bookings
{
    private const COLUMN = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    /**
     * Active bookings, the row id behind each day, and the days frozen by a late cancellation.
     *
     * @return array{0: array<string, string[]>, 1: array<string, string>, 2: array<string, true>}
     */
    public static function forUser(string $userId): array
    {
        $bookings = [];
        $ids = [];
        $frozen = [];
        foreach (DB::table('oder')->where('user', $userId)->orderBy('date')->get() as $row) {
            $date = Days::keyOf($row->date);
            if ($date === '') {
                continue;
            }
            $ids[$date] = $row->id;
            $missed = [];
            $active = [];
            foreach (self::COLUMN as $meal => $column) {
                if ((int) $row->{$column} !== 1) {
                    continue;
                }
                if ((int) $row->{'no_show_' . $meal} === 1) {
                    $missed[] = $meal;
                } else {
                    $active[] = $meal;
                }
            }
            if ($missed !== []) {
                $frozen[$date] = true;
            }
            if ($active !== []) {
                $bookings[$date] = $active;
            }
        }
        return [$bookings, $ids, $frozen];
    }

    /**
     * Applies several changes at once: ['2026-11-06' => ['book' => ['lunch'], 'cancel' => []], ...].
     * Every change is checked again here, whatever the page sent.
     *
     * @return array{done: string[], skipped: array<int, array{date: string, meal: ?string, reason: string}>}
     */
    public static function apply(User $user, array $changes): array
    {
        $rules = BookingRules::current();
        $plan = MealPlan::for($user, $rules);
        $standing = Standing::read($user, true, $rules);
        [$bookings, $ids, $frozen] = self::forUser($user->id);
        $single = $plan['max'] === 1;

        $done = [];
        $skipped = [];
        ksort($changes);
        foreach ($changes as $date => $change) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                continue;
            }
            if ($rules->dayStatus($date) !== 'open') {
                $skipped[] = ['date' => $date, 'meal' => null, 'reason' => $rules->lockedReason()];
                continue;
            }
            if (isset($frozen[$date])) {
                $skipped[] = ['date' => $date, 'meal' => null, 'reason' => t('This booking was cancelled late and can no longer be changed.')];
                continue;
            }

            $before = $bookings[$date] ?? [];
            $cancel = array_values(array_intersect(BookingRules::MEALS, (array) ($change['cancel'] ?? [])));
            $meals = array_values(array_diff($before, $cancel));
            foreach (array_values(array_intersect(BookingRules::MEALS, (array) ($change['book'] ?? []))) as $meal) {
                $blocked = self::block($plan, $standing, $date, $meal, $single ? [] : $meals, $rules);
                if ($blocked !== null) {
                    $skipped[] = ['date' => $date, 'meal' => $meal, 'reason' => $blocked];
                    continue;
                }
                $meals = $single ? [$meal] : (in_array($meal, $meals, true) ? $meals : [...$meals, $meal]);
            }

            $meals = array_values(array_intersect(BookingRules::MEALS, $meals));
            if ($meals !== array_values(array_intersect(BookingRules::MEALS, $before))) {
                self::save($user->id, $date, $meals, $ids[$date] ?? null);
                $done[] = $date;
            }
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Cancels some meals of a day (all of them when $meals is null). A locked day can only be cancelled
     * late, when the rules allow it: the order stays for the kitchen and counts as a no-show.
     *
     * @return array{ok: bool, reason?: string}
     */
    public static function cancelDay(User $user, string $date, ?array $meals): array
    {
        $rules = BookingRules::current();
        [$bookings, $ids, $frozen] = self::forUser($user->id);
        $current = $bookings[$date] ?? [];
        $status = $rules->dayStatus($date);

        if ($status === 'locked' && $rules->lateCancelCounts && $current !== [] && isset($ids[$date])) {
            self::lateCancel($user, $ids[$date], $date);
            return ['ok' => true];
        }
        if ($status !== 'open') {
            return ['ok' => false, 'reason' => $rules->lockedReason()];
        }
        if (isset($frozen[$date])) {
            return ['ok' => false, 'reason' => t('This booking was cancelled late and can no longer be changed.')];
        }

        $left = $meals === null ? [] : array_values(array_diff($current, $meals));
        self::save($user->id, $date, $left, $ids[$date] ?? null);
        return ['ok' => true];
    }

    /** Marks every meal of a locked booking as missed and adds one strike per meal. */
    private static function lateCancel(User $user, string $orderId, string $date): void
    {
        $order = DB::table('oder')->where('id', $orderId)->where('user', $user->id)->first();
        if ($order === null) {
            return;
        }
        $toCancel = [];
        foreach (self::COLUMN as $meal => $column) {
            if ((int) $order->{$column} === 1 && (int) $order->{'no_show_' . $meal} === 0 && (int) $order->{'attended_' . $meal} === 0) {
                $toCancel[] = $meal;
            }
        }
        if ($toCancel === []) {
            return;
        }

        // The row stays (the kitchen and the invoice still count it); each meal counts as missed.
        $flags = ['updated' => Ids::now()];
        foreach ($toCancel as $meal) {
            $flags['no_show_' . $meal] = 1;
        }
        DB::table('oder')->where('id', $orderId)->update($flags);

        foreach ($toCancel as $meal) {
            Standing::addStrike($user->id, $date, $meal, 'late');
        }
    }

    /** Why this person can't book $meal on $date, given what is already booked that day; null when they can. */
    private static function block(array $plan, array $standing, string $date, string $meal, array $current, BookingRules $rules): ?string
    {
        if ($standing['blocked_until'] !== '' && $date < $standing['blocked_until']) {
            return t('Booking is paused until {date} after {count} no-shows. You can book days from then on.', [
                'date' => Days::format($standing['blocked_until'], true),
                'count' => $standing['strikes'],
            ]);
        }
        if ($rules->mealClosed($meal)) {
            return t('{meal} is closed during Ramadan.', ['meal' => $rules->mealLabel($meal)]);
        }
        if (!in_array($meal, $plan['meals'], true)) {
            return t('{meal} is not included in the {plan} plan.', ['meal' => $rules->mealLabel($meal), 'plan' => t($plan['label'])]);
        }
        if ($plan['max'] > 1 && !in_array($meal, $current, true) && count($current) >= $plan['max']) {
            return t('You can book up to {count} meals per day.', ['count' => $plan['max']]);
        }
        return null;
    }

    /** Creates or updates the day's row, or deletes it when no meals are left. */
    private static function save(string $userId, string $date, array $meals, ?string $id): void
    {
        if ($meals === []) {
            if ($id !== null) {
                DB::table('oder')->where('id', $id)->delete();
            }
            return;
        }

        $data = [
            'food' => self::menuIdFor($date),
            'date' => Days::toDb($date),
            'bool' => (int) in_array('breakfast', $meals, true),
            'bool2' => (int) in_array('lunch', $meals, true),
            'bool3' => (int) in_array('dinner', $meals, true),
            'updated' => Ids::now(),
        ];
        if ($id !== null) {
            DB::table('oder')->where('id', $id)->update($data);
            return;
        }
        DB::table('oder')->insert($data + ['id' => Ids::new(), 'user' => $userId, 'created' => Ids::now()]);
    }

    /**
     * An admin puts a person on a day's meals, whatever the rules say: past or locked days, a paused person, a meal
     * outside their plan, Ramadan, an account switched off. Nothing is checked and no strike is added.
     * Meals already booked stay as they are (served / missed marks included).
     *
     * @param  string[]  $meals
     * @return array{added: string[], already: string[]}
     */
    public static function adminAdd(string $userId, string $date, array $meals): array
    {
        $meals = array_values(array_intersect(array_keys(self::COLUMN), $meals));
        $row = DB::table('oder')->where('user', $userId)->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")->first();
        $current = $row ? array_keys(array_filter(self::COLUMN, fn ($c) => (int) $row->{$c} === 1)) : [];
        $added = array_values(array_diff($meals, $current));
        if ($added !== []) {
            self::save($userId, $date, array_values(array_unique([...$current, ...$added])), $row?->id);
        }
        return ['added' => $added, 'already' => array_values(array_intersect($meals, $current))];
    }

    /**
     * An admin takes meals off a booking (the whole booking when none are named), whatever the rules say.
     * A meal that was marked missed gives its strike back. Returns the meals removed.
     *
     * @param  ?string[]  $meals
     * @return string[]
     */
    public static function adminRemove(string $orderId, ?array $meals = null): array
    {
        $row = DB::table('oder')->where('id', $orderId)->first();
        if ($row === null) {
            return [];
        }
        $current = array_keys(array_filter(self::COLUMN, fn ($c) => (int) $row->{$c} === 1));
        $remove = $meals === null ? $current : array_values(array_intersect($current, $meals));
        foreach ($remove as $meal) {
            if ((int) $row->{'no_show_' . $meal} === 1) {
                Standing::takeBackStrike((string) $row->user);
            }
        }
        if ($remove === []) {
            return [];
        }
        $left = array_values(array_diff($current, $remove));
        if ($left === []) {
            DB::table('oder')->where('id', $orderId)->delete();
        } else {
            $update = ['updated' => Ids::now()];
            foreach ($remove as $meal) {
                $update[self::COLUMN[$meal]] = 0;
                $update['attended_' . $meal] = 0;
                $update['no_show_' . $meal] = 0;
            }
            DB::table('oder')->where('id', $orderId)->update($update);
        }
        return $remove;
    }

    /** The menu row of a day (preferring one that has dishes), or null when the day has no menu. */
    private static function menuIdFor(string $date): ?string
    {
        $rows = DB::table('meun')->where('day', '>=', $date . ' 00:00:00.000')->where('day', '<=', $date . ' 23:59:59.999')->get();
        $withDishes = $rows->first(fn ($r) => $r->brakfast_title !== '' || $r->lunch_title !== '' || $r->Dinner_title !== '');
        return ($withDishes ?? $rows->first())?->id;
    }
}
