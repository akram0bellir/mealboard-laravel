@php
    $mealOptions = collect(['breakfast', 'lunch', 'dinner'])->reject(fn ($m) => $rules->mealClosed($m))->mapWithKeys(fn ($m) => [$m => $rules->mealLabel($m)])->all();
    $ticketLang = in_array(app()->getLocale(), ['en', 'fr', 'ar'], true) ? app()->getLocale() : 'en';
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Serve')" :title="$mealLabel . ' · ' . t('door check')" :description="t('Hold an employee\'s badge to the back of this phone. Green = serve them; red = don\'t.')" />
    <form method="GET" action="/serve" data-autosubmit class="mt-6 flex flex-wrap items-center gap-4">
      <x-admin.segmented name="meal" :label="t('Meal')" dark :value="$meal" :options="$mealOptions" />
      <x-admin.btn type="submit" variant="soft-dark" size="sm">{{ t('Show') }}</x-admin.btn>
      <p class="ms-auto text-[15px] tabular-nums text-mute-dark" data-serve-counts>{{ t('{served} / {booked} served', ['served' => $served, 'booked' => $booked]) }}</p>
    </form>
  </x-admin.section>

  <x-admin.section tone="cream">
    <div class="mx-auto flex w-full max-w-xl flex-col gap-5" data-serve
         data-meal="{{ $meal }}" data-token="{{ csrf_token() }}" data-ticket-lang="{{ $ticketLang }}"
         data-t-unsupported="{{ t('This browser can’t read badges. Use Chrome on an Android phone with NFC switched on. Over Wi-Fi (http) also enable chrome://flags → “Insecure origins treated as secure” for this address. You can still type a card number or name below.') }}"
         data-t-denied="{{ t('NFC permission was refused. Tap the lock icon next to the address → Permissions → NFC → Allow, then try again.') }}"
         data-t-off="{{ t('NFC is switched off or not available. Turn on NFC in the phone settings, then try again.') }}"
         data-t-unreadable="{{ t('The phone felt a card but couldn\'t read it. Hold it still against the NFC spot (middle or top of the phone\'s back). If it keeps failing, this card type isn\'t supported by phone NFC.') }}"
         data-t-noid="{{ t('The phone read the card but it has no id it can share. This type of card can\'t be used with phone NFC.') }}"
         data-t-network="{{ t('Couldn\'t reach the server. Check the Wi-Fi and try again.') }}"
         data-t-card="{{ t('card number') }}">
      <p data-nfc-on class="hidden items-center justify-center gap-2 rounded-full bg-booked px-5 py-3 text-[15px] font-medium text-booked-ink" role="status"><x-lucide-nfc class="size-5" aria-hidden="true" /> {{ t('Scanning — hold a badge to the phone') }}</p>
      <p data-nfc-message class="hidden rounded-[20px] bg-page px-5 py-4 text-[14px] text-mute"></p>
      <x-admin.btn variant="dark" size="lg" class="w-full" data-nfc-start><x-lucide-nfc aria-hidden="true" /> {{ t('Start scanning badges') }}</x-admin.btn>
      @include('admin.serve-nfc-check')
      <p data-nfc-last class="hidden text-center text-[12px] text-mute tabular-nums">{{ t('Last card') }}: {{ t('none yet — hold one to the phone') }}</p>

      <div data-serve-slot>@include('admin.serve-result')</div>

      <x-admin.btn variant="soft" size="sm" data-print-ticket data-name="Demo Ticket Person" data-department="Test Department" data-meal="{{ $mealLabel }}"><x-lucide-printer aria-hidden="true" /> {{ t('Test print ticket') }}</x-admin.btn>
      <x-admin.btn :href="'/serve?relay=1&meal=' . $meal" variant="soft" size="sm"><x-lucide-door-open aria-hidden="true" /> {{ t('Find relay') }}</x-admin.btn>

      {{-- Manual search: a card number typed by hand (or entered by a USB badge reader), or a name / e-mail. --}}
      <form method="GET" action="/serve" class="flex gap-2">
        <input type="hidden" name="meal" value="{{ $meal }}">
        @if ($linkNumber)<input type="hidden" name="link" value="{{ $linkNumber }}">@endif
        <div class="relative flex-1">
          <x-lucide-search class="pointer-events-none absolute start-4 top-1/2 size-4 -translate-y-1/2 text-mute" aria-hidden="true" />
          <x-admin.input name="q" value="{{ $q }}" aria-label="{{ $linkNumber ? t('Find the badge owner') : t('Card number or name') }}" placeholder="{{ $linkNumber ? t('Whose badge is this? Name or email') : t('Card number or name') }}" class="ps-11" />
        </div>
        <x-admin.btn type="submit" variant="dark">{{ t('Find') }}</x-admin.btn>
      </form>
      @if ($matches !== null)
        <ul class="flex flex-col gap-1 rounded-[20px] bg-page p-2">
          @if (!$matches)<li class="px-3 py-2 text-[14px] text-mute">{{ t('Nobody found.') }}</li>@endif
          @foreach ($matches as $p)
            <li>
              <form method="POST" action="{{ $linkNumber ? '/serve/link' : '/serve/person' }}">
                @csrf
                <input type="hidden" name="user" value="{{ $p['id'] }}"><input type="hidden" name="meal" value="{{ $meal }}">
                @if ($linkNumber)<input type="hidden" name="cardNumber" value="{{ $linkNumber }}">@endif
                <button type="submit" class="flex w-full cursor-pointer items-center gap-3 rounded-full px-2 py-1.5 text-start hover:bg-cream">
                  <x-admin.avatar :name="$p['name']" />
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-medium">{{ $p['name'] }}</span>
                    <span class="block truncate text-[12px] text-mute">{{ t('Dept {dept}', ['dept' => $p['department']]) }}{{ $p['cardId'] ? ' · ' . t('card') . ' ' . $p['cardId'] : '' }}</span>
                  </span>
                  @if ($linkNumber)<x-lucide-link-2 class="size-4 text-mute" aria-label="{{ t('Link badge') }}" />@endif
                </button>
              </form>
            </li>
          @endforeach
        </ul>
      @endif
      <p class="text-center text-[12px] text-mute">{{ t('The turnstile relay is driven by the desktop door app; this page checks and marks people served.') }}</p>
    </div>
  </x-admin.section>

  {{-- "Find relay" (from the door app): the COM ports of this PC; Test sends the open message to one and shows what
       the board answers; "Use this one" saves it as the gate. --}}
  @if ($relay)
    @php
        $d = $relay['draft'];
        $endingKey = ['' => '', "\n" => 'n', "\r\n" => 'rn'][$d['ending']] ?? '';
        $what = fn ($c) => $c['message'] === 'text' ? '"' . $c['text'] . '"' : t('relay bytes, channel {relay}', ['relay' => $c['relay']]);
        $note = $relay['note'];
    @endphp
    <x-admin.modal wide :title="t('Find relay')" :description="t('Plug the USB relay into the PC running Mealboard, press Test to hear which one clicks the gate, then Use this one.')" :close="'/serve?meal=' . $meal">
      <form method="POST" action="/serve/relay/test" class="flex flex-col gap-4" data-relay-form>
        @csrf
        <input type="hidden" name="meal" value="{{ $meal }}">
        <div class="flex flex-wrap items-center gap-3">
          <label class="flex items-center gap-2 text-[13px] font-medium">{{ t('Message') }}
            <x-admin.select name="message" class="!h-9 !w-auto !px-3 !text-[13px]">
              <option value="text" @selected($d['message'] === 'text')>{{ t('Text') }}</option>
              <option value="bytes" @selected($d['message'] === 'bytes')>{{ t('Relay bytes (A0 01 01 A2)') }}</option>
            </x-admin.select>
          </label>
          <x-admin.input name="text" value="{{ $d['text'] }}" aria-label="{{ t('Text to send') }}" class="!h-9 !w-28 !px-3 font-mono !text-[13px]" maxlength="32" />
          <x-admin.select name="ending" aria-label="{{ t('Line ending') }}" class="!h-9 !w-auto !px-3 !text-[13px]">
            <option value="" @selected($endingKey === '')>{{ t('Nothing') }}</option>
            <option value="n" @selected($endingKey === 'n')>{{ t('New line (\\n)') }}</option>
            <option value="rn" @selected($endingKey === 'rn')>{{ t('CR + new line (\\r\\n)') }}</option>
          </x-admin.select>
          <label class="flex items-center gap-2 text-[13px] font-medium">{{ t('Channel') }}
            <x-admin.select name="relay" class="!h-9 !w-auto !px-3 !text-[13px]">@foreach (range(1, 8) as $c)<option value="{{ $c }}" @selected($d['relay'] === $c)>{{ $c }}</option>@endforeach</x-admin.select>
          </label>
          <x-admin.btn :href="'/serve?relay=1&meal=' . $meal" variant="soft" size="sm" class="ms-auto"><x-lucide-refresh-cw aria-hidden="true" /> {{ t('Search again') }}</x-admin.btn>
        </div>
        <p class="text-[12px] text-mute">{{ t('"Text" is for the canteen\'s Arduino; "Relay bytes" is for the LCUS relay boards (set the channel).') }}</p>

        <p class="text-[12px] text-mute">
          {{ $relay['config']['port'] ? t('In use: {port}, {what}', ['port' => $relay['config']['port'], 'what' => $what($relay['config'])]) : t('Nothing picked yet: the first Arduino / relay board found is used.') }}
        </p>

        @if ($relay['error'])
          <p class="text-[14px] font-medium text-danger">{{ $relay['error'] }}</p>
        @elseif (!$relay['devices'])
          <p class="text-[14px] text-mute">{{ t('No COM port found. Plug in the relay and press Search again.') }}</p>
        @else
          <ul class="flex flex-col gap-2">
            @foreach ($relay['devices'] as $dev)
              <li class="flex flex-wrap items-center gap-3 rounded-[16px] px-4 py-3 {{ $dev['selected'] ? 'bg-booked' : 'bg-cream' }}">
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-[14px] font-medium">{{ $dev['path'] }}{{ $dev['kind'] ? ' · ' . $dev['kind'] : '' }} @if ($dev['selected'])<x-lucide-check class="inline size-4" aria-label="{{ t('In use') }}" />@endif</span>
                  <span class="block truncate text-[11px] text-mute">{{ $dev['name'] ?: t('Unnamed device') }}@if ($dev['vendorId'])<span class="font-mono"> · {{ strtoupper($dev['vendorId'] . ':' . $dev['productId']) }}</span>@endif</span>
                </span>
                <x-admin.btn type="submit" name="port" value="{{ $dev['path'] }}" formaction="/serve/relay/test" variant="soft" size="sm"><x-lucide-zap aria-hidden="true" /> {{ t('Test') }}</x-admin.btn>
                <x-admin.btn type="submit" name="port" value="{{ $dev['path'] }}" formaction="/serve/relay/choose" variant="dark" size="sm">{{ t('Use this one') }}</x-admin.btn>
              </li>
            @endforeach
          </ul>
        @endif

        @if ($note)
          <p role="status" class="text-[13px] font-medium {{ $note[0] ? '' : 'text-danger' }}">{{ $note[1] }}</p>
          @if ($note[2] !== null)
            <div class="rounded-[16px] bg-ink px-4 py-3 text-page">
              <p class="text-[11px] tracking-[0.08em] text-mute-dark uppercase">{{ t('The board answered') }}</p>
              <p class="mt-1 font-mono text-[13px] break-all whitespace-pre-wrap">{{ $note[2] !== '' ? $note[2] : t('(nothing)') }}</p>
            </div>
          @endif
        @endif
      </form>
    </x-admin.modal>
  @endif
</x-layouts.admin>
