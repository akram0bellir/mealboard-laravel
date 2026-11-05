@props(['card', 'plan', 'rules', 'mealTimes' => [], 'index' => 0])
@php
    $status = $card['status'];
    $open = $status === 'open';
    $booked = $card['booked'];
    $hasBooking = $booked !== [];
    $date = $card['date'];
    $weekday = $date->translatedFormat('l');
    $month = $date->translatedFormat('M');
    $icons = ['breakfast' => 'coffee', 'lunch' => 'sun', 'dinner' => 'moon'];
@endphp
<article
  aria-label="{{ $weekday }} {{ $month }} {{ $date->day }}{{ $hasBooking ? ', ' . t('Booked') : '' }}, {{ t($status === 'open' ? 'Open' : ($status === 'past' ? 'Past' : 'Locked')) }}"
  style="--i: {{ $index }}"
  class="reveal flex min-h-[340px] flex-col gap-6 rounded-card p-6 transition-colors duration-200 {{ $open && $hasBooking ? 'bg-booked hover:bg-booked-deep' : '' }} {{ $open && !$hasBooking ? 'bg-page hover:bg-page/70' : '' }} {{ !$open ? 'bg-cream-deep/70' : '' }}">
  <header class="flex items-start justify-between gap-3">
    <div class="flex flex-col {{ !$open ? 'text-muted-light' : '' }}">
      <span class="text-[13px] font-medium">{{ $weekday }}{{ $card['today'] ? ' · ' . t('Today') : '' }}</span>
      <span class="text-5xl font-medium tabular-nums tracking-[-0.03em]">{{ $date->day }}</span>
      <span class="text-[13px] text-muted-light">{{ $month }}</span>
    </div>
    @if ($hasBooking)
      <span class="rounded-full px-3 py-1 text-[13px] font-medium {{ $open ? 'bg-booked-ink text-page' : 'bg-booked text-booked-ink' }}">{{ t('Booked') }}</span>
    @elseif ($open)
      <span class="rounded-full bg-cream px-3 py-1 text-[13px] font-medium">{{ t('Open') }}</span>
    @else
      <span class="rounded-full bg-cream px-3 py-1 text-[13px] font-medium text-muted-light">{{ $status === 'past' ? t('Past') : t('Locked') }}</span>
    @endif
  </header>

  <div role="group" aria-label="{{ t('Meals for {day}', ['day' => $weekday]) }}" class="flex flex-col gap-2">
    @foreach (['breakfast', 'lunch', 'dinner'] as $meal)
      @php
        $label = $rules->mealLabel($meal);
        $selected = in_array($meal, $booked, true);
        $dish = $card['menu'][$meal] ?? null;
        $inPlan = in_array($meal, $plan['meals'], true) && !$card['outOfAccess'];
        $interactive = $open && ($selected || ($dish && $inPlan && !$card['full']));
        $dishText = !$dish ? t('Not served') : ($inPlan ? $dish['name'] : ($rules->mealClosed($meal) ? t('Closed for Ramadan') : t('Not in your plan')));
      @endphp
      <label
        @if (!$interactive) aria-disabled="true" @endif
        @if ($dish) title="{{ $dish['name'] }} — {{ $dish['description'] }}" @endif
        aria-label="{{ $label }}{{ $dish ? ': ' . $dish['name'] . ($dish['description'] ? ' — ' . $dish['description'] : '') : ', ' . t('not on the menu') }}"
        class="group flex min-h-11 flex-col justify-center gap-0.5 rounded-[22px] px-4 py-2 text-[15px] transition-all duration-200 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ink
          {{ $selected && $open ? 'bg-booked-ink text-page hover:scale-[1.02] has-[:checked]:scale-100 has-[:checked]:bg-danger-soft has-[:checked]:text-danger has-[:checked]:line-through' : '' }}
          {{ $selected && !$open ? 'bg-booked text-booked-ink' : '' }}
          {{ !$selected && $interactive ? ($hasBooking ? 'bg-page/60 hover:bg-page' : 'bg-cream hover:bg-cream-deep') . ' has-[:checked]:bg-lime has-[:checked]:text-ink has-[:checked]:ring-2 has-[:checked]:ring-ink' : '' }}
          {{ !$selected && !$interactive ? 'text-muted-light/70' : '' }}
          {{ $interactive ? 'cursor-pointer' : 'cursor-not-allowed' }}">
        @if ($interactive)
          <input type="checkbox" form="week-form" class="sr-only" name="sel[{{ $card['key'] }}][{{ $meal }}]" value="{{ $selected ? 'cancel' : 'book' }}">
        @endif
        <span class="flex w-full items-center gap-2">
          <x-dynamic-component :component="'lucide-' . $icons[$meal]" class="size-4 shrink-0" aria-hidden="true" />
          <span class="shrink-0 text-start">{{ $label }}</span>
          <span dir="auto" class="min-w-0 flex-1 truncate text-start text-[13px] opacity-70">{{ $dishText }}</span>
          @if ($interactive)
            <span class="hidden shrink-0 text-[12px] font-medium no-underline group-has-[:checked]:inline">{{ $selected ? t('To cancel') : t('Selected') }}</span>
          @endif
          @if ($selected)
            <x-lucide-check class="size-4 shrink-0 group-has-[:checked]:hidden" aria-hidden="true" />
          @endif
        </span>
        @if ($dish && $dish['description'] && $inPlan)
          <span dir="auto" class="w-full ps-6 text-start text-[12px] leading-snug opacity-70">{{ $dish['description'] }}</span>
        @endif
        @if ($selected && isset($mealTimes[$meal]))
          <span class="w-full ps-6 text-start text-[12px] font-medium tabular-nums">{{ t('Your time') }} {{ $mealTimes[$meal] }}</span>
        @endif
      </label>
    @endforeach
  </div>

  <footer class="mt-auto flex flex-col items-start gap-3">
    @if ($open && $card['outOfAccess'])
      <p class="flex items-center gap-2 text-[13px] text-muted-light">
        <x-lucide-lock class="size-3.5" aria-hidden="true" />
        {{ t('Booking paused (no-shows)') }}
      </p>
    @elseif ($open)
      <p class="flex items-center gap-2 text-[13px] font-medium text-ink">
        <x-lucide-clock class="size-3.5" aria-hidden="true" />
        {{ $card['hint'] }}
      </p>
    @else
      <p class="flex items-center gap-2 text-[13px] text-muted-light">
        <x-lucide-lock class="size-3.5" aria-hidden="true" />
        {{ $status === 'past' ? t('Past day') : t('Locked · deadline passed') }}
      </p>
    @endif

    @if ($status === 'locked' && $hasBooking && $rules->lateCancelCounts)
      <a href="/week/cancel/{{ $card['key'] }}" class="flex h-9 items-center gap-1.5 rounded-full bg-danger-soft px-4 text-[13px] font-medium text-danger transition-transform duration-200 hover:scale-[1.02]">
        <x-lucide-x class="size-3.5" aria-hidden="true" />
        {{ t('Cancel late · counts as a no-show') }}
      </a>
    @endif
    @if ($open && $hasBooking)
      <a href="/week/cancel/{{ $card['key'] }}" class="flex h-9 items-center gap-1.5 rounded-full bg-danger-soft px-4 text-[13px] font-medium text-danger transition-transform duration-200 hover:scale-[1.02]">
        <x-lucide-x class="size-3.5" aria-hidden="true" />
        {{ t('Cancel booking') }}
      </a>
    @endif
  </footer>
</article>
