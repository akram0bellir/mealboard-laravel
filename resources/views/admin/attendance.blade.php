@php use App\Support\Days; @endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Attendance')"
                     :description="t('Mark the meals nobody came for — pick a meal to mark just that one. Each missed meal is a strike; {0} within {1} days of the first one pause that person\'s booking until the period ends.', [0 => $rules->maxNoShow, 1 => $rules->periodDays])">
      <x-slot:title>{{ t('Who') }} <span class="text-lime">{{ t("didn't") }}</span> {{ t('show up?') }}</x-slot:title>
    </x-admin.heading>

    <form method="GET" action="/attendance" data-autosubmit class="mt-10 flex flex-col gap-4 md:flex-row md:flex-wrap md:items-center">
      <label for="attendance-date" class="sr-only">{{ t('Day') }}</label>
      <x-admin.input id="attendance-date" type="date" name="date" max="{{ $today }}" value="{{ $date }}" class="!h-11 !w-auto border-ink-3 !bg-ink-2 !text-page [color-scheme:dark]" />
      <x-admin.segmented name="meal" :label="t('Meal')" dark :value="$meal"
                         :options="['all' => 'All meals', 'breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner']" />
      <x-admin.btn type="submit" variant="soft-dark" size="md">{{ t('Show') }}</x-admin.btn>
      <p class="text-[15px] text-mute-dark md:ms-auto">
        {{ count($orders) === 1 ? t('1 order') : t('{0} orders', [0 => count($orders)]) }} ·
        <span class="text-lime">{{ $missedCount === 1 ? t('1 no-show') : t('{0} no-shows', [0 => $missedCount]) }}</span>
      </p>
    </form>
  </x-admin.section>

  <x-admin.section tone="cream">
    <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ Days::fromKey($date)->translatedFormat('l j F') }}</h2>
    <p role="status" aria-live="polite" class="mt-2 min-h-5 text-[13px] text-ink">{{ $status ?? '' }}</p>

    @if (count($orders) === 0)
      <p class="mt-6 text-[15px] text-mute">{{ t('No orders for this day') }}{{ $meal !== 'all' ? t(' and {0}', [0 => mb_strtolower($rules->mealLabel($meal))]) : '' }}.</p>
    @else
      <ul class="mt-6 flex flex-col gap-2">
        @foreach ($orders as $o)
          @php
              $target = $meal === 'all' ? $o['meals'] : [$meal];
              $isMissed = $meal === 'all' ? count($o['missed']) > 0 : in_array($meal, $o['missed'], true);
              $undo = count(array_diff($target, $o['missed'])) === 0;
              $st = $o['standing'];
          @endphp
          <li class="flex flex-col gap-3 rounded-[24px] py-3 pe-3 ps-5 sm:flex-row sm:items-center sm:justify-between sm:rounded-full {{ $isMissed ? 'bg-ink text-page' : 'bg-page' }}">
            <div class="min-w-0">
              <p class="font-medium">{{ $o['name'] }}</p>
              <p class="text-[13px] {{ $isMissed ? 'text-mute-dark' : 'text-mute' }}">
                {{ collect($o['meals'])->map(fn ($m) => in_array($m, $o['missed'], true) ? t('{0} (missed)', [0 => $rules->mealLabel($m)]) : $rules->mealLabel($m))->implode(' + ') }}
                @if ($st){{ t(' · {0} of {1} strikes', [0 => $st['strikes'], 1 => $rules->maxNoShow]) }}@endif
                @if ($st && $st['blocked_until'] !== ''){{ t(' · blocked until {0}', [0 => Days::fromKey($st['blocked_until'])->translatedFormat('j M')]) }}@endif
              </p>
            </div>
            <form method="POST" action="/attendance/{{ $o['id'] }}">
              @csrf
              <input type="hidden" name="meals" value="{{ implode(',', $target) }}">
              <input type="hidden" name="meal" value="{{ $meal }}">
              <input type="hidden" name="undo" value="{{ $undo ? 1 : 0 }}">
              <x-admin.btn type="submit" size="sm" :variant="$isMissed ? 'soft-dark' : 'dark'">
                @if ($undo)<x-lucide-rotate-ccw />@else<x-lucide-user-x />@endif
                {{ $undo ? t('Undo no-show') : t('Mark no-show') }}
              </x-admin.btn>
            </form>
          </li>
        @endforeach
      </ul>
    @endif
    <p class="mt-6 flex items-center gap-2 text-[13px] text-mute"><x-lucide-ban class="size-3.5" aria-hidden="true" />{{ t('Undoing a no-show removes the strike and lifts the block if it falls below the limit.') }}</p>
  </x-admin.section>
</x-layouts.admin>
