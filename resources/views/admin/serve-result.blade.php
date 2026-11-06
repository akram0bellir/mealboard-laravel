{{-- The result card of the Serve page (also sent alone to the page's script, so NFC keeps scanning). --}}
@php
    $icons = ['breakfast' => 'coffee', 'lunch' => 'sun', 'dinner' => 'moon'];
    $r = $result;
@endphp
<div data-serve-result data-counts="{{ t('{served} / {booked} served', ['served' => $served, 'booked' => $booked]) }}" data-kind="{{ $r['kind'] ?? 'idle' }}">
  @if (!$r)
    <div class="flex min-h-72 flex-col items-center justify-center gap-4 rounded-section bg-page text-mute">
      <span class="relative flex size-16 items-center justify-center">
        <span aria-hidden="true" class="absolute inset-0 animate-ping rounded-full bg-ink/5"></span>
        <x-lucide-nfc class="size-8" aria-hidden="true" />
      </span>
      <p class="text-[15px]">{{ t('Waiting for a badge…') }}</p>
    </div>
  @elseif ($r['kind'] === 'error')
    <div class="flex min-h-72 flex-col items-center justify-center gap-4 rounded-section bg-page p-8 text-center animate-in fade-in zoom-in-95 duration-300" role="alert">
      <p class="max-w-[36ch] text-[15px] leading-relaxed">{{ $r['message'] }}</p>
      <x-admin.btn :href="'/serve?meal=' . $meal" variant="soft" size="sm" data-serve-dismiss>{{ t('OK') }}</x-admin.btn>
    </div>
  @elseif ($r['kind'] === 'unknown')
    <div class="overflow-hidden rounded-section bg-ink text-page animate-in fade-in zoom-in-95 duration-300" role="alert">
      <div class="flex flex-col items-center gap-2 px-6 pt-8 pb-6 text-center">
        <span class="flex size-14 items-center justify-center rounded-full bg-ink-3"><x-lucide-nfc class="size-7" aria-hidden="true" /></span>
        <p class="mt-2 text-2xl font-medium tracking-[-0.01em]">{{ t('Card not in the user list') }}</p>
        <p class="max-w-[34ch] text-[13px] leading-relaxed text-mute-dark">{{ $r['cardNumber'] === null ? t('This badge isn’t a 4-byte card, so it has no card number.') : t('No one has this number as their Card ID. Find the owner below to give it to them.') }}</p>
      </div>
      <div class="mx-6 mb-6 flex items-center justify-between rounded-[20px] bg-ink-2 px-5 py-4">
        <span class="text-[12px] tracking-[0.08em] text-mute-dark uppercase">{{ t('Card number') }}</span>
        <span class="font-mono text-2xl font-semibold tabular-nums" data-unknown-number>{{ $r['cardNumber'] ?? '—' }}</span>
      </div>
      @if ($r['cardNumber'] !== null)
        <form method="GET" action="/serve" class="mx-6 mb-4 flex gap-2">
          <input type="hidden" name="meal" value="{{ $meal }}"><input type="hidden" name="link" value="{{ $r['cardNumber'] }}">
          <x-admin.input name="q" tone="page" placeholder="{{ t('Whose badge is this? Name or email') }}" aria-label="{{ t('Find the badge owner') }}" required />
          <x-admin.btn type="submit" variant="primary">{{ t('Find') }}</x-admin.btn>
        </form>
      @endif
      <div class="flex justify-center pb-6"><x-admin.btn :href="'/serve?meal=' . $meal" variant="soft" size="sm" data-serve-dismiss>{{ t('Cancel') }}</x-admin.btn></div>
    </div>
  @else
    @php $ok = $r['allowed']; $p = $r['person']; @endphp
    <div role="alert" aria-live="assertive" class="relative overflow-hidden rounded-section animate-in fade-in zoom-in-95 duration-300 {{ $ok ? 'bg-booked-ink text-page' : 'bg-lime text-ink' }}">
      <div class="flex flex-col items-center gap-3 px-6 pt-8 pb-7 text-center">
        <span class="relative flex size-20 items-center justify-center">
          <span aria-hidden="true" class="absolute inset-0 rounded-full animate-ping [animation-iteration-count:2] {{ $ok ? 'bg-page/25' : 'bg-ink/20' }}"></span>
          <span class="relative flex size-20 items-center justify-center rounded-full shadow-lg {{ $ok ? 'bg-page text-booked-ink' : 'bg-ink text-lime' }}">
            @if ($ok)@icon('check', 'size-10', ['style' => 'stroke-width:3;', 'aria-hidden' => 'true'])@else@icon('x', 'size-10', ['style' => 'stroke-width:3;', 'aria-hidden' => 'true'])@endif
          </span>
        </span>
        <p class="text-[40px] leading-none font-semibold tracking-[-0.03em]" data-verdict>{{ $ok ? t('Allowed') : t('Not allowed') }}</p>
        <p class="text-[17px] font-medium {{ $ok ? 'text-page/85' : 'text-ink/80' }}" data-reason>{{ $ok ? t('Serve {meal}', ['meal' => mb_strtolower($mealLabel)]) : $r['reason'] }}</p>
      </div>
      <div class="mx-4 mb-4 rounded-[24px] bg-page text-ink shadow-sm">
        <div class="flex items-center gap-4 p-5">
          @if ($p['picture'])
            <img src="{{ $p['picture'] }}" alt="" class="size-16 shrink-0 rounded-full object-cover ring-4 ring-cream">
          @else
            <span class="shrink-0 scale-125 p-1.5"><x-admin.avatar :name="$p['name']" /></span>
          @endif
          <div class="min-w-0">
            <p class="truncate text-xl font-medium tracking-[-0.01em]">{{ $p['name'] }}</p>
            <p class="text-[13px] text-mute">{{ t('Department {dept}', ['dept' => $p['department']]) }}</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 px-5 pb-5">
          <span class="flex items-center gap-1.5 rounded-full bg-cream px-3 py-1.5 text-[13px] font-medium">@icon($icons[$meal], 'size-3.5', ['aria-hidden' => 'true']) {{ $mealLabel }}</span>
          @if (!empty($r['groupTime']))<span class="flex items-center gap-1.5 rounded-full bg-cream px-3 py-1.5 text-[13px] font-medium tabular-nums"><x-lucide-clock class="size-3.5" aria-hidden="true" /> {{ $r['groupTime'] }}</span>@endif
          @if ($r['served'])<span class="flex items-center gap-1.5 rounded-full bg-booked px-3 py-1.5 text-[13px] font-medium text-booked-ink"><x-lucide-check class="size-3.5" aria-hidden="true" /> {{ t('Marked served') }}</span>@endif
          @if (!empty($r['note']))<span class="flex items-center gap-1.5 rounded-full bg-ink px-3 py-1.5 text-[13px] font-medium text-page"><x-lucide-info class="size-3.5" aria-hidden="true" /> {{ $r['note'] }}</span>@endif
        </div>
        @if (!empty($r['warning']))<p data-gate-warning role="alert" class="mx-5 mb-4 rounded-[16px] bg-lime/20 px-4 py-3 text-[13px] font-medium">{{ $r['warning'] }}</p>@endif
        @if (!empty($r['cardNumber']))
          <div class="flex items-center justify-between border-t-2 border-dashed border-ink/10 px-5 py-3.5">
            <span class="text-[11px] tracking-[0.08em] text-mute uppercase">{{ t('Card number') }}</span>
            <span class="font-mono text-[17px] font-semibold tabular-nums">{{ $r['cardNumber'] }}</span>
          </div>
        @endif
        <div class="flex flex-wrap justify-end gap-2 border-t border-ink/10 px-5 py-3">
          @if (!$ok)
            {{-- Admin override: let this person eat anyway (books the meal if needed, marks it served, opens the gate). --}}
            <form method="POST" action="/serve/force" data-serve-force>
              @csrf
              <input type="hidden" name="user" value="{{ $p['id'] }}"><input type="hidden" name="meal" value="{{ $meal }}">
              <x-admin.btn type="submit" variant="dark" size="sm"><x-lucide-shield-check aria-hidden="true" /> {{ t('Serve anyway') }}</x-admin.btn>
            </form>
          @endif
          <x-admin.btn variant="soft" size="sm" data-print-ticket data-name="{{ $p['name'] }}" data-department="{{ $p['department'] }}" data-meal="{{ $mealLabel }}"><x-lucide-printer aria-hidden="true" /> {{ t('Print ticket') }}</x-admin.btn>
        </div>
      </div>
      <span aria-hidden="true" class="absolute inset-x-0 bottom-0 h-1 origin-left {{ $ok ? 'bg-page/40' : 'bg-ink/30' }}" style="animation: serve-countdown 8000ms linear forwards"></span>
    </div>
  @endif
</div>
