{{-- Menu: dish cards (size = popularity, fill = eaten, ! = complaints). --}}
@php $menu = $tiles['menu']; @endphp
<x-stats.block icon="soup" :title="t('Menu')" :question="t('Bigger card = more popular · fuller bar = more of it actually eaten · ! = complaints. To change the menu later.')" class="xl:col-span-8">
  <x-slot:actions>
    <x-admin.btn href="/meals" size="sm" variant="ghost">{{ t('Open meal planning') }}</x-admin.btn>
  </x-slot:actions>
  @if (!$menu['dishes'])
    <p class="text-[14px] text-mute">{{ t('No dishes with bookings in this period.') }}</p>
  @else
    <div class="flex flex-wrap items-end gap-3">
      @foreach ($menu['dishes'] as $r)
        @php $scale = 0.55 + ($r['perServing'] / $menu['top']) * 0.45; $hot = $menu['weakest'] === $r['key']; @endphp
        <div class="flex flex-col justify-between gap-3 rounded-card bg-page p-4 {{ $hot ? 'ring-2 ring-lime' : '' }}" style="width: {{ round($scale * 15) }}rem; min-height: {{ round($scale * 9) }}rem; max-width: 100%"
             title="{{ t('{0}: {1} bookings each time served · {2}% eaten · {3} complaints', [0 => $r['dish'], 1 => number_format($r['perServing'], 1), 2 => round($r['eaten'] * 100), 3 => $r['complaints']]) }}">
          <div>
            <p class="leading-tight font-medium" style="font-size: {{ round(13 + $scale * 6) }}px">{{ $r['dish'] }}</p>
            <p class="text-[11px] text-mute">{{ t(ucfirst($r['meal'])) }}</p>
          </div>
          <div class="flex flex-col gap-1">
            <span class="h-2 overflow-hidden rounded-full bg-ink/10"><span class="block h-full rounded-full bg-booked-ink" style="width: {{ $r['eaten'] * 100 }}%"></span></span>
            <span class="flex justify-between text-[11px] text-mute"><span>{{ round($r['eaten'] * 100) }}{{ t('% eaten') }}</span>@if ($r['complaints'])<span class="font-medium text-lime">{{ str_repeat('!', min($r['complaints'], 3)) }}</span>@endif</span>
          </div>
        </div>
      @endforeach
    </div>
  @endif
  <p class="mt-4 text-[12px] text-mute">{{ $menu['weakest'] ? t('Outlined: the dish to look at first. ') : '' }}{{ t('A longer period (month or year) gives a fairer picture.') }}</p>
</x-stats.block>
