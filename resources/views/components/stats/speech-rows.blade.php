@props(['rows'])
{{-- One speech bubble per report; filled = answered, outline = waiting. --}}
<div class="flex flex-col gap-3">
  @foreach ($rows as $r)
    <div class="grid grid-cols-[8rem_1fr] items-center gap-3 text-[13px]">
      <span class="text-mute">{{ $r['label'] }}</span>
      <span class="flex flex-wrap gap-1">
        @for ($i = 0; $i < $r['answered']; $i++)<x-lucide-message-square class="size-5 fill-booked-ink text-booked-ink" aria-hidden="true" />@endfor
        @for ($i = 0; $i < $r['waiting']; $i++)<x-lucide-message-square class="size-5 text-lime" aria-hidden="true" />@endfor
        @if (!$r['answered'] && !$r['waiting'])<span class="text-ink/20">—</span>@endif
      </span>
    </div>
  @endforeach
  <p class="flex gap-4 text-[12px] text-mute">
    <span class="flex items-center gap-1.5"><x-lucide-message-square class="size-4 fill-booked-ink text-booked-ink" /> {{ t('answered') }}</span>
    <span class="flex items-center gap-1.5"><x-lucide-message-square class="size-4 text-lime" /> {{ t('waiting for an answer') }}</span>
  </p>
</div>
