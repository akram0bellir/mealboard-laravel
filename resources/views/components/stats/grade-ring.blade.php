@props(['grade', 'parts'])
{{-- The letter in the middle, a ring split by what caused the penalties. --}}
@php
    $gradeColor = in_array($grade, ['A', 'B'], true) ? 'rgb(29 92 51)' : (in_array($grade, ['D', 'E'], true) ? 'rgb(246 52 64)' : 'rgb(17 17 16)');
    $total = array_sum(array_column($parts, 'value'));
    $R = 54;
    $C = 2 * M_PI * $R;
    $shades = ['rgb(17 17 16 / 1)', 'rgb(17 17 16 / 0.65)', 'rgb(17 17 16 / 0.4)', 'rgb(17 17 16 / 0.2)'];
    $offset = 0;
@endphp
<div class="flex flex-wrap items-center gap-6">
  <svg viewBox="0 0 140 140" class="size-36" role="img" aria-label="{{ t('Grade {0}', [0 => $grade]) }}">
    <circle cx="70" cy="70" r="{{ $R }}" fill="none" stroke="rgb(17 17 16 / 0.08)" stroke-width="14" />
    @if ($total)
      @foreach ($parts as $i => $p)
        @php $len = $p['value'] / $total * $C; @endphp
        <circle cx="70" cy="70" r="{{ $R }}" fill="none" stroke="{{ $shades[$i % 4] }}" stroke-width="14" stroke-dasharray="{{ $len }} {{ $C - $len }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 70 70)"><title>{{ $p['label'] }}</title></circle>
        @php $offset += $len; @endphp
      @endforeach
    @endif
    <text x="70" y="82" text-anchor="middle" font-size="40" font-weight="600" fill="{{ $gradeColor }}">{{ $grade }}</text>
  </svg>
  <ul class="flex flex-col gap-1.5 text-[12px] text-mute">
    @foreach ($parts as $i => $p)
      <li class="flex items-center gap-2"><span class="size-3 rounded-sm" style="background: {{ $total ? $shades[$i % 4] : 'rgb(17 17 16 / 0.08)' }}"></span>{{ $p['label'] }}@if ($total)<span class="text-ink tabular-nums">{{ round($p['value'] / $total * 100) }}%</span>@endif</li>
    @endforeach
    @if (!$total)<li>{{ t('No penalties recorded — the ring stays empty.') }}</li>@endif
  </ul>
</div>
