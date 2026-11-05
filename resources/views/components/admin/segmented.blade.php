@props(['name', 'options', 'value', 'label', 'dark' => false, 'form' => null])
{{-- A row of radio buttons drawn as pills. $options = [value => label]. --}}
<div role="radiogroup" aria-label="{{ $label }}" class="inline-flex flex-wrap gap-1 rounded-full p-1 {{ $dark ? 'bg-ink-3' : 'bg-cream-deep' }}">
  @foreach ($options as $optionValue => $optionLabel)
    <label class="cursor-pointer">
      <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" class="peer sr-only" @checked((string) $value === (string) $optionValue) @if($form) form="{{ $form }}" @endif>
      <span class="flex h-9 items-center rounded-full px-4 text-[13px] font-medium transition duration-200 peer-focus-visible:ring-2 peer-focus-visible:ring-ink {{ $dark ? 'text-mute-dark hover:text-page peer-checked:bg-lime peer-checked:text-ink' : 'text-mute hover:text-ink peer-checked:bg-ink peer-checked:text-page' }}">{{ t($optionLabel) }}</span>
    </label>
  @endforeach
</div>
