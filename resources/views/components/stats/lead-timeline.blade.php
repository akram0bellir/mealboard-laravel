@props(['leads', 'deadlineDays'])
{{-- Dots by how many days before the meal they were booked. Green = early, red = around the deadline. --}}
@php
    $maxDay = max(7, ...array_map(fn ($d) => min($d, 14), $leads ?: [0]));
    $cols = array_map(fn ($d) => $maxDay - $d, range(0, $maxDay));
    $count = fn ($d) => count(array_filter($leads, fn ($x) => min($x, 14) === $d));
    $top = max(1, ...array_map($count, $cols));
    $per = max(1, (int) ceil($top / 10));
@endphp
<div class="flex flex-col gap-2">
  <div class="flex items-end gap-1">
    @foreach ($cols as $d)
      @php $n = $count($d); $late = $d <= $deadlineDays; @endphp
      <div class="flex flex-1 flex-col items-center justify-end gap-1" title="{{ t('{0}: {1} meals', [0 => $d === 0 ? t('Same day') : ($d === 1 ? t('1 day before') : t('{0} days before', [0 => $d])), 1 => $n]) }}">
        @for ($i = 0; $i < (int) ceil($n / $per); $i++)<span class="size-3 rounded-full {{ $late ? 'bg-lime' : 'bg-booked-ink' }}"></span>@endfor
        <span class="h-1 w-full rounded-full bg-ink/10"></span>
      </div>
    @endforeach
  </div>
  <div class="flex justify-between text-[11px] text-mute">
    <span>{{ $maxDay >= 14 ? t('14+ days before') : t('{0} days before', [0 => $maxDay]) }}</span>
    <span>{{ t('the day before / same day') }}</span>
  </div>
  <p class="text-[12px] text-mute">{{ t('Each dot =') }} {{ $per === 1 ? t('one meal') : t('{0} meals', [0 => $per]) }} {{ t('· green = booked early · red = booked at the last moment (around the deadline)') }}</p>
</div>
