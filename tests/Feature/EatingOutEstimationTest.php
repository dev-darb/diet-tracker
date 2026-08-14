<?php

namespace Tests\Feature;

use App\AI\Contracts\EatingOutEstimator;
use App\AI\DataObjects\EatingOutEstimate;
use App\AI\Local\UnavailableEatingOutEstimator;
use App\AI\OpenRouter\PrismEatingOutEstimator;
use App\Models\AiJob;
use App\Models\ConsumptionEvent;
use App\Models\User;
use App\Services\AiJobLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Eating-out estimation (capture flow; BUILD_PLAN §1b tier 3). The user should
 * never NEED to know a dish's figures: the estimator proposes, the user
 * confirms/edits, the record is marked estimated. No key -> manual fallback.
 */
class EatingOutEstimationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    private function fakeEstimate(array $structured): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured($structured)
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(80, 40))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);
    }

    public function test_estimator_returns_figures_confidence_and_basis_and_logs_the_job(): void
    {
        $this->fakeEstimate([
            'calories' => 1180, 'protein' => 45, 'carbs' => 128, 'sugars' => 12,
            'fat' => 52, 'saturated_fat' => 11, 'fibre' => 7, 'salt' => 3.2,
            'confidence' => 0.85,
            'basis' => 'Wagamama publishes chicken katsu curry at about 1180 kcal.',
        ]);

        $estimator = new PrismEatingOutEstimator(app(AiJobLogger::class), 'openrouter', 'openai/gpt-4o-mini');
        $estimate = $estimator->estimate('Chicken katsu curry', 'Wagamama');

        $this->assertNotNull($estimate);
        $this->assertSame(1180.0, $estimate->calories);
        $this->assertSame(45.0, $estimate->protein);
        $this->assertSame(0.85, $estimate->confidence);
        $this->assertStringContainsString('Wagamama', $estimate->basis);

        // Diagnostics row for the benchmarking plan (brief §14).
        $job = AiJob::latest('id')->firstOrFail();
        $this->assertSame('eating_out_estimation', $job->task_type);
        $this->assertSame('estimated', $job->result_status);
    }

    public function test_estimate_rejects_absurd_values_as_null(): void
    {
        $this->fakeEstimate([
            'calories' => 123456789, 'protein' => -5, 'carbs' => 60, 'sugars' => null,
            'fat' => null, 'saturated_fat' => null, 'fibre' => null, 'salt' => null,
            'confidence' => 0.4, 'basis' => 'Typical values.',
        ]);

        $estimator = new PrismEatingOutEstimator(app(AiJobLogger::class), 'openrouter', 'openai/gpt-4o-mini');
        $estimate = $estimator->estimate('Mystery dish');

        $this->assertNull($estimate->calories); // out of range -> unknown
        $this->assertNull($estimate->protein);  // negative -> unknown
        $this->assertSame(60.0, $estimate->carbs);
    }

    public function test_binding_uses_prism_estimator_with_key_and_unavailable_without(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->assertInstanceOf(UnavailableEatingOutEstimator::class, app(EatingOutEstimator::class));
        $this->assertFalse(app(EatingOutEstimator::class)->available());

        config()->set('prism.providers.openrouter.api_key', 'sk-or-test');
        $this->assertInstanceOf(PrismEatingOutEstimator::class, app(EatingOutEstimator::class));
        $this->assertTrue(app(EatingOutEstimator::class)->available());
    }

    public function test_log_flow_estimates_then_logs_with_editable_figures(): void
    {
        // Bind a deterministic estimator double — the UI contract is what's under test.
        $this->app->bind(EatingOutEstimator::class, fn () => new class implements EatingOutEstimator
        {
            public function available(): bool
            {
                return true;
            }

            public function estimate(string $dish, ?string $venue = null): ?EatingOutEstimate
            {
                return EatingOutEstimate::fromArray([
                    'calories' => 1180, 'protein' => 45, 'carbs' => 128,
                    'fat' => 52, 'confidence' => 0.85,
                    'basis' => 'Wagamama publishes this dish.',
                ]);
            }
        });

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Chicken katsu curry')
            ->set('outVenue', 'Wagamama')
            ->call('estimateOut')
            ->assertSet('outCalories', '1180')
            ->assertSet('outProtein', '45')
            ->assertSet('estimateConfidence', 85)
            ->assertSee('Wagamama publishes this dish.')
            ->set('outCalories', '1000') // the user stays in charge
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $event = ConsumptionEvent::firstOrFail();
        $this->assertSame(1000.0, (float) $event->calories); // edited value wins
        $this->assertSame('Wagamama', $event->venue);
        $this->assertTrue($event->estimated);

        // History shows the venue.
        Volt::actingAs($this->user)->test('eat')->assertSee('WAGAMAMA');
    }

    public function test_keyless_flow_hides_estimation_and_still_logs(): void
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
    }

    public function test_failed_estimate_degrades_to_manual(): void
    {
        $this->app->bind(EatingOutEstimator::class, fn () => new class implements EatingOutEstimator
        {
            public function available(): bool
            {
                return true;
            }

            public function estimate(string $dish, ?string $venue = null): ?EatingOutEstimate
            {
                return null; // provider failure
            }
        });

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Something obscure')
            ->call('estimateOut')
            ->assertSet('estimateFailed', true)
            ->assertSee('estimate that one')
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');
    }
}
