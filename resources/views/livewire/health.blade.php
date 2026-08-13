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

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Health</h1>
            <p class="mt-1 text-sm text-zinc-500">Today and this week. Trends arrive as your history grows.</p>
        </div>

        @if (! $week['has_data'] && ! $today['has_data'])
            <x-app.placeholder
                title="No insights yet"
                subtitle="After a few days of logging, you'll see daily and weekly nutrition figures, component indicators, and lightweight trends.">
                <x-slot:icon>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h12M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3M9 11.25l2.25-2.25 1.5 1.5L15 8.25" />
                    </svg>
                </x-slot:icon>
            </x-app.placeholder>
        @else
            {{-- Your focus this week — prioritised, pantry-aware insight (brief §9.6). --}}
            <livewire:insight-card />

            {{-- THIS WEEK — average intake + trends vs previous week (brief §9.4). --}}
            <section class="space-y-3">
                <div class="flex items-baseline justify-between px-1">
                    <h2 class="text-sm font-semibold text-zinc-900">This week</h2>
                    <span class="text-xs text-zinc-400">{{ \Illuminate\Support\Carbon::parse($week['start'])->format('j M') }} – {{ \Illuminate\Support\Carbon::parse($week['end'])->format('j M') }}</span>
                </div>

                <div class="rounded-2xl border border-zinc-100 bg-white px-5 py-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Average intake</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900">
                        {{ $week['averages']['calories']['value'] === null ? '—' : number_format((float) $week['averages']['calories']['value']) }}
                        <span class="text-base font-normal text-zinc-400">kcal/day</span>
                    </p>

                    {{-- Weekly calorie sparkline (last 7 days). --}}
                    <div class="mt-4">
                        <x-app.sparkline :series="$week['sparklines']['calories']" :labels="$dayLabels" unit="kcal" color="emerald" />
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-x-4 gap-y-4">
                        @php
                            $metrics = [
                                'protein' => ['label' => 'Protein', 'unit' => 'g/day'],
                                'fibre' => ['label' => 'Fibre', 'unit' => 'g/day'],
                                'saturated_fat' => ['label' => 'Saturated fat', 'unit' => 'g/day'],
                                'salt' => ['label' => 'Salt', 'unit' => 'g/day'],
                            ];
                        @endphp
                        @foreach ($metrics as $key => $meta)
                            @php($avg = $week['averages'][$key])
                            @php($trend = $week['trends'][$key])
                            <div>
                                <p class="text-xs font-medium text-zinc-500">{{ $meta['label'] }}</p>
                                <p class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900">
                                    {{ $avg['value'] === null ? '—' : rtrim(rtrim(number_format((float) $avg['value'], 1), '0'), '.') }}<span class="text-xs font-normal text-zinc-400"> {{ $meta['unit'] }}</span>
                                </p>
                                @if ($trend['comparable'])
                                    <p class="mt-0.5 flex items-center gap-1 text-xs font-medium {{ $trend['direction'] === 'up' ? 'text-emerald-600' : ($trend['direction'] === 'down' ? 'text-amber-600' : 'text-zinc-400') }}">
                                        <span>{{ $trend['direction'] === 'up' ? '↑' : ($trend['direction'] === 'down' ? '↓' : '→') }}</span>
                                        <span>{{ number_format(abs((float) $trend['delta']) * 100, 0) }}% vs last week</span>
                                    </p>
                                @else
                                    <p class="mt-0.5 text-xs text-zinc-400">No prior week yet</p>
                                @endif
                                @if ($avg['partial'])
                                    <p class="text-[11px] text-zinc-400">Partial — some days not stated</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Variety / regularity / fruit & veg summary tiles. --}}
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-zinc-100 bg-white px-4 py-3.5 shadow-sm">
                        <p class="text-2xl font-semibold tabular-nums text-zinc-900">{{ $week['food_variety'] }}</p>
                        <p class="mt-0.5 text-xs text-zinc-500">distinct foods</p>
                    </div>
                    <div class="rounded-2xl border border-zinc-100 bg-white px-4 py-3.5 shadow-sm">
                        <p class="text-2xl font-semibold tabular-nums text-zinc-900">{{ $week['meal_regularity']['days_logged'] }}<span class="text-sm font-normal text-zinc-400">/{{ $week['meal_regularity']['days'] }}</span></p>
                        <p class="mt-0.5 text-xs text-zinc-500">days logged</p>
                    </div>
                    <div class="rounded-2xl border border-zinc-100 bg-white px-4 py-3.5 shadow-sm">
                        <p class="text-2xl font-semibold tabular-nums text-zinc-900">
                            {{ $week['fruit_veg']['known'] ? rtrim(rtrim(number_format((float) $week['fruit_veg']['portions_per_day'], 1), '0'), '.') : '—' }}
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500">fruit &amp; veg/day</p>
                    </div>
                </div>
            </section>

            {{-- Component indicators over the week (brief §9.5). --}}
            <section class="space-y-2">
                <h2 class="px-1 text-sm font-semibold text-zinc-900">Weekly indicators</h2>
                <x-app.indicators :indicators="$week['indicators']" />
            </section>

            {{-- TODAY mini-view (brief §9.2/§9.3). --}}
            @if ($today['has_data'])
                <section class="space-y-2">
                    <h2 class="px-1 text-sm font-semibold text-zinc-900">Today</h2>
                    <div class="rounded-2xl border border-zinc-100 bg-white px-5 py-4 shadow-sm">
                        <p class="text-2xl font-semibold tabular-nums text-zinc-900">
                            {{ $today['totals']['calories'] === null ? '—' : number_format((float) $today['totals']['calories']) }}
                            <span class="text-sm font-normal text-zinc-400">kcal so far</span>
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $today['food_variety'] }} distinct foods today</p>
                    </div>
                </section>
            @endif

            {{-- Trends horizon — stub for MVP (brief §9.2: Today + Week suffice). --}}
            <section class="space-y-2">
                <h2 class="px-1 text-sm font-semibold text-zinc-900">Trends</h2>
                <div class="flex items-center gap-3 rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/60 px-5 py-4">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-500">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>Coming soon
                    </span>
                    <p class="text-xs text-zinc-500">Longer-term trends appear here once you've logged a few weeks.</p>
                </div>
            </section>

            <x-app.health-disclaimer />
        @endif
    </div>

