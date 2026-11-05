@php
    use App\Support\Days;
    use App\Support\PenaltyRules as P;
    $money = fn (float $n) => number_format($n, 2, '.', ',') . ' ' . $currency;
    $monthName = fn (string $m) => Days::fromKey($m . '-01')->translatedFormat('F Y');
    $box = 'group rounded-card p-4 ring-1 ring-ink/10 transition duration-200 has-[[data-section]:checked]:bg-cream has-[[data-section]:checked]:ring-0';
    $reveal = 'mt-4 hidden group-has-[[data-section]:checked]:block';
    $oldKpis = (array) old('kpis', []);
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Penalties')" :description="t('Record penalties against the catering contractor. Each one is worked out from the contract section that applies and the amount of the meal service concerned.')">
      <x-slot:title>{{ t('Hold the kitchen to the') }} <span class="text-lime">{{ t('contract') }}</span></x-slot:title>
    </x-admin.heading>

    <form method="GET" action="/penalties" data-autosubmit class="mt-10 flex items-center gap-3">
      <div class="w-60">
        <label for="penalty-month" class="sr-only">{{ t('Month') }}</label>
        <x-admin.select id="penalty-month" name="month" class="!h-11 !bg-ink-2 !text-page [color-scheme:dark]">
          @foreach ($months as $m)<option value="{{ $m }}" @selected($m === $month)>{{ $monthName($m) }}</option>@endforeach
        </x-admin.select>
      </div>
      <x-admin.btn type="submit" variant="soft-dark">{{ t('Show') }}</x-admin.btn>
    </form>

    <div class="mt-8 grid grid-cols-2 gap-6 lg:grid-cols-5">
      <x-admin.card tone="ink-2" :delay="1" class="col-span-2 lg:col-span-1">
        <p class="text-[13px] text-mute-dark">{{ t('Total deducted') }}</p>
        <p class="mt-2 text-3xl font-medium tracking-[-0.03em] text-lime">{{ number_format($monthTotal, 2, '.', ',') }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ $currency }} · {{ $inMonth->count() === 1 ? t('1 penalty') : t('{0} penalties', [0 => $inMonth->count()]) }}</p>
      </x-admin.card>
      @foreach ($bySection as $s => $info)
        <x-admin.card :delay="$s + 1" class="ring-1 ring-ink-3">
          <p class="text-[13px] text-mute-dark">{{ t('Section') }} {{ $s }} · {{ t(P::SECTION_TITLE[$s]) }}</p>
          <p class="mt-2 text-3xl font-medium tracking-[-0.03em]">{{ $info['count'] }}</p>
          <p class="mt-1 text-[12px] text-mute-dark">{{ $money($info['amount']) }}</p>
        </x-admin.card>
      @endforeach
    </div>
  </x-admin.section>

  <x-admin.section tone="cream">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
      {{-- ---------------- Record penalties ---------------- --}}
      <x-admin.card tone="page" class="lg:col-span-6">
        <form method="POST" action="/penalties" data-penalty-form class="flex flex-col gap-5"
              data-prices='@json($prices)' data-currency="{{ $currency }}" data-booked-label="{{ t('{0} booked for this {1}') }}"
              data-empty="{{ t('Tick a section above to add a penalty.') }}" data-total-label="{{ t('Total deducted') }}">
          @csrf
          <div>
            <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-gavel class="size-5" aria-hidden="true" />{{ t('Record penalties') }}</h2>
            <p class="mt-1 text-[14px] text-mute">{{ t("Tick every section that applies to this service — they're recorded together.") }}</p>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-admin.field :label="t('Day of the service')" for="p-date"><x-admin.input id="p-date" type="date" name="date" value="{{ $date }}" required data-penalty-date /></x-admin.field>
            <x-admin.field :label="t('Meal service')" for="p-meal">
              <x-admin.select id="p-meal" name="meal" data-penalty-meal>
                @foreach (['breakfast', 'lunch', 'dinner'] as $m)<option value="{{ $m }}" @selected($meal === $m)>{{ t(ucfirst($m)) }}</option>@endforeach
              </x-admin.select>
            </x-admin.field>
          </div>

          {{-- Section 1 --}}
          <fieldset class="{{ $box }}">
            <legend class="sr-only">{{ t('Section') }} 1 · {{ t(P::SECTION_TITLE[1]) }}</legend>
            <label class="flex cursor-pointer items-center gap-3 text-[15px] font-medium"><input type="checkbox" name="s1" value="1" data-section class="size-5 cursor-pointer accent-ink" @checked(old('s1'))>{{ t('Section') }} 1 · {{ t(P::SECTION_TITLE[1]) }}</label>
            <ul class="{{ $reveal }} flex-col gap-2">
              @foreach (P::KPIS as $id => $k)
                <li class="mb-2">
                  <label class="flex cursor-pointer items-start gap-3 text-[14px]">
                    <input type="checkbox" name="kpis[]" value="{{ $id }}" data-percent="{{ $k['percent'] }}" data-reason="{{ t($k['indicator']) }}" class="mt-0.5 size-4 shrink-0 cursor-pointer accent-ink" @checked(in_array($id, $oldKpis, true))>
                    <span class="flex-1">{{ t($k['indicator']) }}<span class="block text-[12px] text-mute">{{ t($k['impact']) }}</span></span>
                    <span class="font-medium tabular-nums">{{ $k['percent'] }}%</span>
                  </label>
                </li>
              @endforeach
            </ul>
          </fieldset>

          {{-- Section 2 --}}
          <fieldset class="{{ $box }}">
            <legend class="sr-only">{{ t('Section') }} 2 · {{ t(P::SECTION_TITLE[2]) }}</legend>
            <label class="flex cursor-pointer items-center gap-3 text-[15px] font-medium"><input type="checkbox" name="s2" value="1" data-section class="size-5 cursor-pointer accent-ink" @checked(old('s2'))>{{ t('Section') }} 2 · {{ t(P::SECTION_TITLE[2]) }}</label>
            <div class="{{ $reveal }}">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-admin.field :label="t('Delay (minutes)')" for="p-delay"><x-admin.input id="p-delay" type="number" name="delay" min="0" step="1" value="{{ old('delay') }}" placeholder="e.g. 12" /></x-admin.field>
                <label class="flex cursor-pointer items-center gap-3 self-end px-1 pb-3 text-[14px]"><input type="checkbox" name="significant" value="1" class="peer size-4 cursor-pointer accent-ink" @checked(old('significant'))>{{ t('Significant impact (service refused)') }}</label>
                <div class="hidden sm:col-span-2 group-has-[[name=significant]:checked]:block">
                  <x-admin.field :label="t('Penalty % (up to 100%)')" for="p-sig">
                    <x-admin.input id="p-sig" type="number" name="significant_percent" min="0" max="100" value="{{ old('significant_percent', 100) }}" />
                  </x-admin.field>
                </div>
              </div>
            </div>
          </fieldset>

          {{-- Section 3 --}}
          <fieldset class="{{ $box }}">
            <legend class="sr-only">{{ t('Section') }} 3 · {{ t(P::SECTION_TITLE[3]) }}</legend>
            <label class="flex cursor-pointer items-center gap-3 text-[15px] font-medium"><input type="checkbox" name="s3" value="1" data-section class="size-5 cursor-pointer accent-ink" @checked(old('s3'))>{{ t('Section') }} 3 · {{ t(P::SECTION_TITLE[3]) }}</label>
            <div class="{{ $reveal }}">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-admin.field :label="t('Shortfall')" for="p-short">
                  <x-admin.select id="p-short" name="shortfall">
                    @foreach (P::SHORTFALL_TIERS as $id => [$label, $pct])<option value="{{ $id }}" data-percent="{{ $pct }}" data-reason="{{ $label }}" @selected(old('shortfall') === $id)>{{ t($label) }} · {{ $pct }}%</option>@endforeach
                  </x-admin.select>
                </x-admin.field>
                <x-admin.field :label="t('Meals undelivered (refund)')" for="p-undel" :hint="t('Optional')"><x-admin.input id="p-undel" type="number" name="undelivered" min="0" step="1" value="{{ old('undelivered') }}" placeholder="e.g. 4" /></x-admin.field>
              </div>
            </div>
          </fieldset>

          {{-- Section 4 --}}
          <fieldset class="{{ $box }}">
            <legend class="sr-only">{{ t('Section') }} 4 · {{ t(P::SECTION_TITLE[4]) }}</legend>
            <label class="flex cursor-pointer items-center gap-3 text-[15px] font-medium"><input type="checkbox" name="s4" value="1" data-section class="size-5 cursor-pointer accent-ink" @checked(old('s4'))>{{ t('Section') }} 4 · {{ t(P::SECTION_TITLE[4]) }}</label>
            <div class="{{ $reveal }}">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-admin.field :label="t('Quality failure')" for="p-quality">
                  <x-admin.select id="p-quality" name="quality">
                    @foreach (P::QUALITY_FAILURES as $q)<option value="{{ $q }}" @selected(old('quality') === $q)>{{ t($q) }}</option>@endforeach
                  </x-admin.select>
                </x-admin.field>
                <x-admin.field :label="t('Penalty %')" for="p-qpct"><x-admin.input id="p-qpct" type="number" name="quality_percent" min="0" max="100" value="{{ old('quality_percent') }}" placeholder="e.g. 10" /></x-admin.field>
              </div>
            </div>
          </fieldset>

          <div class="grid grid-cols-1 gap-3">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <x-admin.field :label="t('Number of meals')" for="p-meals" :hint="t('{0} booked for this {1}', [0 => $booked, 1 => mb_strtolower(t(ucfirst($meal)))])">
                <x-admin.input id="p-meals" type="number" name="meals" min="0" step="1" value="{{ old('meals', $booked) }}" data-penalty-meals />
              </x-admin.field>
              <div class="flex flex-col gap-2">
                <p class="px-1 text-[13px] font-medium">{{ t('Service amount (') }}{{ $currency }})</p>
                <p class="flex h-12 items-center rounded-full bg-cream px-5 text-[15px] font-medium tabular-nums" aria-live="polite" data-penalty-service>{{ number_format($booked * $prices[$meal], 2, '.', ',') }}</p>
                <p class="px-1 text-[13px] text-mute" data-penalty-unit>{{ $booked }} × {{ $money($prices[$meal]) }}</p>
              </div>
            </div>
            <x-admin.field :label="t('Note')" for="p-note"><x-admin.textarea id="p-note" name="note" class="!min-h-20" placeholder="{{ t('What happened, inspection reference…') }}">{{ old('note') }}</x-admin.textarea></x-admin.field>
          </div>

          <dl class="flex flex-col gap-1.5 rounded-card bg-cream p-5 text-[14px]" data-penalty-preview>
            <p class="text-mute">{{ t('Tick a section above to add a penalty.') }}</p>
          </dl>

          <div class="flex flex-wrap items-center gap-4">
            <x-admin.btn type="submit" variant="dark"><x-lucide-plus />{{ t('Record penalties') }}</x-admin.btn>
            <p role="status" aria-live="polite" class="min-h-5 text-[13px] {{ $problems ? 'font-medium text-danger' : 'text-ink' }}">{{ $problems ? t($problems[0]) : ($status ? t($status) : '') }}</p>
          </div>
        </form>
      </x-admin.card>

      {{-- ---------------- Contract reference ---------------- --}}
      <x-admin.card class="flex flex-col gap-6 lg:col-span-6">
        <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ t('Contract rules') }}</h2>
        @php
            $blocks = [
                [1, collect(P::KPIS)->map(fn ($k) => [t($k['indicator']), t($k['impact']), $k['percent'] . '%'])->values(), null],
                [2, collect(P::DELAY_TIERS)->map(fn ($d) => [t($d[0]), null, ($d[1] === 100 ? t('up to') . ' ' : '') . $d[1] . '%']), null],
                [3, collect(P::SHORTFALL_TIERS)->map(fn ($s) => [t($s[0]), null, $s[1] . '%'])->values(), t('Undelivered quantities are also refunded in full.')],
                [4, collect(P::QUALITY_FAILURES)->map(fn ($q) => [t($q), null, null]), t('Penalty % is set for each case.')],
            ];
        @endphp
        @foreach ($blocks as [$s, $rows, $note])
          <div>
            <h3 class="text-[15px] font-medium">{{ t('Section {0} · {1}', [0 => $s, 1 => t(P::SECTION_TITLE[$s])]) }}</h3>
            <ul class="mt-2 flex flex-col">
              @foreach ($rows as [$label, $detail, $pct])
                <li class="flex items-start justify-between gap-4 border-b border-ink/10 py-2 text-[14px]">
                  <span>{{ $label }}@if ($detail)<span class="block text-[12px] text-mute">{{ $detail }}</span>@endif</span>
                  <span class="shrink-0 font-medium tabular-nums {{ $pct === null ? 'text-mute' : '' }}">{{ $pct ?? t('Set per case') }}</span>
                </li>
              @endforeach
            </ul>
            @if ($note)<p class="mt-2 text-[12px] text-mute">{{ $note }}</p>@endif
          </div>
        @endforeach
      </x-admin.card>
    </div>
  </x-admin.section>

  {{-- ---------------- Penalties this month ---------------- --}}
  <x-admin.section tone="cream" id="list">
    <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('Penalties ·') }} {{ $monthName($month) }}</h2>
    @if ($inMonth->isEmpty())
      <p class="mt-6 text-[15px] text-mute">{{ t('No penalties recorded for this month.') }}</p>
    @else
      <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[820px] border-separate border-spacing-y-1 text-start text-[14px]">
          <thead class="text-[12px] text-mute">
            <tr>
              <th class="px-4 py-2 text-start font-medium">{{ t('Service') }}</th>
              <th class="px-4 py-2 text-start font-medium">{{ t('Section') }}</th>
              <th class="px-4 py-2 text-start font-medium">{{ t('Reason') }}</th>
              <th class="px-4 py-2 text-end font-medium">{{ t('Rate') }}</th>
              <th class="px-4 py-2 text-end font-medium">{{ t('Deducted') }}</th>
              <th class="px-4 py-2"><span class="sr-only">{{ t('Actions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($inMonth as $p)
              <tr class="bg-page">
                <td class="rounded-s-full py-3 pe-4 ps-5 whitespace-nowrap">{{ Days::fromKey($p['date'])->translatedFormat('D j M') }} · {{ t(ucfirst($p['meal'])) }}</td>
                <td class="px-4 py-3">{{ $p['section'] }}</td>
                <td class="px-4 py-3"><p>{{ $p['reason'] }}</p>@if ($p['note'] !== '')<p class="text-[12px] text-mute">{{ $p['note'] }}</p>@endif</td>
                <td class="px-4 py-3 text-end tabular-nums">{{ rtrim(rtrim(number_format($p['percent'], 2, '.', ''), '0'), '.') }}%{{ $p['refund'] ? t(' + refund') : '' }}</td>
                <td class="px-4 py-3 text-end font-medium tabular-nums">{{ $money($p['amount']) }}</td>
                <td class="rounded-e-full py-3 pe-2 ps-4 text-end">
                  <form method="POST" action="/penalties/{{ $p['id'] }}/delete">
                    @csrf<input type="hidden" name="month" value="{{ $month }}">
                    <x-admin.btn type="submit" size="icon-sm" variant="ghost" aria-label="{{ t('Delete penalty on {0}', [0 => Days::fromKey($p['date'])->translatedFormat('j M')]) }}"><x-lucide-x /></x-admin.btn>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </x-admin.section>
</x-layouts.admin>
