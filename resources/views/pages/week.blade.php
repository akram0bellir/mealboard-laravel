@php
    $mealIds = ['breakfast', 'lunch', 'dinner'];
    $timeLine = collect($mealIds)
        ->filter(fn ($m) => isset($mealTimes[$m]))
        ->map(fn ($m) => $rules->mealLabel($m) . ' ' . $mealTimes[$m])
        ->implode(' · ');
@endphp
<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      {{-- One form: ticking a meal selects it; "Confirm" opens the confirmation. The form is empty and the
           meals and buttons join it with form="week-form", because a form can't contain the top bar's own forms. --}}
      <form id="week-form" method="POST" action="/week/review" data-week-form>@csrf</form>
      <div class="contents">
        <x-hero-slab>
          <x-top-bar />
          <div class="relative z-10 grid gap-10 pt-14 sm:pt-20 lg:grid-cols-12 lg:items-end">
            <div class="reveal flex flex-col gap-4 lg:col-span-7">
              <x-pill-label dark>{{ t('This week & next') }} · {{ $rangeLabel }}</x-pill-label>
              <h1 class="text-balance text-4xl font-medium leading-[1.05] tracking-[-0.02em] sm:text-[56px]">
                {{ t('Hi {name}, plan your', ['name' => $firstName]) }} <span class="text-lime">{{ t('week') }}</span>.
              </h1>
              <p class="max-w-[52ch] text-[17px] leading-relaxed text-muted-dark">
                {{ t($plan['description']) }}
                {{ $single
                    ? t('Book, switch or cancel until {deadline} — then the day locks.', ['deadline' => $rules->deadlineLabel()])
                    : t('Book or cancel until {deadline} — then the day locks.', ['deadline' => $rules->deadlineLabel()]) }}
              </p>
              @if ($blockedUntil !== '')
                <p class="w-fit rounded-full bg-danger-soft px-5 py-2.5 text-[15px] font-medium text-danger">
                  {{ t('Booking is paused until {date} after too many no-shows.', ['date' => \App\Support\Days::format($blockedUntil, true)]) }}
                </p>
              @endif
              @if ($rules->ramadan)
                <p class="w-fit rounded-full bg-ink-3 px-5 py-2.5 text-[15px] font-medium">
                  {{ t('Ramadan Kareem · lunch is closed — book suhoor or iftar.') }}
                </p>
              @endif
              @if ($timeLine !== '')
                <p class="text-[15px] text-muted-dark">{{ t('Your time') }} · {{ $timeLine }}</p>
              @endif
              <dl class="mt-2 flex gap-8">
                <div>
                  <dt class="text-[13px] text-muted-dark">{{ t('Booked') }}</dt>
                  <dd class="text-3xl font-medium tabular-nums">{{ $bookedCount }}/{{ count($cards) }}</dd>
                </div>
                <div>
                  <dt class="text-[13px] text-muted-dark">{{ t('Still open') }}</dt>
                  <dd class="text-3xl font-medium tabular-nums">{{ $openCount }}</dd>
                </div>
              </dl>
            </div>
          </div>
        </x-hero-slab>

        <x-cream-slab :label="t('Meals for this week and next week')">
          {{-- Mobile: day list --}}
          <div class="sm:hidden">
            <x-week.mobile-picker :cards="$cards" :plan="$plan" :rules="$rules" :meal-times="$mealTimes" />
          </div>

          {{-- Tablet & desktop: card grid --}}
          <ul class="hidden gap-4 sm:grid sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cards as $i => $card)
              <li class="flex flex-col">
                <x-week.day-card :card="$card" :plan="$plan" :rules="$rules" :meal-times="$mealTimes" :index="$i" />
              </li>
            @endforeach
            <li class="reveal flex flex-col justify-end gap-5 p-6" style="--i: 7">
              <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ t('Reading the week') }}</h2>
              <ul class="flex flex-col gap-3 text-[15px] text-muted-light">
                @foreach ([
                    ['bg-booked-ink', 'Booked — tap the meal again to cancel'],
                    ['bg-page', 'Open — tap a meal to book'],
                    ['bg-cream-deep', 'Locked or past — read only'],
                    ['bg-danger', 'Cancel & no-shows'],
                ] as [$swatch, $text])
                  <li class="flex items-center gap-3">
                    <span aria-hidden="true" class="size-4 shrink-0 rounded-full {{ $swatch }}"></span>
                    {{ t($text) }}
                  </li>
                @endforeach
              </ul>
            </li>
          </ul>
        </x-cream-slab>

        {{-- Always shown without JavaScript; with it, shown only while something is ticked (resources/js/app.js). --}}
        <div role="region" aria-label="{{ t('Selected meals') }}" data-week-bar
             data-book="{{ t('{count} to book') }}" data-cancel="{{ t('{count} to cancel') }}"
             class="fixed inset-x-3 bottom-3 z-30 mx-auto flex max-w-xl flex-wrap items-center justify-between gap-3 rounded-full bg-ink py-2 pe-2 ps-6 text-page shadow-lg"
             style="margin-bottom: env(safe-area-inset-bottom, 0px)">
          <p class="text-[15px]" data-week-count>{{ t('Tick the meals you want, then confirm.') }}</p>
          <div class="flex gap-2">
            <button type="reset" form="week-form" class="flex h-11 items-center gap-1.5 rounded-full px-4 text-[14px] font-medium text-muted-dark transition-colors hover:text-page">
              <x-lucide-x class="size-4" aria-hidden="true" />
              {{ t('Clear') }}
            </button>
            <button type="submit" form="week-form" class="flex h-11 items-center gap-1.5 rounded-full bg-lime px-5 text-[14px] font-medium text-ink transition-transform duration-200 hover:scale-[1.02]">
              <x-lucide-check class="size-4" aria-hidden="true" />
              {{ t('Confirm') }}
            </button>
          </div>
        </div>
      </div>
    </main>
  </x-page-frame>

  @if (!empty($dialog))
    <x-week.dialog :dialog="$dialog" />
  @endif
</x-layouts.app>
