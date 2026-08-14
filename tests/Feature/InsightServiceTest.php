<?php

namespace Tests\Feature;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\DataObjects\DietInsightContext;
use App\AI\DataObjects\GeneratedInsight;
use App\Enums\ConsumptionType;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\AiInsight;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\InsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class InsightServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    /** A week with a clear fibre gap and a fibre-rich pantry item. */
    private function seedFibreGap(): PantryItem
    {
        $oats = CanonicalProduct::factory()->create(['brand' => 'Mornflake', 'name' => 'Oats', 'category' => 'cereals']);
        ProductVersion::factory()->for($oats)->create(['serving_basis' => ServingBasis::Per100g, 'fibre' => 9.0]);
        $item = PantryItem::factory()->for($this->user)->for($oats)->create([
            'current_quantity' => 2,
            'quantity_unit' => QuantityUnit::Unit,
        ]);

        // Log several low-fibre days so the weekly fibre average is well below target.
        for ($i = 0; $i < 5; $i++) {
            ConsumptionEvent::factory()->for($this->user)->create([
                'type' => ConsumptionType::Single,
                'consumed_at' => now()->subDays($i)->setTime(12, 0),
                ...array_fill_keys(['calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt'], 0.0),
                'calories' => 500,
                'protein' => 25,
                'fibre' => 3,
            ])->items()->create([
                'canonical_product_id' => null,
                'quantity' => 1,
                'unit' => QuantityUnit::Unit,
                ...array_fill_keys(['calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt'], 0.0),
                'calories' => 500,
                'protein' => 25,
                'fibre' => 3,
            ]);
        }

        return $item;
    }

    public function test_it_generates_and_persists_an_insight_without_a_key(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->seedFibreGap();

        $insight = app(InsightService::class)->currentInsight($this->user);

        $this->assertInstanceOf(AiInsight::class, $insight);
        $this->assertSame('rule_based', $insight->provider);
        $this->assertSame('fibre', $insight->focus_key);
        $this->assertNotEmpty($insight->pantry_item_ids);
        $this->assertDatabaseCount('ai_insights', 1);
    }

    public function test_it_caches_and_does_not_regenerate_on_each_call(): void
    {
        config()->set('prism.providers.openrouter.api_key', 'test-key');
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['title' => 'Fibre focus', 'body' => 'More fibre helps.', 'priority' => 'high'])
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(200, 50))
                ->withMeta(new Meta('id', 'openai/gpt-4o')),
        ]);
        $this->seedFibreGap();

        $service = app(InsightService::class);
        $first = $service->currentInsight($this->user);
        $second = $service->currentInsight($this->user);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('ai_insights', 1);
        // The LLM ran exactly once — the second call hit the cache, not Prism.
        $this->assertDatabaseCount('ai_jobs', 1);
    }

    public function test_dismiss_hides_the_insight_for_the_period_and_does_not_regenerate(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->seedFibreGap();

        $service = app(InsightService::class);
        $insight = $service->currentInsight($this->user);
        $service->dismiss($insight);

        $this->assertNull($service->currentInsight($this->user));
        // Still exactly one row — dismissed, not regenerated.
        $this->assertDatabaseCount('ai_insights', 1);
        $this->assertNotNull($insight->fresh()->dismissed_at);
    }

    public function test_it_returns_null_when_there_is_no_data(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');

        $this->assertNull(app(InsightService::class)->currentInsight($this->user));
        $this->assertDatabaseCount('ai_insights', 0);
    }

    public function test_it_falls_back_to_rule_based_when_the_llm_throws(): void
    {
        $this->seedFibreGap();

        // Simulate a provider/key error from the bound generator.
        $this->app->bind(DietInsightGenerator::class, fn () => new class implements DietInsightGenerator
        {
            public function generate(DietInsightContext $context): GeneratedInsight
            {
                throw new \RuntimeException('provider unavailable');
            }
        });

        $insight = app(InsightService::class)->currentInsight($this->user);

        // Graceful: a rule-based insight is still delivered, no error surfaces.
        $this->assertInstanceOf(AiInsight::class, $insight);
        $this->assertSame('rule_based', $insight->provider);
        $this->assertSame('fibre', $insight->focus_key);
    }

    public function test_referenced_pantry_items_returns_the_real_items(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->seedFibreGap();

        $service = app(InsightService::class);
        $insight = $service->currentInsight($this->user);
        $items = $service->referencedPantryItems($insight);

        $this->assertCount(1, $items);
        $this->assertSame('Oats', $items->first()->canonicalProduct->name);
    }

    public function test_refresh_regenerates_even_after_dismissal(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->seedFibreGap();

        $service = app(InsightService::class);
        $insight = $service->currentInsight($this->user);
        $service->dismiss($insight);

        $refreshed = $service->refresh($this->user);

        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->dismissed_at);
        $this->assertDatabaseCount('ai_insights', 1);
    }
}
