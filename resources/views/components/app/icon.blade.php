@props(['name'])

{{--
    The console's icon bank — hairline glyphs matching the instrument language
    (1.7 stroke, currentColor, no fills; think silkscreened panel symbols).

    PLACEMENT RULES (design decision, Aug 2026): icons appear ONLY where a
    complete list gets them — never in titles, never as decoration on a lone
    element. Current sanctioned lists:
      - Log-a-meal context keys (pan / storefront / barcode)
      - Indicator rows (egg / wheat / apple / droplet / shaker / grid)
      - Eat history rows (context: basket / pan / storefront)
    Adding an icon to one member of a list means the whole list carries them.
--}}
<svg {{ $attributes->merge(['class' => 'size-4 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        {{-- Contexts --}}
        @case('pan') {{-- home-cooked --}}
            <circle cx="10" cy="12" r="5.25" />
            <path d="M15.25 12h4.5" />
            @break
        @case('storefront') {{-- eating out --}}
            <path d="M4.75 9.25 6 5h12l1.25 4.25" />
            <path d="M5.75 9.25V19h12.5V9.25" />
            <path d="M10 19v-4.5h4V19" />
            @break
        @case('barcode') {{-- packaged / scan --}}
            <path d="M4.5 5v14m3.25-14v14M11 5v14m4.25-14v14M19.5 5v14" />
            @break
        @case('basket') {{-- pantry --}}
            <path d="M4.5 9.5h15l-1.6 9.1a1.7 1.7 0 0 1-1.68 1.4H7.78a1.7 1.7 0 0 1-1.67-1.4Z" />
            <path d="m8.5 9.5 3.5-5.5 3.5 5.5" />
            @break

        {{-- Nutrients / indicators (food-group glyphs) --}}
        @case('egg') {{-- protein --}}
            <path d="M12 4c3 3.3 5 6.7 5 9.4a5 5 0 0 1-10 0C7 10.7 9 7.3 12 4Z" />
            @break
        @case('wheat') {{-- fibre --}}
            <path d="M12 20V7.5" />
            <path d="M12 7.5c-2.4 0-3.9-1.4-3.9-3.4 2.4 0 3.9 1.4 3.9 3.4Zm0 0c2.4 0 3.9-1.4 3.9-3.4-2.4 0-3.9 1.4-3.9 3.4Z" />
            <path d="M12 12.5c-2.4 0-3.9-1.4-3.9-3.4 2.4 0 3.9 1.4 3.9 3.4Zm0 0c2.4 0 3.9-1.4 3.9-3.4-2.4 0-3.9 1.4-3.9 3.4Z" />
            @break
        @case('apple') {{-- fruit & veg --}}
            <path d="M12 7.3c-.9-1.8.4-3.4 1.9-3.8" />
            <path d="M15.4 7.1c2.4.6 4.1 2.7 4.1 5.3 0 3.6-2.7 7.6-5.5 7.6-.7 0-1.4-.2-2-.6-.6.4-1.3.6-2 .6-2.8 0-5.5-4-5.5-7.6 0-2.6 1.7-4.7 4.1-5.3 1 .3 2.2.7 3.4.7s2.4-.4 3.4-.7Z" />
            @break
        @case('droplet') {{-- saturated fat --}}
            <path d="M12 3.75c3.1 3.7 5.4 6.8 5.4 9.8a5.4 5.4 0 0 1-10.8 0c0-3 2.3-6.1 5.4-9.8Z" />
            @break
        @case('shaker') {{-- salt --}}
            <path d="M9.75 3.75h4.5V7h-4.5Z" />
            <path d="M8.6 20.25 9.75 7h4.5l1.15 13.25Z" />
            @break
        @case('grid') {{-- food variety --}}
            <path d="M4.75 4.75h5.5v5.5h-5.5Z" />
            <path d="M13.75 4.75h5.5v5.5h-5.5Z" />
            <path d="M4.75 13.75h5.5v5.5h-5.5Z" />
            <path d="M13.75 13.75h5.5v5.5h-5.5Z" />
            @break
    @endswitch
</svg>
