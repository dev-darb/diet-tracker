<?php

use App\Models\FoodyScore;
use App\Services\FoodyScore\FoodyScoreService;
use App\Services\FoodyScore\SharePayloadService;
use App\Services\NutritionAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Home / Today (Foody Score spec, Home IA): the console's master panel in the
 * spec's hierarchy — Foody Score headline with its confidence state, core
 * nutrition context (kcal + protein/carbs/fat/fibre), a contextual read,
 * dynamic signals (lazy island, ≤3), rolling 7-day context, then the action.
 *
 * All figures come from the deterministic services — this component selects
 * copy and geometry, it computes nothing (brief §9.9). The score is engine
 * output recorded on today's row; the LLM can neither move it nor invent a
 * signal (spec §1–§2).
 */
new #[Layout('components.layouts.app', ['title' => 'Home'])] class extends Component
{
    /** UI labels for pillar keys — natural words, never internal keys. */
    private const PILLAR_LABELS = [
        'energy' => 'Energy',
        'macro' => 'Macros',
        'fibre_plants' => 'Fibre & plants',
        'micronutrients' => 'Micronutrients',
        'moderation' => 'Moderation',
    ];

    /**
     * The contextual read (Home IA level 3): one deterministic sentence that
     * interprets the score's state honestly. Time-aware by construction — a
     * quiet morning is low confidence, never "under-eating" (spec §7, §13).
     */
    private function read(FoodyScore $record, bool $hasToday): string
    {
        if ($record->display_state === 'building') {
            return 'A few more logged days and this becomes a firm read.';
        }

        if (! $hasToday) {
            return 'Nothing logged yet — the score is leaning on your recent days.';
        }

        if ($record->display_state === 'provisional') {
            return 'Early read — it firms up as the day fills in.';
        }

        $down = $record->contributors['down'] ?? [];
        if ($down !== []) {
            return (self::PILLAR_LABELS[$down[0]] ?? 'One area').' is the one to nudge.';
        }

        if ($record->score >= 80) {
            $up = $record->contributors['up'] ?? [];

            return $up !== []
                ? 'Running well — '.strtolower(self::PILLAR_LABELS[$up[0]] ?? 'the basics').' is carrying it.'
                : 'Running well.';
        }

        return 'Steady. Nothing needs forcing.';
    }

    /** The label stamped under the score: confidence first, verdict second. */
    private function stateLabel(FoodyScore $record): string
    {
        return match ($record->display_state) {
            'building' => 'Building',
            'provisional' => 'Early read',
            default => ucfirst($record->band),
        };
    }

    public function with(NutritionAnalyticsService $analytics, FoodyScoreService $scores, SharePayloadService $share): array
    {
        $user = Auth::user();
        $week = $analytics->weeklySummary($user);
        $today = $analytics->dailySummary($user);

        // Deterministic engine + recorder — no model anywhere in this path,
        // so the headline never waits (product principle 7).
        $record = $scores->computeAndRecord($user);

        // Rolling context: the last 7 recorded days' scores, aligned with the
        // streak strip's dates. Missing rows render as gaps, never zeros.
        $history = FoodyScore::query()
            ->where('user_id', $user->id)
            ->whereDate('score_date', '<', now()->toDateString())
            ->orderByDesc('score_date')
            ->limit(7)
            ->get()
            ->keyBy(fn (FoodyScore $row) => $row->score_date->toDateString());

        return [
            'record' => $record,
            'readLine' => $this->read($record, $today['has_data']),
            'stateLabel' => $this->stateLabel($record),
            'share' => $share->daily($user, $record),
            'today' => $today,
            'trace' => array_map(fn (array $day) => [
                ...$day,
                'score' => $history->get($day['date'])?->score,
            ], $week['daily']),
            'daysLogged' => $week['meal_regularity']['days_logged'],
            'lastLoggedAt' => $user->consumptionEvents()
                ->whereDate('consumed_at', now()->toDateString())
                ->max('consumed_at'),
        ];
    }
}; ?>

    <div class="space-y-5">
        <h1 class="sr-only">Home</h1>

        {{-- 1 · FOODY SCORE — the headline instrument. Confidence shapes the
             presentation: firm glows, an early read sits quieter, building
             stays faint — the number never pretends to more certainty than
             the evidence holds (spec §13, §15). --}}
        <section class="module px-5 pb-5 pt-4" x-data="{ shareOpen: false }">
            <div class="flex items-center justify-between">
                <h2 class="silkscreen">Foody Score</h2>
                <span class="data-sm uppercase {{ $record->display_state === 'firm' ? 'text-ink-dim' : 'text-ink-faint' }}">{{ $stateLabel }}</span>
            </div>

            <p class="mt-4 flex items-baseline justify-center gap-3">
                <span wire:key="score-{{ $record->score }}-{{ $record->display_state }}"
                      class="value-settle font-seg text-[clamp(4.6rem,19vw,6rem)] leading-none {{ match ($record->display_state) {
                          'firm' => 'text-phosphor',
                          'provisional' => 'text-ink',
                          default => 'text-ink-faint',
                      } }}"
                      aria-label="Foody Score {{ $record->score }}, {{ $stateLabel }}">{{ $record->score }}</span>
            </p>

            {{-- 3 · The contextual read: one honest sentence, no filler. --}}
            <p class="voice-body mt-3 border-t border-seam pt-3 text-center text-ink-dim">
                {{ $readLine }}
            </p>

            {{-- Milestones minted today (spec §17): personal records only —
                 never a comparison with anyone else. Share is a keycap, not
                 a nag. --}}
            @if ($share['milestones'] !== [] || $record->display_state === 'firm')
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-seam pt-3">
                    <div class="min-w-0">
                        @foreach ($share['milestones'] as $milestone)
                            <p class="stamp-in data-sm truncate text-phosphor uppercase" wire:key="milestone-{{ $milestone['kind'] }}">
                                ★ {{ $milestone['label'] }}
                            </p>
                        @endforeach
                    </div>
                    <button type="button" x-on:click="shareOpen = true"
                            class="key keycap-sm hit shrink-0 px-3.5 py-2 text-ink-dim">
                        Share
                    </button>
                </div>
            @endif

            {{-- The share card (spec §18): visually distinctive, recognisably
                 Foody, and entirely self-referential — your day, no ranking. --}}
            <div x-show="shareOpen" x-cloak x-on:keydown.escape.window="shareOpen = false"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-6"
                 role="dialog" aria-modal="true" aria-label="Share your Foody Score">
                <div class="w-full max-w-xs" x-on:click.outside="shareOpen = false">
                    <div class="rounded-2xl border border-seam-strong bg-chassis p-6 text-center shadow-2xl">
                        <p class="silkscreen text-ink-faint">Foody Score</p>
                        <p class="mt-4 font-seg text-7xl leading-none text-phosphor">{{ $record->score }}</p>
                        <p class="data-sm mt-3 text-ink-dim uppercase">{{ $stateLabel }}</p>
                        @foreach ($share['milestones'] as $milestone)
                            <p class="data-sm mt-1.5 text-phosphor uppercase">★ {{ $milestone['label'] }}</p>
                        @endforeach
                        <p class="data-micro mt-4 border-t border-seam pt-3 text-ink-faint uppercase">
                            {{ \Illuminate\Support\Carbon::parse($share['date'])->format('D j M Y') }} · Foody
                        </p>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="button"
                                x-on:click="const text = @js($share['share_text']); navigator.share ? navigator.share({ text }).catch(() => {}) : navigator.clipboard?.writeText(text)"
                                class="key keycap-sm hit flex-1 py-2.5 text-ink">
                            Share it
                        </button>
                        <button type="button" x-on:click="shareOpen = false"
                                class="keycap-sm hit flex-1 py-2.5 text-ink-faint">
                            Done
                        </button>
                    </div>
                </div>
            </div>
        </section>

        {{-- 2 · CORE CONTEXT — the day's numbers under the score: kcal on the
             gauge, then the four nutrients that anchor the pillars. --}}
        <section class="module px-5 pb-5 pt-4">
            <h2 class="silkscreen">Today</h2>

            @php($kcal = $today['totals']['calories'])
            <p class="mt-4 flex items-baseline justify-center gap-3">
                <span wire:key="kcal-{{ $kcal ?? 'none' }}"
                      class="value-settle font-seg text-[clamp(2.6rem,10vw,3.4rem)] leading-none {{ $today['has_data'] && $kcal !== null ? 'text-ink' : 'text-ink-faint' }}"
                      aria-label="{{ $kcal !== null ? number_format((float) $kcal).' kilocalories today' : 'No calories logged yet today' }}">{{ $kcal !== null ? number_format((float) $kcal, 0, '', '') : '----' }}</span>
                <span class="data-md text-ink-dim">KCAL</span>
            </p>

            {{-- Calibrated to YOUR daily calorie target (NutritionTargetsService).
                 The marker is the day's reading; geometry only, no maths here. --}}
            @php($calorieTarget = $today['targets']['calories'] ?? null)
            @php($scaleMax = (float) ($calorieTarget['target'] ?? 2500))
            @php($frac = $kcal !== null ? min(max((float) $kcal, 0) / $scaleMax, 1) : null)
            <div class="mt-3" role="img" aria-label="{{ $kcal !== null ? 'Scale reading '.number_format((float) $kcal).' of your '.number_format($scaleMax).' kilocalorie target' : 'Scale idle' }}">
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
                    <span>0</span>
                    <span title="{{ $calorieTarget['basis'] ?? '' }}">
                        {{ number_format($scaleMax, 0, '', '') }}
                    </span>
                </div>
            </div>

            {{-- Protein / Carbs / Fat / Fibre — carbs and fibre are first-class
                 citizens of the readout (spec §6, §8). Meters run against each
                 nutrient's own target; unknown reads as a gap, never a zero. --}}
            @if ($today['has_data'])
            @php($cluster = collect(['protein' => 'Protein', 'carbs' => 'Carbs', 'fat' => 'Fat', 'fibre' => 'Fibre'])
                ->map(fn ($label, $key) => [
                    'label' => $label,
                    'value' => $today['totals'][$key],
                    'target' => (float) ($today['targets'][$key]['target'] ?? 0),
                ]))
            <div class="-mx-5 mt-4 grid grid-cols-4 divide-x divide-seam border-t border-seam" aria-label="Protein, carbs, fat and fibre today">
                @foreach ($cluster as $m)
                    <div class="px-3 py-3.5">
                        <h3 class="silkscreen">{{ $m['label'] }}</h3>
                        <p class="data-lg mt-1.5 text-ink">
                            {{ $m['value'] === null ? '—' : rtrim(rtrim(number_format((float) $m['value'], 1), '0'), '.') }}<span class="data-micro text-ink-dim">g</span>
                        </p>
                        <div class="meter mt-2.5" title="{{ $m['target'] > 0 ? 'of '.rtrim(rtrim(number_format($m['target'], 1), '0'), '.').'g' : '' }}">
                            <span style="width: {{ $m['value'] !== null && $m['target'] > 0 ? round(min((float) $m['value'] / $m['target'], 1) * 100) : 0 }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="data-sm -mx-5 border-t border-seam px-5 pt-3 text-ink-faint uppercase">
                {{ $today['food_variety'] }} {{ $today['food_variety'] === 1 ? 'food' : 'foods' }} logged{{ $lastLoggedAt ? ' · last '.\Illuminate\Support\Carbon::parse($lastLoggedAt)->format('H:i') : '' }}
            </p>
            @else
                <p class="voice-body mt-4 border-t border-seam pt-3 text-ink-dim">
                    Nothing logged yet.
                </p>
            @endif
        </section>

        {{-- 4 · SIGNALS — at most three, normally one or two, silent on a
             quiet day. Lazy island: the page never waits for wording. --}}
        <livewire:score-signals />

        {{-- 5 · ROLLING CONTEXT — the week at a glance: score trace above,
             logging streak below, aligned day by day. --}}
        <section class="module px-5 py-3.5">
            <div class="flex items-center justify-between">
                <h2 class="silkscreen">Last 7 days</h2>
                <p class="data-md text-ink">
                    {{ str_pad((string) $daysLogged, 2, '0', STR_PAD_LEFT) }}<span class="text-ink-faint">/07</span>
                    <span class="data-sm ml-1 text-ink-dim uppercase">logged</span>
                </p>
            </div>
            @if (collect($trace)->contains(fn ($d) => $d['score'] !== null))
                <div class="mt-3 grid h-9 grid-cols-7 items-end gap-1.5" role="img"
                     aria-label="Foody Score for the last seven days">
                    @foreach ($trace as $day)
                        @if ($day['score'] !== null)
                            <div class="w-full rounded-t-[2px] bg-seam-strong" style="height: {{ max(8, $day['score']) }}%"
                                 title="{{ $day['date'] }}: {{ $day['score'] }}"></div>
                        @else
                            <div class="w-full self-end border-t border-seam" title="{{ $day['date'] }}: no score"></div>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="mt-2.5 grid grid-cols-7 justify-items-center gap-1.5" aria-hidden="true">
                @foreach ($trace as $day)
                    <span class="led {{ $day['has_data'] ? 'led-on' : '' }}"></span>
                @endforeach
            </div>
        </section>

        {{-- 6 · ACTION — logging is one tap from the front panel: SCAN owns
             the nav's centre key; every other meal goes through Log. --}}
        <x-app.console-key :href="route('eat.log')">
            <span class="flex items-center justify-center gap-2"><x-app.icon name="pan" class="size-4" />Log a meal</span>
        </x-app.console-key>
    </div>
