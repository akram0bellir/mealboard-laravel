@php use App\Support\Days; $meals = ['breakfast', 'lunch', 'dinner']; @endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Book people')" :description="t('Put anyone on any day\'s meals, including people who never booked — past or locked days, paused people, meals outside their plan, Ramadan, even switched-off accounts. One day or a range, chosen people or everyone in the list. No rule stops you and no strike is added.')">
      <x-slot:title>{{ t('Add people to') }} <span class="text-lime">{{ t('meals') }}</span></x-slot:title>
    </x-admin.heading>
    <form method="GET" action="/diners" data-autosubmit class="mt-10 flex flex-col gap-4 md:flex-row md:flex-wrap md:items-center">
      <label for="d-date" class="sr-only">{{ t('Day') }}</label>
      <x-admin.input id="d-date" type="date" name="date" value="{{ $date }}" class="!h-11 !w-auto border-ink-3 !bg-ink-2 !text-page [color-scheme:dark]" />
      <label for="d-q" class="sr-only">{{ t('Search') }}</label>
      <x-admin.input id="d-q" type="search" name="q" value="{{ $q }}" placeholder="{{ t('Name, e-mail, card number…') }}" class="!h-11 !w-64 border-ink-3 !bg-ink-2 !text-page" />
      <label for="d-dept" class="sr-only">{{ t('Department') }}</label>
      <select id="d-dept" name="dept" class="h-11 cursor-pointer rounded-full border border-ink-3 bg-ink-2 px-4 text-[14px] text-page [color-scheme:dark]">
        <option value="">{{ t('All departments') }}</option>
        @foreach ($departments as $d)<option value="{{ $d }}" @selected($dept === (string) $d)>{{ t('Department') }} {{ $d }}</option>@endforeach
      </select>
      <x-admin.btn type="submit" variant="soft-dark">{{ t('Show') }}</x-admin.btn>
    </form>
  </x-admin.section>

  <x-admin.section tone="cream">
    <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ Days::fromKey($date)->translatedFormat('l j F Y') }}</h2>
    <p role="status" aria-live="polite" class="mt-2 min-h-5 text-[14px] font-medium {{ $status && !$status[0] ? 'text-danger' : 'text-ink' }}" data-status>{{ $status[1] ?? '' }}</p>

    <div class="mt-4 grid grid-cols-1 gap-6 xl:grid-cols-2">
      {{-- ---------------------------------------------------------------- Add --}}
      <form method="POST" action="/diners" class="flex flex-col gap-5 rounded-card bg-page p-6" data-add-form>
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="q" value="{{ $q }}"><input type="hidden" name="dept" value="{{ $dept }}">
        <div>
          <h3 class="text-xl font-medium">{{ t('Who eats?') }}</h3>
          <p class="mt-1 text-[13px] text-mute">{{ $matches === 1 ? t('1 person found') : t('{0} people found', [0 => $matches]) }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
          <x-admin.field :label="t('From')" for="d-from"><x-admin.input id="d-from" type="date" value="{{ $date }}" disabled class="!h-10 !w-auto" /></x-admin.field>
          <x-admin.field :label="t('Until (optional)')" for="d-until" :hint="t('Every day up to this one, at most {0} days.', [0 => $maxDays])"><x-admin.input id="d-until" type="date" name="until" min="{{ $date }}" class="!h-10 !w-auto" /></x-admin.field>
          <label class="flex cursor-pointer items-center gap-2 pb-8 text-[14px]"><input type="checkbox" name="menu_only" value="1" class="size-5 cursor-pointer accent-ink"> {{ t('Only days with that meal on the menu') }}</label>
        </div>
        <fieldset class="flex flex-wrap gap-3">
          <legend class="sr-only">{{ t('Meals') }}</legend>
          @foreach ($meals as $m)
            <label class="cursor-pointer">
              <input type="checkbox" name="meals[]" value="{{ $m }}" class="peer sr-only" @checked($m === 'lunch')>
              <span class="flex h-10 items-center gap-2 rounded-full bg-cream px-5 text-[14px] font-medium transition peer-checked:bg-ink peer-checked:text-page peer-focus-visible:ring-2 peer-focus-visible:ring-ink">{{ t(ucfirst($m)) }}</span>
            </label>
          @endforeach
        </fieldset>
        <ul class="flex max-h-96 flex-col gap-1 overflow-y-auto rounded-[20px] bg-cream p-2" data-people>
          @forelse ($people as $p)
            @php $full = trim($p->firstname . ' ' . $p->lastname) ?: (string) $p->email; @endphp
            <li>
              <label class="flex cursor-pointer items-center gap-3 rounded-full px-2 py-1.5 hover:bg-page">
                <input type="checkbox" name="users[]" value="{{ $p->id }}" class="peer sr-only">
                <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-cream-deep text-transparent transition peer-checked:bg-ink peer-checked:text-lime peer-focus-visible:ring-2 peer-focus-visible:ring-ink"><x-lucide-check class="size-3.5" aria-hidden="true" /></span>
                <x-admin.avatar :name="$full" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-[15px] font-medium">{{ $full }}</span>
                  <span class="block truncate text-[12px] text-mute">{{ t('Dept {0}', [0 => $p->Department]) }}{{ $p->Position ? ' · ' . $p->Position : '' }}{{ (int) $p->Status !== 1 ? ' · ' . t('account off') : '' }}</span>
                </span>
              </label>
            </li>
          @empty
            <li class="px-3 py-2 text-[14px] text-mute">{{ t('Nobody found.') }}</li>
          @endforelse
        </ul>
        <label class="flex cursor-pointer items-center gap-3 text-[14px]" data-everyone>
          <input type="checkbox" name="everyone" value="1" class="size-5 cursor-pointer accent-ink"> {{ t('Everyone in this list ({0} people), not only the ticked ones', [0 => $matches]) }}
        </label>
        <label class="flex cursor-pointer items-center gap-3 text-[14px]">
          <input type="checkbox" name="notify" value="1" checked class="size-5 cursor-pointer accent-ink"> {{ t('Tell them in the app (and by e-mail)') }}
        </label>
        <div><x-admin.btn type="submit" variant="dark" size="lg"><x-lucide-user-plus />{{ t('Add to the meals') }}</x-admin.btn></div>
      </form>

      {{-- ---------------------------------------------------------------- Booked that day --}}
      <div class="flex flex-col gap-4 rounded-card bg-page p-6">
        <div>
          <h3 class="text-xl font-medium">{{ t('Booked this day') }}</h3>
          <p class="mt-1 text-[13px] text-mute">{{ collect($meals)->map(fn ($m) => t(ucfirst($m)) . ' ' . $totals[$m])->implode(' · ') }}</p>
        </div>
        @if (!$booked)
          <p class="text-[14px] text-mute">{{ t('Nobody is booked on this day.') }}</p>
        @else
          <ul class="flex max-h-[34rem] flex-col gap-2 overflow-y-auto" data-booked>
            @foreach ($booked as $b)
              <li class="flex flex-col gap-2 rounded-[20px] bg-cream px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                  <p class="truncate font-medium">{{ $b['name'] }} <span class="text-[12px] font-normal text-mute">{{ t('Dept {0}', [0 => $b['dept']]) }}</span></p>
                  <p class="text-[13px] text-mute">
                    {{ collect($b['meals'])->map(fn ($m) => t(ucfirst($m)) . (in_array($m, $b['missed'], true) ? ' (' . t('missed') . ')' : (in_array($m, $b['served'], true) ? ' (' . t('served') . ')' : '')))->implode(' + ') }}
                  </p>
                </div>
                <div class="flex flex-wrap gap-2">
                  @foreach ($b['meals'] as $m)
                    @php $isServed = in_array($m, $b['served'], true); @endphp
                    <form method="POST" action="/diners/{{ $b['id'] }}/served" data-served-form>
                      @csrf
                      <input type="hidden" name="meal" value="{{ $m }}"><input type="hidden" name="served" value="{{ $isServed ? 0 : 1 }}">
                      <x-admin.btn type="submit" size="sm" :variant="$isServed ? 'dark' : 'ghost'" aria-label="{{ $isServed ? t('{0}: undo served', [0 => $b['name'] . ' · ' . t(ucfirst($m))]) : t('{0}: mark served', [0 => $b['name'] . ' · ' . t(ucfirst($m))]) }}"><x-lucide-check />{{ $isServed ? t('Served') : t('Serve') }}</x-admin.btn>
                    </form>
                    <form method="POST" action="/diners/{{ $b['id'] }}/remove">
                      @csrf
                      <input type="hidden" name="meals" value="{{ $m }}">
                      <x-admin.btn type="submit" size="sm" variant="soft" aria-label="{{ t('Remove {0}', [0 => $b['name'] . ' · ' . t(ucfirst($m))]) }}"><x-lucide-x />{{ t(ucfirst($m)) }}</x-admin.btn>
                    </form>
                  @endforeach
                </div>
              </li>
            @endforeach
          </ul>
        @endif
        <p class="flex items-start gap-2 text-[12px] text-mute"><x-lucide-info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ t('Removing a meal that was marked missed gives the strike back. Meals added on a past day that nobody served can still be marked missed by the automatic no-shows if that meal has not been processed yet.') }}</p>
      </div>
    </div>
  </x-admin.section>
</x-layouts.admin>
