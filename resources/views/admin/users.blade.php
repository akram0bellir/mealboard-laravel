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
    <form id="users-filter" method="GET" action="/users" data-autosubmit class="flex flex-col gap-3">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div class="relative w-full md:max-w-sm">
          <label for="user-search" class="sr-only">{{ t('Search users') }}</label>
          <x-lucide-search class="pointer-events-none absolute start-5 top-1/2 size-4 -translate-y-1/2 text-mute" aria-hidden="true" />
          <x-admin.input tone="page" id="user-search" type="search" name="q" value="{{ $q }}" placeholder="{{ t('Search name, email, username, department, position…') }}" class="ps-12" />
        </div>
        <p class="text-[13px] text-mute">{{ $strikes }} {{ t('strikes across all users') }}</p>
      </div>
      <p class="flex flex-wrap items-center gap-3 px-1 text-[14px] text-mute">
        <span>{{ t('Showing {0} of {1} users', [0 => $matches, 1 => $total]) }}</span>
        <span>· {{ t('click a column title to sort or filter') }}</span>
        @if ($q !== '' || $status !== 'all' || $advanced > 0)
          <a href="/users" class="font-medium text-ink underline underline-offset-4">{{ t('Clear all filters') }}</a>
        @endif
        <label class="ms-auto flex items-center gap-2">{{ t('Rows per page') }}
          <select name="per" class="rounded-full bg-page px-3 py-1 text-[13px] text-ink">
            @foreach (['25' => '25', '50' => '50', '100' => '100', 'all' => t('All')] as $v => $label)<option value="{{ $v }}" @selected($filters['per'] === $v)>{{ $label }}</option>@endforeach
          </select>
        </label>
      </p>
      @php
          $chips = [];
          $drop = fn (array $keys) => request()->fullUrlWithoutQuery(array_merge($keys, ['page']));
          $yn = fn (string $v) => $v === 'yes' ? t('Yes') : t('No');
          if ($q !== '') { $chips[] = [t('Search') . ': ' . $q, $drop(['q'])]; }
          if ($status !== 'all') { $chips[] = [t('Status') . ': ' . t(ucfirst($status)), $drop(['status'])]; }
          foreach ($filters['department'] as $dv) {
              $chips[] = [t('Department') . ': ' . ($dv === '__none__' ? t('(No department)') : $dv), request()->fullUrlWithQuery(['department' => array_values(array_diff($filters['department'], [$dv])), 'page' => null])];
          }
          if ($filters['position_q'] !== '') { $chips[] = [t('Position') . ': ' . $filters['position_q'], $drop(['position_q'])]; }
          if ($filters['role'] !== '') { $chips[] = [t('Role') . ': ' . t($roleLabel[$filters['role']] ?? $filters['role']), $drop(['role'])]; }
          $strikeLabel = ['any' => t('Has strikes'), 'clean' => t('No strikes'), 'blocked' => t('Blocked now')];
          if ($filters['strikes'] !== '') { $chips[] = [t('Strikes') . ': ' . $strikeLabel[$filters['strikes']], $drop(['strikes'])]; }
          if ($filters['card'] !== '') { $chips[] = [t('Card ID') . ': ' . ($filters['card'] === 'with' ? t('Has a card') : t('No card')), $drop(['card'])]; }
          if ($filters['empid'] !== '') { $chips[] = [t('Employee ID') . ': ' . ($filters['empid'] === 'with' ? t('Has an ID') : t('No ID')), $drop(['empid'])]; }
          foreach ($meals as $m => $label) {
              if ($filters[$m] !== '') { $chips[] = [t($label) . ': ' . $yn($filters[$m]), $drop([$m])]; }
          }
          if ($filters['multiple'] !== '') { $chips[] = [t('Multiple') . ': ' . $yn($filters['multiple']), $drop(['multiple'])]; }
      @endphp
      @if ($chips)
        <div class="flex flex-wrap gap-2 px-1">
          @foreach ($chips as [$text, $url])
            <a href="{{ $url }}" class="chip" title="{{ t('Remove this filter') }}">{{ $text }} <span aria-hidden="true">×</span></a>
          @endforeach
        </div>
      @endif
    </form>

    @if ($flash)
      <p role="status" class="mt-6 rounded-full bg-booked px-5 py-3 text-[15px] font-medium text-booked-ink">{{ t($flash) }}</p>
    @endif
    @if ($imported)
      <div role="status" class="mt-6 rounded-card bg-booked p-5 text-[15px] text-booked-ink">
        <p class="font-medium">{{ t('Imported {count} users.', ['count' => $imported['done']]) }}@if (count($imported['skipped'])) · {{ t('{count} skipped', ['count' => count($imported['skipped'])]) }}@endif</p>
        @if (!empty($imported['newDepartments']))<p class="mt-1 text-[13px]">{{ t('{count} new departments were added to the list.', ['count' => $imported['newDepartments']]) }}</p>@endif
        @if (!empty($imported['fixedUsernames']))<p class="mt-1 text-[13px]">{{ t('{count} usernames had spaces or lost characters removed.', ['count' => $imported['fixedUsernames']]) }}</p>@endif
        @if (count($imported['skipped']))
          <ul class="mt-2 flex flex-col gap-1 text-[13px]">
            @foreach (array_slice($imported['skipped'], 0, 30) as $s)
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
          @php
              $sortNow = $filters['sort'];
              $arrow = fn (string $asc, string $desc) => $sortNow === $asc ? ' ↑' : ($sortNow === $desc ? ' ↓' : '');
              $radio = fn (string $name, string $value, string $label, bool $checked) => '<label class="opt"><input type="radio" form="users-filter" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . ' data-quick> ' . e($label) . '</label>';
              $sortRadios = fn (string $asc, string $desc, string $ascLabel, string $descLabel) => $radio('sort', $asc, $ascLabel, $sortNow === $asc) . $radio('sort', $desc, $descLabel, $sortNow === $desc);
              $yesNo = fn (string $key) => $radio($key, '', t('All'), $filters[$key] === '') . $radio($key, 'yes', t('Yes'), $filters[$key] === 'yes') . $radio($key, 'no', t('No'), $filters[$key] === 'no');
              $foot = '<div class="colfoot"><button type="button" data-clear>' . e(t('Clear')) . '</button><button type="submit" form="users-filter" class="go">' . e(t('Apply')) . '</button></div>';
              $colBtn = fn (string $key, string $label, bool $on, string $mark = '') => '<button type="button" class="colbtn' . ($on ? ' on' : '') . '" data-col="' . e($key) . '" aria-haspopup="true" aria-expanded="false">' . e($label) . e($mark) . ($on ? '<i class="coldot"></i>' : '') . '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>';
              $anyAccount = $filters['role'] !== '' || $filters['card'] !== '' || $filters['empid'] !== '' || in_array($filters['sort'], ['name_desc', 'newest'], true);
          @endphp
          <tr class="text-[12px] font-medium text-mute">
            <th scope="col" class="px-4 py-3 text-start font-medium">
              {!! $colBtn('name', t('Name'), $anyAccount, $arrow('name', 'name_desc')) !!}
              <div class="colpop" data-pop="name" hidden>
                {!! $sortRadios('name', 'name_desc', t('Sort A → Z'), t('Sort Z → A')) !!}
                {!! $radio('sort', 'newest', t('Newest first'), $sortNow === 'newest') !!}
                <h4>{{ t('Role') }}</h4>
                <select form="users-filter" name="role" class="sel"><option value="">{{ t('Any role') }}</option>@foreach ($roles as $ro)<option value="{{ $ro }}" @selected($filters['role'] === $ro)>{{ t($roleLabel[$ro] ?? $ro) }}</option>@endforeach</select>
                <h4>{{ t('Card ID') }}</h4>
                <select form="users-filter" name="card" class="sel"><option value="">{{ t('Any') }}</option><option value="with" @selected($filters['card'] === 'with')>{{ t('Has a card') }}</option><option value="without" @selected($filters['card'] === 'without')>{{ t('No card') }}</option></select>
                <h4>{{ t('Employee ID') }}</h4>
                <select form="users-filter" name="empid" class="sel"><option value="">{{ t('Any') }}</option><option value="with" @selected($filters['empid'] === 'with')>{{ t('Has an ID') }}</option><option value="without" @selected($filters['empid'] === 'without')>{{ t('No ID') }}</option></select>
                {!! $foot !!}
              </div>
            </th>
            <th scope="col" class="px-4 py-3 text-start font-medium">
              {!! $colBtn('department', t('Department'), $filters['department'] !== [], $arrow('department', 'department_desc')) !!}
              <div class="colpop" data-pop="department" hidden>
                {!! $sortRadios('department', 'department_desc', t('Sort A → Z'), t('Sort Z → A')) !!}
                <input type="search" class="find" data-find placeholder="{{ t('Search') }}">
                <div class="listbox" data-list>
                  <label class="opt"><input type="checkbox" form="users-filter" name="department[]" value="__none__" @checked(in_array('__none__', $filters['department'], true))> <em>{{ t('(No department)') }}</em></label>
                  @foreach ($departments as $d)
                    <label class="opt"><input type="checkbox" form="users-filter" name="department[]" value="{{ $d }}" @checked(in_array($d, $filters['department'], true))> <span>{{ $d }}</span></label>
                  @endforeach
                </div>
                <div class="links"><button type="button" data-all>{{ t('Select all') }}</button><button type="button" data-none>{{ t('Select none') }}</button></div>
                {!! $foot !!}
              </div>
            </th>
            <th scope="col" class="px-4 py-3 text-start font-medium">
              {!! $colBtn('position', t('Position'), $filters['position_q'] !== '', $arrow('position', 'position_desc')) !!}
              <div class="colpop" data-pop="position" hidden>
                {!! $sortRadios('position', 'position_desc', t('Sort A → Z'), t('Sort Z → A')) !!}
                <h4>{{ t('Contains') }}</h4>
                <input type="text" form="users-filter" name="position_q" value="{{ $filters['position_q'] }}" class="find" placeholder="{{ t('e.g. Operator') }}">
                {!! $foot !!}
              </div>
            </th>
            <th scope="col" class="py-3 pe-4 ps-10 text-start font-medium">
              {!! $colBtn('strikes', t('Strikes'), $filters['strikes'] !== '', $arrow('strikes', 'strikes_asc')) !!}
              <div class="colpop" data-pop="strikes" hidden>
                {!! $sortRadios('strikes', 'strikes_asc', t('Most strikes first'), t('Fewest strikes first')) !!}
                <h4>{{ t('Show') }}</h4>
                {!! $radio('strikes', '', t('All'), $filters['strikes'] === '') !!}
                {!! $radio('strikes', 'any', t('Has strikes'), $filters['strikes'] === 'any') !!}
                {!! $radio('strikes', 'clean', t('No strikes'), $filters['strikes'] === 'clean') !!}
                {!! $radio('strikes', 'blocked', t('Blocked now'), $filters['strikes'] === 'blocked') !!}
              </div>
            </th>
            <th scope="col" class="px-4 py-3 text-start font-medium">
              {!! $colBtn('status', t('Status'), $status !== 'all') !!}
              <div class="colpop" data-pop="status" hidden>
                {!! $radio('status', 'all', t('All'), $status === 'all') !!}
                {!! $radio('status', 'active', t('Active'), $status === 'active') !!}
                {!! $radio('status', 'inactive', t('Inactive'), $status === 'inactive') !!}
              </div>
            </th>
            @foreach ($meals as $m => $label)
              <th scope="col" class="px-2 py-3 text-center font-medium">
                {!! $colBtn($m, t($label), $filters[$m] !== '') !!}
                <div class="colpop" data-pop="{{ $m }}" hidden>
                  <h4>{{ t('Can book') }} {{ mb_strtolower(t($label)) }}</h4>
                  {!! $yesNo($m) !!}
                </div>
              </th>
            @endforeach
            <th scope="col" class="px-2 py-3 text-center font-medium" title="{{ t('Several meals a day') }}">
              {!! $colBtn('multiple', t('Multiple'), $filters['multiple'] !== '') !!}
              <div class="colpop" data-pop="multiple" hidden>
                <h4>{{ t('Several meals a day') }}</h4>
                {!! $yesNo('multiple') !!}
              </div>
            </th>
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
    @if ($pages > 1)
      <nav class="mt-6 flex items-center justify-center gap-4 text-[14px]" aria-label="{{ t('Pages') }}">
        @if ($page > 1)<x-admin.btn href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}" variant="soft" size="sm">{{ t('Previous') }}</x-admin.btn>@endif
        <span class="text-mute tabular-nums">{{ t('Page {0} of {1}', [0 => $page, 1 => $pages]) }}</span>
        @if ($page < $pages)<x-admin.btn href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}" variant="soft" size="sm">{{ t('Next') }}</x-admin.btn>@endif
      </nav>
    @endif
  </x-admin.section>

  {{-- ------------------------------------------------------------ dialogs --}}
  @if ($dialog === 'new' || $dialog === 'edit')
    @php
        $isEdit = $dialog === 'edit';
        $e = $edit;
        $ticks = [
            'Breakfast' => $isEdit ? (int) $e->Breakfast : (int) ($deptMeals[$departments[0] ?? '']['breakfast'] ?? 0),
            'Lunch' => $isEdit ? (int) $e->Lunch : (int) ($deptMeals[$departments[0] ?? '']['lunch'] ?? 0),
            'Dinner' => $isEdit ? (int) $e->Dinner : (int) ($deptMeals[$departments[0] ?? '']['dinner'] ?? 0),
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
            @php $current = (string) ($e->Department ?? ''); $opts = $current !== '' && !in_array($current, $departments, true) ? array_merge([$current], $departments) : $departments; @endphp
            @foreach ($opts as $d)<option @selected($field('department', $current !== '' ? $current : ($departments[0] ?? '')) === $d)>{{ $d }}</option>@endforeach
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
                   :description="t('Upload a file (.xlsx, .ods, .csv, .tsv, .txt) whose first row has the columns immatricule, nom, prenom, n_carte, dept, poste, user_name, PW. New departments are added to the list, passwords are stored hashed, and everyone starts as an employee. Or paste one user per line: first, last, email, department, position.')">
      <form method="POST" action="/users/import" enctype="multipart/form-data" class="flex flex-col gap-5">
        @csrf
        <x-admin.field :label="t('Paste list')" for="i-list">
          <x-admin.textarea id="i-list" name="list" class="min-h-44 font-mono text-[13px]" placeholder="Nadia, Sabbagh, nadia.sabbagh@company.com, 1, Analyst&#10;Jad, Haddad, jad.haddad@company.com, 2, Intern"></x-admin.textarea>
        </x-admin.field>
        <label class="flex w-fit cursor-pointer items-center gap-2 rounded-full bg-cream px-5 py-3 text-sm font-medium transition hover:bg-cream-deep">
          <x-lucide-upload class="size-4" aria-hidden="true" />{{ t('Upload a table file (.xlsx, .ods, .csv, .tsv, .txt)') }}
          <input type="file" name="file" accept=".xlsx,.xlsm,.ods,.csv,.tsv,.txt,text/csv,text/plain" class="sr-only">
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

<style>
  .colbtn{display:inline-flex;align-items:center;gap:.3rem;cursor:pointer;background:none;border:0;padding:.3rem .6rem;margin:-.3rem -.6rem;border-radius:9999px;font:inherit;color:inherit;white-space:nowrap}
  .colbtn:hover,.colbtn[aria-expanded="true"]{background:#ebe8db;color:#111110}
  .colbtn.on{color:#111110}
  .coldot{width:.45rem;height:.45rem;border-radius:9999px;background:#F63440;display:inline-block}
  .colpop{position:fixed;z-index:60;opacity:1;width:16rem;max-height:75vh;overflow:auto;background:#fcfcfa;color:#111110;border-radius:1rem;box-shadow:0 12px 44px rgba(0,0,0,.2),0 0 0 1px rgba(0,0,0,.06);padding:.6rem;font-size:14px;font-weight:400;text-align:start}
  .colpop[hidden]{display:none}
  .colpop .opt{display:flex;align-items:center;gap:.55rem;padding:.35rem .5rem;border-radius:.6rem;cursor:pointer}
  .colpop .opt:hover{background:#f5f3ea}
  .colpop h4{margin:.7rem .5rem .25rem;font-size:12px;font-weight:600;color:#8a887c}
  .colpop .find,.colpop .sel{width:100%;box-sizing:border-box;border:0;border-radius:9999px;background:#f5f3ea;padding:.5rem .9rem;font-size:14px;color:#111110;margin:.25rem 0}
  .colpop .listbox{max-height:14rem;overflow:auto;margin:.2rem 0;border-radius:.6rem}
  .colpop .links{display:flex;gap:1rem;padding:.2rem .5rem;font-size:12px}
  .colpop .links button{background:none;border:0;padding:0;cursor:pointer;color:#111110;text-decoration:underline}
  .chip{display:inline-flex;align-items:center;gap:.4rem;border-radius:9999px;background:#fcfcfa;color:#111110;padding:.25rem .75rem;font-size:13px;text-decoration:none;box-shadow:0 0 0 1px rgba(0,0,0,.08)}
  .chip:hover{background:#111110;color:#fcfcfa}
  .colfoot{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.6rem;padding-top:.6rem;border-top:1px solid rgba(0,0,0,.08)}
  .colfoot button{cursor:pointer;border:0;border-radius:9999px;padding:.45rem 1rem;font-size:13px;font-weight:500;background:#f5f3ea;color:#111110}
  .colfoot .go{background:#111110;color:#fcfcfa}
</style>
<script>
(() => {
  const form = document.getElementById('users-filter');
  if (!form) return;
  const pops = [...document.querySelectorAll('.colpop')];
  const close = () => pops.forEach((p) => { p.hidden = true; const b = document.querySelector('.colbtn[data-col="' + p.dataset.pop + '"]'); if (b) b.setAttribute('aria-expanded', 'false'); });
  // The menus live in <body>: a parent with an animation or transform would otherwise shift their fixed position.
  // (Their inputs still belong to the form through the form="users-filter" attribute.)
  pops.forEach((p) => document.body.appendChild(p));
  document.querySelectorAll('.colbtn').forEach((btn) => btn.addEventListener('click', (e) => {
    e.stopPropagation();
    const pop = document.querySelector('.colpop[data-pop="' + btn.dataset.col + '"]');
    const wasOpen = !pop.hidden;
    close();
    if (wasOpen) return;
    pop.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    const r = btn.getBoundingClientRect();
    const w = pop.offsetWidth;
    const top = Math.min(r.bottom + 6, Math.max(8, window.innerHeight - 200));
    pop.style.top = top + 'px';
    pop.style.maxHeight = Math.max(160, window.innerHeight - top - 12) + 'px'; // never taller than the space below
    pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
    const first = pop.querySelector('[data-find], input[type="text"]');
    if (first) first.focus();
  }));
  document.addEventListener('click', (e) => { if (!e.target.closest('.colpop')) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  // Scrolling the page closes a menu; scrolling inside it (the department list) must not.
  window.addEventListener('scroll', (e) => { if (!(e.target instanceof Element && e.target.closest('.colpop'))) close(); }, true);
  window.addEventListener('resize', close);

  // Radios and drop-downs apply at once; checklists and text boxes wait for "Apply" (or Enter).
  document.querySelectorAll('.colpop [data-quick], .colpop select').forEach((i) => i.addEventListener('change', () => form.requestSubmit()));
  document.querySelectorAll('.colpop').forEach((pop) => {
    pop.querySelectorAll('[data-clear]').forEach((b) => b.addEventListener('click', () => {
      pop.querySelectorAll('input[type="checkbox"]').forEach((c) => { c.checked = false; });
      pop.querySelectorAll('input[type="text"]').forEach((t) => { t.value = ''; });
      pop.querySelectorAll('select').forEach((s) => { s.value = ''; });
      pop.querySelectorAll('input[type="radio"][value=""]').forEach((r) => { r.checked = true; });
      form.requestSubmit();
    }));
    const boxes = () => [...pop.querySelectorAll('[data-list] label')].filter((l) => !l.hidden).map((l) => l.querySelector('input'));
    pop.querySelectorAll('[data-all]').forEach((b) => b.addEventListener('click', () => boxes().forEach((c) => { c.checked = true; })));
    pop.querySelectorAll('[data-none]').forEach((b) => b.addEventListener('click', () => boxes().forEach((c) => { c.checked = false; })));
    pop.querySelectorAll('[data-find]').forEach((f) => f.addEventListener('input', () => {
      const q = f.value.trim().toLowerCase();
      pop.querySelectorAll('[data-list] label').forEach((l) => { l.hidden = q !== '' && !l.textContent.toLowerCase().includes(q); });
    }));
  });
})();
</script>
</x-layouts.admin>
