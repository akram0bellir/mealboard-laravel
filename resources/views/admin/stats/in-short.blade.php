{{-- In short: a sentence, then one plate per meal (full = eaten, dashed = missed), then what needs attention. --}}
@php $s = $tiles['inShort']; @endphp
<x-admin.section tone="cream" class="@container md:px-8 md:py-10 xl:col-span-7">
  <p class="max-w-[70ch] text-2xl leading-snug font-medium tracking-[-0.01em]" data-in-short>{{ $s['sentence'] }}</p>
  <div class="mt-6"><x-stats.plates :eaten="$s['eaten']" :missed="$s['missed']" /></div>
  @if ($s['attention'])
    <ul class="mt-6 flex flex-col gap-2">
      @foreach ($s['attention'] as [$text, $href])
        <li><a href="{{ $href }}" class="flex w-fit items-center gap-2 rounded-full bg-page px-4 py-2 text-[14px] transition hover:bg-cream-deep"><x-lucide-triangle-alert class="size-4 text-lime" aria-hidden="true" />{{ $text }} →</a></li>
      @endforeach
    </ul>
  @endif
</x-admin.section>
