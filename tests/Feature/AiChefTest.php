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
 * The RESIDENT chef (tranche 4, Aug 2026): a compact, time-of-day-aware card
 * on the Pantry's stock face, backed by durable chef_suggestions rows.
 * Grounding: only offered ids may be claimed; cooked suggestions are records
 * and never overwritten; keyless -> no chef, the inventory stands alone.
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

    private function curryIdeas(int $pantryItemId, ?string $slot = null): RecipeIdeas
    {
        return new RecipeIdeas([[
            'slot' => $slot ?? app(\App\Services\ChefService::class)->slotNow(),
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
        ]]);
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

        $this->assertDatabaseHas('ai_jobs', ['task_type' => 'recipe_suggestion', 'result_status' => 'suggested']);
    }

    public function test_the_resident_card_shows_the_current_slots_dish(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $this->bindChef($this->curryIdeas($chicken->id));

        Volt::actingAs($this->user)->test('pantry-chef')
            ->call('load')
            ->assertHasNoErrors()
            ->assertSee('Coconut chicken curry')
            ->assertSee('Protein-forward and simple.')
            ->assertSee('Cooked this')
            ->assertSee('Chicken thighs')
            ->assertSee('In stock')
            ->assertSee('To get')
            ->assertSee('~650 KCAL');

        // The durable artifact: the suggestion persisted for the slot.
        $this->assertDatabaseHas('chef_suggestions', [
            'user_id' => $this->user->id,
            'title' => 'Coconut chicken curry',
        ]);
    }

    public function test_the_artifact_is_durable_and_another_idea_regenerates(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $calls = new class
        {
            public int $count = 0;
        };

        $slot = app(\App\Services\ChefService::class)->slotNow();
        $this->app->bind(RecipeSuggester::class, fn () => new class($chicken->id, $calls, $slot) implements RecipeSuggester
        {
            public function __construct(private readonly int $itemId, private $calls, private readonly string $slot) {}

            public function available(): bool
            {
                return true;
            }

            public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
            {
                $this->calls->count++;

                return new RecipeIdeas([[
                    'slot' => $this->slot, 'title' => 'Idea #'.$this->calls->count, 'summary' => '',
                    'ingredients' => [['name' => 'Chicken', 'amount' => '150 g', 'pantry_item_id' => $this->itemId]],
                    'upgrades' => [], 'steps' => ['Cook'],
                    'approx_calories' => null, 'approx_protein' => null,
                ]]);
            }
        });

        $component = Volt::actingAs($this->user)->test('pantry-chef')->call('load');
        $component->call('load'); // same slot, same day -> the stored row, no new call
        $this->assertSame(1, $calls->count);

        $component->call('anotherIdea'); // explicit regenerate
        $this->assertSame(2, $calls->count);
        $this->assertSame(1, \App\Models\ChefSuggestion::query()->where('slot', $slot)->count());
    }

    public function test_cooked_this_prefills_compose_and_flips_the_card(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $this->bindChef($this->curryIdeas($chicken->id));

        // Generate today's suggestion.
        Volt::actingAs($this->user)->test('pantry-chef')->call('load');
        $suggestion = \App\Models\ChefSuggestion::query()->firstOrFail();

        // The bridge prefills home-cooked compose with the dish + stock.
        $this->actingAs($this->user)
            ->get('/eat/log?chef='.$suggestion->id)
            ->assertOk()
            ->assertSee('From the chef: Coconut chicken curry')
            ->assertSee('Chicken thighs');

        // Logging it links back and the card flips to cooked.
        Volt::actingAs($this->user)->test('log-meal')
            ->set('chefId', $suggestion->id)
            ->set('mealName', $suggestion->title)
            ->call('addComponent', $chicken->id)
            ->call('logHomeCooked')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $suggestion->refresh();
        $this->assertNotNull($suggestion->consumption_event_id);

        Volt::actingAs($this->user)->test('pantry-chef')
            ->call('load')
            ->assertSee('COOKED')
            ->assertDontSee('Cooked this');
    }

    public function test_a_cooked_suggestion_is_never_overwritten_by_regeneration(): void
    {
        $chicken = $this->stocked('Chicken thighs');
        $this->bindChef($this->curryIdeas($chicken->id));

        $chef = app(\App\Services\ChefService::class);
        $suggestion = $chef->plan($this->user);
        $event = app(\App\Services\ConsumptionService::class)->consumePantryItem($this->user, $chicken->fresh(), 100);
        $chef->markCooked($suggestion, $event);

        $this->bindChef($this->curryIdeas($chicken->id)); // a "different" plan
        $chef = app(\App\Services\ChefService::class);
        $chef->refresh($this->user);

        $this->assertSame($suggestion->id, $chef->current($this->user)->id);
        $this->assertNotNull($chef->current($this->user)->consumption_event_id);
    }

    public function test_keyless_pantry_has_no_chef_and_the_inventory_stands_alone(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->assertInstanceOf(UnavailableRecipeSuggester::class, app(RecipeSuggester::class));

        $this->stocked('Chicken thighs');

        Volt::actingAs($this->user)->test('pantry-chef')
            ->call('load')
            ->assertDontSee('Cooked this');

        Volt::actingAs($this->user)->test('pantry')
            ->assertSee('Add item')
            ->assertSee('Chicken thighs');
    }
}
