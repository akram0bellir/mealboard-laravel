@php $super = \App\Support\Roles::isSuper(auth()->user()->User_Type); @endphp
<x-layouts.app>
  {{-- The super admin's sidebar is fixed on the left on wide screens; keep the page clear of it. --}}
  <div class="{{ $super ? 'lg:ps-[264px] print:ps-0' : '' }}">
    <x-page-frame>
      <main class="flex flex-col gap-6 print:gap-0">
        <x-admin-nav />
        {{ $slot }}
      </main>
    </x-page-frame>
  </div>
</x-layouts.app>
