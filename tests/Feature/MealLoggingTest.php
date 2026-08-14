<?php

namespace Tests\Feature;

use App\Enums\ConsumptionType;
use App\Enums\MealContext;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\ConsumptionService;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Phase A of the capture flow (BUILD_PLAN "Reframe, Aug 2026"): the ledger
 * records every meal — home-cooked composed from pantry, eating out with
 * honest estimates — and usuals come from the user's own history.
 */
class MealLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ConsumptionService $consumption;

    private PantryService $pantry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
        $this->consumption = app(ConsumptionService::class);
        $this->pantry = app(PantryService::class);
    }

    /** A gram-stocked component with per-100g figures: easy deterministic sums. */
    private function gramItem(string $name, float $stock, float $kcalPer100g, float $proteinPer100g = 10.0)
    {
        $product = CanonicalProduct::factory()->create(['name' => $name, 'pack_size_value' => null]);
        ProductVersion::factory()->for($product)->create([
            'calories' => $kcalPer100g,
            'protein' => $proteinPer100g,
            'serving_size_value' => null,
        ]);

        return $this->pantry->purchase($this->user, $product, $stock, QuantityUnit::Gram);
    }

    // --- home-cooked meals ---------------------------------------------------

    public function test_home_cooked_meal_sums_components_and_deducts_each_pantry_item(): void
    {
        $chicken = $this->gramItem('Chicken thighs', 500, 200, 18);
        $rice = $this->gramItem('Basmati rice', 1000, 350, 8);

        $event = $this->consumption->logHomeCookedMeal($this->user, 'Chicken curry', [
            ['item' => $chicken, 'quantity' => 200, 'portion_label' => null],
            ['item' => $rice, 'quantity' => 100, 'portion_label' => null],
        ]);

        // Deterministic totals: 200g @200/100g = 400 kcal + 100g @350/100g = 350 kcal.
        $this->assertSame(ConsumptionType::Meal, $event->type);
        $this->assertSame(MealContext::HomeCooked, $event->context);
        $this->assertFalse($event->estimated);
        $this->assertSame(750.0, (float) $event->calories);
        $this->assertSame(2, $event->items()->count());

        // BOTH pantry items deducted, both linked to the one event.
        $this->assertSame(300.0, (float) $chicken->fresh()->current_quantity);
        $this->assertSame(900.0, (float) $rice->fresh()->current_quantity);
        $this->assertSame(2, $event->pantryTransactions()->count());
    }

    public function test_deleting_a_meal_restores_every_component(): void
    {
        $chicken = $this->gramItem('Chicken thighs', 500, 200);
        $rice = $this->gramItem('Basmati rice', 1000, 350);

        $event = $this->consumption->logHomeCookedMeal($this->user, 'Curry', [
            ['item' => $chicken, 'quantity' => 200],
            ['item' => $rice, 'quantity' => 100],
        ]);

        $this->consumption->deleteConsumption($event);

        $this->assertSame(500.0, (float) $chicken->fresh()->current_quantity);
        $this->assertSame(1000.0, (float) $rice->fresh()->current_quantity);
        $this->assertSame(0, ConsumptionEvent::count());
    }

    public function test_meals_reject_amount_editing(): void
    {
        $chicken = $this->gramItem('Chicken', 500, 200);
        $event = $this->consumption->logHomeCookedMeal($this->user, 'Curry', [
            ['item' => $chicken, 'quantity' => 200],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->consumption->editConsumption($event, 1.5);
    }

    public function test_a_meal_cannot_use_another_users_pantry(): void
    {
        $other = User::factory()->onboarded()->create();
        $product = CanonicalProduct::factory()->create();
        $theirs = $this->pantry->purchase($other, $product, 500, QuantityUnit::Gram);

        $this->expectException(InvalidArgumentException::class);
        $this->consumption->logHomeCookedMeal($this->user, 'Sneaky', [
            ['item' => $theirs, 'quantity' => 100],
        ]);
    }

    // --- eating out ----------------------------------------------------------

    public function test_eating_out_stores_estimates_and_honest_nulls(): void
    {
        $event = $this->consumption->logEatingOut($this->user, 'Katsu curry at Wagamama', [
            'calories' => 850,
            'protein' => 32,
        ]);

        $this->assertSame(MealContext::EatingOut, $event->context);
        $this->assertTrue($event->estimated);
        $this->assertSame(850.0, (float) $event->calories);
        $this->assertSame(32.0, (float) $event->protein);
        $this->assertNull($event->carbs);   // unknown stays unknown — never 0
        $this->assertNull($event->salt);
        $this->assertSame(0, $event->items()->count());
    }

    public function test_eating_out_with_no_figures_still_goes_on_the_record(): void
    {
        // Completeness beats precision: the meal happened, the record shows it.
        $event = $this->consumption->logEatingOut($this->user, 'Dinner with friends');

        $this->assertNull($event->calories);
        $this->assertTrue($event->estimated);
        $this->assertSame(1, ConsumptionEvent::where('user_id', $this->user->id)->count());
    }

    // --- the capture flow UI -------------------------------------------------

    public function test_log_flow_composes_a_home_cooked_meal_from_pantry(): void
    {
        $chicken = $this->gramItem('Chicken thighs', 500, 200);

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseHomeCooked')
            ->assertSet('step', 'home')
            ->call('addComponent', $chicken->id)
            ->set('components.'.$chicken->id.'.choice', 'custom')
            ->set('components.'.$chicken->id.'.custom', '250')
            ->set('mealName', 'Roast chicken')
            ->call('logHomeCooked')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $event = ConsumptionEvent::firstOrFail();
        $this->assertSame('Roast chicken', $event->name);
        $this->assertSame(500.0, (float) $event->calories); // 250g @ 200/100g
        $this->assertSame(250.0, (float) $chicken->fresh()->current_quantity);
    }

    public function test_log_flow_records_an_eating_out_meal(): void
    {
        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('outName', 'Full English at the cafe')
            ->set('outCalories', '900')
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $event = ConsumptionEvent::firstOrFail();
        $this->assertSame(MealContext::EatingOut, $event->context);
        $this->assertSame(900.0, (float) $event->calories);

        // Eat shows the estimate honestly.
        Volt::actingAs($this->user)->test('eat')
            ->assertSee('Full English at the cafe')
            ->assertSee('~900')
            ->assertSee('ESTIMATED');
    }

    public function test_eating_out_usual_relogs_in_one_tap(): void
    {
        $this->consumption->logEatingOut($this->user, 'Flat white + croissant', ['calories' => 460]);

        Volt::actingAs($this->user)->test('log-meal')
            ->assertSee('Flat white + croissant')
            ->call('useUsual', ConsumptionEvent::firstOrFail()->id)
            ->assertSet('step', 'done');

        $this->assertSame(2, ConsumptionEvent::count());
        $latest = ConsumptionEvent::latest('id')->firstOrFail();
        $this->assertSame('Flat white + croissant', $latest->name);
        $this->assertSame(460.0, (float) $latest->calories);
        $this->assertTrue($latest->estimated);
    }

    public function test_home_cooked_usual_prefills_the_compose_screen(): void
    {
        $chicken = $this->gramItem('Chicken thighs', 500, 200);
        $this->consumption->logHomeCookedMeal($this->user, 'Roast chicken', [
            ['item' => $chicken, 'quantity' => 250],
        ]);

        Volt::actingAs($this->user)->test('log-meal')
            ->call('useUsual', ConsumptionEvent::firstOrFail()->id)
            ->assertSet('step', 'home')          // prefilled, NOT auto-logged
            ->assertSet('mealName', 'Roast chicken')
            ->assertSet('components.'.$chicken->id.'.custom', '250');

        $this->assertSame(1, ConsumptionEvent::count()); // nothing logged yet
    }

    public function test_another_users_usual_cannot_be_relogged(): void
    {
        $other = User::factory()->onboarded()->create();
        $this->consumption->logEatingOut($other, 'Their dinner', ['calories' => 700]);

        Volt::actingAs($this->user)->test('log-meal')
            ->call('useUsual', ConsumptionEvent::firstOrFail()->id)
            ->assertSet('step', 'context');

        $this->assertSame(1, ConsumptionEvent::count());
    }
}
