{{-- Kitchen plan: the next 7 days — tower height = portions to prepare (as booked, manager and special meals included). --}}
@php $plan = $tiles['plan']; @endphp
<x-stats.block icon="chef-hat" :title="t('Kitchen plan')" :question="t('The next 7 days: tower height = portions to prepare (exactly as booked, never fewer), layers = which meals.')" data-print-section="kitchen" class="xl:col-span-5">
  <x-slot:actions>
    <x-admin.btn type="button" variant="dark" size="sm" data-print-kitchen><x-lucide-printer />{{ t('Print kitchen sheet') }}</x-admin.btn>
  </x-slot:actions>
  <div class="print:hidden"><x-stats.towers :days="$plan" :legend="[t('Breakfast'), t('Lunch'), t('Dinner'), t('Special meals')]" /></div>
  {{-- The printed sheet is for the kitchen, so it keeps the exact counts. --}}
  <table class="hidden w-full text-start text-[14px] print:table">
    <thead><tr><th>{{ t('Day') }}</th><th>{{ t('Breakfast') }}</th><th>{{ t('Lunch') }}</th><th>{{ t('Dinner') }}</th><th>{{ t('Special meals') }}</th><th>{{ t('Total') }}</th></tr></thead>
    <tbody>
      @foreach ($plan as $r)
        <tr><td>{{ $r['label'] }}</td>@foreach ($r['layers'] as $n)<td>{{ $n }}</td>@endforeach<td>{{ $r['extra'] }}</td><td>{{ array_sum($r['layers']) + $r['extra'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
</x-stats.block>
