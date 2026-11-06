@php
    $rules = [
        ['icon' => 'calendar-check', 'title' => 'One meal a day', 'body' => 'Breakfast, lunch or dinner — pick one per day, or book the whole week at once.', 'filled' => true],
        ['icon' => 'clock', 'title' => '14:00 the day before', 'body' => 'Booking and cancelling close at 14:00 on the previous day. After that, the day locks.', 'filled' => false],
        ['icon' => 'user-round-x', 'title' => 'No-shows count', 'body' => 'Booked but didn’t show up? Your counter drops by one. Cancel in time instead.', 'filled' => false],
    ];
    $error = session('login_error');
@endphp
<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      <x-hero-slab class="sm:pt-8">
        <header class="relative z-10 flex items-center justify-between">
          <x-logo />
          <span class="flex items-center gap-3">
            <span class="hidden text-[15px] text-muted-dark sm:inline">{{ t('Employee meal booking') }}</span>
            <x-language-switcher dark />
          </span>
        </header>

        <div class="relative z-10 grid gap-12 pt-16 sm:pt-24 lg:grid-cols-12 lg:items-end">
          <div class="reveal flex flex-col gap-6 lg:col-span-7">
            <x-pill-label dark>{{ t('Accounts are provided by your workplace') }}</x-pill-label>
            <h1 class="text-balance text-5xl font-medium leading-[1.02] tracking-[-0.03em] sm:text-[64px]">
              {{ t('Your meals,') }} <span class="text-lime">{{ t('sorted') }}</span> {{ t('for the week.') }}
            </h1>
            <p class="max-w-[48ch] text-[17px] leading-relaxed text-muted-dark">
              {{ t('Sign in to book breakfast, lunch or dinner for each day — and keep an eye on the deadline.') }}
            </p>
          </div>

          @if ($mustChange)
            <div class="reveal lg:col-span-5" style="--i: 1">
              @include('auth.first-password', ['error' => $error])
            </div>
          @else
            <form method="POST" action="/login" class="reveal flex flex-col gap-4 lg:col-span-5" style="--i: 1"
                  @if($error) aria-describedby="login-error" @endif>
              @csrf
              <label class="sr-only" for="email">{{ t('Email or username') }}</label>
              <input id="email" name="email" type="text" autocapitalize="none" autocorrect="off" spellcheck="false"
                     autocomplete="username" required value="{{ old('email') }}" placeholder="{{ t('Email or username') }}"
                     class="h-[72px] w-full rounded-full bg-page/10 px-7 text-[17px] text-page placeholder:text-muted-dark focus:bg-page/15 focus:outline-2 focus:outline-lime">
              <div class="relative">
                <label class="sr-only" for="password">{{ t('Password') }}</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       placeholder="{{ t('Password') }}"
                       class="h-[72px] w-full rounded-full bg-page/10 ps-7 pe-20 text-[17px] text-page placeholder:text-muted-dark focus:bg-page/15 focus:outline-2 focus:outline-lime">
                <button type="submit" aria-label="{{ t('Sign in') }}"
                        class="absolute end-2 top-1/2 flex size-14 -translate-y-1/2 items-center justify-center rounded-full bg-lime text-ink transition-transform duration-200 hover:scale-[1.02]">
                  <x-lucide-arrow-right class="size-5 rtl:rotate-180" />
                </button>
              </div>

              @if ($error)
                <p id="login-error" role="alert" class="px-7 text-[15px] text-danger-bright">{{ t($error) }}</p>
              @endif
            </form>
          @endif
        </div>
      </x-hero-slab>

      <x-cream-slab :label="t('Booking rules')">
        <div class="flex flex-col gap-4">
          <x-pill-label>{{ t('House rules') }}</x-pill-label>
          <h2 class="max-w-[18ch] text-balance text-4xl font-medium tracking-[-0.02em] sm:text-5xl">
            {{ t('Three rules keep the kitchen honest.') }}
          </h2>
        </div>
        <ul class="mt-12 grid gap-6 sm:mt-20 md:grid-cols-3">
          @foreach ($rules as $i => $rule)
            <li class="reveal flex min-h-64 flex-col justify-between gap-8 rounded-card p-8 sm:p-10 {{ $rule['filled'] ? 'bg-cream-deep' : '' }}"
                style="--i: {{ $i + 2 }}">
              <span class="flex size-14 items-center justify-center rounded-full bg-ink">
                @icon($rule['icon'], 'size-5 text-lime')
              </span>
              <div class="flex flex-col gap-3">
                <h3 class="text-2xl font-medium tracking-[-0.01em]">{{ t($rule['title']) }}</h3>
                <p class="text-[17px] leading-relaxed text-muted-light">{{ t($rule['body']) }}</p>
              </div>
            </li>
          @endforeach
        </ul>
      </x-cream-slab>
    </main>
  </x-page-frame>
</x-layouts.app>
