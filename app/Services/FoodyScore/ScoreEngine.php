<?php

namespace App\Services\FoodyScore;

use App\Services\FoodyScore\Support\Curves;

/**
 * Foody Score v1 — the deterministic engine (spec §3–§16).
 *
 * Pure computation over an assembled ScoreInput: no I/O, no clock, no
 * randomness, no LLM. Identical input + identical config version always
 * produces the identical ScoreResult. The LLM never calculates, adjusts or
 * overrides anything here — it only words the structured output.
 *
 * Pillars: Energy Fit, Macro Fit, Fibre & Plants, Micronutrient Coverage,
 * Moderation. A pillar without adequate data reports its confidence and is
 * excluded from the weighted denominator — missing data is never zero and
 * never a behavioural judgement (spec §1, §13).
 */
class ScoreEngine
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('foody_score');
    }

    public function calculate(ScoreInput $input): ScoreResult
    {
        $reasonCodes = [];
        $candidates = [];

        $pillars = [
            'energy' => $this->energyFit($input, $reasonCodes, $candidates),
            'macro' => $this->macroFit($input, $reasonCodes, $candidates),
            'fibre_plants' => $this->fibrePlants($input, $reasonCodes, $candidates),
            'micronutrients' => $this->micronutrients($input, $reasonCodes, $candidates),
            'moderation' => $this->moderation($input, $reasonCodes, $candidates),
        ];

        $weights = $this->pillarWeights($input->profileKey);

        // Exclude pillars without evidence from the denominator and
        // redistribute their weight proportionally (spec §10, §13).
        $scored = array_filter($pillars, fn ($p) => $p['score'] !== null);
        $totalWeight = array_sum(array_intersect_key($weights, $scored));

        $score = 0.0;
        foreach ($scored as $key => $pillar) {
            $share = $totalWeight > 0 ? $weights[$key] / $totalWeight : 0;
            $pillars[$key]['weight'] = round($share, 4);
            $score += $share * $pillar['score'];
        }
        foreach ($pillars as $key => $pillar) {
            if ($pillar['score'] === null) {
                $pillars[$key]['weight'] = 0.0;
            }
        }

        $score = (int) round(Curves::clamp($score));

        $confidence = $this->confidence($input, $pillars);
        $displayState = $this->displayState($input, $confidence);
        $band = $this->band($score);

        if ($displayState === 'building') {
            $reasonCodes[] = 'confidence.building_history';
        } elseif ($displayState === 'provisional') {
            $reasonCodes[] = 'confidence.day_incomplete';
        }

        return new ScoreResult(
            score: $score,
            band: $band,
            displayState: $displayState,
            pillars: $pillars,
            confidence: $confidence,
            reasonCodes: array_values(array_unique($reasonCodes)),
            contributors: $this->contributors($pillars),
            candidates: $this->rankCandidates($candidates, $input),
            algorithmVersion: $this->config['score_version'],
            targetRulesVersion: $this->config['target_rules_version'],
        );
    }

    /* ---------------------------------------------------------------------
     * Pillar weights (spec §3–§4)
     * ------------------------------------------------------------------ */

    /** @return array<string, float> normalised, bound-clamped weights */
    public function pillarWeights(string $profileKey): array
    {
        $profile = $this->config['goal_profiles'][$profileKey]
            ?? $this->config['goal_profiles']['general_health'];

        $weights = array_map(fn ($w) => $w / 100, $profile);

        // Clamp to permitted bounds, then renormalise (spec §3).
        foreach ($this->config['pillar_bounds'] as $key => [$min, $max]) {
            $weights[$key] = max($min, min($max, $weights[$key] ?? $min));
        }

        $sum = array_sum($weights);

        return array_map(fn ($w) => $w / $sum, $weights);
    }

    /* ---------------------------------------------------------------------
     * Energy Fit (spec §7, §12)
     * ------------------------------------------------------------------ */

    /** @return array{score: ?float, weight: float, confidence: float, components: array<string, mixed>} */
    private function energyFit(ScoreInput $input, array &$reasons, array &$candidates): array
    {
        $target = $input->targets['calories']['target'] ?? null;
        $curve = $this->config['energy'][$input->profileKey] ?? $this->config['energy']['general_health'];

        $today = $input->todayTotals()->calories;
        $todayScore = null;
        $ratio = null;

        if ($target !== null && $target > 0 && $today !== null) {
            // Intraday: judge against expected-to-date intake, never against
            // 100% of the daily target (spec §7). Missing logs are not
            // missing food.
            $fraction = max($input->expectedFractionByNow, 0.05);
            $expected = $target * $fraction;
            $ratio = $expected > 0 ? $today / $expected : null;

            if ($ratio !== null) {
                $todayScore = Curves::plateau($ratio, $curve['full'][0], $curve['full'][1], $curve['sigma'][0], $curve['sigma'][1]);
            }
        }

        // Rolling anchor: end-of-day scores for recent complete days.
        $rollingScores = [];
        foreach ($input->rollingDays(7) as $values) {
            if ($values->calories !== null && $target !== null && $target > 0) {
                $rollingScores[] = Curves::plateau($values->calories / $target, $curve['full'][0], $curve['full'][1], $curve['sigma'][0], $curve['sigma'][1]);
            }
        }
        $anchor = $rollingScores !== [] ? array_sum($rollingScores) / count($rollingScores) : null;

        [$min, $max] = array_values($this->config['horizons']['energy_macro_today_weight']);
        $todayWeight = $min + ($max - $min) * Curves::smoothstep(0.0, 1.0, $input->expectedFractionByNow);

        $score = $this->blend($todayScore, $anchor, $todayWeight);

        if ($score === null) {
            return $this->emptyPillar();
        }

        if ($todayScore !== null && $ratio !== null) {
            if ($ratio > $curve['full'][1] + 0.15) {
                $reasons[] = 'energy.over_expected';
                $candidates[] = $this->candidate('energy_over', 'energy.over_expected', 'corrective',
                    importance: min(1.0, ($ratio - $curve['full'][1])),
                    confidence: $input->expectedFractionByNow,
                    actionability: 0.6, timingFit: 0.7,
                    data: ['ratio' => round($ratio, 2), 'today_kcal' => $today, 'target' => $target]);
            } elseif ($ratio >= $curve['full'][0]) {
                $reasons[] = 'energy.on_track';
            }
        }

        return [
            'score' => round($score, 1),
            'weight' => 0.0,
            'confidence' => round($input->expectedFractionByNow, 2),
            'components' => [
                'today' => ['score' => $todayScore !== null ? round($todayScore, 1) : null, 'value' => $today, 'target' => $target, 'ratio' => $ratio !== null ? round($ratio, 3) : null],
                'rolling' => ['score' => $anchor !== null ? round($anchor, 1) : null, 'value' => null, 'target' => $target, 'ratio' => null],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     * Macro Fit (spec §6): protein, carbohydrate and fat — all first-class.
     * ------------------------------------------------------------------ */

    private function macroFit(ScoreInput $input, array &$reasons, array &$candidates): array
    {
        $cfg = $this->config['macro'];
        $today = $input->todayTotals();
        $profileKey = $input->profileKey;

        [$min, $max] = array_values($this->config['horizons']['energy_macro_today_weight']);
        $todayWeight = $min + ($max - $min) * Curves::smoothstep(0.0, 1.0, $input->expectedFractionByNow);
        $fraction = max($input->expectedFractionByNow, 0.05);

        $components = [];
        $componentScores = [];

        foreach (['protein', 'carbs', 'fat'] as $macro) {
            $target = $input->targets[$macro]['target'] ?? null;
            $explicit = (bool) ($input->macroMeta[$macro]['explicit'] ?? false);

            if ($macro === 'fat') {
                $full = $explicit ? $cfg['fat']['explicit'] : $cfg['fat']['default'];
            } else {
                $full = $cfg[$macro][$profileKey] ?? $cfg[$macro]['general_health'];
            }

            $sigma = $explicit ? $cfg['sigma_explicit'] : $cfg['sigma_default'];

            $value = $today->get($macro);
            $todayScore = null;
            $ratio = null;

            if ($target !== null && $target > 0 && $value !== null) {
                $ratio = $value / ($target * $fraction);
                $todayScore = Curves::plateau($ratio, $full[0], $full[1], $sigma[0], $sigma[1]);
            }

            $rolling = [];
            foreach ($input->rollingDays(7) as $values) {
                $v = $values->get($macro);
                if ($v !== null && $target !== null && $target > 0) {
                    $rolling[] = Curves::plateau($v / $target, $full[0], $full[1], $sigma[0], $sigma[1]);
                }
            }
            $anchor = $rolling !== [] ? array_sum($rolling) / count($rolling) : null;

            $score = $this->blend($todayScore, $anchor, $todayWeight);

            $components[$macro] = [
                'score' => $score !== null ? round($score, 1) : null,
                'value' => $value,
                'target' => $target,
                'ratio' => $ratio !== null ? round($ratio, 3) : null,
            ];

            if ($score !== null) {
                $componentScores[$macro] = $score;
            }
        }

        if ($componentScores === []) {
            return $this->emptyPillar();
        }

        // Subweights: goal baseline × manual-target ratio (capped) →
        // deviation-aware boost → normalise (spec §4, §6).
        $sub = $cfg['subweights'][$profileKey] ?? $cfg['subweights']['general_health'];
        [$capLow, $capHigh] = $cfg['manual_subweight_ratio_cap'];

        foreach ($sub as $macro => $w) {
            $meta = $input->macroMeta[$macro] ?? null;
            if ($meta !== null && $meta['explicit'] && ($meta['default'] ?? 0) > 0 && ($input->targets[$macro]['target'] ?? 0) > 0) {
                $ratio = $input->targets[$macro]['target'] / $meta['default'];
                $sub[$macro] = $w * max($capLow, min($capHigh, $ratio));
            }
        }

        foreach ($sub as $macro => $w) {
            if (($componentScores[$macro] ?? 100) < $cfg['deviation_floor']) {
                $sub[$macro] = $w * $cfg['deviation_multiplier'];
            }
        }

        $sub = array_intersect_key($sub, $componentScores);
        $subSum = array_sum($sub);

        $score = 0.0;
        foreach ($componentScores as $macro => $componentScore) {
            $score += ($sub[$macro] / $subSum) * $componentScore;
        }

        foreach ($componentScores as $macro => $componentScore) {
            if ($componentScore < 60 && ($components[$macro]['ratio'] ?? 1.0) < 1.0) {
                $reasons[] = "macro.{$macro}_low";
                $candidates[] = $this->candidate("{$macro}_low", "macro.{$macro}_low", 'corrective',
                    importance: min(1.0, (60 - $componentScore) / 60 + 0.3),
                    confidence: $input->expectedFractionByNow,
                    actionability: $macro === 'protein' ? 0.9 : 0.7, timingFit: 0.8,
                    data: ['value' => $components[$macro]['value'], 'target' => $components[$macro]['target']]);
            } elseif ($componentScore >= 95) {
                $reasons[] = "macro.{$macro}_on_target";
            }
        }

        return [
            'score' => round($score, 1),
            'weight' => 0.0,
            'confidence' => round($input->expectedFractionByNow, 2),
            'components' => $components,
        ];
    }

    /* ---------------------------------------------------------------------
     * Fibre & Plants (spec §8–§9): supports gut health; never claims to
     * measure the microbiome.
     * ------------------------------------------------------------------ */

    private function fibrePlants(ScoreInput $input, array &$reasons, array &$candidates): array
    {
        $cfg = $this->config['fibre_plants'];
        $points = $cfg['points'];
        $target = $input->targets['fibre']['target'] ?? null;

        // Fibre adequacy: today + rolling week, saturating curve (spec §9).
        $todayFibre = $input->todayTotals()->fibre;
        $rollingFibre = [];
        foreach ($input->rollingDays(7) as $values) {
            if ($values->fibre !== null) {
                $rollingFibre[] = $values->fibre;
            }
        }

        $fibreScore = null;
        if ($target !== null && $target > 0) {
            $todayScore = $todayFibre !== null ? Curves::fibreSaturation($todayFibre / $target, $cfg['fibre_curve_k']) : null;
            $rollingScore = $rollingFibre !== []
                ? Curves::fibreSaturation((array_sum($rollingFibre) / count($rollingFibre)) / $target, $cfg['fibre_curve_k'])
                : null;
            $fibreScore = $this->blend($todayScore, $rollingScore, 0.4);
        }

        // Plant diversity: weekly unique plants vs the aspirational benchmark
        // (spec §8) — herbs/spices excluded, one credit per plant. Coverage-
        // gated: with too little classifiable data the component is unknown.
        $plants = $input->plants;
        $classifiableShare = $plants['classifiable'] > 0
            ? $plants['classified_plant'] / max(1, $plants['classifiable'])
            : 0;
        $hasPlantData = $plants['classifiable'] >= 3;

        $diversityScore = $hasPlantData
            ? Curves::benchmarkProgress((float) $plants['unique_plants'], (float) $cfg['plants_weekly_benchmark'])
            : null;

        // Fruit & veg adequacy — separate from diversity (spec §8).
        $fruitVegScore = $hasPlantData
            ? Curves::clamp(100 * min(1.0, ($plants['fruit_veg_lines'] / 7.0) / $cfg['fruit_veg_daily_portions_target']))
            : null;

        // Bonus pool (≤10): consistency, breadth, fermented (spec §8).
        $bonus = 0.0;
        if ($hasPlantData) {
            $repeatedPlants = count(array_filter($plants['plant_days'], fn ($days) => $days >= 3));
            $bonus += min($cfg['repeat_consistency_bonus_max'], $repeatedPlants);
            $bonus += min($cfg['breadth_bonus_max'], max(0, count(array_unique($plants['categories'])) - 1));
            $bonus += min($cfg['fermented_bonus_max'], $plants['fermented']);
        }

        // Redistribute unknown components' points; cap at 100 (spec §8).
        $earned = 0.0;
        $available = 0.0;
        foreach (['fibre' => $fibreScore, 'diversity' => $diversityScore, 'fruit_veg' => $fruitVegScore] as $key => $componentScore) {
            if ($componentScore !== null) {
                $earned += $points[$key] * ($componentScore / 100);
                $available += $points[$key];
            }
        }

        if ($available <= 0) {
            return $this->emptyPillar();
        }

        $score = Curves::clamp(($earned / $available) * 90 + min($points['bonus'], $bonus));

        if ($fibreScore !== null && $fibreScore < 60) {
            $reasons[] = 'fibre.low';
            $candidates[] = $this->candidate('fibre_low', 'fibre.low', 'corrective',
                importance: 0.8, confidence: 0.8, actionability: 0.9, timingFit: 0.8,
                data: ['today' => $todayFibre, 'target' => $target]);
        }
        if ($diversityScore !== null) {
            if ($plants['unique_plants'] >= $cfg['plants_weekly_benchmark']) {
                $reasons[] = 'plants.diversity_strong';
                $candidates[] = $this->candidate('plants_strong', 'plants.diversity_strong', 'positive',
                    importance: 0.5, confidence: 0.8, actionability: 0.3, timingFit: 0.5,
                    data: ['unique_plants' => $plants['unique_plants']]);
            } elseif ($diversityScore < 50) {
                $reasons[] = 'plants.diversity_low';
                $candidates[] = $this->candidate('plants_low', 'plants.diversity_low', 'corrective',
                    importance: 0.6, confidence: 0.7, actionability: 0.8, timingFit: 0.6,
                    data: ['unique_plants' => $plants['unique_plants'], 'benchmark' => $cfg['plants_weekly_benchmark']]);
            }
        } else {
            $reasons[] = 'plants.coverage_insufficient';
        }

        return [
            'score' => round($score, 1),
            'weight' => 0.0,
            'confidence' => round(min(1.0, ($available / 90) * (0.5 + 0.5 * $classifiableShare)), 2),
            'components' => [
                'fibre' => ['score' => $fibreScore !== null ? round($fibreScore, 1) : null, 'value' => $todayFibre, 'target' => $target, 'ratio' => null],
                'diversity' => ['score' => $diversityScore !== null ? round($diversityScore, 1) : null, 'value' => (float) $plants['unique_plants'], 'target' => (float) $cfg['plants_weekly_benchmark'], 'ratio' => null],
                'fruit_veg' => ['score' => $fruitVegScore !== null ? round($fruitVegScore, 1) : null, 'value' => round($plants['fruit_veg_lines'] / 7.0, 1), 'target' => (float) $cfg['fruit_veg_daily_portions_target'], 'ratio' => null],
                'bonus' => ['score' => round(min($points['bonus'], $bonus), 1), 'value' => null, 'target' => (float) $points['bonus'], 'ratio' => null],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     * Micronutrient Coverage (spec §10) — coverage-gated; the audited core
     * set is empty today, so this pillar is excluded until real columns
     * exist. The scoring path below is live for the day they do.
     * ------------------------------------------------------------------ */

    private function micronutrients(ScoreInput $input, array &$reasons, array &$candidates): array
    {
        $cfg = $this->config['micronutrients'];
        $coreSet = $cfg['core_set'];

        if ($coreSet === [] || $input->microDays === []) {
            $reasons[] = 'micros.coverage_insufficient';

            return $this->emptyPillar();
        }

        $scored = [];
        $penalty = 0.0;

        foreach ($coreSet as $nutrient => $meta) {
            $nutrientKey = is_array($meta) ? $nutrient : $meta;
            $rollingTarget = is_array($meta) ? ($meta['target'] ?? null) : null;

            if ($rollingTarget === null || $rollingTarget <= 0) {
                continue;
            }

            // Rolling adequacy: mean over days WITH data only — null days are
            // excluded from the denominator, never counted as zero (spec §10).
            $values = [];
            foreach ($input->microDays as $day) {
                if (($day[$nutrientKey] ?? null) !== null) {
                    $values[] = $day[$nutrientKey];
                }
            }

            $coverage = count($input->microDays) > 0 ? count($values) / count($input->microDays) : 0;

            if ($coverage < $cfg['min_coverage_for_scoring']) {
                continue; // insufficient data → excluded from the denominator
            }

            $ratio = (array_sum($values) / count($values)) / $rollingTarget;
            $scored[$nutrientKey] = Curves::fibreSaturation($ratio, 3.0);

            // Persistent-weakness penalty: repeated AND well-covered lows only.
            $lowDays = count(array_filter($values, fn ($v) => $v < 0.6 * $rollingTarget));
            if ($lowDays >= $cfg['persistent_weakness']['min_repeated_days'] && $scored[$nutrientKey] < 55) {
                $penalty += min(5.0, (55 - $scored[$nutrientKey]) / 10);
                $reasons[] = "micros.{$nutrientKey}_persistently_low";
            }
        }

        if ($scored === []) {
            $reasons[] = 'micros.coverage_insufficient';

            return $this->emptyPillar();
        }

        $penalty = min($cfg['persistent_weakness']['penalty_cap'], $penalty);
        $score = Curves::clamp((array_sum($scored) / count($scored)) - $penalty);

        return [
            'score' => round($score, 1),
            'weight' => 0.0,
            'confidence' => round(count($scored) / max(1, count($coreSet)), 2),
            'components' => array_map(fn ($s) => ['score' => round($s, 1), 'value' => null, 'target' => null, 'ratio' => null], $scored)
                + ['penalty' => ['score' => -round($penalty, 1), 'value' => null, 'target' => null, 'ratio' => null]],
        ];
    }

    /* ---------------------------------------------------------------------
     * Moderation (spec §11): healthy-range salt, upper-limit sat fat and
     * sugars. Zero salt is not ideal; nothing prompts consuming more of a
     * capped nutrient; no food carries a morality deduction.
     * ------------------------------------------------------------------ */

    private function moderation(ScoreInput $input, array &$reasons, array &$candidates): array
    {
        $cfg = $this->config['moderation'];
        $today = $input->todayTotals();
        $components = [];
        $scores = [];

        foreach (['salt', 'saturated_fat', 'free_sugars'] as $key) {
            $rule = $cfg[$key];
            $target = $input->targets[$rule['target_key']]['target'] ?? null;

            if ($target === null || $target <= 0) {
                continue;
            }

            $column = $rule['target_key'] === 'sugars' ? 'sugars' : $rule['target_key'];

            // Rolling pattern dominates (spec §11: persistent patterns matter
            // more than a single treat).
            $rolling = [];
            foreach ($input->rollingDays($cfg['rolling_days']) as $values) {
                $v = $values->get($column);
                if ($v !== null) {
                    $rolling[] = $v;
                }
            }

            $todayValue = $today->get($column);

            $scoreOf = function (float $value) use ($rule, $target): float {
                $ratio = $value / $target;

                return $rule['kind'] === 'range'
                    ? Curves::plateau($ratio, $rule['full'][0], $rule['full'][1], $rule['sigma'][0], $rule['sigma'][1])
                    : Curves::upperLimit($ratio, $rule['full_below'], $rule['sigma_high']);
            };

            $rollingScore = $rolling !== [] ? $scoreOf(array_sum($rolling) / count($rolling)) : null;
            $todayScore = $todayValue !== null ? $scoreOf($todayValue) : null;

            $score = $this->blend($todayScore, $rollingScore, $cfg['today_weight_reliable']);

            if ($score === null) {
                $components[$key] = ['score' => null, 'value' => $todayValue, 'target' => $target, 'ratio' => null];

                continue;
            }

            $components[$key] = [
                'score' => round($score, 1),
                'value' => $todayValue,
                'target' => $target,
                'ratio' => $todayValue !== null ? round($todayValue / $target, 3) : null,
            ];
            $scores[$key] = $score;

            if ($rollingScore !== null && $rollingScore < 60 && $rolling !== []) {
                $mean = array_sum($rolling) / count($rolling);
                if ($rule['kind'] === 'limit' || $mean > $target) {
                    $reasons[] = "moderation.{$key}_high_pattern";
                    $candidates[] = $this->candidate("{$key}_high", "moderation.{$key}_high_pattern", 'corrective',
                        importance: 0.7, confidence: min(1.0, count($rolling) / 5),
                        actionability: 0.6, timingFit: 0.5,
                        data: ['rolling_avg' => round($mean, 1), 'target' => $target]);
                }
            }
        }

        if ($scores === []) {
            return $this->emptyPillar();
        }

        return [
            'score' => round(array_sum($scores) / count($scores), 1),
            'weight' => 0.0,
            'confidence' => round(count($scores) / 3, 2),
            'components' => $components,
        ];
    }

    /* ---------------------------------------------------------------------
     * Confidence, display state, bands, contributors (spec §13, §15, §19)
     * ------------------------------------------------------------------ */

    /** @return array{day_completeness: float, nutrient_coverage: float, historical: float} */
    private function confidence(ScoreInput $input, array $pillars): array
    {
        // Coverage is read from the STRICT total, not the one the pillars scored.
        // The pillars are allowed to work from the known part of a day so a
        // single unlogged item does not black out six recorded meals — but the
        // gap it looked past has to land somewhere, and it lands here. A partial
        // day scores; it just does not claim to be a certain score.
        $known = 0;
        $total = 0;
        foreach ($input->todayStrictTotals()->toArray() as $value) {
            $total++;
            if ($value !== null) {
                $known++;
            }
        }

        return [
            'day_completeness' => round(min(1.0, $input->expectedFractionByNow + ($input->todayEventHours !== [] ? 0.1 : 0.0)), 2),
            'nutrient_coverage' => round($total > 0 ? $known / $total : 0, 2),
            'historical' => round(min(1.0, $input->adequatelyLoggedDays / max(1, $this->config['confidence']['min_historical_days'] * 2)), 2),
        ];
    }

    private function displayState(ScoreInput $input, array $confidence): string
    {
        if ($input->adequatelyLoggedDays < $this->config['confidence']['min_historical_days']) {
            return 'building';
        }

        if ($confidence['day_completeness'] < $this->config['confidence']['firm_day_completeness']) {
            return 'provisional';
        }

        return 'firm';
    }

    public function band(int $score): string
    {
        foreach ($this->config['bands'] as $band) {
            if ($score >= $band['min']) {
                return $band['key'];
            }
        }

        return 'rebuilding';
    }

    /** @return array{up: array<int, string>, down: array<int, string>, largest_delta: ?string} */
    private function contributors(array $pillars): array
    {
        $up = [];
        $down = [];
        $largest = null;
        $largestMagnitude = 0.0;

        foreach ($pillars as $key => $pillar) {
            if ($pillar['score'] === null) {
                continue;
            }

            if ($pillar['score'] >= 85) {
                $up[] = $key;
            } elseif ($pillar['score'] <= 55) {
                $down[] = $key;
            }

            $magnitude = abs($pillar['weight'] * ($pillar['score'] - 70));
            if ($magnitude > $largestMagnitude) {
                $largestMagnitude = $magnitude;
                $largest = $key;
            }
        }

        return ['up' => $up, 'down' => $down, 'largest_delta' => $largest];
    }

    /* ---------------------------------------------------------------------
     * Candidate insights (spec §14): deterministic candidates with reason
     * codes; priority = importance × confidence × actionability × timing ×
     * novelty. The LLM only ever sees the ranked survivors.
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function candidate(string $key, string $reasonCode, string $kind, float $importance, float $confidence, float $actionability, float $timingFit, array $data): array
    {
        return [
            'key' => $key,
            'reason_code' => $reasonCode,
            'kind' => $kind, // corrective | positive | informational
            'importance' => round(min(1.0, $importance), 3),
            'confidence' => round(min(1.0, $confidence), 3),
            'actionability' => $actionability,
            'timing_fit' => $timingFit,
            'novelty' => 1.0,
            'priority' => 0.0,
            'data' => $data,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rankCandidates(array $candidates, ScoreInput $input): array
    {
        $bias = $this->config['insights']['positive_bias'];

        foreach ($candidates as &$candidate) {
            if (in_array($candidate['key'], $input->recentInsightKeys, true)) {
                $candidate['novelty'] = 0.35; // recently shown → mostly spent
            }

            $kindBias = $candidate['kind'] === 'positive' ? (1 + $bias) : 1.0;

            $candidate['priority'] = round(
                $candidate['importance'] * $candidate['confidence'] * $candidate['actionability']
                * $candidate['timing_fit'] * $candidate['novelty'] * $kindBias,
                4
            );
        }
        unset($candidate);

        usort($candidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice($candidates, 0, 6); // the strongest few; display caps apply later
    }

    /* ------------------------------------------------------------------ */

    private function blend(?float $today, ?float $rolling, float $todayWeight): ?float
    {
        if ($today === null && $rolling === null) {
            return null;
        }
        if ($today === null) {
            return $rolling;
        }
        if ($rolling === null) {
            return $today;
        }

        return $todayWeight * $today + (1 - $todayWeight) * $rolling;
    }

    /** @return array{score: null, weight: float, confidence: float, components: array} */
    private function emptyPillar(): array
    {
        return ['score' => null, 'weight' => 0.0, 'confidence' => 0.0, 'components' => []];
    }
}
