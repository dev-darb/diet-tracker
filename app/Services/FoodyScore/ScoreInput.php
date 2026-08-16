<?php

namespace App\Services\FoodyScore;

use App\ValueObjects\NutrientTotal;
use App\ValueObjects\NutrientValues;
use Carbon\CarbonImmutable;

/**
 * Everything the deterministic engine needs, assembled up front. The engine
 * itself performs no I/O and never consults the clock: identical ScoreInput
 * always produces the identical ScoreResult (spec §1, acceptance #1).
 */
final class ScoreInput
{
    /**
     * @param  string  $profileKey  goal score-profile key (config goal_profiles)
     * @param  array<string, array{target: float, unit: string, direction: string, basis: string, personalised: bool, explicit?: bool}>  $targets
     * @param  array<string, array{explicit: bool, default: float}>  $macroMeta  per-macro manual-target metadata (protein/carbs/fat)
     * @param  array<string, NutrientValues>  $days  date (Y-m-d) => the KNOWN part of each day's event-level totals, most recent 14 days that have data
     * @param  array<string, NutrientTotal>  $dayTotals  the same days carrying their gaps: how many events did not state each nutrient
     * @param  array{unique_plants: int, plant_days: array<string, int>, classifiable: int, classified_plant: int, fermented: int, categories: array<int, string>, fruit_veg_lines: int, herb_spice_excluded: int}  $plants  7-day item-level plant evidence
     * @param  array<int, int>  $todayEventHours  hour-of-day of each event logged today
     * @param  float  $expectedFractionByNow  learned share of the day's intake expected by asOf (0–1)
     * @param  int  $adequatelyLoggedDays  days in the last 14 with usable data
     * @param  array<string, array<string, ?float>>  $microDays  date => [nutrient => value|null] for the configured core set (empty today)
     * @param  array<int, string>  $recentInsightKeys  reason keys surfaced in the novelty window
     * @param  NutrientValues|null  $scenario  planned-meal addition for projected scores (spec §16)
     */
    public function __construct(
        public readonly CarbonImmutable $asOf,
        public readonly string $profileKey,
        public readonly array $targets,
        public readonly array $macroMeta,
        public readonly array $days,
        public readonly array $plants,
        public readonly array $todayEventHours,
        public readonly float $expectedFractionByNow,
        public readonly int $adequatelyLoggedDays,
        public readonly array $microDays = [],
        public readonly array $recentInsightKeys = [],
        public readonly ?NutrientValues $scenario = null,
        public readonly array $dayTotals = [],
    ) {}

    public function todayKey(): string
    {
        return $this->asOf->toDateString();
    }

    /**
     * Today's total in its STRICT reading: unknown for any nutrient some event
     * failed to state.
     *
     * The pillars score {@see todayTotals()}, which is the known part — a day
     * with one unlogged coffee is still a day worth scoring. Confidence reads
     * this one instead, so the gap the pillars were allowed to look past is
     * still counted against how sure the score claims to be. Scoring a partial
     * day at full confidence would be the worst of both readings.
     */
    public function todayStrictTotals(): NutrientValues
    {
        $total = $this->dayTotals[$this->todayKey()] ?? null;

        return $total?->strict() ?? NutrientValues::unknown();
    }

    /** Today's totals with any projection scenario applied (spec §16). */
    public function todayTotals(): NutrientValues
    {
        $today = $this->days[$this->todayKey()] ?? NutrientValues::unknown();

        if ($this->scenario === null) {
            return $today;
        }

        // A planned meal adds onto a day; an unknown day starts from zero for
        // the scenario (the plan IS the evidence being examined).
        $base = ($this->days[$this->todayKey()] ?? null) !== null ? $today : NutrientValues::zero();

        return $base->add($this->scenario);
    }

    /**
     * Rolling day totals, excluding today, newest first.
     *
     * @return array<string, NutrientValues>
     */
    public function rollingDays(int $limit): array
    {
        $out = [];

        foreach ($this->days as $date => $values) {
            if ($date === $this->todayKey()) {
                continue;
            }
            $out[$date] = $values;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
