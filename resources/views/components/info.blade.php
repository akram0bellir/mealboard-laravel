@props(['label', 'value' => ''])
<div class="flex flex-col gap-1">
  <dt class="text-[13px] text-muted-light">{{ t($label) }}</dt>
  <dd class="text-[17px]">{{ $value }}</dd>
</div>
