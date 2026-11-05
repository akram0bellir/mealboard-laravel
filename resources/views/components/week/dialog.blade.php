@props(['dialog'])
{{-- The booking dialog, drawn by the server: the same markup as the React dialog. Closing = back to /week. --}}
@php
    $kind = $dialog['kind'];
    $success = ($dialog['tone'] ?? '') === 'success';
@endphp
<a href="/week" aria-label="{{ t('Close') }}" class="fixed inset-0 z-40 bg-ink/50 backdrop-blur-sm"></a>
<div role="dialog" aria-modal="true" aria-labelledby="dialog-title"
     class="fixed left-1/2 top-1/2 z-50 max-h-[calc(100dvh-32px)] w-[calc(100%-24px)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-section bg-page p-6 text-ink sm:p-10">
  @if ($kind === 'outcome')
    <div class="flex flex-col gap-6">
      <span class="flex size-14 items-center justify-center rounded-full {{ $success ? 'bg-booked-ink text-page' : 'bg-danger-soft text-danger' }}">
        @if ($success)<x-lucide-check class="size-5" />@else<x-lucide-triangle-alert class="size-5" />@endif
      </span>
      <div class="flex flex-col gap-2">
        <h2 id="dialog-title" class="text-2xl font-medium tracking-[-0.01em]">{{ $dialog['title'] }}</h2>
        @if (!empty($dialog['message']))
          <p class="text-[17px] leading-relaxed text-muted-light">{{ $dialog['message'] }}</p>
        @endif
      </div>
      @if (!empty($dialog['done']) || !empty($dialog['skipped']))
        <x-week.day-lists :done="$dialog['done'] ?? []" :skipped="$dialog['skipped'] ?? []" :label="$dialog['doneLabel'] ?? t('Done')" />
      @endif
      <a href="/week" class="flex h-14 items-center justify-center rounded-full bg-lime px-7 text-[15px] font-medium text-ink transition-transform duration-200 hover:scale-[1.02]">{{ t('Done') }}</a>
    </div>
  @else
    <div class="flex flex-col gap-6">
      <div class="flex items-start justify-between gap-4">
        <h2 id="dialog-title" class="text-2xl font-medium tracking-[-0.01em]">{{ $dialog['title'] }}</h2>
        <a href="/week" aria-label="{{ t('Close') }}" class="flex size-10 shrink-0 items-center justify-center rounded-full bg-cream transition-colors duration-200 hover:bg-cream-deep">
          <x-lucide-x class="size-4" />
        </a>
      </div>
      <p class="text-[17px] leading-relaxed text-muted-light">{{ $dialog['body'] }}</p>
      @if (!empty($dialog['lines']))
        <ul class="flex max-h-64 flex-col gap-2 overflow-y-auto rounded-card bg-cream p-5">
          @foreach ($dialog['lines'] as $line)
            <li class="text-[15px] {{ $line['cancel'] ? 'text-danger' : 'text-ink' }}">{{ $line['text'] }}</li>
          @endforeach
        </ul>
      @endif
      <div class="flex flex-col gap-2 sm:flex-row-reverse">
        <form method="POST" action="{{ $dialog['action'] }}" class="flex flex-1">
          @csrf
          @foreach ($dialog['fields'] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
          @endforeach
          <button type="submit" class="h-14 flex-1 rounded-full px-7 text-[15px] font-medium transition-transform duration-200 hover:scale-[1.02] {{ $dialog['danger'] ? 'bg-danger text-page' : 'bg-lime text-ink' }}">{{ $dialog['confirm'] }}</button>
        </form>
        <a href="/week" class="flex h-14 flex-1 items-center justify-center rounded-full bg-cream px-7 text-[15px] font-medium transition-colors duration-200 hover:bg-cream-deep">{{ t('Keep as is') }}</a>
      </div>
    </div>
  @endif
</div>
