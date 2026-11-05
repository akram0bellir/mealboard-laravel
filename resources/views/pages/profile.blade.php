@php
    $user = auth()->user();
    $name = $user->fullName();
    $initials = collect(explode(' ', $name))->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
    $count = $standing['strikes'];
    $password = session('password_status');
    $field = 'h-14 w-full rounded-full bg-page px-6 text-[15px] placeholder:text-muted-light focus:outline-2 focus:outline-ink';
@endphp
<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      <x-hero-slab>
        <x-top-bar />

        <div class="reveal relative z-10 flex flex-col items-start gap-6 pt-14 sm:pt-20">
          <x-back-link />

          <div class="flex flex-wrap items-center gap-5">
            <span class="flex size-20 items-center justify-center rounded-full bg-lime text-2xl font-medium text-ink">{{ $initials }}</span>
            <div class="flex flex-col gap-1">
              <h1 class="text-4xl font-medium tracking-[-0.02em] sm:text-[56px]">{{ $name }}</h1>
              <p class="text-[17px] text-muted-dark">{{ $user->Department }}</p>
            </div>
          </div>
        </div>
      </x-hero-slab>

      <x-cream-slab :label="t('Profile details')">
        <div class="grid gap-4 md:grid-cols-12">

          {{-- ACCOUNT --}}
          <section aria-labelledby="info-title" class="reveal flex flex-col gap-8 rounded-card bg-cream-deep p-8 sm:p-12 md:col-span-5">
            <h2 id="info-title" class="text-2xl font-medium tracking-[-0.01em]">{{ t('Account') }}</h2>

            <dl class="flex flex-col gap-5">
              <x-info label="Email" :value="(string) $user->email" />
              <x-info label="Account type" :value="t($plan['label'])" />
              <x-info label="Meal plan" :value="t($plan['description'])" />
              @if ($mealTimes !== '')
                <x-info label="Your meal time" :value="$mealTimes" />
              @endif
              <x-info :label="$plan['plan'] === 'intern' ? 'Intern ID' : 'Employee ID'" :value="$user->id_user ? (string) $user->id_user : ''" />
              <x-info label="Department" :value="(string) $user->Department" />
              <x-info label="Canteen" value="" />

              <div class="flex flex-col gap-2">
                <dt class="text-[13px] text-muted-light">{{ t('Language') }}</dt>
                <dd><x-language-switcher class="w-fit" /></dd>
              </div>
            </dl>

            <form method="POST" action="/logout" class="mt-auto self-start">
              @csrf
              <button type="submit" class="flex h-14 items-center justify-center gap-2 rounded-full bg-ink px-7 text-[15px] font-medium text-page transition-transform duration-200 hover:scale-[1.02]">
                <x-lucide-log-out class="size-4" aria-hidden="true" />
                {{ t('Log out') }}
              </button>
            </form>
          </section>

          {{-- NO-SHOW HISTORY --}}
          <section id="no-shows" aria-labelledby="noshow-title" class="reveal flex scroll-mt-6 flex-col gap-8 p-8 sm:p-12 md:col-span-7" style="--i: 1">
            <div class="flex items-start justify-between gap-4">
              <span class="flex size-14 items-center justify-center rounded-full bg-ink">
                <x-lucide-user-round-x class="size-5 text-lime" aria-hidden="true" />
              </span>
              <div class="text-end">
                <p class="text-6xl font-medium tabular-nums tracking-[-0.03em] text-danger">{{ $count > 0 ? '−' . $count : '0' }}</p>
                <p class="text-[13px] text-muted-light">{{ t('{count} of {max} chances used', ['count' => $count, 'max' => $rules->maxNoShow]) }}</p>
              </div>
            </div>

            <div class="flex flex-col gap-3">
              <h2 id="noshow-title" class="text-2xl font-medium tracking-[-0.01em]">{{ t('No-show history') }}</h2>
              <p class="max-w-[52ch] text-[17px] leading-relaxed text-muted-light">
                {{ t("Each booking you didn't pick up lowers your counter by one. Cancel before {deadline} to avoid it. After {max} no-shows, booking is paused until the period ends.", ['deadline' => $rules->deadlineLabel(), 'max' => $rules->maxNoShow]) }}
              </p>
              @if ($blockedUntil !== '')
                <p class="rounded-card bg-danger-soft px-5 py-4 text-[15px] font-medium text-danger">
                  {{ t('Booking is paused until {date}.', ['date' => \App\Support\Days::format($blockedUntil, true)]) }}
                </p>
              @endif
            </div>

            @if (count($noShows))
              <ul class="flex flex-col gap-2">
                @foreach ($noShows as $n)
                  <li class="flex items-center justify-between gap-4 rounded-full bg-page px-5 py-3 text-[15px]">
                    <span>{{ \App\Support\Days::format($n['date'], true) }}</span>
                    <span class="flex items-center gap-2 text-danger">
                      {{ $rules->mealLabel($n['meal']) }}
                      <span class="rounded-full bg-danger-soft px-2.5 py-0.5 text-[13px] font-medium">−1</span>
                    </span>
                  </li>
                @endforeach
              </ul>

              @if ($resetsOn !== '' && $standing['period_start'] !== '')
                <div class="rounded-card bg-cream-deep px-5 py-4">
                  <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-col gap-1">
                      <p class="text-[13px] text-muted-light">{{ t('First missed meal') }}</p>
                      <p class="text-[15px] font-medium">{{ \App\Support\Days::format($standing['period_start'], true) }}</p>
                    </div>
                    <div class="text-end">
                      <p class="text-[13px] text-muted-light">{{ t('No-show resets') }}</p>
                      <p class="text-[15px] font-medium">{{ app()->getLocale() === 'en' ? \App\Support\Days::fromKey($resetsOn)->translatedFormat('F j') : \App\Support\Days::fromKey($resetsOn)->translatedFormat('j F') }}</p>
                    </div>
                  </div>
                  <p class="mt-3 text-[14px] leading-relaxed text-muted-light">
                    {{ t('Your no-show count resets {days} days after your first missed meal.', ['days' => $rules->periodDays]) }}
                  </p>
                </div>
              @endif
            @else
              <x-pill-label>{{ $earlierCount ? t('Fresh start — no missed meals this period.') : t('No missed meals — nice.') }}</x-pill-label>
            @endif
            @if ($earlierCount > 0)
              <p class="text-[13px] text-muted-light">{{ t('{count} earlier no-show(s) from a previous period no longer count.', ['count' => $earlierCount]) }}</p>
            @endif
          </section>

          {{-- PASSWORD --}}
          <section id="password" aria-labelledby="password-title" class="reveal grid scroll-mt-6 gap-8 p-8 sm:p-12 md:col-span-12 md:grid-cols-12" style="--i: 2">
            <div class="flex flex-col gap-4 md:col-span-5">
              <span class="flex size-14 items-center justify-center rounded-full bg-ink">
                <x-lucide-key-round class="size-5 text-lime" aria-hidden="true" />
              </span>
              <h2 id="password-title" class="text-2xl font-medium tracking-[-0.01em]">{{ t('Change password') }}</h2>
              <p class="text-[17px] leading-relaxed text-muted-light">{{ t('At least 8 characters.') }}</p>
            </div>

            <form method="POST" action="/profile/password" class="flex flex-col gap-3 md:col-span-7">
              @csrf
              <label class="sr-only" for="current-password">{{ t('Current password') }}</label>
              <input id="current-password" name="current" type="password" autocomplete="current-password" required placeholder="{{ t('Current password') }}" class="{{ $field }}">

              <label class="sr-only" for="new-password">{{ t('New password') }}</label>
              <input id="new-password" name="password" type="password" autocomplete="new-password" required minlength="8" placeholder="{{ t('New password') }}" class="{{ $field }}">

              <label class="sr-only" for="confirm-password">{{ t('Confirm new password') }}</label>
              <input id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" required placeholder="{{ t('Confirm new password') }}" class="{{ $field }}">

              <div class="flex flex-wrap items-center gap-4 pt-2">
                <button type="submit" class="h-14 rounded-full bg-lime px-7 text-[15px] font-medium text-ink transition-transform duration-200 hover:scale-[1.02]">{{ t('Update password') }}</button>
                @if ($password)
                  <p role="{{ $password['tone'] === 'error' ? 'alert' : 'status' }}" class="text-[15px] {{ $password['tone'] === 'error' ? 'text-danger' : 'text-booked-ink' }}">{{ t($password['text']) }}</p>
                @endif
              </div>
            </form>
          </section>

          @if ($responsible)
            <section id="sandwiches" class="reveal flex scroll-mt-6 flex-col gap-6 rounded-card bg-cream-deep p-8 sm:p-12 md:col-span-12" style="--i: 2">
              <div>
                <a href="/team" class="mb-6 inline-flex h-11 items-center rounded-full bg-ink px-5 text-[15px] font-medium text-page">{{ t('My team: switch meals for tomorrow') }}</a>
                <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ t('Order sandwiches for your team') }}</h2>
                <p class="mt-2 max-w-[58ch] text-[15px] leading-relaxed text-muted-light">
                  {{ t('Choose the day and number of sandwiches. Orders close at {hour}:00 on the selected day.', ['hour' => str_pad((string) $rules->sandwichDeadlineHour, 2, '0', STR_PAD_LEFT)]) }}
                </p>
              </div>
              <form method="POST" action="/profile/sandwiches" class="flex flex-wrap items-end gap-4">
                @csrf
                <label class="flex flex-col gap-2 text-[13px] text-muted-light">
                  {{ t('Date') }}
                  <input class="h-12 rounded-full border border-ink/10 bg-page px-4 text-[15px] text-ink" type="date" name="date" value="{{ \App\Support\Days::today() }}" min="{{ \App\Support\Days::today() }}" required>
                </label>
                <label class="flex flex-col gap-2 text-[13px] text-muted-light">
                  {{ t('Quantity') }}
                  <input class="h-12 w-32 rounded-full border border-ink/10 bg-page px-4 text-[15px] text-ink" type="number" name="quantity" min="1" max="10" step="1" value="1" required>
                </label>
                <button type="submit" class="h-12 rounded-full bg-ink px-6 text-[15px] font-medium text-page">{{ t('Submit order') }}</button>
              </form>
              @if (session('sandwich_status'))
                <p class="text-[14px] text-muted-light">{{ t(session('sandwich_status')) }}</p>
              @endif
            </section>
          @endif

        </div>
      </x-cream-slab>
    </main>
  </x-page-frame>
</x-layouts.app>
