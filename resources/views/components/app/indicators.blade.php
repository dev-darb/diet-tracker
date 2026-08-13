@props(['indicators'])

{{--
    Component indicators (brief §9.5): qualitative bands, NOT a numeric score.
    Bands and values are computed in NutritionAnalyticsService — this partial only
    maps a band to a colour and lays it out. No arithmetic here.
--}}
@php
    $bandClasses = [
        \App\Services\NutritionAnalyticsService::BAND_GOOD => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        \App\Services\NutritionAnalyticsService::BAND_OK => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        \App\Services\NutritionAnalyticsService::BAND_LOW => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        \App\Services\NutritionAnalyticsService::BAND_SLIGHTLY_HIGH => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        \App\Services\NutritionAnalyticsService::BAND_UNKNOWN => 'bg-zinc-100 text-zinc-500 ring-zinc-500/10',
    ];
@endphp

<div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm">
    @foreach ($indicators as $indicator)
        <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 last:border-b-0">
            <div class="min-w-0">
                <p class="text-sm font-medium text-zinc-900">{{ $indicator['label'] }}</p>
                @if ($indicator['known'])
                    <p class="mt-0.5 text-xs text-zinc-400">
                        {{ rtrim(rtrim(number_format((float) $indicator['value'], 1), '0'), '.') }}{{ $indicator['unit'] === 'g' ? 'g' : '' }}
                        @if ($indicator['unit'] !== 'g') {{ $indicator['unit'] }} @endif
                        · aim {{ rtrim(rtrim(number_format((float) $indicator['target'], 1), '0'), '.') }}{{ $indicator['unit'] === 'g' ? 'g' : '' }}
                    </p>
                @else
                    <p class="mt-0.5 text-xs text-zinc-400">Not enough data yet</p>
                @endif
            </div>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $bandClasses[$indicator['band']] ?? $bandClasses[\App\Services\NutritionAnalyticsService::BAND_UNKNOWN] }}">
                {{ $indicator['known'] ? $indicator['band'] : '—' }}
            </span>
        </div>
    @endforeach
</div>
