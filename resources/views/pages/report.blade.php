@php
    $status = session('report_status');
    $field = 'h-14 w-full rounded-full bg-page px-6 text-[15px] placeholder:text-muted-light focus:outline-2 focus:outline-ink';
    $dateTime = fn (string $created) => \Carbon\CarbonImmutable::parse($created . ' UTC')->setTimezone(config('app.timezone'))->translatedFormat('j M, H:i');
@endphp
<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      <x-hero-slab>
        <x-top-bar />
        <div class="reveal relative z-10 flex flex-col items-start gap-4 pt-14 sm:pt-20">
          <x-back-link />
          <h1 class="text-4xl font-medium tracking-[-0.02em] sm:text-[56px]">{{ t('Report a problem') }}</h1>
          <p class="max-w-[52ch] text-[17px] leading-relaxed text-muted-dark">
            {{ t('Something wrong with a meal or the app? Tell the kitchen team — add a picture if it helps. Their reply arrives in your notifications.') }}
          </p>
        </div>
      </x-hero-slab>

      <x-cream-slab :label="t('Report a problem')">
        <div class="grid gap-4 md:grid-cols-12">
          {{-- Form --}}
          <form method="POST" action="/report" enctype="multipart/form-data" data-report-form
                data-type-error="{{ t('Please choose an image (JPG, PNG or WebP).') }}" data-size-error="{{ t('The picture must be under {mb} MB.', ['mb' => 5]) }}"
                class="reveal flex flex-col gap-4 rounded-card bg-cream-deep p-8 sm:p-12 md:col-span-7">
            @csrf
            <span class="flex size-14 items-center justify-center rounded-full bg-ink">
              <x-lucide-message-square-text class="size-5 text-lime" aria-hidden="true" />
            </span>
            <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ t('Send a report') }}</h2>

            <label for="report-title" class="sr-only">{{ t('Title') }}</label>
            <input id="report-title" name="title" required maxlength="80" placeholder="{{ t('Title, e.g. Cold food at lunch') }}" value="{{ old('title') }}" class="{{ $field }}">

            <label for="report-message" class="sr-only">{{ t('What happened?') }}</label>
            <textarea id="report-message" name="message" required maxlength="1000" placeholder="{{ t('What happened? The more detail, the easier it is to fix.') }}"
                      class="min-h-36 w-full rounded-[28px] bg-page px-6 py-4 text-[15px] leading-relaxed placeholder:text-muted-light focus:outline-2 focus:outline-ink">{{ old('message') }}</textarea>

            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label for="report-date" class="mb-1 block px-2 text-[13px] text-muted-light">{{ t('Day of the meal (optional)') }}</label>
                <input id="report-date" name="meal_date" type="date" value="{{ old('meal_date') }}" class="{{ $field }}">
              </div>
              <div>
                <label for="report-meal" class="mb-1 block px-2 text-[13px] text-muted-light">{{ t('Meal (optional)') }}</label>
                <select id="report-meal" name="meal" class="{{ $field }} cursor-pointer appearance-none">
                  <option value="">{{ t('Not about a meal') }}</option>
                  @foreach (['breakfast', 'lunch', 'dinner'] as $m)
                    <option value="{{ $m }}" @selected(old('meal') === $m)>{{ $rules->mealLabel($m) }}</option>
                  @endforeach
                </select>
              </div>
            </div>

            {{-- Picture --}}
            <div data-picture-preview class="relative hidden w-fit">
              <img data-picture-img src="" alt="{{ t('Picture to send') }}" class="max-h-56 w-auto max-w-full rounded-[24px] object-cover">
              <button type="button" data-picture-remove aria-label="{{ t('Remove picture') }}" class="absolute top-2 end-2 flex size-9 items-center justify-center rounded-full bg-ink text-page">
                <x-lucide-x class="size-4" />
              </button>
            </div>
            <label data-picture-label class="flex h-14 w-fit cursor-pointer items-center gap-2 rounded-full bg-page px-6 text-[15px] font-medium transition-transform duration-200 hover:scale-[1.02]">
              <x-lucide-image-plus class="size-4" aria-hidden="true" />
              {{ t('Add a picture') }}
              <input type="file" name="picter" accept="image/*" data-picture-input class="sr-only">
            </label>

            <div class="flex flex-wrap items-center gap-4 pt-2">
              <button type="submit" class="flex h-14 items-center gap-2 rounded-full bg-lime px-7 text-[15px] font-medium text-ink transition-transform duration-200 hover:scale-[1.02]">
                <x-lucide-send class="size-4" aria-hidden="true" />
                {{ t('Send report') }}
              </button>
              <p role="status" data-report-status class="text-[15px] {{ ($status['tone'] ?? '') === 'error' ? 'text-danger' : 'text-ink' }}">{{ $status ? t($status['text']) : '' }}</p>
            </div>
          </form>

          {{-- Past reports --}}
          <section aria-labelledby="my-reports" class="reveal flex flex-col gap-4 p-8 sm:p-12 md:col-span-5" style="--i: 1">
            <h2 id="my-reports" class="text-2xl font-medium tracking-[-0.01em]">{{ t('Your reports') }}</h2>
            @if (count($reports) === 0)
              <x-pill-label>{{ t('No reports yet') }}</x-pill-label>
            @else
              <ul class="flex flex-col gap-3">
                @foreach ($reports as $r)
                  <li class="flex flex-col gap-2 rounded-card bg-page p-5">
                    <div class="flex items-start justify-between gap-3">
                      <p class="font-medium" dir="auto">{{ $r['subject'] }}</p>
                      <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[12px] font-medium {{ $r['reply'] !== '' ? 'bg-booked-ink text-page' : ($r['read'] ? 'bg-cream-deep text-ink' : 'bg-cream text-muted-light') }}">
                        {{ $r['reply'] !== '' ? t('Answered') : ($r['read'] ? t('Seen') : t('Sent')) }}
                      </span>
                    </div>
                    <p class="text-[13px] text-muted-light">{{ $dateTime($r['createdAt']) }}{{ $r['meal'] ? ' · ' . $r['meal'] : '' }}</p>
                    <p class="text-[15px] leading-relaxed whitespace-pre-line text-muted-light" dir="auto">{{ $r['message'] }}</p>
                    @if ($r['picture'])
                      <img src="{{ $r['picture'] }}" alt="" loading="lazy" class="max-h-40 w-fit max-w-full rounded-[16px] object-cover">
                    @endif
                    @if ($r['reply'] !== '')
                      <div class="rounded-[20px] bg-ink p-4 text-[15px] text-page">
                        <p class="text-[12px] text-muted-dark">{{ t('Kitchen team replied') }}</p>
                        <p class="mt-1 leading-relaxed" dir="auto">{{ $r['reply'] }}</p>
                      </div>
                    @endif
                  </li>
                @endforeach
              </ul>
            @endif
          </section>
        </div>
      </x-cream-slab>
    </main>
  </x-page-frame>
</x-layouts.app>
