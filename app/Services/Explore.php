<?php

namespace App\Services;

use App\Services\StatsItems as S;

/**
 * Statistics → Explore: pick a measure, split it, and compare it with the previous period or between two groups
 * (port of the React explore.tsx). The "view" lives in the address (x_* query values), so it needs no script.
 */
class Explore
{
    /** The ready-made views, as shown by the chips. */
    public const PRESETS = [
        ['name' => 'No-shows by department', 'metric' => 'noShowRate', 'split' => 'department', 'mode' => 'previous', 'cdim' => 'sex', 'a' => '', 'b' => ''],
        ['name' => 'Women vs men by meal', 'metric' => 'meals', 'split' => 'meal', 'mode' => 'groups', 'cdim' => 'sex', 'a' => 'female', 'b' => 'male'],
        ['name' => 'Cost by department', 'metric' => 'cost', 'split' => 'department', 'mode' => 'none', 'cdim' => 'sex', 'a' => '', 'b' => ''],
        ['name' => 'Popular dishes', 'metric' => 'meals', 'split' => 'dish', 'mode' => 'none', 'cdim' => 'sex', 'a' => '', 'b' => ''],
        ['name' => 'Weekday pattern', 'metric' => 'meals', 'split' => 'weekday', 'mode' => 'previous', 'cdim' => 'sex', 'a' => '', 'b' => ''],
    ];

    /** The view asked for in the address; the first ready-made view when none (or a broken one). */
    public static function viewFrom(array $query): array
    {
        $view = self::PRESETS[0];
        if (!isset($query['x_metric'])) {
            return $view;
        }
        $metric = (string) $query['x_metric'];
        $split = (string) ($query['x_split'] ?? 'none');
        $mode = (string) ($query['x_mode'] ?? 'none');
        $cdim = (string) ($query['x_cdim'] ?? 'sex');
        return [
            'name' => (string) ($query['x_name'] ?? ''),
            'metric' => isset(S::METRICS[$metric]) ? $metric : $view['metric'],
            'split' => $split === 'none' || isset(S::DIMENSIONS[$split]) ? $split : 'none',
            'mode' => in_array($mode, ['none', 'previous', 'groups'], true) ? $mode : 'none',
            'cdim' => isset(S::DIMENSIONS[$cdim]) ? $cdim : 'sex',
            'a' => (string) ($query['x_a'] ?? ''),
            'b' => (string) ($query['x_b'] ?? ''),
        ];
    }

    /** The view as address values. */
    public static function toQuery(array $view): array
    {
        return ['x_metric' => $view['metric'], 'x_split' => $view['split'], 'x_mode' => $view['mode'], 'x_cdim' => $view['cdim'], 'x_a' => $view['a'], 'x_b' => $view['b'], 'x_name' => $view['name']];
    }

    /** "Cost by department · Women vs men" — the name a saved view gets. */
    public static function nameOf(array $v): string
    {
        $by = $v['split'] === 'none' ? t('everyone') : mb_strtolower(S::dimLabel($v['split']));
        $name = t('{0} by {1}', [0 => S::metricLabel($v['metric']), 1 => $by]);
        if ($v['mode'] === 'groups') {
            $name .= ' · ' . S::dimName($v['cdim'], $v['a']) . ' ' . t('vs') . ' ' . S::dimName($v['cdim'], $v['b']);
        } elseif ($v['mode'] === 'previous') {
            $name .= ' · ' . t('vs before');
        }
        return $name;
    }

    /**
     * @param  array  $items      the period's items
     * @param  ?array $prevItems  the previous period's items (null when there is none)
     * @param  array  $prices     meal => price
     * @return array{data: array, labels: ?array, headline: string, ordered: bool, choices: array, unit: string, metricLabel: string, splitLabel: ?string, compareDim: string}
     */
    public static function run(array $v, array $items, ?array $prevItems, array $prices, string $currency): array
    {
        [$metricLabel, , $unit] = S::METRICS[$v['metric']];
        $metricLabel = t($metricLabel);
        $fmt = fn (float $n) => S::format($n, $unit, $currency);
        $splitting = $v['split'] !== 'none';
        $ordered = $splitting && S::DIMENSIONS[$v['split']][1];
        $compute = fn (array $list) => S::compute($v['metric'], $list, $prices);
        $groupsOf = fn (array $list) => $splitting
            ? S::splitBy($list, $v['split'], $v['metric'], $prices)
            : [['key' => 'all', 'name' => t('Everyone'), 'value' => $compute($list), 'meals' => count($list), 'people' => S::people($list), 'hidden' => false, 'small' => count($list) < 10]];

        $data = [];
        $labels = null;
        $overall = $compute($items);

        if ($v['mode'] === 'none') {
            foreach ($groupsOf($items) as $g) {
                $data[] = ['key' => $g['key'], 'name' => $g['name'], 'value' => $g['value'], 'hidden' => $g['hidden'], 'small' => $g['small'], 'note' => t('{0} meals · {1} people', [0 => $g['meals'], 1 => $g['people']])];
            }
            $headline = t('{0}: {1} overall', [0 => $metricLabel, 1 => $fmt($overall)]);
        } elseif ($v['mode'] === 'previous') {
            $now = collect($groupsOf($items))->keyBy('key');
            $before = collect($prevItems ? $groupsOf($prevItems) : [])->keyBy('key');
            foreach ($now->keys()->merge($before->keys())->unique() as $k) {
                $n = $now->get($k);
                $b = $before->get($k);
                $data[] = [
                    'key' => (string) $k, 'name' => $n['name'] ?? $b['name'] ?? (string) $k, 'value' => $n['value'] ?? 0.0, 'compare' => $b['value'] ?? 0.0,
                    'hidden' => ($n['hidden'] ?? false) || ($b['hidden'] ?? false), 'small' => $n['small'] ?? false, 'note' => t('{0} meals now', [0 => $n['meals'] ?? 0]),
                ];
            }
            if (!$ordered) {
                usort($data, fn ($a, $b) => $b['value'] <=> $a['value']);
            }
            $labels = [t('This period'), t('Previous period')];
            $hasPrev = $prevItems && count($prevItems) > 0;
            $headline = $hasPrev
                ? t('{0}: {1} now vs {2} before', [0 => $metricLabel, 1 => $fmt($overall), 2 => $fmt($compute($prevItems))])
                : t('{0}: {1} now · no bookings in the previous period to compare with', [0 => $metricLabel, 1 => $fmt($overall)]);
            if (!$hasPrev) {
                $data = array_map(fn ($d) => array_diff_key($d, ['compare' => 1]), $data);
            }
        } else {
            $cdim = $v['cdim'];
            $aItems = array_values(array_filter($items, fn ($i) => S::dimOf($cdim, $i) === $v['a']));
            $bItems = array_values(array_filter($items, fn ($i) => S::dimOf($cdim, $i) === $v['b']));
            $tooSmall = S::DIMENSIONS[$cdim][2] && (S::people($aItems) < S::PRIVACY_MIN_PEOPLE || S::people($bItems) < S::PRIVACY_MIN_PEOPLE);
            $aName = S::dimName($cdim, $v['a']);
            $bName = S::dimName($cdim, $v['b']);
            $labels = [$aName, $bName];
            if ($v['a'] === '' || $v['b'] === '') {
                $headline = t('Choose two {0} values to compare.', [0 => mb_strtolower(S::dimLabel($cdim))]);
            } elseif ($tooSmall) {
                $headline = t('Hidden: one of the groups has fewer than {0} people, so the comparison could single someone out.', [0 => S::PRIVACY_MIN_PEOPLE]);
            } else {
                $same = $v['split'] === $cdim;
                $aGroups = $same ? collect() : collect($groupsOf($aItems))->keyBy('key');
                $bGroups = $same ? collect() : collect($groupsOf($bItems))->keyBy('key');
                if ($same) {
                    $data = [['key' => 'all', 'name' => t('Overall'), 'value' => $compute($aItems), 'compare' => $compute($bItems)]];
                } else {
                    foreach ($aGroups->keys()->merge($bGroups->keys())->unique() as $k) {
                        $a = $aGroups->get($k);
                        $b = $bGroups->get($k);
                        $data[] = [
                            'key' => (string) $k, 'name' => $a['name'] ?? $b['name'] ?? (string) $k, 'value' => $a['value'] ?? 0.0, 'compare' => $b['value'] ?? 0.0,
                            'small' => ($a['meals'] ?? 0) < 10 || ($b['meals'] ?? 0) < 10, 'note' => ($a['meals'] ?? 0) . ' / ' . ($b['meals'] ?? 0) . ' ' . t('meals'),
                        ];
                    }
                }
                $av = $compute($aItems);
                $bv = $compute($bItems);
                $sign = $av >= $bv ? '+' : '';
                $diff = $unit === 'percent' ? $sign . round(($av - $bv) * 100) . ' pts' : ($bv ? $sign . round(($av - $bv) / $bv * 100) . '%' : '');
                $headline = "{$aName}: {$fmt($av)} · {$bName}: {$fmt($bv)}" . ($diff !== '' ? " ({$diff})" : '');
            }
        }

        return [
            'data' => $data, 'labels' => $labels, 'headline' => $headline, 'ordered' => $ordered,
            'choices' => S::valuesOf($items, $v['cdim']), 'unit' => $unit, 'metricLabel' => $metricLabel,
            'splitLabel' => $splitting ? S::dimLabel($v['split']) : null, 'fmt' => $fmt,
        ];
    }
}
