{{-- Feedback (speech bubbles) and Booking behaviour (lead-time timeline, regular vs occasional). --}}
@php $b = $tiles['booking']; @endphp
<div class="flex flex-col gap-6 xl:col-span-4">
  <x-stats.block icon="message-square-text" :title="t('Feedback')" :question="t('What people complain about, and whether we answered — one bubble per report.')">
    <x-slot:actions>
      <x-admin.btn href="/reports" size="sm" variant="ghost">{{ t('Open reports') }}</x-admin.btn>
    </x-slot:actions>
    <div class="rounded-card bg-page p-6">
      @if ($tiles['feedback']['count'])
        <x-stats.speech-rows :rows="$tiles['feedback']['rows']" />
      @else
        <p class="text-[14px] text-mute">{{ t('No reports in this period.') }}</p>
      @endif
    </div>
  </x-stats.block>

  <x-stats.block icon="calendar-clock" :title="t('Booking behaviour')" :question="t('When do people decide — early, or right before the deadline?')">
    <div class="grid grid-cols-1 gap-6">
      <x-stats.panel :title="t('How long before the meal it was booked')">
        @if ($b['leads'])
          <x-stats.lead-timeline :leads="$b['leads']" :deadline-days="$b['deadlineDays']" />
        @else
          <p class="text-[14px] text-mute">{{ t('No bookings.') }}</p>
        @endif
      </x-stats.panel>
      <x-stats.panel :title="t('Regular or occasional')">
        <div class="flex flex-wrap gap-1">
          @for ($i = 0; $i < $b['regular']; $i++)<span class="size-3 rounded-full bg-ink"></span>@endfor
          @for ($i = 0; $i < $b['occasional']; $i++)<span class="size-3 rounded-full border border-ink/50"></span>@endfor
        </div>
        <p class="text-[12px] text-mute">{{ t('Each circle is a person · filled = books on most days · outline = only now and then') }}</p>
      </x-stats.panel>
    </div>
  </x-stats.block>
</div>
