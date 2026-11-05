@props(['tone' => 'none', 'delay' => 0])
<div {{ $attributes->merge(['class' => 'reveal rounded-card p-6 md:p-8 ' . ['none' => '', 'page' => 'bg-page', 'cream' => 'bg-cream', 'cream-deep' => 'bg-cream-deep', 'ink-2' => 'bg-ink-2'][$tone]]) }} style="animation-delay: {{ $delay * 80 }}ms">
  {{ $slot }}
</div>
