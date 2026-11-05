@props(['tone' => 'cream'])
<section {{ $attributes->merge(['class' => 'rounded-section px-5 py-10 md:px-12 md:py-14 ' . ['ink' => 'bg-ink text-page', 'cream' => 'bg-cream text-ink', 'none' => 'bg-transparent text-ink'][$tone]]) }}>
  {{ $slot }}
</section>
