@props(['done' => [], 'skipped' => [], 'label'])
<div class="flex flex-col gap-4 rounded-card bg-cream p-5">
  <div class="flex flex-col gap-2">
    <span class="text-[13px] font-medium text-muted-light">{{ $label }}</span>
    @if ($done)
      <ul class="flex flex-wrap gap-2">
        @foreach ($done as $key)
          <li class="rounded-full bg-booked px-3 py-1 text-[13px] font-medium text-booked-ink">{{ \App\Support\Days::format($key) }}</li>
        @endforeach
      </ul>
    @else
      <span class="text-[15px] text-muted-light">{{ t('No days') }}</span>
    @endif
  </div>
  @if ($skipped)
    <div class="flex flex-col gap-2">
      <span class="text-[13px] font-medium text-muted-light">{{ t('Skipped') }}</span>
      <ul class="flex flex-wrap gap-2">
        @foreach ($skipped as $key)
          <li class="rounded-full bg-cream-deep px-3 py-1 text-[13px] font-medium text-muted-light">{{ \App\Support\Days::format($key) }}</li>
        @endforeach
      </ul>
    </div>
  @endif
</div>
