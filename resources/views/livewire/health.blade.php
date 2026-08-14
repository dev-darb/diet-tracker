<?php

use App\Services\NutritionAnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Health — Today + This-week horizons (BUILD_PLAN §6 J6.2; brief §9.2/§9.4).
 * Weekly averages with ↑/↓ vs the previous week, food variety, meal regularity,
 * and lightweight inline-SVG sparklines. Trends (§9.2) is a stub for MVP. Every
 * figure comes from NutritionAnalyticsService; the component does NO maths.
 */
new #[Layout('components.layouts.app', ['title' => 'Health'])] class extends Component
{
    public function with(NutritionAnalyticsService $analytics): array
    {
        $user = Auth::user();
        $week = $analytics->weeklySummary($user);

        return [
            'today' => $analytics->dailySummary($user),
            'week' => $week,
            'dayLabels' => array_map(
                fn (array $d) => Carbon::parse($d['date'])->format('D'),
                $week['daily'],
            ),
        ];
    }
}; ?>

    <div class="space-y-3">
        <div class="px-1">
            <h1 class="text-xl font-medium tracking-tight text-ink">Health</h1>
            <p class="mt-0.5 text-sm text-ink-dim">Today and this week. Trends arrive as your history grows.</p>
        </div>

        @if (! $week['has_data'] && ! $today['has_data'])
            <x-app.placeholder
                status="NO DATA"
                title="No readings yet"
                subtitle="After a few days of logging, daily and weekly figures, component indicators and lightweight trends light up here." />
        @else
            {{-- THIS WEEK — average intake + trends vs previous week (brief §9.4). --}}
            <section class="module px-5 pb-5 pt-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="silkscreen">This week</h2>
                    <span class="data text-[11px] text-ink-faint uppercase">{{ \Illuminate\Support\Carbon::parse($week['start'])->format('j M') }} – {{ \Illuminate\Support\Carbon::parse($week['end'])->format('j M') }}</span>
                </div>

                <p class="mt-4 flex items-baseline gap-2">
                    <span class="data text-3xl text-ink">{{ $week['averages']['calories']['value'] === null ? '----' : number_format((float) $week['averages']['calories']['value'], 0, '', '') }}</span>
                    <span class="data text-xs text-ink-dim uppercase">KCAL/DAY AVG</span>
                </p>

                {{-- Weekly calorie sparkline (last 7 days). --}}
                <div class="mt-4">
                    <x-app.sparkline :series="$week['sparklines']['calories']" :labels="$dayLabels" unit="kcal" />
                </div>

                <div class="mt-4 grid grid-cols-2 gap-x-6 border-t border-seam">
                    @php
                        $metrics = [
                            'protein' => ['label' => 'Protein', 'unit' => 'G/DAY'],
                            'fibre' => ['label' => 'Fibre', 'unit' => 'G/DAY'],
                            'saturated_fat' => ['label' => 'Sat fat', 'unit' => 'G/DAY'],
                            'salt' => ['label' => 'Salt', 'unit' => 'G/DAY'],
                        ];
                    @endphp
                    @foreach ($metrics as $key => $meta)
                        @php($avg = $week['averages'][$key])
                        @php($trend = $week['trends'][$key])
                        <div class="py-3">
                            <h3 class="silkscreen">{{ $meta['label'] }}</h3>
                            <p class="data mt-1 text-lg text-ink">
                                {{ $avg['value'] === null ? '----' : rtrim(rtrim(number_format((float) $avg['value'], 1), '0'), '.') }}<span class="text-[10px] text-ink-faint"> {{ $meta['unit'] }}</span>
                            </p>
                            @if ($trend['comparable'])
                                <p class="data mt-0.5 text-[11px] {{ $trend['direction'] === 'up' ? 'text-good' : ($trend['direction'] === 'down' ? 'text-low' : 'text-ink-faint') }}">
                                    {{ $trend['direction'] === 'up' ? '↑' : ($trend['direction'] === 'down' ? '↓' : '→') }}
                                    {{ number_format(abs((float) $trend['delta']) * 100, 0) }}% VS LAST WK
                                </p>
                            @else
                                <p class="data mt-0.5 text-[11px] text-ink-faint uppercase">No prior week</p>
                            @endif
                            @if ($avg['partial'])
                                <p class="data text-[10px] text-ink-faint uppercase">Partial — some days not stated</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Variety / regularity / fruit & veg counters. --}}
            <section class="module grid grid-cols-3 divide-x divide-seam" aria-label="Weekly counters">
                <div class="px-4 py-3.5">
                    <h3 class="silkscreen">Foods</h3>
                    <p class="data mt-1.5 text-xl text-ink">{{ $week['food_variety'] }}</p>
                    <p class="mt-0.5 text-[11px] text-ink-dim">distinct</p>
                </div>
                <div class="px-4 py-3.5">
                    <h3 class="silkscreen">Logged</h3>
                    <p class="data mt-1.5 text-xl text-ink">{{ $week['meal_regularity']['days_logged'] }}<span class="text-sm text-ink-faint">/{{ $week['meal_regularity']['days'] }}</span></p>
                    <p class="mt-0.5 text-[11px] text-ink-dim">days</p>
                </div>
                <div class="px-4 py-3.5">
                    <h3 class="silkscreen">Fruit+Veg</h3>
                    <p class="data mt-1.5 text-xl text-ink">
                        {{ $week['fruit_veg']['known'] ? rtrim(rtrim(number_format((float) $week['fruit_veg']['portions_per_day'], 1), '0'), '.') : '----' }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-ink-dim">portions/day</p>
                </div>
            </section>

            {{-- Component indicators over the week (brief §9.5). --}}
            <x-app.indicators :indicators="$week['indicators']" label="Weekly indicators" />

            {{-- TODAY mini-view (brief §9.2/§9.3). --}}
            @if ($today['has_data'])
                <section class="module px-5 pb-4 pt-4">
                    <h2 class="silkscreen">Today</h2>
                    <p class="mt-2 flex items-baseline gap-2">
                        <span class="data text-2xl text-ink">{{ $today['totals']['calories'] === null ? '----' : number_format((float) $today['totals']['calories'], 0, '', '') }}</span>
                        <span class="data text-xs text-ink-dim uppercase">KCAL so far</span>
                    </p>
                    <p class="data mt-1 text-[11px] text-ink-faint uppercase">{{ $today['food_variety'] }} distinct {{ $today['food_variety'] === 1 ? 'food' : 'foods' }} today</p>
                </section>
            @endif

            {{-- Your focus this week — prioritised, pantry-aware insight (brief §9.6). --}}
            <livewire:insight-card />

            {{-- Trends horizon — stub for MVP (brief §9.2: Today + Week suffice). --}}
            <section class="module px-5 py-4">
                <div class="flex items-center justify-between">
                    <h2 class="silkscreen">Trends</h2>
                    <span class="data text-[11px] text-ink-faint uppercase">Standby</span>
                </div>
                <p class="mt-2 text-xs leading-relaxed text-ink-dim">Longer-term trends switch on here once you've logged a few weeks.</p>
            </section>

            <x-app.health-disclaimer />
        @endif
    </div>
