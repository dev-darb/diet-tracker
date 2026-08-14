<?php

namespace Tests\Feature;

use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\ConsumptionService;
use App\Services\PantryService;
use App\Services\PortionSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Natural-portion consumption, Phase 1 (portions design; brief §8.3).
 *
 * The contract under test: chips are derived deterministically from pack size,
 * stated serving size and the user's own last portion; every chip resolves to a
 * plain quantity in the item's OWN unit before any maths; the human wording is
 * snapshotted onto the consumption row and echoed in history; an edited amount
 * drops the stale wording.
 */
class PortionSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PortionSuggestionService $portions;

    private PantryService $pantry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
        $this->portions = app(PortionSuggestionService::class);
        $this->pantry = app(PantryService::class);
    }

    // --- suggestion derivation ---------------------------------------------

    public function test_counted_item_offers_one_half_and_all_with_pack_hints(): void
    {
        $product = CanonicalProduct::factory()->create(['pack_size_value' => 48, 'pack_size_unit' => 'g']);
        $item = $this->pantry->purchase($this->user, $product, 3, QuantityUnit::Unit);

        $chips = $this->portions->suggestionsFor($item, $this->user);

        $this->assertSame(['I ate one', 'Half of one', 'All of them'], array_column($chips, 'label'));
        $this->assertSame([1.0, 0.5, 3.0], array_column($chips, 'quantity'));
        $this->assertSame('48 g', $chips[0]['hint']);
        $this->assertSame('24 g', $chips[1]['hint']);
        $this->assertSame('144 g', $chips[2]['hint']);
        $this->assertSame('one (48 g)', $chips[0]['record']);
    }

    public function test_measured_item_offers_serving_and_pack_fractions(): void
    {
        $product = CanonicalProduct::factory()->create(['pack_size_value' => 400, 'pack_size_unit' => 'g']);
        ProductVersion::factory()->for($product)->create([
            'serving_size_value' => 30,
            'serving_size_unit' => 'g',
        ]);
        $item = $this->pantry->purchase($this->user, $product, 400, QuantityUnit::Gram);

        $chips = $this->portions->suggestionsFor($item, $this->user);

        $this->assertSame(['A serving', 'Half the pack', 'The whole pack'], array_column($chips, 'label'));
        $this->assertSame([30.0, 200.0, 400.0], array_column($chips, 'quantity'));
        $this->assertSame('a serving (30 g)', $chips[0]['record']);
        $this->assertSame('half the pack (200 g)', $chips[1]['record']);
    }

    public function test_serving_in_a_different_unit_is_not_offered(): void
    {
        // A "1 biscuit"-style serving parsed in a non-g unit must not leak into
        // a gram-measured item's chips (cross-unit consumption is M5).
        $product = CanonicalProduct::factory()->create(['pack_size_value' => null]);
        ProductVersion::factory()->for($product)->create([
            'serving_size_value' => 1,
            'serving_size_unit' => 'biscuit',
        ]);
        $item = $this->pantry->purchase($this->user, $product, 300, QuantityUnit::Gram);

        $this->assertSame([], $this->portions->suggestionsFor($item, $this->user));
    }

    public function test_last_portion_leads_and_skips_duplicates(): void
    {
        $product = CanonicalProduct::factory()->create(['pack_size_value' => 48, 'pack_size_unit' => 'g']);
        // Stock 5 so the remaining balance (3) never collides with the last
        // portion (2) — a collision would rightly suppress the leading chip.
        $item = $this->pantry->purchase($this->user, $product, 5, QuantityUnit::Unit);

        // Log an unusual portion — it should lead next time.
        app(ConsumptionService::class)->consumePantryItem($this->user, $item, 2.0, portionLabel: 'two (96 g)');

        $chips = $this->portions->suggestionsFor($item->refresh(), $this->user);
        $this->assertSame('Same as last time', $chips[0]['label']);
        $this->assertSame(2.0, $chips[0]['quantity']);
        $this->assertSame('two (96 g)', $chips[0]['record']);

        // A last portion of exactly 1 duplicates "I ate one" — no leading chip.
        app(ConsumptionService::class)->consumePantryItem($this->user, $item->refresh(), 1.0);
        $chips = $this->portions->suggestionsFor($item->refresh(), $this->user);
        $this->assertSame('I ate one', $chips[0]['label']);
    }

    // --- persistence + display ---------------------------------------------

    public function test_chip_consumption_snapshots_the_portion_label(): void
    {
        $product = CanonicalProduct::factory()->create(['pack_size_value' => 48, 'pack_size_unit' => 'g']);
        ProductVersion::factory()->for($product)->create(['calories' => 250]);
        $item = $this->pantry->purchase($this->user, $product, 3, QuantityUnit::Unit);

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->call('consumePortion', 0) // "I ate one"
            ->assertHasNoErrors();

        $line = ConsumptionItem::latest('id')->firstOrFail();
        $this->assertSame('one (48 g)', $line->portion_label);
        $this->assertSame(1.0, (float) $line->quantity);
        $this->assertSame(2.0, (float) $item->fresh()->current_quantity);

        // Eat history echoes the wording instead of "1 UNIT".
        Volt::actingAs($this->user)->test('eat')->assertSee('one (48 g)');
    }

    public function test_custom_amount_has_no_portion_label(): void
    {
        $product = CanonicalProduct::factory()->create();
        $item = $this->pantry->purchase($this->user, $product, 500, QuantityUnit::Gram);

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->set('consumeAmount', '137')
            ->call('consume')
            ->assertHasNoErrors();

        $this->assertNull(ConsumptionItem::latest('id')->firstOrFail()->portion_label);
    }

    public function test_editing_an_entry_clears_the_stale_portion_label(): void
    {
        $product = CanonicalProduct::factory()->create(['pack_size_value' => 48, 'pack_size_unit' => 'g']);
        $item = $this->pantry->purchase($this->user, $product, 3, QuantityUnit::Unit);

        $service = app(ConsumptionService::class);
        $event = $service->consumePantryItem($this->user, $item, 1.0, portionLabel: 'one (48 g)');

        $service->editConsumption($event, 1.5);

        $this->assertNull($event->items()->firstOrFail()->portion_label);
    }

    public function test_chip_index_is_rederived_server_side(): void
    {
        // An out-of-range index (stale UI, tampered payload) is a no-op, never
        // an arbitrary quantity.
        $product = CanonicalProduct::factory()->create();
        $item = $this->pantry->purchase($this->user, $product, 2, QuantityUnit::Unit);

        Volt::actingAs($this->user)->test('pantry-item', ['pantryItem' => $item])
            ->call('consumePortion', 99)
            ->assertHasNoErrors();

        $this->assertSame(2.0, (float) $item->fresh()->current_quantity);
        $this->assertSame(0, ConsumptionItem::count());
    }
}
