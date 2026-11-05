<x-layouts.app>
  <x-page-frame>
    <main class="flex flex-col gap-6">
      <x-hero-slab>
        <x-top-bar />
        <div class="relative z-10 flex flex-col gap-6 pt-16 sm:pt-24">
          <x-pill-label dark>{{ auth()->user()->fullName() }} · {{ auth()->user()->role() }}</x-pill-label>
          <h1 class="text-balance text-5xl font-medium leading-[1.02] tracking-[-0.03em] sm:text-[64px]">
            {{ t($title) }}
          </h1>
          <p class="max-w-[48ch] text-[17px] leading-relaxed text-muted-dark">This page is built in the next step.</p>
          <form method="POST" action="/logout">
            @csrf
            <button type="submit" class="h-12 w-fit rounded-full bg-lime px-6 text-[15px] font-medium text-ink transition-transform duration-200 hover:scale-[1.02]">Sign out</button>
          </form>
        </div>
      </x-hero-slab>
    </main>
  </x-page-frame>
</x-layouts.app>
