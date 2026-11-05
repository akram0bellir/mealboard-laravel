@props(['cards', 'plan', 'rules', 'mealTimes' => []])
@php
    $first = $plan['meals'][0] ?? null;
    // In Ramadan everyone has suhoor and iftar, so each day lists both.
    $shown = $first === null ? [] : ($rules->ramadan ? $plan['meals'] : [$first]);
@endphp
@if ($first === null)
  <section aria-label="This week bookings" class="rounded-card bg-page p-6 text-[15px] text-muted-light">
    {{ t('No meals to book right now.') }}
  </section>
@else
  <section aria-label="{{ t('This week bookings') }}" class="rounded-card bg-page p-4">
    <ul class="flex flex-col divide-y divide-ink/10">
      @foreach ($cards as $card)
        @foreach ($shown as $meal)
          @php
            $date = $card['date'];
            $status = $card['status'];
            $open = $status === 'open';
            $dish = $card['menu'][$meal] ?? null;
            $booked = in_array($meal, $card['booked'], true);
            $label = $rules->mealLabel($meal);
            $interactive = $open && ($booked || ($dish && !$card['outOfAccess']));
            $line1 = $date->translatedFormat('l') . ' ' . $date->day . ' ' . $date->translatedFormat('M');
            $description = !$dish ? t('{meal} not served', ['meal' => $label]) : ($dish['description'] ?: $dish['name']);
            $hint = !$open ? ($status === 'past' ? t('Past day') : t('Locked · deadline passed')) : ($card['outOfAccess'] ? t('Booking paused (no-shows)') : $card['hint']);
            $lateCancel = $booked && $status === 'locked' && $rules->lateCancelCounts;
          @endphp
          <li aria-label="{{ $line1 }}"
              class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 {{ $booked && $open ? 'bg-booked rounded-[16px] px-3 my-1' : '' }} {{ !$open ? 'text-muted-light' : '' }}">
            <h3 class="text-xl font-medium tracking-[-0.01em]">
              {{ $line1 }}
              @if ($card['today'])<span class="text-[13px] font-medium text-muted-light"> · {{ t('Today') }}</span>@endif
            </h3>

            <p dir="auto" class="text-[15px] text-start {{ $dish ? 'text-ink' : 'text-muted-light' }}">{{ $description }}</p>

            <div class="flex flex-wrap items-center gap-3">
              @if ($interactive)
                <label class="group flex h-11 cursor-pointer items-center gap-2 rounded-full px-5 text-[14px] font-medium transition-transform duration-200 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ink
                    {{ $booked ? 'bg-booked-ink text-page has-[:checked]:bg-danger-soft has-[:checked]:text-danger has-[:checked]:line-through' : 'bg-ink text-page hover:scale-[1.02] has-[:checked]:bg-lime has-[:checked]:text-ink has-[:checked]:ring-2 has-[:checked]:ring-ink' }}">
                  <input type="checkbox" form="week-form" class="sr-only" name="sel[{{ $card['key'] }}][{{ $meal }}]" value="{{ $booked ? 'cancel' : 'book' }}">
                  <span aria-hidden="true" class="flex size-5 items-center justify-center rounded-full border {{ $booked ? 'border-page bg-page text-booked-ink' : 'border-current' }}">
                    @if ($booked)<x-lucide-check class="size-3.5" />@endif
                  </span>
                  <span class="group-has-[:checked]:hidden">{{ $booked ? t('{meal} booked', ['meal' => $label]) : t('Book {meal}', ['meal' => mb_strtolower($label)]) }}</span>
                  <span class="hidden group-has-[:checked]:inline">{{ $booked ? t('{meal} to cancel', ['meal' => $label]) : t('{meal} selected', ['meal' => $label]) }}</span>
                </label>
              @else
                <span aria-disabled="true" class="flex h-11 cursor-not-allowed items-center gap-2 rounded-full px-5 text-[14px] font-medium {{ $booked ? 'bg-booked-ink text-page' : 'bg-cream text-muted-light/70' }}">
                  <span aria-hidden="true" class="flex size-5 items-center justify-center rounded-full border {{ $booked ? 'border-page bg-page text-booked-ink' : 'border-current' }}">
                    @if ($booked)<x-lucide-check class="size-3.5" />@endif
                  </span>
                  {{ $booked ? t('{meal} booked', ['meal' => $label]) : t('Book {meal}', ['meal' => mb_strtolower($label)]) }}
                </span>
              @endif

              <p class="flex items-center gap-2 text-[13px] text-muted-light">
                @if ($open && !$card['outOfAccess'])
                  <x-lucide-clock class="size-3.5" aria-hidden="true" />
                @else
                  <x-lucide-lock class="size-3.5" aria-hidden="true" />
                @endif
                {{ $hint }}
                @if ($booked && isset($mealTimes[$meal]))<span class="font-medium text-ink tabular-nums">· {{ t('Your time') }} {{ $mealTimes[$meal] }}</span>@endif
              </p>

              @if ($lateCancel)
                <a href="/week/cancel/{{ $card['key'] }}?meals={{ $meal }}" class="flex h-9 items-center rounded-full bg-danger-soft px-4 text-[13px] font-medium text-danger">{{ t('Cancel late · counts as a no-show') }}</a>
              @endif
            </div>
          </li>
        @endforeach
      @endforeach
    </ul>
  </section>
@endif
