<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\Settings;
use App\Support\Days;
use App\Support\Ids;
use App\Support\PenaltyRules as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin: penalties against the catering contractor (`record_penalties`). One record = one service (day + meal)
 * with an amount and a reason per contract section. Amounts are worked out here from the contract rules and
 * the service amount (meals x the meal's unit price), never typed.
 */
class PenaltyController extends Controller
{
    private const MEAL_TO_DB = ['breakfast' => 'brakfast', 'lunch' => 'lunch', 'dinner' => 'dinner'];

    public function index(Request $request): View
    {
        $rows = self::rows();
        $thisMonth = substr(Days::today(), 0, 7);
        $months = collect($rows)->pluck('date')->map(fn ($d) => substr($d, 0, 7))->push($thisMonth)->unique()->sortDesc()->values();
        $month = (string) $request->query('month', $thisMonth);
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : $thisMonth;

        $inMonth = collect($rows)->filter(fn ($r) => str_starts_with($r['date'], $month))
            ->sortByDesc(fn ($r) => $r['date'] . $r['created'])->values();

        $prices = Settings::prices();
        $date = (string) old('date', Days::today());
        $meal = (string) old('meal', 'lunch');

        return view('admin.penalties', [
            'rules' => BookingRules::current(),
            'currency' => Settings::invoice()['currency'],
            'prices' => $prices['meals'],
            'months' => $months,
            'month' => $month,
            'inMonth' => $inMonth,
            'monthTotal' => P::round2($inMonth->sum('amount')),
            'bySection' => collect([1, 2, 3, 4])->mapWithKeys(fn ($s) => [$s => [
                'count' => $inMonth->where('section', $s)->count(),
                'amount' => P::round2($inMonth->where('section', $s)->sum('amount')),
            ]]),
            'date' => $date,
            'meal' => $meal,
            'booked' => $this->booked($date, $meal),
            'status' => session('penalty_status'),
            'problems' => session('penalty_problems', []),
        ]);
    }

    /** Booked meals for a day's meal: the default "number of meals" (asked by the page when the day changes). */
    public function booked(string $date, string $meal): int
    {
        $column = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'][$meal] ?? 'bool2';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? (int) DB::table('oder')->where($column, 1)->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")->count()
            : 0;
    }

    public function bookedJson(Request $request): JsonResponse
    {
        return response()->json(['booked' => $this->booked((string) $request->query('date'), (string) $request->query('meal'))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $date = (string) $request->input('date');
        $meal = (string) $request->input('meal');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !isset(self::MEAL_TO_DB[$meal])) {
            return $this->fail($request, ['Pick the day and the meal of the service.']);
        }

        $unit = Settings::prices()['meals'][$meal];
        $mealsInput = trim((string) $request->input('meals'));
        $mealCount = $mealsInput === '' ? $this->booked($date, $meal) : max(0, (int) round((float) $mealsInput));
        $service = P::round2($mealCount * $unit);

        [$lines, $problems] = $this->lines($request, $unit);
        if (!$lines && !$problems) {
            $problems[] = 'Tick a section above to add a penalty.';
        }
        if ($problems) {
            return $this->fail($request, $problems);
        }

        $record = ['meal' => self::MEAL_TO_DB[$meal], 'date_of_it' => Days::toDb($date), 'note' => trim((string) $request->input('note'))];
        foreach (P::FIELDS as [$amountColumn, $reasonColumn]) {
            $record[$amountColumn] = 0;
            $record[$reasonColumn] = '';
        }
        $total = 0.0;
        foreach ([1, 2, 3, 4] as $section) {
            $mine = array_filter($lines, fn ($l) => $l['section'] === $section);
            if (!$mine) {
                continue;
            }
            [$amountColumn, $reasonColumn] = P::FIELDS[$section];
            $amounts = array_map(fn ($l) => P::round2($service * $l['percent'] / 100 + $l['refund']), $mine);
            $record[$amountColumn] = P::round2(array_sum($amounts));
            // "Delivery delay of 12 minutes — 5%" (+ refund N); several KPIs are joined with "; ".
            $record[$reasonColumn] = implode('; ', array_map(
                fn ($l) => $l['reason'] . ' — ' . $l['percent'] . '%' . ($l['refund'] ? ' + refund ' . $l['refund'] : ''),
                $mine
            ));
            $total += $record[$amountColumn];
        }
        $now = Ids::now();
        DB::table('record_penalties')->insert($record + ['id' => Ids::new(), 'created' => $now, 'updated' => $now]);

        $money = Settings::money(P::round2($total));
        $when = Days::fromKey($date)->locale('en')->translatedFormat('j M');
        $text = count($lines) === 1
            ? "1 penalty recorded: {$money} for {$meal} on {$when}."
            : count($lines) . " penalties recorded: {$money} for {$meal} on {$when}.";
        return redirect('/penalties?month=' . substr($date, 0, 7))->with('penalty_status', $text);
    }

    /** Removes one section of a record; the record goes once no section is left. id = "<record id>|<section>". */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        [$recordId, $section] = array_pad(explode('|', $id), 2, '');
        $section = (int) $section;
        $record = DB::table('record_penalties')->where('id', $recordId)->first();
        abort_if($record === null || !isset(P::FIELDS[$section]), 404);

        $others = array_filter([1, 2, 3, 4], fn ($s) => $s !== $section
            && ((float) $record->{P::FIELDS[$s][0]} !== 0.0 || trim((string) $record->{P::FIELDS[$s][1]}) !== ''));
        if (!$others) {
            DB::table('record_penalties')->where('id', $recordId)->delete();
        } else {
            DB::table('record_penalties')->where('id', $recordId)->update([P::FIELDS[$section][0] => 0, P::FIELDS[$section][1] => '', 'updated' => Ids::now()]);
        }
        return redirect('/penalties?month=' . $request->input('month', substr(Days::today(), 0, 7)))->with('penalty_status', 'Penalty deleted.');
    }

    /**
     * The penalty lines chosen in the form, and what is still missing.
     *
     * @return array{0: array<int, array{section: int, reason: string, percent: float, refund: float}>, 1: string[]}
     */
    private function lines(Request $request, float $unit): array
    {
        $lines = [];
        $problems = [];

        if ($request->boolean('s1')) {
            $kpis = array_values(array_intersect(array_keys(P::KPIS), (array) $request->input('kpis', [])));
            if (!$kpis) {
                $problems[] = 'Section 1: tick at least one KPI.';
            }
            foreach ($kpis as $id) {
                $lines[] = ['section' => 1, 'reason' => P::KPIS[$id]['indicator'], 'percent' => P::KPIS[$id]['percent'], 'refund' => 0];
            }
        }
        if ($request->boolean('s2')) {
            $delay = trim((string) $request->input('delay'));
            $minutes = (int) $delay;
            if ($request->boolean('significant')) {
                $percent = min(P::MAX_SIGNIFICANT_DELAY_PERCENT, max(0, (float) $request->input('significant_percent', 100)));
                $lines[] = ['section' => 2, 'reason' => 'Delay with significant impact' . ($delay !== '' ? " ({$minutes} min)" : ''), 'percent' => $percent, 'refund' => 0];
            } elseif ($delay === '' || $minutes < 0) {
                $problems[] = 'Section 2: enter the delay in minutes.';
            } elseif (P::delayPercent($minutes) === 0) {
                $problems[] = 'Section 2: delays under 5 minutes carry no penalty.';
            } else {
                $lines[] = ['section' => 2, 'reason' => "Delivery delay of {$minutes} minutes", 'percent' => P::delayPercent($minutes), 'refund' => 0];
            }
        }
        if ($request->boolean('s3')) {
            $tier = P::SHORTFALL_TIERS[(string) $request->input('shortfall')] ?? P::SHORTFALL_TIERS['under5'];
            $missing = max(0, (int) round((float) $request->input('undelivered')));
            $lines[] = [
                'section' => 3,
                'reason' => $missing ? "{$tier[0]} ({$missing} meals undelivered)" : $tier[0],
                'percent' => $tier[1],
                'refund' => P::round2($missing * $unit),
            ];
        }
        if ($request->boolean('s4')) {
            $percent = trim((string) $request->input('quality_percent'));
            $quality = in_array($request->input('quality'), P::QUALITY_FAILURES, true) ? $request->input('quality') : P::QUALITY_FAILURES[0];
            if ($percent === '') {
                $problems[] = 'Section 4: enter the penalty %.';
            } else {
                $lines[] = ['section' => 4, 'reason' => $quality, 'percent' => min(100, max(0, (float) $percent)), 'refund' => 0];
            }
        }
        return [$lines, $problems];
    }

    /**
     * Every saved record split into one row per section that has a penalty (the React toRows()).
     *
     * @return array<int, array{id: string, date: string, meal: string, section: int, reason: string, percent: float, refund: float, amount: float, note: string, created: string}>
     */
    public static function rows(): array
    {
        $out = [];
        foreach (DB::table('record_penalties')->get() as $r) {
            $date = Days::keyOf($r->date_of_it ?: $r->created);
            $meal = array_search((string) $r->meal, self::MEAL_TO_DB, true) ?: 'lunch';
            foreach (P::FIELDS as $section => [$amountColumn, $reasonColumn]) {
                $amount = (float) $r->{$amountColumn};
                $reason = trim((string) $r->{$reasonColumn});
                if ($amount === 0.0 && $reason === '') {
                    continue;
                }
                preg_match_all('/(\d+(?:\.\d+)?)%/', $reason, $rates);
                preg_match('/\+ refund (\d+(?:\.\d+)?)/', $reason, $refund);
                $out[] = [
                    'id' => "{$r->id}|{$section}",
                    'date' => $date,
                    'meal' => $meal,
                    'section' => $section,
                    'reason' => $reason,
                    'percent' => $rates[1] ? (float) end($rates[1]) : 0.0,
                    'refund' => (float) ($refund[1] ?? 0),
                    'amount' => $amount,
                    'note' => (string) $r->note,
                    'created' => (string) $r->created,
                ];
            }
        }
        return $out;
    }

    private function fail(Request $request, array $problems): RedirectResponse
    {
        return redirect('/penalties')->withInput($request->except('_token'))->with('penalty_problems', $problems);
    }
}
