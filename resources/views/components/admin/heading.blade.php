@props(['label', 'title', 'description' => null])
<div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
  <div class="reveal max-w-2xl">
    <x-admin.pill dark>{{ $label }}</x-admin.pill>
    <h1 class="mt-4 text-balance text-4xl font-medium tracking-[-0.02em] md:text-[52px] md:leading-[1.05]">{{ $title }}</h1>
    @if ($description)
      <p class="mt-5 max-w-[60ch] text-[17px] leading-relaxed text-mute-dark">{{ $description }}</p>
    @endif
  </div>
  @if (!$slot->isEmpty())
    <div class="reveal flex flex-wrap gap-3">{{ $slot }}</div>
  @endif
</div>
