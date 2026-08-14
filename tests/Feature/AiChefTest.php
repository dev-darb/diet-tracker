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
 * AI chef — the Pantry's default face. Standardised recipe format: structured
 * ingredients (pantry rows lit, everything else shopping-list honesty),
 * upgrades worth buying, an optional food-first wellness note (labelled
 * non-medical). Grounding: only offered ids may be claimed; keyless -> the
 * pantry defaults to Stock and the chef shows a quiet note.
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

    private function bindChef(?RecipeIdeas $ideas): void
    {
        $this->app->bind(RecipeSuggester::class, fn () => new class($ideas) implements RecipeSuggester
        {
            public function __construct(private readonly ?RecipeIdeas $ideas) {}

            public function available(): bool
            {
                return true;
            }

            public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
            {
                return $this->ideas;
            }
        });
    }

    private function curryIdeas(int $pantryItemId): RecipeIdeas
    {
        return new RecipeIdeas([[
            'slot' => 'dinner',
            'title' => 'Coconut chicken curry',
            'summary' => 'Protein-forward and simple.',
            'ingredients' => [
                ['name' => 'Chicken thighs', 'amount' => '300 g', 'pantry_item_id' => $pantryItemId],
                ['name' => 'Coconut milk', 'amount' => '1 tin', 'pantry_item_id' => null],
            ],
            'upgrades' => ['Fresh coriander lifts the curry'],
            'steps' => ['Sear the chicken', 'Simmer in coconut milk 15 min'],
            'approx_calories' => 650,
            'approx_protein' => 45,
        ]], 'Oily fish once a week is an easy omega-3 win.');
    }

    public function test_prism_suggester_grounds_ingredient_ids_and_orders_slots(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'suggestions' => [
                        ['slot' => 'snack', 'title' => 'Yoghurt bowl', 'summary' => '', 'ingredients' => [['name' => 'Yoghurt', 'amount' => '150 g', 'pantry_item_id' => 2]], 'upgrades' => [], 'steps' => ['Spoon into a bowl'], 'approx_calories' => 150, 'approx_protein' => 15],
                        ['slot' => 'breakfast', 'title' => 'Porridge', 'summary' => '', 'ingredients' => [
                            ['name' => 'Oats', 'amount' => '50 g', 'pantry_item_id' => 1],
                            ['name' => 'Honey', 'amount' => '1 tsp', 'pantry_item_id' => 999], // never offered -> demoted to null
                        ], 'upgrades' => ['Blueberries on top'], 'steps' => ['Simmer the oats'], 'approx_calories' => 320, 'approx_protein' => 12],
                    ],
                    'wellness_note' => 'NHS suggests considering vitamin D October to March.',
                ])
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(300, 200))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);

        $suggester = new PrismRecipeSuggester(app(AiJobLogger::class), app(NutritionTargetsService::class), 'openrouter', 'openai/gpt-4o-mini');
        $ideas = $suggester->suggest($this->user, [
            ['id' => 1, 'label' => 'Oats', 'quantity' => '1000 g'],
            ['id' => 2, 'label' => 'Yoghurt', 'quantity' => '450 g'],
        ]);

        // Day order regardless of model order.
        $this->assertSame(['breakfast', 'snack'], array_column($ideas->suggestions, 'slot'));

        $breakfast = $ideas->suggestions[0];
        $this->assertSame(1, $breakfast['ingredients'][0]['pantry_item_id']);
        $this->assertNull($breakfast['ingredients'][1]['pantry_item_id']); // invented id stripped
        $this->assertSame(['Blueberries on top'], $breakfast['upgrades']);
        $this->assertSame('NHS suggests considering vitamin D October to March.', $ideas->wellnessNote);

        $this->assertDatabaseHas('ai_jobs', ['task_type' => 'recipe_suggestion', 'result_status' => 'suggested']);
    }

    public function test_chef_renders_standardised_recipe_cards(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $this->bindChef($this->curryIdeas($chicken->id));

        Volt::actingAs($this->user)->test('ai-chef')
            ->call('suggest')
            ->assertHasNoErrors()
            ->assertSee('DINNER')
            ->assertSee('Coconut chicken curry')
            ->assertSee('Chicken thighs')
            ->assertSee('300 g')                       // amount to use
            ->assertSee('In stock')                    // lit pantry row
            ->assertSee('To get')                      // honest shopping row
            ->assertSee('Fresh coriander lifts the curry')
            ->assertSee('~650 KCAL')
            ->assertSee('omega-3')                     // wellness note
            ->assertSee('not medical advice');
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
                    'ingredients' => [['name' => 'Chicken', 'amount' => '150 g', 'pantry_item_id' => $this->itemId]],
                    'upgrades' => [], 'steps' => ['Cook'],
                    'approx_calories' => null, 'approx_protein' => null,
                ]]);
            }
        });

        $component = Volt::actingAs($this->user)->test('ai-chef')->call('suggest');
        $component->call('suggest'); // same day -> cache
        $this->assertSame(1, $calls->count);

        $component->call('freshIdeas'); // explicit bypass
        $this->assertSame(2, $calls->count);
    }

    public function test_keyless_chef_shows_not_configured_and_pantry_defaults_to_stock(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->assertInstanceOf(UnavailableRecipeSuggester::class, app(RecipeSuggester::class));

        Volt::actingAs($this->user)->test('ai-chef')
            ->assertSee('switched on yet');

        Volt::actingAs($this->user)->test('pantry')
            ->assertSet('view', 'stock');
    }

    public function test_pantry_defaults_to_chef_and_switches_to_stock(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $this->bindChef($this->curryIdeas($chicken->id));

        Volt::actingAs($this->user)->test('pantry')
            ->assertSet('view', 'chef')
            ->assertDontSee('Add item')     // stock affordances live in the stock view
            ->call('showStock')
            ->assertSet('view', 'stock')
            ->assertSee('Add item')
            ->assertSee('Chicken thighs')
            ->call('showChef')
            ->assertSet('view', 'chef');
    }

    public function test_failure_shows_a_friendly_note_with_retry(): void
    {
        $this->stocked('Chicken thighs');
        $this->bindChef(null);

        Volt::actingAs($this->user)->test('ai-chef')
            ->call('suggest')
            ->assertSet('failed', true)
            ->assertSee('scan your next shop')
            ->assertSee('Try again');
    }
}
