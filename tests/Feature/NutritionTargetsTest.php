<?php

namespace Tests\Feature;

use App\Enums\ActivityLevel;
use App\Enums\PrimaryGoal;
use App\Enums\Sex;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\NutritionAnalyticsService;
use App\Services\NutritionTargetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The definition of "good" (baseline-credibility decision, Aug 2026): targets
 * are deterministic and citable — Mifflin-St Jeor + g/kg personalisation where
 * the profile allows, UK government guidance as scaffolding and fallback, and
 * every figure carries its receipt. Formulas are pinned here against
 * hand-computed values so a drift is a test failure, not a silent change.
 */
class NutritionTargetsTest extends TestCase
{
    use RefreshDatabase;

    private NutritionTargetsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NutritionTargetsService::class);
    }

    private function userWithProfile(array $attributes): User
    {
        $user = User::factory()->create();
        UserProfile::factory()->for($user)->create($attributes);

        return $user;
    }

    public function test_full_profile_personalises_calories_and_protein(): void
    {
        // Hand-computed: BMR = 10*82 + 6.25*180 - 5*30 + 5 = 1800.
        // TDEE = 1800 * 1.55 (moderate) = 2790. +10% muscle gain = 3069 -> 3050.
        // Protein = 1.8 g/kg * 82 = 147.6 -> 150 (nearest 5).
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::GainMuscle,
            'sex' => Sex::Male,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'height_cm' => 180,
            'weight_kg' => 82,
            'activity_level' => ActivityLevel::Moderate,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(3050.0, $targets['calories']['target']);
        $this->assertTrue($targets['calories']['personalised']);
        $this->assertStringContainsString('Mifflin-St Jeor', $targets['calories']['basis']);
        $this->assertStringContainsString('muscle-gain', $targets['calories']['basis']);

        $this->assertSame(150.0, $targets['protein']['target']);
        $this->assertTrue($targets['protein']['personalised']);
        $this->assertStringContainsString('1.8 g/kg', $targets['protein']['basis']);
    }

    public function test_weight_loss_applies_deficit_and_lean_mass_protein(): void
    {
        // BMR = 10*70 + 6.25*165 - 5*40 - 161 = 700 + 1031.25 - 200 - 161 = 1370.25.
        // TDEE = 1370.25 * 1.375 (light) = 1884.09. -15% = 1601.48 -> 1600.
        // Protein = 1.6 * 70 = 112 -> 110 (nearest 5).
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::LoseWeight,
            'sex' => Sex::Female,
            'date_of_birth' => now()->subYears(40)->toDateString(),
            'height_cm' => 165,
            'weight_kg' => 70,
            'activity_level' => ActivityLevel::Light,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(1600.0, $targets['calories']['target']);
        $this->assertSame(110.0, $targets['protein']['target']);
        $this->assertStringContainsString('preserve lean mass', $targets['protein']['basis']);
    }

    public function test_recomp_goal_holds_maintenance_with_high_protein(): void
    {
        // BMR = 10*82 + 6.25*180 - 5*30 + 5 = 1800. TDEE = 1800 * 1.55 = 2790.
        // Recomposition trains AT maintenance: x1.0 -> 2790 -> 2800 (nearest 50).
        // Protein = 2.0 g/kg * 82 = 164 -> 165 (nearest 5) — the upper evidence range.
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::Recomp,
            'sex' => Sex::Male,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'height_cm' => 180,
            'weight_kg' => 82,
            'activity_level' => ActivityLevel::Moderate,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(2800.0, $targets['calories']['target']);
        $this->assertStringContainsString('recomposition', $targets['calories']['basis']);

        $this->assertSame(165.0, $targets['protein']['target']);
        $this->assertStringContainsString('2 g/kg', $targets['protein']['basis']);
        $this->assertStringContainsString('while losing fat', $targets['protein']['basis']);
    }

    public function test_undisclosed_sex_uses_stated_midpoint(): void
    {
        // BMR = 10*75 + 6.25*170 - 5*35 - 78 = 750 + 1062.5 - 175 - 78 = 1559.5.
        // TDEE = 1559.5 * 1.2 (sedentary) = 1871.4 -> maintain -> 1850.
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::MaintainWeight,
            'sex' => Sex::PreferNotToSay,
            'date_of_birth' => now()->subYears(35)->toDateString(),
            'height_cm' => 170,
            'weight_kg' => 75,
            'activity_level' => ActivityLevel::Sedentary,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(1850.0, $targets['calories']['target']);
        $this->assertStringContainsString('sex-neutral midpoint', $targets['calories']['basis']);
    }

    public function test_sparse_profile_falls_back_to_cited_generic_guidance(): void
    {
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::EatHealthier,
            'sex' => Sex::Female,
            'date_of_birth' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'activity_level' => null,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(2000.0, $targets['calories']['target']); // NHS women
        $this->assertFalse($targets['calories']['personalised']);
        $this->assertStringContainsString('NHS', $targets['calories']['basis']);
        $this->assertStringContainsString('add your', $targets['calories']['basis']); // nudge

        $this->assertSame(50.0, $targets['protein']['target']); // UK/EU RI
        $this->assertFalse($targets['protein']['personalised']);
    }

    public function test_population_caps_never_personalise(): void
    {
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::GainMuscle,
            'weight_kg' => 100,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(30.0, $targets['fibre']['target']);       // SACN
        $this->assertSame(20.0, $targets['saturated_fat']['target']); // Eatwell
        $this->assertSame(6.0, $targets['salt']['target']);         // NHS max
        $this->assertSame(5.0, $targets['fruit_veg']['target']);    // 5-a-day
        $this->assertFalse($targets['salt']['personalised']);
    }

    public function test_implausible_profile_falls_back_rather_than_absurd_target(): void
    {
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::GainMuscle,
            'sex' => Sex::Male,
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'height_cm' => 250,
            'weight_kg' => 250, // implausible data -> silly TDEE
            'activity_level' => ActivityLevel::VeryActive,
        ]);

        $targets = $this->service->targetsFor($user);

        $this->assertSame(NutritionTargetsService::CALORIES_GENERIC_MALE, $targets['calories']['target']);
        $this->assertFalse($targets['calories']['personalised']);
    }

    // --- integration: the analytics layer serves personalised targets --------

    public function test_manual_targets_override_derived_figures_with_a_receipt(): void
    {
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::GainMuscle,
            'sex' => Sex::Male,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'height_cm' => 180,
            'weight_kg' => 82,
            'activity_level' => ActivityLevel::Moderate,
            'custom_calorie_target' => 2800,
            'custom_protein_g' => 160.0,
        ]);

        $targets = $this->service->targetsFor($user);

        // The explicit figures win, marked explicit, with the derived default
        // preserved alongside (Foody Score spec §4).
        $this->assertSame(2800.0, $targets['calories']['target']);
        $this->assertTrue($targets['calories']['explicit']);
        $this->assertSame(3050.0, $targets['calories']['default']);
        $this->assertSame('Your own target, set in Profile', $targets['calories']['basis']);

        $this->assertSame(160.0, $targets['protein']['target']);
        $this->assertSame(150.0, $targets['protein']['default']);

        // Carbs/fat derive from the OVERRIDDEN calorie target (the user's own
        // energy budget), not the discarded derived one: 2800 * 0.50 / 4 = 350.
        $this->assertSame(350.0, $targets['carbs']['target']);
        $this->assertArrayNotHasKey('explicit', $targets['carbs']);
    }

    public function test_summaries_expose_targets_and_personalised_protein_indicator(): void
    {
        $user = $this->userWithProfile([
            'primary_goal' => PrimaryGoal::GainMuscle,
            'weight_kg' => 82,
        ]);

        $daily = app(NutritionAnalyticsService::class)->dailySummary($user);

        $this->assertSame(150.0, $daily['targets']['protein']['target']);

        $protein = collect($daily['indicators'])->firstWhere('key', 'protein');
        $this->assertSame(150.0, $protein['target']); // banded against YOUR target
        $this->assertTrue($protein['personalised']);
        $this->assertStringContainsString('1.8 g/kg', $protein['basis']);

        $salt = collect($daily['indicators'])->firstWhere('key', 'salt');
        $this->assertStringContainsString('NHS', $salt['basis']);
    }
}
