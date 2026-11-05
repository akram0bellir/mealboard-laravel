@props(['count' => 0])
<a href="/profile#no-shows"
   aria-label="{{ t('No-show counter: {count} missed meals', ['count' => $count]) }}"
   {{ $attributes->merge(['class' => 'flex h-10 items-center gap-2 rounded-full bg-ink-3 px-4 text-[13px] font-medium text-page transition-transform duration-200 hover:scale-[1.02]']) }}>
  <span aria-hidden="true" class="size-2 rounded-full {{ $count > 0 ? 'bg-danger-bright' : 'bg-muted-dark' }}"></span>
  <span class="tabular-nums {{ $count > 0 ? 'text-danger-bright' : 'text-page' }}">{{ $count > 0 ? '−' . $count : '0' }}</span>
  <span class="text-muted-dark">{{ t('missed') }}</span>
</a>
