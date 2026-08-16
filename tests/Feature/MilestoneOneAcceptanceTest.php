<?php

namespace Tests\Feature;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\User;
use App\Services\PantryNutritionService;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Milestone 1 acceptance test (BUILD_PLAN §6 M1 AC) — the milestone's definition
 * of done, driven end-to-end through the real UI components:
 *
 *   admin creates a canonical product + version with KNOWN macros
 *     → a normal user manually adds N of it to their pantry
 *     → the pantry shows the correct quantity AND the correct computed nutrition
 *     → the user consumes some
 *     → the quantity decreases, a consume ledger row exists,
 *       and PantryService::reconcile holds (cache == Σ ledger).
 */
class MilestoneOneAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_milestone_one_acceptance_loop(): void
    {
        // --- 1. Admin creates a product + version with known macros ----------
        $admin = User::factory()->admin()->create();

        Volt::actingAs($admin)->test('admin.products.create')
            ->set('brand', 'Testco')
            ->set('name', 'Rolled Oats')
            ->set('pack_size_value', '500')
            ->set('pack_size_unit', 'g')
            ->set('withVersion', true)
            ->set('serving_basis', ServingBasis::Per100g->value)
            ->set('nutrients.calories', '100')   // per 100g
            ->set('nutrients.protein', '5')
            ->set('nutrients.carbs', '10')
            ->set('nutrients.sugars', '2')
            ->set('nutrients.fat', '3')
            ->set('nutrients.saturated_fat', '1')
            ->set('nutrients.fibre', '4')
            ->set('nutrients.salt', '0.5')
            ->set('confidence', '1.0')
            ->call('save')
            ->assertHasNoErrors();

        $product = CanonicalProduct::where('name', 'Rolled Oats')->firstOrFail();
        $this->assertSame(1, $product->versions()->count());

        // --- 2. A normal user manually adds 500g to their pantry -------------
        $user = User::factory()->onboarded()->create();

        Volt::actingAs($user)->test('pantry')
            ->call('selectProduct', $product->id)
            ->set('addQuantity', '500')
            ->set('addUnit', QuantityUnit::Gram->value)
            ->call('add')
            ->assertHasNoErrors();

        $item = PantryItem::where('user_id', $user->id)->firstOrFail();

        // --- 3. Pantry shows the correct quantity AND computed nutrition -----
        $this->assertSame(500.0, (float) $item->current_quantity);

        $nutrition = app(PantryNutritionService::class);

        // 500g at 100 kcal / 100g = 500 kcal, 25g protein, etc. (deterministic).
        $values = $nutrition->currentNutrition($item->fresh());
        $this->assertNotNull($values);
        $this->assertSame(500.0, round($values->calories, 2));
        $this->assertSame(25.0, round($values->protein, 2));
        $this->assertSame(50.0, round($values->carbs, 2));
        $this->assertSame(15.0, round($values->fat, 2));
        $this->assertSame(2.5, round($values->salt, 2));

        // The detail screen renders those figures.
        Volt::actingAs($user)->test('pantry-item', ['pantryItem' => $item->fresh()])
            ->assertSee('500')   // quantity + calories
            ->assertSee('remaining');

        // --- 4. Consume some, through the detail component -------------------
        Volt::actingAs($user)->test('pantry-item', ['pantryItem' => $item->fresh()])
            ->set('consumeAmount', '200')
            ->call('consume')
            ->assertHasNoErrors();

        $item->refresh();

        // Quantity decreased.
        $this->assertSame(300.0, (float) $item->current_quantity);

        // A consume ledger row exists.
        $consumeRows = $item->transactions()->where('type', PantryTransactionType::Consume)->get();
        $this->assertCount(1, $consumeRows);
        $this->assertSame(-200.0, (float) $consumeRows->first()->quantity_delta);

        // Nutrition tracks the new balance: 300g -> 300 kcal, 15g protein.
        $after = $nutrition->currentNutrition($item);
        $this->assertSame(300.0, round($after->calories, 2));
        $this->assertSame(15.0, round($after->protein, 2));

        // The ledger reconciles: cache == Σ ledger.
        $service = app(PantryService::class);
        $this->assertTrue($service->reconcile($item));
        $this->assertSame(300.0, $service->recomputeBalance($item));
    }
}
