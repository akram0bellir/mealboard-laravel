@php
    use App\Support\Days;
    $icons = ['breakfast' => 'coffee', 'lunch' => 'soup', 'dinner' => 'moon'];
    $labels = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
    $todayMeals = $week[$today] ?? [];
    $managerToday = collect($todayMeals)->map(fn ($m) => $m['manager']);
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="Days::fromKey($today)->translatedFormat('l j F')" :description="t('Bookings, people and the week ahead at a glance.')">
      <x-slot:title>{{ t('Today in the') }} <span class="text-lime">{{ t('kitchen') }}</span></x-slot:title>
      <x-admin.btn href="/meals" size="lg"><x-lucide-calendar-days />{{ t('Create meal') }}</x-admin.btn>
      <x-admin.btn href="/admin?email=1" variant="soft-dark" size="lg"><x-lucide-mail />{{ t('Email orders') }}</x-admin.btn>
      @if ($canUsers)
        <x-admin.btn href="/users?new=1" variant="soft-dark" size="lg"><x-lucide-user-plus />{{ t('Add user') }}</x-admin.btn>
      @endif
      <x-admin.btn href="/reports" variant="ghost-dark" size="lg">
        <x-lucide-message-square-text />{{ t('Open reports') }}
        @if ($unread > 0)<span class="rounded-full bg-lime px-2 text-[12px] text-ink">{{ $unread }}</span>@endif
      </x-admin.btn>
    </x-admin.heading>

    <div class="mt-12 grid grid-cols-1 gap-6 md:grid-cols-12">
      <x-admin.card tone="ink-2" class="flex flex-col justify-between gap-10 md:col-span-5 md:row-span-3" :delay="1">
        <x-admin.icon-chip icon="users" class="ring-1 ring-ink-3" />
        <div>
          <p class="text-[13px] font-medium tracking-wide text-mute-dark uppercase">{{ t('Total users') }}</p>
          <p class="mt-2 text-7xl font-medium tracking-[-0.03em] md:text-8xl">{{ $total }}</p>
          <div class="mt-6 flex h-2 overflow-hidden rounded-full bg-ink-3" aria-hidden="true">
            <div class="h-full rounded-full bg-lime" style="width: {{ ($active / max($total, 1)) * 100 }}%"></div>
          </div>
          <dl class="mt-4 flex gap-8 text-[15px]">
            <div>
              <dt class="text-mute-dark">{{ t('Active') }}</dt>
              <dd class="text-2xl font-medium">{{ $active }}</dd>
            </div>
            <div>
              <dt class="text-mute-dark">{{ t('Inactive') }}</dt>
              <dd class="text-2xl font-medium">{{ $total - $active }}</dd>
            </div>
          </dl>
        </div>
      </x-admin.card>

      @foreach (['breakfast', 'lunch', 'dinner'] as $i => $type)
        @php $meal = $todayMeals[$type] ?? ['name' => null, 'bookings' => 0, 'manager' => 0]; @endphp
        <x-admin.card :tone="$type === 'lunch' ? 'ink-2' : 'none'" :delay="$i + 2" class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between md:col-span-7">
          <div class="flex items-center gap-4">
            <x-admin.icon-chip :icon="$icons[$type]" class="bg-ink-3" />
            <div>
              <p class="text-[13px] font-medium text-mute-dark">{{ t($labels[$type]) }} {{ t('· today') }}</p>
              <p class="mt-1 text-xl font-medium">{{ $meal['name'] ?? t('No meal planned') }}</p>
            </div>
          </div>
          <div class="text-end">
            <p class="text-5xl font-medium tracking-[-0.03em]">
              {{ $meal['bookings'] + $meal['manager'] }}
              <span class="ms-2 text-[15px] font-normal tracking-normal text-mute-dark">{{ t('booked') }}</span>
            </p>
            @if ($meal['manager'])
              <p class="mt-1 text-[13px] text-mute-dark">{{ t('incl. {0} manager meals', [0 => $meal['manager']]) }}</p>
            @endif
          </div>
        </x-admin.card>
      @endforeach
    </div>
  </x-admin.section>

  <x-admin.section tone="cream">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div>
        <x-admin.pill>{{ t('This week') }}</x-admin.pill>
        <h2 class="mt-4 text-3xl font-medium tracking-[-0.02em] md:text-4xl">{{ t('Bookings per day') }}</h2>
      </div>
      <x-admin.btn href="/statistics" variant="dark">{{ t('Full statistics') }}<x-lucide-arrow-up-right /></x-admin.btn>
    </div>

    <div class="mt-10 overflow-x-auto">
      <ul class="grid min-w-[720px] grid-cols-7 gap-3">
        @foreach ($week as $key => $meals)
          @php
              $isToday = $key === $today;
              $managerTotal = collect($meals)->sum('manager');
              $total = collect($meals)->sum(fn ($m) => $m['bookings'] + $m['manager']);
          @endphp
          <li class="reveal rounded-card p-5 {{ $isToday ? 'bg-ink text-page' : '' }}" style="animation-delay: {{ $loop->index * 80 }}ms">
            <p class="text-[13px] font-medium {{ $isToday ? 'text-lime' : 'text-mute' }}">{{ $isToday ? t('Today') : Days::fromKey($key)->translatedFormat('D') }}</p>
            <p class="text-[15px]">{{ Days::fromKey($key)->translatedFormat('j M') }}</p>
            <p class="mt-6 text-4xl font-medium tracking-[-0.03em]">{{ $total }}</p>
            <dl class="mt-4 flex flex-col gap-1.5 text-[13px]">
              @foreach (['breakfast', 'lunch', 'dinner'] as $type)
                <div class="flex justify-between">
                  <dt class="{{ $isToday ? 'text-mute-dark' : 'text-mute' }}">{{ t($labels[$type]) }}</dt>
                  <dd class="font-medium">{{ $meals[$type]['name'] ? $meals[$type]['bookings'] + $meals[$type]['manager'] : '–' }}</dd>
                </div>
              @endforeach
              @if ($managerTotal)
                <div class="flex justify-between border-t border-current/10 pt-1.5">
                  <dt class="{{ $isToday ? 'text-mute-dark' : 'text-mute' }}">{{ t('incl. manager') }}</dt>
                  <dd class="font-medium">{{ $managerTotal }}</dd>
                </div>
              @endif
            </dl>
          </li>
        @endforeach
      </ul>
    </div>
  </x-admin.section>

  @if ($email)
    @php $status = session('email_status'); $count = count(array_filter(preg_split('/[\s,;]+/', $email['recipients']))); @endphp
    <x-admin.modal :title="t('Email the order list')" :description="t('Everyone who booked on that day, as a CSV file (opens in Excel), with the totals per meal in the e-mail.')" close="/admin">
      <form method="POST" action="/admin/orders-email" class="flex flex-col gap-5">
        @csrf
        <x-admin.field :label="t('Day')" for="eo-day">
          <x-admin.input id="eo-day" type="date" name="day" value="{{ $email['day'] }}" required />
        </x-admin.field>
        <x-admin.field :label="t('Send to')" for="eo-to" :hint="$count ? t('{0} address(es) · separate them with commas or new lines', [0 => $count]) : t('One or more e-mail addresses, separated with commas or new lines.')">
          <x-admin.textarea id="eo-to" name="recipients" autocomplete="off" spellcheck="false" placeholder="kitchen@company.com&#10;contractor@catering.com">{{ $email['recipients'] }}</x-admin.textarea>
        </x-admin.field>
        @unless ($email['on'])
          <p class="text-[13px] font-medium text-danger">{{ t('E-mail is switched off: fill in the SMTP settings first (Settings → SMTP).') }}</p>
        @endunless
        <div class="flex flex-wrap items-center gap-4">
          <x-admin.btn type="submit" variant="dark"><x-lucide-send />{{ t('Send {0}', [0 => Days::fromKey($email['day'])->translatedFormat('l j F Y')]) }}</x-admin.btn>
          @if ($status)<p role="status" class="text-[14px] font-medium {{ $status[0] ? '' : 'text-danger' }}">{{ $status[1] }}</p>@endif
        </div>
      </form>
    </x-admin.modal>
  @endif
</x-layouts.admin>
