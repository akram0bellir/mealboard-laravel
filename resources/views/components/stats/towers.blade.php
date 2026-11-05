@props(['days', 'legend'])
{{-- One tower per day; height = portions, layers = meal mix (breakfast light, lunch dark, dinner mid, specials outlined). --}}
@php
    $shades = ['rgb(17 17 16 / 0.3)', 'rgb(17 17 16 / 1)', 'rgb(17 17 16 / 0.6)'];
    $totals = array_map(fn ($d) => array_sum($d['layers']) + $d['extra'], $days);
    $max = max(1, ...$totals);
@endphp
<div class="flex flex-col gap-3">
  <div class="flex h-44 items-end gap-3">
    @foreach ($days as $i => $d)
      <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ t('{0}: {1} portions', [0 => $d['label'], 1 => $totals[$i]]) }}">
        <span class="text-[11px] text-mute tabular-nums">{{ $totals[$i] ?: '' }}</span>
        <div class="flex w-full max-w-14 flex-col-reverse overflow-hidden rounded-t-[6px]" style="height: {{ $totals[$i] / $max * 85 }}%">
          @foreach ($d['layers'] as $m => $n)
            @if ($n)<div style="height: {{ $n / $totals[$i] * 100 }}%; background: {{ $shades[$m] }}"></div>@endif
          @endforeach
          @if ($d['extra'])<div class="border border-ink/40" style="height: {{ $d['extra'] / $totals[$i] * 100 }}%"></div>@endif
        </div>
        @if (!$totals[$i])<div class="h-1 w-full max-w-14 rounded-full bg-ink/10"></div>@endif
      </div>
    @endforeach
  </div>
  <div class="flex gap-3 text-[11px] text-mute">
    @foreach ($days as $d)<span class="flex-1 text-center">{{ $d['label'] }}</span>@endforeach
  </div>
  <ul class="flex flex-wrap gap-4 text-[12px] text-mute">
    @foreach ($legend as $i => $label)
      <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm {{ $i >= 3 ? 'border border-ink/40' : '' }}" style="{{ $i < 3 ? 'background: ' . $shades[$i] : '' }}" aria-hidden="true"></span>{{ $label }}</li>
    @endforeach
  </ul>
</div>
