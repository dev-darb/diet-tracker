<?php

namespace Tests\Unit;

use App\Services\FoodyScore\ScoreEngine;
use App\Services\FoodyScore\ScoreInput;
use App\Services\FoodyScore\Support\Curves;
use App\ValueObjects\NutrientValues;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Foody Score v1 — deterministic engine tests (spec Phase 5).
 *
 * The engine is pure: these tests hand it a fully-assembled ScoreInput and
 * the versioned config file directly, with no framework, database or clock.
 * Anything the engine does that these tests pin down can only change by
 * bumping the algorithm version.
 */
class FoodyScoreEngineTest extends TestCase
{
    private array $config;

    private ScoreEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = require __DIR__.'/../../config/foody_score.php';
        $this->engine = new ScoreEngine($this->config);
    }

    /* ------------------------------------------------------------------ */
    /* Input scaffolding                                                   */
    /* ------------------------------------------------------------------ */

    private function targets(array $overrides = []): array
    {
        return array_replace_recursive([
            'calories' => ['target' => 2000.0, 'unit' => 'kcal', 'direction' => 'target', 'label' => 'Calories', 'basis' => 'test', 'personalised' => true],
            'protein' => ['target' => 120.0, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Protein', 'basis' => 'test', 'personalised' => true],
            'carbs' => ['target' => 250.0, 'unit' => 'g', 'direction' => 'target', 'label' => 'Carbs', 'basis' => 'test', 'personalised' => true],
            'fat' => ['target' => 67.0, 'unit' => 'g', 'direction' => 'target', 'label' => 'Fat', 'basis' => 'test', 'personalised' => true],
            'fibre' => ['target' => 30.0, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Fibre', 'basis' => 'test', 'personalised' => false],
            'sugars' => ['target' => 90.0, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Sugars', 'basis' => 'test', 'personalised' => false],
            'saturated_fat' => ['target' => 20.0, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Saturated fat', 'basis' => 'test', 'personalised' => false],
            'salt' => ['target' => 6.0, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Salt', 'basis' => 'test', 'personalised' => false],
            'fruit_veg' => ['target' => 5.0, 'unit' => 'portions', 'direction' => 'higher', 'label' => 'Fruit & veg', 'basis' => 'test', 'personalised' => false],
        ], $overrides);
    }

    /** A day at the given fraction of every target, all nutrients known. */
    private function dayAt(float $f): NutrientValues
    {
        return NutrientValues::fromArray([
            'calories' => 2000 * $f, 'protein' => 120 * $f, 'carbs' => 250 * $f, 'sugars' => 40 * $f,
            'fat' => 67 * $f, 'saturated_fat' => 14 * $f, 'fibre' => 30 * $f, 'salt' => 4.2 * $f,
        ]);
    }

    private function goodPlants(): array
    {
        return [
            'unique_plants' => 20, 'plant_days' => ['1' => 4, '2' => 3, '3' => 1],
            'classifiable' => 25, 'classified_plant' => 18, 'fermented' => 1,
            'categories' => ['fruit', 'vegetables', 'wholegrains'], 'fruit_veg_lines' => 22,
            'herb_spice_excluded' => 0,
        ];
    }

    private function noPlants(): array
    {
        return [
            'unique_plants' => 0, 'plant_days' => [], 'classifiable' => 0, 'classified_plant' => 0,
            'fermented' => 0, 'categories' => [], 'fruit_veg_lines' => 0, 'herb_spice_excluded' => 0,
        ];
    }

    private function input(array $overrides = []): ScoreInput
    {
        $asOf = $overrides['asOf'] ?? CarbonImmutable::parse('2026-08-15 20:00:00');

        $days = $overrides['days'] ?? null;
        if ($days === null) {
            $days = [$asOf->toDateString() => $this->dayAt(0.95)];
            for ($i = 1; $i <= 7; $i++) {
                $days[$asOf->subDays($i)->toDateString()] = $this->dayAt(1.0);
            }
        }

        return new ScoreInput(
            asOf: $asOf,
            profileKey: $overrides['profileKey'] ?? 'general_health',
            targets: $overrides['targets'] ?? $this->targets(),
            macroMeta: $overrides['macroMeta'] ?? [
                'protein' => ['explicit' => false, 'default' => 120.0],
                'carbs' => ['explicit' => false, 'default' => 250.0],
                'fat' => ['explicit' => false, 'default' => 67.0],
            ],
            days: $days,
            plants: $overrides['plants'] ?? $this->goodPlants(),
            todayEventHours: $overrides['todayEventHours'] ?? [8, 13, 19],
            expectedFractionByNow: $overrides['expectedFractionByNow'] ?? 0.95,
            adequatelyLoggedDays: $overrides['adequatelyLoggedDays'] ?? 7,
            microDays: $overrides['microDays'] ?? [],
            recentInsightKeys: $overrides['recentInsightKeys'] ?? [],
            scenario: $overrides['scenario'] ?? null,
        );
    }

    /* ------------------------------------------------------------------ */
    /* 1. Identical input → identical score                               */
    /* ------------------------------------------------------------------ */

    public function test_identical_input_produces_identical_result(): void
    {
        $input = $this->input();

        $first = $this->engine->calculate($input);
        $second = $this->engine->calculate($input);
        $third = (new ScoreEngine($this->config))->calculate($input);

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($first->toArray(), $third->toArray());
    }

    /* ------------------------------------------------------------------ */
    /* 2. Algorithm-version stamping                                      */
    /* ------------------------------------------------------------------ */

    public function test_result_carries_algorithm_and_target_rules_versions(): void
    {
        $result = $this->engine->calculate($this->input());

        $this->assertSame('foody_score_v1', $result->algorithmVersion);
        $this->assertSame($this->config['target_rules_version'], $result->targetRulesVersion);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Early-morning empty log is not a low-score judgement            */
    /* ------------------------------------------------------------------ */

    public function test_empty_morning_log_does_not_read_as_under_eating(): void
    {
        $asOf = CarbonImmutable::parse('2026-08-15 08:05:00');

        // A solid on-target week, nothing logged yet today.
        $days = [];
        for ($i = 1; $i <= 7; $i++) {
            $days[$asOf->subDays($i)->toDateString()] = $this->dayAt(1.0);
        }

        $result = $this->engine->calculate($this->input([
            'asOf' => $asOf,
            'days' => $days,
            'todayEventHours' => [],
            'expectedFractionByNow' => 0.05,
        ]));

        // The rolling anchor keeps the score high; the missing morning is a
        // confidence problem, never an intake judgement (spec §7, §12–§13).
        $this->assertGreaterThanOrEqual(85, $result->score);
        $this->assertSame('provisional', $result->displayState);
        $this->assertContains('confidence.day_incomplete', $result->reasonCodes);
        $this->assertNotContains('energy', $result->contributors['down']);

        foreach ($result->candidates as $candidate) {
            $this->assertStringNotContainsString('low', $candidate['key'], 'no under-eating insight from an empty morning');
        }
    }

    /* ------------------------------------------------------------------ */
    /* 4. Goal-specific energy asymmetry                                  */
    /* ------------------------------------------------------------------ */

    public function test_energy_asymmetry_follows_goal(): void
    {
        // Full-day evaluation of a single day, no rolling history.
        $make = fn (string $profile, float $fraction) => $this->engine->calculate($this->input([
            'profileKey' => $profile,
            'days' => [CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString() => $this->dayAt($fraction)],
            'expectedFractionByNow' => 1.0,
        ]))->pillars['energy']['score'];

        // Fat loss: overshooting hurts more than the same undershoot.
        $this->assertLessThan($make('fat_loss', 0.65), $make('fat_loss', 1.35));

        // Muscle gain: undershooting hurts more than the same overshoot.
        $this->assertLessThan($make('muscle_gain', 1.35), $make('muscle_gain', 0.65));
    }

    /* ------------------------------------------------------------------ */
    /* 5. Goal-specific macro weighting — carbs are first-class            */
    /* ------------------------------------------------------------------ */

    public function test_low_carbs_cost_more_where_the_goal_demands_them(): void
    {
        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();

        // Protein and fat on target; carbs at 40% of target.
        $day = NutrientValues::fromArray([
            'calories' => 2000.0, 'protein' => 120.0, 'carbs' => 100.0, 'sugars' => 30.0,
            'fat' => 67.0, 'saturated_fat' => 14.0, 'fibre' => 30.0, 'salt' => 4.2,
        ]);

        $macroScore = fn (string $profile) => $this->engine->calculate($this->input([
            'profileKey' => $profile,
            'days' => [$today => $day],
            'expectedFractionByNow' => 1.0,
        ]))->pillars['macro']['score'];

        // Performance weights carbs at 0.45 vs 0.30 for general health, so the
        // same carb shortfall costs a performance user more (spec §4, §6).
        $this->assertLessThan($macroScore('general_health'), $macroScore('performance'));

        $result = $this->engine->calculate($this->input([
            'profileKey' => 'performance',
            'days' => [$today => $day],
            'expectedFractionByNow' => 1.0,
        ]));
        $this->assertContains('macro.carbs_low', $result->reasonCodes);
    }

    /* ------------------------------------------------------------------ */
    /* 6. Manual macro targets: narrower band, bounded subweight shift    */
    /* ------------------------------------------------------------------ */

    public function test_explicit_macro_target_narrows_the_scoring_band(): void
    {
        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();

        // Protein at 72% of the target — outside every full band.
        $day = NutrientValues::fromArray([
            'calories' => 2000.0, 'protein' => 86.0, 'carbs' => 250.0, 'sugars' => 30.0,
            'fat' => 67.0, 'saturated_fat' => 14.0, 'fibre' => 30.0, 'salt' => 4.2,
        ]);

        $proteinScore = fn (bool $explicit) => $this->engine->calculate($this->input([
            'days' => [$today => $day],
            'expectedFractionByNow' => 1.0,
            'macroMeta' => [
                'protein' => ['explicit' => $explicit, 'default' => 120.0],
                'carbs' => ['explicit' => false, 'default' => 250.0],
                'fat' => ['explicit' => false, 'default' => 67.0],
            ],
        ]))->pillars['macro']['components']['protein']['score'];

        // An intentional, user-set target is judged with the narrower sigma:
        // the same miss scores lower (spec §4, §6).
        $this->assertLessThan($proteinScore(false), $proteinScore(true));
    }

    public function test_manual_target_subweight_shift_is_capped(): void
    {
        // Raising the protein target 3× the default must move its subweight by
        // at most the configured cap (1.35), not by 3× (spec §4).
        [$capLow, $capHigh] = $this->config['macro']['manual_subweight_ratio_cap'];
        $this->assertSame(1.35, $capHigh);
        $this->assertSame(0.75, $capLow);

        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();
        $day = NutrientValues::fromArray([
            'calories' => 2000.0, 'protein' => 60.0, 'carbs' => 250.0, 'sugars' => 30.0,
            'fat' => 67.0, 'saturated_fat' => 14.0, 'fibre' => 30.0, 'salt' => 4.2,
        ]);

        $score = fn (float $default) => $this->engine->calculate($this->input([
            'days' => [$today => $day],
            'expectedFractionByNow' => 1.0,
            'targets' => $this->targets(['protein' => ['target' => 120.0, 'explicit' => true]]),
            'macroMeta' => [
                'protein' => ['explicit' => true, 'default' => $default],
                'carbs' => ['explicit' => false, 'default' => 250.0],
                'fat' => ['explicit' => false, 'default' => 67.0],
            ],
        ]))->pillars['macro']['score'];

        // default 100 → ratio 1.2 (inside cap); default 30 → ratio 4.0 (capped
        // to 1.35). If the cap works, moving the default further changes
        // nothing once the ratio exceeds it.
        $this->assertSame($score(30.0), $score(20.0));
        $this->assertNotSame($score(100.0), $score(30.0));
    }

    /* ------------------------------------------------------------------ */
    /* 7. Fibre saturation curve                                          */
    /* ------------------------------------------------------------------ */

    public function test_fibre_saturation_has_diminishing_returns_and_caps(): void
    {
        $k = $this->config['fibre_plants']['fibre_curve_k'];

        $half = Curves::fibreSaturation(0.5, $k);
        $full = Curves::fibreSaturation(1.0, $k);
        $double = Curves::fibreSaturation(2.0, $k);

        // Concave: the first half of the target earns well over half the credit.
        $this->assertGreaterThan(70, $half);
        $this->assertEqualsWithDelta(100.0, $full, 0.01);
        // No bonus for overshooting fibre — the curve caps (spec §9).
        $this->assertEqualsWithDelta(100.0, $double, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /* 8. Plant uniqueness + repeated-plant consistency                   */
    /* ------------------------------------------------------------------ */

    public function test_plant_diversity_rewards_uniqueness_with_diminishing_returns(): void
    {
        $diversity = fn (int $unique) => $this->engine->calculate($this->input([
            'plants' => [...$this->goodPlants(), 'unique_plants' => $unique],
        ]))->pillars['fibre_plants']['components']['diversity']['score'];

        $this->assertGreaterThan($diversity(5), $diversity(15));
        $this->assertGreaterThan($diversity(15), $diversity(30));
        $this->assertEqualsWithDelta(100.0, $diversity(30), 0.01);
        // Above the benchmark the curve caps — no infinite chase (spec §8).
        $this->assertEqualsWithDelta(100.0, $diversity(45), 0.01);
    }

    public function test_repeated_plants_earn_a_small_bounded_consistency_bonus(): void
    {
        $score = fn (array $plantDays) => $this->engine->calculate($this->input([
            'plants' => [...$this->goodPlants(), 'fermented' => 0, 'plant_days' => $plantDays],
        ]))->pillars['fibre_plants']['score'];

        $noRepeats = $score(['1' => 1, '2' => 1, '3' => 1]);
        $withRepeats = $score(['1' => 5, '2' => 4, '3' => 3]);

        $this->assertGreaterThan($noRepeats, $withRepeats);
        // Bounded: the whole bonus pool is capped at 10 points (spec §8).
        $this->assertLessThanOrEqual(
            $this->config['fibre_plants']['points']['bonus'],
            $withRepeats - $noRepeats + 0.001,
        );
    }

    /* ------------------------------------------------------------------ */
    /* 9. Herbs and spices excluded from the plant count                  */
    /* ------------------------------------------------------------------ */

    public function test_herb_spice_lines_do_not_move_the_diversity_score(): void
    {
        // The assembler routes herb/spice lines into herb_spice_excluded and
        // never into unique_plants (its own feature test covers the DB path);
        // here we pin that the engine gives that counter no credit.
        $without = $this->engine->calculate($this->input([
            'plants' => [...$this->goodPlants(), 'herb_spice_excluded' => 0],
        ]));
        $with = $this->engine->calculate($this->input([
            'plants' => [...$this->goodPlants(), 'herb_spice_excluded' => 12],
        ]));

        $this->assertSame($without->pillars['fibre_plants']['score'], $with->pillars['fibre_plants']['score']);
    }

    /* ------------------------------------------------------------------ */
    /* 10. Micronutrient null handling — unknown is never zero            */
    /* ------------------------------------------------------------------ */

    public function test_missing_micronutrient_data_suppresses_the_pillar_instead_of_scoring_zero(): void
    {
        $result = $this->engine->calculate($this->input());

        $this->assertNull($result->pillars['micronutrients']['score']);
        $this->assertSame(0.0, $result->pillars['micronutrients']['weight']);
        $this->assertContains('micros.coverage_insufficient', $result->reasonCodes);

        // The suppressed pillar's weight is redistributed: the scored pillars'
        // weights sum to 1 and the overall score is untouched by the gap.
        $scoredWeight = array_sum(array_column(
            array_filter($result->pillars, fn ($p) => $p['score'] !== null),
            'weight',
        ));
        $this->assertEqualsWithDelta(1.0, $scoredWeight, 0.001);
        $this->assertGreaterThanOrEqual(90, $result->score);
    }

    public function test_sparse_micronutrient_coverage_is_excluded_not_zeroed(): void
    {
        // Even with a configured core set, a nutrient below the coverage
        // threshold is excluded from the denominator (spec §10).
        $config = $this->config;
        $config['micronutrients']['core_set'] = ['iron' => ['target' => 10.0]];
        $engine = new ScoreEngine($config);

        $microDays = [];
        for ($i = 1; $i <= 14; $i++) {
            // Only 3 of 14 days carry data: 21% coverage < the 60% gate.
            $microDays["2026-08-{$i}"] = ['iron' => $i <= 3 ? 2.0 : null];
        }

        $result = $engine->calculate($this->input(['microDays' => $microDays]));

        $this->assertNull($result->pillars['micronutrients']['score']);
        $this->assertContains('micros.coverage_insufficient', $result->reasonCodes);
    }

    /* ------------------------------------------------------------------ */
    /* 11. Persistent micronutrient weakness: penalty applied and capped  */
    /* ------------------------------------------------------------------ */

    public function test_persistent_micronutrient_weakness_penalty_is_applied_and_capped(): void
    {
        $config = $this->config;
        $config['micronutrients']['core_set'] = ['iron' => ['target' => 10.0]];

        $lowDays = [];
        $okDays = [];
        for ($i = 1; $i <= 14; $i++) {
            $lowDays[sprintf('2026-08-%02d', $i)] = ['iron' => 2.0]; // persistently low
            $okDays[sprintf('2026-08-%02d', $i)] = ['iron' => 9.5];
        }

        $engine = new ScoreEngine($config);
        $low = $engine->calculate($this->input(['microDays' => $lowDays]));
        $ok = $engine->calculate($this->input(['microDays' => $okDays]));

        $this->assertNotNull($low->pillars['micronutrients']['score']);
        $this->assertLessThan($ok->pillars['micronutrients']['score'], $low->pillars['micronutrients']['score']);
        $this->assertContains('micros.iron_persistently_low', $low->reasonCodes);

        // Penalty is visible in the components and bounded by the cap.
        $penalty = abs($low->pillars['micronutrients']['components']['penalty']['score']);
        $this->assertGreaterThan(0, $penalty);
        $this->assertLessThanOrEqual($config['micronutrients']['persistent_weakness']['penalty_cap'], $penalty);
    }

    /* ------------------------------------------------------------------ */
    /* 12. Moderation: zero salt is not ideal; limits never prompt upward */
    /* ------------------------------------------------------------------ */

    public function test_zero_salt_is_not_treated_as_ideal(): void
    {
        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();

        $saltScore = fn (float $salt) => $this->engine->calculate($this->input([
            'days' => [$today => NutrientValues::fromArray([
                'calories' => 2000.0, 'protein' => 120.0, 'carbs' => 250.0, 'sugars' => 30.0,
                'fat' => 67.0, 'saturated_fat' => 14.0, 'fibre' => 30.0, 'salt' => $salt,
            ])],
            'expectedFractionByNow' => 1.0,
        ]))->pillars['moderation']['components']['salt']['score'];

        // Salt is an adequacy RANGE: a mid-range intake beats zero (spec §11).
        $this->assertGreaterThan($saltScore(0.0), $saltScore(4.0));
        $this->assertLessThan(100.0, $saltScore(0.0));
    }

    public function test_saturated_fat_below_the_limit_is_full_credit_never_prompted_upward(): void
    {
        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();

        $satFatScore = fn (float $satFat) => $this->engine->calculate($this->input([
            'days' => [$today => NutrientValues::fromArray([
                'calories' => 2000.0, 'protein' => 120.0, 'carbs' => 250.0, 'sugars' => 30.0,
                'fat' => 67.0, 'saturated_fat' => $satFat, 'fibre' => 30.0, 'salt' => 4.0,
            ])],
            'expectedFractionByNow' => 1.0,
        ]));

        // Anywhere at or below the cap scores identically — nothing rewards
        // eating MORE saturated fat (spec §11).
        $this->assertSame(
            $satFatScore(2.0)->pillars['moderation']['components']['saturated_fat']['score'],
            $satFatScore(18.0)->pillars['moderation']['components']['saturated_fat']['score'],
        );
        $this->assertLessThan(
            $satFatScore(18.0)->pillars['moderation']['components']['saturated_fat']['score'],
            $satFatScore(40.0)->pillars['moderation']['components']['saturated_fat']['score'],
        );
    }

    /* ------------------------------------------------------------------ */
    /* 13. No food morality: only totals exist, no per-food penalties     */
    /* ------------------------------------------------------------------ */

    public function test_identical_day_totals_score_identically_regardless_of_what_was_eaten(): void
    {
        // The engine's input carries day TOTALS only — food identity never
        // reaches it, so "one pizza" cannot attract a direct penalty (spec §1).
        // Two days with the same totals — one imagined as pizza, one as a
        // grain bowl — are indistinguishable by construction.
        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();
        $totals = NutrientValues::fromArray([
            'calories' => 2100.0, 'protein' => 95.0, 'carbs' => 240.0, 'sugars' => 38.0,
            'fat' => 78.0, 'saturated_fat' => 22.0, 'fibre' => 24.0, 'salt' => 5.5,
        ]);

        $pizzaDay = $this->engine->calculate($this->input(['days' => [$today => $totals], 'expectedFractionByNow' => 1.0]));
        $bowlDay = $this->engine->calculate($this->input(['days' => [$today => $totals], 'expectedFractionByNow' => 1.0]));

        $this->assertSame($pizzaDay->toArray(), $bowlDay->toArray());

        // And no reason code ever names a food.
        foreach ($pizzaDay->reasonCodes as $code) {
            $this->assertMatchesRegularExpression(
                '/^(energy|macro|fibre|plants|micros|moderation|confidence)\./',
                $code,
                'reason codes speak in nutrients and patterns, never foods',
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 14. Planned-meal projected score                                   */
    /* ------------------------------------------------------------------ */

    public function test_scenario_projects_a_planned_meal_through_the_same_engine(): void
    {
        $asOf = CarbonImmutable::parse('2026-08-15 17:00:00');

        // Day so far: protein well behind.
        $days = [$asOf->toDateString() => NutrientValues::fromArray([
            'calories' => 1200.0, 'protein' => 40.0, 'carbs' => 160.0, 'sugars' => 25.0,
            'fat' => 40.0, 'saturated_fat' => 9.0, 'fibre' => 18.0, 'salt' => 2.5,
        ])];
        for ($i = 1; $i <= 7; $i++) {
            $days[$asOf->subDays($i)->toDateString()] = $this->dayAt(1.0);
        }

        $base = ['days' => $days, 'asOf' => $asOf, 'expectedFractionByNow' => 0.70];

        $without = $this->engine->calculate($this->input($base));
        $withMeal = $this->engine->calculate($this->input([
            ...$base,
            'scenario' => NutrientValues::fromArray([
                'calories' => 550.0, 'protein' => 45.0, 'carbs' => 45.0, 'sugars' => 5.0,
                'fat' => 18.0, 'saturated_fat' => 4.0, 'fibre' => 8.0, 'salt' => 1.2,
            ]),
        ]));

        // The protein-filling meal lifts the projected macro pillar.
        $this->assertGreaterThan(
            $without->pillars['macro']['components']['protein']['score'],
            $withMeal->pillars['macro']['components']['protein']['score'],
        );
        $this->assertGreaterThanOrEqual($without->score, $withMeal->score);
    }

    /* ------------------------------------------------------------------ */
    /* 15. Reason-code explainability                                     */
    /* ------------------------------------------------------------------ */

    public function test_explanations_derive_from_stored_reason_codes(): void
    {
        $result = $this->engine->calculate($this->input());

        // A well-logged, on-target day explains itself with positive codes…
        $this->assertContains('energy.on_track', $result->reasonCodes);
        $this->assertContains('macro.protein_on_target', $result->reasonCodes);

        // …and the contributor summary names pillars, ready for wording.
        $this->assertNotEmpty($result->contributors['up']);
        $this->assertNotNull($result->contributors['largest_delta']);

        // Every candidate insight is anchored to a reason code + data payload —
        // the LLM words these, it never invents its own claims (spec §14, §19).
        foreach ($result->candidates as $candidate) {
            $this->assertNotSame('', $candidate['reason_code']);
            $this->assertIsArray($candidate['data']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* 17. Insight cap and novelty suppression                            */
    /* ------------------------------------------------------------------ */

    public function test_candidates_are_capped_and_recently_shown_insights_lose_priority(): void
    {
        $today = CarbonImmutable::parse('2026-08-15 20:00:00')->toDateString();

        // A rough day: everything low or excessive → many candidates.
        $roughDay = NutrientValues::fromArray([
            'calories' => 3400.0, 'protein' => 30.0, 'carbs' => 120.0, 'sugars' => 160.0,
            'fat' => 150.0, 'saturated_fat' => 55.0, 'fibre' => 5.0, 'salt' => 0.2,
        ]);
        $days = [$today => $roughDay];
        for ($i = 1; $i <= 7; $i++) {
            $days[CarbonImmutable::parse($today)->subDays($i)->toDateString()] = $roughDay;
        }

        $base = ['days' => $days, 'expectedFractionByNow' => 1.0, 'plants' => $this->noPlants()];

        $fresh = $this->engine->calculate($this->input($base));
        $this->assertLessThanOrEqual(6, count($fresh->candidates));
        $this->assertNotEmpty($fresh->candidates);

        $topKey = $fresh->candidates[0]['key'];
        $suppressed = $this->engine->calculate($this->input([
            ...$base,
            'recentInsightKeys' => [$topKey],
        ]));

        $freshTop = collect($fresh->candidates)->firstWhere('key', $topKey);
        $suppressedTop = collect($suppressed->candidates)->firstWhere('key', $topKey);

        $this->assertLessThan($freshTop['priority'], $suppressedTop['priority']);
    }

    /* ------------------------------------------------------------------ */
    /* Display-state gating (spec §13, §15)                               */
    /* ------------------------------------------------------------------ */

    public function test_thin_history_reads_as_building_not_as_a_verdict(): void
    {
        $result = $this->engine->calculate($this->input(['adequatelyLoggedDays' => 1]));

        $this->assertSame('building', $result->displayState);
        $this->assertContains('confidence.building_history', $result->reasonCodes);
    }

    public function test_confidence_reports_three_separate_dimensions(): void
    {
        $confidence = $this->engine->calculate($this->input())->confidence;

        $this->assertArrayHasKey('day_completeness', $confidence);
        $this->assertArrayHasKey('nutrient_coverage', $confidence);
        $this->assertArrayHasKey('historical', $confidence);
    }
}
