<?php

namespace Tests\Feature;

use App\Enums\ConsumptionType;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\InsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InsightCardUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('prism.providers.openrouter.api_key', ''); // deterministic path for the UI test
        $this->user = User::factory()->onboarded()->create();
    }

    private function seedFibreGap(): void
    {
        $oats = CanonicalProduct::factory()->create(['brand' => 'Mornflake', 'name' => 'Oats', 'category' => 'cereals']);
        ProductVersion::factory()->for($oats)->create(['serving_basis' => ServingBasis::Per100g, 'fibre' => 9.0]);
        PantryItem::factory()->for($this->user)->for($oats)->create([
            'current_quantity' => 2,
            'quantity_unit' => QuantityUnit::Unit,
        ]);

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
    }

    public function test_home_render_never_generates_the_insight(): void
    {
        $this->seedFibreGap();

        // The page itself must not wait for (or trigger) generation — the
        // insight arrives via the card's wire:init follow-up request.
        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertDontSee('Mornflake Oats');

        $this->assertSame(0, \App\Models\AiInsight::count());
    }

    public function test_the_card_loads_via_init_and_exposes_the_actions(): void
    {
        $this->seedFibreGap();

        Volt::actingAs($this->user)->test('insight-card')
            ->assertSet('loaded', false)
            ->call('load')
            ->assertSet('loaded', true)
            ->assertSee('Focus')
            ->assertSee('Why this matters')
            ->assertSee('What could I eat')
            // Disclosure is client-side now: both bodies ship in the DOM.
            ->assertSee('Fibre supports digestion')
            ->assertSee('Mornflake Oats')
            ->assertSee('Dismiss');
    }

    public function test_dismiss_hides_the_card_and_undo_brings_it_back(): void
    {
        $this->seedFibreGap();

        $component = Volt::actingAs($this->user)->test('insight-card')
            ->call('load')
            ->assertSee('Dismiss for this week')
            ->call('dismiss')
            ->assertDontSee('Dismiss for this week')
            ->assertSee('Undo');

        // Persisted: the page no longer shows it either.
        $this->assertNull(app(InsightService::class)->currentInsight($this->user));

        // A mis-tap costs nothing: undo restores the same insight.
        $component->call('undoDismiss')
            ->assertSee('Dismiss for this week');

        $this->assertNotNull(app(InsightService::class)->currentInsight($this->user));
    }

    public function test_card_is_absent_without_data(): void
    {
        Volt::actingAs($this->user)->test('insight-card')
            ->call('load')
            ->assertDontSee('Dismiss for this week');
    }
}
