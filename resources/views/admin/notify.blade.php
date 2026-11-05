@php
    $status = session('notify_status');
    $meals = ['breakfast', 'lunch', 'dinner'];
    $deptCounts = collect($departments)->mapWithKeys(fn ($d) => [$d => $users->where('Department', $d)->count()]);
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Notifications')" :description="t('Send a push notification to everyone, a department, the people booked on a specific meal, or one person.')">
      <x-slot:title>{{ t('Tell people') }} <span class="text-lime">{{ t("what's new") }}</span></x-slot:title>
    </x-admin.heading>
  </x-admin.section>

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
    <x-admin.section tone="cream" class="lg:col-span-7">
      <form method="POST" action="/admain_notifications" data-notify-form class="group/form flex flex-col gap-6"
            data-everyone="{{ $users->count() }}" @foreach ($deptCounts as $d => $n) data-dept-{{ $d }}="{{ $n }}" @endforeach
            data-recipient="{{ t('recipient') }}" data-recipients="{{ t('recipients') }}">
        @csrf
        <h2 class="text-3xl font-medium tracking-[-0.02em]">{{ t('New notification') }}</h2>

        <div class="flex flex-col gap-3">
          <p class="px-1 text-[13px] font-medium" id="audience-label">{{ t('Send to') }}</p>
          <x-admin.segmented name="audience" :label="t('Audience')" :value="old('audience', 'everyone')"
                             :options="['everyone' => 'Everyone', 'meal' => 'Meal bookings', 'department' => 'Department', 'user' => 'One user']" />
        </div>

        <div class="hidden grid-cols-1 gap-4 group-has-[[name=audience][value=meal]:checked]/form:grid sm:grid-cols-2">
          <x-admin.field :label="t('Date')" for="n-date">
            <x-admin.input tone="page" id="n-date" type="date" name="meal_date" value="{{ old('meal_date', $today) }}" />
          </x-admin.field>
          <x-admin.field :label="t('Meal')" for="n-meal">
            <x-admin.select tone="page" id="n-meal" name="meal">
              @foreach ($meals as $m)
                <option value="{{ $m }}" @selected(old('meal', 'lunch') === $m)>{{ t(ucfirst($m)) }}</option>
              @endforeach
            </x-admin.select>
          </x-admin.field>
        </div>

        <div class="hidden group-has-[[name=audience][value=department]:checked]/form:block">
          <x-admin.field :label="t('Department')" for="n-dept">
            <x-admin.select tone="page" id="n-dept" name="department">
              @foreach ($departments as $d)<option @selected(old('department') === $d)>{{ $d }}</option>@endforeach
            </x-admin.select>
          </x-admin.field>
        </div>

        <div class="hidden flex-col gap-3 group-has-[[name=audience][value=user]:checked]/form:flex">
          <x-admin.field :label="t('Search user')" for="n-search">
            <x-admin.input tone="page" id="n-search" type="search" data-user-filter placeholder="{{ t('Search name, email, department…') }}" autocomplete="off" />
          </x-admin.field>
          <x-admin.field :label="t('User')" for="n-user">
            <x-admin.select tone="page" id="n-user" name="user" data-user-select>
              @foreach ($users as $u)
                <option value="{{ $u->id }}" data-text="{{ mb_strtolower(trim("{$u->firstname} {$u->lastname} {$u->email} {$u->Department} {$u->Position} {$u->id_user} {$u->card_id}")) }}" @selected(old('user') === $u->id)>{{ trim($u->firstname . ' ' . $u->lastname) ?: $u->email }} · {{ $u->Department }}</option>
              @endforeach
            </x-admin.select>
          </x-admin.field>
        </div>

        <x-admin.field :label="t('Title')" for="n-title">
          <x-admin.input tone="page" id="n-title" name="title" required maxlength="60" value="{{ old('title') }}" placeholder="{{ t('e.g. Dinner menu changed') }}" data-preview-title />
        </x-admin.field>
        <x-admin.field :label="t('Message')" for="n-message">
          <x-admin.textarea tone="page" id="n-message" name="message" required maxlength="240" placeholder="{{ t('Keep it short and clear.') }}" data-preview-message>{{ old('message') }}</x-admin.textarea>
        </x-admin.field>

        <div class="flex flex-wrap items-center justify-between gap-4 rounded-full bg-page py-2 pe-2 ps-5">
          <p class="text-[15px]" data-recipient-line><span class="text-mute">{{ t('Choose who gets it, then send.') }}</span></p>
          <x-admin.btn type="submit" variant="dark"><x-lucide-send />{{ t('Send notification') }}</x-admin.btn>
        </div>
        <p role="status" aria-live="polite" class="min-h-5 text-[13px] text-ink">{{ $status ? t($status) : '' }}</p>
      </form>
    </x-admin.section>

    <div class="flex flex-col gap-6 lg:col-span-5">
      <x-admin.card tone="none" class="rounded-section bg-ink p-6 text-page md:p-8">
        <p class="text-[13px] text-mute-dark">{{ t('Preview') }}</p>
        <div class="mt-4 flex items-start gap-3 rounded-[20px] bg-ink-2 p-4">
          <x-admin.icon-chip icon="bell" size="sm" />
          <div class="min-w-0">
            <p class="text-[15px] font-medium" data-preview-title-out data-empty="{{ t('Notification title') }}">{{ old('title') ?: t('Notification title') }}</p>
            <p class="mt-1 text-[13px] leading-relaxed text-mute-dark" data-preview-message-out data-empty="{{ t('Your message will appear here.') }}">{{ old('message') ?: t('Your message will appear here.') }}</p>
          </div>
        </div>
      </x-admin.card>

      <x-admin.section tone="cream" class="md:px-8 md:py-10">
        <h2 class="text-2xl font-medium tracking-[-0.01em]">{{ t('Sent') }}</h2>
        <ul class="mt-6 flex flex-col gap-3">
          @foreach ($sent as $n)
            <li class="rounded-[20px] bg-page p-4">
              <div class="flex items-center justify-between gap-3 text-[12px] text-mute">
                <span class="truncate">{{ $n['audience'] }} · {{ $n['recipients'] }}</span>
                <time datetime="{{ $n['sentAt']->toIso8601String() }}" class="shrink-0">{{ $n['sentAt']->translatedFormat('j M, H:i') }}</time>
              </div>
              <p class="mt-2 text-[15px] font-medium" dir="auto">{{ t($n['title']) }}</p>
              <p class="mt-1 text-[13px] leading-relaxed text-mute" dir="auto">{{ $n['message'] }}</p>
            </li>
          @endforeach
        </ul>
      </x-admin.section>
    </div>
  </div>
</x-layouts.admin>
