@props([
    'series' => [],
    'labels' => [],
    'unit' => '',
    'color' => 'emerald',
])

{{--
    Lightweight 7-day bar sparkline (brief §9.4/§15.2). Pure inline SVG — no JS
    chart dependency, so it plays cleanly with Livewire (BUILD_PLAN §21 Q38).
    A null day is a genuine gap (no data / unknown), drawn as an empty track, NEVER
    a fabricated zero (brief §2.1). The only computation here is view geometry
    (scaling values to bar heights); no nutrition maths lives in the view.
--}}
@php
    $known = array_values(array_filter($series, fn ($v) => $v !== null));
    $max = $known === [] ? 0 : max($known);
    $count = max(count($series), 1);
    $barColors = [
        'emerald' => 'fill-emerald-500',
        'sky' => 'fill-sky-500',
        'amber' => 'fill-amber-500',
    ];
    $fill = $barColors[$color] ?? $barColors['emerald'];
    // Layout in a 100x40 viewBox; gap between bars is a fixed fraction of the slot.
    $slot = 100 / $count;
    $barW = $slot * 0.6;
    $pad = ($slot - $barW) / 2;
@endphp

<svg viewBox="0 0 100 44" preserveAspectRatio="none" class="h-14 w-full" role="img"
     aria-label="Last {{ $count }} days{{ $unit ? ' ('.$unit.')' : '' }}">
    {{-- baseline --}}
    <line x1="0" y1="40" x2="100" y2="40" class="stroke-zinc-200" stroke-width="0.5" />
    @foreach ($series as $i => $value)
        @php
            $x = $i * $slot + $pad;
        @endphp
        @if ($value === null || $max <= 0)
            {{-- empty track for a day with no data / unknown --}}
            <rect x="{{ $x }}" y="36" width="{{ $barW }}" height="4" rx="1" class="fill-zinc-100" />
        @else
            @php $h = max(2, ($value / $max) * 36); @endphp
            <rect x="{{ $x }}" y="{{ 40 - $h }}" width="{{ $barW }}" height="{{ $h }}" rx="1" class="{{ $fill }}" />
        @endif
    @endforeach
</svg>

@if (! empty($labels))
    <div class="mt-1 flex justify-between px-0.5 text-[10px] font-medium text-zinc-400">
        @foreach ($labels as $label)
            <span class="flex-1 text-center">{{ $label }}</span>
        @endforeach
    </div>
@endif
