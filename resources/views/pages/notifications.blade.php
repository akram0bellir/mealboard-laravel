@php
    $dateTime = fn (string $created) => \Carbon\CarbonImmutable::parse($created . ' UTC')->setTimezone(config('app.timezone'))->translatedFormat('D j M, H:i');
@endphp
<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      <x-hero-slab>
        <x-top-bar />
        <div class="reveal relative z-10 flex flex-col items-start gap-4 pt-14 sm:pt-20">
          <x-back-link />
          <h1 class="text-4xl font-medium tracking-[-0.02em] sm:text-[56px]">{{ t('Notifications') }}</h1>
          <p class="max-w-[52ch] text-[17px] leading-relaxed text-muted-dark">
            {{ t('Menu updates, deadline reminders and kitchen news will show up here.') }}
          </p>
        </div>
      </x-hero-slab>

      <x-cream-slab>
        @if (count($items) === 0)
          <div class="grid gap-4 md:grid-cols-12">
            <div class="reveal flex min-h-72 flex-col justify-between gap-10 rounded-card bg-cream-deep p-8 sm:p-12 md:col-span-7">
              <span class="flex size-14 items-center justify-center rounded-full bg-ink">
                <x-lucide-bell-off class="size-5 text-lime" aria-hidden="true" />
              </span>
              <div class="flex flex-col gap-3">
                <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ t("You're all caught up") }}</h2>
                <p class="max-w-[48ch] text-[17px] leading-relaxed text-muted-light">
                  {{ t('There are no notifications yet. When the kitchen posts something, it will appear in this list.') }}
                </p>
              </div>
            </div>
          </div>
        @else
          <ul class="flex flex-col gap-3">
            @foreach ($items as $i => $n)
              @php $isNew = in_array($n['id'], $new, true); @endphp
              <li class="reveal flex items-start gap-4 rounded-card p-6 sm:p-8 {{ $isNew ? 'bg-cream-deep' : 'bg-page' }}" style="--i: {{ min($i, 6) }}">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-ink">
                  <x-lucide-bell class="size-4 text-lime" aria-hidden="true" />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-[17px] font-medium" dir="auto">{{ $n['title'] }}</h2>
                    <span class="flex items-center gap-2 text-[13px] text-muted-light">
                      @if ($isNew)<x-pill-label>{{ t('New') }}</x-pill-label>@endif
                      <time datetime="{{ $n['createdAt'] }}">{{ $dateTime($n['createdAt']) }}</time>
                    </span>
                  </div>
                  <p class="text-[15px] leading-relaxed text-muted-light" dir="auto">{{ $n['message'] }}</p>
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      </x-cream-slab>
    </main>
  </x-page-frame>
</x-layouts.app>
