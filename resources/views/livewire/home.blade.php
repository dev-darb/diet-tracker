<?php

use App\Services\NutritionAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Home / Today (BUILD_PLAN §6 J6.2; brief §9.3). The console's master panel:
 * the day's kcal on the seven-segment readout, macro tiles, the logging
 * streak, and the qualitative component indicators. All figures come from
 * NutritionAnalyticsService — this component does NO arithmetic (brief §9.9).
 */
new #[Layout('components.layouts.app', ['title' => 'Home'])] class extends Component
{
    public function with(NutritionAnalyticsService $analytics): array
    {
        $user = Auth::user();
        $week = $analytics->weeklySummary($user);

        return [
            'today' => $analytics->dailySummary($user),
            'streakDays' => $week['daily'],
            'daysLogged' => $week['meal_regularity']['days_logged'],
            'lastLoggedAt' => $user->consumptionEvents()
                ->whereDate('consumed_at', now()->toDateString())
                ->max('consumed_at'),
        ];
    }
}; ?>

    <div class="space-y-5">
        <h1 class="sr-only">Home</h1>

        {{-- TODAY — the master readout (brief §9.3). Scale axis is the typical
             adult reference intake range; geometry only, no maths here. --}}
        <section class="module px-5 pb-5 pt-4">
            <h2 class="silkscreen">Today</h2>

            @php($kcal = $today['totals']['calories'])
            <p class="mt-6 flex items-baseline justify-center gap-3">
                <span class="font-seg text-[clamp(4.2rem,17vw,5.4rem)] leading-none {{ $today['has_data'] && $kcal !== null ? 'text-phosphor' : 'text-ink-faint' }}"
                      aria-label="{{ $kcal !== null ? number_format((float) $kcal).' kilocalories today' : 'No calories logged yet today' }}">{{ $kcal !== null ? number_format((float) $kcal, 0, '', '') : '----' }}</span>
                <span class="data-md text-ink-dim">KCAL</span>
            </p>

            {{-- Calibrated scale, 0–2500 kcal; the marker is the day's reading. --}}
            @php($frac = $kcal !== null ? min(max((float) $kcal, 0) / 2500, 1) : null)
            <div class="mt-4" role="img" aria-label="{{ $kcal !== null ? 'Scale reading '.number_format((float) $kcal).' of 2500 kilocalories' : 'Scale idle' }}">
                <svg viewBox="0 0 100 8" preserveAspectRatio="none" class="h-4 w-full" aria-hidden="true">
                    @for ($i = 0; $i <= 50; $i++)
                        <line x1="{{ $i * 2 }}" y1="{{ $i % 5 === 0 ? 0.5 : 2.5 }}" x2="{{ $i * 2 }}" y2="7.5"
                              stroke="{{ $frac !== null && $i * 2 <= $frac * 100 ? 'var(--color-seam-strong)' : 'var(--color-seam)' }}" stroke-width="0.45" />
                    @endfor
                    @if ($frac !== null)
                        <line x1="{{ $frac * 100 }}" y1="0" x2="{{ $frac * 100 }}" y2="8" stroke="var(--color-action)" stroke-width="0.9" />
                    @endif
                </svg>
                <div class="data-micro flex justify-between text-ink-faint">
                    <span>0</span><span>2500</span>
                </div>
            </div>

            {{-- Macro readings live on the same faceplate as the master gauge:
                 one instrument cluster, seam-divided (comp A). --}}
            @if ($today['has_data'])
            @php($bandFill = [
                \App\Services\NutritionAnalyticsService::BAND_GOOD => 'var(--color-good)',
                \App\Services\NutritionAnalyticsService::BAND_OK => 'var(--color-good)',
                \App\Services\NutritionAnalyticsService::BAND_LOW => 'var(--color-low)',
                \App\Services\NutritionAnalyticsService::BAND_SLIGHTLY_HIGH => 'var(--color-high)',
            ])
            @php($proteinBand = collect($today['indicators'])->firstWhere('label', 'Protein'))
            @php($macros = collect(['protein' => 'Protein', 'carbs' => 'Carbs', 'fat' => 'Fat'])
                ->map(fn ($label, $key) => [
                    'label' => $label,
                    'value' => $today['totals'][$key],
                    'fill' => $key === 'protein' && ($proteinBand['known'] ?? false)
                        ? ($bandFill[$proteinBand['band']] ?? null)
                        : null,
                ]))
            @php($macroMax = max(array_filter($macros->pluck('value')->all(), fn ($v) => $v !== null) ?: [0]))
            <div class="-mx-5 mt-5 grid grid-cols-3 divide-x divide-seam border-t border-seam" aria-label="Macronutrients today">
                @foreach ($macros as $m)
                    <div class="px-4 py-3.5">
                        <h3 class="silkscreen">{{ $m['label'] }}</h3>
                        <p class="data-lg mt-1.5 text-ink">
                            {{ $m['value'] === null ? '—' : rtrim(rtrim(number_format((float) $m['value'], 1), '0'), '.') }}<span class="data-micro text-ink-dim">g</span>
                        </p>
                        <div class="meter mt-2.5">
                            <span style="width: {{ $m['value'] !== null && $macroMax > 0 ? round($m['value'] / $macroMax * 100) : 0 }}%; {{ $m['fill'] ? 'background:'.$m['fill'] : '' }}"></span>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="data-sm -mx-5 border-t border-seam px-5 pt-3 text-ink-faint uppercase">
                {{ $today['food_variety'] }} {{ $today['food_variety'] === 1 ? 'food' : 'foods' }} logged{{ $lastLoggedAt ? ' · last '.\Illuminate\Support\Carbon::parse($lastLoggedAt)->format('H:i') : '' }}
            </p>
            @else
                <p class="voice-body mt-4 border-t border-seam pt-3 text-ink-dim">
                    Nothing logged yet — scan what you bought or log what you ate, and today's readout wakes up.
                </p>
            @endif
        </section>

        {{-- ASSESS — component indicators (brief §9.5): qualitative bands. --}}
        @if ($today['has_data'])
            <section aria-label="How today looks">
                <x-app.indicators :indicators="$today['indicators']" label="Indicators" />
            </section>
        @endif

        {{-- MOTIVATE + GUIDE — the streak strip and the focus printout pair. --}}
        <div class="space-y-2">
            <section class="module flex items-center justify-between px-5 py-3.5">
                <div class="flex items-center gap-4">
                    <h2 class="silkscreen">Streak</h2>
                    <div class="flex gap-1.5" aria-hidden="true">
                        @foreach ($streakDays as $day)
                            <span class="led {{ $day['has_data'] ? 'led-on' : '' }}"></span>
                        @endforeach
                    </div>
                </div>
                <p class="data-md text-ink">
                    {{ str_pad((string) $daysLogged, 2, '0', STR_PAD_LEFT) }}<span class="text-ink-faint">/07</span>
                    <span class="data-sm ml-1 text-ink-dim uppercase">this week</span>
                </p>
            </section>

            {{-- Your focus this week — the top AI (or deterministic) insight (brief §9.6). --}}
            <livewire:insight-card />
        </div>

        @if ($today['has_data'])
            <x-app.health-disclaimer />
        @endif
    </div>
