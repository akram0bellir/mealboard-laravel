{{-- Explore: pick a measure, split it, and compare it with the previous period or between two groups (A vs B).
     The choices live in the address (x_*), so the page works without a script; the form re-submits by itself. --}}
@php
    use App\Services\Explore;
    use App\Services\StatsItems as S;
    $qs = fn (array $x = []) => substr($q($x), strlen('/statistics'));
    $v = $f['explore'];
    $x = $explore;
    $ordered = $x['ordered'];
    $addr = fn (array $view, array $extra = []) => '/statistics' . $qs(array_merge(Explore::toQuery($view), $extra));
    $viewKey = fn (array $a) => implode('|', [$a['metric'], $a['split'], $a['mode'], $a['cdim'], $a['a'], $a['b']]);
    $current = $viewKey($v);
    $presets = Explore::PRESETS;
    $saved = $savedViews;
    $tableOn = $f['xTable'];
    $hasA = $v['mode'] === 'groups';
@endphp
<x-stats.block icon="compass" :title="t('Explore')" :question="t('Ask your own question: pick what to measure, how to split it, and what to compare it with.')" id="explore" class="xl:col-span-12">
  <x-slot:actions>
    <form method="POST" action="/statistics/explore/save{{ $qs(Explore::toQuery($v)) }}" class="contents">
      @csrf
      <x-admin.btn type="submit" size="sm" variant="ghost"><x-lucide-bookmark />{{ t('Save view') }}</x-admin.btn>
    </form>
    <x-admin.btn :href="'/statistics/explore.csv' . $qs(Explore::toQuery($v))" size="sm" variant="ghost" :aria-disabled="!$x['data'] ? 'true' : null"><x-lucide-download />{{ t('CSV') }}</x-admin.btn>
  </x-slot:actions>

  <ul class="-mt-2 mb-6 flex flex-wrap gap-2" data-explore-views>
    @foreach ($presets as $p)
      <li class="flex items-center"><a href="{{ $addr($p, ['x_name' => $p['name']]) }}#explore" class="rounded-full px-3 py-1.5 text-[13px] transition {{ $viewKey($p) === $current ? 'bg-ink text-page' : 'bg-page hover:bg-cream-deep' }}">{{ t($p['name']) }}</a></li>
    @endforeach
    @foreach ($saved as $s)
      <li class="flex items-center">
        <a href="{{ $addr($s) }}#explore" class="rounded-full px-3 py-1.5 text-[13px] transition {{ $viewKey($s) === $current ? 'bg-ink text-page' : 'bg-page hover:bg-cream-deep' }}">{{ $s['name'] }}</a>
        <form method="POST" action="/statistics/explore/delete{{ $qs(Explore::toQuery($v)) }}" class="contents">
          @csrf
          <input type="hidden" name="name" value="{{ $s['name'] }}">
          <button type="submit" aria-label="{{ t('Remove saved view {0}', [0 => $s['name']]) }}" class="ms-1 cursor-pointer rounded-full p-1 text-mute hover:text-ink"><x-lucide-x class="size-3.5" /></button>
        </form>
      </li>
    @endforeach
  </ul>

  <form method="GET" action="/statistics#explore" data-autosubmit class="grid grid-cols-1 gap-3 @xl:grid-cols-2 @5xl:grid-cols-4">
    {{-- keep the page's own filters --}}
    @foreach (['period' => $f['period'], 'meal' => $f['meal'], 'metric' => $f['metric'], 'anchor' => $f['anchor'], 'department' => $f['department'], 'sex' => $f['sex'], 'admins' => $f['admins'] ? 1 : ''] as $k => $val)
      @if ($val !== '')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
    @endforeach
    @if ($tableOn)<input type="hidden" name="x_view" value="table">@endif
    <label class="flex flex-col gap-1 text-[13px] font-medium">{{ t('Measure') }}
      <x-admin.select name="x_metric" tone="page">@foreach (S::METRICS as $key => $m)<option value="{{ $key }}" @selected($v['metric'] === $key)>{{ t($m[0]) }}</option>@endforeach</x-admin.select>
    </label>
    <label class="flex flex-col gap-1 text-[13px] font-medium">{{ t('Split by') }}
      <x-admin.select name="x_split" tone="page">
        <option value="none" @selected($v['split'] === 'none')>{{ t('No split (everyone)') }}</option>
        @foreach (S::DIMENSIONS as $key => $d)<option value="{{ $key }}" @selected($v['split'] === $key)>{{ t($d[0]) }}</option>@endforeach
      </x-admin.select>
    </label>
    <label class="flex flex-col gap-1 text-[13px] font-medium">{{ t('Compare with') }}
      <x-admin.select name="x_mode" tone="page">
        <option value="none" @selected($v['mode'] === 'none')>{{ t('Nothing') }}</option>
        <option value="previous" @selected($v['mode'] === 'previous')>{{ t('The previous period') }}</option>
        <option value="groups" @selected($v['mode'] === 'groups')>{{ t('Two groups (A vs B)') }}</option>
      </x-admin.select>
    </label>
    @if ($hasA)
      <div class="grid grid-cols-3 gap-2">
        <label class="flex flex-col gap-1 text-[13px] font-medium">{{ t('Groups of') }}
          <x-admin.select name="x_cdim" tone="page">@foreach (S::DIMENSIONS as $key => $d)<option value="{{ $key }}" @selected($v['cdim'] === $key)>{{ t($d[0]) }}</option>@endforeach</x-admin.select>
        </label>
        @foreach (['x_a' => ['A', $v['a']], 'x_b' => ['B', $v['b']]] as $name => [$letter, $current])
          <label class="flex flex-col gap-1 text-[13px] font-medium">{{ $letter }}
            <x-admin.select name="{{ $name }}" tone="page">
              <option value="">{{ t('Choose…') }}</option>
              @foreach ($x['choices'] as $c)<option value="{{ $c['key'] }}" @selected($current === $c['key'])>{{ $c['name'] }}</option>@endforeach
            </x-admin.select>
          </label>
        @endforeach
      </div>
    @else
      <input type="hidden" name="x_cdim" value="{{ $v['cdim'] }}"><input type="hidden" name="x_a" value="{{ $v['a'] }}"><input type="hidden" name="x_b" value="{{ $v['b'] }}">
    @endif
    <noscript><x-admin.btn type="submit" variant="dark" size="sm">{{ t('Show') }}</x-admin.btn></noscript>
  </form>

  <div class="mt-8 rounded-card bg-page p-6">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <p class="text-lg font-medium" data-explore-headline>{{ $x['headline'] }}</p>
      <div class="flex rounded-full bg-cream p-1 text-[13px]">
        @foreach ([false => 'Chart', true => 'Table'] as $isTable => $label)
          <a href="{{ '/statistics' . $qs(array_merge(Explore::toQuery($v), ['x_view' => $isTable ? 'table' : null])) }}#explore" class="rounded-full px-3 py-1 {{ $isTable === $tableOn ? 'bg-ink text-page' : 'text-mute' }}">{{ t($label) }}</a>
        @endforeach
      </div>
    </div>
    @if ($tableOn)
      <div class="overflow-x-auto">
        <table class="w-full min-w-[480px] text-start text-[14px]" data-explore-table>
          <thead class="text-[12px] text-mute">
            <tr class="border-b border-ink/10">
              <th class="py-2 text-start font-medium">{{ $x['splitLabel'] ? t($x['splitLabel']) : t('Group') }}</th>
              <th class="py-2 text-end font-medium">{{ $x['labels'][0] ?? $x['metricLabel'] }}</th>
              @if ($x['labels'])<th class="py-2 text-end font-medium">{{ $x['labels'][1] }}</th>@endif
              <th class="py-2 text-end font-medium">{{ t('Based on') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($x['data'] as $d)
              <tr class="border-b border-ink/5">
                <td class="py-2">{{ $d['name'] }}</td>
                <td class="py-2 text-end tabular-nums">{{ !empty($d['hidden']) ? t('hidden') : $x['fmt']($d['value']) }}</td>
                @if ($x['labels'])<td class="py-2 text-end tabular-nums">{{ isset($d['compare']) ? $x['fmt']($d['compare']) : '' }}</td>@endif
                <td class="py-2 text-end text-mute">{{ $d['note'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @elseif ($ordered)
      <x-stats.columns :data="$x['data']" :fmt="$x['fmt']" :labels="$x['labels']" />
    @else
      <x-stats.bars :data="$x['data']" :fmt="$x['fmt']" :labels="$x['labels']" />
    @endif
    <p class="mt-4 text-[12px] text-mute">{{ t('How we count: one entry per booked meal in the selected period, after the filters at the top. A no-show is a booked meal marked as missed. Splits by sex or position hide groups under') }} {{ S::PRIVACY_MIN_PEOPLE }} {{ t('people.') }}</p>
  </div>
</x-stats.block>
