@php
    $status = session('team_status');
    $groupText = fn ($g) => $g ? "{$g->name} · {$g->start}–{$g->end}" : t('Usual time');
@endphp
<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      <x-hero-slab>
        <x-top-bar />
        <div class="reveal relative z-10 flex flex-col items-start gap-6 pt-14 sm:pt-20">
          <x-back-link />
          <h1 class="text-[clamp(2.25rem,6vw,4.5rem)] font-medium leading-[0.95] tracking-[-0.03em]">{{ t('My team') }}</h1>
          <p class="max-w-[60ch] text-[15px] leading-relaxed text-muted-dark">
            {{ t('When someone works other hours tomorrow, switch their meal and group for that day only. Nothing is added or cancelled.') }}
          </p>
          @if ($isResponsible)
            <div class="flex flex-wrap gap-2">
              <x-pill-label dark>{{ \App\Support\Days::format($day, true) }}</x-pill-label>
              <x-pill-label dark>{{ t('{count} booked', ['count' => $booked]) }}</x-pill-label>
              <x-pill-label dark>{{ $open ? t('Open until 23:59 today') : t('Closed — switches close at 23:59 the day before') }}</x-pill-label>
            </div>
          @endif
        </div>
      </x-hero-slab>

      <x-cream-slab :label="t('My team')">
        @unless ($isResponsible)
          <p class="text-[15px] text-muted-light">{{ t('This page is for responsibles.') }}</p>
        @else
          <div class="flex flex-col gap-4">
            @if ($status && !$edit)
              <p role="status" class="rounded-card bg-cream-deep p-5 text-[15px] {{ $status['tone'] === 'error' ? 'text-danger' : 'text-booked-ink' }}">{{ t($status['text']) }}</p>
            @endif

            <form method="GET" action="/team" class="flex h-12 max-w-md items-center gap-2 rounded-full border border-ink/10 bg-page px-4">
              <x-lucide-search class="size-4 text-muted-light" aria-hidden="true" />
              <input name="q" value="{{ $q }}" class="h-full w-full bg-transparent text-[15px] text-ink outline-none" placeholder="{{ t('Search a name') }}">
            </form>

            <ul class="flex flex-col gap-2">
              @foreach ($people as $p)
                @php $b = $p['booking']; @endphp
                <li id="p-{{ $p['id'] }}" class="flex flex-col gap-3 rounded-card bg-cream-deep p-4 sm:p-5 {{ !$b ? 'opacity-60' : '' }}">
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p class="text-[16px] font-medium">{{ $p['name'] }}</p>
                      <p class="text-[13px] text-muted-light">{{ collect([$p['employeeId'] ? '#' . $p['employeeId'] : '', $p['position']])->filter()->implode(' · ') }}</p>
                    </div>
                    @unless ($b)<span class="text-[14px] text-muted-light">{{ t('No booking') }}</span>@endunless
                  </div>

                  @foreach ($b['meals'] ?? [] as $m)
                    @php
                        $meal = $m['meal'];
                        $editing = $edit === $b['orderId'] . ':' . $meal;
                    @endphp
                    <div class="flex flex-col gap-3">
                      <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                          <span class="rounded-full bg-ink px-3 py-1 text-[13px] font-medium text-page">{{ $rules->mealLabel($meal) }}</span>
                          <span class="text-[14px] text-muted-light">{{ $groupText($m['group']) }}</span>
                          @if ($b['switched'])
                            <span class="rounded-full bg-lime px-3 py-1 text-[12px] font-medium text-ink">{{ t('Switched by {name}', ['name' => $b['switchedBy'] ?: t('a responsible')]) }}</span>
                          @endif
                        </div>
                        @if ($open && !$editing)
                          <a href="/team?edit={{ $b['orderId'] }}:{{ $meal }}{{ $q !== '' ? '&q=' . urlencode($q) : '' }}#p-{{ $p['id'] }}"
                             class="flex h-10 items-center gap-2 rounded-full bg-ink px-4 text-[14px] font-medium text-page">
                            <x-lucide-arrow-right-left class="size-4" aria-hidden="true" />
                            {{ t('Switch') }}
                          </a>
                        @endif
                      </div>

                      @if ($editing)
                        @php $bookedMeals = array_column($b['meals'], 'meal'); @endphp
                        <form method="POST" action="/team/switch" data-switch-form class="flex flex-col gap-4 rounded-card bg-page p-4">
                          @csrf
                          <input type="hidden" name="orderId" value="{{ $b['orderId'] }}">
                          <input type="hidden" name="from" value="{{ $meal }}">
                          <input type="hidden" name="q" value="{{ $q }}">
                          <div class="flex flex-col gap-2">
                            <span class="text-[13px] text-muted-light">{{ t('New meal for that day') }}</span>
                            <div class="flex flex-wrap gap-2" role="radiogroup">
                              @foreach (['breakfast', 'lunch', 'dinner'] as $to)
                                @php $taken = $to !== $meal && in_array($to, $bookedMeals, true); @endphp
                                <label class="{{ $taken ? 'cursor-not-allowed opacity-40' : 'cursor-pointer' }}">
                                  <input type="radio" name="to" value="{{ $to }}" class="peer sr-only" @checked($to === $meal) @disabled($taken)>
                                  <span class="flex h-10 items-center rounded-full bg-cream-deep px-4 text-[14px] font-medium text-ink transition-colors peer-checked:bg-ink peer-checked:text-page peer-focus-visible:ring-2 peer-focus-visible:ring-ink">{{ $rules->mealLabel($to) }}</span>
                                </label>
                              @endforeach
                            </div>
                          </div>
                          <label class="flex flex-col gap-2 text-[13px] text-muted-light">
                            {{ t('Group for that day') }}
                            <select name="groupId" data-group-select class="h-12 max-w-sm rounded-full border border-ink/10 bg-page px-4 text-[15px] text-ink">
                              <option value="">{{ t('Their usual group') }}</option>
                              @foreach (['breakfast', 'lunch', 'dinner'] as $to)
                                <optgroup label="{{ $rules->mealLabel($to) }}">
                                  @foreach ($groups->filter(fn ($g) => \App\Services\MealTimes::mealOf($g->meal) === $to) as $g)
                                    <option value="{{ $g->id }}" data-meal="{{ $to }}" @selected($m['group'] && $m['group']->id === $g->id && $to === $meal)>{{ $g->name }} · {{ $g->start }}–{{ $g->end }}</option>
                                  @endforeach
                                </optgroup>
                              @endforeach
                            </select>
                          </label>
                          @if ($status && $status['tone'] === 'error')
                            <p class="text-[14px] text-danger">{{ t($status['text']) }}</p>
                          @endif
                          <div class="flex flex-wrap gap-2">
                            <button type="submit" class="h-11 rounded-full bg-ink px-6 text-[15px] font-medium text-page">{{ t('Save switch') }}</button>
                            <a href="/team{{ $q !== '' ? '?q=' . urlencode($q) : '' }}" class="flex h-11 items-center rounded-full bg-cream-deep px-6 text-[15px] font-medium text-ink">{{ t('Cancel') }}</a>
                          </div>
                        </form>
                      @endif
                    </div>
                  @endforeach
                </li>
              @endforeach
              @if (!count($people))
                <li class="p-6 text-[15px] text-muted-light">{{ t('Nobody found.') }}</li>
              @endif
            </ul>
          </div>
        @endunless
      </x-cream-slab>
    </main>
  </x-page-frame>
</x-layouts.app>
