@props(['tone' => 'cream'])
<input {{ $attributes->merge(['class' => 'h-12 w-full rounded-full px-5 text-[15px] text-ink placeholder:text-mute outline-none transition focus-visible:bg-cream-deep focus-visible:ring-2 focus-visible:ring-ink ' . ($tone === 'page' ? 'bg-page' : 'bg-cream')]) }}>
