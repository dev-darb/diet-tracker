<?php

namespace Tests\Feature;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\User;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PantryUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    public function test_pantry_lists_only_items_with_stock(): void
    {
        $service = app(PantryService::class);
        $stocked = CanonicalProduct::factory()->create(['brand' => 'Full', 'name' => 'Jar']);
        $empty = CanonicalProduct::factory()->create(['brand' => 'Empty', 'name' => 'Tin']);

        $service->purchase($this->user, $stocked, 3, QuantityUnit::Unit);
        $item = $service->purchase($this->user, $empty, 1, QuantityUnit::Unit);
        $service->consume($item, 1); // back to zero

        Volt::actingAs($this->user)->test('pantry')
            ->assertSee('Full')
            ->assertDontSee('Empty');
    }

    public function test_manual_add_creates_a_pantry_item_via_service(): void
    {
        $product = CanonicalProduct::factory()->create();

        Volt::actingAs($this->user)->test('pantry')
            ->call('toggleAdd')
            ->call('selectProduct', $product->id)
            ->set('addQuantity', '2')
            ->set('addUnit', QuantityUnit::Unit->value)
            ->call('add')
            ->assertHasNoErrors();

        $item = PantryItem::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame(2.0, (float) $item->current_quantity);
        $this->assertSame(1, $item->transactions()->where('type', PantryTransactionType::Purchase)->count());
    }

    public function test_manual_add_requires_a_selected_product(): void
    {
        Volt::actingAs($this->user)->test('pantry')
            ->call('toggleAdd')
            ->set('addQuantity', '2')
            ->call('add')
            ->assertHasErrors(['selectedProductId']);
    }

    public function test_detail_consume_one_decrements_and_writes_ledger(): void
    {
        $service = app(PantryService::class);
        $product = CanonicalProduct::factory()->create();
        $item = $service->purchase($this->user, $product, 5, QuantityUnit::Unit);

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->call('consumePortion', 0)
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(4.0, (float) $item->current_quantity);
        $this->assertSame(1, $item->transactions()->where('type', PantryTransactionType::Consume)->count());
    }

    public function test_detail_change_quantity_records_a_correction(): void
    {
        $service = app(PantryService::class);
        $product = CanonicalProduct::factory()->create();
        $item = $service->purchase($this->user, $product, 5, QuantityUnit::Unit);

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->set('newQuantity', '2')
            ->call('changeQuantity')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(2.0, (float) $item->current_quantity);
        $this->assertSame(1, $item->transactions()->where('type', PantryTransactionType::Correction)->count());
        $this->assertTrue($service->reconcile($item));
    }

    public function test_detail_remove_empties_the_item_and_redirects(): void
    {
        $service = app(PantryService::class);
        $product = CanonicalProduct::factory()->create();
        $item = $service->purchase($this->user, $product, 5, QuantityUnit::Unit);

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->call('remove')
            ->assertRedirect(route('pantry'));

        $this->assertSame(0.0, (float) $item->fresh()->current_quantity);
        $this->assertTrue($service->reconcile($item->fresh()));
    }

    public function test_a_user_cannot_open_another_users_pantry_item(): void
    {
        $service = app(PantryService::class);
        $other = User::factory()->onboarded()->create();
        $product = CanonicalProduct::factory()->create();
        $item = $service->purchase($other, $product, 1, QuantityUnit::Unit);

        $this->actingAs($this->user)->get(route('pantry.item', $item))->assertForbidden();
    }
}
