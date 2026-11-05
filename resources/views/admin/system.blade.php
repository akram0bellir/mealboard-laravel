@php
    $dot = ['ok' => 'bg-booked-ink', 'warn' => 'bg-ink/40', 'bad' => 'bg-lime'];
    $at = fn ($ms) => \Carbon\CarbonImmutable::createFromTimestampMs((int) $ms)->setTimezone(config('app.timezone'))->format('j M H:i:s');
    $size = fn ($b) => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
    $summary = function ($entry) {
        $r = $entry['result'] ?? [];
        $marked = array_sum(array_column($r['autoNoShow']['marked'] ?? [], 'marked'));
        $parts = [];
        $parts[] = ($r['autoNoShow']['status'] ?? '') === 'ran' ? t('{0} meal(s) marked', [0 => $marked]) : t('no-shows off');
        $parts[] = t('{0} e-mail(s) sent', [0 => $r['emails']['sent'] ?? 0]) . (($r['emails']['failed'] ?? 0) ? ', ' . ($r['emails']['failed']) . ' ' . t('failed') : '');
        return implode(' · ', $parts) . (isset($r['errors']) ? ' · ' . implode(' | ', $r['errors']) : '');
    };
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('System')" :description="t('Is everything running? The scheduled jobs, the door PCs, the e-mail queue and the backups.')">
      <x-slot:title>{{ t('What is') }} <span class="text-lime">{{ t('running') }}</span></x-slot:title>
    </x-admin.heading>
    @if ($status)
      <p role="status" class="mt-8 rounded-[20px] bg-ink-2 px-5 py-4 text-[15px] {{ $status[0] ? '' : 'text-lime' }}">{{ $status[1] }}</p>
    @endif
  </x-admin.section>

  <x-admin.section tone="cream">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
      {{-- ------------------------------------------------------------ Health --}}
      <x-admin.card tone="page" class="flex flex-col gap-4 xl:col-span-2">
        <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-heart-pulse class="size-5" aria-hidden="true" />{{ t('What works and what doesn\'t') }}</h2>
        <ul class="grid grid-cols-1 gap-2 md:grid-cols-2" data-health>
          @foreach ($health as $h)
            <li class="flex items-start gap-3 rounded-[16px] bg-cream px-4 py-3 text-[14px]" data-state="{{ $h['state'] }}">
              <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $dot[$h['state']] }}" aria-hidden="true"></span>
              <span><span class="font-medium">{{ t($h['name']) }}</span><span class="block text-[13px] text-mute">{{ $h['detail'] }}</span></span>
            </li>
          @endforeach
        </ul>
      </x-admin.card>

      {{-- ------------------------------------------------------------ Cron --}}
      <x-admin.card tone="page" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-timer class="size-5" aria-hidden="true" />{{ t('Scheduled jobs') }}</h2>
          <form method="POST" action="/system/cron">@csrf<x-admin.btn type="submit" variant="dark" size="sm"><x-lucide-play />{{ t('Run now') }}</x-admin.btn></form>
        </div>
        <p class="text-[13px] text-mute">{{ t('Every 5 minutes: automatic no-shows, the daily order list, the waiting e-mails.') }}</p>
        @if (!$log)
          <p class="text-[14px] text-mute">{{ t('No run yet.') }}</p>
        @else
          <ul class="flex flex-col gap-1.5 text-[13px]" data-cron-log>
            @foreach ($log as $e)
              <li class="flex items-start gap-3 rounded-[12px] bg-cream px-3 py-2">
                <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $e['ok'] ? 'bg-booked-ink' : 'bg-lime' }}"></span>
                <span><span class="font-medium tabular-nums">{{ $at($e['at']) }}</span> <span class="text-mute">· {{ $e['source'] }} · {{ $e['ms'] }} ms</span><span class="block text-mute">{{ $summary($e) }}</span></span>
              </li>
            @endforeach
          </ul>
        @endif
      </x-admin.card>

      {{-- ------------------------------------------------------------ Backups --}}
      <x-admin.card tone="page" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-database-backup class="size-5" aria-hidden="true" />{{ t('Backups') }}</h2>
          <form method="POST" action="/system/backup">@csrf<x-admin.btn type="submit" variant="dark" size="sm"><x-lucide-play />{{ t('Back up now') }}</x-admin.btn></form>
        </div>
        <p class="text-[13px] text-mute">{{ t('Every 6 hours: the whole database. Once a day: the photos. 14 days are kept, then one per week for 3 months.') }}</p>
        @if (!$backups)
          <p class="text-[14px] text-mute">{{ t('No backup yet.') }}</p>
        @else
          <ul class="flex flex-col gap-1.5 text-[13px]" data-backups>
            @foreach ($backups as $b)
              <li class="flex justify-between gap-3 rounded-[12px] bg-cream px-3 py-2"><span class="truncate font-medium">{{ $b['name'] }}</span><span class="shrink-0 text-mute tabular-nums">{{ \Carbon\CarbonImmutable::createFromTimestamp($b['at'])->setTimezone(config('app.timezone'))->format('j M H:i') }} · {{ $size($b['size']) }}</span></li>
            @endforeach
          </ul>
        @endif
        @if ($backupLog)<pre class="overflow-x-auto rounded-[12px] bg-cream px-3 py-2 text-[11px] text-mute">{{ implode("\n", $backupLog) }}</pre>@endif
      </x-admin.card>

      {{-- ------------------------------------------------------------ E-mail queue --}}
      <x-admin.card tone="page" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-mail class="size-5" aria-hidden="true" />{{ t('E-mail queue') }}</h2>
          <form method="POST" action="/system/emails">@csrf<x-admin.btn type="submit" variant="dark" size="sm"><x-lucide-send />{{ t('Send waiting e-mails') }}</x-admin.btn></form>
        </div>
        <p class="text-[13px] text-mute">{{ t('{0} waiting · {1} failed at least once · {2} sent', [0 => $queue['waiting'], 1 => $queue['failed'], 2 => $queue['sent']]) }}</p>
        @if ($emails->isEmpty())
          <p class="text-[14px] text-mute">{{ t('Nothing in the queue.') }}</p>
        @else
          <ul class="flex flex-col gap-1.5 text-[13px]" data-emails>
            @foreach ($emails as $m)
              <li class="rounded-[12px] bg-cream px-3 py-2">
                <span class="font-medium">{{ $m->to_address }}</span> <span class="text-mute">· {{ $m->sent_at ? t('sent') : ($m->attempts > 0 ? t('failed') : t('waiting')) }}</span>
                <span class="block truncate text-mute">{{ $m->subject }}</span>
                @if ($m->error !== '' && $m->error !== null)<span class="block text-lime">{{ $m->error }}</span>@endif
              </li>
            @endforeach
          </ul>
        @endif
      </x-admin.card>

      {{-- ------------------------------------------------------------ Doors --}}
      <x-admin.card tone="page" class="flex flex-col gap-4">
        <h2 class="flex items-center gap-2 text-2xl font-medium tracking-[-0.01em]"><x-lucide-door-open class="size-5" aria-hidden="true" />{{ t('Door PCs (last 3 days)') }}</h2>
        @if (!$doors)
          <p class="text-[14px] text-mute">{{ t('No door has reported yet. Doors send a heartbeat to /api/mealboard/door-heartbeat.') }}</p>
        @else
          <ul class="flex flex-col gap-1.5 text-[13px]" data-doors>
            @foreach ($doors as $d)
              <li class="rounded-[12px] bg-cream px-3 py-2">
                <span class="font-medium">{{ $d->name ?: $d->door }}</span> <span class="text-mute">· {{ $d->door }}</span>
                <span class="block text-mute">{{ t('last seen') }} {{ $at($d->last_seen) }} · {{ t('{0} mark(s) waiting to be sent', [0 => $d->pending]) }} · {{ t('waits {0} min after a meal', [0 => $d->close_after]) }}</span>
              </li>
            @endforeach
          </ul>
        @endif
      </x-admin.card>
    </div>
  </x-admin.section>
</x-layouts.admin>
