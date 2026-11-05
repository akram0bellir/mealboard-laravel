@php
    use App\Support\Days;
    $meals = ['breakfast', 'lunch', 'dinner'];
    $icons = ['breakfast' => 'coffee', 'lunch' => 'soup', 'dinner' => 'moon'];
    $mealColor = ['breakfast' => 'bg-ink/30', 'lunch' => 'bg-ink', 'dinner' => 'bg-ink/60'];
    $pct = fn ($part, $whole) => $whole ? (int) round($part / $whole * 100) : 0;
    $rate = fn ($s) => $pct($s['attended'], $s['booked']);
    $nf0 = fn ($n) => number_format($n, 0, '.', ',');
    $money = fn ($n) => $nf0($n) . ' ' . $currency;
    $q = fn (array $x = []) => '/statistics?' . http_build_query(array_filter(array_merge(['period' => $f['period'], 'meal' => $f['meal'], 'metric' => $f['metric'], 'anchor' => $f['anchor'], 'department' => $f['department'], 'sex' => $f['sex'], 'admins' => $f['admins'] ? 1 : null], $x), fn ($v) => $v !== null && $v !== ''));
    $delta = function ($cur, $prev, bool $higherIsGood, bool $points = false) {
        if ($prev === null || (!$points && !$prev)) return null;
        $diff = $points ? $cur - $prev : (int) round(($cur - $prev) / $prev * 100);
        if ($diff === 0) return ['text' => t('Same as previous'), 'good' => true];
        return ['text' => ($diff > 0 ? '+' : '') . $diff . ($points ? ' pts' : '%') . ' ' . t('vs previous'), 'good' => ($diff > 0) === $higherIsGood];
    };
    $total = $now['total'];
    $byType = $now['byType'];
    $eaten = $total['booked'] ? $total['attended'] / $total['booked'] : 0;
    $attDelta = $prev && $prev['total']['booked'] ? $delta($rate($total), $rate($prev['total']), true, true) : null;
@endphp
<x-layouts.admin>
  {{-- ---------------------------------------------------------------- Filters, overview, insights --}}
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Statistics')" :description="t('Bookings, attendance and no-shows for any day, week, month or year.')">
      <x-slot:title>{{ t('Who') }} <span class="text-lime">{{ t('showed up') }}</span></x-slot:title>
      <x-admin.btn :href="str_replace('/statistics?', '/statistics/csv?', $q())" size="lg"><x-lucide-download />{{ t('Export CSV') }}</x-admin.btn>
      <x-admin.btn size="lg" variant="soft-dark" class="print:hidden" onclick="window.print()"><x-lucide-printer />{{ t('Print') }}</x-admin.btn>
    </x-admin.heading>

    <form method="GET" action="/statistics" data-autosubmit class="mt-10 flex flex-col gap-4 print:hidden md:flex-row md:flex-wrap md:items-center">
      <input type="hidden" name="metric" value="{{ $f['metric'] }}">
      <x-admin.segmented name="period" :label="t('Period')" dark :value="$f['period']" :options="['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly']" />
      <x-admin.segmented name="meal" :label="t('Meal')" dark :value="$f['meal']" :options="['all' => 'All meals', 'breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner']" />
      <label for="stats-anchor" class="sr-only">{{ t('Reference date') }}</label>
      <x-admin.input id="stats-anchor" type="date" name="anchor" min="2026-01-05" max="{{ Days::today() }}" value="{{ $f['anchor'] }}" class="!h-11 !w-auto border border-ink-3 !bg-ink-2 !text-page [color-scheme:dark]" />
      <label for="stats-department" class="sr-only">{{ t('Department') }}</label>
      <select id="stats-department" name="department" class="h-11 cursor-pointer rounded-full border border-ink-3 bg-ink-2 px-4 text-[14px] text-page [color-scheme:dark]">
        <option value="">{{ t('All departments') }}</option>
        @foreach (['1', '2', '3'] as $d)<option value="{{ $d }}" @selected($f['department'] === $d)>{{ t('Department') }} {{ $d }}</option>@endforeach
      </select>
      <label for="stats-sex" class="sr-only">{{ t('Sex') }}</label>
      <select id="stats-sex" name="sex" class="h-11 cursor-pointer rounded-full border border-ink-3 bg-ink-2 px-4 text-[14px] text-page [color-scheme:dark]">
        <option value="">{{ t('Women & men') }}</option>
        <option value="female" @selected($f['sex'] === 'female')>{{ t('Women') }}</option>
        <option value="male" @selected($f['sex'] === 'male')>{{ t('Men') }}</option>
      </select>
      <label class="flex h-11 cursor-pointer items-center gap-2 rounded-full border border-ink-3 px-4 text-[14px] text-mute-dark">
        <input type="checkbox" name="admins" value="1" class="size-4 cursor-pointer accent-lime" @checked($f['admins'])>{{ t('Include admins') }}
      </label>
      <x-admin.btn type="submit" variant="soft-dark">{{ t('Show') }}</x-admin.btn>
    </form>

    <h2 class="mt-10 text-2xl font-medium tracking-[-0.01em] text-mute-dark">{{ $title }}@if ($f['meal'] !== 'all')<span class="text-page"> · {{ t($labels[$f['meal']]) }}</span>@endif</h2>

    <div class="mt-6 flex flex-col gap-3 rounded-card bg-ink-2 p-6">
      @if ($total['booked'])
        <div class="flex h-12 overflow-hidden rounded-full" role="img" aria-label="{{ t('{0} of {1} booked meals eaten', [0 => $total['attended'], 1 => $total['booked']]) }}">
          <div class="bg-booked" style="width: {{ $eaten * 100 }}%"></div>
          <div class="bg-lime" style="width: {{ (1 - $eaten) * 100 }}%"></div>
        </div>
      @else
        <div class="h-12 rounded-full border border-dashed border-ink-3"></div>
      @endif
      <p class="flex flex-wrap gap-x-6 gap-y-1 text-[12px] text-mute-dark">
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-booked"></span> {{ t('eaten ·') }} <span data-stat-eaten>{{ $total['attended'] }}</span></span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-lime"></span> {{ t('booked but missed ·') }} <span data-stat-missed>{{ $total['noShows'] }}</span></span>
        <span>{{ t('whole bar =') }} <span data-stat-booked>{{ $total['booked'] }}</span> {{ t('booked') }}</span>
        @if ($attDelta)<span class="{{ $attDelta['good'] ? '' : 'text-page' }}">{{ t('attendance') }} {{ $attDelta['text'] }}</span>@endif
      </p>
    </div>

    @if ($insights)
      <ul class="mt-8 grid grid-cols-1 gap-3 md:grid-cols-2">
        @foreach ($insights as [$tone, $text])
          <li class="flex items-start gap-3 rounded-[16px] bg-ink-2 px-4 py-3 text-[14px] text-page">
            @if ($tone === 'warn')<x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0 text-lime" aria-hidden="true" />
            @elseif ($tone === 'good')<x-lucide-trending-up class="mt-0.5 size-4 shrink-0 text-lime" aria-hidden="true" />
            @else<x-lucide-lightbulb class="mt-0.5 size-4 shrink-0 text-mute-dark" aria-hidden="true" />@endif
            {{ $text }}
          </li>
        @endforeach
      </ul>
    @endif
    @if ($prev)<p class="mt-4 text-[12px] text-mute-dark">{{ t('Changes compare with the same number of days in the previous period.') }}</p>@endif
  </x-admin.section>

  <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
    @include('admin.stats.in-short')
    @include('admin.stats.kitchen-plan')

    {{-- ---------------------------------------------------------------- Money --}}
    @php $m = $cost; @endphp
    <x-admin.section tone="cream" class="@container md:px-8 md:py-10 xl:col-span-7">
      <h2 class="flex items-center gap-2 text-3xl font-medium tracking-[-0.02em]"><x-lucide-wallet class="size-6" aria-hidden="true" />{{ t('Money') }}</h2>
      <p class="mt-2 text-[14px] text-mute">{{ $title }} {{ t('· where the bill goes. The accent part is money paid for meals nobody ate; the hatched end is what penalties take back.') }}</p>
      <div class="mt-8 flex flex-col gap-6 rounded-card bg-page p-6">
        <x-admin.split-bar :parts="[
            ['label' => 'Meals eaten', 'value' => max(0, $m['mealsCost'] - $m['wasteCost']), 'tone' => 'good', 'note' => $money(max(0, $m['mealsCost'] - $m['wasteCost']))],
            ['label' => 'Wasted on no-shows', 'value' => $m['wasteCost'], 'tone' => 'accent', 'note' => $money($m['wasteCost']) . ' · ' . t('{0} meals', [0 => $m['wasteMeals']])],
            ['label' => 'Special meals', 'value' => $m['specialsCost'], 'tone' => 'light', 'note' => $money($m['specialsCost'])],
        ]" :minus="['label' => 'Penalties taken back', 'value' => $m['penaltyTotal'], 'note' => $money($m['penaltyTotal'])]" />
        <p class="flex flex-wrap gap-x-6 gap-y-1 text-[12px] text-mute">
          <span>{{ t('Owed after penalties') }} <span class="text-ink" data-stat-net>{{ $money($m['net']) }}</span> ({{ $money($m['net'] * (1 + $vat / 100)) }} {{ t('with VAT)') }}</span>
          @if ($m['bookers'])<span>{{ t('About') }} <span class="text-ink">{{ $money($m['mealsCost'] / $m['bookers']) }}</span> {{ t('per person') }}</span>@endif
          <span>{{ t('Already booked for the next 7 days') }} <span class="text-ink">{{ $money($m['forecast']) }}</span></span>
        </p>
      </div>
    </x-admin.section>

    @include('admin.stats.quality')
    @include('admin.stats.discipline')
    @include('admin.stats.attendance')

    {{-- ---------------------------------------------------------------- Departments --}}
    @php
        $deps = array_values(array_filter($departments, fn ($d) => $d['headcount'] || $d['meals']));
        $withMeals = array_values(array_filter($deps, fn ($d) => $d['meals'] > 0));
        usort($withMeals, fn ($a, $b) => $pct($b['noShows'], $b['meals']) <=> $pct($a['noShows'], $a['meals']));
        $hot = count($withMeals) > 1 && $withMeals[0]['noShows'] ? $withMeals[0]['department'] : null;
        $W = 560; $H = 260; $pad = 36;
        $range = function (array $v) { $lo = min($v); $hi = max($v); $span = max($hi - $lo, 0.1); return [max(0, $lo - $span * 0.35), min(1, $hi + $span * 0.35)]; };
        $pts = array_map(fn ($d) => ['d' => $d, 'x' => $d['headcount'] ? min(1, $d['bookers'] / $d['headcount']) : 0, 'y' => $d['meals'] ? $d['noShows'] / $d['meals'] : 0], $deps);
        [$xlo, $xhi] = $pts ? $range(array_column($pts, 'x')) : [0, 1];
        [$ylo, $yhi] = $pts ? $range(array_column($pts, 'y')) : [0, 1];
        $maxCost = max(1, ...array_column($deps ?: [['cost' => 1]], 'cost'));
    @endphp
    <x-admin.section tone="cream" class="@container md:px-8 md:py-10 xl:col-span-6">
      <h2 class="flex items-center gap-2 text-3xl font-medium tracking-[-0.02em]"><x-lucide-building-2 class="size-6" aria-hidden="true" />{{ t('Departments') }}</h2>
      <p class="mt-2 text-[14px] text-mute">{{ $title }} {{ t('· which teams use the service, which waste it, and what they cost. The accent bubble misses the most.') }}</p>
      <div class="mt-8 rounded-card bg-page p-6">
        @if (!$deps)
          <p class="text-[14px] text-mute">{{ t('No departments to compare yet.') }}</p>
        @else
          <div class="overflow-x-auto">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full min-w-[420px]" role="img" aria-label="{{ t('more of the team books meals') }} / {{ t('more meals missed') }}">
              <rect x="{{ $pad }}" y="8" width="{{ $W - $pad - 8 }}" height="{{ $H - $pad - 8 }}" rx="12" fill="rgba(17,17,16,0.03)" />
              <line x1="{{ $pad + ($W - $pad - 8) / 2 }}" y1="8" x2="{{ $pad + ($W - $pad - 8) / 2 }}" y2="{{ $H - $pad }}" stroke="rgba(17,17,16,0.1)" stroke-dasharray="4 4" />
              <line x1="{{ $pad }}" y1="{{ 8 + ($H - $pad - 8) / 2 }}" x2="{{ $W - 8 }}" y2="{{ 8 + ($H - $pad - 8) / 2 }}" stroke="rgba(17,17,16,0.1)" stroke-dasharray="4 4" />
              <text x="{{ $W - 10 }}" y="{{ $H - 12 }}" text-anchor="end" font-size="11" fill="rgba(17,17,16,0.55)">{{ t('more of the team books meals') }} →</text>
              <text x="12" y="18" font-size="11" fill="rgba(17,17,16,0.55)">↑ {{ t('more meals missed') }}</text>
              @foreach ($pts as $i => $p)
                @php
                    $r = 8 + sqrt($p['d']['cost'] / $maxCost) * 26;
                    $sx = $xhi > $xlo ? ($p['x'] - $xlo) / ($xhi - $xlo) : 0.5;
                    $sy = $yhi > $ylo ? ($p['y'] - $ylo) / ($yhi - $ylo) : 0.5;
                    $cx = min($W - 8 - $r, max($pad + $r, $pad + $sx * ($W - $pad - 8)));
                    $cy = min($H - $pad - $r, max(24 + $r, 8 + (1 - $sy) * ($H - $pad - 8)));
                    $isHot = $p['d']['department'] === $hot;
                @endphp
                <g>
                  <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="{{ $isHot ? 'var(--color-lime)' : 'rgba(17,17,16,0.8)' }}" fill-opacity="{{ $isHot ? 0.9 : 0.75 }}"><title>{{ t('Dept {0}', [0 => $p['d']['department']]) }}: {{ t('{0}% of {1} people booked · {2}% no-shows · {3}', [0 => $pct($p['d']['bookers'], $p['d']['headcount']), 1 => $p['d']['headcount'], 2 => $pct($p['d']['noShows'], $p['d']['meals']), 3 => $money($p['d']['cost'])]) }}</title></circle>
                  <text x="{{ $cx }}" y="{{ $i % 2 ? $cy + $r + 13 : $cy - $r - 4 }}" text-anchor="middle" font-size="11" fill="rgba(17,17,16,0.85)">{{ t('Dept {0}', [0 => $p['d']['department']]) }}</text>
                </g>
              @endforeach
            </svg>
          </div>
          <table class="mt-4 w-full text-start text-[13px]" data-departments>
            <thead class="text-[12px] text-mute"><tr><th class="py-1 text-start font-medium">{{ t('Department') }}</th><th class="py-1 text-end font-medium">{{ t('People') }}</th><th class="py-1 text-end font-medium">{{ t('Booked') }}</th><th class="py-1 text-end font-medium">{{ t('Meals') }}</th><th class="py-1 text-end font-medium">{{ t('No-shows') }}</th><th class="py-1 text-end font-medium">{{ t('Cost') }}</th></tr></thead>
            <tbody>
              @foreach ($deps as $d)
                <tr class="border-t border-ink/10"><td class="py-1.5">{{ t('Department') }} {{ $d['department'] }}</td><td class="py-1.5 text-end tabular-nums">{{ $d['headcount'] }}</td><td class="py-1.5 text-end tabular-nums">{{ $d['bookers'] }}</td><td class="py-1.5 text-end tabular-nums">{{ $d['meals'] }}</td><td class="py-1.5 text-end tabular-nums">{{ $d['noShows'] }}</td><td class="py-1.5 text-end tabular-nums">{{ $money($d['cost']) }}</td></tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </x-admin.section>

    {{-- ---------------------------------------------------------------- Special meals --}}
    <x-admin.section tone="cream" class="@container md:px-8 md:py-10 xl:col-span-6">
      <h2 class="flex items-center gap-2 text-3xl font-medium tracking-[-0.02em]"><x-lucide-sparkles class="size-6" aria-hidden="true" />{{ t('Special meals') }}</h2>
      <p class="mt-2 text-[14px] text-mute">{{ t('Enhanced meals, VIP meals, special meals, snacks and VIP snacks ordered by admins ·') }} {{ $title }}</p>
      <div class="mt-8 grid grid-cols-1 gap-6 @3xl:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-card bg-page p-6">
          <h3 class="text-[15px] font-medium">{{ t('What kind') }}</h3>
          <x-admin.split-bar :parts="collect($specialByKind)->values()->map(fn ($k, $i) => ['label' => $k['label'], 'value' => $k['count'], 'tone' => ['ink', 'mid', 'light', 'mid', 'light'][$i % 5], 'note' => (string) $k['count']])->all()" />
        </div>
        <div class="flex flex-col gap-4 rounded-card bg-page p-6">
          <h3 class="text-[15px] font-medium">{{ t('At which meal') }}</h3>
          <x-admin.split-bar :parts="collect($specialByMeal)->map(fn ($k, $i) => ['label' => $k['label'], 'value' => $k['count'], 'tone' => $i === 0 ? 'ink' : 'mid', 'note' => (string) $k['count']])->all()" />
        </div>
      </div>
    </x-admin.section>

    @include('admin.stats.menu')
    @include('admin.stats.side')

    {{-- ---------------------------------------------------------------- Who didn't show up --}}
    <x-admin.section tone="cream" class="@container md:px-8 md:py-10 xl:col-span-12" id="noshows">
      <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <h2 class="flex items-center gap-2 text-3xl font-medium tracking-[-0.02em]"><x-lucide-user-x class="size-6" aria-hidden="true" />{{ t("Who didn't show up") }}</h2>
          <p class="mt-2 text-[14px] text-mute">{{ t('Bookings marked as no-show ·') }} {{ $title }}{{ $f['meal'] !== 'all' ? ' · ' . t($labels[$f['meal']]) : '' }}</p>
        </div>
        <p class="text-[15px]"><span class="text-4xl font-medium tracking-[-0.03em]">{{ count($people) }}</span><span class="ms-2 text-mute">{{ count($people) === 1 ? t('person') : t('people') }} · {{ $total['noShows'] === 1 ? t('1 meal') : t('{0} meals', [0 => $total['noShows']]) }}</span></p>
      </div>
      @if (!$people)
        <p class="mt-6 text-[15px] text-mute">{{ t('Everyone who booked showed up.') }}</p>
      @else
        <div class="mt-6 overflow-x-auto">
          <table class="w-full min-w-[520px] border-separate border-spacing-y-1 text-start text-[14px]">
            <thead class="text-[12px] text-mute"><tr><th class="px-4 py-2 text-start font-medium">{{ t('Person') }}</th><th class="px-4 py-2 text-start font-medium">{{ t('Missed') }}</th><th class="px-4 py-2 text-end font-medium">{{ t('Meals') }}</th></tr></thead>
            <tbody>
              @foreach ($f['all'] ? $people : array_slice($people, 0, 10) as $p)
                <tr class="bg-page">
                  <td class="rounded-s-full py-3 pe-4 ps-5"><p class="font-medium">{{ $p['name'] }}</p>@if ($p['email'] !== '')<p class="text-[12px] text-mute">{{ $p['email'] }}</p>@endif</td>
                  <td class="px-4 py-3">{{ collect($p['entries'])->sortBy('date')->map(fn ($e) => Days::fromKey($e['date'])->translatedFormat('D j M') . ' (' . collect($e['meals'])->map(fn ($x) => t($labels[$x]))->implode(', ') . ')')->implode(' · ') }}</td>
                  <td class="rounded-e-full py-3 pe-5 ps-4 text-end font-medium tabular-nums">{{ $p['meals'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @if (count($people) > 10)
          <a href="{{ $q(['all' => $f['all'] ? null : 1]) }}#noshows" class="mt-4 inline-block rounded-full bg-page px-4 py-2 text-[14px] font-medium transition hover:bg-cream-deep">{{ $f['all'] ? t('Show the top 10 only') : t('Show all {0} people', [0 => count($people)]) }}</a>
        @endif
      @endif
    </x-admin.section>
    @include('admin.stats.explore')
  </div>

  {{-- ---------------------------------------------------------------- Volume by meal & attendance --}}
  <x-admin.section tone="cream">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div>
        <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('Volume by meal & attendance') }}</h2>
        <p class="mt-2 text-[14px] text-mute">{{ t('Bars split by meal type · line shows attendance rate') }}{{ $f['period'] === 'day' ? t(' · last 14 days') : '' }}</p>
      </div>
      <div class="flex flex-wrap gap-1 rounded-full bg-cream-deep p-1 print:hidden" role="radiogroup" aria-label="{{ t('Bars show') }}">
        @foreach (['booked' => 'Booked', 'attended' => 'Attended', 'noShows' => 'No-shows'] as $mk => $ml)
          <a href="{{ $q(['metric' => $mk]) }}" role="radio" aria-checked="{{ $f['metric'] === $mk ? 'true' : 'false' }}" class="flex h-9 items-center rounded-full px-4 text-[13px] font-medium transition duration-200 {{ $f['metric'] === $mk ? 'bg-ink text-page' : 'text-mute hover:text-ink' }}">{{ t($ml) }}</a>
        @endforeach
      </div>
    </div>

    <div class="mt-8 flex min-h-[76px] flex-wrap items-center gap-x-8 gap-y-3 rounded-[20px] px-5 py-4 ring-1 ring-ink/10">
      <div class="min-w-32"><p class="text-[12px] text-mute">{{ t('Period') }}</p><p class="text-lg font-medium">{{ $title }}</p></div>
      @foreach ($meals as $t)
        <div class="{{ $f['meal'] !== 'all' && $f['meal'] !== $t ? 'opacity-40' : '' }}">
          <p class="flex items-center gap-1.5 text-[12px] text-mute"><span class="size-2.5 rounded-full ring-1 ring-ink/20 {{ $mealColor[$t] }}"></span>{{ t($labels[$t]) }}</p>
          <p class="text-lg font-medium">{{ $byType[$t][$f['metric']] }}<span class="ms-1.5 text-[12px] font-normal text-mute">{{ $rate($byType[$t]) }}{{ t('% att.') }}</span></p>
        </div>
      @endforeach
      @if ($peak)
        <div class="ms-auto text-end"><p class="text-[12px] text-mute">{{ t('Peak · avg') }} {{ number_format($avgMetric, $avgMetric >= 10 ? 0 : 1) }}</p><p class="text-lg font-medium">{{ $peak['focus'][$f['metric']] }} <span class="text-[13px] font-normal text-mute">{{ t('on') }} {{ $peak['label'] }}</span></p></div>
      @endif
    </div>

    <div class="mt-6 overflow-x-auto">
      <div style="{{ count($chart) > 14 ? 'min-width: ' . count($chart) * 28 . 'px' : '' }}">
        <div class="relative h-72">
          @foreach ([1, 0.5] as $g)
            <div class="pointer-events-none absolute inset-x-0 border-t border-ink/10" style="bottom: {{ $g * 100 }}%">
              <span class="absolute -top-4 start-0 text-[10px] text-mute">{{ round($chartMax * $g) }}</span>
              <span class="absolute -top-4 end-0 text-[10px] text-mute">{{ $g * 100 }}%</span>
            </div>
          @endforeach
          @if ($avgMetric)<div class="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-ink/30" style="bottom: {{ $avgMetric / $chartMax * 100 }}%" aria-hidden="true"></div>@endif
          <ul class="absolute inset-0 flex items-end" aria-label="{{ t('Volume by meal chart') }}" data-chart>
            @foreach ($chart as $i => $c)
              <li class="flex h-full min-w-0 flex-1 items-end justify-center px-[3px] hover:bg-ink/5" title="{{ $c['label'] }}: {{ collect($meals)->map(fn ($t) => t($labels[$t]) . ' ' . $c['byType'][$t][$f['metric']])->implode(', ') }} · {{ $rate($c['focus']) }}%">
                <div class="reveal flex w-full max-w-14 flex-col-reverse overflow-hidden rounded-[10px]" style="height: {{ $c['stack'] / $chartMax * 100 }}%; animation-delay: {{ $i * 25 }}ms">
                  @foreach ($meals as $t)
                    <div class="{{ $mealColor[$t] }} {{ $f['meal'] !== 'all' && $f['meal'] !== $t ? 'opacity-25' : '' }}" style="height: {{ $c['stack'] ? $c['byType'][$t][$f['metric']] / $c['stack'] * 100 : 0 }}%"></div>
                  @endforeach
                </div>
                <span class="sr-only">{{ $c['label'] }}: {{ collect($meals)->map(fn ($t) => t($labels[$t]) . ' ' . $c['byType'][$t][$f['metric']])->implode(', ') }}, {{ $rate($c['focus']) }}%</span>
              </li>
            @endforeach
          </ul>
          <svg viewBox="0 0 1000 100" preserveAspectRatio="none" class="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true">
            <path d="{{ $ratePath }}" fill="none" class="stroke-page" stroke-width="6" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
            <path d="{{ $ratePath }}" fill="none" class="stroke-ink" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
          </svg>
        </div>
        <div class="mt-2 flex">
          @foreach ($chart as $i => $c)
            <span class="min-w-0 flex-1 truncate text-center text-[11px] text-mute {{ !$c['days'] ? 'opacity-40' : '' }} {{ $f['period'] === 'day' && $i % 2 === 1 ? 'invisible' : '' }}">{{ $c['label'] }}</span>
          @endforeach
        </div>
      </div>
    </div>
    <div class="mt-4 flex flex-wrap gap-5 text-[12px] text-mute" aria-hidden="true">
      @foreach ($meals as $t)<span class="flex items-center gap-2"><span class="size-3 rounded-[4px] ring-1 ring-ink/20 {{ $mealColor[$t] }}"></span> {{ t($labels[$t]) }}</span>@endforeach
      <span class="flex items-center gap-2"><span class="h-0.5 w-5 bg-ink"></span> {{ t('Attendance %') }}</span>
      <span class="flex items-center gap-2"><span class="w-5 border-t-2 border-dashed border-ink/40"></span> {{ t('Average') }}</span>
    </div>
    @if (!$hasDays)<p class="mt-6 text-center text-mute">{{ t('No data for this period yet.') }}</p>@endif

    <div class="mt-12 grid grid-cols-1 gap-10 xl:grid-cols-5">
      <div class="overflow-x-auto xl:col-span-3">
        <h3 class="text-xl font-medium">{{ t('Meal breakdown') }}</h3>
        <table class="mt-4 w-full min-w-[560px] text-start text-[14px]" data-breakdown>
          <thead class="text-[12px] text-mute"><tr class="border-b border-ink/10">
            @foreach (['Meal', 'Share', 'Booked', 'Attended', 'No-shows', 'Att. rate', 'vs prev.'] as $h)<th class="py-2.5 text-start font-medium">{{ t($h) }}</th>@endforeach
          </tr></thead>
          <tbody>
            @foreach ($meals as $t)
              @php
                  $s = $byType[$t];
                  $share = $pct($s['booked'], $allMeals['booked']);
                  $nsShare = $pct($s['noShows'], $allMeals['noShows']);
                  $dl = $prev ? $delta($s['booked'], $prev['byType'][$t]['booked'], true) : null;
              @endphp
              <tr class="border-b border-ink/10 {{ $f['meal'] !== 'all' && $f['meal'] !== $t ? 'opacity-40' : '' }}">
                <td class="py-3"><span class="flex items-center gap-2.5 font-medium"><x-admin.icon-chip :icon="$icons[$t]" size="sm" />{{ t($labels[$t]) }}</span></td>
                <td class="py-3"><div class="flex items-center gap-2"><div class="h-2 w-16 overflow-hidden rounded-full bg-ink/10"><div class="h-full {{ $mealColor[$t] }}" style="width: {{ $share }}%"></div></div>{{ $share }}%</div></td>
                <td class="py-3">{{ $s['booked'] }}</td>
                <td class="py-3">{{ $s['attended'] }}</td>
                <td class="py-3"><span class="{{ $nsShare > $share + 5 ? 'rounded-full bg-lime px-2 py-0.5 font-medium' : '' }}" title="{{ t('{0}% of all no-shows', [0 => $nsShare]) }}">{{ $s['noShows'] }}</span></td>
                <td class="py-3">{{ $rate($s) }}%</td>
                <td class="py-3 text-[12px] {{ $dl && $dl['good'] ? 'text-ink' : 'text-mute' }}">{{ $dl ? str_replace(' ' . t('vs previous'), '', $dl['text']) : '—' }}</td>
              </tr>
            @endforeach
            @if ($prevAll)
              <tr class="text-[12px] text-mute"><td colspan="7" class="pt-3">{{ t('Highlighted no-shows: the meal causes more than its share of no-shows. Previous period:') }} {{ $prevAll['booked'] }} {{ t('booked,') }} {{ $rate($prevAll) }}{{ t('% attendance.') }}</td></tr>
            @endif
          </tbody>
        </table>
      </div>

      <div class="overflow-x-auto xl:col-span-2">
        <h3 class="text-xl font-medium">{{ t('Weekly rhythm') }}</h3>
        <p class="mt-1 text-[12px] text-mute">{{ t('Avg bookings per weekday') }}{{ in_array($f['period'], ['day', 'week'], true) ? t(', last 12 weeks') : '' }}</p>
        <table class="mt-3 w-full border-separate border-spacing-1 text-center text-[13px]">
          <thead class="text-[11px] text-mute"><tr><th></th>@foreach ($meals as $t)<th class="truncate font-medium">{{ t($labels[$t]) }}</th>@endforeach<th class="font-medium">{{ t('Att.') }}</th></tr></thead>
          <tbody>
            @foreach ($weekdays as $day)
              <tr>
                <th class="pe-1 text-start text-[12px] font-medium">{{ $day['label'] }}</th>
                @foreach ($meals as $t)
                  @php $avg = $day['count'] ? $day['byType'][$t]['booked'] / $day['count'] : 0; $strength = $avg / $heatMax; @endphp
                  <td class="relative overflow-hidden rounded-[8px] p-0" title="{{ $day['label'] }} · {{ t($labels[$t]) }}: {{ number_format($avg, 1) }}">
                    <div class="absolute inset-0 bg-ink" style="opacity: {{ $avg ? 0.08 + $strength * 0.82 : 0.04 }}" aria-hidden="true"></div>
                    <span class="relative block py-2 font-medium {{ $strength > 0.55 ? 'text-page' : 'text-ink' }} {{ !$avg ? 'text-mute' : '' }}">{{ $avg ? number_format($avg, $avg >= 10 ? 0 : 1) : '—' }}</span>
                  </td>
                @endforeach
                <td class="text-[12px] text-mute">{{ $day['total']['booked'] ? $rate($day['total']) . '%' : '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </x-admin.section>
</x-layouts.admin>
