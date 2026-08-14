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

        return [
            'calories' => $this->calories($profile),
            'protein' => $this->protein($profile),
            'fibre' => [
                'target' => self::FIBRE_G, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Fibre',
                'basis' => 'UK SACN recommendation (~30 g/day)', 'personalised' => false,
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
}
