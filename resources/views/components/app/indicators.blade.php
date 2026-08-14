@props(['indicators', 'label' => 'Indicators'])

{{--
    Component indicators (brief §9.5): qualitative bands, NOT a numeric score.
    Bands and values are computed in NutritionAnalyticsService — this partial only
    maps a band to its signal chip and lays the rows out. No arithmetic here.
--}}
@php
    $chip = [
        \App\Services\NutritionAnalyticsService::BAND_GOOD => 'bg-good text-black',
        \App\Services\NutritionAnalyticsService::BAND_OK => 'bg-good/70 text-black',
        \App\Services\NutritionAnalyticsService::BAND_LOW => 'bg-low text-black',
        \App\Services\NutritionAnalyticsService::BAND_SLIGHTLY_HIGH => 'bg-high text-black',
        \App\Services\NutritionAnalyticsService::BAND_UNKNOWN => 'border-seam-strong text-ink-faint',
    ];
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $icon = [
        'protein' => 'egg',
        'fibre' => 'wheat',
        'fruit_veg' => 'apple',
        'saturated_fat' => 'droplet',
        'salt' => 'shaker',
        'food_variety' => 'grid',
    ];
@endphp

<div class="module px-5 pb-2 pt-4">
    <h2 class="silkscreen">{{ $label }}</h2>
    <ul class="mt-2 divide-y divide-seam">
        @foreach ($indicators as $indicator)
            {{-- Every target carries its receipt (NutritionTargetsService basis):
                 tap/hover a row to see where the number comes from. --}}
            <li class="flex items-center gap-3 py-2.5" @isset($indicator['basis']) title="{{ $indicator['basis'] }}" @endisset>
                <x-app.icon :name="$icon[$indicator['key']] ?? 'grid'" class="size-4 shrink-0 text-ink-faint" />
                <span class="voice-caption min-w-0 flex-1 truncate text-ink">
                    {{ $indicator['label'] }}
                    @if ($indicator['personalised'] ?? false)
                        <span class="data-micro text-phosphor uppercase" title="Personal target from your profile">· yours</span>
                    @endif
                </span>
                <span class="data-md text-ink-dim">
                    @if ($indicator['known'])
                        {{ $fmt($indicator['value']) }}{{ $indicator['unit'] === 'g' ? 'G' : '' }}<span class="text-ink-faint">/{{ $fmt($indicator['target']) }}{{ $indicator['unit'] === 'g' ? 'G' : '' }}</span>
                        @if ($indicator['unit'] !== 'g')
                            <span class="data-micro ml-1 text-ink-faint uppercase">{{ $indicator['unit'] }}</span>
                        @endif
                    @else
                        ----
                    @endif
                </span>
                <span class="chip shrink-0 border {{ $chip[$indicator['band']] ?? $chip[\App\Services\NutritionAnalyticsService::BAND_UNKNOWN] }}">
                    <span class="uppercase">{{ $indicator['known'] ? $indicator['band'] : 'No data' }}</span>
                </span>
            </li>
        @endforeach
    </ul>

    {{-- Provenance on tap: the receipt for every target — personalised formula
         or the cited UK guidance. Trust comes from showing the working. --}}
    <div x-data="{ open: false }" class="border-t border-seam py-2.5">
        <button type="button" x-on:click="open = !open" class="keycap-sm flex w-full items-center justify-between text-ink-faint transition hover:text-ink">
            <span>Where these targets come from</span>
            <span x-text="open ? '−' : '+'" class="data-sm"></span>
        </button>
        <ul x-show="open" x-cloak class="mt-2 space-y-1.5">
            @foreach ($indicators as $indicator)
                @isset($indicator['basis'])
                    <li class="text-xs text-ink-dim">
                        <span class="text-ink">{{ $indicator['label'] }}:</span> {{ $indicator['basis'] }}
                    </li>
                @endisset
            @endforeach
            <li class="text-xs text-ink-faint">General guidance, not medical advice.</li>
        </ul>
    </div>
</div>
