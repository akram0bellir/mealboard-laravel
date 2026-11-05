@php
    use App\Support\Days;
    $meals = ['breakfast', 'lunch', 'dinner'];
    $long = fn (string $k) => Days::fromKey($k)->translatedFormat('l j F');
    $weekRange = fn (string $w) => Days::short(Days::fromKey($w)) . ' – ' . Days::short(Days::fromKey(Days::add($w, 6)));
    $statusOf = function (string $d) use ($today) {
        $diff = (int) Days::fromKey($today)->diffInDays(Days::fromKey($d), false);
        return $diff < 0 ? 'past' : ($diff === 0 ? 'today' : ($diff < \App\Http\Controllers\Admin\MealController::WINDOW_DAYS ? 'open' : 'draft'));
    };
    $q = fn (array $extra = []) => '/meals?' . str_replace('%3A', ':', http_build_query(array_filter(['week' => $week, 'arm' => $armed->id ?? null] + $extra)));
    $kindOf = fn (string $m) => $m === 'breakfast' ? 'Breakfast' : 'Lunch/dinner';
    $total = \App\Http\Controllers\Admin\MealController::WINDOW_DAYS * 3;
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Meal planning')" :description="t('Breakfast, lunch and dinner for every day. Users can book anything inside the {0}-day window; meals further out stay as drafts.', [0 => \App\Http\Controllers\Admin\MealController::WINDOW_DAYS])">
      <x-slot:title>{{ t('Plan the next') }} <span class="text-lime">{{ \App\Http\Controllers\Admin\MealController::WINDOW_DAYS }} {{ t('days') }}</span></x-slot:title>
    </x-admin.heading>

    <div class="mt-12 grid grid-cols-1 gap-6 md:grid-cols-3">
      <x-admin.card tone="ink-2" :delay="1">
        <p class="text-[13px] text-mute-dark">{{ t('Booking window filled') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em]">{{ $windowFilled }}<span class="text-2xl text-mute-dark">/{{ $total }}</span></p>
        <div class="mt-5 h-2 overflow-hidden rounded-full bg-ink-3" aria-hidden="true">
          <div class="h-full rounded-full bg-lime" style="width: {{ ($windowFilled / $total) * 100 }}%"></div>
        </div>
      </x-admin.card>
      <x-admin.card :delay="2">
        <p class="text-[13px] text-mute-dark">{{ t('Window ends') }}</p>
        <p class="mt-2 text-2xl font-medium">{{ $long($windowEnd) }}</p>
        <p class="mt-2 text-[15px] text-mute-dark">{{ t('Slots after this date are saved as drafts.') }}</p>
      </x-admin.card>
      <x-admin.card :delay="3">
        <p class="text-[13px] text-mute-dark">{{ t('Templates saved') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em]">{{ $templates->count() }}</p>
        <p class="mt-2 text-[15px] text-mute-dark">{{ t('Reuse any of them in one click below.') }}</p>
      </x-admin.card>
    </div>
  </x-admin.section>

  <x-admin.section tone="none">
    <div class="flex flex-col gap-8 xl:flex-row xl:items-center xl:justify-between">
      <div class="flex flex-wrap items-center gap-3">
        <x-admin.btn :href="$q(['week' => Days::add($week, -7)])" variant="dark" size="icon" aria-label="{{ t('Previous week') }}"><x-lucide-chevron-left /></x-admin.btn>
        <div class="min-w-44 px-2">
          <p class="text-[13px] text-mute">
            @if ($weekOffset === 0){{ t('This week') }}
            @elseif ($weekOffset > 0){{ $weekOffset === 1 ? t('1 week ahead') : t('{0} weeks ahead', [0 => $weekOffset]) }}
            @else{{ $weekOffset === -1 ? t('1 week ago') : t('{0} weeks ago', [0 => -$weekOffset]) }}@endif
          </p>
          <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ $weekRange($week) }}</h2>
        </div>
        <x-admin.btn :href="$q(['week' => Days::add($week, 7)])" variant="dark" size="icon" aria-label="{{ t('Next week') }}"><x-lucide-chevron-right /></x-admin.btn>
        <x-admin.btn :href="$q(['week' => Days::add($week, 28)])" variant="soft">{{ t('+4 weeks') }}</x-admin.btn>
        @if ($weekOffset !== 0)
          <x-admin.btn :href="$q(['week' => $currentWeek])" variant="ghost">{{ t('Today') }}</x-admin.btn>
        @endif
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <form method="GET" action="/meals" data-autosubmit class="flex items-center gap-3">
          @if ($armed)<input type="hidden" name="arm" value="{{ $armed->id }}">@endif
          <label class="sr-only" for="week-picker">{{ t('Jump to week') }}</label>
          <x-admin.input tone="page" id="week-picker" type="date" name="week" value="{{ $week }}" class="!h-11 !w-auto" />
          <x-admin.btn type="submit" variant="soft">{{ t('Go') }}</x-admin.btn>
        </form>
        <form method="POST" action="/meals/copy">
          @csrf
          <input type="hidden" name="week" value="{{ $week }}">
          <input type="hidden" name="count" value="1">
          <x-admin.btn type="submit" variant="primary"><x-lucide-copy />{{ t('Copy week to next week') }}</x-admin.btn>
        </form>
      </div>
    </div>

    @if ($armed)
      <div class="mt-8 flex flex-wrap items-center justify-between gap-3 rounded-full bg-ink py-2 pe-2 ps-5 text-page">
        <p class="text-[15px]"><x-lucide-bookmark class="me-2 inline size-4 text-lime" aria-hidden="true" />{{ t('Click any slot to place') }} <span class="text-lime">{{ $armed->title }}</span></p>
        <x-admin.btn :href="'/meals?week=' . $week" variant="soft-dark" size="sm"><x-lucide-x />{{ t('Done') }}</x-admin.btn>
      </div>
    @endif

    <p role="status" aria-live="polite" class="mt-8 min-h-5 text-[13px] text-ink">{{ $status ? t($status) : '' }}</p>

    <div class="mt-8 overflow-x-auto pb-0">
      <div class="grid min-w-[1200px] grid-cols-7 gap-8">
        @foreach ($days as $i => $date)
          @php $st = $statusOf($date); $locked = $st === 'past'; @endphp
          <div class="reveal flex flex-col gap-8" style="animation-delay: {{ $i * 80 }}ms">
            <div class="flex items-start justify-between px-2 pb-1">
              <div>
                <p class="text-[13px] font-medium {{ $st === 'today' ? 'text-ink' : 'text-mute' }}">{{ Days::fromKey($date)->translatedFormat('D') }}</p>
                <p class="text-lg font-medium">{{ Days::fromKey($date)->translatedFormat('j M') }}</p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-[11px] font-medium {{ ['today' => 'bg-ink text-lime', 'open' => 'bg-page text-ink', 'draft' => 'bg-cream-deep text-mute', 'past' => 'text-mute'][$st] }}">{{ t(['past' => 'Past', 'today' => 'Today', 'open' => 'Open', 'draft' => 'Draft'][$st]) }}</span>
            </div>

            @foreach ($meals as $meal)
              @php
                  $dish = $menu[$date][$meal] ?? null;
                  $booked = $bookings[$date][$meal] ?? 0;
                  $label = $rules->mealLabel($meal);
                  $slot = 'group flex min-h-36 w-38 h-52 flex-col justify-between gap-3 rounded-[20px] p-4 text-start transition duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink '
                        . ($dish ? 'bg-cream-deep hover:bg-[#e1ddcc]' : 'bg-transparent hover:bg-cream-deep')
                        . ($locked ? ' cursor-not-allowed opacity-50 hover:bg-transparent' : ' cursor-pointer')
                        . ($armed && !$locked ? ' ring-2 ring-ink ring-offset-2 ring-offset-cream' : '');
              @endphp
              @php ob_start(); @endphp
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[12px] font-medium text-mute">{{ $label }}</span>
                  @if ($locked)<x-lucide-lock class="size-3.5 text-mute" aria-hidden="true" />@endif
                </div>
                @if ($dish)
                  <div class="flex flex-col gap-1"><p class="text-[15px] leading-snug font-medium" dir="auto">{{ $dish['name'] }}</p></div>
                @else
                  <span class="inline-flex items-center gap-1.5 text-[13px] text-mute group-hover:text-ink"><x-lucide-plus class="size-4" aria-hidden="true" />{{ t('Add') }} {{ mb_strtolower($label) }}</span>
                @endif
                @if ($dish && $booked > 0)
                  <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-ink px-2.5 py-1 text-[11px] font-medium text-lime"><x-lucide-triangle-alert class="size-3" aria-hidden="true" />{{ $booked }} {{ t('booked') }}</span>
                @endif
              @php $inner = ob_get_clean(); @endphp
              @if ($locked)
                <span aria-disabled="true" class="{{ $slot }}">{!! $inner !!}</span>
              @elseif ($armed)
                <form method="POST" action="/meals/place" class="contents">
                  @csrf
                  <input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="meal" value="{{ $meal }}"><input type="hidden" name="template" value="{{ $armed->id }}">
                  <button type="submit" class="{{ $slot }}" aria-label="{{ t('Place template in {0}', [0 => $label . ', ' . Days::fromKey($date)->translatedFormat('D j M')]) }}">{!! $inner !!}</button>
                </form>
              @else
                <a href="{{ $q(['slot' => $date . ':' . $meal]) }}" class="{{ $slot }}" aria-label="{{ t('Edit {0}', [0 => $label . ', ' . Days::fromKey($date)->translatedFormat('D j M') . ': ' . ($dish['name'] ?? t('empty'))]) }}">{!! $inner !!}</a>
              @endif
            @endforeach
          </div>
        @endforeach
      </div>
    </div>
  </x-admin.section>

  {{-- ------------------------------------------------------ special meals --}}
  @php
      $e = $editSpecial;
      $sTotals = collect($specialKinds)->map(fn ($label, $kind) => collect($specials)->where('kind', $kind)->sum('count'));
  @endphp
  <x-admin.section tone="none" class="px-0 md:px-0" id="special">
    <div class="px-2 md:px-6">
      <x-admin.pill>{{ t('Special meals') }}</x-admin.pill>
      <h2 class="mt-4 text-3xl font-medium tracking-[-0.02em] md:text-4xl">{{ t('Enhanced, VIP, special meals & snacks') }}</h2>
      <p class="mt-2 text-[15px] text-mute">{{ t('Extra meals for') }} {{ $weekRange($week) }}{{ t('. Only admins see these.') }}</p>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-12">
      <x-admin.card tone="cream" class="lg:col-span-5">
        <form method="POST" action="{{ $e ? '/meals/special/' . $e['id'] : '/meals/special' }}" class="flex flex-col gap-5">
          @csrf
          <input type="hidden" name="week" value="{{ $week }}">
          <h3 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-sparkles class="size-5" aria-hidden="true" />{{ $e ? t('Edit special meal') : t('Add special meal') }}</h3>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-admin.field :label="t('Type')" for="s-kind" class="sm:col-span-2">
              <x-admin.select tone="page" id="s-kind" name="kind">
                @foreach ($specialKinds as $kind => $label)<option value="{{ $kind }}" @selected(($e['kind'] ?? 'enhanced') === $kind)>{{ t($label) }}</option>@endforeach
              </x-admin.select>
            </x-admin.field>
            <x-admin.field :label="t('Day')" for="s-date">
              <x-admin.select tone="page" id="s-date" name="date">
                @foreach ($days as $d)<option value="{{ $d }}" @selected(($e['date'] ?? $days[0]) === $d)>{{ Days::fromKey($d)->translatedFormat('D j M') }}</option>@endforeach
              </x-admin.select>
            </x-admin.field>
            <x-admin.field :label="t('Meal')" for="s-meal">
              <x-admin.select tone="page" id="s-meal" name="meal">
                <option value="lunch" @selected(($e['meal'] ?? 'lunch') === 'lunch')>{{ t('Lunch') }}</option>
                <option value="dinner" @selected(($e['meal'] ?? '') === 'dinner')>{{ t('Dinner') }}</option>
              </x-admin.select>
            </x-admin.field>
            <x-admin.field :label="t('How many')" for="s-count">
              <x-admin.input tone="page" id="s-count" type="number" name="count" min="1" step="1" value="{{ $e['count'] ?? 1 }}" required />
            </x-admin.field>
            <x-admin.field :label="t('Note')" for="s-note">
              <x-admin.input tone="page" id="s-note" name="note" value="{{ $e['note'] ?? '' }}" placeholder="{{ t('e.g. Board visit') }}" />
            </x-admin.field>
          </div>
          <div class="flex flex-wrap gap-3">
            <x-admin.btn type="submit" variant="dark">@unless($e)<x-lucide-plus />@endunless {{ $e ? t('Save changes') : t('Add special meal') }}</x-admin.btn>
            @if ($e)<x-admin.btn :href="$q() . '#special'" variant="soft">{{ t('Cancel') }}</x-admin.btn>@endif
          </div>
        </form>
      </x-admin.card>

      <x-admin.card class="flex flex-col gap-6 lg:col-span-7">
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">
          @foreach ($specialKinds as $kind => $label)
            <div><p class="text-[13px] text-mute">{{ t($label) }}</p><p class="mt-1 text-4xl font-medium tracking-[-0.03em]">{{ $sTotals[$kind] }}</p></div>
          @endforeach
        </div>
        @if (count($specials) === 0)
          <p class="text-[15px] text-mute">{{ t('No special meals this week yet.') }}</p>
        @else
          <ul class="flex flex-col gap-2">
            @foreach ($specials as $s)
              @php $active = $e && $e['id'] === $s['id']; @endphp
              <li class="flex items-center justify-between gap-3 rounded-full py-2 pe-2 ps-5 transition duration-200 {{ $active ? 'bg-ink text-page' : 'bg-cream' }}">
                <div class="min-w-0">
                  <p class="truncate text-[15px] font-medium">{{ $s['count'] }} × {{ t($specialKinds[$s['kind']]) }}</p>
                  <p class="truncate text-[12px] {{ $active ? 'text-mute-dark' : 'text-mute' }}">{{ Days::fromKey($s['date'])->translatedFormat('D j M') }} · {{ $s['meal'] === 'lunch' ? t('Lunch') : t('Dinner') }}{{ $s['note'] !== '' ? ' · ' . $s['note'] : '' }}</p>
                </div>
                <div class="flex shrink-0 gap-1">
                  <x-admin.btn :href="$active ? $q() . '#special' : $q(['special' => $s['id']]) . '#special'" size="icon-sm" :variant="$active ? 'ghost-dark' : 'ghost'" aria-label="{{ t('Edit {0} {1} on {2}', [0 => $s['count'], 1 => t($specialKinds[$s['kind']]), 2 => Days::fromKey($s['date'])->translatedFormat('j M')]) }}"><x-lucide-pencil /></x-admin.btn>
                  <form method="POST" action="/meals/special/{{ $s['id'] }}/delete">
                    @csrf<input type="hidden" name="week" value="{{ $week }}">
                    <x-admin.btn type="submit" size="icon-sm" :variant="$active ? 'ghost-dark' : 'ghost'" aria-label="{{ t('Delete {0} {1} on {2}', [0 => $s['count'], 1 => t($specialKinds[$s['kind']]), 2 => Days::fromKey($s['date'])->translatedFormat('j M')]) }}"><x-lucide-x /></x-admin.btn>
                  </form>
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      </x-admin.card>
    </div>
  </x-admin.section>

  {{-- ------------------------------------------------------------ advanced --}}
  <x-admin.section tone="none" class="px-0 md:px-0" id="templates">
    <div class="px-2 md:px-6">
      <x-admin.pill>{{ t('Advanced') }}</x-admin.pill>
      <h2 class="mt-4 text-3xl font-medium tracking-[-0.02em] md:text-4xl">{{ t('Advanced meal planning') }}</h2>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-12">
      <x-admin.card tone="cream" class="lg:col-span-7">
        <div class="flex flex-col gap-6">
          <div>
            <h3 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-bookmark class="size-5" aria-hidden="true" />{{ t('Meal templates') }}</h3>
            <p class="mt-2 text-[15px] text-mute">{{ t('Pick a template, then click any slot in the grid to place it.') }}</p>
          </div>
          <ul class="flex flex-col gap-2">
            @foreach ($templates as $tpl)
              @php $active = $armed && $armed->id === $tpl->id; @endphp
              <li class="flex items-center justify-between gap-3 rounded-full py-2 pe-2 ps-5 transition duration-200 {{ $active ? 'bg-ink text-page' : 'bg-page' }}">
                <div class="min-w-0">
                  <p class="truncate text-[15px] font-medium" dir="auto">{{ $tpl->title }}</p>
                  <p class="truncate text-[12px] {{ $active ? 'text-mute-dark' : 'text-mute' }}">{{ $tpl->type }} · {{ $tpl->description !== '' ? $tpl->description : t('No description') }}</p>
                </div>
                <div class="flex shrink-0 gap-1">
                  <x-admin.btn :href="$active ? '/meals?week=' . $week : '/meals?week=' . $week . '&arm=' . $tpl->id" size="sm" :variant="$active ? 'primary' : 'dark'">{{ $active ? t('Placing…') : t('Use') }}</x-admin.btn>
                  <form method="POST" action="/meals/templates/{{ $tpl->id }}/delete">
                    @csrf<input type="hidden" name="week" value="{{ $week }}">
                    <x-admin.btn type="submit" size="icon-sm" :variant="$active ? 'ghost-dark' : 'ghost'" aria-label="{{ t('Delete template {0}', [0 => $tpl->title]) }}"><x-lucide-x /></x-admin.btn>
                  </form>
                </div>
              </li>
            @endforeach
          </ul>
          <form method="POST" action="/meals/templates" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @csrf<input type="hidden" name="week" value="{{ $week }}">
            <x-admin.field :label="t('Template name')" for="t-name"><x-admin.input tone="page" id="t-name" name="name" required placeholder="{{ t('e.g. Tuna niçoise') }}" /></x-admin.field>
            <x-admin.field :label="t('Meal type')" for="t-type">
              <x-admin.select tone="page" id="t-type" name="type"><option>Breakfast</option><option selected>Lunch/dinner</option></x-admin.select>
            </x-admin.field>
            <x-admin.field :label="t('Description')" for="t-desc" class="sm:col-span-2"><x-admin.input tone="page" id="t-desc" name="description" placeholder="{{ t('Short description') }}" /></x-admin.field>
            <x-admin.btn type="submit" variant="dark" class="w-fit"><x-lucide-plus />{{ t('Save template') }}</x-admin.btn>
          </form>
        </div>
      </x-admin.card>

      <x-admin.card class="flex flex-col gap-6 lg:col-span-5">
        <div>
          <h3 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-repeat class="size-5" aria-hidden="true" />{{ t('Repeat pattern') }}</h3>
          <p class="mt-2 text-[15px] leading-relaxed text-mute">{{ t('Apply the menu of') }} {{ $weekRange($week) }} {{ t('to the following weeks. Existing bookings are kept.') }}</p>
        </div>
        <form method="POST" action="/meals/copy" class="flex flex-col gap-6">
          @csrf<input type="hidden" name="week" value="{{ $week }}">
          <x-admin.segmented name="count" :label="t('Number of weeks')" value="4" :options="['2' => '2 weeks', '4' => '4 weeks', '6' => '6 weeks']" />
          <x-admin.btn type="submit" variant="dark" size="lg" class="w-fit">{{ t('Apply to the following weeks') }}</x-admin.btn>
        </form>
      </x-admin.card>
    </div>
  </x-admin.section>

  {{-- ------------------------------------------------------------- slot editor --}}
  @if ($dialog)
    @php
        $d = $dialog;
        $label = $rules->mealLabel($d['meal']);
        $name = old('name', $d['use']->title ?? $d['dish']['name'] ?? '');
        $desc = old('description', $d['use']->description ?? $d['dish']['description'] ?? '');
        $chips = $templates->filter(fn ($tpl) => $tpl->type === $kindOf($d['meal']));
    @endphp
    <x-admin.modal :title="$label . ' · ' . $long($d['date'])" :description="$d['dish'] ? t('Edit the meal for this slot.') : t('Add a meal to this slot.')" :close="$q()">
      <form method="POST" action="/meals/slot" class="flex flex-col gap-6">
        @csrf
        <input type="hidden" name="date" value="{{ $d['date'] }}"><input type="hidden" name="meal" value="{{ $d['meal'] }}">
        @if ($d['bookings'] > 0)
          <div class="flex items-start gap-3 rounded-card bg-ink p-5 text-page">
            <x-lucide-triangle-alert class="mt-0.5 size-5 shrink-0 text-lime" aria-hidden="true" />
            <p class="text-[15px] leading-relaxed"><span class="font-medium">{{ $d['bookings'] }} {{ t('people already booked this meal.') }}</span> <span class="text-mute-dark">{{ t('Any change will be visible to them.') }}</span></p>
          </div>
        @endif

        @if ($chips->isNotEmpty())
          <div>
            <p class="mb-3 px-1 text-[13px] font-medium">{{ t('Use a template') }}</p>
            <div class="flex flex-wrap gap-2">
              @foreach ($chips as $tpl)
                <a href="{{ $q(['slot' => $d['date'] . ':' . $d['meal'], 'use' => $tpl->id]) }}" class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-full px-4 text-[13px] font-medium transition duration-200 hover:scale-[1.02] {{ $tpl->title === $name ? 'bg-ink text-lime' : 'bg-cream text-ink hover:bg-cream-deep' }}"><x-lucide-bookmark class="size-3.5" aria-hidden="true" />{{ $tpl->title }}</a>
              @endforeach
            </div>
          </div>
        @endif

        <x-admin.field :label="t('Meal name')" for="m-name"><x-admin.input id="m-name" name="name" value="{{ $name }}" placeholder="{{ t('e.g. Chicken shawarma bowl') }}" required autofocus /></x-admin.field>
        <x-admin.field :label="t('Description')" for="m-desc"><x-admin.textarea id="m-desc" name="description" placeholder="{{ t('Ingredients, sides, allergens…') }}">{{ $desc }}</x-admin.textarea></x-admin.field>

        <label class="flex cursor-pointer items-center gap-3 px-1 text-[15px]">
          <input type="checkbox" name="template" value="1" class="size-5 cursor-pointer accent-ink">{{ t('Also save as a reusable template') }}
        </label>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
          @if ($d['dish'])
            <x-admin.btn type="submit" variant="ghost" formaction="/meals/slot/remove" formnovalidate>{{ t('Remove meal') }}</x-admin.btn>
          @else
            <span></span>
          @endif
          <div class="flex gap-3">
            <x-admin.btn :href="$q()" variant="soft">{{ t('Cancel') }}</x-admin.btn>
            <x-admin.btn type="submit" variant="dark">{{ t('Save') }}</x-admin.btn>
          </div>
        </div>
      </form>
    </x-admin.modal>
  @endif
</x-layouts.admin>
