@props(['name', 'checked' => false, 'label', 'value' => '1', 'form' => null])
{{-- A tick drawn as a dot (the React CheckDot), as a real checkbox. A hidden 0 is sent when it is off. --}}
<span class="inline-flex">
  <input type="hidden" name="{{ $name }}" value="0" @if($form) form="{{ $form }}" @endif>
  <input type="checkbox" name="{{ $name }}" value="{{ $value }}" id="{{ $attributes->get('id') }}" class="peer sr-only" aria-label="{{ $label }}" @checked($checked) @if($form) form="{{ $form }}" @endif {{ $attributes->except('id') }}>
  <label for="{{ $attributes->get('id') }}" class="inline-flex size-8 cursor-pointer items-center justify-center rounded-full bg-cream-deep text-transparent transition duration-200 hover:bg-[#e1ddcc] peer-checked:bg-ink peer-checked:text-lime peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ink">
    <x-lucide-check class="size-4" aria-hidden="true" />
  </label>
</span>
