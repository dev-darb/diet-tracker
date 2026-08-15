<?php

namespace Tests\Feature;

use App\Enums\ConsumptionType;
use App\Enums\PrimaryGoal;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\ConsumptionItem;
use App\Models\FoodyMilestone;
use App\Models\FoodyScore;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\FoodyScore\FoodyScoreService;
use App\Services\FoodyScore\InputAssembler;
use App\ValueObjects\NutrientValues;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foody Score v1 — the database-facing half of the deterministic pipeline
 * (spec Phase 5): input assembly semantics, record persistence, versioning,
 * historical immutability, milestones and the projected-score service API.
 */
class FoodyScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FoodyScoreService $service;

    private InputAssembler $assembler;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        UserProfile::factory()->for($this->user)->create([
            'primary_goal' => PrimaryGoal::EatHealthier->value,
            'weight_kg' => 80, 'height_cm' => 180, 'date_of_birth' => '1990-01-01',
        ]);
        $this->service = app(FoodyScoreService::class);
        $this->assembler = app(InputAssembler::class);
        $this->asOf = CarbonImmutable::parse('2026-08-15 20:00:00');
    }

    /**
     * Log one event with snapshotted EVENT-level totals (the semantics the
     * whole app uses: pantry, home-cooked and eating-out entries all carry
     * their figures on the event row).
     *
     * @param  array<string, float|null>  $nutrients
     */
    private function logEvent(string $at, array $nutrients, ?CanonicalProduct $product = null): ConsumptionEvent
    {
        $event = ConsumptionEvent::factory()->for($this->user)->create([
            'type' => ConsumptionType::Single,
            'consumed_at' => CarbonImmutable::parse($at),
            ...array_merge(array_fill_keys(NutrientValues::KEYS, 0.0), $nutrients),
        ]);

        if ($product !== null) {
            ConsumptionItem::factory()->for($event)->create([
                'canonical_product_id' => $product->id,
            ]);
        }

        return $event;
    }

    /** A steady on-target day, logged as three meals. */
    private function logGoodDay(string $date): void
    {
        foreach ([['08:00', 0.25], ['13:00', 0.35], ['19:00', 0.40]] as [$time, $f]) {
            $this->logEvent("{$date} {$time}", [
                'calories' => 2400 * $f, 'protein' => 120 * $f, 'carbs' => 280 * $f, 'sugars' => 40 * $f,
                'fat' => 75 * $f, 'saturated_fat' => 14 * $f, 'fibre' => 30 * $f, 'salt' => 4.2 * $f,
            ]);
        }
    }

    private function logGoodWeek(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->logGoodDay($this->asOf->subDays($i)->toDateString());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Input assembly semantics                                            */
    /* ------------------------------------------------------------------ */

    public function test_day_totals_sum_event_snapshots_and_propagate_unknowns(): void
    {
        $today = $this->asOf->toDateString();

        // An eating-out estimate with unknown fibre + a known pantry meal:
        // the day's fibre must be UNKNOWN, never an understated sum.
        $this->logEvent("{$today} 12:00", ['calories' => 800.0, 'fibre' => null]);
        $this->logEvent("{$today} 18:00", ['calories' => 600.0, 'fibre' => 9.0]);

        $input = $this->assembler->assemble($this->user, $this->asOf);
        $todayTotals = $input->days[$today];

        $this->assertSame(1400.0, $todayTotals->calories);
        $this->assertNull($todayTotals->fibre);
    }

    public function test_herbs_and_spices_are_excluded_from_the_plant_count(): void
    {
        $today = $this->asOf->toDateString();
        $spinach = CanonicalProduct::factory()->create(['category' => 'Fresh vegetables']);
        $oregano = CanonicalProduct::factory()->create(['category' => 'Dried herbs and spices']);

        $this->logEvent("{$today} 12:00", ['calories' => 300.0], $spinach);
        $this->logEvent("{$today} 13:00", ['calories' => 5.0], $oregano);

        $plants = $this->assembler->assemble($this->user, $this->asOf)->plants;

        $this->assertSame(1, $plants['unique_plants']);
        $this->assertSame(1, $plants['herb_spice_excluded']);
        $this->assertSame(2, $plants['classifiable']);
    }

    public function test_expected_fraction_uses_default_schedule_until_history_is_learned(): void
    {
        // No history at all → default meal schedule (8/13/19).
        $morning = $this->assembler->assemble($this->user, $this->asOf->setTime(9, 0));
        $this->assertEqualsWithDelta(1 / 3, $morning->expectedFractionByNow, 0.001);

        $evening = $this->assembler->assemble($this->user, $this->asOf->setTime(21, 0));
        $this->assertEqualsWithDelta(1.0, $evening->expectedFractionByNow, 0.001);
    }

    public function test_expected_fraction_learns_from_the_users_own_pattern(): void
    {
        // Seven days of front-loaded eating: 80% of calories by 09:00.
        for ($i = 1; $i <= 7; $i++) {
            $date = $this->asOf->subDays($i)->toDateString();
            $this->logEvent("{$date} 07:30", ['calories' => 1600.0]);
            $this->logEvent("{$date} 20:00", ['calories' => 400.0]);
        }

        $input = $this->assembler->assemble($this->user, $this->asOf->setTime(9, 0));

        // Learned: ~80% expected by 9am — not the default schedule's 33%.
        $this->assertEqualsWithDelta(0.8, $input->expectedFractionByNow, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /* Recording: versioning, immutability, stability                      */
    /* ------------------------------------------------------------------ */

    public function test_compute_and_record_persists_a_versioned_explained_record(): void
    {
        $this->logGoodWeek();
        $this->logGoodDay($this->asOf->toDateString());

        $record = $this->service->computeAndRecord($this->user, $this->asOf);

        $this->assertSame('foody_score_v1', $record->algorithm_version);
        $this->assertSame($this->asOf->toDateString(), $record->score_date->toDateString());
        $this->assertNotEmpty($record->reason_codes);
        $this->assertNotEmpty($record->pillars);
        $this->assertArrayHasKey('day_completeness', $record->confidence);
        $this->assertContains($record->display_state, ['firm', 'provisional', 'building']);
        $this->assertGreaterThanOrEqual(80, $record->score);
    }

    public function test_recomputing_updates_todays_row_in_place(): void
    {
        $this->logGoodWeek();
        $this->logGoodDay($this->asOf->toDateString());

        $first = $this->service->computeAndRecord($this->user, $this->asOf);
        $second = $this->service->computeAndRecord($this->user, $this->asOf->addHour());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FoodyScore::query()->where('user_id', $this->user->id)->count());
    }

    public function test_historical_records_are_never_rewritten(): void
    {
        // A record from an earlier algorithm era, exactly as it was minted.
        $historic = FoodyScore::query()->create([
            'user_id' => $this->user->id,
            'score_date' => $this->asOf->subDays(30)->toDateString(),
            'score' => 91, 'raw_score' => 91, 'band' => 'excellent', 'display_state' => 'firm',
            'pillars' => [], 'reason_codes' => ['energy.on_track'], 'contributors' => [], 'candidates' => [],
            'confidence' => ['day_completeness' => 1, 'nutrient_coverage' => 1, 'historical' => 1],
            'algorithm_version' => 'foody_score_v0_hypothetical',
            'target_rules_version' => 'target_rules_v0',
        ]);

        $this->logGoodWeek();
        $this->logGoodDay($this->asOf->toDateString());
        $this->service->computeAndRecord($this->user, $this->asOf);

        $historic->refresh();
        $this->assertSame(91, $historic->score);
        $this->assertSame('foody_score_v0_hypothetical', $historic->algorithm_version);
    }

    public function test_display_stability_limits_swings_on_thin_evidence(): void
    {
        $this->logGoodWeek();

        // Yesterday's record sits at 90; this morning almost nothing is
        // logged and the raw engine output collapses. The DISPLAYED score may
        // move at most the low-evidence step (spec §15).
        FoodyScore::query()->create([
            'user_id' => $this->user->id,
            'score_date' => $this->asOf->subDay()->toDateString(),
            'score' => 90, 'raw_score' => 90, 'band' => 'excellent', 'display_state' => 'firm',
            'pillars' => [], 'reason_codes' => [], 'contributors' => [], 'candidates' => [],
            'confidence' => ['day_completeness' => 1, 'nutrient_coverage' => 1, 'historical' => 1],
            'algorithm_version' => 'foody_score_v1', 'target_rules_version' => 'target_rules_v1',
        ]);

        // A rough partial day: salt-free sugar binge at 9am.
        $this->logEvent($this->asOf->toDateString().' 08:30', [
            'calories' => 1400.0, 'protein' => 5.0, 'carbs' => 300.0, 'sugars' => 250.0,
            'fat' => 30.0, 'saturated_fat' => 18.0, 'fibre' => 1.0, 'salt' => 0.1,
        ]);

        $record = $this->service->computeAndRecord($this->user, $this->asOf->setTime(9, 0));

        $maxStep = config('foody_score.stability.max_step_high_evidence');
        $this->assertLessThan(90, $record->score);
        $this->assertGreaterThanOrEqual(90 - $maxStep, $record->score);
        // Smoothing only ever tempers the raw collapse; the unsmoothed engine
        // output is preserved alongside for honesty.
        $this->assertGreaterThanOrEqual($record->raw_score, $record->score);
    }

    /* ------------------------------------------------------------------ */
    /* Projected score (spec §16)                                          */
    /* ------------------------------------------------------------------ */

    public function test_projected_score_evaluates_a_planned_meal_without_persisting(): void
    {
        $this->logGoodWeek();

        // Today: protein far behind by late afternoon.
        $today = $this->asOf->toDateString();
        $this->logEvent("{$today} 08:00", ['calories' => 500.0, 'protein' => 12.0, 'carbs' => 80.0, 'fat' => 14.0, 'fibre' => 6.0, 'salt' => 1.0, 'sugars' => 20.0, 'saturated_fat' => 4.0]);
        $this->logEvent("{$today} 13:00", ['calories' => 600.0, 'protein' => 15.0, 'carbs' => 90.0, 'fat' => 18.0, 'fibre' => 7.0, 'salt' => 1.2, 'sugars' => 15.0, 'saturated_fat' => 5.0]);

        $projection = $this->service->projected($this->user, NutrientValues::fromArray([
            'calories' => 700.0, 'protein' => 55.0, 'carbs' => 60.0, 'sugars' => 6.0,
            'fat' => 22.0, 'saturated_fat' => 6.0, 'fibre' => 10.0, 'salt' => 1.5,
        ]), $this->asOf->setTime(17, 30));

        $this->assertGreaterThanOrEqual(0, $projection['delta']);
        $this->assertSame('foody_score_v1', $projection['result']->algorithmVersion);
        // A what-if writes nothing.
        $this->assertSame(0, FoodyScore::query()->where('user_id', $this->user->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* Milestones (spec §17–§18): personal, never comparative              */
    /* ------------------------------------------------------------------ */

    public function test_first_firm_score_mints_a_milestone_once(): void
    {
        $this->logGoodWeek();
        $this->logGoodDay($this->asOf->toDateString());

        $record = $this->service->computeAndRecord($this->user, $this->asOf);
        $this->assertSame('firm', $record->display_state);

        $this->service->computeAndRecord($this->user, $this->asOf->addMinutes(30));

        $milestones = FoodyMilestone::query()
            ->where('user_id', $this->user->id)
            ->where('kind', 'first_firm_score')
            ->get();

        $this->assertCount(1, $milestones);
        $this->assertArrayHasKey('score', $milestones->first()->payload);
    }

    public function test_a_new_personal_best_mints_a_best_yet_milestone(): void
    {
        $this->logGoodWeek();
        $this->logGoodDay($this->asOf->toDateString());

        // An earlier firm day at a modest score.
        FoodyScore::query()->create([
            'user_id' => $this->user->id,
            'score_date' => $this->asOf->subDays(3)->toDateString(),
            'score' => 72, 'raw_score' => 72, 'band' => 'steady', 'display_state' => 'firm',
            'pillars' => [], 'reason_codes' => [], 'contributors' => [], 'candidates' => [],
            'confidence' => ['day_completeness' => 1, 'nutrient_coverage' => 1, 'historical' => 1],
            'algorithm_version' => 'foody_score_v1', 'target_rules_version' => 'target_rules_v1',
        ]);

        $record = $this->service->computeAndRecord($this->user, $this->asOf);

        $this->assertGreaterThan(72, $record->score);
        $best = FoodyMilestone::query()->where('user_id', $this->user->id)->where('kind', 'best_yet')->first();
        $this->assertNotNull($best);
        $this->assertSame(72, $best->payload['previous_best']);
    }

    public function test_milestones_are_personal_records_with_no_cross_user_comparison(): void
    {
        // Structural guarantee: milestone rows reference exactly one user and
        // carry only that user's own figures (spec §18 — no "87 beats 82").
        $this->logGoodWeek();
        $this->logGoodDay($this->asOf->toDateString());
        $this->service->computeAndRecord($this->user, $this->asOf);

        foreach (FoodyMilestone::query()->get() as $milestone) {
            $this->assertSame($this->user->id, $milestone->user_id);
            $this->assertArrayNotHasKey('rank', $milestone->payload ?? []);
            $this->assertArrayNotHasKey('leaderboard', $milestone->payload ?? []);
        }
    }
}
