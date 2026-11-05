@props(['parts', 'minus' => null])
{{-- One bar split into parts ($parts = [[label, value, tone, note]]); $minus is drawn hatched at the end. --}}
@php
    $total = array_sum(array_column($parts, 'value'));
    $tone = ['ink' => 'bg-ink', 'mid' => 'bg-ink/55', 'light' => 'bg-ink/20', 'accent' => 'bg-lime', 'good' => 'bg-booked-ink'];
@endphp
@if (!$total)
  <p class="text-[14px] text-mute">{{ t('Nothing to show for this period.') }}</p>
@else
  <div class="flex flex-col gap-3">
    <div class="relative flex h-10 overflow-hidden rounded-full">
      @foreach ($parts as $p)
        @if ($p['value'])<div class="{{ $tone[$p['tone']] }}" style="width: {{ $p['value'] / $total * 100 }}%" title="{{ t($p['label']) }}: {{ $p['note'] ?? $p['value'] }}"></div>@endif
      @endforeach
      @if ($minus && $minus['value'])
        <div class="absolute inset-y-0 end-0 border-s-2 border-dashed border-page bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(255,255,255,0.55)_6px_9px)]" style="width: {{ min(100, $minus['value'] / $total * 100) }}%" title="{{ t($minus['label']) }}: {{ $minus['note'] ?? $minus['value'] }}"></div>
      @endif
    </div>
    <ul class="flex flex-wrap gap-x-6 gap-y-2 text-[12px] text-mute">
      @foreach ($parts as $p)
        <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm {{ $tone[$p['tone']] }}"></span>{{ t($p['label']) }}<span class="text-ink tabular-nums">{{ round($p['value'] / $total * 100) }}%</span>@if (!empty($p['note']))<span>· {{ $p['note'] }}</span>@endif</li>
      @endforeach
      @if ($minus && $minus['value'])
        <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm border border-dashed border-ink/50"></span>{{ t($minus['label']) }}@if (!empty($minus['note']))<span>· {{ $minus['note'] }}</span>@endif</li>
      @endif
    </ul>
  </div>
@endif
