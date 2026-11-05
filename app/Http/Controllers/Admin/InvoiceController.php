<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\ManagerMeals;
use App\Services\Settings;
use App\Support\Days;
use App\Support\PenaltyRules as P;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: the monthly invoice for the catering contractor, built from the meals ordered (or served) each day,
 * the manager meals, special meals and sandwiches, minus the month's penalties, plus VAT. Prices and the
 * contractor/company details come from Settings. Same figures as the React Invoices page.
 */
class InvoiceController extends Controller
{
    private const MEALS = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    public function index(Request $request): View
    {
        [$month, $months] = $this->monthOf($request);
        $basis = $request->query('basis') === 'attended' ? 'attended' : 'booked';
        $sent = Settings::get('invoices_sent');

        return view('admin.invoices', $this->build($month, $basis) + [
            'months' => $months,
            'currentMonth' => substr(Days::today(), 0, 7),
            'sent' => $sent,
            'sentAt' => $sent[$month] ?? null,
            'isSuper' => Roles::isSuper($request->user()->User_Type),
        ]);
    }

    /** Marks the month as sent (or undoes it). Shared by every admin, unlike the React page's browser storage. */
    public function toggleSent(Request $request): RedirectResponse
    {
        $month = (string) $request->input('month');
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month), 422);
        $sent = Settings::get('invoices_sent');
        if (isset($sent[$month])) {
            unset($sent[$month]);
        } else {
            $sent[$month] = gmdate('c');
        }
        Settings::put('invoices_sent', $sent);
        return redirect("/invoices?month={$month}&basis=" . $request->input('basis', 'booked'));
    }

    public function csv(Request $request): StreamedResponse
    {
        [$month] = $this->monthOf($request);
        $basis = $request->query('basis') === 'attended' ? 'attended' : 'booked';
        $inv = $this->build($month, $basis);
        $cur = $inv['currency'];
        $f2 = fn (float $n) => number_format($n, 2, '.', '');
        $mealNames = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];

        $rows = [[$inv['number'], Days::fromKey("{$month}-01")->locale('en')->translatedFormat('F Y')], []];
        $rows[] = ['Date', 'Breakfast', 'Lunch', 'Dinner', 'Sandwiches', 'Special meals', 'Meals', "Penalties ({$cur})", "Amount ({$cur})"];
        foreach ($inv['days'] as $d) {
            $rows[] = [$d['key'], $d['meals']['breakfast'], $d['meals']['lunch'], $d['meals']['dinner'], $d['sandwiches'], $d['specials'], $d['total'], $d['penalty'] ? $f2(-$d['penalty']) : '', $f2($d['amount'])];
        }
        $rows[] = [];
        $rows[] = ['Section', 'Meal', 'Quantity', 'Unit price', 'Amount'];
        foreach ($inv['groups'] as $g) {
            foreach ($g['lines'] as $l) {
                $rows[] = [$g['label'], $mealNames[$l['type']], $l['quantity'], $f2($l['price']), $f2($l['amount'])];
            }
            $rows[] = ["{$g['label']} subtotal", '', $g['quantity'], '', $f2($g['amount'])];
        }
        $rows[] = ['Sandwiches', 'Quantity', $inv['sandwiches'], '', ''];
        foreach ($inv['specialLines'] as $l) {
            $rows[] = ['Special meals', $l['label'], $l['quantity'], $f2($l['price']), $f2($l['amount'])];
        }
        $rows[] = ['Special meals subtotal', '', $inv['specialQuantity'], '', $f2($inv['specialAmount'])];
        $rows[] = ['Meals and special meals', '', $inv['totalMeals'], '', $f2($inv['grossSubtotal'])];
        foreach ($inv['penaltyLines'] as $l) {
            $rows[] = ['Penalty', P::SECTION_TITLE[$l['section']], $l['count'], '', $f2(-$l['amount'])];
        }
        $rows[] = ['Subtotal after penalties', '', '', '', $f2($inv['subtotal'])];
        $rows[] = ["VAT {$inv['vat']}%", '', '', '', $f2($inv['vatAmount'])];
        $rows[] = ['Total', '', '', '', $f2($inv['total'])];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads the accents as UTF-8
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "{$inv['number']}.csv", ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string[]} the chosen month and every billable month, newest first */
    private function monthOf(Request $request): array
    {
        $today = Days::today();
        $first = (string) (DB::table('oder')->whereNotNull('date')->min('date') ?? $today);
        $months = [];
        $cursor = CarbonImmutable::createFromFormat('Y-m-d', substr($first, 0, 7) . '-01');
        $last = substr($today, 0, 7);
        while ($cursor->format('Y-m') <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }
        $months = array_reverse($months);
        $current = substr($today, 0, 7);
        $default = collect($months)->first(fn ($m) => $m !== $current) ?? $months[0];
        $month = (string) $request->query('month', $request->input('month', $default));
        return [in_array($month, $months, true) ? $month : $default, $months];
    }

    /** Every figure of one month's invoice. */
    private function build(string $month, string $basis): array
    {
        $invoice = Settings::invoice();
        $allPrices = Settings::prices();
        $prices = $allPrices['meals'];
        $rules = BookingRules::current();
        $first = "{$month}-01";
        $length = (int) CarbonImmutable::createFromFormat('Y-m-d', $first)->daysInMonth;
        $keys = array_map(fn ($i) => Days::add($first, $i), range(0, $length - 1));
        $lastDay = end($keys);
        $range = fn ($q, string $column) => $q->where($column, '>=', "{$first} 00:00:00.000")->where($column, '<=', "{$lastDay} 23:59:59.999");

        // Orders of the month, once per person/day/meals (the React uniqueOrderRows), with their role.
        $users = DB::table('user')->pluck('User_Type', 'id');
        $seen = [];
        $byDay = [];      // date => meal => count (all groups)
        $byGroup = ['main' => [], 'intern' => []]; // group => meal => count
        $missedMeals = 0;
        $missedAmount = 0.0;
        foreach ($range(DB::table('oder'), 'date')->get() as $o) {
            $meals = array_keys(array_filter(self::MEALS, fn ($c) => (int) $o->{$c} === 1));
            if (!$meals) {
                continue;
            }
            $missed = array_values(array_filter($meals, fn ($m) => (int) $o->{'no_show_' . $m} === 1));
            $day = Days::keyOf($o->date);
            $key = "{$o->user}|{$day}|" . implode(',', $meals) . '|' . ($missed ? 1 : 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $missedMeals += count($missed);
            foreach ($missed as $m) {
                $missedAmount += $prices[$m];
            }
            $group = Roles::normalize($users[$o->user] ?? '') === 'intern' ? 'intern' : 'main';
            foreach ($basis === 'attended' ? array_diff($meals, $missed) : $meals as $m) {
                $byDay[$day][$m] = ($byDay[$day][$m] ?? 0) + 1;
                $byGroup[$group][$m] = ($byGroup[$group][$m] ?? 0) + 1;
            }
        }

        // Manager meals: booked automatically on the days the menu serves the meal; billed with the main meals.
        $served = [];
        $fields = ['breakfast' => 'brakfast_title', 'lunch' => 'lunch_title', 'dinner' => 'Dinner_title'];
        foreach ($range(DB::table('meun'), 'day')->get() as $row) {
            foreach ($fields as $m => $f) {
                if (trim((string) $row->{$f}) !== '') {
                    $served[Days::keyOf($row->day)][] = $m;
                }
            }
        }
        foreach ($keys as $k) {
            foreach (ManagerMeals::on($k, $served[$k] ?? []) as $m => $n) {
                if ($n > 0) {
                    $byDay[$k][$m] = ($byDay[$k][$m] ?? 0) + $n;
                    $byGroup['main'][$m] = ($byGroup['main'][$m] ?? 0) + $n;
                }
            }
        }

        // Special meals, sandwiches and penalties of the month.
        $specialKinds = MealController::SPECIAL_KINDS;
        $specials = $range(DB::table('special_meals'), 'Day')->get()->map(fn ($s) => [
            'date' => Days::keyOf($s->Day), 'kind' => array_search($s->type, $specialKinds, true) ?: 'enhanced', 'count' => (int) $s->How_many,
        ]);
        $sandwiches = $range(DB::table('sandwich_orders'), 'date')->get()->groupBy(fn ($s) => Days::keyOf($s->date))->map(fn ($g) => $g->sum('quantity'));
        $penalties = collect(PenaltyController::rows())->filter(fn ($p) => str_starts_with($p['date'], $month));

        $days = [];
        foreach ($keys as $k) {
            $meals = ['breakfast' => $byDay[$k]['breakfast'] ?? 0, 'lunch' => $byDay[$k]['lunch'] ?? 0, 'dinner' => $byDay[$k]['dinner'] ?? 0];
            $daySpecials = $specials->where('date', $k);
            $specialAmount = $daySpecials->sum(fn ($s) => $s['count'] * $allPrices['specials'][$s['kind']]);
            $penalty = P::round2($penalties->where('date', $k)->sum('amount'));
            $gross = $meals['breakfast'] * $prices['breakfast'] + $meals['lunch'] * $prices['lunch'] + $meals['dinner'] * $prices['dinner'] + $specialAmount;
            $days[] = [
                'key' => $k, 'meals' => $meals, 'specials' => $daySpecials->sum('count'), 'sandwiches' => (int) ($sandwiches[$k] ?? 0),
                'total' => array_sum($meals) + $daySpecials->sum('count'), 'penalty' => $penalty, 'amount' => P::round2($gross - $penalty),
            ];
        }

        $groups = [];
        foreach (['main' => 'Main meals', 'intern' => 'Intern meals'] as $g => $label) {
            $lines = [];
            foreach (array_keys(self::MEALS) as $m) {
                $q = $byGroup[$g][$m] ?? 0;
                if ($q > 0) {
                    $lines[] = ['type' => $m, 'quantity' => $q, 'price' => $prices[$m], 'amount' => P::round2($q * $prices[$m])];
                }
            }
            $groups[] = ['group' => $g, 'label' => $label, 'lines' => $lines, 'quantity' => array_sum(array_column($lines, 'quantity')), 'amount' => P::round2(array_sum(array_column($lines, 'amount')))];
        }

        $specialLines = [];
        foreach ($specialKinds as $kind => $label) {
            $q = $specials->where('kind', $kind)->sum('count');
            $price = $allPrices['specials'][$kind];
            $specialLines[] = ['kind' => $kind, 'label' => $label, 'quantity' => $q, 'price' => $price, 'amount' => P::round2($q * $price)];
        }
        $specialQuantity = array_sum(array_column($specialLines, 'quantity'));
        $specialAmount = P::round2(array_sum(array_column($specialLines, 'amount')));

        $penaltyLines = [];
        foreach ([1, 2, 3, 4] as $s) {
            $own = $penalties->where('section', $s);
            if ($own->isNotEmpty()) {
                $penaltyLines[] = ['section' => $s, 'count' => $own->count(), 'amount' => P::round2($own->sum('amount'))];
            }
        }
        $penaltyTotal = P::round2(array_sum(array_column($penaltyLines, 'amount')));
        $grossSubtotal = P::round2(array_sum(array_column($groups, 'amount')) + $specialAmount);
        $subtotal = P::round2($grossSubtotal - $penaltyTotal);
        $vatAmount = P::round2($subtotal * (float) $invoice['vat'] / 100);

        $today = Days::today();
        $inProgress = $month === substr($today, 0, 7);
        $issue = $inProgress ? $today : Days::add($lastDay, 1);

        return [
            'month' => $month,
            'basis' => $basis,
            'rules' => $rules,
            'invoice' => $invoice,
            'currency' => $invoice['currency'],
            'vat' => $invoice['vat'],
            'prices' => $allPrices,
            'number' => 'INV-' . str_replace('-', '', $month),
            'periodEnd' => $lastDay,
            'inProgress' => $inProgress,
            'issueDate' => $issue,
            'dueDate' => Days::add($issue, (int) $invoice['paymentDays']),
            'days' => $days,
            'groups' => $groups,
            'specialLines' => $specialLines,
            'specialQuantity' => $specialQuantity,
            'specialAmount' => $specialAmount,
            'sandwiches' => (int) $sandwiches->sum(),
            'penaltyLines' => $penaltyLines,
            'penaltyTotal' => $penaltyTotal,
            'totalMeals' => array_sum(array_column($groups, 'quantity')) + $specialQuantity,
            'missedMeals' => $missedMeals,
            'missedAmount' => P::round2($missedAmount),
            'grossSubtotal' => $grossSubtotal,
            'subtotal' => $subtotal,
            'vatAmount' => $vatAmount,
            'total' => P::round2($subtotal + $vatAmount),
        ];
    }
}
