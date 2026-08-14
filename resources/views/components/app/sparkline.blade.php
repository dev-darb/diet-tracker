@props([
    'series' => [],
    'labels' => [],
    'unit' => '',
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
    // Layout in a 100x44 viewBox; gap between bars is a fixed fraction of the slot.
    $slot = 100 / $count;
    $barW = $slot * 0.55;
    $pad = ($slot - $barW) / 2;
@endphp

<svg viewBox="0 0 100 44" preserveAspectRatio="none" class="h-14 w-full" role="img"
     aria-label="Last {{ $count }} days{{ $unit ? ' ('.$unit.')' : '' }}">
    {{-- baseline --}}
    <line x1="0" y1="40" x2="100" y2="40" stroke="var(--color-seam-strong)" stroke-width="0.5" />
    @foreach ($series as $i => $value)
        @php($x = $i * $slot + $pad)
        @if ($value === null || $max <= 0)
            {{-- empty track for a day with no data / unknown --}}
            <rect x="{{ $x }}" y="37" width="{{ $barW }}" height="3" fill="var(--color-seam)" />
        @else
            @php($h = max(2, ($value / $max) * 34))
            {{-- quantized cell stack: bars read as LED columns, not smooth fills --}}
            @php($cells = max(1, (int) round($h / 4)))
            @for ($c = 0; $c < $cells; $c++)
                <rect x="{{ $x }}" y="{{ 40 - ($c + 1) * 4 + 0.8 }}" width="{{ $barW }}" height="3.2"
                      fill="{{ $c === $cells - 1 ? 'var(--color-ink-dim)' : 'var(--color-ink-faint)' }}" opacity="{{ $c === $cells - 1 ? 1 : 0.55 }}" />
            @endfor
        @endif
    @endforeach
</svg>

@if (! empty($labels))
    <div class="mt-1 flex justify-between px-0.5">
        @foreach ($labels as $label)
            <span class="silkscreen flex-1 text-center !text-[9px]">{{ $label }}</span>
        @endforeach
    </div>
@endif
