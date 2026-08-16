<?php

namespace Tests\Feature;

use App\AI\Contracts\NutritionEstimator;
use App\AI\Local\UnavailableNutritionEstimator;
use App\AI\OpenRouter\PrismNutritionEstimator;
use App\Models\AiJob;
use App\Models\ConsumptionEvent;
use App\Models\NutritionEstimate;
use App\Models\User;
use App\Nutrition\Estimation\EstimateGuard;
use App\Nutrition\Estimation\EstimationBasis;
use App\Nutrition\Estimation\EstimationDraft;
use App\Nutrition\Estimation\EstimationReason;
use App\Nutrition\Estimation\EstimationRequest;
use App\Nutrition\NutrientOrigin;
use App\Services\AiJobLogger;
use App\Services\NutritionEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Guarded nutrition estimation (founder decision, Aug 2026).
 *
 * A model MAY produce a nutrient figure where no source has one — but only when
 * asked deliberately, only for nutrients that were named, only with its working
 * shown, only if the figures survive deterministic checks, and never in a form
 * that leaves the number mistakable for one a label stated.
 *
 * These tests are the guardrails made executable. Most of them assert that
 * something is REFUSED, which is the point.
 */
class NutritionEstimationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    private function fakeModel(array $structured): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured($structured)
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(120, 90))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o')),
        ]);
    }

    /** A well-formed answer: coherent figures, reasoning, a named reference. */
    private function goodDraftPayload(): array
    {
        return [
            'calories' => 1180, 'protein' => 45, 'carbs' => 128, 'sugars' => 12,
            'fat' => 52, 'saturated_fat' => 11, 'fibre' => 7, 'salt' => 3.2,
            'steps' => [
                'Chicken katsu curry is breaded chicken, curry sauce and white rice.',
                'Assuming a standard restaurant portion of about 550 g.',
                'Rice contributes roughly 350 kcal, the breaded chicken about 550, the sauce about 280.',
            ],
            'assumptions' => ['A standard single portion, not the large size.'],
            'reference' => 'Wagamama publishes chicken katsu curry at about 1180 kcal.',
            'confidence' => 0.85,
        ];
    }

    private function bind(callable $estimate, bool $available = true): void
    {
        $this->app->bind(NutritionEstimator::class, fn () => new class($estimate, $available) implements NutritionEstimator
        {
            public function __construct(private $fn, private bool $available) {}

            public function available(): bool
            {
                return $this->available;
            }

            public function estimate(EstimationRequest $request): ?EstimationDraft
            {
                return ($this->fn)($request);
            }
        });
    }

    private function draft(array $overrides = []): EstimationDraft
    {
        $payload = array_merge($this->goodDraftPayload(), $overrides);

        return new EstimationDraft(
            values: array_intersect_key($payload, array_flip([
                'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt', 'iron',
            ])),
            steps: $payload['steps'],
            assumptions: $payload['assumptions'],
            reference: $payload['reference'],
            confidence: $payload['confidence'],
        );
    }

    private function request(array $nutrients = ['calories', 'protein', 'carbs', 'fat']): EstimationRequest
    {
        return EstimationRequest::for(
            subject: 'Chicken katsu curry',
            reason: EstimationReason::NoSourceRecord,
            basis: EstimationBasis::WholeItem,
            nutrients: $nutrients,
        );
    }

    // --- The ask itself ------------------------------------------------------

    /**
     * There is deliberately no way to ask for "whatever is missing". An absent
     * figure on a sourced product may be perfectly accurate — the manufacturer
     * may simply not measure it — and turning that silence into a guess would
     * make an honest gap into a confident wrong answer.
     */
    public function test_there_is_no_way_to_ask_for_whatever_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name the nutrients/');

        EstimationRequest::for('Anything', EstimationReason::NoSourceRecord, nutrients: []);
    }

    public function test_a_request_records_why_estimation_was_permitted(): void
    {
        $request = $this->request();

        $this->assertSame(EstimationReason::NoSourceRecord, $request->reason);
        $this->assertSame('no_source_record', $request->toArray()['reason']);
    }

    // --- The guardrails ------------------------------------------------------

    public function test_a_coherent_reasoned_estimate_is_accepted(): void
    {
        $verdict = (new EstimateGuard)->assess($this->request(), $this->draft());

        $this->assertTrue($verdict->isAccepted);
        $this->assertSame(1180.0, $verdict->values['calories']);
        $this->assertSame(45.0, $verdict->values['protein']);
    }

    /** A number nobody can check afterwards is not evidence, whatever its value. */
    public function test_an_estimate_with_no_working_is_refused_whole(): void
    {
        $verdict = (new EstimateGuard)->assess($this->request(), $this->draft(['steps' => []]));

        $this->assertFalse($verdict->isAccepted);
        $this->assertSame([], $verdict->values);
        $this->assertStringContainsString('No reasoning', implode(' ', $verdict->notes));
    }

    public function test_an_estimate_with_no_named_reference_is_refused(): void
    {
        $verdict = (new EstimateGuard)->assess($this->request(), $this->draft(['reference' => null]));

        $this->assertFalse($verdict->isAccepted);
    }

    /** A model that says it is guessing is believed. */
    public function test_an_estimate_below_the_confidence_floor_is_refused(): void
    {
        $verdict = (new EstimateGuard)->assess($this->request(), $this->draft(['confidence' => 0.2]));

        $this->assertFalse($verdict->isAccepted);
        $this->assertStringContainsString('below the floor', implode(' ', $verdict->notes));
    }

    /**
     * The same cross-checks a real source faces. An estimate that contradicts
     * itself is worth less than no estimate, so the whole set goes — salvaging
     * numbers out of reasoning we have decided not to trust is the worst of
     * both worlds.
     */
    public function test_figures_that_do_not_hold_together_are_refused_whole(): void
    {
        // 5000 kcal cannot follow from these macros.
        $verdict = (new EstimateGuard)->assess(
            $this->request(),
            $this->draft(['calories' => 5000, 'protein' => 45, 'carbs' => 128, 'fat' => 52]),
        );

        $this->assertFalse($verdict->isAccepted);
        $this->assertStringContainsString('do not hold together', implode(' ', $verdict->notes));
    }

    /** An estimate cannot spread into fields nobody asked about. */
    public function test_nutrients_that_were_not_asked_for_are_dropped(): void
    {
        $verdict = (new EstimateGuard)->assess(
            $this->request(['calories', 'protein']),
            $this->draft(['iron' => 3.0]),
        );

        $this->assertTrue($verdict->isAccepted);
        $this->assertSame(['calories', 'protein'], $verdict->keys());
        $this->assertStringContainsString('iron: not asked for', implode(' ', $verdict->notes));
    }

    public function test_a_physically_impossible_figure_is_dropped(): void
    {
        $verdict = (new EstimateGuard)->assess(
            $this->request(['calories', 'protein']),
            $this->draft(['protein' => 90000]),
        );

        $this->assertTrue($verdict->isAccepted);
        $this->assertArrayNotHasKey('protein', $verdict->values);
        $this->assertStringContainsString('physically possible', implode(' ', $verdict->notes));
    }

    /** Declining is a legitimate answer and passes through quietly. */
    public function test_a_nutrient_the_model_declined_is_simply_absent(): void
    {
        $verdict = (new EstimateGuard)->assess(
            $this->request(['calories', 'protein']),
            $this->draft(['protein' => null]),
        );

        $this->assertTrue($verdict->isAccepted);
        $this->assertSame(['calories'], $verdict->keys());
        // Declining is not a fault, so it earns no note — unlike the nutrients
        // the model volunteered without being asked, which do.
        $this->assertStringNotContainsString('protein', implode(' ', $verdict->notes));
    }

    // --- The estimator + the record -----------------------------------------

    public function test_the_estimator_returns_its_working_and_logs_the_job(): void
    {
        $this->fakeModel($this->goodDraftPayload());

        $draft = (new PrismNutritionEstimator(app(AiJobLogger::class), 'openrouter', 'openai/gpt-4o'))
            ->estimate($this->request());

        $this->assertNotNull($draft);
        $this->assertSame(1180.0, $draft->values['calories']);
        $this->assertCount(3, $draft->steps);
        $this->assertStringContainsString('Wagamama', $draft->reference);

        $job = AiJob::latest('id')->firstOrFail();
        $this->assertSame('nutrition_estimation', $job->task_type);
        $this->assertSame('drafted', $job->result_status);
    }

    /**
     * The rejected record is the more interesting one: it is the evidence the
     * guardrails did something, and the only way to tell a model that could not
     * estimate a food from one that was never asked.
     */
    public function test_a_refused_estimate_is_still_recorded_with_its_reasons(): void
    {
        $this->bind(fn () => $this->draft(['confidence' => 0.1]));

        $outcome = app(NutritionEstimationService::class)
            ->estimate($this->request(), $this->user);

        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->isAccepted());

        $record = NutritionEstimate::firstOrFail();
        $this->assertFalse($record->accepted);
        $this->assertNull($record->values);
        $this->assertNotEmpty($record->guard_notes);
        $this->assertSame('Chicken katsu curry', $record->subject_label);
    }

    public function test_binding_uses_the_prism_estimator_with_a_key_and_nothing_without(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->assertInstanceOf(UnavailableNutritionEstimator::class, app(NutritionEstimator::class));

        config()->set('prism.providers.openrouter.api_key', 'sk-or-test');
        $this->assertInstanceOf(PrismNutritionEstimator::class, app(NutritionEstimator::class));
    }

    // --- The flow it now powers ---------------------------------------------

    public function test_the_log_flow_estimates_then_logs_and_marks_what_was_estimated(): void
    {
        $this->bind(fn () => $this->draft());

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Chicken katsu curry')
            ->set('outVenue', 'Wagamama')
            ->call('estimateOut')
            ->assertSet('outCalories', '1180')
            ->assertSet('outProtein', '45')
            ->assertSet('estimateConfidence', 85)
            ->assertSee('Wagamama publishes chicken katsu curry')
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $event = ConsumptionEvent::firstOrFail();
        $origins = $event->nutrientOrigins();

        $this->assertTrue($event->hasEstimatedNutrients());
        $this->assertSame(NutrientOrigin::Estimated, $origins->originOf('calories'));

        // The marker carries the id of the record holding the working, so the
        // trail from a number on a screen back to the reasoning is one lookup.
        $record = NutritionEstimate::firstOrFail();
        $this->assertSame((string) $record->id, $origins->detailOf('calories'));
        $this->assertTrue($record->accepted);
        $this->assertSame($event->id, $record->subject_id);
        $this->assertNotEmpty($record->workingLines());
    }

    /** A figure the user typed over is theirs, and stops being marked as the model's. */
    public function test_a_figure_the_user_corrects_is_no_longer_marked_estimated(): void
    {
        $this->bind(fn () => $this->draft());

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Chicken katsu curry')
            ->call('estimateOut')
            ->set('outCalories', '1000') // the user stays in charge
            ->call('logOut')
            ->assertHasNoErrors();

        $event = ConsumptionEvent::firstOrFail();
        $origins = $event->nutrientOrigins();

        $this->assertSame(1000.0, (float) $event->calories);
        $this->assertSame(NutrientOrigin::Stated, $origins->originOf('calories'));
        // The untouched figures are still the model's, and still say so.
        $this->assertSame(NutrientOrigin::Estimated, $origins->originOf('protein'));
    }

    public function test_a_refused_estimate_degrades_to_manual_entry(): void
    {
        $this->bind(fn () => null); // provider failure

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Something obscure')
            ->call('estimateOut')
            ->assertSet('estimateFailed', true)
            ->assertSee('estimate that one')
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        // Nothing was marked as estimated, because nothing was estimated.
        $this->assertFalse(ConsumptionEvent::firstOrFail()->hasEstimatedNutrients());
    }

    /**
     * The trail has to stay walkable from the entry itself. Someone looking at a
     * number in their history months later is entitled to ask where it came
     * from, and the answer has to be there without them knowing to look for a
     * separate record.
     */
    public function test_the_working_is_reachable_from_the_logged_entry(): void
    {
        $this->bind(fn () => $this->draft());

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Chicken katsu curry')
            ->call('estimateOut')
            ->call('logOut');

        Volt::actingAs($this->user)->test('eat')
            ->assertSee('How this was worked out')
            ->assertSee('Assuming a standard restaurant portion of about 550 g.')
            ->assertSee('Wagamama publishes chicken katsu curry');
    }

    public function test_the_keyless_flow_hides_estimation_and_still_logs(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->assertDontSee('Estimate the figures')
            ->set('outName', 'Dinner with friends')
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $this->assertSame(1, ConsumptionEvent::count());
        $this->assertSame(0, NutritionEstimate::count());
    }
}
