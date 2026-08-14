<?php

namespace Tests\Feature;

use App\AI\Contracts\RecipeSuggester;
use App\AI\DataObjects\RecipeIdeas;
use App\AI\Local\UnavailableRecipeSuggester;
use App\AI\OpenRouter\PrismRecipeSuggester;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\User;
use App\Services\AiJobLogger;
use App\Services\NutritionTargetsService;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * AI chef (Pantry): pantry-grounded breakfast/lunch/dinner ideas. Grounding
 * rules: only offered ids may be claimed; extras land in also_needed; approx
 * figures are advisory (~) and never logged. Keyless -> no chef module.
 */
class AiChefTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    private function stocked(string $name, float $qty = 500)
    {
        $product = CanonicalProduct::factory()->create(['name' => $name]);

        return app(PantryService::class)->purchase($this->user, $product, $qty, QuantityUnit::Gram);
    }

    private function ideas(array $suggestions): RecipeIdeas
    {
        return new RecipeIdeas($suggestions);
    }

    public function test_prism_suggester_grounds_ids_orders_slots_and_logs_the_job(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['suggestions' => [
                    // Deliberately out of order + an invented id + a bogus slot.
                    ['slot' => 'dinner', 'title' => 'Coconut chicken curry', 'summary' => 'High protein.', 'pantry_item_ids' => [1, 999], 'also_needed' => ['rice'], 'steps' => ['Sear chicken', 'Simmer in coconut milk'], 'approx_calories' => 650, 'approx_protein' => 45],
                    ['slot' => 'breakfast', 'title' => 'Porridge', 'summary' => 'Quick.', 'pantry_item_ids' => [2], 'also_needed' => [], 'steps' => ['Simmer oats'], 'approx_calories' => 320, 'approx_protein' => 12],
                    ['slot' => 'brunch', 'title' => 'Invalid slot', 'summary' => '', 'pantry_item_ids' => [], 'also_needed' => [], 'steps' => [], 'approx_calories' => null, 'approx_protein' => null],
                ]])
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(300, 200))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);

        $suggester = new PrismRecipeSuggester(app(AiJobLogger::class), app(NutritionTargetsService::class), 'openrouter', 'openai/gpt-4o-mini');
        $ideas = $suggester->suggest($this->user, [
            ['id' => 1, 'label' => 'Chicken thighs', 'quantity' => '500 g'],
            ['id' => 2, 'label' => 'Oats', 'quantity' => '1000 g'],
        ]);

        $this->assertSame(['breakfast', 'dinner'], array_column($ideas->suggestions, 'slot')); // day order, bogus slot dropped
        $dinner = collect($ideas->suggestions)->firstWhere('slot', 'dinner');
        $this->assertSame([1], $dinner['pantry_item_ids']); // invented 999 dropped
        $this->assertSame(['rice'], $dinner['also_needed']);

        $this->assertDatabaseHas('ai_jobs', ['task_type' => 'recipe_suggestion', 'result_status' => 'suggested']);
    }

    public function test_chef_module_renders_suggestions_with_recipes(): void
    {
        $chicken = $this->stocked('Chicken thighs');

        $this->app->bind(RecipeSuggester::class, fn () => new class($chicken->id) implements RecipeSuggester
        {
            public function __construct(private readonly int $itemId) {}

            public function available(): bool
            {
                return true;
            }

            public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
            {
                return new RecipeIdeas([[
                    'slot' => 'dinner', 'title' => 'Garlic roast chicken', 'summary' => 'Protein-forward and simple.',
                    'pantry_item_ids' => [$this->itemId], 'also_needed' => ['garlic', 'olive oil'],
                    'steps' => ['Season the thighs', 'Roast 25 min at 200C'],
                    'approx_calories' => 520, 'approx_protein' => 42,
                ]]);
            }
        });

        Volt::actingAs($this->user)->test('ai-chef')
            ->call('suggest')
            ->assertHasNoErrors()
            ->assertSee('DINNER')
            ->assertSee('Garlic roast chicken')
            ->assertSee('CHICKEN THIGHS')       // resolved pantry name
            ->assertSee('GARLIC, OLIVE OIL')    // also-needed, honest
            ->assertSee('~520 KCAL')            // advisory tilde
            ->assertSee('Roast 25 min at 200C');
    }

    public function test_results_are_cached_for_the_day_and_fresh_ideas_bypasses(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $calls = new class
        {
            public int $count = 0;
        };

        $this->app->bind(RecipeSuggester::class, fn () => new class($chicken->id, $calls) implements RecipeSuggester
        {
            public function __construct(private readonly int $itemId, private $calls) {}

            public function available(): bool
            {
                return true;
            }

            public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
            {
                $this->calls->count++;

                return new RecipeIdeas([[
                    'slot' => 'lunch', 'title' => 'Idea #'.$this->calls->count, 'summary' => '',
                    'pantry_item_ids' => [$this->itemId], 'also_needed' => [], 'steps' => ['Cook'],
                    'approx_calories' => null, 'approx_protein' => null,
                ]]);
            }
        });

        $component = Volt::actingAs($this->user)->test('ai-chef')->call('suggest');
        $component->call('suggest'); // same day -> cache, no second AI call
        $this->assertSame(1, $calls->count);

        $component->call('freshIdeas'); // explicit bypass
        $this->assertSame(2, $calls->count);
    }

    public function test_keyless_pantry_shows_no_chef(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->assertInstanceOf(UnavailableRecipeSuggester::class, app(RecipeSuggester::class));

        Volt::actingAs($this->user)->test('ai-chef')
            ->assertDontSee('AI chef');
    }

    public function test_failure_shows_a_friendly_note(): void
    {
        $this->stocked('Chicken thighs');

        $this->app->bind(RecipeSuggester::class, fn () => new class implements RecipeSuggester
        {
            public function available(): bool
            {
                return true;
            }

            public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
            {
                return null;
            }
        });

        Volt::actingAs($this->user)->test('ai-chef')
            ->call('suggest')
            ->assertSet('failed', true)
            ->assertSee('after your next scan');
    }
}
