<?php

namespace App\Services;

use App\Enums\PrimaryGoal;
use App\Enums\Sex;
use App\Models\User;
use App\Models\UserProfile;

/**
 * The user's daily nutrition targets — the app's definition of "good"
 * (brief §9.5; the baseline-credibility decision, Aug 2026).
 *
 * LAYERED BASELINE, deterministic and citable end to end:
 *
 *  1. UK GOVERNMENT GUIDANCE is the scaffolding — fibre, saturated fat, salt
 *     and fruit & veg are universal caps/floors (SACN / Eatwell / NHS), and the
 *     generic calorie/protein references are the fallback when the profile
 *     lacks the data to personalise.
 *  2. FORMULA PERSONALISATION on top, from the profile the user already gave
 *     us: calories via Mifflin-St Jeor BMR x activity factor, adjusted for the
 *     primary goal; protein scaled to bodyweight by goal (1.2-1.8 g/kg,
 *     the well-evidenced sports-dietetics range).
 *
 * Every target carries a human-readable `basis` — the receipt shown in the UI
 * ("NHS adult maximum", "1.8 g/kg x 82 kg for your muscle-gain goal") — and a
 * `personalised` flag. No LLM defines any number here (brief §9.9), and none
 * of this is medical advice (§9.10): formulas are general adult guidance.
 */
class NutritionTargetsService
{
    /*
    |--------------------------------------------------------------------------
    | Population guidance (the scaffolding + fallbacks)
    |--------------------------------------------------------------------------
    */

    /** UK/EU reference intake for protein — the unpersonalised floor. */
    public const PROTEIN_RI_G = 50.0;

    /** UK SACN / Eatwell fibre recommendation. */
    public const FIBRE_G = 30.0;

    /** UK Eatwell reference intake for saturated fat ("less than"). */
    public const SATURATED_FAT_CAP_G = 20.0;

    /** UK food-label reference intake for TOTAL sugars ("no more than"). */
    public const TOTAL_SUGARS_RI_G = 90.0;

    /** NHS maximum salt for adults. */
    public const SALT_CAP_G = 6.0;

    /** NHS 5-a-day. */
    public const FRUIT_VEG_PORTIONS = 5.0;

    /** NHS general adult calorie guidance, used only when we cannot personalise. */
    public const CALORIES_GENERIC_FEMALE = 2000.0;

    public const CALORIES_GENERIC_MALE = 2500.0;

    public const CALORIES_GENERIC_UNKNOWN = 2250.0; // midpoint of the NHS pair

    /*
    |--------------------------------------------------------------------------
    | Personalisation formulas (documented, deterministic)
    |--------------------------------------------------------------------------
    */

    /**
     * Mifflin-St Jeor sex constant: +5 (male), -161 (female). For other /
     * prefer-not-to-say we use the midpoint (-78) and say so in the basis —
     * a documented compromise, never a silent guess.
     */
    public const MSJ_SEX_MALE = 5.0;

    public const MSJ_SEX_FEMALE = -161.0;

    public const MSJ_SEX_MIDPOINT = -78.0;

    /**
     * Standard activity multipliers for Mifflin-St Jeor TDEE. Missing activity
     * uses the light-to-moderate midpoint 1.45, stated in the basis.
     *
     * @var array<string, float>
     */
    public const ACTIVITY_FACTORS = [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
        'very_active' => 1.9,
    ];

    public const ACTIVITY_FACTOR_DEFAULT = 1.45;

    /**
     * Goal adjustment to maintenance calories: a moderate -15% deficit for
     * weight loss, +10% surplus for muscle gain — conservative, sustainable
     * mainstream guidance; never a crash figure.
     *
     * @var array<string, float>
     */
    public const GOAL_CALORIE_FACTORS = [
        'lose_weight' => 0.85,
        'gain_muscle' => 1.10,
        'recomp' => 1.0, // recomposition trains AT maintenance; protein does the work
        'performance' => 1.05, // modest surplus to fuel training volume
        'gut_health' => 1.0,
    ];

    /**
     * Protein g per kg bodyweight by goal (sports-dietetics consensus range
     * 1.2-2.2 g/kg): 1.8 for muscle gain, 1.6 in a deficit (lean-mass
     * preservation), 1.2 as an active-adult baseline otherwise.
     *
     * @var array<string, float>
     */
    public const PROTEIN_G_PER_KG = [
        'gain_muscle' => 1.8,
        'lose_weight' => 1.6,
        'recomp' => 2.0, // upper evidence range: simultaneous muscle gain + fat loss
        'performance' => 1.4, // endurance-athlete consensus range
    ];

    /**
     * Carb/fat energy split by goal, as fractions of the calorie target
     * (4 kcal/g carbs, 9 kcal/g fat). Mainstream reference splits: ~50%
     * carbohydrate energy and ≤35% fat energy for general health (UK Eatwell),
     * carbs raised for performance fuelling, eased where a deficit
     * prioritises protein.
     *
     * @var array<string, array{carbs: float, fat: float}>
     */
    public const MACRO_ENERGY_SPLIT = [
        'default' => ['carbs' => 0.50, 'fat' => 0.30],
        'performance' => ['carbs' => 0.55, 'fat' => 0.27],
        'gain_muscle' => ['carbs' => 0.50, 'fat' => 0.27],
        'recomp' => ['carbs' => 0.45, 'fat' => 0.28],
        'lose_weight' => ['carbs' => 0.45, 'fat' => 0.30],
    ];

    public const PROTEIN_G_PER_KG_DEFAULT = 1.2;

    /** Sanity clamp for personalised figures — outside this we fall back to generic. */
    public const CALORIES_MIN = 1200.0;

    public const CALORIES_MAX = 4500.0;

    /**
     * All daily targets for a user, each with its receipt.
     *
     * @return array<string, array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}>
     */
    public function targetsFor(User $user): array
    {
        $profile = $user->profile;

        $calories = $this->withOverride($this->calories($profile), $profile?->custom_calorie_target, 'kcal');
        $protein = $this->withOverride($this->protein($profile), $profile?->custom_protein_g, 'g');

        return [
            'calories' => $calories,
            'protein' => $protein,
            'carbs' => $this->withOverride($this->energySplitTarget($profile, $calories, 'carbs', 4.0, 'Carbs'), $profile?->custom_carbs_g, 'g'),
            'fat' => $this->withOverride($this->energySplitTarget($profile, $calories, 'fat', 9.0, 'Fat'), $profile?->custom_fat_g, 'g'),
            'fibre' => [
                'target' => self::FIBRE_G, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Fibre',
                'basis' => 'UK SACN recommendation (~30 g/day)', 'personalised' => false,
            ],
            // Documented deviation from the Foody Score spec's "free sugars":
            // the schema tracks TOTAL sugars only, so moderation scores against
            // the UK label reference intake for total sugars (90 g/day) rather
            // than fabricating a free-sugars figure we don't have.
            'sugars' => [
                'target' => self::TOTAL_SUGARS_RI_G, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Sugars',
                'basis' => 'UK label reference intake (90 g/day total sugars)', 'personalised' => false,
            ],
            'saturated_fat' => [
                'target' => self::SATURATED_FAT_CAP_G, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Saturated fat',
                'basis' => 'UK Eatwell reference intake (less than 20 g/day)', 'personalised' => false,
            ],
            'salt' => [
                'target' => self::SALT_CAP_G, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Salt',
                'basis' => 'NHS adult maximum (6 g/day)', 'personalised' => false,
            ],
            'fruit_veg' => [
                'target' => self::FRUIT_VEG_PORTIONS, 'unit' => 'portions', 'direction' => 'higher', 'label' => 'Fruit & veg',
                'basis' => 'NHS 5-a-day', 'personalised' => false,
            ],
        ];
    }

    /**
     * @return array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}
     */
    private function calories(?UserProfile $profile): array
    {
        $generic = function (?Sex $sex): array {
            [$value, $who] = match ($sex) {
                Sex::Male => [self::CALORIES_GENERIC_MALE, 'men'],
                Sex::Female => [self::CALORIES_GENERIC_FEMALE, 'women'],
                default => [self::CALORIES_GENERIC_UNKNOWN, 'adults'],
            };

            return [
                'target' => $value, 'unit' => 'kcal', 'direction' => 'target', 'label' => 'Calories',
                'basis' => "NHS general guidance for {$who} — add your height, weight and date of birth in Profile for a personal figure",
                'personalised' => false,
            ];
        };

        if ($profile === null
            || $profile->weight_kg === null
            || $profile->height_cm === null
            || $profile->date_of_birth === null) {
            return $generic($profile?->sex);
        }

        $weight = (float) $profile->weight_kg;
        $height = (float) $profile->height_cm;
        $age = (int) $profile->date_of_birth->age;

        [$sexConstant, $sexNote] = match ($profile->sex) {
            Sex::Male => [self::MSJ_SEX_MALE, ''],
            Sex::Female => [self::MSJ_SEX_FEMALE, ''],
            default => [self::MSJ_SEX_MIDPOINT, ', sex-neutral midpoint'],
        };

        $bmr = (10.0 * $weight) + (6.25 * $height) - (5.0 * $age) + $sexConstant;

        $activity = $profile->activity_level;
        $factor = $activity !== null
            ? (self::ACTIVITY_FACTORS[$activity->value] ?? self::ACTIVITY_FACTOR_DEFAULT)
            : self::ACTIVITY_FACTOR_DEFAULT;
        $activityNote = $activity !== null ? $activity->value : 'typical activity (add yours in Profile)';

        $maintenance = $bmr * $factor;

        $goal = $profile->primary_goal;
        $goalFactor = self::GOAL_CALORIE_FACTORS[$goal->value] ?? 1.0;
        $goalNote = match ($goal) {
            PrimaryGoal::LoseWeight => ', -15% for your weight-loss goal',
            PrimaryGoal::GainMuscle => ', +10% for your muscle-gain goal',
            PrimaryGoal::Recomp => ', at maintenance for your recomposition goal',
            default => '',
        };

        $target = round($maintenance * $goalFactor / 50) * 50; // nearest 50 kcal

        if ($target < self::CALORIES_MIN || $target > self::CALORIES_MAX) {
            // A wildly out-of-range result means implausible profile data —
            // fall back to the citable generic rather than a silly number.
            return $generic($profile->sex);
        }

        return [
            'target' => $target, 'unit' => 'kcal', 'direction' => 'target', 'label' => 'Calories',
            'basis' => "Mifflin-St Jeor ({$weight} kg, {$height} cm, age {$age}{$sexNote}) x {$activityNote}{$goalNote}",
            'personalised' => true,
        ];
    }

    /**
     * @return array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}
     */
    private function protein(?UserProfile $profile): array
    {
        if ($profile === null || $profile->weight_kg === null) {
            return [
                'target' => self::PROTEIN_RI_G, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Protein',
                'basis' => 'UK/EU reference intake (50 g/day) — add your weight in Profile for a personal figure',
                'personalised' => false,
            ];
        }

        $weight = (float) $profile->weight_kg;
        $perKg = self::PROTEIN_G_PER_KG[$profile->primary_goal->value] ?? self::PROTEIN_G_PER_KG_DEFAULT;

        $goalNote = match ($profile->primary_goal) {
            PrimaryGoal::GainMuscle => 'for your muscle-gain goal',
            PrimaryGoal::LoseWeight => 'to preserve lean mass while losing weight',
            PrimaryGoal::Recomp => 'for building muscle while losing fat',
            default => 'active-adult baseline',
        };

        return [
            'target' => round($weight * $perKg / 5) * 5, // nearest 5 g
            'unit' => 'g', 'direction' => 'higher', 'label' => 'Protein',
            'basis' => "{$perKg} g/kg x {$weight} kg — {$goalNote}",
            'personalised' => true,
        ];
    }

    /**
     * Carb/fat gram targets derived from the calorie target via the goal's
     * energy split. Inherits the calorie target's personalisation.
     *
     * @param  array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}  $calories
     * @return array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}
     */
    private function energySplitTarget(?UserProfile $profile, array $calories, string $key, float $kcalPerGram, string $label): array
    {
        $goal = $profile?->primary_goal?->value;
        $split = self::MACRO_ENERGY_SPLIT[$goal] ?? self::MACRO_ENERGY_SPLIT['default'];
        $fraction = $split[$key];

        $grams = round($calories['target'] * $fraction / $kcalPerGram / 5) * 5;
        $pct = (int) round($fraction * 100);

        return [
            'target' => max(5.0, $grams), 'unit' => 'g', 'direction' => 'target', 'label' => $label,
            'basis' => "~{$pct}% of your {$calories['target']} kcal target as {$label}",
            'personalised' => $calories['personalised'],
        ];
    }

    /**
     * Explicit manual targets become the scoring targets (Foody Score spec
     * §4). The receipt says so — provenance stays first-class.
     *
     * The derived (profile-default) figure is kept alongside as `default` so
     * the score engine can weigh how far the user's own target sits from the
     * profile baseline (manual subweight ratio, spec §4).
     *
     * @param  array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}  $derived
     * @return array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool, explicit?: bool, default?: float}
     */
    private function withOverride(array $derived, float|int|null $custom, string $unit): array
    {
        if ($custom === null || (float) $custom <= 0) {
            return $derived;
        }

        return [
            ...$derived,
            'target' => (float) $custom,
            'unit' => $unit,
            'basis' => 'Your own target, set in Profile',
            'personalised' => true,
            'explicit' => true,
            'default' => $derived['target'],
        ];
    }
}
