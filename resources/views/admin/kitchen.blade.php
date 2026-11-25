@php
    use App\Support\Days;
    $mealNames = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
    $money = fn ($amount) => number_format($amount, 2, '.', ',') . ' ' . $currency;
    $rangeDays = count($daily);
@endphp
<x-layouts.app>
  <x-page-frame>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Kitchen counts')" :description="t('Booked meal portions by day and month, with a custom date and department filter.')">
      <x-slot:title>{{ t('Kitchen') }} <span class="text-lime">{{ t('counts') }}</span></x-slot:title>
      <x-admin.btn size="lg" variant="soft-dark" onclick="window.print()"><x-lucide-printer />{{ t('Print') }}</x-admin.btn>
    </x-admin.heading>

    <form method="GET" action="/kitchen" class="mt-8 flex flex-col gap-3 print:hidden md:flex-row md:flex-wrap md:items-end">
      <div>
        <label for="kitchen-from" class="mb-1 block text-[12px] text-mute-dark">{{ t('From') }}</label>
        <x-admin.input id="kitchen-from" type="date" name="from" required value="{{ $from }}" class="!h-11 !w-auto border border-ink-3 !bg-ink-2 !text-page [color-scheme:dark]" />
      </div>
      <div>
        <label for="kitchen-to" class="mb-1 block text-[12px] text-mute-dark">{{ t('To') }}</label>
        <x-admin.input id="kitchen-to" type="date" name="to" required value="{{ $to }}" class="!h-11 !w-auto border border-ink-3 !bg-ink-2 !text-page [color-scheme:dark]" />
      </div>
      <div>
        <label for="kitchen-meal" class="mb-1 block text-[12px] text-mute-dark">{{ t('Meal') }}</label>
        <x-admin.select id="kitchen-meal" name="meal" class="!h-11 !bg-ink-2 !text-page [color-scheme:dark]">
          <option value="all" @selected($meal === 'all')>{{ t('All meals') }}</option>
          @foreach ($mealNames as $key => $label)<option value="{{ $key }}" @selected($meal === $key)>{{ t($label) }}</option>@endforeach
        </x-admin.select>
      </div>
      <div>
        <label for="kitchen-department" class="mb-1 block text-[12px] text-mute-dark">{{ t('Department') }}</label>
        <x-admin.select id="kitchen-department" name="department" class="!h-11 !bg-ink-2 !text-page [color-scheme:dark]">
          <option value="">{{ t('All departments') }}</option>
          @foreach ($departments as $d)<option value="{{ $d }}" @selected($department === $d)>{{ $d }}</option>@endforeach
        </x-admin.select>
      </div>
      <x-admin.btn type="submit" variant="soft-dark">{{ t('Apply filters') }}</x-admin.btn>
    </form>

    @if ($errors->any())
      <p class="mt-4 rounded-2xl bg-danger/15 px-4 py-3 text-[14px] text-page">{{ $errors->first() }}</p>
    @endif

    <div class="mt-8 grid grid-cols-2 gap-3 lg:grid-cols-5">
      <x-admin.card tone="ink-2">
        <p class="text-[13px] text-mute-dark">{{ t('Total portions') }}</p>
        <p class="mt-2 text-4xl font-medium">{{ number_format($totals['total']) }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ t('{0} days', [0 => $rangeDays]) }}</p>
      </x-admin.card>
      @foreach ($mealNames as $key => $label)
        <x-admin.card tone="ink-2">
          <p class="text-[13px] text-mute-dark">{{ t($label) }}</p>
          <p class="mt-2 text-4xl font-medium">{{ number_format($totals[$key]) }}</p>
        </x-admin.card>
      @endforeach
      <x-admin.card tone="ink-2">
        <p class="text-[13px] text-mute-dark">{{ t('Estimated meal cost') }}</p>
        <p class="mt-2 text-2xl font-medium">{{ $money($totals['cost']) }}</p>
        <p class="mt-1 text-[12px] text-mute-dark">{{ t('Using current meal prices') }}</p>
      </x-admin.card>
    </div>
    <p class="mt-5 text-[12px] text-mute-dark">{{ t('Counts are based on bookings. Manager portions are included when viewing all departments.') }}</p>
  </x-admin.section>

  <x-admin.section tone="cream">
    <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('Monthly totals') }}</h2>
    <div class="mt-5 overflow-x-auto">
      <table class="w-full min-w-[560px] text-start text-[14px]">
        <thead class="border-b border-ink/10 text-[12px] text-mute">
          <tr><th class="py-3 pe-4 text-start">{{ t('Month') }}</th>@foreach ($mealNames as $label)<th class="px-3 py-3 text-end">{{ t($label) }}</th>@endforeach<th class="ps-3 py-3 text-end">{{ t('Total') }}</th></tr>
        </thead>
        <tbody>
          @foreach ($months as $monthKey => $counts)
            <tr class="border-b border-ink/5">
              <th class="py-3 pe-4 text-start font-medium">{{ Days::fromKey($monthKey . '-01')->translatedFormat('F Y') }}</th>
              @foreach ($mealNames as $key => $label)<td class="px-3 py-3 text-end">{{ number_format($counts[$key]) }}</td>@endforeach
              <td class="ps-3 py-3 text-end font-medium">{{ number_format($counts['total']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </x-admin.section>

  <x-admin.section tone="cream">
    <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('Daily portions') }}</h2>
    <div class="mt-5 overflow-x-auto">
      <table class="w-full min-w-[560px] text-start text-[14px]">
        <thead class="border-b border-ink/10 text-[12px] text-mute">
          <tr><th class="py-3 pe-4 text-start">{{ t('Date') }}</th>@foreach ($mealNames as $label)<th class="px-3 py-3 text-end">{{ t($label) }}</th>@endforeach<th class="ps-3 py-3 text-end">{{ t('Total') }}</th></tr>
        </thead>
        <tbody>
          @foreach ($daily as $date => $counts)
            <tr class="border-b border-ink/5">
              <th class="py-3 pe-4 text-start font-medium">{{ Days::fromKey($date)->translatedFormat('D, j M Y') }}</th>
              @php $dayTotal = array_sum($counts); @endphp
              @foreach ($mealNames as $key => $label)<td class="px-3 py-3 text-end">{{ number_format($counts[$key]) }}</td>@endforeach
              <td class="ps-3 py-3 text-end font-medium">{{ number_format($dayTotal) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </x-admin.section>
  </x-page-frame>
</x-layouts.app>
