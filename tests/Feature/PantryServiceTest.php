<?php

namespace Tests\Feature;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\User;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PantryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PantryService $service;

    private User $user;

    private CanonicalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PantryService;
        $this->user = User::factory()->create();
        $this->product = CanonicalProduct::factory()->create();
    }

    public function test_first_purchase_creates_item_and_transaction(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 3, QuantityUnit::Unit);

        $this->assertSame(3.0, (float) $item->current_quantity);
        $this->assertSame(QuantityUnit::Unit, $item->quantity_unit);
        $this->assertSame(1, $item->transactions()->count());

        $tx = $item->transactions()->first();
        $this->assertSame(PantryTransactionType::Purchase, $tx->type);
        $this->assertSame(3.0, (float) $tx->quantity_delta);
    }

    public function test_repurchase_increments_existing_item(): void
    {
        $this->service->purchase($this->user, $this->product, 3, QuantityUnit::Unit);
        $item = $this->service->purchase($this->user, $this->product, 2, QuantityUnit::Unit);

        $this->assertSame(1, PantryItem::count());
        $this->assertSame(5.0, (float) $item->fresh()->current_quantity);
        $this->assertSame(2, $item->transactions()->count());
    }

    public function test_consume_decrements_the_balance(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 5, QuantityUnit::Unit);
        $this->service->consume($item, 2);

        $this->assertSame(3.0, (float) $item->fresh()->current_quantity);
    }

    public function test_discard_and_manual_remove(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 10, QuantityUnit::Unit);
        $this->service->discard($item, 1);
        $this->service->manualRemove($item, 2);

        $this->assertSame(7.0, (float) $item->fresh()->current_quantity);
    }

    public function test_cache_reconciles_with_ledger_after_a_mixed_sequence(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 10, QuantityUnit::Unit);
        $this->service->consume($item, 3);
        $this->service->discard($item, 1);
        $this->service->consume($item, 2);
        $this->service->correct($item, 3.5);   // set absolute balance
        $this->service->manualRemove($item, 0.5);

        $item->refresh();
        $ledgerSum = round((float) $item->transactions()->sum('quantity_delta'), 3);

        $this->assertSame($ledgerSum, (float) $item->current_quantity);
        $this->assertSame(3.0, $ledgerSum);
        $this->assertTrue($this->service->reconcile($item));
        $this->assertSame(3.0, $this->service->recomputeBalance($item));
    }

    public function test_fractional_grams_reconcile_exactly(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 250.5, QuantityUnit::Gram);
        $this->service->consume($item, 30.25);
        $this->service->consume($item, 100.125);

        $item->refresh();

        $this->assertSame(120.125, (float) $item->current_quantity);
        $this->assertTrue($this->service->reconcile($item));
    }

    /**
     * Over-consume rule: consume/discard/manual_remove CLAMP the removed amount
     * to the current balance, so it floors at zero and the recorded delta is
     * what was actually removed. The ledger still reconciles.
     */
    public function test_over_consume_is_clamped_and_never_negative(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 2, QuantityUnit::Unit);
        $tx = $this->service->consume($item, 5); // only 2 available

        $item->refresh();

        $this->assertSame(0.0, (float) $item->current_quantity);
        $this->assertSame(-2.0, (float) $tx->quantity_delta); // actual removed, not -5
        $this->assertTrue($this->service->reconcile($item));
    }

    public function test_correction_applies_a_signed_delta_up_or_down(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 4, QuantityUnit::Unit);

        $up = $this->service->correct($item, 6);
        $this->assertSame(2.0, (float) $up->quantity_delta);
        $this->assertSame(6.0, (float) $item->fresh()->current_quantity);

        $down = $this->service->correct($item->fresh(), 1);
        $this->assertSame(-5.0, (float) $down->quantity_delta);
        $this->assertSame(1.0, (float) $item->fresh()->current_quantity);
        $this->assertTrue($this->service->reconcile($item->fresh()));
    }

    public function test_non_positive_purchase_and_consume_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->purchase($this->user, $this->product, 0, QuantityUnit::Unit);
    }

    public function test_negative_consume_is_rejected(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 5, QuantityUnit::Unit);

        $this->expectException(InvalidArgumentException::class);
        $this->service->consume($item, -1);
    }

    public function test_negative_correction_target_is_rejected(): void
    {
        $item = $this->service->purchase($this->user, $this->product, 5, QuantityUnit::Unit);

        $this->expectException(InvalidArgumentException::class);
        $this->service->correct($item, -1);
    }

    public function test_separate_items_are_kept_per_unit(): void
    {
        $units = $this->service->purchase($this->user, $this->product, 3, QuantityUnit::Unit);
        $grams = $this->service->purchase($this->user, $this->product, 500, QuantityUnit::Gram);

        $this->assertNotSame($units->id, $grams->id);
        $this->assertSame(2, PantryItem::count());
    }
}
