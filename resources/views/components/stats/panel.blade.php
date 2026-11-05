@props(['title'])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 rounded-card bg-page p-6']) }}>
  <h3 class="text-[15px] font-medium">{{ $title }}</h3>
  {{ $slot }}
</div>
