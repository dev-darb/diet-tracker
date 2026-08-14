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

    <div class="space-y-5">
        <div class="px-1">
            <h1 class="voice-title text-ink">Health</h1>
            <p class="voice-caption mt-0.5 text-ink-dim">Today and this week. Trends arrive as your history grows.</p>
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
                    <span class="data-sm text-ink-faint uppercase">{{ \Illuminate\Support\Carbon::parse($week['start'])->format('j M') }} – {{ \Illuminate\Support\Carbon::parse($week['end'])->format('j M') }}</span>
                </div>

                <p class="mt-4 flex items-baseline gap-2">
                    <span class="data-xl text-ink">{{ $week['averages']['calories']['value'] === null ? '----' : number_format((float) $week['averages']['calories']['value'], 0, '', '') }}</span>
                    <span class="data-sm text-ink-dim uppercase">KCAL/DAY AVG</span>
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
                            <p class="data-lg mt-1 text-ink">
                                {{ $avg['value'] === null ? '----' : rtrim(rtrim(number_format((float) $avg['value'], 1), '0'), '.') }}<span class="data-micro text-ink-faint"> {{ $meta['unit'] }}</span>
                            </p>
                            @if ($trend['comparable'])
                                <p class="data-sm mt-0.5 {{ $trend['direction'] === 'up' ? 'text-good' : ($trend['direction'] === 'down' ? 'text-low' : 'text-ink-faint') }}">
                                    {{ $trend['direction'] === 'up' ? '↑' : ($trend['direction'] === 'down' ? '↓' : '→') }}
                                    {{ number_format(abs((float) $trend['delta']) * 100, 0) }}% VS LAST WK
                                </p>
                            @else
                                <p class="data-sm mt-0.5 text-ink-faint uppercase">No prior week</p>
                            @endif
                            @if ($avg['partial'])
                                <p class="data-micro text-ink-faint uppercase">Partial — some values unknown</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                {{-- Weekly counters share the faceplate: one measurement cluster. --}}
                <div class="-mx-5 -mb-5 mt-4 grid grid-cols-3 divide-x divide-seam border-t border-seam" aria-label="Weekly counters">
                    <div class="px-4 py-3.5">
                        <h3 class="silkscreen">Foods</h3>
                        <p class="data-lg mt-1.5 text-ink">{{ $week['food_variety'] }}</p>
                        <p class="voice-micro mt-0.5 text-ink-dim">distinct</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <h3 class="silkscreen">Logged</h3>
                        <p class="data-lg mt-1.5 text-ink">{{ $week['meal_regularity']['days_logged'] }}<span class="data-md text-ink-faint">/{{ $week['meal_regularity']['days'] }}</span></p>
                        <p class="voice-micro mt-0.5 text-ink-dim">days</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <h3 class="silkscreen">Fruit+Veg</h3>
                        <p class="data-lg mt-1.5 text-ink">
                            {{ $week['fruit_veg']['known'] ? rtrim(rtrim(number_format((float) $week['fruit_veg']['portions_per_day'], 1), '0'), '.') : '----' }}
                        </p>
                        <p class="voice-micro mt-0.5 text-ink-dim">portions/day</p>
                    </div>
                </div>
            </section>

            {{-- TODAY echo pairs tight with the week cluster: both are measurements. --}}
            @if ($today['has_data'])
                <section class="module -mt-3 flex items-baseline justify-between px-5 py-3.5">
                    <div class="flex items-baseline gap-3">
                        <h2 class="silkscreen">Today</h2>
                        <p class="flex items-baseline gap-2">
                            <span class="data-lg text-ink">{{ $today['totals']['calories'] === null ? '----' : number_format((float) $today['totals']['calories'], 0, '', '') }}</span>
                            <span class="data-sm text-ink-dim uppercase">KCAL so far</span>
                        </p>
                    </div>
                    <p class="data-sm text-ink-faint uppercase">{{ $today['food_variety'] }} {{ $today['food_variety'] === 1 ? 'food' : 'foods' }}</p>
                </section>
            @endif

            {{-- ASSESS — component indicators over the week (brief §9.5). --}}
            <x-app.indicators :indicators="$week['indicators']" label="Weekly indicators" />

            {{-- GUIDE — prioritised, pantry-aware insight (brief §9.6). --}}
            <livewire:insight-card />

            {{-- Trends horizon: an unpowered bay, recessed, not a plate (§9.2). --}}
            <section class="well !rounded-md px-5 py-3.5">
                <div class="flex items-center justify-between">
                    <h2 class="silkscreen">Trends</h2>
                    <span class="data-sm text-ink-faint uppercase">Standby</span>
                </div>
                <p class="voice-micro mt-1.5 text-ink-faint">Longer-term trends switch on here once you've logged a few weeks.</p>
            </section>

            <x-app.health-disclaimer />
        @endif
    </div>
