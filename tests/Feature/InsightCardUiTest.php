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

    public function test_home_renders_the_focus_card_with_data(): void
    {
        $this->seedFibreGap();

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Focus')
            ->assertSee('Mornflake Oats')
            ->assertSee('Why this matters')
            ->assertSee('Dismiss');
    }

    public function test_the_card_component_exposes_the_actions(): void
    {
        $this->seedFibreGap();

        Volt::actingAs($this->user)->test('insight-card')
            ->assertSee('Focus')
            ->assertSee('What could I eat')
            ->assertSet('why', false)
            ->call('toggleWhy')
            ->assertSet('why', true)
            ->assertSee('Fibre supports digestion')
            ->call('toggleEat')
            ->assertSet('eat', true)
            ->assertSee('Mornflake Oats');
    }

    public function test_dismiss_hides_the_card(): void
    {
        $this->seedFibreGap();

        Volt::actingAs($this->user)->test('insight-card')
            ->assertSee('Focus')
            ->call('dismiss')
            ->assertDontSee('Focus');

        // Persisted: the page no longer shows it either.
        $this->assertNull(app(InsightService::class)->currentInsight($this->user));
    }

    public function test_card_is_absent_without_data(): void
    {
        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertDontSee('Focus');
    }
}
