@props(['data', 'rateLabel', 'target' => null])
{{-- Height = how many, colour = how well (a rate). Below the target = red (needs attention). --}}
@php $max = max(1, ...array_column($data, 'volume')); @endphp
<div class="flex flex-col gap-3">
  <div class="flex h-40 items-end gap-3">
    @foreach ($data as $d)
      @php
          $low = $target !== null && $d['rate'] < $target;
          $bg = $target === null ? 'rgb(17 17 16 / ' . (0.15 + $d['rate'] * 0.85) . ')' : ($low ? 'rgb(246 52 64 / 0.9)' : 'rgb(29 92 51 / ' . (0.35 + $d['rate'] * 0.65) . ')');
      @endphp
      <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ t('{0}: {1} meals · {2}% {3}', [0 => $d['label'], 1 => $d['volume'], 2 => round($d['rate'] * 100), 3 => $rateLabel]) }}">
        <span class="text-[11px] tabular-nums {{ $low ? 'font-medium text-ink' : 'text-mute' }}">{{ round($d['rate'] * 100) }}%</span>
        <div class="w-full max-w-14 rounded-t-[6px]" style="height: {{ $d['volume'] / $max * 85 }}%; background: {{ $bg }}"></div>
      </div>
    @endforeach
  </div>
  <div class="flex gap-3 text-[11px] text-mute">
    @foreach ($data as $d)<span class="flex-1 text-center">{{ $d['label'] }}</span>@endforeach
  </div>
  <p class="text-[12px] text-mute">{{ t('Height = meals booked ·') }}
    {{ $target !== null ? t('green = {0} at or above {1}% · red = below it', [0 => $rateLabel, 1 => round($target * 100)]) : t('darker = higher {0}', [0 => $rateLabel]) }}</p>
</div>
