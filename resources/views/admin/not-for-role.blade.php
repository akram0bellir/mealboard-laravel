<x-layouts.admin>
  <section class="flex min-h-[50vh] flex-col items-center justify-center gap-4 rounded-section bg-ink p-8 text-center text-page">
    <p class="text-2xl font-medium">{{ t('Not available for your role') }}</p>
    <p class="max-w-[44ch] text-[15px] text-muted-dark">{{ t('Managers can use the dashboard, meal planning, reports and statistics.') }}</p>
    <a href="/admin" class="rounded-full bg-lime px-6 py-3 text-[15px] font-medium text-ink">{{ t('Back to the dashboard') }}</a>
  </section>
</x-layouts.admin>
