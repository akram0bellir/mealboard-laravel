@props(['grid', 'sizeLabel', 'rateLabel'])
{{-- Size = how many, colour = a miss rate: green under 10%, red above, stronger as it gets worse. --}}
@php
    $maxSize = 1;
    $worst = null;
    foreach ($grid['cells'] as $meal => $row) {
        foreach ($row as $col => $c) {
            if (!$c) {
                continue;
            }
            $maxSize = max($maxSize, $c['size']);
            if ($c['size'] >= 3 && ($worst === null || $c['rate'] > $grid['cells'][$worst[0]][$worst[1]]['rate'])) {
                $worst = [$meal, (string) $col];
            }
        }
    }
    $color = fn ($r) => $r < 0.1 ? 'rgb(29 92 51 / ' . (0.75 - $r * 4) . ')' : 'rgb(246 52 64 / ' . (0.35 + min(1, ($r - 0.1) / 0.4) * 0.6) . ')';
@endphp
<div class="flex flex-col gap-3">
  <div class="overflow-x-auto">
    <table class="w-full min-w-[420px] text-[12px]">
      <thead><tr><th></th>@foreach ($grid['cols'] as $c)<th class="pb-2 text-center font-medium text-mute">{{ $c['name'] }}</th>@endforeach</tr></thead>
      <tbody>
        @foreach ($grid['rows'] as $r)
          <tr>
            <th class="pe-3 text-start font-medium whitespace-nowrap text-mute">{{ $r['name'] }}</th>
            @foreach ($grid['cols'] as $c)
              @php $v = $grid['cells'][$r['key']][$c['key']] ?? null; $d = $v ? 10 + sqrt($v['size'] / $maxSize) * 30 : 0; @endphp
              <td class="h-14 text-center align-middle">
                @if ($v)
                  <span class="inline-block rounded-full {{ $worst === [$r['key'], $c['key']] && $v['rate'] > 0 ? 'ring-2 ring-ink ring-offset-2 ring-offset-page' : '' }}" style="width: {{ $d }}px; height: {{ $d }}px; background: {{ $color($v['rate']) }}" title="{{ $r['name'] }} · {{ $c['name'] }}: {{ $v['size'] }} {{ $sizeLabel }}, {{ round($v['rate'] * 100) }}% {{ $rateLabel }}"></span>
                @else
                  <span class="text-ink/20">·</span>
                @endif
              </td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <p class="text-[12px] text-mute">{{ t('Bigger = more') }} {{ $sizeLabel }} {{ t('· green = few missed (under 10%) · red = more missed, stronger as it gets worse · outlined = where it happens most') }}</p>
</div>
