@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button'])
@php
    $variants = [
        'primary' => 'bg-lime text-ink',
        'dark' => 'bg-ink text-page',
        'soft' => 'bg-cream-deep text-ink hover:bg-[#e1ddcc]',
        'ghost' => 'bg-transparent text-ink hover:bg-cream-deep',
        'ghost-dark' => 'bg-transparent text-page hover:bg-ink-3',
        'soft-dark' => 'bg-ink-3 text-page hover:bg-[#2c2c28]',
        'danger' => 'bg-danger text-page',
    ];
    $sizes = ['lg' => 'h-14 px-7 text-[15px]', 'md' => 'h-11 px-5 text-sm', 'sm' => 'h-9 px-4 text-[13px]', 'icon' => 'size-11', 'icon-sm' => 'size-9'];
    $class = 'inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-full font-medium whitespace-nowrap transition duration-200 hover:scale-[1.02] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime disabled:pointer-events-none disabled:opacity-40 [&_svg]:size-4 [&_svg]:shrink-0 ' . $variants[$variant] . ' ' . $sizes[$size];
@endphp
@if ($href)
  <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</a>
@else
  <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</button>
@endif
