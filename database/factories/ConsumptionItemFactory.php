<?php

namespace Database\Factories;

use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\ConsumptionItem;
use App\Models\ProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumptionItem>
 */
class ConsumptionItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'consumption_event_id' => ConsumptionEvent::factory(),
            'canonical_product_id' => CanonicalProduct::factory(),
            'product_version_id' => ProductVersion::factory(),
            'quantity' => 1,
            'unit' => QuantityUnit::Unit,
            'calories' => fake()->randomFloat(2, 50, 500),
            'protein' => fake()->randomFloat(2, 0, 30),
            'carbs' => fake()->randomFloat(2, 0, 60),
            'sugars' => fake()->randomFloat(2, 0, 40),
            'fat' => fake()->randomFloat(2, 0, 30),
            'saturated_fat' => fake()->randomFloat(2, 0, 15),
            'fibre' => fake()->randomFloat(2, 0, 12),
            'salt' => fake()->randomFloat(2, 0, 3),
        ];
    }
}
