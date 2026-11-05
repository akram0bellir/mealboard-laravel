{{-- Shown after signing in with the default password: they choose their own before the app opens. --}}
@php
    $field = 'h-[72px] w-full rounded-full bg-page/10 px-7 text-[17px] text-page placeholder:text-muted-dark focus:bg-page/15 focus:outline-2 focus:outline-lime';
@endphp
<form method="POST" action="/first-password" class="flex flex-col gap-4" aria-describedby="first-password-help">
  @csrf
  <p id="first-password-help" class="px-7 text-[15px] leading-relaxed text-muted-dark">
    {{ t('You signed in with the default password. Choose your own password to continue (at least 8 characters).') }}
  </p>
  <label class="sr-only" for="first-password">{{ t('New password') }}</label>
  <input id="first-password" name="password" type="password" autocomplete="new-password" required minlength="8" autofocus
         placeholder="{{ t('New password') }}" class="{{ $field }}">
  <div class="relative">
    <label class="sr-only" for="first-password-confirm">{{ t('Confirm new password') }}</label>
    <input id="first-password-confirm" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8"
           placeholder="{{ t('Confirm new password') }}" class="{{ $field }} pe-20">
    <button type="submit" aria-label="{{ t('Save password and continue') }}"
            class="absolute end-2 top-1/2 flex size-14 -translate-y-1/2 items-center justify-center rounded-full bg-lime text-ink transition-transform duration-200 hover:scale-[1.02] disabled:opacity-60">
      <x-lucide-arrow-right class="size-5 rtl:rotate-180" />
    </button>
  </div>

  @if ($error)
    <p role="alert" class="px-7 text-[15px] text-danger-bright">{{ t($error) }}</p>
  @endif
</form>
<form method="POST" action="/first-password/cancel" class="mt-4">
  @csrf
  <button type="submit" class="w-fit px-7 text-[15px] text-muted-dark underline-offset-4 transition-colors hover:text-page hover:underline">{{ t('Cancel and sign out') }}</button>
</form>
