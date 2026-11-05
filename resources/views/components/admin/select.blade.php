@props(['tone' => 'cream'])
<div class="relative">
  <select {{ $attributes->merge(['class' => 'h-12 w-full cursor-pointer appearance-none rounded-full pe-11 ps-5 text-[15px] text-ink outline-none transition focus-visible:bg-cream-deep focus-visible:ring-2 focus-visible:ring-ink disabled:cursor-not-allowed disabled:opacity-60 ' . ($tone === 'page' ? 'bg-page' : 'bg-cream')]) }}>{{ $slot }}</select>
  <x-lucide-chevron-down class="pointer-events-none absolute end-4 top-1/2 size-4 -translate-y-1/2 text-mute" aria-hidden="true" />
</div>
