@php
    use App\Support\Days;
    use App\Support\PenaltyRules as P;
    $nf = fn (float $n) => number_format($n, 2, '.', ',');
    $money = fn (float $n) => $nf($n) . ' ' . $currency;
    $monthName = fn (string $m) => Days::fromKey("{$m}-01")->translatedFormat('F Y');
    $dm = fn (string $k) => Days::fromKey($k)->translatedFormat('j M');
    $basisLabel = $basis === 'attended' ? t('Served') : t('Ordered');
    $icons = ['breakfast' => 'coffee', 'lunch' => 'soup', 'dinner' => 'moon'];
    $mealLabel = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
    $q = fn (array $x = []) => '/invoices?' . http_build_query(array_merge(['month' => $month, 'basis' => $basis], $x));

    // Month at a glance
    $totals = array_column($days, 'total');
    $max = max(1, ...$totals);
    $busiest = array_search(max($totals), $totals, true);
    $activeDays = count(array_filter($totals));
    $average = $activeDays ? array_sum($totals) / $activeDays : 0;
    $shade = ['bg-ink/30', 'bg-ink', 'bg-ink/60'];

    // E-mail to the contractor (opens the mail app, like the React page)
    $body = collect([
        'Hello ' . $invoice['contractorName'] . ',', '',
        'Please find the meal invoice for ' . Days::fromKey("{$month}-01")->locale('en')->translatedFormat('F Y') . '.', '',
    ])->merge(collect($groups)->flatMap(fn ($g) => array_merge(
        ["{$g['label']}:"],
        array_map(fn ($l) => '  ' . $mealLabel[$l['type']] . ": {$l['quantity']} × " . $money($l['price']) . ' = ' . $money($l['amount']), $g['lines']),
        ["  Subtotal: {$g['quantity']} meals = " . $money($g['amount']), '']
    )))->merge([
        "Sandwiches: {$sandwiches}",
        "Special meals: {$specialQuantity} = " . $money($specialAmount), '',
        'Meals and special meals: ' . $money($grossSubtotal),
    ])->merge($penaltyTotal ? ['Penalties: −' . $money($penaltyTotal)] : [])->merge([
        'Subtotal: ' . $money($subtotal), "VAT {$vat}%: " . $money($vatAmount), 'Total: ' . $money($total),
        'Due: ' . Days::fromKey($dueDate)->locale('en')->translatedFormat('j M Y'), '', 'Regards,', $invoice['companyName'],
    ])->implode("\n");
    $mailto = 'mailto:' . $invoice['contractorEmail'] . '?subject=' . rawurlencode("{$number} · Meals for " . Days::fromKey("{$month}-01")->locale('en')->translatedFormat('F Y')) . '&body=' . rawurlencode($body);
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink" class="print:hidden">
    <x-admin.heading :label="t('Invoices')" :description="t('A monthly invoice for the catering contractor, built from the meals ordered each day.')">
      <x-slot:title>{{ t('Bill the') }} <span class="text-lime">{{ t('kitchen') }}</span></x-slot:title>
      <x-admin.btn size="lg" onclick="window.print()"><x-lucide-printer />{{ t('Print / PDF') }}</x-admin.btn>
      <x-admin.btn :href="'/invoices/csv?month=' . $month . '&basis=' . $basis" size="lg" variant="soft-dark"><x-lucide-download />{{ t('Export CSV') }}</x-admin.btn>
      <x-admin.btn :href="$mailto" size="lg" variant="soft-dark"><x-lucide-mail />{{ t('Email contractor') }}</x-admin.btn>
    </x-admin.heading>

    <div class="mt-10 flex flex-col gap-4 md:flex-row md:flex-wrap md:items-center">
      <form method="GET" action="/invoices" data-autosubmit class="flex flex-wrap items-center gap-4">
        <div class="w-60">
          <label for="invoice-month" class="sr-only">{{ t('Invoice month') }}</label>
          <x-admin.select id="invoice-month" name="month" class="!h-11 !bg-ink-2 !text-page [color-scheme:dark]">
            @foreach ($months as $m)
              <option value="{{ $m }}" @selected($m === $month)>{{ $monthName($m) }}{{ $m === $currentMonth ? t(' (in progress)') : '' }}{{ isset($sent[$m]) ? t(' · sent') : '' }}</option>
            @endforeach
          </x-admin.select>
        </div>
        <x-admin.segmented name="basis" :label="t('Bill on')" dark :value="$basis" :options="['booked' => 'Meals ordered', 'attended' => 'Meals served']" />
        <x-admin.btn type="submit" variant="soft-dark">{{ t('Show') }}</x-admin.btn>
      </form>
      <form method="POST" action="/invoices/sent" class="md:ms-auto">
        @csrf<input type="hidden" name="month" value="{{ $month }}"><input type="hidden" name="basis" value="{{ $basis }}">
        <x-admin.btn type="submit" :variant="$sentAt ? 'primary' : 'soft-dark'">
          @if ($sentAt)<x-lucide-check-circle-2 />{{ t('Sent {0} · undo', [0 => \Carbon\CarbonImmutable::parse($sentAt)->setTimezone(config('app.timezone'))->translatedFormat('j M, H:i')]) }}
          @else<x-lucide-mail />{{ t('Mark as sent') }}@endif
        </x-admin.btn>
      </form>
    </div>

    <div class="mt-10 grid grid-cols-2 gap-6 lg:grid-cols-4">
      <x-admin.card tone="ink-2" :delay="1">
        <p class="text-[13px] text-mute-dark">{{ t('Meals') }} {{ mb_strtolower($basisLabel) }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em]">{{ $totalMeals }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ t('over') }} {{ count($days) }} {{ t('days') }}</p>
      </x-admin.card>
      <x-admin.card :delay="2" class="ring-1 ring-ink-3">
        <p class="text-[13px] text-mute-dark">{{ t('Not eaten (no-shows)') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em]">{{ $missedMeals }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ $money($missedAmount) }} {{ t('paid for nothing') }}</p>
      </x-admin.card>
      <x-admin.card :delay="3" class="ring-1 ring-ink-3">
        <p class="text-[13px] text-mute-dark">{{ t('Penalties deducted') }}</p>
        <p class="mt-2 text-3xl font-medium tracking-[-0.03em]">{{ $penaltyTotal ? '−' . $nf($penaltyTotal) : '0.00' }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ $penaltyLines ? t('{0} · {1} recorded · {2}', [0 => $currency, 1 => array_sum(array_column($penaltyLines, 'count')), 2 => collect($penaltyLines)->map(fn ($l) => mb_strtolower(t(P::SECTION_TITLE[$l['section']])))->implode(', ')]) : t('{0} · none recorded this month', [0 => $currency]) }}</p>
      </x-admin.card>
      <x-admin.card :delay="4" class="ring-1 ring-ink-3">
        <p class="text-[13px] text-mute-dark">{{ t('Total due') }}</p>
        <p class="mt-2 text-3xl font-medium tracking-[-0.03em] text-lime" data-invoice-total>{{ $nf($total) }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ $currency }} {{ t('· due') }} {{ $dm($dueDate) }} {{ t('· subtotal') }} {{ $nf($subtotal) }} {{ t('+ VAT') }}</p>
      </x-admin.card>
    </div>
  </x-admin.section>

  {{-- ---------------------------------------------------------------- Month at a glance --}}
  @if ($activeDays)
    <x-admin.section tone="cream" class="print:hidden">
      <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('Month at a glance') }}</h2>
          <p class="mt-2 text-[14px] text-mute">{{ $activeDays }} {{ t('days with meals ·') }} {{ number_format($average, 1) }} {{ t('meals on an average day · busiest') }} <span class="font-medium text-ink">{{ $dm($days[$busiest]['key']) }}</span> ({{ $totals[$busiest] }} {{ t('meals)') }}</p>
        </div>
        <ul class="flex flex-wrap gap-4 text-[12px] text-mute">
          @foreach (['breakfast', 'lunch', 'dinner'] as $i => $m)<li class="flex items-center gap-1.5"><span class="size-3 rounded-sm {{ $shade[$i] }}" aria-hidden="true"></span>{{ t($mealLabel[$m]) }}</li>@endforeach
          <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm border border-ink/40 bg-transparent" aria-hidden="true"></span>{{ t('Special meals') }}</li>
          <li class="flex items-center gap-1.5"><span class="h-1 w-3 rounded-full bg-lime" aria-hidden="true"></span>{{ t('Penalty that day') }}</li>
        </ul>
      </div>
      <div class="mt-4 overflow-x-auto pt-7">
        <div class="flex h-48 min-w-[640px] items-end gap-1" role="img" aria-label="{{ t('Meals per day this month') }}">
          @foreach ($days as $i => $d)
            <div class="relative flex h-full flex-1 flex-col justify-end" title="{{ t('{0}: {1} meals · {2}', [0 => $dm($d['key']), 1 => $d['total'], 2 => $money($d['amount'])]) }}">
              @if ($i === $busiest && $d['total'])<span class="absolute -top-6 left-1/2 -translate-x-1/2 rounded-full bg-lime px-1.5 text-[11px] font-medium text-ink">{{ $d['total'] }}</span>@endif
              <div class="flex flex-col-reverse overflow-hidden rounded-t-[4px]" style="height: {{ ($d['total'] / $max) * 100 }}%">
                @foreach (['breakfast', 'lunch', 'dinner'] as $mi => $m)
                  @if ($d['meals'][$m])<div class="{{ $shade[$mi] }}" style="height: {{ ($d['meals'][$m] / $d['total']) * 100 }}%"></div>@endif
                @endforeach
                @if ($d['specials'])<div class="border border-ink/40 bg-transparent" style="height: {{ ($d['specials'] / $d['total']) * 100 }}%"></div>@endif
              </div>
            </div>
          @endforeach
        </div>
        <div class="mt-1 flex min-w-[640px] gap-1" aria-hidden="true">
          @foreach ($days as $d)<span class="flex flex-1 justify-center">@if ($d['penalty'])<span class="h-1 w-full max-w-6 rounded-full bg-lime"></span>@endif</span>@endforeach
        </div>
        <div class="mt-2 flex min-w-[640px] gap-1 text-[10px] text-mute">
          @foreach ($days as $i => $d)<span class="flex-1 text-center">{{ $i % 5 === 0 || $i === count($days) - 1 ? Days::fromKey($d['key'])->day : '' }}</span>@endforeach
        </div>
      </div>
      @if (collect($days)->contains(fn ($d) => $d['penalty']))
        <p class="mt-4 text-[13px] text-mute">{{ t('Penalties deducted on') }} {{ collect($days)->filter(fn ($d) => $d['penalty'])->map(fn ($d) => $dm($d['key']) . ' (−' . $money($d['penalty']) . ')')->implode(', ') }}.</p>
      @endif
    </x-admin.section>
  @endif

  {{-- ---------------------------------------------------------------- Printable invoice --}}
  <x-admin.section tone="cream" class="print:rounded-none print:bg-page print:p-0">
    <article aria-label="{{ t('Invoice {0}', [0 => $number]) }}" class="invoice-print mx-auto max-w-4xl rounded-card bg-page p-6 md:p-12 print:max-w-none print:rounded-none print:p-0">
      <header class="flex flex-col gap-6 border-b border-ink/10 pb-8 md:flex-row md:items-start md:justify-between print:flex-row print:items-start print:justify-between">
        <div>
          <img src="/ldm-logo.png" alt="LDM Groupe" width="150" height="110" class="mb-5 h-16 w-auto print:mb-3 print:h-14">
          <p class="text-[13px] font-medium text-mute">{{ t('Invoice') }}</p>
          <h2 class="mt-1 text-4xl font-medium tracking-[-0.02em]">{{ $number }}</h2>
          <p class="mt-2 text-[15px] text-mute">{{ t('Meals ·') }} {{ $dm("{$month}-01") }} – {{ $dm($periodEnd) }} {{ substr($month, 0, 4) }}</p>
          @if ($inProgress)<span class="mt-3 inline-flex rounded-full bg-lime px-3 py-1 text-[12px] font-medium text-ink print:ring-1 print:ring-ink">{{ t('Draft · month in progress') }}</span>@endif
        </div>
        <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-[14px] md:text-end print:text-end">
          <dt class="text-mute">{{ t('Issue date') }}</dt><dd class="font-medium">{{ $dm($issueDate) }} {{ substr($issueDate, 0, 4) }}</dd>
          <dt class="text-mute">{{ t('Due date') }}</dt><dd class="font-medium">{{ $dm($dueDate) }} {{ substr($dueDate, 0, 4) }}</dd>
          <dt class="text-mute">{{ t('Billed on') }}</dt><dd class="font-medium">{{ t('Meals') }} {{ mb_strtolower($basisLabel) }}</dd>
        </dl>
      </header>

      <div class="grid grid-cols-1 gap-8 border-b border-ink/10 py-8 sm:grid-cols-2">
        <div>
          <p class="text-[12px] font-medium text-mute">{{ t('To · contractor') }}</p>
          <p class="mt-2 text-lg font-medium">@if ($invoice['contractorName'] !== ''){{ $invoice['contractorName'] }}@else<span class="text-mute print:hidden">{{ t('Not set — Settings → Invoice details') }}</span>@endif</p>
          <p class="text-[14px] whitespace-pre-line text-mute">{{ $invoice['contractorAddress'] }}</p>
          <p class="text-[14px] text-mute">{{ $invoice['contractorEmail'] }}</p>
        </div>
        <div>
          <p class="text-[12px] font-medium text-mute">{{ t('From') }}</p>
          <p class="mt-2 text-lg font-medium">{{ $invoice['companyName'] }}</p>
          <p class="text-[14px] whitespace-pre-line text-mute">{{ $invoice['companyAddress'] }}</p>
          <p class="text-[14px] text-mute">{{ $invoice['companyEmail'] }}</p>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="mt-6 w-full min-w-[480px] text-start text-[14px]">
          <thead class="text-[12px] text-mute">
            <tr class="border-b border-ink/10">
              <th class="py-2.5 text-start font-medium">{{ t('Meal') }}</th>
              <th class="py-2.5 text-end font-medium">{{ t('Quantity') }}</th>
              <th class="py-2.5 text-end font-medium">{{ t('Unit price') }}</th>
              <th class="py-2.5 text-end font-medium">{{ t('Amount') }}</th>
            </tr>
          </thead>
          @foreach ($groups as $g)
            <tbody>
              <tr><th colspan="4" scope="rowgroup" class="pt-6 pb-2 text-start text-[13px] font-medium">{{ t($g['label']) }}</th></tr>
              @foreach ($g['lines'] as $l)
                <tr class="border-b border-ink/10">
                  <td class="py-3"><span class="flex items-center gap-2.5 font-medium"><x-admin.icon-chip :icon="$icons[$l['type']]" size="sm" class="print:hidden" />{{ t($mealLabel[$l['type']]) }}</span></td>
                  <td class="py-3 text-end tabular-nums">{{ $l['quantity'] }}</td>
                  <td class="py-3 text-end tabular-nums">{{ $money($l['price']) }}</td>
                  <td class="py-3 text-end font-medium tabular-nums">{{ $money($l['amount']) }}</td>
                </tr>
              @endforeach
              <tr class="bg-cream print:bg-transparent">
                <td class="py-2.5 ps-3 font-medium">{{ t($g['label']) }} {{ t('subtotal') }}</td>
                <td class="py-2.5 text-end font-medium tabular-nums">{{ $g['quantity'] }}</td>
                <td></td>
                <td class="py-2.5 pe-3 text-end font-medium tabular-nums">{{ $money($g['amount']) }}</td>
              </tr>
            </tbody>
          @endforeach
          <tbody>
            <tr><th colspan="4" scope="rowgroup" class="pt-6 pb-2 text-start text-[13px] font-medium">{{ t('Sandwiches') }}</th></tr>
            <tr class="border-b border-ink/10">
              <td class="py-3"><span class="flex items-center gap-2.5 font-medium"><x-admin.icon-chip icon="sandwich" size="sm" class="print:hidden" />{{ t('Sandwiches ordered') }}</span></td>
              <td class="py-3 text-end tabular-nums">{{ $sandwiches }}</td><td></td>
              <td class="py-3 text-end font-medium tabular-nums">{{ $money(0) }}</td>
            </tr>
          </tbody>
          <tbody>
            <tr><th colspan="4" scope="rowgroup" class="pt-6 pb-2 text-start text-[13px] font-medium">{{ t('Special meals') }}</th></tr>
            @foreach ($specialLines as $l)
              <tr class="border-b border-ink/10">
                <td class="py-3"><span class="flex items-center gap-2.5 font-medium"><x-admin.icon-chip icon="sparkles" size="sm" class="print:hidden" />{{ t($l['label']) }}</span></td>
                <td class="py-3 text-end tabular-nums">{{ $l['quantity'] }}</td>
                <td class="py-3 text-end tabular-nums">{{ $money($l['price']) }}</td>
                <td class="py-3 text-end font-medium tabular-nums">{{ $money($l['amount']) }}</td>
              </tr>
            @endforeach
            <tr class="bg-cream print:bg-transparent">
              <td class="py-2.5 ps-3 font-medium">{{ t('Special meals subtotal') }}</td>
              <td class="py-2.5 text-end font-medium tabular-nums">{{ $specialQuantity }}</td><td></td>
              <td class="py-2.5 pe-3 text-end font-medium tabular-nums">{{ $money($specialAmount) }}</td>
            </tr>
          </tbody>
          <tbody>
            <tr><th colspan="4" scope="rowgroup" class="pt-6 pb-2 text-start text-[13px] font-medium">{{ t('Penalties (deducted)') }}</th></tr>
            @if (!$penaltyLines)
              <tr class="border-b border-ink/10"><td colspan="4" class="py-3 text-mute">{{ t('No penalties recorded this month.') }}</td></tr>
            @endif
            @foreach ($penaltyLines as $l)
              <tr class="border-b border-ink/10">
                <td class="py-3"><span class="flex items-center gap-2.5 font-medium"><x-admin.icon-chip icon="gavel" size="sm" class="print:hidden" />{{ t(P::SECTION_TITLE[$l['section']]) }}</span></td>
                <td class="py-3 text-end tabular-nums">{{ $l['count'] }}</td><td></td>
                <td class="py-3 text-end font-medium tabular-nums">−{{ $money($l['amount']) }}</td>
              </tr>
            @endforeach
            <tr class="bg-cream print:bg-transparent">
              <td class="py-2.5 ps-3 font-medium">{{ t('Penalties total') }}</td>
              <td class="py-2.5 text-end font-medium tabular-nums">{{ array_sum(array_column($penaltyLines, 'count')) }}</td><td></td>
              <td class="py-2.5 pe-3 text-end font-medium tabular-nums">−{{ $money($penaltyTotal) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <dl class="ms-auto mt-6 flex max-w-xs flex-col gap-2 text-[14px]">
        <div class="flex justify-between"><dt class="text-mute">{{ t('Meals ·') }} {{ $totalMeals }}</dt><dd class="tabular-nums">{{ $money($grossSubtotal) }}</dd></div>
        @if ($penaltyTotal)<div class="flex justify-between"><dt class="text-mute">{{ t('Penalties') }}</dt><dd class="tabular-nums">−{{ $money($penaltyTotal) }}</dd></div>@endif
        <div class="flex justify-between font-medium"><dt>{{ t('Subtotal') }}</dt><dd class="tabular-nums">{{ $money($subtotal) }}</dd></div>
        <div class="flex justify-between"><dt class="text-mute">{{ t('VAT') }} {{ $vat }}%</dt><dd class="tabular-nums">{{ $money($vatAmount) }}</dd></div>
        <div class="mt-2 flex justify-between rounded-[16px] bg-ink px-4 py-3 text-page print:bg-transparent print:px-0 print:text-ink"><dt class="font-medium">{{ t('Total due') }}</dt><dd class="font-medium tabular-nums">{{ $money($total) }}</dd></div>
      </dl>

      <h3 class="mt-12 text-xl font-medium print:break-before-page">{{ t('Daily breakdown') }}</h3>
      <p class="mt-1 text-[12px] text-mute">{{ t('Meals') }} {{ mb_strtolower($basisLabel) }} {{ t("per day · amounts are after that day's penalties") }}</p>
      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[760px] text-start text-[13px]" data-daily>
          <thead class="text-[12px] text-mute">
            <tr class="border-b border-ink/10">
              <th class="py-2 text-start font-medium">{{ t('Date') }}</th>
              @foreach ($mealLabel as $label)<th class="py-2 text-end font-medium">{{ t($label) }}</th>@endforeach
              <th class="py-2 text-end font-medium">{{ t('Sandwiches') }}</th>
              <th class="py-2 text-end font-medium">{{ t('Specials') }}</th>
              <th class="py-2 text-end font-medium">{{ t('Meals') }}</th>
              <th class="py-2 text-end font-medium">{{ t('Penalties') }}</th>
              <th class="py-2 text-end font-medium">{{ t('Amount') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($days as $d)
              <tr class="border-b border-ink/5 {{ Days::fromKey($d['key'])->isWeekend() ? 'text-mute' : '' }}">
                <td class="py-1.5"><span class="inline-block w-9">{{ Days::fromKey($d['key'])->translatedFormat('D') }}</span>{{ $dm($d['key']) }}</td>
                @foreach (['breakfast', 'lunch', 'dinner'] as $m)<td class="py-1.5 text-end tabular-nums">{{ $d['meals'][$m] }}</td>@endforeach
                <td class="py-1.5 text-end tabular-nums">{{ $d['sandwiches'] }}</td>
                <td class="py-1.5 text-end tabular-nums">{{ $d['specials'] }}</td>
                <td class="py-1.5 text-end font-medium tabular-nums">{{ $d['total'] }}</td>
                <td class="py-1.5 text-end tabular-nums">{{ $d['penalty'] ? '−' . $nf($d['penalty']) : '' }}</td>
                <td class="py-1.5 text-end tabular-nums">{{ $nf($d['amount']) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr class="font-medium">
              <td class="pt-3">{{ t('Total') }}</td>
              @foreach (['breakfast', 'lunch', 'dinner'] as $m)<td class="pt-3 text-end tabular-nums">{{ collect($days)->sum(fn ($d) => $d['meals'][$m]) }}</td>@endforeach
              <td class="pt-3 text-end tabular-nums">{{ $sandwiches }}</td>
              <td class="pt-3 text-end tabular-nums">{{ $specialQuantity }}</td>
              <td class="pt-3 text-end tabular-nums">{{ $totalMeals }}</td>
              <td class="pt-3 text-end tabular-nums">{{ $penaltyTotal ? '−' . $nf($penaltyTotal) : '' }}</td>
              <td class="pt-3 text-end tabular-nums">{{ $nf($subtotal) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="mt-10 text-[12px] text-mute">{{ t('Payment within') }} {{ $invoice['paymentDays'] }} {{ t('days of the issue date. Quantities come from the Mealboard booking records') }}{{ $basis === 'attended' ? t(', excluding meals marked as no-shows') : '' }}.</p>
    </article>
  </x-admin.section>

  {{-- ---------------------------------------------------------------- Settings summary --}}
  <x-admin.section tone="cream" class="print:hidden">
    <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('Invoice settings') }}</h2>
    <p class="mt-2 text-[14px] text-mute">{{ t('Contractor and company details are set by the super admin in Settings → Invoice details, the same for every admin.') }}</p>
    <div class="mt-8 grid grid-cols-1 gap-10 lg:grid-cols-2">
      <div class="flex flex-col gap-4">
        @foreach (['contractor' => 'Contractor', 'company' => 'Your company'] as $who => $title)
          <h3 class="text-xl font-medium {{ $who === 'company' ? 'mt-4' : '' }}">{{ t($title) }}</h3>
          <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 rounded-card bg-page p-5 text-[14px]">
            <dt class="text-mute">{{ t('Name') }}</dt><dd>{{ $invoice[$who . 'Name'] ?: '—' }}</dd>
            <dt class="text-mute">{{ t('Email') }}</dt><dd>{{ $invoice[$who . 'Email'] ?: '—' }}</dd>
            <dt class="text-mute">{{ t('Address') }}</dt><dd class="whitespace-pre-line">{{ $invoice[$who . 'Address'] ?: '—' }}</dd>
          </dl>
        @endforeach
        @if ($isSuper)<x-admin.btn href="/settings#invoice" variant="ghost" class="self-start">{{ t('Change in Settings') }}</x-admin.btn>@endif
      </div>
      <div class="flex flex-col gap-4">
        <h3 class="text-xl font-medium">{{ t('Prices & terms') }}</h3>
        <p class="-mt-2 text-[13px] text-mute">{{ t('Set by the super admin in Settings.') }}</p>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-card bg-page p-5 text-[14px]">
          @foreach ($mealLabel as $m => $label)<dt class="text-mute">{{ t($label) }}</dt><dd class="text-end tabular-nums">{{ $money($prices['meals'][$m]) }}</dd>@endforeach
          @foreach (\App\Http\Controllers\Admin\MealController::SPECIAL_KINDS as $kind => $label)<dt class="text-mute">{{ t($label) }}</dt><dd class="text-end tabular-nums">{{ $money($prices['specials'][$kind]) }}</dd>@endforeach
          <dt class="text-mute">{{ t('VAT') }}</dt><dd class="text-end tabular-nums">{{ $vat }}%</dd>
          <dt class="text-mute">{{ t('Payment') }}</dt><dd class="text-end tabular-nums">{{ t('within') }} {{ $invoice['paymentDays'] }} {{ t('days') }}</dd>
        </dl>
      </div>
    </div>
  </x-admin.section>
</x-layouts.admin>
