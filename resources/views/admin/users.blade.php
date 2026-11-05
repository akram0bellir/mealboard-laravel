@php
    use App\Support\Days;
    $cell = 'px-4 py-3 transition-colors duration-200 group-hover:bg-cream-deep';
    $field = fn (string $key, $default = '') => old($key, $default);
    $err = fn (string $key) => $errors[$key] ?? null;
    $dot = 'inline-flex size-8 cursor-pointer items-center justify-center rounded-full transition duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink disabled:cursor-not-allowed disabled:opacity-40';
    $roleLabel = ['employee' => 'Employee', 'responsible' => 'Responsible', 'intern' => 'Intern', 'manager' => 'Manager', 'admin' => 'Admin', 'super_admin' => 'Super admin', 'door' => 'Door'];
@endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    <x-admin.heading :label="t('Users')" :description="t('Create accounts, decide which meals each person may book, and correct strikes when a no-show was a mistake.')">
      <x-slot:title>{{ t('Everyone who can') }} <span class="text-lime">{{ t('book') }}</span></x-slot:title>
      <x-admin.btn href="/users?new=1" size="lg"><x-lucide-user-plus />{{ t('Add new user') }}</x-admin.btn>
      <x-admin.btn href="/users?import=1" size="lg" variant="soft-dark"><x-lucide-upload />{{ t('Import list') }}</x-admin.btn>
    </x-admin.heading>

    <div class="mt-12 grid grid-cols-2 gap-6 md:grid-cols-4">
      <x-admin.card tone="ink-2" :delay="1" class="col-span-2">
        <p class="text-[13px] font-medium tracking-wide text-mute-dark uppercase">{{ t('Total users') }}</p>
        <p class="mt-2 text-7xl font-medium tracking-[-0.03em]">{{ $total }}</p>
      </x-admin.card>
      <x-admin.card :delay="2">
        <p class="text-[13px] text-mute-dark">{{ t('Active') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em] text-lime">{{ $active }}</p>
      </x-admin.card>
      <x-admin.card :delay="3">
        <p class="text-[13px] text-mute-dark">{{ t('Inactive') }}</p>
        <p class="mt-2 text-5xl font-medium tracking-[-0.03em]">{{ $total - $active }}</p>
      </x-admin.card>
    </div>
  </x-admin.section>

  <x-admin.section tone="cream">
    <form method="GET" action="/users" data-autosubmit class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div class="relative w-full md:max-w-sm">
        <label for="user-search" class="sr-only">{{ t('Search users') }}</label>
        <x-lucide-search class="pointer-events-none absolute start-5 top-1/2 size-4 -translate-y-1/2 text-mute" aria-hidden="true" />
        <x-admin.input tone="page" id="user-search" type="search" name="q" value="{{ $q }}" placeholder="{{ t('Search name, email, department…') }}" class="ps-12" />
      </div>
      <div class="flex flex-wrap items-center gap-4">
        <p class="text-[13px] text-mute">{{ $strikes }} {{ t('strikes across all users') }}</p>
        <x-admin.segmented name="status" :label="t('Filter by status')" :value="$status" :options="['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive']" />
        <x-admin.btn type="submit" variant="soft">{{ t('Apply') }}</x-admin.btn>
      </div>
    </form>

    @if ($flash)
      <p role="status" class="mt-6 rounded-full bg-booked px-5 py-3 text-[15px] font-medium text-booked-ink">{{ t($flash) }}</p>
    @endif
    @if ($imported)
      <div role="status" class="mt-6 rounded-card bg-booked p-5 text-[15px] text-booked-ink">
        <p class="font-medium">{{ t('Imported {count} users.', ['count' => $imported['done']]) }}@if (count($imported['skipped'])) · {{ t('{count} skipped', ['count' => count($imported['skipped'])]) }}@endif</p>
        @if (count($imported['skipped']))
          <ul class="mt-2 flex flex-col gap-1 text-[13px]">
            @foreach (array_slice($imported['skipped'], 0, 8) as $s)
              <li>{{ t('Line') }} {{ $s['line'] }}: {{ t($s['reason']) }}</li>
            @endforeach
          </ul>
        @endif
      </div>
    @endif

    <div class="mt-6 overflow-x-auto">
      <table class="w-full min-w-[1100px] border-separate border-spacing-y-1 text-start text-[15px]">
        <caption class="sr-only">{{ t('Users and their meal permissions') }}</caption>
        <thead>
          <tr class="text-[12px] font-medium text-mute">
            <th scope="col" class="px-4 py-3 text-start font-medium">{{ t('Name') }}</th>
            <th scope="col" class="px-4 py-3 text-start font-medium">{{ t('Department') }}</th>
            <th scope="col" class="px-4 py-3 text-start font-medium">{{ t('Position') }}</th>
            <th scope="col" class="py-3 pe-4 ps-10 text-start font-medium">{{ t('Strikes') }}</th>
            <th scope="col" class="px-4 py-3 text-start font-medium">{{ t('Status') }}</th>
            @foreach ($meals as $m => $label)
              <th scope="col" class="px-2 py-3 text-center font-medium">{{ t($label) }}</th>
            @endforeach
            <th scope="col" class="px-2 py-3 text-center font-medium" title="{{ t('Several meals a day') }}">{{ t('Multiple') }}</th>
            <th scope="col" class="px-4 py-3 text-end font-medium"><span class="sr-only">{{ t('Actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $r)
            @php
                $u = $r['u'];
                $active = (int) $u->Status === 1;
                $locked = !$isSuper && $r['protected'];
                $s = $r['standing'];
                $blocked = $s['blocked_until'] !== '' && $s['blocked_until'] > Days::today();
            @endphp
            <tr id="u-{{ $u->id }}" class="group {{ !$active ? 'text-mute' : '' }}">
              <td class="{{ $cell }} rounded-s-full ps-3">
                <div class="flex items-center gap-3">
                  <x-admin.avatar :name="$r['name']" />
                  <div class="min-w-0">
                    <p class="flex items-center gap-2 font-medium text-ink">
                      {{ $r['name'] }}
                      @if ($r['protected'])
                        <span class="rounded-full bg-ink px-2 py-0.5 text-[11px] font-medium text-lime">{{ t($roleLabel[$r['role']]) }}</span>
                      @endif
                    </p>
                    <p class="truncate text-[13px] text-mute">{{ collect([$u->email, $u->username ? '@' . $u->username : ''])->filter()->implode(' · ') }}</p>
                  </div>
                </div>
              </td>
              <td class="{{ $cell }}">
                {{ $u->Department }}
                @if ($r['lunch'] !== '')<span class="block text-[12px] whitespace-nowrap text-mute tabular-nums">{{ t('Lunch') }} {{ $r['lunch'] }}</span>@endif
              </td>
              <td class="{{ $cell }}">{{ $u->Position }}</td>
              <td class="{{ $cell }}">
                <form method="POST" action="/users/{{ $u->id }}/strike" class="flex items-center gap-1">
                  @csrf
                  <button type="submit" name="delta" value="-1" @disabled($s['strikes'] === 0) aria-label="{{ t('Remove a strike from {0}', [0 => $r['name']]) }}" class="inline-flex size-9 cursor-pointer items-center justify-center rounded-full bg-transparent text-ink transition duration-200 hover:scale-[1.02] hover:bg-cream-deep disabled:pointer-events-none disabled:opacity-40"><x-lucide-minus class="size-4" /></button>
                  <span class="inline-flex min-w-9 items-center justify-center rounded-full px-2 py-1 text-[15px] font-medium {{ $s['strikes'] >= $rules->maxNoShow ? 'bg-ink text-lime' : 'text-ink' }}" aria-label="{{ t('{0} strikes', [0 => $s['strikes']]) }}">{{ $s['strikes'] === 0 ? '0' : '-' . $s['strikes'] }}</span>
                  <button type="submit" name="delta" value="1" aria-label="{{ t('Add a strike to {0}', [0 => $r['name']]) }}" class="inline-flex size-9 cursor-pointer items-center justify-center rounded-full bg-transparent text-ink transition duration-200 hover:scale-[1.02] hover:bg-cream-deep"><x-lucide-plus class="size-4" /></button>
                </form>
                @if ($s['strikes'] > 0)
                  <p class="ps-10 mt-1 text-[12px] whitespace-nowrap text-mute">{{ $s['strikes'] }} {{ t('of') }} {{ $rules->maxNoShow }} · {{ $r['periodEnd'] !== '' ? t('resets {0}', [0 => Days::fromKey($r['periodEnd'])->translatedFormat('j M')]) : t('no reset date') }}</p>
                @endif
              </td>
              <td class="{{ $cell }}">
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-[13px] font-medium {{ $active ? 'bg-page text-ink' : 'text-mute' }}">
                  <span class="size-2 rounded-full {{ $active ? 'bg-ink' : 'bg-mute' }}" aria-hidden="true"></span>
                  {{ $active ? t('Active') : t('Inactive') }}
                </span>
                @if ($blocked)
                  <form method="POST" action="/users/{{ $u->id }}/lift" class="mt-1 flex items-center gap-1 text-[12px] whitespace-nowrap text-ink">
                    @csrf
                    {{ t('Blocked until') }} {{ Days::fromKey($s['blocked_until'])->translatedFormat('j M') }}
                    <button type="submit" class="cursor-pointer rounded-full px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-cream-deep">{{ t('Lift') }}</button>
                  </form>
                @endif
              </td>
              @foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'multiple' => 'multiple_meals'] as $meal => $column)
                @php
                    $on = $meal === 'multiple' ? (int) $u->multiple_meals === 1 : (int) $u->{ucfirst($meal)} === 1;
                    $label = $meal === 'multiple' ? t('{0} can book several meals a day', [0 => $r['name']]) : t('{0} can book {1}', [0 => $r['name'], 1 => mb_strtolower(t(ucfirst($meal)))]);
                @endphp
                <td class="{{ $cell }} px-2 text-center">
                  <form method="POST" action="/users/{{ $u->id }}/allow">
                    @csrf
                    <input type="hidden" name="meal" value="{{ $meal }}">
                    <input type="hidden" name="value" value="{{ $on ? 0 : 1 }}">
                    <button type="submit" role="checkbox" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}" class="{{ $dot }} {{ $on ? 'bg-ink text-lime' : 'bg-cream-deep text-transparent hover:bg-[#e1ddcc]' }}"><x-lucide-check class="size-4" aria-hidden="true" /></button>
                  </form>
                </td>
              @endforeach
              <td class="{{ $cell }} rounded-e-full pe-3">
                <div class="flex justify-end gap-1">
                  <x-admin.btn :href="'/users?edit=' . $u->id" size="icon-sm" variant="ghost" aria-label="{{ t('Edit {0}', [0 => $r['name']]) }}"><x-lucide-pencil /></x-admin.btn>
                  @if ($locked || $u->id === $myId)
                    <span class="inline-flex size-9 items-center justify-center rounded-full opacity-40" title="{{ $u->id === $myId ? t("You can't delete your own account.") : t('Only the super admin can delete admins') }}"><x-lucide-trash-2 class="size-4" /></span>
                  @else
                    <x-admin.btn :href="'/users?delete=' . $u->id" size="icon-sm" variant="ghost" aria-label="{{ t('Delete {0}', [0 => $r['name']]) }}"><x-lucide-trash-2 /></x-admin.btn>
                  @endif
                  <form method="POST" action="/users/{{ $u->id }}/active">
                    @csrf
                    <x-admin.btn type="submit" size="sm" :variant="$active ? 'ghost' : 'dark'" :disabled="$locked" aria-label="{{ $active ? t('Switch off {0}', [0 => $r['name']]) : t('Switch on {0}', [0 => $r['name']]) }}"><x-lucide-power /></x-admin.btn>
                  </form>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      @if ($rows->isEmpty())
        <p class="py-16 text-center text-mute">{{ t('No users match your filters.') }}</p>
      @endif
    </div>
  </x-admin.section>

  {{-- ------------------------------------------------------------ dialogs --}}
  @if ($dialog === 'new' || $dialog === 'edit')
    @php
        $isEdit = $dialog === 'edit';
        $e = $edit;
        $ticks = [
            'Breakfast' => $isEdit ? (int) $e->Breakfast : (int) $deptMeals['1']['breakfast'],
            'Lunch' => $isEdit ? (int) $e->Lunch : (int) $deptMeals['1']['lunch'],
            'Dinner' => $isEdit ? (int) $e->Dinner : (int) $deptMeals['1']['dinner'],
        ];
        $isOn = fn (string $key, int $default) => (bool) (int) old($key, $default);
        $currentRole = $isEdit ? \App\Support\Roles::normalize($e->User_Type) : 'employee';
    @endphp
    <x-admin.modal :title="$isEdit ? t('Edit user') : t('Add new user')" :description="$isEdit ? t('Update details and meal access.') : t('New users start with 0 strikes and the default password.')" close="/users">
      <form method="POST" action="{{ $isEdit ? '/users/' . $e->id : '/users' }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-5 sm:grid-cols-2"
            @unless($isEdit) data-dept-meals='@json($deptMeals)' @endunless>
        @csrf
        <x-admin.field :label="t('First name')" for="f-first" :error="$err('firstname') ? t($err('firstname')) : null">
          <x-admin.input id="f-first" name="firstname" value="{{ $field('firstname', $e->firstname ?? '') }}" :required="!$isEdit" autofocus />
        </x-admin.field>
        <x-admin.field :label="t('Last name')" for="f-last" :error="$err('lastname') ? t($err('lastname')) : null">
          <x-admin.input id="f-last" name="lastname" value="{{ $field('lastname', $e->lastname ?? '') }}" :required="!$isEdit" />
        </x-admin.field>
        <x-admin.field :label="t('Email')" for="f-email" class="sm:col-span-2" :error="$err('email') ? t($err('email')) : null">
          <x-admin.input id="f-email" type="text" inputmode="email" name="email" value="{{ $field('email', $e->email ?? '') }}" placeholder="{{ t('name@company.com') }}" />
        </x-admin.field>
        <x-admin.field :label="t('Username (optional)')" for="f-username" class="sm:col-span-2"
                       :error="$err('username') ? t($err('username')) : null" :hint="t('They can sign in with this or their email.')">
          <x-admin.input id="f-username" name="username" value="{{ $field('username', $e->username ?? '') }}" placeholder="{{ t('e.g. ahmed.b') }}" autocomplete="off" />
        </x-admin.field>
        <x-admin.field :label="t('Department')" for="f-dept" :error="$err('department') ? t($err('department')) : null">
          <x-admin.select id="f-dept" name="department" data-dept-select>
            @foreach ($departments as $d)<option @selected($field('department', $e->Department ?? $departments[0]) === $d)>{{ $d }}</option>@endforeach
          </x-admin.select>
        </x-admin.field>
        <x-admin.field :label="t('Position')" for="f-position" :error="$err('position') ? t($err('position')) : null">
          <x-admin.input id="f-position" name="position" value="{{ $field('position', $e->Position ?? '') }}" placeholder="{{ t('e.g. Accountant') }}" :required="!$isEdit" />
        </x-admin.field>

        <x-admin.field :label="t('User Type')" for="f-role" :hint="$isSuper ? null : t('Only the super admin can make someone an admin.')">
          <x-admin.select id="f-role" name="User_Type" :disabled="!$isSuper">
            @foreach ($roles as $role)<option value="{{ $role }}" @selected($field('User_Type', $currentRole) === $role)>{{ t($roleLabel[$role]) }}</option>@endforeach
          </x-admin.select>
        </x-admin.field>
        <x-admin.field :label="t('Sex')" for="f-sex">
          <x-admin.select id="f-sex" name="sex">
            <option value="male" @selected($field('sex', ($e->sex ?? 0) ? 'female' : 'male') === 'male')>{{ t('Male') }}</option>
            <option value="female" @selected($field('sex', ($e->sex ?? 0) ? 'female' : 'male') === 'female')>{{ t('Female') }}</option>
          </x-admin.select>
        </x-admin.field>

        <x-admin.field :label="t('Card ID')" for="f-card">
          <x-admin.input id="f-card" type="number" name="card_id" value="{{ $field('card_id', $e->card_id ?? '') }}" placeholder="e.g. 10452" />
        </x-admin.field>
        <x-admin.field :label="t('User ID')" for="f-idu">
          <x-admin.input id="f-idu" type="number" name="id_user" value="{{ $field('id_user', $e->id_user ?? '') }}" placeholder="e.g. 2087" />
        </x-admin.field>

        <x-admin.field :label="$isEdit ? t('New password') : t('Password')" for="f-pw" :error="$err('password') ? t($err('password')) : null"
                       :hint="$isEdit ? t('Leave blank to keep the current password.') : t('Leave blank for the default password.')">
          <x-admin.input id="f-pw" type="password" name="password" autocomplete="new-password" />
        </x-admin.field>
        <x-admin.field :label="t('Confirm password')" for="f-pw2">
          <x-admin.input id="f-pw2" type="password" name="password_confirmation" autocomplete="new-password" />
        </x-admin.field>

        <div class="sm:col-span-2">
          <p class="mb-3 px-1 text-[13px] font-medium">{{ t('Picture') }}</p>
          <label class="flex w-fit cursor-pointer items-center gap-2 rounded-full bg-cream px-5 py-3 text-sm font-medium transition hover:bg-cream-deep">
            <x-lucide-upload class="size-4" aria-hidden="true" />
            {{ $isEdit && $e->picter ? t('Replace {0}', [0 => $e->picter]) : t('Upload a picture') }}
            <input type="file" name="picter" accept="image/*" class="sr-only">
          </label>
          @if ($err('picter'))<p class="mt-2 px-1 text-[13px] font-medium text-danger">{{ $err('picter') }}</p>@endif
        </div>

        <fieldset class="sm:col-span-2">
          <legend class="mb-3 px-1 text-[13px] font-medium">{{ t('Allowed to book') }}</legend>
          @unless ($isEdit)
            <p class="-mt-2 mb-3 px-1 text-[13px] text-mute" data-dept-note>{{ t("Filled from Department {0}'s rules — change them for a special case.", [0 => '1']) }}</p>
          @endunless
          <div class="flex flex-wrap gap-2">
            @foreach (['Breakfast' => 'Breakfast', 'Lunch' => 'Lunch', 'Dinner' => 'Dinner'] as $column => $label)
              <label class="flex items-center gap-3 rounded-full bg-cream py-1.5 pe-5 ps-1.5 text-[15px]">
                <x-admin.check id="f-{{ $column }}" :name="$column" :label="t($label)" :checked="$isOn($column, $ticks[$column])" data-meal-tick="{{ strtolower($column) }}" />
                {{ t($label) }}
              </label>
            @endforeach
          </div>
          <label class="mt-3 flex w-fit items-center gap-3 rounded-full bg-cream py-1.5 pe-5 ps-1.5 text-[15px]">
            <x-admin.check id="f-multi" name="multiple_meals" :label="t('Several meals a day')" :checked="$isOn('multiple_meals', $isEdit ? (int) $e->multiple_meals : 0)" />
            {{ t('Several meals a day') }}
          </label>
          <p class="mt-2 px-1 text-[13px] text-mute">{{ t('On: this person can book all the meals ticked above on the same day (e.g. lunch and dinner). Off: one meal a day.') }}</p>
        </fieldset>

        @if ($isSuper && $groups->count())
          <fieldset class="grid grid-cols-1 gap-3 sm:col-span-2 sm:grid-cols-3">
            <legend class="mb-3 px-1 text-[13px] font-medium">{{ t('Meal time') }}</legend>
            @foreach (['breakfast', 'lunch', 'dinner'] as $meal)
              @php $ofMeal = $groups->filter(fn ($g) => \App\Services\MealTimes::mealOf($g->meal) === $meal); @endphp
              @continue($ofMeal->isEmpty())
              <x-admin.field :label="t(ucfirst($meal))" for="f-g-{{ $meal }}">
                <x-admin.select id="f-g-{{ $meal }}" name="{{ $meal }}_group">
                  <option value="">{{ t("Department's") }}{{ t(' · normal time') }}</option>
                  @foreach ($ofMeal as $g)
                    <option value="{{ $g->id }}" @selected($field("{$meal}_group", $e->{"{$meal}_group"} ?? '') === $g->id)>{{ $g->name }} · {{ $g->start }}–{{ $g->end }}</option>
                  @endforeach
                </x-admin.select>
              </x-admin.field>
            @endforeach
          </fieldset>
        @endif

        <fieldset class="sm:col-span-2">
          <legend class="mb-3 px-1 text-[13px] font-medium">{{ t('Account') }}</legend>
          <div class="flex flex-wrap gap-2">
            <label class="flex items-center gap-3 rounded-full bg-cream py-1.5 pe-5 ps-1.5 text-[15px]">
              <x-admin.check id="f-status" name="Status" :label="t('Active')" :checked="$isOn('Status', $isEdit ? (int) $e->Status : 1)" />{{ t('Active') }}
            </label>
            <label class="flex items-center gap-3 rounded-full bg-cream py-1.5 pe-5 ps-1.5 text-[15px]">
              <x-admin.check id="f-vis" name="emailVisibility" :label="t('Email visible')" :checked="$isOn('emailVisibility', $isEdit ? (int) $e->emailVisibility : 1)" />{{ t('Email visible') }}
            </label>
          </div>
        </fieldset>

        <div class="flex justify-end gap-3 pt-2 sm:col-span-2">
          <x-admin.btn href="/users" variant="soft">{{ t('Cancel') }}</x-admin.btn>
          <x-admin.btn type="submit" variant="dark">{{ $isEdit ? t('Save changes') : t('Create user') }}</x-admin.btn>
        </div>
      </form>
    </x-admin.modal>
  @elseif ($dialog === 'import')
    <x-admin.modal :title="t('Import users from a list')" wide close="/users"
                   :description="t('One user per line: first, last, email, department, position. Everyone gets the default password, 0 strikes and their department\'s default meals.')">
      <form method="POST" action="/users/import" enctype="multipart/form-data" class="flex flex-col gap-5">
        @csrf
        <x-admin.field :label="t('Paste list')" for="i-list">
          <x-admin.textarea id="i-list" name="list" class="min-h-44 font-mono text-[13px]" placeholder="Nadia, Sabbagh, nadia.sabbagh@company.com, 1, Analyst&#10;Jad, Haddad, jad.haddad@company.com, 2, Intern"></x-admin.textarea>
        </x-admin.field>
        <label class="flex w-fit cursor-pointer items-center gap-2 rounded-full bg-cream px-5 py-3 text-sm font-medium transition hover:bg-cream-deep">
          <x-lucide-upload class="size-4" aria-hidden="true" />{{ t('Or upload a .csv / .txt file') }}
          <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" class="sr-only">
        </label>
        <div class="flex justify-end gap-3">
          <x-admin.btn href="/users" variant="soft">{{ t('Cancel') }}</x-admin.btn>
          <x-admin.btn type="submit" variant="dark">{{ t('Import') }}</x-admin.btn>
        </div>
      </form>
    </x-admin.modal>
  @elseif ($dialog === 'delete')
    <x-admin.modal :title="t('Delete {0}?', [0 => $edit->firstname . ' ' . $edit->lastname])" :description="t('This can\'t be undone.')" close="/users">
      <form method="POST" action="/users/{{ $edit->id }}/delete" class="flex justify-end gap-3">
        @csrf
        <x-admin.btn href="/users" variant="soft">{{ t('Cancel') }}</x-admin.btn>
        <x-admin.btn type="submit" variant="danger">{{ t('Delete') }}</x-admin.btn>
      </form>
    </x-admin.modal>
  @endif
</x-layouts.admin>
