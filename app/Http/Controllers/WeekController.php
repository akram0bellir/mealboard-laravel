<?php

namespace App\Http\Controllers;

use App\Services\Bookings;
use App\Services\BookingRules;
use App\Services\MealPlan;
use App\Services\MealTimes;
use App\Services\WeekPage;
use App\Support\Days;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The week page and its booking flow. Without JavaScript: meals are ticked in one form, "Confirm" opens
 * a confirmation (the same dialog, drawn by the server), and the result is shown the same way.
 */
class WeekController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $dialog = session('outcome');
        if (session()->has('review_sel')) {
            $dialog = $this->reviewDialog($request, (array) session('review_sel'));
        }
        return view('pages.week', WeekPage::build($user) + ['dialog' => $dialog]);
    }

    /** Ticked meals -> the confirmation dialog. */
    public function review(Request $request): RedirectResponse
    {
        $selection = $this->selection($request);
        return $selection === [] ? redirect('/week') : redirect('/week')->with('review_sel', $selection);
    }

    /** The confirmed changes are applied; the result is shown in a dialog on the week page. */
    public function apply(Request $request): RedirectResponse
    {
        $changes = $this->changes($this->selection($request));
        $rules = BookingRules::current();
        $result = Bookings::apply($request->user(), $changes);

        $done = $result['done'];
        $reasons = array_values(array_unique(array_column($result['skipped'], 'reason')));
        $skippedDates = array_values(array_diff(array_unique(array_column($result['skipped'], 'date')), $done));

        return redirect('/week')->with('outcome', [
            'kind' => 'outcome',
            'tone' => $done ? 'success' : 'error',
            'title' => $done
                ? (count($done) === 1 ? t('1 day updated') : t('{count} days updated', ['count' => count($done)]))
                : t('Nothing could be changed'),
            'message' => $reasons ? implode(' ', $reasons) : null,
            'done' => $done,
            'skipped' => $skippedDates,
            'doneLabel' => t('Updated'),
        ]);
    }

    /** "Cancel booking" / "Cancel late": asks first. */
    public function cancelDialog(Request $request, string $date): View|RedirectResponse
    {
        $meals = $this->mealsParam($request, $date);
        if ($meals === []) {
            return redirect('/week');
        }

        $rules = BookingRules::current();
        $locked = $rules->dayStatus($date) === 'locked';
        $dialog = $locked
            ? [
                'title' => t('Cancel after the deadline?'),
                'body' => t('The deadline for {date} has passed. Cancelling now counts as a no-show (one of your {max} chances), and the whole day is cancelled.', [
                    'date' => Days::format($date, true), 'max' => $rules->maxNoShow,
                ]),
                'confirm' => t('Cancel anyway'),
            ]
            : [
                'title' => t('Cancel {meal}?', ['meal' => mb_strtolower($rules->mealsLabel($meals))]),
                'body' => t('Your booking on {date} will be removed.', ['date' => Days::format($date, true)]),
                'confirm' => t('Cancel booking'),
            ];

        return view('pages.week', WeekPage::build($request->user()) + ['dialog' => $dialog + [
            'kind' => 'confirm',
            'danger' => true,
            'action' => "/week/cancel/{$date}",
            'fields' => ['meals' => implode(',', $meals)],
        ]]);
    }

    public function cancel(Request $request, string $date): RedirectResponse
    {
        $rules = BookingRules::current();
        $meals = $this->mealsParam($request, $date);
        $result = Bookings::cancelDay($request->user(), $date, $request->input('meals') ? $meals : null);

        return redirect('/week')->with('outcome', $result['ok']
            ? [
                'kind' => 'outcome', 'tone' => 'success', 'title' => t('Booking cancelled'),
                'message' => t('{meal} on {date} was removed.', ['meal' => $rules->mealsLabel($meals), 'date' => Days::format($date, true)]),
            ]
            : ['kind' => 'outcome', 'tone' => 'error', 'title' => t('Day is locked'), 'message' => $result['reason']]);
    }

    /** sel[date][meal] = book | cancel, cleaned. */
    private function selection(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('sel', []) as $date => $meals) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || !is_array($meals)) {
                continue;
            }
            foreach ($meals as $meal => $action) {
                if (in_array($meal, BookingRules::MEALS, true) && in_array($action, ['book', 'cancel'], true)) {
                    $out[$date][$meal] = $action;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** date => ['book' => meals, 'cancel' => meals]. */
    private function changes(array $selection): array
    {
        $changes = [];
        foreach ($selection as $date => $meals) {
            $changes[$date] = [
                'book' => array_keys(array_filter($meals, fn ($a) => $a === 'book')),
                'cancel' => array_keys(array_filter($meals, fn ($a) => $a === 'cancel')),
            ];
        }
        return $changes;
    }

    /** The meals to cancel: the "meals" field (comma list) or, when it is empty, everything booked that day. */
    private function mealsParam(Request $request, string $date): array
    {
        [$bookings] = Bookings::forUser($request->user()->id, $date);
        $booked = $bookings[$date] ?? [];
        $asked = array_filter(explode(',', (string) $request->input('meals', '')));
        $meals = $asked === [] ? $booked : array_values(array_intersect($booked, $asked));
        return array_values(array_intersect(BookingRules::MEALS, $meals));
    }

    private function reviewDialog(Request $request, array $selection): array
    {
        $user = $request->user();
        $rules = BookingRules::current();
        $plan = MealPlan::for($user, $rules);
        [$bookings] = Bookings::forUser($user->id, \App\Support\Days::key($rules->upcomingDays(\App\Support\Days::now())[0]));
        $times = MealTimes::for($user, $plan['meals'], $rules);
        $timeOf = fn (string $meal) => isset($times[$meal]) ? ' · ' . t('your time {time}', ['time' => $times[$meal]]) : '';

        $lines = [];
        $fields = [];
        foreach ($this->changes($selection) as $date => $change) {
            $day = Days::format($date, true);
            $keeps = array_diff($bookings[$date] ?? [], $change['cancel']);
            foreach ($change['cancel'] as $meal) {
                $lines[] = ['text' => $day . ' · ' . t('cancel {meal}', ['meal' => mb_strtolower($rules->mealLabel($meal))]), 'cancel' => true];
                $fields["sel[{$date}][{$meal}]"] = 'cancel';
            }
            foreach ($change['book'] as $meal) {
                $verb = $plan['max'] === 1 && $keeps !== [] ? 'switch to {meal}' : 'book {meal}';
                $lines[] = ['text' => $day . ' · ' . t($verb, ['meal' => mb_strtolower($rules->mealLabel($meal))]) . $timeOf($meal), 'cancel' => false];
                $fields["sel[{$date}][{$meal}]"] = 'book';
            }
        }

        $books = count(array_filter($lines, fn ($l) => !$l['cancel']));
        $cancels = count($lines) - $books;
        $summary = array_filter([
            $books ? t('{count} to book', ['count' => $books]) : '',
            $cancels ? t('{count} to cancel', ['count' => $cancels]) : '',
        ]);

        return [
            'kind' => 'confirm',
            'title' => count($lines) === 1 ? t('Confirm 1 change?') : t('Confirm {count} changes?', ['count' => count($lines)]),
            'body' => implode(' · ', $summary) . '. ' . t('You can change them until {deadline}.', ['deadline' => $rules->deadlineLabel()]),
            'confirm' => t('Confirm all'),
            'danger' => false,
            'lines' => $lines,
            'action' => '/week/apply',
            'fields' => $fields,
        ];
    }
}
