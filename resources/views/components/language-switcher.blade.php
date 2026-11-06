@props(['dark' => false])
{{-- EN · FR · ع switch: one form, one button per language; the choice is kept in a cookie. --}}
<form method="POST" action="/lang" role="radiogroup" aria-label="{{ t('Language') }}"
      {{ $attributes->merge(['class' => 'flex h-10 items-center gap-0.5 rounded-full p-1 ' . ($dark ? 'bg-ink-3' : 'bg-cream-deep')]) }}>
  @csrf
  @icon('languages', 'mx-1.5 size-4 ' . ($dark ? 'text-muted-dark' : 'text-mute'), ['aria-hidden' => 'true'])
  @foreach (MEALBOARD_LANGS as $code => $lang)
    @php $active = app()->getLocale() === $code; @endphp
    <button type="submit" name="lang" value="{{ $code }}" role="radio" aria-checked="{{ $active ? 'true' : 'false' }}"
            aria-label="{{ $lang['label'] }}" title="{{ $lang['label'] }}"
            class="h-8 min-w-8 cursor-pointer rounded-full px-2 text-[12px] font-medium transition-colors {{ $active ? ($dark ? 'bg-page text-ink' : 'bg-ink text-page') : ($dark ? 'text-muted-dark hover:text-page' : 'text-mute hover:text-ink') }}">{{ $lang['short'] }}</button>
  @endforeach
</form>
