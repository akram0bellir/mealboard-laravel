<?php

namespace App\Support;

/**
 * Contract penalties for the outsourced kitchen (the React app's lib/penalty-rules.ts). Percentages apply to the
 * amount of the service concerned: one meal service, i.e. a day's breakfast, lunch or dinner.
 */
class PenaltyRules
{
    public const SECTION_TITLE = [
        1 => 'Hygiene & safety KPIs',
        2 => 'Delivery delay',
        3 => 'Failure to meet ordered quantities',
        4 => 'Qualitative non-conformity of meals',
    ];

    /** Where each section is stored in `record_penalties`: [amount column, reason column]. */
    public const FIELDS = [
        1 => ['Hygiene_and_safety_KPIs', 'Hygiene_and_safety_KPIs_resone'],
        2 => ['Delivery_delay', 'Delivery_delay_resone'],
        3 => ['Failure_to_meet_ordered_quantities', 'Failure_to_meet_ordered_quantities_resone'],
        4 => ['Qualitative_nonconformity_of_meals', 'Qualitative_nonconformity_of_meals_resone'],
    ];

    /** Section 1: KPI breaches, each with a fixed penalty. */
    public const KPIS = [
        'tiac' => ['indicator' => 'Food poisoning outbreaks (TIAC)', 'impact' => 'Immediate crisis audit + action plan within 48 hours', 'percent' => 100],
        'microbiology' => ['indicator' => 'Compliance of microbiological analyses', 'impact' => 'Immediate crisis audit + action plan within 48 hours', 'percent' => 20],
        'haccp' => ['indicator' => 'Temperature recording (HACCP)', 'impact' => 'Written warning', 'percent' => 5],
        'waste' => ['indicator' => 'Waste management compliance', 'impact' => 'Written warning', 'percent' => 5],
        'uniform' => ['indicator' => 'Staff wearing of protective clothing/uniform', 'impact' => 'Immediate removal of non-compliant personnel', 'percent' => 10],
    ];

    /** Section 2: a delay with significant impact lets the client refuse the service, up to 100%. */
    public const MAX_SIGNIFICANT_DELAY_PERCENT = 100;

    public const DELAY_TIERS = [
        ['5 to 10 minutes', 2],
        ['11 to 15 minutes', 5],
        ['More than 15 minutes', 10],
        ['Significant impact (service refused)', 100],
    ];

    /** Section 3: shortfall tiers. Undelivered quantities are refunded on top. */
    public const SHORTFALL_TIERS = [
        'under5' => ['Less than 5% short', 5],
        '5to10' => ['5% to 10% short', 10],
        'over10' => ['More than 10% short', 20],
    ];

    /** Section 4: quality failures listed in the contract so far. The penalty % is set per case. */
    public const QUALITY_FAILURES = ['Insufficient or inappropriate taste quality', 'Other quality failure'];

    /** Section 2: delay in minutes -> penalty %. Under 5 minutes there is no penalty. */
    public static function delayPercent(int $minutes): int
    {
        return match (true) {
            $minutes > 15 => 10,
            $minutes >= 11 => 5,
            $minutes >= 5 => 2,
            default => 0,
        };
    }

    public static function round2(float $n): float
    {
        return round($n * 100) / 100;
    }
}
