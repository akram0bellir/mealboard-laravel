@php
    use App\Support\Days;
    use App\Services\MealTimes;
    $meals = ['breakfast', 'lunch', 'dinner'];
    $cur = $invoice['currency'];
    $note = function (string $card) use ($status) {
        if (!$status || $status['card'] !== $card) {
            return '';
        }
        return '<p role="status" aria-live="polite" class="min-h-5 text-[13px] ' . ($status['ok'] ? 'text-ink' : 'font-medium text-danger') . '">' . e(t($status['text'])) . '</p>';
    };
    $times = array_map(fn ($i) => sprintf('%02d:%02d', intdiv(300 + $i * 15, 60), (300 + $i * 15) % 60), range(0, (24 - 5) * 4 - 1));
    $ramadanTimes = array_map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 4), ($i % 4) * 15), range(0, 24 * 4 - 1));
    $specialLabels = \App\Http\Controllers\Admin\MealController::SPECIAL_KINDS;
    $weekdays = [1, 2, 3, 4, 5, 6, 7];
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Settings')" :description="t('What everyone else sees as fixed: meal prices, invoice terms, and the booking and no-show rules.')">
      <x-slot:title>{{ t('Prices &') }} <span class="text-lime">{{ t('rules') }}</span></x-slot:title>
    </x-admin.heading>
  </x-admin.section>

  <x-admin.section tone="cream">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
      <div class="flex flex-col gap-6">

        {{-- ---------------------------------------------------------------- Prices --}}
        <x-admin.card tone="page" id="prices" class="scroll-mt-6">
          <form method="POST" action="/settings/prices" class="flex flex-col gap-5">
            @csrf
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-badge-dollar-sign class="size-5" aria-hidden="true" />{{ t('Prices') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t('Used for invoices and penalty amounts. Only you can change them.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              @foreach ($meals as $m)
                <x-admin.field :label="t(ucfirst($m)) . ' (' . $cur . ')'" for="pr-{{ $m }}"><x-admin.input id="pr-{{ $m }}" type="number" min="0" step="0.01" name="price[{{ $m }}]" value="{{ $prices['meals'][$m] }}" /></x-admin.field>
              @endforeach
            </div>
            <p class="px-1 text-[13px] font-medium">{{ t('Special meals') }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              @foreach ($specialLabels as $kind => $label)
                @continue($kind === 'vip' && !$hasVip)
                <x-admin.field :label="t($label) . ' (' . $cur . ')'" for="pr-{{ $kind }}"><x-admin.input id="pr-{{ $kind }}" type="number" min="0" step="0.01" name="price[{{ $kind }}]" value="{{ $prices['specials'][$kind] }}" /></x-admin.field>
              @endforeach
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <x-admin.field :label="t('Currency')" for="pr-cur"><x-admin.input id="pr-cur" name="currency" maxlength="5" value="{{ $cur }}" class="uppercase" /></x-admin.field>
              <x-admin.field :label="t('VAT %')" for="pr-vat"><x-admin.input id="pr-vat" type="number" min="0" max="100" step="0.01" name="vat" value="{{ $invoice['vat'] }}" /></x-admin.field>
              <x-admin.field :label="t('Pay within (days)')" for="pr-days"><x-admin.input id="pr-days" type="number" min="0" max="365" name="paymentDays" value="{{ $invoice['paymentDays'] }}" /></x-admin.field>
            </div>
            <div class="flex flex-wrap items-center gap-4"><x-admin.btn type="submit" variant="dark">{{ t('Save prices') }}</x-admin.btn>{!! $note('prices') !!}</div>
          </form>
        </x-admin.card>

        {{-- ---------------------------------------------------------------- Invoice details --}}
        <x-admin.card tone="page" id="invoice" class="scroll-mt-6">
          <form method="POST" action="/settings/invoice" class="flex flex-col gap-5">
            @csrf
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-receipt-text class="size-5" aria-hidden="true" />{{ t('Invoice details') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t('Printed on every invoice, under the LDM logo. Currency, VAT and payment days are in the Prices card.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              @foreach (['contractor' => 'To · contractor', 'company' => 'From · your company'] as $who => $title)
                <div class="flex flex-col gap-3">
                  <p class="text-[13px] font-medium">{{ t($title) }}</p>
                  <x-admin.field :label="t('Name')" for="iv-{{ $who }}-name"><x-admin.input id="iv-{{ $who }}-name" name="{{ $who }}Name" value="{{ $invoice[$who . 'Name'] }}" placeholder="{{ $who === 'company' ? 'LDM Groupe' : t('Catering company') }}" /></x-admin.field>
                  <x-admin.field :label="t('Email')" for="iv-{{ $who }}-email"><x-admin.input id="iv-{{ $who }}-email" name="{{ $who }}Email" value="{{ $invoice[$who . 'Email'] }}" placeholder="{{ $who === 'company' ? 'finance@ldmgroupe.com' : 'billing@caterer.com' }}" /></x-admin.field>
                  <x-admin.field :label="t('Address')" for="iv-{{ $who }}-addr"><x-admin.textarea id="iv-{{ $who }}-addr" name="{{ $who }}Address" class="!min-h-20">{{ $invoice[$who . 'Address'] }}</x-admin.textarea></x-admin.field>
                </div>
              @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-4"><x-admin.btn type="submit" variant="dark">{{ t('Save invoice details') }}</x-admin.btn>{!! $note('invoice') !!}</div>
          </form>
        </x-admin.card>

        {{-- ---------------------------------------------------------------- Meal times + Ramadan --}}
        <x-admin.card tone="page" id="times" class="scroll-mt-6">
          <div class="flex flex-col gap-6">
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-clock class="size-5" aria-hidden="true" />{{ t('Meal times') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t('Split a meal into time groups and give each department one. Someone can get a different group on the Users page.') }}</p>
            </div>
            {!! $note('times') !!}

            <form method="POST" action="/settings/ramadan" class="flex flex-col gap-3 rounded-[20px] p-5 {{ $rules->ramadan ? 'bg-ink text-page' : 'bg-cream' }}">
              @csrf
              <input type="hidden" name="on" value="{{ $rules->ramadan ? 0 : 1 }}">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p class="flex items-center gap-2 text-[15px] font-medium"><x-lucide-moon class="size-4" aria-hidden="true" />{{ t('Ramadan mode ·') }} {{ $rules->ramadan ? t('On') : t('Off') }}</p>
                  <p class="mt-1 text-[13px] {{ $rules->ramadan ? 'text-mute-dark' : 'text-mute' }}">{{ t('For everyone: lunch closed, breakfast becomes Suhoor, dinner becomes Iftar, and groups use their Ramadan time.') }}</p>
                  @unless ($rules->ramadan)<p class="mt-1 text-[13px] text-mute">{{ t('Turning it on cancels the lunch bookings still to come and tells the people.') }}</p>@endunless
                </div>
                <x-admin.btn type="submit" size="sm" :variant="$rules->ramadan ? 'primary' : 'dark'" aria-pressed="{{ $rules->ramadan ? 'true' : 'false' }}">{{ $rules->ramadan ? t('Turn off') : t('Turn on') }}</x-admin.btn>
              </div>
            </form>

            @foreach ($meals as $meal)
              @php $own = $groups->filter(fn ($g) => MealTimes::mealOf($g->meal) === $meal); @endphp
              <div class="flex flex-col gap-3 border-t border-ink/10 pt-5">
                <h3 class="text-[15px] font-medium">{{ t(ucfirst($meal)) }}</h3>
                @if ($own->isNotEmpty())
                  <ul class="flex flex-col gap-2">
                    @foreach ($own as $g)
                      <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-[24px] bg-cream px-5 py-2.5 text-[15px]">
                        <span><span class="font-medium">{{ $g->name }}</span><span class="ms-2 tabular-nums text-mute">{{ $g->start }}–{{ $g->end }}</span><span class="ms-2 text-[12px] text-mute">· {{ t('{0} people', [0 => $groupCounts[$g->id] ?? 0]) }}</span></span>
                        @if ($meal === 'lunch')
                          <span class="text-[12px] text-mute">{{ t('Closed in Ramadan') }}</span>
                        @else
                          <form method="POST" action="/settings/groups/{{ $g->id }}/ramadan" class="ms-auto flex items-center gap-1.5 text-[12px] text-mute">
                            @csrf
                            {{ t('Ramadan') }}
                            @foreach (['ramadan_start' => 'from', 'ramadan_end' => 'to'] as $part => $ph)
                              <select name="{{ $part }}" aria-label="{{ t('{0} Ramadan {1}', [0 => $g->name, 1 => $ph]) }}" class="h-8 cursor-pointer rounded-full bg-page px-2 text-[13px] text-ink tabular-nums">
                                <option value="">{{ t($ph) }}</option>
                                @foreach ($ramadanTimes as $tm)<option @selected($g->{$part} === $tm)>{{ $tm }}</option>@endforeach
                              </select>
                            @endforeach
                            <button type="submit" class="h-8 cursor-pointer rounded-full bg-ink px-3 text-[12px] font-medium text-page">{{ t('Save') }}</button>
                          </form>
                        @endif
                        <form method="POST" action="/settings/groups/{{ $g->id }}/delete">
                          @csrf
                          <button type="submit" class="cursor-pointer text-mute transition hover:text-ink" aria-label="{{ t('Delete {0}', [0 => $g->name]) }}"><x-lucide-trash-2 class="size-4" aria-hidden="true" /></button>
                        </form>
                      </li>
                    @endforeach
                  </ul>
                @else
                  <p class="text-[13px] text-mute">{{ t('No groups — everyone has the normal') }} {{ mb_strtolower(t(ucfirst($meal))) }} {{ t('time.') }}</p>
                @endif
                <form method="POST" action="/settings/groups" class="flex flex-wrap items-center gap-2">
                  @csrf<input type="hidden" name="meal" value="{{ $meal }}">
                  <x-admin.input name="name" aria-label="{{ t('New {0} group name', [0 => mb_strtolower(t(ucfirst($meal)))]) }}" placeholder="{{ t('Group {0}', [0 => $own->count() + 1]) }}" class="!h-10 !w-36" />
                  <div class="w-32"><x-admin.select name="start" aria-label="{{ t('Start') }}" class="!h-10 tabular-nums"><option value="">{{ t('Start') }}</option>@foreach ($times as $tm)<option>{{ $tm }}</option>@endforeach</x-admin.select></div>
                  <span class="text-mute">–</span>
                  <div class="w-32"><x-admin.select name="end" aria-label="{{ t('End') }}" class="!h-10 tabular-nums"><option value="">{{ t('End') }}</option>@foreach ($times as $tm)<option>{{ $tm }}</option>@endforeach</x-admin.select></div>
                  <x-admin.btn type="submit" variant="dark" size="sm">{{ t('Add group') }}</x-admin.btn>
                </form>
              </div>
            @endforeach

            <form method="POST" action="/settings/departments" class="flex flex-col gap-3 border-t border-ink/10 pt-5">
              @csrf<input type="hidden" name="card" value="times">
              <h3 class="text-[15px] font-medium">{{ t("Each department's group") }}</h3>
              <div class="overflow-x-auto">
                <table class="w-full text-start text-[15px]">
                  <thead class="text-[12px] text-mute"><tr><th class="py-2 text-start font-medium">{{ t('Department') }}</th>@foreach ($meals as $m)<th class="py-2 ps-2 text-start font-medium">{{ t(ucfirst($m)) }}</th>@endforeach</tr></thead>
                  <tbody>
                    @foreach ($departments as $d)
                      <tr class="border-t border-ink/10">
                        <td class="py-3 font-medium whitespace-nowrap">{{ t('Department') }} {{ $d }}</td>
                        @foreach ($meals as $m)
                          <td class="py-2 ps-2">
                            <x-admin.select name="groups[{{ $d }}][{{ $m }}]" aria-label="{{ t('Department {0} {1} group', [0 => $d, 1 => mb_strtolower(t(ucfirst($m)))]) }}" class="!h-10 min-w-32 text-[14px]">
                              <option value="">{{ t('Normal time') }}</option>
                              @foreach ($groups->filter(fn ($g) => MealTimes::mealOf($g->meal) === $m) as $g)
                                <option value="{{ $g->id }}" @selected(($deptRows->get($d)?->{$m . '_group'} ?? '') === $g->id)>{{ $g->name }} · {{ $g->start }}–{{ $g->end }}</option>
                              @endforeach
                            </x-admin.select>
                          </td>
                        @endforeach
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
              <x-admin.btn type="submit" variant="dark" size="sm" class="self-start">{{ t('Save department groups') }}</x-admin.btn>
            </form>
          </div>
        </x-admin.card>
      </div>

      <div class="flex flex-col gap-6">
        {{-- ---------------------------------------------------------------- No-show rules --}}
        <x-admin.card tone="page" id="rules" class="scroll-mt-6">
          <form method="POST" action="/settings/rules" class="group/rules flex flex-col gap-5">
            @csrf
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-settings-2 class="size-5" aria-hidden="true" />{{ t('No-show rules') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t('Now: {0} no-shows within {1} days of the first one pause booking until the period ends. Booking closes at {2}.', [0 => $rules->maxNoShow, 1 => $rules->periodDays, 2 => $rules->deadlineLabel()]) }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <x-admin.field :label="t('No-shows allowed')" for="r-max" :hint="t('Booking pauses when someone reaches this number.')"><x-admin.input id="r-max" type="number" min="1" step="1" name="maxNoShow" value="{{ $rules->maxNoShow }}" /></x-admin.field>
              <x-admin.field :label="t('Period (days)')" for="r-period" :hint="t('Counted from the first no-show; then everything resets.')"><x-admin.input id="r-period" type="number" min="1" step="1" name="periodDays" value="{{ $rules->periodDays }}" /></x-admin.field>
              <x-admin.field :label="t('Deadline hour (0–23)')" for="r-hour" :hint="t('Booking and cancelling close at this hour…')"><x-admin.input id="r-hour" type="number" min="0" max="23" step="1" name="deadlineHour" value="{{ $rules->deadlineHour }}" /></x-admin.field>
              <x-admin.field :label="t('Days before the meal')" for="r-before" :hint="t('…this many days before. 1 = the day before.')"><x-admin.input id="r-before" type="number" min="0" step="1" name="deadlineDaysBefore" value="{{ $rules->deadlineDaysBefore }}" /></x-admin.field>
              <x-admin.field :label="t('Sandwich deadline hour (0-23)')" for="r-sand" :hint="t('Sandwich orders close at this hour on the selected day.')"><x-admin.input id="r-sand" type="number" min="0" max="23" step="1" name="sandwichDeadlineHour" value="{{ $rules->sandwichDeadlineHour }}" /></x-admin.field>
            </div>
            <label class="flex cursor-pointer items-start gap-3 px-1 text-[15px]">
              <input type="checkbox" name="lateCancelCounts" value="1" class="mt-0.5 size-5 cursor-pointer accent-ink" @checked($rules->lateCancelCounts)>
              <span>{{ t('Allow late cancellations, counted as a no-show') }}<span class="block text-[13px] text-mute">{{ t("Off: the day locks at the deadline and can't be cancelled.") }}</span></span>
            </label>
            <div class="flex flex-col gap-3 px-1 text-[15px]">
              <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="autoNoShow" value="1" class="mt-0.5 size-5 cursor-pointer accent-ink" @checked($autoOn)>
                <span>{{ t('Mark no-shows automatically') }}<span class="block text-[13px] text-mute">{{ t('When a meal ends — the end of its last meal-time group, or when the last door closes if a door stays open longer — every booking of it nobody was served for at a door counts as a missed meal (one strike each). It waits until every door has sent what it served, so nobody who ate is marked. Only switch it on once every meal is scanned at a door.') }}</span></span>
              </label>
              <div class="hidden flex-wrap items-center gap-2 ps-8 group-has-[[name=autoNoShow]:checked]/rules:flex">
                <span class="text-[13px] font-medium">{{ t('Starting from') }}</span>
                <x-admin.input type="date" name="autoDay" value="{{ $autoDay }}" aria-label="{{ t('Starting from') }}" class="!h-10 !w-auto" />
                <div class="w-36"><x-admin.select name="autoMeal" aria-label="{{ t('Meal') }}" class="!h-10">@foreach ($meals as $m)<option value="{{ $m }}" @selected($autoMeal === $m)>{{ t(ucfirst($m)) }}</option>@endforeach</x-admin.select></div>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-4"><x-admin.btn type="submit" variant="dark">{{ t('Save rules') }}</x-admin.btn>{!! $note('rules') !!}</div>
          </form>
        </x-admin.card>

        {{-- ---------------------------------------------------------------- Department rules --}}
        <x-admin.card tone="page" id="departments" class="scroll-mt-6">
          <form method="POST" action="/settings/departments" class="flex flex-col gap-5">
            @csrf<input type="hidden" name="card" value="departments">
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-building-2 class="size-5" aria-hidden="true" />{{ t('Department rules') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t('Meals a new person from each department can book. Admins can still change them for someone special.') }}</p>
            </div>
            <table class="w-full text-start text-[15px]">
              <thead class="text-[12px] text-mute"><tr><th class="py-2 text-start font-medium">{{ t('Department') }}</th>@foreach ($meals as $m)<th class="py-2 text-center font-medium">{{ t(ucfirst($m)) }}</th>@endforeach</tr></thead>
              <tbody>
                @foreach ($departments as $d)
                  @php $row = $deptRows->get($d); $on = ['breakfast' => $row ? (int) $row->breakefast === 1 : false, 'lunch' => $row ? (int) $row->lunch === 1 : true, 'dinner' => $row ? (int) $row->dinner === 1 : false]; @endphp
                  <tr class="border-t border-ink/10">
                    <td class="py-3 font-medium">{{ t('Department') }} {{ $d }}</td>
                    @foreach ($meals as $m)
                      <td class="py-3 text-center"><span class="inline-flex justify-center"><x-admin.check id="dr-{{ $d }}-{{ $m }}" name="meals[{{ $d }}][{{ $m }}]" :checked="$on[$m]" :label="t('Department {0} can book {1}', [0 => $d, 1 => mb_strtolower(t(ucfirst($m)))])" /></span></td>
                    @endforeach
                  </tr>
                @endforeach
              </tbody>
            </table>
            <div class="flex flex-wrap items-center gap-4"><x-admin.btn type="submit" variant="dark">{{ t('Save department rules') }}</x-admin.btn>{!! $note('departments') !!}</div>
          </form>
        </x-admin.card>

        {{-- ---------------------------------------------------------------- SMTP --}}
        <x-admin.card tone="page" id="smtp" class="scroll-mt-6">
          <form method="POST" action="/settings/smtp" class="flex flex-col gap-5">
            @csrf
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-mail class="size-5" aria-hidden="true" />{{ t('SMTP Email settings') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t('Configure the outgoing mail server for automated system emails and alerts.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <x-admin.field :label="t('Sender name')" for="m-name" :hint="t('Display name on outgoing emails')"><x-admin.input id="m-name" name="senderName" value="{{ $smtp->Sender_name ?? '' }}" placeholder="e.g. Canteen Service" /></x-admin.field>
              <x-admin.field :label="t('Sender address')" for="m-addr" :hint="t('From email address')"><x-admin.input id="m-addr" name="senderAddress" value="{{ $smtp->Sender_address ?? '' }}" placeholder="e.g. noreply@example.com" /></x-admin.field>
              <x-admin.field :label="t('SMTP server host')" for="m-host" :hint="t('Host domain or IP address')"><x-admin.input id="m-host" name="serverHost" value="{{ $smtp->SMTP_server_host ?? '' }}" placeholder="e.g. smtp.gmail.com" /></x-admin.field>
              <x-admin.field :label="t('Port')" for="m-port" :hint="t('587 (STARTTLS) or 465 (SSL)')"><x-admin.input id="m-port" name="port" value="{{ $smtp->Port ?? '' }}" placeholder="587" /></x-admin.field>
              <x-admin.field :label="t('Username')" for="m-user" :hint="t('SMTP authentication account')"><x-admin.input id="m-user" name="username" autocomplete="off" value="{{ $smtp->Username ?? '' }}" placeholder="e.g. user@example.com" /></x-admin.field>
              <x-admin.field :label="t('Password')" for="m-pass" :hint="($smtp && $smtp->Password !== '' ? t('A password is saved.') . ' ' : '') . t('Leave blank to keep the current password. It is never shown again once saved.')"><x-admin.input id="m-pass" type="password" name="password" autocomplete="new-password" placeholder="••••••••••••" /></x-admin.field>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">{!! $note('smtp') !!}<x-admin.btn type="submit" variant="dark">{{ t('Save SMTP settings') }}</x-admin.btn></div>
          </form>
        </x-admin.card>

        {{-- ---------------------------------------------------------------- Daily order list --}}
        <x-admin.card tone="page" id="daily" class="scroll-mt-6">
          <form method="POST" action="/settings/daily" class="group/daily flex flex-col gap-5">
            @csrf
            <div>
              <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-mail class="size-5" aria-hidden="true" />{{ t('Daily order list') }}</h2>
              <p class="mt-1 text-[14px] text-mute">{{ t("Who receives the day's bookings as a CSV file (the kitchen, the caterer…). The Dashboard's \"Email orders\" starts from this list.") }}</p>
            </div>
            <x-admin.field :label="t('Receivers')" for="d-rec" :hint="count($daily['recipients']) ? t('{0} address(es) · separate them with commas or new lines', [0 => count($daily['recipients'])]) : t('One or more e-mail addresses, separated with commas or new lines.')">
              <x-admin.textarea id="d-rec" name="recipients" spellcheck="false" placeholder="kitchen@company.com&#10;contractor@catering.com">{{ implode("\n", $daily['recipients']) }}</x-admin.textarea>
            </x-admin.field>
            <label class="flex cursor-pointer items-start gap-3 px-1 text-[15px]">
              <input type="checkbox" name="auto" value="1" class="mt-0.5 size-5 cursor-pointer accent-ink" @checked($daily['auto'])>
              <span>{{ t('Send it automatically every day') }}<span class="block text-[13px] text-mute">{{ t('Once a day. If the server is off at that hour, it is sent as soon as the server is back.') }}</span></span>
            </label>
            <div class="hidden flex-wrap items-center gap-2 ps-8 text-[13px] font-medium group-has-[[name=auto]:checked]/daily:flex">
              {{ t('At') }}
              <div class="w-28"><x-admin.select name="hour" aria-label="{{ t('Hour') }}" class="!h-10">@for ($h = 0; $h < 24; $h++)<option value="{{ $h }}" @selected((int) $daily['hour'] === $h)>{{ sprintf('%02d:00', $h) }}</option>@endfor</x-admin.select></div>
              {{ t('the list for') }}
              <div class="w-40"><x-admin.select name="day" aria-label="{{ t('Which day') }}" class="!h-10"><option value="tomorrow" @selected($daily['day'] === 'tomorrow')>{{ t('the next day') }}</option><option value="today" @selected($daily['day'] === 'today')>{{ t('that day') }}</option></x-admin.select></div>
            </div>
            @if (!empty($daily['last']))
              <p class="text-[13px] {{ $daily['last']->error !== '' ? 'font-medium text-danger' : 'text-mute' }}">
                {{ $daily['last']->error !== '' ? t('Last try failed ({0}): {1}', [0 => $daily['last']->day, 1 => $daily['last']->error]) : t('Last list sent: {0}', [0 => $daily['last']->day]) }}
              </p>
            @endif
            <div class="flex flex-wrap items-center gap-4"><x-admin.btn type="submit" variant="dark">{{ t('Save list') }}</x-admin.btn>{!! $note('daily') !!}</div>
          </form>
        </x-admin.card>
      </div>

      {{-- ---------------------------------------------------------------- Manager meals --}}
      <x-admin.card tone="page" id="managers" class="scroll-mt-6 xl:col-span-2">
        <div class="flex flex-col gap-6">
          <div>
            <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-briefcase class="size-5" aria-hidden="true" />{{ t('Manager meals') }}</h2>
            <p class="mt-1 text-[14px] text-mute">{{ t('Meals for the department directors, booked automatically every day at the normal price (default {0}). Only meals on the menu that day are counted.', [0 => \App\Services\ManagerMeals::DEFAULT_COUNT]) }}</p>
          </div>
          {!! $note('managers') !!}
          <form method="POST" action="/settings/managers/weekly" class="flex flex-col gap-4">
            @csrf
            <div class="overflow-x-auto">
              <table class="w-full text-start text-[15px]">
                <thead class="text-[12px] text-mute"><tr><th class="py-2 text-start font-medium">{{ t('Day') }}</th>@foreach ($meals as $m)<th class="py-2 ps-2 text-start font-medium">{{ t(ucfirst($m)) }}</th>@endforeach</tr></thead>
                <tbody>
                  @foreach ($weekdays as $w)
                    @php $wd = Days::fromKey('2026-10-' . (18 + $w))->translatedFormat('l'); @endphp
                    <tr class="border-t border-ink/10">
                      <td class="py-2 font-medium whitespace-nowrap capitalize">{{ $wd }}</td>
                      @foreach ($meals as $m)
                        <td class="py-1.5 ps-2"><x-admin.input type="number" min="0" inputmode="numeric" name="week[{{ $w }}][{{ $m }}]" value="{{ $managers['weekly'][$w][$m] }}" aria-label="{{ $wd }} {{ t(ucfirst($m)) }}" class="!h-10 !w-24 tabular-nums" /></td>
                      @endforeach
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <x-admin.btn type="submit" variant="dark" class="self-start">{{ t('Save weekly numbers') }}</x-admin.btn>
          </form>

          <div class="flex flex-col gap-3 border-t border-ink/10 pt-5">
            <h3 class="text-[15px] font-medium">{{ t('A different number on one day') }}</h3>
            <form method="POST" action="/settings/managers/day" class="flex flex-wrap items-end gap-2">
              @csrf
              <x-admin.input type="date" name="date" min="{{ $today }}" aria-label="{{ t('Day') }}" class="!h-10 !w-44" required />
              @foreach ($meals as $m)
                <label class="flex flex-col gap-1 text-[12px] text-mute">{{ t(ucfirst($m)) }}<x-admin.input type="number" min="0" name="day[{{ $m }}]" value="{{ \App\Services\ManagerMeals::DEFAULT_COUNT }}" class="!h-10 !w-24 tabular-nums" /></label>
              @endforeach
              <x-admin.btn type="submit" variant="dark" size="sm">{{ t('Save this day') }}</x-admin.btn>
            </form>
            @php $special = collect($managers['days'])->filter(fn ($c, $k) => $k >= $today)->sortKeys(); @endphp
            @if ($special->isNotEmpty())
              <ul class="flex flex-col gap-2">
                @foreach ($special as $k => $c)
                  <li class="flex flex-wrap items-center justify-between gap-3 rounded-full bg-cream px-5 py-2.5 text-[14px]">
                    <span class="font-medium">{{ Days::fromKey($k)->translatedFormat('l j F') }}</span>
                    <span class="tabular-nums text-mute">{{ collect($meals)->map(fn ($m) => t(ucfirst($m)) . ' ' . $c[$m])->implode(' · ') }}</span>
                    <form method="POST" action="/settings/managers/day/{{ $c['id'] }}/delete">
                      @csrf
                      <button type="submit" class="cursor-pointer text-mute transition hover:text-ink" aria-label="{{ t('Remove {0}', [0 => Days::fromKey($k)->translatedFormat('l j F')]) }}"><x-lucide-trash-2 class="size-4" aria-hidden="true" /></button>
                    </form>
                  </li>
                @endforeach
              </ul>
            @else
              <p class="text-[13px] text-mute">{{ t('No special days — every day uses the weekly numbers.') }}</p>
            @endif
          </div>
        </div>
      </x-admin.card>
    </div>
  </x-admin.section>
</x-layouts.admin>
