<?php

namespace Tests\Unit;

use App\Services\Explore;
use App\Services\StatsItems as S;
use Tests\TestCase;

class StatsTest extends TestCase
{
    private const PRICES = ['breakfast' => 100.0, 'lunch' => 300.0, 'dinner' => 200.0];

    private function item(array $over = []): array
    {
        return array_merge(['date' => '2026-11-02', 'user' => 'u1', 'dept' => '1', 'sex' => 'male', 'position' => 'cook', 'type' => 'employee', 'meal' => 'lunch', 'dish' => 'Couscous', 'missed' => false, 'lead' => 2], $over);
    }

    private function items(): array
    {
        return [
            $this->item(['user' => 'u1']),
            $this->item(['user' => 'u1', 'meal' => 'dinner', 'missed' => true, 'lead' => 0]),
            $this->item(['user' => 'u2', 'sex' => 'female', 'dept' => '2']),
            $this->item(['user' => 'u3', 'missed' => true, 'lead' => 1]),
        ];
    }

    public function test_the_measures(): void
    {
        $i = $this->items();
        $c = fn (string $k) => S::compute($k, $i, self::PRICES);
        $this->assertSame(4.0, $c('meals'));
        $this->assertSame(3.0, $c('people'));
        $this->assertEqualsWithDelta(4 / 3, $c('perPerson'), 0.0001);
        $this->assertSame(0.5, $c('attendance'));
        $this->assertSame(0.5, $c('noShowRate'));
        $this->assertSame(2.0, $c('noShows'));
        $this->assertSame(1100.0, $c('cost'), '3 lunches + 1 dinner');
        $this->assertSame(500.0, $c('waste'), 'a missed dinner and a missed lunch');
        $this->assertSame(1.25, $c('leadTime'));
        $this->assertSame(0.5, $c('lastMinute'), '2 of 4 booked the day before or the same day');
        $this->assertSame(0.0, S::compute('attendance', [], self::PRICES), 'no division by zero');
    }

    public function test_lead_days(): void
    {
        $this->assertSame(2, S::leadDays('2026-11-06 12:00:00.000', '2026-11-04 09:00:00.000'));
        $this->assertSame(0, S::leadDays('2026-11-06 12:00:00.000', '2026-11-07 09:00:00.000'), 'never negative');
        $this->assertSame(0, S::leadDays('2026-11-06 12:00:00.000', ''));
    }

    public function test_formatting(): void
    {
        $this->assertSame('50%', S::format(0.5, 'percent', 'DZD'));
        $this->assertSame('1,234,567 DZD', S::format(1234567, 'money', 'DZD'));
        $this->assertSame('1.3', S::format(1.333, 'decimal', 'DZD'));
        $this->assertSame('4', S::format(3.6, 'count', 'DZD'));
    }

    public function test_splitting_hides_small_sensitive_groups(): void
    {
        $groups = collect(S::splitBy($this->items(), 'sex', 'meals', self::PRICES))->keyBy('key');
        $this->assertTrue($groups['male']['hidden'], 'fewer than 3 people: could single someone out');
        $this->assertTrue($groups['female']['hidden']);
        $byDept = collect(S::splitBy($this->items(), 'department', 'meals', self::PRICES))->keyBy('key');
        $this->assertFalse($byDept['1']['hidden'], 'department is not sensitive');
        $this->assertSame(3.0, $byDept['1']['value']);
        $this->assertTrue($byDept['1']['small'], 'under 10 meals: flagged as a small sample');
    }

    public function test_ordered_splits_keep_their_order_and_others_sort_by_value(): void
    {
        $byMeal = array_column(S::splitBy($this->items(), 'meal', 'meals', self::PRICES), 'key');
        $this->assertSame(['dinner', 'lunch'], $byMeal, 'meal is ordered by key');
        $values = array_column(S::splitBy($this->items(), 'department', 'meals', self::PRICES), 'value');
        $this->assertSame([3.0, 1.0], $values, 'department is sorted by value');
    }

    public function test_weekday_and_month_names(): void
    {
        $this->assertSame('Monday', S::dimName('weekday', '0'));
        $this->assertSame('0', S::dimOf('weekday', $this->item(['date' => '2026-11-02'])));
        $this->assertSame('2026-11', S::dimOf('month', $this->item()));
        $this->assertSame('3', S::dimOf('leadBand', $this->item(['lead' => 5])));
    }

    public function test_explore_view_parsing_falls_back_on_nonsense(): void
    {
        $this->assertSame(Explore::PRESETS[0]['metric'], Explore::viewFrom([])['metric']);
        $v = Explore::viewFrom(['x_metric' => 'nope', 'x_split' => 'bad', 'x_mode' => 'weird', 'x_cdim' => 'wrong']);
        $this->assertSame('noShowRate', $v['metric']);
        $this->assertSame('none', $v['split']);
        $this->assertSame('none', $v['mode']);
        $this->assertSame('sex', $v['cdim']);
    }

    public function test_explore_compares_with_the_previous_period(): void
    {
        $view = array_merge(Explore::PRESETS[0], ['metric' => 'meals', 'split' => 'department', 'mode' => 'previous']);
        $now = $this->items();
        $before = [$this->item(['user' => 'u9'])];
        $r = Explore::run($view, $now, $before, self::PRICES, 'DZD');
        $this->assertStringContainsString('4 now vs 1 before', $r['headline']);
        $this->assertSame('1', $r['data'][0]['key']);
        $this->assertSame(1.0, $r['data'][0]['compare']);
        $none = Explore::run($view, $now, null, self::PRICES, 'DZD');
        $this->assertStringContainsString('no bookings in the previous period', $none['headline']);
        $this->assertArrayNotHasKey('compare', $none['data'][0]);
    }

    public function test_explore_two_groups_and_the_privacy_rule(): void
    {
        $items = [];
        foreach (range(1, 4) as $n) {
            $items[] = $this->item(['user' => "m$n", 'sex' => 'male']);
            $items[] = $this->item(['user' => "f$n", 'sex' => 'female', 'meal' => 'dinner']);
        }
        $view = array_merge(Explore::PRESETS[1], ['metric' => 'meals', 'split' => 'meal', 'mode' => 'groups', 'cdim' => 'sex', 'a' => 'female', 'b' => 'male']);
        $r = Explore::run($view, $items, null, self::PRICES, 'DZD');
        $this->assertStringContainsString('Women: 4 · Men: 4', $r['headline']);
        // two people per group is too few for a sensitive split
        $small = Explore::run($view, [$this->item(['user' => 'a', 'sex' => 'male']), $this->item(['user' => 'b', 'sex' => 'female'])], null, self::PRICES, 'DZD');
        $this->assertStringContainsString('Hidden', $small['headline']);
        $this->assertSame([], $small['data'], 'and no numbers are returned');
    }
}
