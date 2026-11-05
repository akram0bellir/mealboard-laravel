@php
    $current = '/' . request()->path();
    $links = function (bool $vertical) use ($items, $current, $unread) {
        return collect($items)->map(function ($item) use ($current, $unread) {
            [$href, $label, $icon] = $item;
            $active = str_starts_with($current, $href);
            return compact('href', 'label', 'icon', 'active') + ['badge' => $href === '/reports' && $unread > 0 ? $unread : 0];
        });
    };
@endphp

@if ($super)
  {{-- Super admin: a sidebar on wide screens (the top bar below is used on phones and tablets). --}}
  <aside class="fixed inset-y-6 start-6 z-20 hidden w-60 flex-col gap-4 overflow-y-auto rounded-[32px] bg-ink p-3 text-page lg:flex print:hidden">
    <a href="/admin" class="flex items-center gap-3 rounded-full py-1 pe-4 ps-1 focus-visible:outline-2 focus-visible:outline-lime">
      <span class="inline-flex size-11 items-center justify-center rounded-full bg-lime text-ink" aria-hidden="true"><x-lucide-utensils class="size-5" /></span>
      <span class="text-[15px] font-medium leading-tight">{{ t('Mealboard') }}<span class="block text-[12px] font-normal text-mute-dark">{{ t('Super admin') }}</span></span>
    </a>
    <nav aria-label="{{ t('Main') }}" class="flex-1">
      <ul class="flex flex-col gap-1">
        @foreach ($links(true) as $l)
          <li>
            <a href="{{ $l['href'] }}" @if($l['active']) aria-current="page" @endif
               class="flex h-11 items-center gap-2 rounded-full px-4 text-[15px] whitespace-nowrap transition duration-200 focus-visible:outline-2 focus-visible:outline-lime {{ $l['active'] ? 'bg-lime text-ink' : 'text-mute-dark hover:bg-ink-3 hover:text-page' }}">
              <x-dynamic-component :component="'lucide-' . $l['icon']" class="size-4" aria-hidden="true" />
              {{ t($l['label']) }}
              @if ($l['badge'])
                <span class="inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-medium {{ $l['active'] ? 'bg-ink text-lime' : 'bg-lime text-ink' }}"><span class="sr-only">{{ t('Unread:') }} </span>{{ $l['badge'] }}</span>
              @endif
            </a>
          </li>
        @endforeach
      </ul>
    </nav>
    <div class="flex flex-col gap-2 border-t border-ink-3 pt-3">
      <p class="truncate px-3 text-[13px] text-mute-dark"><span class="text-page">{{ $name }}</span> · {{ t($role) }}</p>
      <x-language-switcher dark class="w-fit" />
      <form method="POST" action="/logout">
        @csrf
        <button type="submit" class="flex h-11 w-full items-center gap-2 rounded-full bg-ink-3 px-4 text-[14px] font-medium text-page transition duration-200 hover:bg-lime hover:text-ink focus-visible:outline-2 focus-visible:outline-lime">
          <x-lucide-log-out class="size-4" aria-hidden="true" />{{ t('Log out') }}
        </button>
      </form>
    </div>
  </aside>
@endif

<header class="rounded-[32px] bg-ink p-3 text-page print:hidden {{ $super ? 'lg:hidden' : '' }}">
  <div class="flex items-center justify-between gap-4">
    <a href="/admin" class="flex items-center gap-3 rounded-full py-1 pe-4 ps-1 focus-visible:outline-2 focus-visible:outline-lime">
      <span class="inline-flex size-11 items-center justify-center rounded-full bg-lime text-ink" aria-hidden="true"><x-lucide-utensils class="size-5" /></span>
      <span class="text-[15px] font-medium leading-tight">{{ t('Mealboard') }}<span class="block text-[12px] font-normal text-mute-dark">{{ t('Admin') }}</span></span>
    </a>

    <div class="flex shrink-0 items-center gap-2">
      <span class="hidden items-center gap-2 rounded-full bg-ink-3 px-4 py-2 text-[13px] text-mute-dark sm:inline-flex">
        <span class="size-2 rounded-full bg-lime" aria-hidden="true"></span>
        <span class="max-w-40 truncate text-page">{{ $name }}</span>
        {{ t($role) }}
      </span>
      <x-language-switcher dark class="hidden md:flex" />
      <form method="POST" action="/logout">
        @csrf
        <button type="submit" class="flex h-11 items-center gap-2 rounded-full bg-ink-3 px-4 text-[14px] font-medium text-page transition duration-200 hover:bg-lime hover:text-ink focus-visible:outline-2 focus-visible:outline-lime">
          <x-lucide-log-out class="size-4" aria-hidden="true" />{{ t('Log out') }}
        </button>
      </form>
    </div>
  </div>

  <nav aria-label="{{ $super ? t('Main (compact)') : t('Main') }}" class="-mx-1 mt-3 overflow-x-auto px-1 pb-1">
    <ul class="flex items-center gap-1">
      @foreach ($links(false) as $l)
        <li>
          <a href="{{ $l['href'] }}" @if($l['active']) aria-current="page" @endif
             class="flex h-11 items-center gap-2 rounded-full px-4 text-[15px] whitespace-nowrap transition duration-200 focus-visible:outline-2 focus-visible:outline-lime {{ $l['active'] ? 'bg-lime text-ink' : 'text-mute-dark hover:bg-ink-3 hover:text-page' }}">
            <x-dynamic-component :component="'lucide-' . $l['icon']" class="size-4" aria-hidden="true" />
            {{ t($l['label']) }}
            @if ($l['badge'])
              <span class="inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-medium {{ $l['active'] ? 'bg-ink text-lime' : 'bg-lime text-ink' }}"><span class="sr-only">{{ t('Unread:') }} </span>{{ $l['badge'] }}</span>
            @endif
          </a>
        </li>
      @endforeach
    </ul>
  </nav>
</header>
