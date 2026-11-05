{{-- Kitchen quality: the grade (penalties as a share of the bill) and which failures caused them. --}}
@php $q = $tiles['quality']; @endphp
<x-stats.block icon="clipboard-list" :title="t('Kitchen quality')" :question="t('Is the contractor keeping the contract? The letter is the grade; the ring shows which failures caused the penalties.')" class="xl:col-span-5">
  <x-slot:actions>
    <x-admin.btn href="/penalties" size="sm" variant="ghost">{{ t('Record a penalty') }}</x-admin.btn>
  </x-slot:actions>
  <div class="flex flex-col gap-4 rounded-card bg-page p-6">
    <x-stats.grade-ring :grade="$q['grade']['letter'] ?? '—'" :parts="$q['parts']" />
    <p class="text-[13px] text-mute">
      {{ $q['grade'] ? t('{0}: penalties are a {1} share of the bill.', [0 => t($q['grade']['text']), 1 => $q['share'] < 0.01 ? t('tiny') : ($q['share'] < 0.05 ? t('small') : t('large'))]) : t('No meals billed in this period.') }}
      {{ $q['complaints'] ? t('Employees also sent reports in this period — check whether they should have become penalties.') : '' }}
    </p>
    <p class="text-[11px] text-mute">{{ t('A under 1% of the bill · B under 3% · C under 5% · D under 10% · E 10% or more') }}</p>
  </div>
</x-stats.block>
