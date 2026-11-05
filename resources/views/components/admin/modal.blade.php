@props(['title', 'description' => null, 'wide' => false, 'close' => '/'])
{{-- A dialog drawn by the server (no JavaScript): the page behind stays; closing = following the link. --}}
<a href="{{ $close }}" aria-label="{{ t('Close dialog') }}" class="fixed inset-0 z-40 bg-ink/50 backdrop-blur-sm"></a>
<div role="dialog" aria-modal="true" aria-labelledby="modal-title"
     class="fixed left-1/2 top-1/2 z-50 max-h-[calc(100dvh-32px)] w-[calc(100vw-32px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-section bg-page p-0 text-ink {{ $wide ? 'max-w-3xl' : 'max-w-xl' }}">
  <div class="p-6 md:p-10">
    <div class="mb-8 flex items-start justify-between gap-4">
      <div>
        <h2 id="modal-title" class="text-2xl font-medium tracking-[-0.01em]">{{ $title }}</h2>
        @if ($description)<div class="mt-2 text-[15px] leading-relaxed text-mute">{{ $description }}</div>@endif
      </div>
      <x-admin.btn :href="$close" variant="soft" size="icon-sm" aria-label="{{ t('Close dialog') }}"><x-lucide-x /></x-admin.btn>
    </div>
    {{ $slot }}
  </div>
</div>
