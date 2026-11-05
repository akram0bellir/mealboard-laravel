<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingRules;
use App\Services\Explore;
use App\Services\ManagerMeals;
use App\Services\Settings;
use App\Services\Standing;
use App\Services\StatsItems as S;
use App\Support\Days;
use App\Support\PenaltyRules;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: bookings, attendance and no-shows for a day, week, month or year, filtered by meal, department, sex and
 * admin accounts. Every figure comes from the same "meal items" (one per booked meal), like the React page.
 */
class StatisticsController extends Controller
{
    /** Statistics start here (the React STATS_START_KEY). */
    private const START = '2026-01-05';

    /** The booking window counts too, so upcoming bookings show. */
    private const WINDOW_DAYS = 15;

    private const MEALS = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    private const LABEL = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];

    public function index(Request $request): View
    {
        $f = $this->filters($request);
        $data = $this->compute($f);
        return view('admin.statistics', $data + ['f' => $f, 'labels' => self::LABEL]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $d = $this->compute($f);
        $types = $f['meal'] === 'all' ? array_keys(self::MEALS) : [$f['meal']];
        $rows = [['Period', 'Meal', 'Booked', 'Attended', 'No-shows', 'Attendance %']];
        foreach ($d['buckets'] as $b) {
            $s = $this->sum($b['days'], $f['meal'], $d['lookup']);
            foreach ($types as $t) {
                $rows[] = [$b['label'], self::LABEL[$t], $s['byType'][$t]['booked'], $s['byType'][$t]['attended'], $s['byType'][$t]['noShows'], $this->rate($s['byType'][$t])];
            }
        }
        $name = "meal-stats-{$f['period']}-{$f['anchor']}" . ($f['meal'] === 'all' ? '' : "-{$f['meal']}") . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /* ------------------------------------------------------------------ */

    private function filters(Request $request): array
    {
        $today = Days::today();
        $anchor = (string) $request->query('anchor', $today);
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) && $anchor >= self::START && $anchor <= $today ? $anchor : $today;
        return [
            'period' => in_array($request->query('period'), ['day', 'week', 'month', 'year'], true) ? $request->query('period') : 'week',
            'meal' => isset(self::MEALS[(string) $request->query('meal')]) ? (string) $request->query('meal') : 'all',
            'metric' => in_array($request->query('metric'), ['booked', 'attended', 'noShows'], true) ? $request->query('metric') : 'booked',
            'anchor' => $anchor,
            'department' => in_array($request->query('department'), ['1', '2', '3'], true) ? $request->query('department') : '',
            'sex' => in_array($request->query('sex'), ['male', 'female'], true) ? $request->query('sex') : '',
            'admins' => $request->boolean('admins'),
            'all' => $request->boolean('all'),
            'explore' => Explore::viewFrom($request->query()),
            'xTable' => $request->query('x_view') === 'table',
            'uid' => (string) $request->user()->id,
        ];
    }

    /** The page's own values (filters + Explore view) as an address, so links keep the whole screen. */
    private function address(array $f, array $extra = []): string
    {
        $base = ['period' => $f['period'], 'meal' => $f['meal'], 'metric' => $f['metric'], 'anchor' => $f['anchor'], 'department' => $f['department'], 'sex' => $f['sex'], 'admins' => $f['admins'] ? 1 : null];
        return '?' . http_build_query(array_filter(array_merge($base, $extra), fn ($v) => $v !== null && $v !== ''));
    }

    /** Explore → "Save view": the view is kept for this admin (the React page keeps it in the browser). */
    public function exploreSave(Request $request): RedirectResponse
    {
        $f = $this->filters($request);
        $view = $f['explore'];
        $view['name'] = Explore::nameOf($view);
        $key = 'explore_views_' . $f['uid'];
        $saved = array_values(array_filter(Settings::get($key), fn ($v) => ($v['name'] ?? '') !== $view['name']));
        $saved[] = $view;
        Settings::put($key, array_slice($saved, -30));
        return redirect('/statistics' . $this->address($f, Explore::toQuery($view)) . '#explore');
    }

    public function exploreDelete(Request $request): RedirectResponse
    {
        $f = $this->filters($request);
        $key = 'explore_views_' . $f['uid'];
        $name = (string) $request->input('name');
        Settings::put($key, array_values(array_filter(Settings::get($key), fn ($v) => ($v['name'] ?? '') !== $name)));
        return redirect('/statistics' . $this->address($f, Explore::toQuery($f['explore'])) . '#explore');
    }

    public function exploreCsv(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $d = $this->compute($f);
        $x = $d['explore'];
        $rows = [array_merge(['Group', $x['labels'][0] ?? $x['metricLabel']], $x['labels'] ? [$x['labels'][1]] : [], ['Note'])];
        foreach ($x['data'] as $r) {
            $rows[] = array_merge([$r['name'], !empty($r['hidden']) ? '' : $x['fmt']($r['value'])], $x['labels'] ? [isset($r['compare']) ? $x['fmt']($r['compare']) : ''] : [], [$r['note'] ?? '']);
        }
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 'mealboard-explore.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function inRange(string $key): bool
    {
        return $key >= self::START && $key <= Days::add(Days::today(), self::WINDOW_DAYS - 1);
    }

    /** @return array{title: string, buckets: array<int, array{label: string, days: string[]}>} */
    private function buckets(string $period, string $anchor): array
    {
        $keep = fn (array $days) => array_values(array_filter($days, fn ($d) => $this->inRange($d)));
        if ($period === 'day') {
            return ['title' => Days::fromKey($anchor)->translatedFormat('l j F'), 'buckets' => [['label' => Days::fromKey($anchor)->translatedFormat('j M'), 'days' => $keep([$anchor])]]];
        }
        if ($period === 'week') {
            $start = Days::key(Days::mondayOf(Days::fromKey($anchor)));
            $b = [];
            for ($i = 0; $i < 7; $i++) {
                $k = Days::add($start, $i);
                $b[] = ['label' => Days::fromKey($k)->translatedFormat('D'), 'days' => $keep([$k])];
            }
            return ['title' => Days::short(Days::fromKey($start)) . ' – ' . Days::short(Days::fromKey(Days::add($start, 6))), 'buckets' => $b];
        }
        if ($period === 'month') {
            $start = substr($anchor, 0, 7) . '-01';
            $b = [];
            for ($i = 0; $i < Days::fromKey($start)->daysInMonth; $i++) {
                $b[] = ['label' => (string) ($i + 1), 'days' => $keep([Days::add($start, $i)])];
            }
            return ['title' => Days::fromKey($start)->translatedFormat('F Y'), 'buckets' => $b];
        }
        $year = substr($anchor, 0, 4);
        $b = [];
        for ($m = 1; $m <= 12; $m++) {
            $start = sprintf('%s-%02d-01', $year, $m);
            $days = array_map(fn ($i) => Days::add($start, $i), range(0, Days::fromKey($start)->daysInMonth - 1));
            $b[] = ['label' => Days::fromKey($start)->translatedFormat('M'), 'days' => $keep($days)];
        }
        return ['title' => $year, 'buckets' => $b];
    }

    private function previousAnchor(string $period, string $anchor): string
    {
        return match ($period) {
            'day' => Days::add($anchor, -1),
            'week' => Days::add($anchor, -7),
            'month' => Days::add(substr($anchor, 0, 7) . '-01', -1),
            default => ((int) substr($anchor, 0, 4) - 1) . '-01-01',
        };
    }

    private function sum(array $days, string $meal, array $lookup): array
    {
        $byType = [];
        foreach (array_keys(self::MEALS) as $t) {
            $byType[$t] = ['booked' => 0, 'attended' => 0, 'noShows' => 0];
            foreach ($days as $d) {
                $booked = $lookup[$d][$t]['booked'] ?? 0;
                $missed = min($booked, $lookup[$d][$t]['missed'] ?? 0);
                $byType[$t]['booked'] += $booked;
                $byType[$t]['noShows'] += $missed;
                $byType[$t]['attended'] += $booked - $missed;
            }
        }
        $types = $meal === 'all' ? array_keys(self::MEALS) : [$meal];
        $total = ['booked' => 0, 'attended' => 0, 'noShows' => 0];
        foreach ($types as $t) {
            foreach ($total as $k => $_) {
                $total[$k] += $byType[$t][$k];
            }
        }
        return ['total' => $total, 'byType' => $byType];
    }

    private static function pct(float $part, float $whole): int
    {
        return $whole ? (int) round($part / $whole * 100) : 0;
    }

    private function rate(array $s): int
    {
        return self::pct($s['attended'], $s['booked']);
    }

    private function noShowRate(array $s): int
    {
        return self::pct($s['noShows'], $s['booked']);
    }

    /** The dish served per day and meal: [date][meal] => title. */
    private function dishes(string $end): array
    {
        $out = [];
        foreach (DB::table('meun')->where('day', '>=', self::START . ' 00:00:00.000')->where('day', '<=', "$end 23:59:59.999")->get() as $r) {
            $day = Days::keyOf($r->day);
            foreach (['breakfast' => 'brakfast_title', 'lunch' => 'lunch_title', 'dinner' => 'Dinner_title'] as $meal => $column) {
                if (trim((string) $r->{$column}) !== '') {
                    $out[$day][$meal] = trim((string) $r->{$column});
                }
            }
        }
        return $out;
    }

    /**
     * Data of the eight insight tiles: In short, Kitchen plan, Kitchen quality, Discipline, Attendance, Menu,
     * Feedback and Booking behaviour (port of the React insights.tsx).
     */
    private function tiles(array $f, array $all, array $items, ?array $prevItems, array $days, $employees, $allSpecials, array $dish, array $prices, \Closure $inMeal): array
    {
        $rules = BookingRules::current();
        $today = Days::today();
        $daySet = array_flip($days);
        $reports = DB::table('reports')->get()->map(fn ($r) => [
            'created' => Days::keyOf($r->created),
            'meal' => ['brakfast' => 'breakfast', 'lunch' => 'lunch', 'dinner' => 'dinner'][(string) $r->meal] ?? null,
            'mealDate' => $r->meal_date ? Days::keyOf($r->meal_date) : '',
            'answered' => trim((string) $r->reply) !== '',
        ])->all();
        $meals = array_keys(self::MEALS);

        // ---- In short
        $count = count($items);
        $missed = S::missedCount($items);
        $rate = S::ratio($count - $missed, $count);
        $prevRate = $prevItems ? S::ratio(count($prevItems) - S::missedCount($prevItems), count($prevItems)) : null;
        $byDept = [];
        foreach ($items as $i) {
            if ($i['missed']) {
                $byDept[$i['dept']] = ($byDept[$i['dept']] ?? 0) + 1;
            }
        }
        arsort($byDept);
        $topDept = $byDept ? array_key_first($byDept) : null;
        $standings = $employees->map(fn ($u) => ['u' => $u, 's' => Standing::read($u, false, $rules)])->values();
        $atRisk = $standings->filter(fn ($x) => $x['s']['blocked_until'] === '' && $x['s']['strikes'] === $rules->maxNoShow - 1)->count();
        $blocked = $standings->filter(fn ($x) => $x['s']['blocked_until'] !== '')->count();
        $unanswered = count(array_filter($reports, fn ($r) => !$r['answered']));
        $quality = $rate >= 0.9 ? t('Almost every booked meal was eaten') : ($rate >= 0.7 ? t('Most booked meals were eaten') : ($rate >= 0.4 ? t('Many booked meals were missed') : t('Most booked meals were missed')));
        $trend = $prevRate === null ? '' : ($rate > $prevRate + 0.05 ? t(', better than before') : ($rate < $prevRate - 0.05 ? t(', worse than before') : t(', about the same as before')));
        $where = $topDept !== null && $missed ? t(' — the misses come mostly from {0}.', [0 => $topDept !== '' ? t('Department {0}', [0 => $topDept]) : t('people without a department')]) : '.';
        $inShort = [
            'sentence' => $count ? $quality . $trend . $where : t('No meals were booked in this period.'),
            'eaten' => $count - $missed,
            'missed' => $missed,
            'attention' => array_values(array_filter([
                $atRisk ? [$atRisk === 1 ? t('Someone is one no-show away from a booking pause') : t('Several people are one no-show away from a booking pause'), '/users'] : null,
                $blocked ? [$blocked === 1 ? t('Someone is paused right now') : t('Some people are paused right now'), '/users'] : null,
                $unanswered ? [$unanswered === 1 ? t('A report is waiting for an answer') : t('Reports are waiting for an answer'), '/reports'] : null,
            ])),
        ];

        // ---- Kitchen plan: the next 7 days, exactly as booked (manager meals and special meals count too)
        $plan = [];
        for ($n = 1; $n <= 7; $n++) {
            $d = Days::add($today, $n);
            $manager = ManagerMeals::on($d, array_keys($dish[$d] ?? []));
            $layers = array_map(fn ($m) => count(array_filter($all, fn ($i) => $i['date'] === $d && $i['meal'] === $m)) + ($manager[$m] ?? 0), $meals);
            $plan[] = [
                'key' => $d,
                'label' => Days::fromKey($d)->translatedFormat('D j'),
                'layers' => $layers,
                'extra' => (int) $allSpecials->where('date', $d)->sum('count'),
            ];
        }

        // ---- Attendance: 8 weeks, teams x meals, weekdays x meals
        $lastWeek = Days::key(Days::mondayOf(Days::fromKey($f['anchor'])));
        $weekly = [];
        for ($w = 0; $w < 8; $w++) {
            $start = Days::add($lastWeek, ($w - 7) * 7);
            $end = Days::add($start, 7);
            $inWeek = array_values(array_filter($all, fn ($i) => $inMeal($i['meal']) && $i['date'] >= $start && $i['date'] < $end && $i['date'] <= $today));
            if ($inWeek) {
                $weekly[] = ['key' => $start, 'label' => Days::fromKey($start)->translatedFormat('j M'), 'volume' => count($inWeek), 'rate' => S::ratio(count($inWeek) - S::missedCount($inWeek), count($inWeek))];
            }
        }
        $depts = array_values(array_unique(array_merge(S::DEPARTMENTS, array_column($items, 'dept'))));
        $cell = function (array $list) {
            return $list ? ['size' => count($list), 'rate' => S::ratio(S::missedCount($list), count($list))] : null;
        };
        $mealRows = array_map(fn ($m) => ['key' => $m, 'name' => t(ucfirst($m))], $meals);
        $teamGrid = ['rows' => $mealRows, 'cols' => array_map(fn ($d) => ['key' => $d, 'name' => $d !== '' ? t('Dept {0}', [0 => $d]) : t('No dept')], $depts), 'cells' => []];
        foreach ($meals as $m) {
            foreach ($depts as $d) {
                $teamGrid['cells'][$m][$d] = $cell(array_values(array_filter($items, fn ($i) => $i['meal'] === $m && $i['dept'] === $d)));
            }
        }
        $dayGrid = ['rows' => $mealRows, 'cols' => array_map(fn ($n, $name) => ['key' => (string) $n, 'name' => t($name)], range(0, 6), ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']), 'cells' => []];
        foreach ($meals as $m) {
            for ($n = 0; $n < 7; $n++) {
                $dayGrid['cells'][$m][(string) $n] = $cell(array_values(array_filter($items, fn ($i) => $i['meal'] === $m && Days::fromKey($i['date'])->dayOfWeekIso - 1 === $n)));
            }
        }

        // ---- Discipline: the strike ladder
        $max = $rules->maxNoShow;
        $name = fn ($u) => trim($u->firstname . ' ' . $u->lastname) ?: (string) $u->email;
        $steps = [];
        for ($n = 0; $n <= $max; $n++) {
            $steps[] = [
                'label' => $n === $max ? t('Paused') : ($n === 0 ? t('No strikes') : ($n === 1 ? t('1 strike') : t('{0} strikes', [0 => $n]))),
                'people' => $standings->filter(fn ($x) => $n === $max ? ($x['s']['strikes'] >= $max || $x['s']['blocked_until'] !== '') : ($x['s']['strikes'] === $n && $x['s']['blocked_until'] === ''))
                    ->map(fn ($x) => ['id' => $x['u']->id, 'name' => $name($x['u'])])->values()->all(),
            ];
        }
        $paused = $standings->filter(fn ($x) => $x['s']['blocked_until'] !== '')->map(fn ($x) => ['name' => $name($x['u']), 'until' => $x['s']['blocked_until']])->values()->all();

        // ---- Menu: dish cards
        $groups = [];
        foreach ($items as $i) {
            if ($i['dish'] !== '') {
                $groups[$i['meal'] . '|' . $i['dish']][] = $i;
            }
        }
        $dishes = [];
        foreach ($groups as $k => $list) {
            [$meal, $dishName] = explode('|', (string) $k, 2);
            $served = array_flip(array_unique(array_column($list, 'date')));
            $complaints = count(array_filter($reports, fn ($r) => $r['meal'] === $meal && isset($served[$r['mealDate']])));
            $dishes[] = ['key' => (string) $k, 'meal' => $meal, 'dish' => $dishName, 'perServing' => S::ratio(count($list), count($served)), 'eaten' => S::ratio(count($list) - S::missedCount($list), count($list)), 'complaints' => $complaints, 'meals' => count($list)];
        }
        usort($dishes, fn ($a, $b) => $b['perServing'] <=> $a['perServing']);
        $weakestPool = array_values(array_filter($dishes, fn ($d) => $d['meals'] >= 5));
        usort($weakestPool, fn ($a, $b) => ($a['eaten'] - $b['eaten'] - ($b['complaints'] - $a['complaints']) * 0.1) <=> 0);
        $menu = ['dishes' => $dishes, 'top' => max(1, ...array_merge([1], array_column($dishes, 'perServing'))), 'weakest' => $weakestPool[0]['key'] ?? null];

        // ---- Booking behaviour
        $dayCount = count(array_unique(array_column($items, 'date')));
        $perPerson = [];
        foreach ($items as $i) {
            $perPerson[$i['user']][$i['date']] = true;
        }
        $regular = count(array_filter($perPerson, fn ($s) => count($s) >= $dayCount / 2));
        $booking = ['leads' => array_column($items, 'lead'), 'deadlineDays' => $rules->deadlineDaysBefore, 'regular' => $regular, 'occasional' => count($perPerson) - $regular];

        // ---- Kitchen quality: grade
        $bill = S::compute('cost', $items, $prices);
        $own = array_values(array_filter(PenaltyController::rows(), fn ($p) => isset($daySet[$p['date']])));
        $penaltyTotal = array_sum(array_column($own, 'amount'));
        $share = S::ratio($penaltyTotal, $bill);
        $grades = [[0.01, 'A', 'Excellent'], [0.03, 'B', 'Good'], [0.05, 'C', 'Fair'], [0.1, 'D', 'Poor'], [INF, 'E', 'Failing']];
        $grade = null;
        if ($bill) {
            foreach ($grades as [$limit, $letter, $text]) {
                if ($share < $limit) {
                    $grade = ['letter' => $letter, 'text' => $text];
                    break;
                }
            }
        }
        $parts = [];
        foreach ([1, 2, 3, 4] as $section) {
            $parts[] = ['label' => PenaltyRules::SECTION_TITLE[$section], 'value' => array_sum(array_map(fn ($p) => $p['section'] === $section ? $p['amount'] : 0, $own))];
        }
        $inPeriodReports = array_values(array_filter($reports, fn ($r) => isset($daySet[$r['created']])));
        $kq = ['grade' => $grade, 'share' => $share, 'parts' => $parts, 'complaints' => count($inPeriodReports)];

        // ---- Feedback
        $feedRows = [];
        foreach (array_merge($meals, [null]) as $m) {
            $list = array_values(array_filter($inPeriodReports, fn ($r) => $m === null ? ($r['meal'] === null || $r['mealDate'] === '') : ($r['meal'] === $m && $r['mealDate'] !== '')));
            $feedRows[] = ['label' => $m === null ? t('Other') : t(ucfirst($m)), 'answered' => count(array_filter($list, fn ($r) => $r['answered'])), 'waiting' => count(array_filter($list, fn ($r) => !$r['answered']))];
        }

        return [
            'inShort' => $inShort,
            'plan' => $plan,
            'weekly' => $weekly,
            'teamGrid' => $teamGrid,
            'dayGrid' => $dayGrid,
            'steps' => $steps,
            'paused' => $paused,
            'maxStrikes' => $max,
            'periodDays' => $rules->periodDays,
            'menu' => $menu,
            'booking' => $booking,
            'quality' => $kq,
            'feedback' => ['rows' => $feedRows, 'count' => count($inPeriodReports)],
        ];
    }

    private function compute(array $f): array
    {
        $today = Days::today();
        $end = Days::add($today, self::WINDOW_DAYS - 1);
        $users = DB::table('user')->get()->keyBy('id');
        $isStaff = fn ($type) => in_array(Roles::normalize($type), ['admin', 'super_admin', 'door'], true);

        // Orders after the page filters, and the meal items they give.
        $rows = [];
        $lookup = [];
        $items = [];
        $dish = $this->dishes($end);
        foreach (DB::table('oder')->where('date', '>=', self::START . ' 00:00:00.000')->where('date', '<=', "$end 23:59:59.999")->get() as $o) {
            $u = $users->get($o->user);
            $dept = (string) ($u->Department ?? '');
            $sex = ($u->sex ?? 0) ? 'female' : 'male';
            if (($f['department'] && $dept !== $f['department']) || ($f['sex'] && $sex !== $f['sex']) || (!$f['admins'] && $isStaff($u->User_Type ?? ''))) {
                continue;
            }
            $meals = array_keys(array_filter(self::MEALS, fn ($c) => (int) $o->{$c} === 1));
            if (!$meals) {
                continue;
            }
            $missed = array_values(array_filter($meals, fn ($m) => (int) $o->{'no_show_' . $m} === 1));
            $date = Days::keyOf($o->date);
            $rows[] = ['user' => (string) $o->user, 'date' => $date, 'dept' => $dept, 'meals' => $meals, 'missed' => $missed];
            foreach ($meals as $m) {
                $items[] = [
                    'date' => $date, 'user' => (string) $o->user, 'dept' => $dept, 'sex' => $sex, 'position' => (string) ($u->Position ?? ''),
                    'type' => (string) ($u->User_Type ?? ''), 'meal' => $m, 'dish' => $dish[$date][$m] ?? '', 'missed' => in_array($m, $missed, true),
                    'lead' => S::leadDays($date, (string) $o->created),
                ];
                $lookup[$date][$m]['booked'] = ($lookup[$date][$m]['booked'] ?? 0) + 1;
                if (in_array($m, $missed, true)) {
                    $lookup[$date][$m]['missed'] = ($lookup[$date][$m]['missed'] ?? 0) + 1;
                }
            }
        }

        ['title' => $title, 'buckets' => $buckets] = $this->buckets($f['period'], $f['anchor']);
        $allDays = array_merge(...array_map(fn ($b) => $b['days'], $buckets ?: [['days' => []]]));
        $now = $this->sum($allDays, $f['meal'], $lookup);
        $allMeals = $this->sum($allDays, 'all', $lookup)['total'];

        $prevBuckets = $this->buckets($f['period'], $this->previousAnchor($f['period'], $f['anchor']))['buckets'];
        $prevDays = array_slice(array_merge(...array_map(fn ($b) => $b['days'], $prevBuckets ?: [['days' => []]])), 0, count($allDays));
        $prev = $prevDays ? $this->sum($prevDays, $f['meal'], $lookup) : null;
        $prevAll = $prevDays ? $this->sum($prevDays, 'all', $lookup)['total'] : null;

        // Chart: the period's buckets (a single day shows the 14 days ending on it).
        $chartBuckets = $f['period'] === 'day'
            ? array_map(fn ($i) => ['label' => Days::fromKey(Days::add($f['anchor'], $i - 13))->translatedFormat('j M'), 'days' => array_values(array_filter([Days::add($f['anchor'], $i - 13)], fn ($d) => $this->inRange($d)))], range(0, 13))
            : $buckets;
        $chart = array_map(function ($b) use ($f, $lookup) {
            $s = $this->sum($b['days'], $f['meal'], $lookup);
            return $b + ['byType' => $s['byType'], 'focus' => $s['total'], 'stack' => array_sum(array_map(fn ($t) => $s['byType'][$t][$f['metric']], array_keys(self::MEALS)))];
        }, $chartBuckets);
        $chartMax = max(1, ...array_column($chart, 'stack'));
        $withData = array_values(array_filter($chart, fn ($c) => $c['focus']['booked'] > 0));
        $avgMetric = $withData ? array_sum(array_map(fn ($c) => $c['focus'][$f['metric']], $withData)) / count($withData) : 0;
        $peak = null;
        foreach ($withData as $c) {
            if (!$peak || $c['focus'][$f['metric']] > $peak['focus'][$f['metric']]) {
                $peak = $c;
            }
        }
        $half = intdiv(count($withData), 2);
        $avgOf = fn (array $list) => array_sum(array_map(fn ($c) => $c['focus']['booked'], $list)) / max(count($list), 1);
        $trend = count($withData) >= 4 ? self::pct($avgOf(array_slice($withData, $half)) - $avgOf(array_slice($withData, 0, $half)), $avgOf(array_slice($withData, 0, $half))) : null;
        $path = '';
        foreach ($chart as $i => $c) {
            if (!$c['focus']['booked']) {
                continue;
            }
            $x = number_format(($i + 0.5) / count($chart) * 1000, 1, '.', '');
            $y = 100 - $this->rate($c['focus']);
            $path .= ($path !== '' && ($chart[$i - 1]['focus']['booked'] ?? 0) ? 'L' : 'M') . "{$x},{$y} ";
        }

        // Weekly rhythm (short periods look back 12 weeks).
        $patternDays = in_array($f['period'], ['day', 'week'], true)
            ? array_values(array_filter(array_map(fn ($i) => Days::add($f['anchor'], -83 + $i), range(0, 83)), fn ($d) => $this->inRange($d)))
            : $allDays;
        $weekdays = [];
        $monday = Days::key(Days::mondayOf(Days::now()));
        for ($i = 0; $i < 7; $i++) {
            $sample = Days::add($monday, $i);
            $dow = Days::fromKey($sample)->dayOfWeekIso;
            $days = array_values(array_filter($patternDays, fn ($d) => Days::fromKey($d)->dayOfWeekIso === $dow));
            $s = $this->sum($days, 'all', $lookup);
            $weekdays[] = ['label' => Days::fromKey($sample)->translatedFormat('D'), 'count' => count($days), 'byType' => $s['byType'], 'total' => $s['total']];
        }
        $heatMax = 1;
        foreach ($weekdays as $w) {
            foreach (array_keys(self::MEALS) as $t) {
                $heatMax = max($heatMax, $w['count'] ? $w['byType'][$t]['booked'] / $w['count'] : 0);
            }
        }

        // Automatic insights.
        $insights = [];
        $ranked = array_keys(array_filter($now['byType'], fn ($s) => $s['booked'] > 0));
        usort($ranked, fn ($a, $b) => $now['byType'][$b]['booked'] <=> $now['byType'][$a]['booked']);
        if ($ranked) {
            $insights[] = ['neutral', t('{0} is the main meal: {1}% of bookings.', [0 => t(self::LABEL[$ranked[0]]), 1 => self::pct($now['byType'][$ranked[0]]['booked'], $allMeals['booked'])])];
            $worst = $ranked;
            usort($worst, fn ($a, $b) => $this->noShowRate($now['byType'][$b]) <=> $this->noShowRate($now['byType'][$a]));
            $w = $worst[0];
            if ($now['byType'][$w]['noShows']) {
                $insights[] = [$this->noShowRate($now['byType'][$w]) >= 10 ? 'warn' : 'neutral', t('{0} loses the most: {1}% no-shows ({2} meals).', [0 => t(self::LABEL[$w]), 1 => $this->noShowRate($now['byType'][$w]), 2 => $now['byType'][$w]['noShows']])];
            }
        }
        $daysRanked = array_values(array_filter($weekdays, fn ($w) => $w['total']['booked']));
        usort($daysRanked, fn ($a, $b) => $this->rate($a['total']) <=> $this->rate($b['total']));
        if (count($daysRanked) >= 2 && $this->rate(end($daysRanked)['total']) - $this->rate($daysRanked[0]['total']) >= 5) {
            $insights[] = ['warn', t('{0} has the weakest attendance ({1}%).', [0 => $daysRanked[0]['label'], 1 => $this->rate($daysRanked[0]['total'])])];
        }
        if ($trend !== null && abs($trend) >= 5) {
            $insights[] = [$trend > 0 ? 'good' : 'neutral', $trend > 0 ? t('Bookings are trending up {0}% over the period.', [0 => abs($trend)]) : t('Bookings are trending down {0}% over the period.', [0 => abs($trend)])];
        }
        if ($prev && $prev['total']['booked'] && $now['total']['booked']) {
            $diff = $this->noShowRate($now['total']) - $this->noShowRate($prev['total']);
            if (abs($diff) >= 2) {
                $insights[] = [$diff > 0 ? 'warn' : 'good', $diff > 0 ? t('No-show rate rose {0} pts vs the previous period.', [0 => abs($diff)]) : t('No-show rate fell {0} pts vs the previous period.', [0 => abs($diff)])];
            }
        }

        // Who didn't show up in the period.
        $periodSet = array_flip($allDays);
        $people = [];
        foreach ($rows as $r) {
            if (!isset($periodSet[$r['date']])) {
                continue;
            }
            $missed = $f['meal'] === 'all' ? $r['missed'] : array_values(array_intersect($r['missed'], [$f['meal']]));
            if (!$missed) {
                continue;
            }
            $u = $users->get($r['user']);
            $people[$r['user']] ??= ['name' => $u ? (trim($u->firstname . ' ' . $u->lastname) ?: (string) $u->email) : t('Unknown user'), 'email' => (string) ($u->email ?? ''), 'entries' => [], 'meals' => 0];
            $people[$r['user']]['entries'][] = ['date' => $r['date'], 'meals' => $missed];
            $people[$r['user']]['meals'] += count($missed);
        }
        uasort($people, fn ($a, $b) => [$b['meals'], $a['name']] <=> [$a['meals'], $b['name']]);

        // Special meals, prices, penalties: money and departments.
        $prices = Settings::prices();
        $invoice = Settings::invoice();
        $kinds = MealController::SPECIAL_KINDS;
        $allSpecials = DB::table('special_meals')->where('Day', '>=', self::START . ' 00:00:00.000')->where('Day', '<=', "$end 23:59:59.999")->get()
            ->map(fn ($s) => ['date' => Days::keyOf($s->Day), 'meal' => strtolower($s->Meal) === 'dinner' ? 'dinner' : 'lunch', 'kind' => array_search($s->type, $kinds, true) ?: 'enhanced', 'count' => (int) $s->How_many]);
        $specials = $allSpecials
            ->filter(fn ($s) => isset($periodSet[$s['date']]) && ($f['meal'] === 'all' || $s['meal'] === $f['meal']));
        $mealsOf = fn ($r) => $f['meal'] === 'all' ? $r['meals'] : array_values(array_intersect($r['meals'], [$f['meal']]));
        $inPeriod = array_values(array_filter($rows, fn ($r) => isset($periodSet[$r['date']])));
        $mealsCost = 0.0;
        $wasteCost = 0.0;
        $wasteMeals = 0;
        foreach ($inPeriod as $r) {
            foreach ($mealsOf($r) as $m) {
                $mealsCost += $prices['meals'][$m];
                if (in_array($m, $r['missed'], true)) {
                    $wasteCost += $prices['meals'][$m];
                    $wasteMeals++;
                }
            }
        }
        $specialsCost = $specials->sum(fn ($s) => $s['count'] * $prices['specials'][$s['kind']]);
        $penaltyTotal = collect(PenaltyController::rows())->filter(fn ($p) => isset($periodSet[$p['date']]) && ($f['meal'] === 'all' || $p['meal'] === $f['meal']))->sum('amount');
        $bookers = count(array_unique(array_column(array_filter($inPeriod, fn ($r) => $mealsOf($r)), 'user')));
        $ahead = array_flip(array_map(fn ($i) => Days::add($today, $i), range(1, 7)));
        $forecast = 0.0;
        foreach ($rows as $r) {
            if (isset($ahead[$r['date']])) {
                foreach (array_diff($mealsOf($r), $r['missed']) as $m) {
                    $forecast += $prices['meals'][$m];
                }
            }
        }

        $employees = $users->filter(fn ($u) => (int) $u->Status === 1 && !$isStaff($u->User_Type));
        $weeks = max(count($allDays) / 7, 1 / 7);
        $departments = [];
        foreach (['1', '2', '3'] as $d) {
            $own = array_filter($inPeriod, fn ($r) => $r['dept'] === $d && $mealsOf($r));
            $meals = array_sum(array_map(fn ($r) => count($mealsOf($r)), $own));
            $who = count(array_unique(array_column($own, 'user')));
            $departments[] = [
                'department' => $d,
                'headcount' => $employees->where('Department', $d)->count(),
                'bookers' => $who,
                'meals' => $meals,
                'perPersonWeek' => $who ? $meals / $who / $weeks : 0,
                'noShows' => array_sum(array_map(fn ($r) => count(array_intersect($mealsOf($r), $r['missed'])), $own)),
                'cost' => array_sum(array_map(fn ($r) => array_sum(array_map(fn ($m) => $prices['meals'][$m], $mealsOf($r))), $own)),
            ];
        }

        // The insight tiles and Explore work on the meal items.
        $inMeal = fn (string $m) => $f['meal'] === 'all' || $m === $f['meal'];
        $periodItems = array_values(array_filter($items, fn ($i) => isset($periodSet[$i['date']]) && $inMeal($i['meal'])));
        $prevSet = array_flip($prevDays);
        $prevItems = $prevDays ? array_values(array_filter($items, fn ($i) => isset($prevSet[$i['date']]) && $inMeal($i['meal']))) : null;
        $tiles = $this->tiles($f, $items, $periodItems, $prevItems, $allDays, $employees, $allSpecials, $dish, $prices['meals'], $inMeal);
        $savedViews = Settings::get('explore_views_' . $f['uid']);
        $explore = Explore::run($f['explore'], $periodItems, $prevItems, $prices['meals'], $invoice['currency']);

        return [
            'tiles' => $tiles,
            'explore' => $explore,
            'savedViews' => $savedViews,
            'title' => $title,
            'buckets' => $buckets,
            'lookup' => $lookup,
            'now' => $now,
            'prev' => $prev,
            'prevAll' => $prevAll,
            'allMeals' => $allMeals,
            'chart' => $chart,
            'chartMax' => $chartMax,
            'avgMetric' => $avgMetric,
            'peak' => $peak,
            'ratePath' => trim($path),
            'weekdays' => $weekdays,
            'heatMax' => $heatMax,
            'insights' => $insights,
            'people' => array_values($people),
            'specialByKind' => collect($kinds)->map(fn ($label, $kind) => ['label' => $label, 'count' => $specials->where('kind', $kind)->sum('count')])->values()->all(),
            'specialByMeal' => [['label' => 'Lunch', 'count' => $specials->where('meal', 'lunch')->sum('count')], ['label' => 'Dinner', 'count' => $specials->where('meal', 'dinner')->sum('count')]],
            'currency' => $invoice['currency'],
            'vat' => (float) $invoice['vat'],
            'cost' => compact('mealsCost', 'wasteCost', 'wasteMeals', 'specialsCost', 'penaltyTotal', 'forecast') + ['bookers' => $bookers, 'net' => $mealsCost + $specialsCost - $penaltyTotal],
            'departments' => $departments,
            'hasDays' => $allDays !== [],
        ];
    }
}
