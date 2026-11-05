@php $superOnly = $page === 'Settings' && !\App\Support\Roles::isSuper(auth()->user()->User_Type); @endphp
<x-layouts.admin>
  <x-admin.section tone="ink">
    @if ($superOnly)
      <x-admin.heading :label="t('Settings')" :title="t('Super admin only')" :description="t('Ask the super admin to change prices or rules.')" />
    @else
      <x-admin.heading :label="t($page)" :title="t($page)" :description="t('This page is built in the next step.')">
        <x-admin.btn href="/admin" variant="soft-dark" size="lg"><x-lucide-layout-grid />{{ t('Back to the dashboard') }}</x-admin.btn>
      </x-admin.heading>
    @endif
  </x-admin.section>
</x-layouts.admin>
