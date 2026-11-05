@props(['data', 'fmt', 'labels' => null])
{{-- Vertical columns in time order (weeks, months, days). --}}
@php
    $vals = [];
    foreach ($data as $d) {
        $vals[] = max($d['value'], $d['compare'] ?? 0);
    }
    $max = max(1e-9, ...($vals ?: [0]));
@endphp
@if (!$data)
  <p class="text-[14px] text-mute">{{ t('No data for this selection.') }}</p>
@else
  <div class="overflow-x-auto pt-6">
    @if ($labels)
      <ul class="-mt-4 mb-4 flex gap-4 text-[12px] text-mute">
        <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-ink" aria-hidden="true"></span>{{ $labels[0] }}</li>
        <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-ink/30" aria-hidden="true"></span>{{ $labels[1] }}</li>
      </ul>
    @endif
    <div class="relative flex h-44 min-w-[480px] items-end gap-2">
      @foreach ($data as $d)
        <div class="flex h-full flex-1 items-end justify-center gap-0.5" title="{{ $d['name'] }}: {{ $fmt($d['value']) }}{{ !empty($d['note']) ? ' · ' . $d['note'] : '' }}">
          <div class="w-full max-w-10 rounded-t-[4px] bg-ink {{ !empty($d['small']) ? 'opacity-50' : '' }}" style="height: {{ $d['value'] / $max * 100 }}%"></div>
          @if (isset($d['compare']))<div class="w-full max-w-10 rounded-t-[4px] bg-ink/30" style="height: {{ $d['compare'] / $max * 100 }}%"></div>@endif
        </div>
      @endforeach
    </div>
    <div class="mt-2 flex min-w-[480px] gap-2 text-[11px] text-mute">
      @foreach ($data as $d)<span class="flex-1 truncate text-center">{{ $d['name'] }}<span class="block text-ink tabular-nums">{{ $fmt($d['value']) }}</span></span>@endforeach
    </div>
  </div>
@endif
