<?php

namespace App\AI\DataObjects;

use App\AI\Contracts\DietInsightGenerator;
use App\Services\DietInsightContextBuilder;
use App\Services\NutritionAnalyticsService;

/**
 * The DETERMINISTIC structured context a {@see DietInsightGenerator}
 * interprets (BUILD_PLAN §6 J7.1; brief §9.7/§9.8).
 *
 * Assembled by {@see DietInsightContextBuilder} from three
 * already-computed, deterministic sources:
 *
 *  - the {@see NutritionAnalyticsService} weekly summary (gaps,
 *    trends, component indicators) — the LLM never recomputes these figures;
 *  - the user's goal / dietary context (for prioritisation only);
 *  - a COMPACT list of current pantry items with their key per-100(g/ml)
 *    nutrients, so an insight can be pantry-aware (§9.7 — the differentiator).
 *
 * ## No raw history (brief §9.8)
 * This object carries ONLY the computed summary + current pantry. It never
 * contains raw consumption events / items — the brief is explicit that a huge
 * raw history must not be handed to the LLM to total up. Everything numeric here
 * was produced by the deterministic analytics engine.
 *
 * This is a pure DTO — no framework, no facades, no provider types.
 */
final class DietInsightContext
{
    /**
     * @param  array<string, mixed>  $weekly  the NutritionAnalyticsService::weeklySummary output.
     * @param  array<int, array<string, mixed>>  $pantry  compact pantry entries:
     *                                                    `{id, name, category, quantity, unit, per_100g: array<string,float|null>}`.
     * @param  array<string, mixed>  $profile  goal + dietary context.
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly array $weekly,
        public readonly array $pantry,
        public readonly array $profile,
    ) {}

    /** Whether the week has any logged consumption to interpret. */
    public function hasData(): bool
    {
        return (bool) ($this->weekly['has_data'] ?? false);
    }

    /**
     * The whole context as a plain array — used both as the LLM input payload
     * and as the `structured_inputs` audit column on the persisted insight, so an
     * insight is always explainable from exactly what fed it (brief §9.8).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'weekly' => $this->weekly,
            'pantry' => $this->pantry,
            'profile' => $this->profile,
        ];
    }
}
