@props(['icon', 'size' => 'md'])
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center rounded-full bg-ink text-lime ' . ($size === 'md' ? 'size-14' : 'size-10')]) }} aria-hidden="true">
  @icon($icon, $size === 'md' ? 'size-6' : 'size-[18px]')
</span>
