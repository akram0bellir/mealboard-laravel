@props(['icon', 'title', 'question'])
{{-- A statistics tile: icon, title, what the picture says, optional actions. --}}
<x-admin.section tone="cream" {{ $attributes->merge(['class' => '@container md:px-8 md:py-10']) }}>
  <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <h2 class="flex items-center gap-2 text-3xl font-medium tracking-[-0.02em]"><x-dynamic-component :component="'lucide-' . $icon" class="size-6" aria-hidden="true" />{{ $title }}</h2>
      <p class="mt-2 text-[14px] text-mute">{{ $question }}</p>
    </div>
    @isset($actions)<div class="flex flex-wrap gap-2 print:hidden">{{ $actions }}</div>@endisset
  </div>
  <div class="mt-8">{{ $slot }}</div>
</x-admin.section>
