<?php

namespace App\Services;

use App\Models\User;
use App\Support\Days;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The no-show standing ("3 chances"): strikes in the current period, when the period
 * started and until when booking is paused. Same rules as the PHP API's lib/strikes.php.
 *
 * A standing is ['strikes' => int, 'period_start' => 'Y-m-d'|'', 'blocked_until' => 'Y-m-d'|''].
 */
class Standing
{
    private const BOOKED = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    /** The counter shown in the top bar. Read only: nothing is written. */
    public static function strikes(User $user): int
    {
        return self::read($user, write: false)['strikes'];
    }

    /**
     * The person's standing as of today, repaired when it is inconsistent: an ended period resets
     * everything, an ended pause is lifted, strikes without a period get one. Saved unless $write is false.
     */
    public static function read(object|array $user, bool $write = true, ?BookingRules $rules = null): array
    {
        $row = $user instanceof User ? $user->getAttributes() : (array) $user;
        $rules ??= BookingRules::current();
        $today = Days::today();

        $strikes = (int) ($row['Strikes'] ?? 0);
        $periodStart = Days::keyOf($row['period_start'] ?? '');
        $blockedUntil = Days::keyOf($row['blocked_until'] ?? '');
        $changed = false;

        $periodEnd = $periodStart !== '' ? Days::add($periodStart, $rules->periodDays) : '';
        if ($periodStart !== '' && $today >= $periodEnd) {
            $strikes = 0;
            $periodStart = '';
            $blockedUntil = '';
            $changed = true;
        } elseif ($blockedUntil !== '' && $today >= $blockedUntil) {
            $blockedUntil = '';
            $changed = true;
        }

        $isBlocked = $blockedUntil !== '';
        $periodEnd = $periodStart !== '' ? Days::add($periodStart, $rules->periodDays) : '';
        $needsRepair = ($strikes > 0 && $periodStart === '')
            || ($isBlocked && $periodStart !== '' && $blockedUntil !== $periodEnd);

        if ($needsRepair) {
            if ($periodStart === '') {
                $periodStart = self::firstNoShowDay((string) $row['id'], $rules->periodDays) ?? $today;
            }
            $periodEnd = Days::add($periodStart, $rules->periodDays);
            $wantedBlock = ($isBlocked || $strikes >= $rules->maxNoShow) ? $periodEnd : '';
            if ($wantedBlock !== $blockedUntil) {
                $blockedUntil = $wantedBlock;
                $changed = true;
            }
            if (!$changed) {
                $changed = Days::keyOf($row['period_start'] ?? '') !== $periodStart;
            }
        }

        if ($changed && $write) {
            self::save((string) $row['id'], $strikes, $periodStart, $blockedUntil);
        }

        return ['strikes' => $strikes, 'period_start' => $periodStart, 'blocked_until' => $blockedUntil];
    }

    /** Earliest missed meal in the last $days days, as a day key. */
    public static function firstNoShowDay(string $userId, int $days): ?string
    {
        $since = Days::add(Days::today(), -$days);
        $rows = DB::table('oder')->where('user', $userId)->where('date', '>=', Days::toDb($since))->orderBy('date')->get();
        foreach ($rows as $row) {
            if (self::hasMissed($row)) {
                return Days::keyOf($row->date);
            }
        }
        return null;
    }

    /** Does this booking have a meal that was booked and missed? */
    public static function hasMissed(object|array|null $order): bool
    {
        $order = (array) ($order ?? []);
        foreach (self::BOOKED as $meal => $column) {
            if ((int) ($order[$column] ?? 0) === 1 && (int) ($order['no_show_' . $meal] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function save(string $userId, int $strikes, string $periodStart, string $blockedUntil): void
    {
        DB::table('user')->where('id', $userId)->update([
            'Strikes' => max(0, $strikes),
            'period_start' => $periodStart === '' ? null : Days::toDb($periodStart),
            'blocked_until' => $blockedUntil === '' ? null : Days::toDb($blockedUntil),
            'updated' => Ids::now(),
        ]);
    }

    /**
     * An admin sets the number of strikes (e.g. forgiving a mistake). Reaching the limit pauses booking until the
     * period ends (open bookings before that day are cancelled); going below it lifts the pause; 0 ends the period.
     * The person is told when the pause starts or ends.
     */
    public static function setStrikes(string $userId, int $count): array
    {
        $rules = BookingRules::current();
        $user = DB::table('user')->where('id', $userId)->first();
        if ($user === null) {
            return ['strikes' => 0, 'period_start' => '', 'blocked_until' => ''];
        }

        $before = self::read($user, true, $rules);
        if ($count <= 0) {
            $periodStart = '';
            $blockedUntil = '';
            $count = 0;
        } else {
            $periodStart = $before['period_start'] !== '' ? $before['period_start'] : (self::firstNoShowDay($userId, $rules->periodDays) ?? Days::today());
            $periodEnd = Days::add($periodStart, $rules->periodDays);
            $blockedUntil = $count >= $rules->maxNoShow ? $periodEnd : '';
        }
        self::save($userId, $count, $periodStart, $blockedUntil);

        if ($before['blocked_until'] === '' && $blockedUntil !== '') {
            self::deleteOpenBookings($userId, $blockedUntil);
            $date = Days::fromKey($blockedUntil)->locale('en')->translatedFormat('j M');
            Notifier::send(
                [$userId],
                "Booking paused until {$date}",
                "You've reached {$rules->maxNoShow} no-shows, so you can't book meals until {$date}.",
                'No-show'
            );
        } elseif ($before['blocked_until'] !== '' && $blockedUntil === '') {
            Notifier::send([$userId], 'Booking access restored', 'A no-show was removed from your record, so you can book meals again.', 'No-show');
        }

        return ['strikes' => $count, 'period_start' => $periodStart, 'blocked_until' => $blockedUntil];
    }

    /** One strike less (a no-show was undone). A pause that falls below the limit is lifted. */
    public static function takeBackStrike(string $userId): array
    {
        $user = DB::table('user')->where('id', $userId)->first();
        if ($user === null) {
            return ['strikes' => 0, 'period_start' => '', 'blocked_until' => ''];
        }
        $standing = self::read($user);
        return $standing['strikes'] <= 0 ? $standing : self::setStrikes($userId, $standing['strikes'] - 1);
    }

    /** An admin lifts the booking pause; the strikes stay. */
    public static function liftBlock(string $userId): void
    {
        DB::table('user')->where('id', $userId)->update(['blocked_until' => null, 'updated' => Ids::now()]);
        Notifier::send([$userId], 'Booking access restored', 'An admin lifted your booking pause, so you can book meals again.', 'No-show');
    }

    /**
     * Adds one strike for a missed meal on $dayKey and warns the person.
     * $kind: 'missed' | 'late'.
     */
    public static function addStrike(string $userId, string $dayKey, string $meal, string $kind = 'missed'): array
    {
        $rules = BookingRules::current();
        $user = DB::table('user')->where('id', $userId)->first();
        if ($user === null) {
            return ['strikes' => 0, 'period_start' => '', 'blocked_until' => ''];
        }

        $standing = self::read($user, true, $rules);
        $strikes = $standing['strikes'];
        $periodStart = $standing['period_start'] !== '' ? $standing['period_start'] : $dayKey;
        $wasBlocked = $standing['blocked_until'] !== '';

        $periodEnd = Days::add($periodStart, $rules->periodDays);
        if ($periodEnd <= Days::today()) {
            return $standing; // a period that is already over: nothing to count
        }

        $strikes++;
        $blockedUntil = $standing['blocked_until'];
        $newlyPaused = $strikes >= $rules->maxNoShow && !$wasBlocked;
        if ($newlyPaused) {
            $blockedUntil = $periodEnd;
        }

        self::save($userId, $strikes, $periodStart, $blockedUntil);
        $standing = ['strikes' => $strikes, 'period_start' => $periodStart, 'blocked_until' => $blockedUntil];

        $what = $kind === 'late'
            ? sprintf('Late cancellation of %s on %s', $meal, self::fmtDay($dayKey))
            : sprintf('Missed %s on %s', $meal, self::fmtDay($dayKey));

        if ($newlyPaused) {
            $cancelled = self::deleteOpenBookings($userId, $blockedUntil);
            $message = sprintf(
                "%s. That's %d missed meals since %s, so you can't book meals until %s.",
                $what, $strikes, self::fmtDay($periodStart), self::fmtDay($blockedUntil)
            );
            if ($cancelled > 0) {
                $message .= sprintf(' %d upcoming booking(s) %s cancelled.', $cancelled, $cancelled === 1 ? 'was' : 'were');
            }
            Notifier::send([$userId], 'Booking paused until ' . self::fmtDay($blockedUntil), $message, 'No-show');
        } elseif ($strikes < $rules->maxNoShow) {
            $left = $rules->maxNoShow - $strikes;
            $title = $left === 1 ? 'Last chance' : $left . ' chances left';
            $message = $left === 1
                ? sprintf('%s. One more missed meal before %s and booking will be paused.', $what, self::fmtDay($periodEnd))
                : sprintf('%s. %d more missed meals before %s and booking will be paused.', $what, $left, self::fmtDay($periodEnd));
            Notifier::send([$userId], $title, $message, 'No-show');
        }

        return $standing;
    }

    /**
     * Deletes the person's bookings from today up to (not including) $untilDay, keeping the days that
     * are already locked (the kitchen has ordered them). Returns how many bookings were removed.
     */
    public static function deleteOpenBookings(string $userId, string $untilDay): int
    {
        $rules = BookingRules::current();
        $rows = DB::table('oder')
            ->where('user', $userId)
            ->where('date', '>=', Days::today() . ' 00:00:00.000')
            ->where('date', '<', Days::toDb($untilDay))
            ->get();

        $deleted = 0;
        foreach ($rows as $row) {
            $hasMeal = (int) $row->bool + (int) $row->bool2 + (int) $row->bool3 > 0;
            if (!$hasMeal || $rules->dayStatus(Days::keyOf($row->date)) !== 'open') {
                continue;
            }
            DB::table('oder')->where('id', $row->id)->delete();
            $deleted++;
        }
        return $deleted;
    }

    /** "Thursday 29 Oct", the way the e-mails and notifications write a day. */
    private static function fmtDay(string $dayKey): string
    {
        return Days::fromKey($dayKey)->locale('en')->format('l j M');
    }
}
