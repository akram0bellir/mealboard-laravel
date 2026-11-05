@props(['label' => null])
<section @if($label) aria-label="{{ $label }}" @endif {{ $attributes->merge(['class' => 'rounded-section bg-cream px-4 py-12 sm:px-12 sm:py-20']) }}>
  {{ $slot }}
</section>
