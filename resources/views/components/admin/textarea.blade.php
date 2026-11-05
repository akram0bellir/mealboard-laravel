@props(['tone' => 'cream'])
<textarea {{ $attributes->merge(['class' => 'min-h-28 w-full rounded-[20px] px-5 py-4 text-[15px] leading-relaxed text-ink placeholder:text-mute outline-none transition focus-visible:bg-cream-deep focus-visible:ring-2 focus-visible:ring-ink ' . ($tone === 'page' ? 'bg-page' : 'bg-cream')]) }}>{{ $slot }}</textarea>
