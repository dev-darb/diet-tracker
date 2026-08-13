<?php

namespace Tests\Feature;

use App\Enums\ConsumptionType;
use App\Enums\PantryTransactionType;
use App\Enums\ProductVerificationStatus;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\ConsumptionItem;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\ConsumptionService;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ConsumptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConsumptionService $service;

    private PantryService $pantry;

    private User $user;

    private CanonicalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConsumptionService::class);
        $this->pantry = app(PantryService::class);
        $this->user = User::factory()->create();
        $this->product = CanonicalProduct::factory()->create([
            'brand' => 'Gym Kitchen',
            'name' => 'Katsu Chicken',
            'pack_size_value' => 189,
            'pack_size_unit' => 'g',
        ]);
    }

    /** A per-100g version: 200 kcal / 10g protein per 100g, 50g serving. */
    private function version(array $overrides = []): ProductVersion
    {
        return ProductVersion::factory()->for($this->product)->create([
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => 50,
            'serving_size_unit' => 'g',
            'calories' => 200,
            'protein' => 10,
            'carbs' => 20,
            'sugars' => 5,
            'fat' => 8,
            'saturated_fat' => 2,
            'fibre' => 3,
            'salt' => 1,
            ...$overrides,
        ]);
    }

    private function gramItem(float $quantity = 500): PantryItem
    {
        return $this->pantry->purchase($this->user, $this->product, $quantity, QuantityUnit::Gram);
    }

    public function test_consume_writes_snapshot_event_and_linked_ledger_row(): void
    {
        $version = $this->version();
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 100, QuantityUnit::Gram);

        // Pantry decremented.
        $this->assertSame(400.0, (float) $item->fresh()->current_quantity);

        // Event + snapshotted item created with correct deterministic totals.
        $this->assertSame(1, ConsumptionEvent::count());
        $this->assertSame(ConsumptionType::Single, $event->type);
        $this->assertSame('Gym Kitchen Katsu Chicken', $event->name);
        $this->assertSame(200.0, (float) $event->calories);   // 100g of a per-100g product
        $this->assertSame(10.0, (float) $event->protein);

        $line = $event->items()->firstOrFail();
        $this->assertSame($this->product->id, $line->canonical_product_id);
        $this->assertSame($version->id, $line->product_version_id);
        $this->assertSame(100.0, (float) $line->quantity);
        $this->assertSame(QuantityUnit::Gram, $line->unit);
        $this->assertSame(200.0, (float) $line->calories);

        // Ledger row linked to the event.
        $tx = $item->transactions()->where('type', PantryTransactionType::Consume)->firstOrFail();
        $this->assertSame(-100.0, (float) $tx->quantity_delta);
        $this->assertSame($event->id, $tx->linked_consumption_event_id);

        $this->assertTrue($this->pantry->reconcile($item->fresh()));
    }

    public function test_partial_custom_grams_snapshot_and_deduction(): void
    {
        $this->version();
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 150, QuantityUnit::Gram);

        $this->assertSame(350.0, (float) $item->fresh()->current_quantity);
        $this->assertSame(300.0, (float) $event->calories);   // 200 * 150/100
        $this->assertTrue($this->pantry->reconcile($item->fresh()));
    }

    public function test_partial_half_of_a_unit_item_uses_serving_size(): void
    {
        // Unit item: one whole "unit" == one 50g serving of a per-100g product.
        $this->version();
        $item = $this->pantry->purchase($this->user, $this->product, 4, QuantityUnit::Unit);

        // "Half" of 4 units = 2 units -> 2 * 50g = 100g -> 200 kcal.
        $event = $this->service->consumePantryItem($this->user, $item, 2, QuantityUnit::Unit);

        $this->assertSame(2.0, (float) $item->fresh()->current_quantity);
        $this->assertSame(200.0, (float) $event->calories);
        $this->assertTrue($this->pantry->reconcile($item->fresh()));
    }

    public function test_edit_recomputes_snapshot_and_adjusts_pantry(): void
    {
        $this->version();
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 100, QuantityUnit::Gram);
        $this->assertSame(400.0, (float) $item->fresh()->current_quantity);

        $newTime = Carbon::parse('2026-08-13 09:15:00');
        $this->service->editConsumption($event, 250, null, $newTime);

        // Snapshot recomputed for 250g.
        $event->refresh();
        $this->assertSame(500.0, (float) $event->calories);   // 200 * 250/100
        $this->assertSame(500.0, (float) $event->items()->first()->calories);
        $this->assertSame(250.0, (float) $event->items()->first()->quantity);
        $this->assertEquals($newTime->format('Y-m-d H:i'), $event->consumed_at->format('Y-m-d H:i'));

        // Net removal now 250g -> balance 250; a compensating correction was written.
        $item->refresh();
        $this->assertSame(250.0, (float) $item->current_quantity);
        $this->assertSame(1, $item->transactions()->where('type', PantryTransactionType::Correction)->count());
        $this->assertTrue($this->pantry->reconcile($item));
    }

    public function test_edit_down_restores_stock_and_reconciles(): void
    {
        $this->version();
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 300, QuantityUnit::Gram);
        $this->assertSame(200.0, (float) $item->fresh()->current_quantity);

        $this->service->editConsumption($event, 50);

        $item->refresh();
        $this->assertSame(450.0, (float) $item->current_quantity);   // 500 - 50
        $this->assertSame(100.0, (float) $event->fresh()->calories); // 200 * 50/100
        $this->assertTrue($this->pantry->reconcile($item));
    }

    public function test_delete_restores_pantry_via_compensating_transaction(): void
    {
        $this->version();
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 120, QuantityUnit::Gram);
        $this->assertSame(380.0, (float) $item->fresh()->current_quantity);
        $eventId = $event->id;

        $this->service->deleteConsumption($event);

        // Stock restored; event + items gone.
        $item->refresh();
        $this->assertSame(500.0, (float) $item->current_quantity);
        $this->assertSame(0, ConsumptionEvent::count());
        $this->assertSame(0, ConsumptionItem::count());

        // A compensating correction (+120) exists and the ledger reconciles.
        $this->assertSame(1, $item->transactions()->where('type', PantryTransactionType::Correction)->count());
        $this->assertNull($item->transactions()->where('type', PantryTransactionType::Consume)->first()->linked_consumption_event_id);
        $this->assertTrue($this->pantry->reconcile($item));
        $this->assertSame(0, ConsumptionEvent::whereKey($eventId)->count());
    }

    public function test_snapshot_is_immutable_when_product_is_later_reformulated(): void
    {
        $original = $this->version(['calories' => 200, 'effective_from' => now()->subDays(10)]);
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 100, QuantityUnit::Gram);
        $this->assertSame(200.0, (float) $event->items()->first()->calories);

        // Reformulate: newer, verified version with very different macros.
        ProductVersion::factory()->for($this->product)->create([
            'serving_basis' => ServingBasis::Per100g,
            'calories' => 999,
            'protein' => 99,
            'effective_from' => now(),
            'status' => ProductVerificationStatus::Verified,
        ]);

        // Historical snapshot unchanged, still tied to the original version.
        $line = $event->items()->firstOrFail()->fresh();
        $this->assertSame(200.0, (float) $line->calories);
        $this->assertSame($original->id, $line->product_version_id);
        $this->assertSame(200.0, (float) $event->fresh()->calories);
    }

    public function test_unknown_nutrients_propagate_as_null_never_zero(): void
    {
        $this->version()->update(['fibre' => null, 'salt' => null]);
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 100, QuantityUnit::Gram);

        $line = $event->items()->firstOrFail();
        $this->assertNull($line->fibre);
        $this->assertNull($line->salt);
        $this->assertNull($event->fibre);
        $this->assertSame(200.0, (float) $line->calories);   // known nutrient still computed
    }

    public function test_consume_without_a_usable_version_snapshots_all_unknown_but_still_deducts(): void
    {
        // No product version at all.
        $item = $this->gramItem(500);

        $event = $this->service->consumePantryItem($this->user, $item, 100, QuantityUnit::Gram);

        $this->assertSame(400.0, (float) $item->fresh()->current_quantity);
        $line = $event->items()->firstOrFail();
        $this->assertNull($line->calories);
        $this->assertNull($line->product_version_id);
        $this->assertTrue($this->pantry->reconcile($item->fresh()));
    }

    public function test_non_positive_quantity_is_rejected(): void
    {
        $this->version();
        $item = $this->gramItem(500);

        $this->expectException(InvalidArgumentException::class);
        $this->service->consumePantryItem($this->user, $item, 0, QuantityUnit::Gram);
    }

    public function test_mismatched_unit_is_rejected(): void
    {
        $this->version();
        $item = $this->gramItem(500);

        $this->expectException(InvalidArgumentException::class);
        $this->service->consumePantryItem($this->user, $item, 1, QuantityUnit::Unit);
    }
}
