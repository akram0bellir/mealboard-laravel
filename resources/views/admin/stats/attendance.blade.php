{{-- Attendance & no-shows: week by week, which teams miss which meals, which days are missed. --}}
<x-stats.block icon="user-check" :title="t('Attendance & no-shows')" :question="t('Do people come when they book — and where and when do they miss?')" class="xl:col-span-12">
  <x-slot:actions>
    <x-admin.btn href="/attendance" size="sm" variant="ghost">{{ t('Mark attendance') }}</x-admin.btn>
  </x-slot:actions>
  <div class="grid grid-cols-1 gap-6 @3xl:grid-cols-2">
    <x-stats.panel :title="t('Week by week')" class="@3xl:col-span-2">
      @if ($tiles['weekly'])
        <x-stats.volume-columns :data="$tiles['weekly']" :rate-label="t('attendance')" :target="0.9" />
      @else
        <p class="text-[14px] text-mute">{{ t('No meals yet in the last 8 weeks.') }}</p>
      @endif
    </x-stats.panel>
    <x-stats.panel :title="t('Which teams miss which meals')"><x-stats.circle-grid :grid="$tiles['teamGrid']" :size-label="t('bookings')" :rate-label="t('no-show rate')" /></x-stats.panel>
    <x-stats.panel :title="t('Which days are missed')"><x-stats.circle-grid :grid="$tiles['dayGrid']" :size-label="t('bookings')" :rate-label="t('no-show rate')" /></x-stats.panel>
  </div>
</x-stats.block>
