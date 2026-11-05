{{-- Discipline: where everyone stands on the no-show rule — each dot is a person. --}}
<x-stats.block icon="shield-alert" :title="t('Discipline')" :question="t('Where everyone stands on the no-show rule — each dot is a person; the highlighted step is one no-show from a pause.')" class="xl:col-span-12">
  <div class="grid grid-cols-1 gap-6 @3xl:grid-cols-3">
    <x-stats.panel :title="t('The strike ladder')" class="@3xl:col-span-2">
      <x-stats.ladder :steps="$tiles['steps']" :danger-step="$tiles['maxStrikes'] - 1" />
      <p class="text-[12px] text-mute">{{ t('Hover a dot to see who it is. Strikes reset') }} {{ $tiles['periodDays'] }} {{ t("days after someone's first no-show.") }}</p>
    </x-stats.panel>
    <x-stats.panel :title="t('Paused now')">
      @if ($tiles['paused'])
        <ul class="flex flex-col gap-1 text-[14px]">
          @foreach ($tiles['paused'] as $p)
            <li class="flex justify-between gap-3"><span>{{ $p['name'] }}</span><span class="text-[12px] text-mute">{{ t('until') }} {{ \App\Support\Days::fromKey($p['until'])->translatedFormat('j M') }}</span></li>
          @endforeach
        </ul>
      @else
        <p class="text-[14px] text-mute">{{ t('Nobody is paused.') }}</p>
      @endif
    </x-stats.panel>
  </div>
</x-stats.block>
