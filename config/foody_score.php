<?php

/*
|--------------------------------------------------------------------------
| Foody Score v1 — versioned algorithm parameters
|--------------------------------------------------------------------------
| Source of truth: "Foody Score v1 — Product, scoring and insight-engine
| specification" (LOCKED V1 DIRECTION). Every constant the deterministic
| engine uses lives HERE, never scattered through UI code (spec §5).
| The LLM never reads or alters any of this; it only words verified outputs.
|
| Historical score records persist the algorithm_version that produced them
| and are never silently recalculated (spec §19).
*/

return [

    'score_version' => 'foody_score_v1',
    'target_rules_version' => 'target_rules_v1',
    'insight_engine_version' => 'insight_engine_v1',

    /*
    | Pillar weights (spec §3–§4). Baseline 20/20/20/20/20; goal profiles move
    | weights within the allowed bounds. Primary goals apply the full profile;
    | secondary goals would apply 50% (no secondary goals exist in the current
    | profile model — the engine supports the rule for when they do). After
    | adjustment, weights are normalised then clamped to bounds and
    | re-normalised.
    */
    'pillar_bounds' => [
        'energy' => [0.10, 0.30],
        'macro' => [0.15, 0.35],
        'fibre_plants' => [0.15, 0.35],
        'micronutrients' => [0.15, 0.30],
        'moderation' => [0.10, 0.25],
    ],

    /*
    | Keyed by SCORE PROFILE (PrimaryGoal::scoreProfile() maps the product's
    | eight goals onto these six). `recomp` is a documented deviation: the
    | spec's five profiles don't cover the existing Recomp goal, so it scores
    | as muscle-gain macro emphasis at maintenance-style energy.
    */
    'goal_profiles' => [
        'general_health' => ['energy' => 20, 'macro' => 20, 'fibre_plants' => 20, 'micronutrients' => 20, 'moderation' => 20],
        'fat_loss' => ['energy' => 25, 'macro' => 25, 'fibre_plants' => 17, 'micronutrients' => 17, 'moderation' => 16],
        'muscle_gain' => ['energy' => 25, 'macro' => 30, 'fibre_plants' => 15, 'micronutrients' => 15, 'moderation' => 15],
        'recomp' => ['energy' => 22, 'macro' => 28, 'fibre_plants' => 17, 'micronutrients' => 17, 'moderation' => 16],
        'performance' => ['energy' => 25, 'macro' => 30, 'fibre_plants' => 15, 'micronutrients' => 15, 'moderation' => 15],
        'gut_health' => ['energy' => 15, 'macro' => 15, 'fibre_plants' => 35, 'micronutrients' => 20, 'moderation' => 15],
    ],

    /*
    | Smooth scoring curve (spec §5): full-credit plateau [lower_full,
    | upper_full] on ratio r = actual/target; Gaussian falloff outside with
    | per-side sigmas. Component scores clamp to 0–100.
    */

    // Energy Fit (spec §7): asymmetric by goal at end of eating day.
    'energy' => [
        'general_health' => ['full' => [0.95, 1.05], 'sigma' => [0.15, 0.15]],
        'fat_loss' => ['full' => [0.90, 1.05], 'sigma' => [0.20, 0.12]],
        'muscle_gain' => ['full' => [0.95, 1.10], 'sigma' => [0.12, 0.20]],
        'recomp' => ['full' => [0.95, 1.05], 'sigma' => [0.15, 0.15]],
        'performance' => ['full' => [0.95, 1.10], 'sigma' => [0.15, 0.18]],
        'gut_health' => ['full' => [0.95, 1.05], 'sigma' => [0.15, 0.15]],
    ],

    // Macro Fit (spec §6). Per-goal full-credit ratios; default sigmas
    // 0.20/0.25, narrowed to 0.15/0.15 when an explicit manual target is set.
    'macro' => [
        'sigma_default' => [0.20, 0.25],
        'sigma_explicit' => [0.15, 0.15],
        'protein' => [
            'general_health' => [0.90, 1.15],
            'gut_health' => [0.90, 1.15],
            'muscle_gain' => [0.95, 1.20],
            'fat_loss' => [0.95, 1.20],
            'recomp' => [0.95, 1.20],
            'performance' => [0.90, 1.15],
        ],
        'carbs' => [
            'general_health' => [0.80, 1.20],
            'gut_health' => [0.80, 1.20],
            'fat_loss' => [0.80, 1.20],
            'muscle_gain' => [0.85, 1.15],
            'recomp' => [0.85, 1.15],
            'performance' => [0.90, 1.10],
        ],
        'fat' => [
            'default' => [0.75, 1.25],
            'explicit' => [0.90, 1.10], // respect intentional user precision
        ],
        // Goal-specific baseline subweights inside Macro Fit (protein/carbs/fat).
        'subweights' => [
            'general_health' => ['protein' => 0.40, 'carbs' => 0.30, 'fat' => 0.30],
            'gut_health' => ['protein' => 0.35, 'carbs' => 0.35, 'fat' => 0.30],
            'fat_loss' => ['protein' => 0.45, 'carbs' => 0.27, 'fat' => 0.28],
            'muscle_gain' => ['protein' => 0.45, 'carbs' => 0.32, 'fat' => 0.23],
            'recomp' => ['protein' => 0.47, 'carbs' => 0.28, 'fat' => 0.25],
            'performance' => ['protein' => 0.33, 'carbs' => 0.45, 'fat' => 0.22],
        ],
        // Manual macro-goal edits multiply the base subweight by the ratio of
        // the explicit target to the profile default, capped, then renormalise
        // (spec §4). Changes importance INSIDE Macro Fit only.
        'manual_subweight_ratio_cap' => [0.75, 1.35],
        // Deviation-aware influence: a macro component below this floor gets
        // its effective subweight multiplied before normalisation (spec §6).
        'deviation_floor' => 60,
        'deviation_multiplier' => 1.20,
    ],

    // Fibre & Plants / gut-support model (spec §8–§9).
    'fibre_plants' => [
        'points' => ['fibre' => 40, 'diversity' => 30, 'fruit_veg' => 20, 'bonus' => 10],
        'fibre_curve_k' => 3.5, // 100 * (1 - exp(-k*r)) / (1 - exp(-k)), capped at 100
        'plants_weekly_benchmark' => 30, // aspirational, diminishing returns above
        'herbs_spices_excluded' => true,
        // Consistency benefit for repeated plants: small, bounded, inside bonus.
        'repeat_consistency_bonus_max' => 4,
        'fermented_bonus_max' => 3,
        'breadth_bonus_max' => 3,
        'fruit_veg_daily_portions_target' => 5,
    ],

    /*
    | Micronutrient Coverage (spec §10). The core set is defined by RELIABLE
    | database coverage, audited at implementation time. With the current
    | schema (8 macro-level nutrient columns, zero micronutrient columns) the
    | core set is EMPTY: the pillar reports nutrient_coverage_confidence = 0,
    | is excluded from the denominator, and its weight is redistributed —
    | unknown is never zero (spec §1, §10). The set below is the intended
    | v1 membership once columns exist; membership changes bump
    | target_rules_version.
    */
    'micronutrients' => [
        'core_set' => [], // audited: no reliable micronutrient coverage yet
        'intended_core_set' => ['iron', 'calcium', 'potassium', 'vitamin_d', 'b12', 'folate', 'zinc', 'iodine'],
        'rolling_days' => 14,
        'min_coverage_for_scoring' => 0.60, // share of consumed food with data
        'persistent_weakness' => [
            'min_repeated_days' => 5,
            'penalty_cap' => 15,
        ],
    ],

    // Moderation (spec §11): hybrid healthy-range + upper-limit handling.
    'moderation' => [
        // Salt: adequacy range — zero is NOT ideal. Grams/day (UK guidance).
        'salt' => ['kind' => 'range', 'full' => [0.40, 1.00], 'sigma' => [0.35, 0.20], 'target_key' => 'salt'],
        // Saturated fat / free sugars: upper-limit measures. At or below the
        // limit = full credit; Gaussian falloff above. Never prompts upward.
        'saturated_fat' => ['kind' => 'limit', 'full_below' => 1.00, 'sigma_high' => 0.30, 'target_key' => 'saturated_fat'],
        'free_sugars' => ['kind' => 'limit', 'full_below' => 1.00, 'sigma_high' => 0.30, 'target_key' => 'sugars'],
        'rolling_days' => 7,
        'today_weight_reliable' => 0.40, // current-day excesses blend when reliable
    ],

    /*
    | Pillar time horizons + intraday blending (spec §12). Energy/Macro today-
    | weight rises smoothly with estimated day completion; micronutrients stay
    | rolling; plant diversity is inherently weekly.
    */
    'horizons' => [
        'energy_macro_today_weight' => ['min' => 0.15, 'max' => 0.85],
        'fibre_rolling_days' => 7,
        'micro_rolling_days' => 14,
        'moderation_rolling_days' => 7,
    ],

    /*
    | Confidence model (spec §13): three separate dimensions, never one blob.
    | Presentation gates (Home policy) — the engine always calculates.
    */
    'confidence' => [
        'firm_day_completeness' => 0.60,
        'provisional_day_completeness' => 0.25,
        'min_historical_days' => 3, // below: "Building"
        'default_meal_hours' => [8, 13, 19], // fallback schedule before learning
        'learned_schedule_min_days' => 5,
    ],

    /*
    | Score stability (spec §15): evidence-sensitive movement. New evidence
    | moves the displayed score in proportion to its confidence and share of
    | the day; large shifts need proportionately strong evidence.
    */
    'stability' => [
        'max_step_low_evidence' => 8,
        'max_step_high_evidence' => 25,
    ],

    // Internal semantic bands (spec §15). UI copy expresses them naturally;
    // sub-70 must never read as failure.
    'bands' => [
        ['min' => 90, 'key' => 'excellent'],
        ['min' => 80, 'key' => 'strong'],
        ['min' => 70, 'key' => 'steady'],
        ['min' => 55, 'key' => 'drifting'],
        ['min' => 0, 'key' => 'rebuilding'],
    ],

    /*
    | Insight engine (spec §14): candidates ranked by a composite; Home shows
    | at most 3, normally 1–2; operational actions are separate from the cap.
    */
    'insights' => [
        'max_shown' => 3,
        'normal_shown' => 2,
        'novelty_suppression_days' => 3, // recently-shown insights lose novelty
        'positive_bias' => 0.10, // slight, never manufactured around correctives
    ],
];
