@php $filtered = $userId !== 'all' || $date !== '' || $read !== 'all'; @endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('User reports')" :description="t('Feedback and issues sent from the app. Replies land directly in the user\'s notifications.')">
      <x-slot:title>{{ t('What people are') }} <span class="text-lime">{{ t('saying') }}</span></x-slot:title>
    </x-admin.heading>
    <div class="mt-12 grid grid-cols-2 gap-6 md:grid-cols-4">
      <x-admin.card tone="ink-2" :delay="1">
        <p class="text-[13px] text-mute-dark">{{ t('Unread') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em] text-lime">{{ $unread }}</p>
      </x-admin.card>
      <x-admin.card :delay="2">
        <p class="text-[13px] text-mute-dark">{{ t('Total reports') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em]">{{ $total }}</p>
      </x-admin.card>
    </div>
  </x-admin.section>

  <x-admin.section tone="cream">
    @if (session('report_flash'))
      <p role="status" class="mb-6 rounded-full bg-booked px-5 py-3 text-[15px] font-medium text-booked-ink">{{ t(session('report_flash')) }}</p>
    @endif

    <form method="GET" action="/reports" data-autosubmit class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex flex-wrap items-center gap-3">
        <div class="w-56">
          <label for="filter-user" class="sr-only">{{ t('Filter by user') }}</label>
          <x-admin.select tone="page" id="filter-user" name="user">
            <option value="all">{{ t('All users') }}</option>
            @foreach ($reporters as $u)
              <option value="{{ $u['id'] }}" @selected($userId === $u['id'])>{{ $u['name'] }}</option>
            @endforeach
          </x-admin.select>
        </div>
        <div>
          <label for="filter-date" class="sr-only">{{ t('Filter by date') }}</label>
          <x-admin.input tone="page" id="filter-date" type="date" name="date" value="{{ $date }}" class="!w-auto" />
        </div>
        <x-admin.btn type="submit" variant="soft" class="h-12">{{ t('Apply') }}</x-admin.btn>
        @if ($filtered)
          <x-admin.btn href="/reports" variant="ghost" class="h-12">{{ t('Clear filters') }}</x-admin.btn>
        @endif
      </div>
      <x-admin.segmented name="read" :label="t('Filter by read status')" :value="$read" :options="['all' => 'All', 'unread' => 'Unread', 'read' => 'Read']" />
    </form>

    <ul class="mt-8 flex flex-col gap-3">
      @foreach ($reports as $r)
        <li class="reveal rounded-card p-6 transition-colors duration-200 md:p-8 {{ !$r['read'] ? 'bg-cream-deep' : 'bg-transparent' }}" style="animation-delay: {{ min($loop->index, 8) * 80 }}ms">
          <article aria-labelledby="report-{{ $r['id'] }}">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
              <div class="flex items-center gap-3">
                <x-admin.avatar :name="$r['name']" />
                <div>
                  <p class="text-[15px] font-medium">{{ $r['name'] }}</p>
                  <p class="text-[13px] text-mute"><time datetime="{{ $r['created']->toIso8601String() }}">{{ $r['created']->translatedFormat('j M, H:i') }}</time>{{ $r['department'] !== '' ? ' · ' . $r['department'] : '' }}</p>
                </div>
                @unless ($r['read'])
                  <span class="ms-2 rounded-full bg-ink px-2.5 py-1 text-[11px] font-medium text-lime">{{ t('New') }}</span>
                @endunless
              </div>
              <div class="flex gap-2">
                <form method="POST" action="/reports/{{ $r['id'] }}/read">
                  @csrf
                  <input type="hidden" name="read" value="{{ $r['read'] ? 0 : 1 }}">
                  <x-admin.btn type="submit" size="sm" variant="ghost">
                    @if ($r['read'])<x-lucide-mail />@else<x-lucide-mail-open />@endif
                    {{ t('Mark as') }} {{ $r['read'] ? t('unread') : t('read') }}
                  </x-admin.btn>
                </form>
              </div>
            </div>

            <h2 id="report-{{ $r['id'] }}" class="mt-6 text-2xl font-medium tracking-[-0.01em]" dir="auto">{{ $r['subject'] }}</h2>
            <p class="mt-2 max-w-[70ch] text-[17px] leading-relaxed whitespace-pre-line text-mute" dir="auto">{{ $r['message'] }}</p>

            @if ($r['picture'])
              <a href="{{ $r['picture'] }}" target="_blank" rel="noreferrer" class="mt-5 block w-fit" aria-label="{{ t('Open the picture full size') }}">
                <img src="{{ $r['thumb'] }}" alt="{{ t('Picture attached to "{0}"', [0 => $r['subject']]) }}" loading="lazy" class="max-h-72 w-auto max-w-full rounded-[20px] object-cover">
              </a>
            @endif

            @if ($r['meal'])
              <span class="mt-5 inline-flex items-center gap-2 rounded-full bg-page px-4 py-2 text-[13px] font-medium">
                <x-lucide-utensils class="size-3.5" aria-hidden="true" />{{ $r['meal'] }}
              </span>
            @endif

            @if ($r['reply'] !== '')
              <ul class="mt-6 flex flex-col gap-2">
                <li class="max-w-[70ch] rounded-[20px] bg-ink p-4 text-[15px] text-page">
                  <p class="text-[12px] text-mute-dark">{{ t('Your reply ·') }} <time datetime="{{ $r['updated']->toIso8601String() }}">{{ $r['updated']->translatedFormat('j M, H:i') }}</time></p>
                  <p class="mt-1 leading-relaxed" dir="auto">{{ $r['reply'] }}</p>
                </li>
              </ul>
            @endif

            {{-- Reply: opens in place, no JavaScript needed. --}}
            <details class="group mt-6 max-w-[70ch]">
              <summary class="inline-flex h-9 cursor-pointer list-none items-center gap-2 rounded-full bg-ink px-4 text-[13px] font-medium text-page transition duration-200 hover:scale-[1.02] [&::-webkit-details-marker]:hidden">
                <x-lucide-reply class="size-4" />{{ t('Reply') }}
              </summary>
              <form method="POST" action="/reports/{{ $r['id'] }}/reply" class="mt-4 flex flex-col gap-3">
                @csrf
                <label for="reply-{{ $r['id'] }}" class="sr-only">{{ t('Reply to') }} {{ $r['name'] }}</label>
                <x-admin.textarea tone="page" id="reply-{{ $r['id'] }}" name="message" required maxlength="1000" placeholder="{{ t('Write a reply to {0}…', [0 => $r['firstName'] ?: 'the user']) }}"></x-admin.textarea>
                <div>
                  <x-admin.btn type="submit" variant="primary"><x-lucide-send />{{ $r['reply'] !== '' ? t('Replace reply & notify') : t('Send to notifications') }}</x-admin.btn>
                </div>
              </form>
            </details>
          </article>
        </li>
      @endforeach
    </ul>
    @if ($reports->isEmpty())
      <p class="py-16 text-center text-mute">{{ t('No reports match these filters.') }}</p>
    @endif
  </x-admin.section>
</x-layouts.admin>
