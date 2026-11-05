{{-- "NFC check": what the phone needs for NFC, a permission button, and a test mode (the script fills in the ticks). --}}
@php
    $fix = t('Fix this first. Open chrome://flags, search “Insecure origins treated as secure”, type {origin} in the box, set Enabled, then press Relaunch. (If the PC’s address changes, add the new one.)', ['origin' => '@@ORIGIN@@']);
    [$fixBefore, $fixAfter] = explode('@@ORIGIN@@', $fix) + ['', ''];
    $rows = [
        'secure' => ['ok' => [t('Address allowed for NFC'), null], 'bad' => [t('Address allowed for NFC'), 'fix']],
        'supported' => [
            'wait' => [t('Browser can use NFC'), t('Waiting for the address step above.')],
            'ok' => [t('Browser can use NFC'), null],
            'bad' => [t('Browser can use NFC'), t('Use Chrome on an Android phone (iPhones and desktop browsers can’t).')],
        ],
        'permission' => [
            'wait' => [t('NFC permission'), t('Can be asked once the steps above are ✓.')],
            'unknown' => [t('NFC permission') . ': ' . t('not asked yet'), t('Press “Ask for NFC permission” and choose Allow.')],
            'prompt' => [t('NFC permission') . ': ' . t('prompt'), t('Press “Ask for NFC permission” and choose Allow.')],
            'granted' => [t('NFC permission') . ': ' . t('granted'), null],
            'denied' => [t('NFC permission') . ': ' . t('denied'), t('Tap the icon left of the address → Permissions → NFC → Allow, then reload.')],
        ],
        'scanning' => [
            'on' => [t('Scanning is on'), null],
            'off' => [t('Scanning is off'), t('Also check NFC is switched on in the phone settings.')],
        ],
    ];
@endphp
<details data-nfc-check open class="rounded-[20px] bg-page px-5 py-4"
         data-t-id="{{ t('Card ID') }}" data-t-reversed="{{ t('Reversed') }}" data-t-number="{{ t('Number') }}" data-t-not4="{{ t('(not a 4-byte card)') }}">
  <summary class="cursor-pointer text-[15px] font-medium">{{ t('NFC check') }}</summary>
  <ul class="mt-3 flex flex-col gap-2.5">
    @foreach ($rows as $name => $variants)
      <li data-row="{{ $name }}" class="flex gap-3 text-[14px]">
        <span data-icon aria-hidden="true" class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-cream-deep text-mute">?</span>
        <span>
          @foreach ($variants as $variant => [$label, $hint])
            <span data-v="{{ $variant }}" class="{{ $loop->first ? '' : 'hidden' }}">
              {{ $label }}
              @if ($hint === 'fix')
                <span class="block text-[12px] text-mute">{{ $fixBefore }}<span data-origin>{{ t('this address') }}</span>{{ $fixAfter }}</span>
              @elseif ($hint)
                <span class="block text-[12px] text-mute">{{ $hint }}</span>
              @endif
            </span>
          @endforeach
        </span>
      </li>
    @endforeach
  </ul>

  <div class="mt-4 flex flex-wrap gap-2">
    <x-admin.btn type="button" size="sm" variant="dark" data-nfc-ask data-t-ask="{{ t('Ask for NFC permission') }}" data-t-granted="{{ t('Permission granted') }}"><x-lucide-nfc aria-hidden="true" /> <span data-ask-label>{{ t('Ask for NFC permission') }}</span></x-admin.btn>
    <x-admin.btn type="button" size="sm" variant="soft" data-nfc-test data-t-start="{{ t('Test a card') }}" data-t-stop="{{ t('Stop testing') }}">{{ t('Test a card') }}</x-admin.btn>
  </div>

  <div data-test-area class="mt-4 hidden flex-col gap-2 [&:not(.hidden)]:flex">
    <p class="text-[13px] text-mute">{{ t('Test mode: hold any card to the phone. Nothing is checked or saved — this only shows what the phone can read.') }}</p>
    <p data-test-waiting class="rounded-[16px] bg-cream px-4 py-3 text-[14px]">{{ t('Waiting for a card… (if nothing appears after a few seconds, this phone can’t read the card)') }}</p>
    <ul data-test-reads class="flex flex-col gap-2"></ul>
  </div>
</details>
