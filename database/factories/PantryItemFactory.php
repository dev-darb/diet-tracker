<?php

namespace Database\Factories;

use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PantryItem>
 */
class PantryItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'canonical_product_id' => CanonicalProduct::factory(),
            'current_quantity' => 0,
            'quantity_unit' => QuantityUnit::Unit,
            'purchased_at' => now(),
            'expiry_date' => null,
        ];
    }
}
