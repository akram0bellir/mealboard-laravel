<section {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-section bg-ink px-6 py-6 text-page sm:px-12 sm:pb-20']) }}>
  <div aria-hidden="true" class="dot-grid pointer-events-none absolute inset-0"></div>
  {{ $slot }}
</section>
