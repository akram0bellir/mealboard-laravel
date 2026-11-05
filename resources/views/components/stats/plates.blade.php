@props(['eaten', 'missed', 'max' => 60])
{{-- One plate per meal; full = eaten, dashed = booked but missed. Large totals: one plate stands for several meals. --}}
@php
    $total = $eaten + $missed;
    $per = max(1, (int) ceil($total / $max));
    $full = (int) round($eaten / $per);
    $empty = max(0, (int) round($total / $per) - $full);
@endphp
@if (!$total)
  <p class="text-[14px] text-mute">{{ t('No meals in this period.') }}</p>
@else
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap gap-1.5" role="img" aria-label="{{ t('{0} meals eaten, {1} missed', [0 => $eaten, 1 => $missed]) }}">
      @for ($i = 0; $i < $full; $i++)<span class="size-6 rounded-full bg-booked-ink"></span>@endfor
      @for ($i = 0; $i < $empty; $i++)<span class="size-6 rounded-full border-2 border-dashed border-lime"></span>@endfor
    </div>
    <p class="flex flex-wrap gap-4 text-[12px] text-mute">
      <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-booked-ink"></span> {{ t('eaten ·') }} {{ $eaten }}</span>
      <span class="flex items-center gap-1.5"><span class="size-3 rounded-full border-2 border-dashed border-lime"></span> {{ t('booked but missed ·') }} {{ $missed }}</span>
      @if ($per > 1)<span>{{ t('each plate =') }} {{ $per }} {{ t('meals') }}</span>@endif
    </p>
  </div>
@endif
