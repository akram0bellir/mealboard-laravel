@props(['name', 'dark' => false])
@php $initials = mb_strtoupper(mb_substr(collect(explode(' ', $name))->map(fn ($p) => mb_substr($p, 0, 1))->implode(''), 0, 2)); @endphp
<span aria-hidden="true" class="inline-flex size-10 shrink-0 items-center justify-center rounded-full text-[13px] font-medium {{ $dark ? 'bg-ink-3 text-page' : 'bg-ink text-page' }}">{{ $initials }}</span>
