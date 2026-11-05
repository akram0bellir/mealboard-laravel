@props(['dark' => false])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-full px-4 py-2 text-[13px] font-medium ' . ($dark ? 'bg-ink-3 text-page' : 'bg-cream-deep text-ink')]) }}>{{ $slot }}</span>
