<div class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 p-3 backdrop-blur-sm sm:items-center" role="dialog" aria-modal="true" aria-labelledby="notif-popup-title">
  <div class="w-full max-w-md rounded-section bg-page p-6 text-ink shadow-xl sm:p-8">
    <div class="flex items-start justify-between gap-4">
      <span class="flex size-12 items-center justify-center rounded-full bg-ink">
        <x-lucide-bell class="size-5 text-lime" aria-hidden="true" />
      </span>
      <form method="POST" action="/notifications/read">
        @csrf
        <button type="submit" aria-label="{{ t('Close') }}" class="flex size-10 items-center justify-center rounded-full bg-cream transition-colors hover:bg-cream-deep">
          <x-lucide-x class="size-4" />
        </button>
      </form>
    </div>
    <h2 id="notif-popup-title" class="mt-4 text-2xl font-medium tracking-[-0.01em]">
      {{ $unread === 1 ? t('You have a new notification') : t('You have {count} new notifications', ['count' => $unread]) }}
    </h2>
    <ul class="mt-4 flex max-h-[45vh] flex-col gap-2 overflow-y-auto">
      @foreach ($shown as $n)
        <li class="rounded-card bg-cream p-4">
          <p class="text-[15px] font-medium" dir="auto">{{ $n['title'] }}</p>
          <p class="mt-1 text-[14px] leading-relaxed text-muted-light" dir="auto">{{ $n['message'] }}</p>
        </li>
      @endforeach
    </ul>
    @if ($more > 0)
      <p class="mt-2 text-[13px] text-muted-light">{{ t('+ {count} more', ['count' => $more]) }}</p>
    @endif
    <div class="mt-6 flex flex-col gap-2 sm:flex-row">
      <form method="POST" action="/notifications/read" class="flex flex-1">
        @csrf
        <button type="submit" class="h-12 flex-1 rounded-full bg-lime px-6 text-[15px] font-medium text-ink transition-transform hover:scale-[1.02]">{{ t('Got it') }}</button>
      </form>
      <a href="/notifications" class="flex h-12 flex-1 items-center justify-center rounded-full bg-cream px-6 text-[15px] font-medium transition-colors hover:bg-cream-deep">{{ t('See all notifications') }}</a>
    </div>
  </div>
</div>
