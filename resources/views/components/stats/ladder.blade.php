@props(['steps', 'dangerStep'])
{{-- One step per strike level, one dot per person. --}}
<div class="flex items-end gap-2">
  @foreach ($steps as $i => $s)
    <div class="flex flex-1 flex-col items-center gap-2">
      <div class="flex min-h-8 flex-wrap justify-center gap-1">
        @foreach ($s['people'] as $p)
          <span title="{{ $p['name'] }}" class="size-4 rounded-full {{ $i === 0 ? 'bg-booked-ink' : ($i === $dangerStep ? 'bg-lime' : ($i === count($steps) - 1 ? 'border-2 border-lime bg-transparent' : 'bg-ink')) }}"></span>
        @endforeach
      </div>
      <div class="w-full rounded-t-[6px]" style="height: {{ 12 + $i * 14 }}px; background: rgb(17 17 16 / {{ 0.08 + ($i / max(1, count($steps) - 1)) * 0.5 }})"></div>
      <span class="text-center text-[11px] {{ $i === $dangerStep ? 'font-medium text-ink' : 'text-mute' }}">{{ $s['label'] }}</span>
    </div>
  @endforeach
</div>
