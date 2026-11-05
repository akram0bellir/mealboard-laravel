<?php

namespace App\Services;

use App\Support\Days;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The live booking rules (the `booking_rules` row) and the day rules built on them:
 * which days are open, when booking closes, Ramadan mode. Same rules as the React
 * app's lib/shared/rules.ts.
 */
class BookingRules
{
    public const MEALS = ['breakfast', 'lunch', 'dinner'];

    /** Meals not served while Ramadan mode is on. */
    public const RAMADAN_CLOSED = ['lunch'];

    /** Next week opens on this weekday (Monday = 0): Tuesday. */
    public const WEEK_OPENS_ON = 1;

    private static ?self $current = null;

    public function __construct(
        public int $maxNoShow = 3,
        public int $periodDays = 30,
        public int $deadlineHour = 14,
        public int $deadlineDaysBefore = 1,
        public bool $lateCancelCounts = false,
        public bool $ramadan = false,
        public int $sandwichDeadlineHour = 14,
        public string $autoNoShowFrom = '',
    ) {
    }

    public static function current(): self
    {
        return self::$current ??= self::fromRow(DB::table('booking_rules')->first());
    }

    /** Anything missing or invalid keeps its default. */
    public static function fromRow(?object $row): self
    {
        $num = static function (mixed $value, int $min, int $max, int $fallback): int {
            if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
                return $fallback;
            }
            $n = (int) round((float) $value);
            return ($n < $min || $n > $max) ? $fallback : $n;
        };
        $r = (array) ($row ?? []);

        return new self(
            maxNoShow: $num($r['max_no_show'] ?? null, 1, 1000, 3),
            periodDays: $num($r['period_days'] ?? null, 1, 3650, 30),
            deadlineHour: $num($r['cancel_deadline_hour'] ?? null, 0, 23, 14),
            deadlineDaysBefore: $num($r['cancel_deadline_days_before'] ?? null, 0, 60, 1),
            lateCancelCounts: (int) ($r['late_cancel_counts'] ?? 0) > 0,
            ramadan: (int) ($r['ramadan_mode'] ?? 0) === 1,
            sandwichDeadlineHour: $num($r['cancel_deadline_hour_for_sandwitch'] ?? null, 0, 23, 14),
            autoNoShowFrom: trim((string) ($r['auto_no_show_from'] ?? '')),
        );
    }

    public function mealClosed(string $meal): bool
    {
        return $this->ramadan && in_array($meal, self::RAMADAN_CLOSED, true);
    }

    /** The name shown for a meal (suhoor / iftar in Ramadan mode), already translated. */
    public function mealLabel(string $meal): string
    {
        $names = $this->ramadan
            ? ['breakfast' => 'Suhoor', 'lunch' => 'Lunch', 'dinner' => 'Iftar']
            : ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
        return t($names[$meal] ?? ucfirst($meal));
    }

    /** "Breakfast", "Breakfast & lunch", "Breakfast, lunch & dinner". */
    public function mealsLabel(array $meals): string
    {
        $names = [];
        foreach (self::MEALS as $meal) {
            if (in_array($meal, $meals, true)) {
                $names[] = mb_strtolower($this->mealLabel($meal));
            }
        }
        $text = count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)) . ' ' . t('&') . ' ' . end($names)
            : ($names[0] ?? '');
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Current week and earlier are open; next week opens on Tuesday. */
    public function weekIsOpen(CarbonImmutable $day, CarbonImmutable $now): bool
    {
        $monday = Days::mondayOf($day);
        $current = Days::mondayOf($now);
        if ($monday <= $current) {
            return true;
        }
        return $monday->equalTo($current->addDays(7)) && Days::mondayIndex($now) >= self::WEEK_OPENS_ON;
    }

    /** When booking and cancelling close for a day. */
    public function deadlineOf(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfDay()->subDays($this->deadlineDaysBefore)->setTime($this->deadlineHour, 0);
    }

    /** past | locked | open. */
    public function dayStatus(string $dayKey, ?CarbonImmutable $now = null): string
    {
        $now ??= Days::now();
        $day = Days::fromKey($dayKey);
        if ($day < $now->startOfDay()) {
            return 'past';
        }
        if (!$this->weekIsOpen($day, $now)) {
            return 'locked';
        }
        return $now >= $this->deadlineOf($day) ? 'locked' : 'open';
    }

    /** e.g. "14:00 the day before" or "10:00, 2 days before". */
    public function deadlineLabel(): string
    {
        $hour = sprintf('%02d:00', $this->deadlineHour);
        return match ($this->deadlineDaysBefore) {
            1 => t('{hour} the day before', ['hour' => $hour]),
            0 => t('{hour} the same day', ['hour' => $hour]),
            default => t('{hour}, {days} days before', ['hour' => $hour, 'days' => $this->deadlineDaysBefore]),
        };
    }

    /** "Book before 14:00 today / tomorrow / Friday". */
    public function deadlineHint(CarbonImmutable $day, CarbonImmutable $now): string
    {
        $deadline = $this->deadlineOf($day);
        $diff = (int) round($now->startOfDay()->diffInDays($deadline->startOfDay(), false));
        $hour = sprintf('%02d:00', $this->deadlineHour);
        return match ($diff) {
            0 => t('Book before {hour} today', ['hour' => $hour]),
            1 => t('Book before {hour} tomorrow', ['hour' => $hour]),
            default => t('Book before {hour} {day}', ['hour' => $hour, 'day' => $deadline->translatedFormat('l')]),
        };
    }

    /** The reason shown when a day is locked. */
    public function lockedReason(): string
    {
        return t('The {deadline} deadline for this day has passed, so it is now locked. Nothing was changed.', ['deadline' => $this->deadlineLabel()]);
    }

    /**
     * The 14 days the employee sees: this week and next week (Monday-Sunday each). The window moves on
     * Tuesday, when next week opens: on Monday it still shows last week and this week.
     *
     * @return CarbonImmutable[]
     */
    public function upcomingDays(CarbonImmutable $now): array
    {
        $monday = Days::mondayOf($now);
        $start = Days::mondayIndex($now) >= self::WEEK_OPENS_ON ? $monday : $monday->subDays(7);
        return array_map(fn (int $i) => $start->addDays($i), range(0, 13));
    }
}
