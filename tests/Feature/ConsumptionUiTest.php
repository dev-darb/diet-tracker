<?php

namespace Tests\Feature;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\ConsumptionService;
use App\Services\PantryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ConsumptionUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PantryService $pantry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
        $this->pantry = app(PantryService::class);
    }

    private function stockedItem(): PantryItem
    {
        $product = CanonicalProduct::factory()->create(['brand' => 'Arla', 'name' => 'Protein Pudding']);
        ProductVersion::factory()->for($product)->create([
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => 100,
            'calories' => 90,
        ]);

        return $this->pantry->purchase($this->user, $product, 4, QuantityUnit::Unit);
    }

    public function test_pantry_consume_one_routes_through_consumption_service(): void
    {
        $item = $this->stockedItem();

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->call('consumePortion', 0)
            ->assertHasNoErrors();

        // A consumption event now exists (not just a bare ledger row).
        $this->assertSame(1, ConsumptionEvent::where('user_id', $this->user->id)->count());
        $this->assertSame(3.0, (float) $item->fresh()->current_quantity);

        $tx = $item->transactions()->where('type', PantryTransactionType::Consume)->firstOrFail();
        $this->assertNotNull($tx->linked_consumption_event_id);
    }

    public function test_eat_screen_lists_entries_grouped_with_today(): void
    {
        $item = $this->stockedItem();
        app(ConsumptionService::class)->consumePantryItem($this->user, $item, 1, QuantityUnit::Unit);

        Volt::actingAs($this->user)->test('eat')
            ->assertSee('Today')
            ->assertSee('Arla Protein Pudding');
    }

    public function test_eat_screen_edit_updates_snapshot_and_pantry(): void
    {
        $item = $this->stockedItem();
        $event = app(ConsumptionService::class)->consumePantryItem($this->user, $item, 1, QuantityUnit::Unit);
        $this->assertSame(3.0, (float) $item->fresh()->current_quantity);

        Volt::actingAs($this->user)->test('eat')
            ->call('startEdit', $event->id)
            ->set('editQuantity', '2')
            ->set('editTime', '2026-08-13T08:30')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(2.0, (float) $item->current_quantity);   // now 2 units consumed of 4
        $this->assertSame(2.0, (float) $event->fresh()->items()->first()->quantity);
        $this->assertTrue($this->pantry->reconcile($item));
    }

    public function test_eat_screen_delete_restores_pantry(): void
    {
        $item = $this->stockedItem();
        $event = app(ConsumptionService::class)->consumePantryItem($this->user, $item, 2, QuantityUnit::Unit);
        $this->assertSame(2.0, (float) $item->fresh()->current_quantity);

        Volt::actingAs($this->user)->test('eat')
            ->call('deleteEntry', $event->id)
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(4.0, (float) $item->current_quantity);
        $this->assertSame(0, ConsumptionEvent::count());
        $this->assertTrue($this->pantry->reconcile($item));
    }

    public function test_a_user_cannot_edit_another_users_consumption(): void
    {
        $item = $this->stockedItem();
        $event = app(ConsumptionService::class)->consumePantryItem($this->user, $item, 1, QuantityUnit::Unit);

        $other = User::factory()->onboarded()->create();

        // The component scopes every lookup to the acting user, so another user's
        // event is simply not found — it can never be deleted or edited.
        $this->expectException(ModelNotFoundException::class);

        Volt::actingAs($other)->test('eat')->call('deleteEntry', $event->id);

        $this->assertSame(1, ConsumptionEvent::whereKey($event->id)->count());
    }
}
