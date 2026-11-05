<header class="relative z-10 flex items-center justify-between gap-4">
  <a href="/week" aria-label="{{ t('Plate — weekly view') }}">
    <x-logo />
  </a>

  <nav aria-label="{{ t('Main') }}" class="hidden items-center gap-1 md:flex">
    @foreach ($nav as $item)
      @php $current = '/' . request()->path() === $item['href']; @endphp
      <a href="{{ $item['href'] }}" @if($current) aria-current="page" @endif
         class="rounded-full px-4 py-2 text-[15px] transition-colors duration-200 {{ $current ? 'bg-ink-3 text-page' : 'text-muted-dark hover:text-page' }}">{{ t($item['label']) }}</a>
    @endforeach
  </nav>

  <div class="flex items-center gap-2">
    <x-language-switcher dark class="hidden sm:flex" />
    <x-no-show-chip :count="$strikes" />
    <a href="/notifications"
       aria-label="{{ $unread ? t('Notifications, {count} unread', ['count' => $unread]) : t('Notifications') }}"
       class="relative flex size-10 items-center justify-center rounded-full bg-ink-3 text-page transition-transform duration-200 hover:scale-[1.02]">
      <x-lucide-bell class="size-4" />
      @if ($unread)
        <span aria-hidden="true" class="absolute -top-1 -end-1 flex min-w-5 items-center justify-center rounded-full bg-lime px-1 text-[11px] font-medium text-ink">{{ $unread > 9 ? '9+' : $unread }}</span>
      @endif
    </a>
    <a href="/profile" aria-label="{{ t('Profile') }}"
       class="flex size-10 items-center justify-center rounded-full bg-lime text-ink transition-transform duration-200 hover:scale-[1.02]">
      <x-lucide-user-round class="size-4" />
    </a>
  </div>
</header>
