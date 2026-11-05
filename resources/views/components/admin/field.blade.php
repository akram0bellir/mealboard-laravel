@props(['label', 'for' => null, 'hint' => null, 'error' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }}>
  <label @if($for) for="{{ $for }}" @endif class="px-1 text-[13px] font-medium text-ink">{{ $label }}</label>
  {{ $slot }}
  @if ($error)
    <p class="px-1 text-[13px] font-medium text-danger">{{ $error }}</p>
  @elseif ($hint)
    <p class="px-1 text-[13px] text-mute">{{ $hint }}</p>
  @endif
</div>
