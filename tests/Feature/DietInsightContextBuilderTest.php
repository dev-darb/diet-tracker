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
use App\Services\DietInsightContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DietInsightContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private DietInsightContextBuilder $builder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(DietInsightContextBuilder::class);
        $this->user = User::factory()->onboarded()->create();
    }

    private function logDay(string $date, array $nutrients, ?CanonicalProduct $product = null): void
    {
        ConsumptionEvent::factory()->for($this->user)->create([
            'type' => ConsumptionType::Single,
            'consumed_at' => Carbon::parse($date.' 12:00:00'),
            // Real writes snapshot totals on BOTH event and item; day totals
            // now read the event (so eating-out entries count), so mirror it.
            ...array_merge(array_fill_keys([
                'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
            ], 0.0), $nutrients),
        ])->items()->create([
            'canonical_product_id' => $product?->id,
            'quantity' => 1,
            'unit' => QuantityUnit::Unit,
            ...array_merge(array_fill_keys([
                'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
            ], 0.0), $nutrients),
        ]);
    }

    public function test_it_includes_the_analytics_summary_and_pantry_but_not_raw_history(): void
    {
        $product = CanonicalProduct::factory()->create(['brand' => 'Mornflake', 'name' => 'Oats', 'category' => 'cereals']);
        ProductVersion::factory()->for($product)->create([
            'serving_basis' => ServingBasis::Per100g,
            'fibre' => 9.0,
            'protein' => 11.0,
        ]);
        PantryItem::factory()->for($this->user)->for($product)->create([
            'current_quantity' => 2,
            'quantity_unit' => QuantityUnit::Unit,
        ]);

        $this->logDay(now()->toDateString(), ['calories' => 500, 'fibre' => 10, 'protein' => 20], $product);

        $context = $this->builder->build($this->user);
        $array = $context->toArray();

        // Deterministic analytics summary is present.
        $this->assertTrue($context->hasData());
        $this->assertArrayHasKey('weekly', $array);
        $this->assertArrayHasKey('indicators', $array['weekly']);

        // Pantry is present with per-100g nutrients + a display name.
        $this->assertArrayHasKey('pantry', $array);
        $this->assertCount(1, $array['pantry']);
        $this->assertSame('Mornflake Oats', $array['pantry'][0]['name']);
        $this->assertSame(9.0, $array['pantry'][0]['per_100g']['fibre']);

        // NO raw consumption history is handed to the LLM (brief §9.8).
        $this->assertArrayNotHasKey('events', $array);
        $this->assertArrayNotHasKey('consumption_items', $array);
        $this->assertArrayNotHasKey('items', $array);
    }

    public function test_it_converts_per_serving_pantry_nutrients_to_per_100g(): void
    {
        $product = CanonicalProduct::factory()->create(['brand' => 'Arla', 'name' => 'Protein Pudding']);
        // Per-serving figures: 200g serving carrying 20g protein => 10g/100g.
        ProductVersion::factory()->for($product)->create([
            'serving_basis' => ServingBasis::PerServing,
            'serving_size_value' => 200,
            'serving_size_unit' => 'g',
            'protein' => 20.0,
        ]);
        PantryItem::factory()->for($this->user)->for($product)->create([
            'current_quantity' => 1,
            'quantity_unit' => QuantityUnit::Unit,
        ]);
        $this->logDay(now()->toDateString(), ['calories' => 300]);

        $context = $this->builder->build($this->user);

        $this->assertSame(10.0, $context->pantry[0]['per_100g']['protein']);
    }

    public function test_it_excludes_out_of_stock_pantry_items(): void
    {
        $product = CanonicalProduct::factory()->create();
        ProductVersion::factory()->for($product)->create();
        PantryItem::factory()->for($this->user)->for($product)->create(['current_quantity' => 0]);
        $this->logDay(now()->toDateString(), ['calories' => 300]);

        $context = $this->builder->build($this->user);

        $this->assertSame([], $context->pantry);
    }
}
