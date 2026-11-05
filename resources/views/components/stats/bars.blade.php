@props(['data', 'fmt', 'labels' => null, 'highlight' => null])
{{-- Horizontal bars, one row per group (and a lighter bar for the second value when comparing). --}}
@php
    $vals = [];
    foreach ($data as $d) {
        if (empty($d['hidden'])) {
            $vals[] = $d['value'];
            $vals[] = $d['compare'] ?? 0;
        }
    }
    $max = max(1e-9, ...($vals ?: [0]));
@endphp
@if (!$data)
  <p class="text-[14px] text-mute">{{ t('No data for this selection.') }}</p>
@else
  <div class="flex flex-col gap-3">
    @if ($labels)
      <ul class="flex gap-4 text-[12px] text-mute">
        <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-ink" aria-hidden="true"></span>{{ $labels[0] }}</li>
        <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-ink/30" aria-hidden="true"></span>{{ $labels[1] }}</li>
      </ul>
    @endif
    <ul class="flex flex-col gap-2.5">
      @foreach ($data as $d)
        @php $hidden = !empty($d['hidden']); $small = !empty($d['small']); @endphp
        <li class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-3 text-[14px]">
          <span class="min-w-0">
            <span class="block truncate {{ $d['key'] === $highlight ? 'font-medium' : '' }}">{{ $d['name'] }}</span>
            @if (!empty($d['note']))<span class="block truncate text-[11px] text-mute">{{ $d['note'] }}</span>@endif
          </span>
          @if ($hidden)
            <span class="text-[12px] text-mute italic">{{ t('Hidden: too few people to show without singling someone out') }}</span>
          @else
            <span class="flex flex-col gap-1">
              <span class="h-3 overflow-hidden rounded-full bg-ink/5"><span class="block h-full rounded-full {{ $d['key'] === $highlight ? 'bg-lime' : 'bg-ink' }} {{ $small ? 'opacity-50' : '' }}" style="width: {{ $d['value'] / $max * 100 }}%"></span></span>
              @if (isset($d['compare']))<span class="h-3 overflow-hidden rounded-full bg-ink/5"><span class="block h-full rounded-full bg-ink/30" style="width: {{ $d['compare'] / $max * 100 }}%"></span></span>@endif
            </span>
          @endif
          <span class="text-end tabular-nums {{ $small ? 'text-mute' : '' }}">
            {{ $hidden ? '—' : $fmt($d['value']) }}
            @if (isset($d['compare']) && !$hidden)<span class="block text-[12px] text-mute">{{ $fmt($d['compare']) }}</span>@endif
          </span>
        </li>
      @endforeach
    </ul>
    @if (collect($data)->contains(fn ($d) => !empty($d['small']) && empty($d['hidden'])))
      <p class="text-[11px] text-mute">{{ t('Faded bars: fewer than 10 meals, so read them with care.') }}</p>
    @endif
  </div>
@endif
