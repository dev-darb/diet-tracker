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
@endphp

<div class="module px-5 pb-2 pt-4">
    <h2 class="silkscreen">{{ $label }}</h2>
    <ul class="mt-2 divide-y divide-seam">
        @foreach ($indicators as $indicator)
            <li class="flex items-center gap-3 py-2.5">
                <span class="voice-caption min-w-0 flex-1 truncate text-ink">{{ $indicator['label'] }}</span>
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
</div>
