<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanonicalProduct>
 */
class CanonicalProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gtin' => fake()->unique()->ean13(),
            'brand' => fake()->company(),
            'name' => fake()->words(2, true),
            'variant' => null,
            'pack_size_value' => fake()->randomElement([250, 330, 500, 1000]),
            'pack_size_unit' => 'g',
            'category' => fake()->randomElement(['snacks', 'dairy', 'drinks', 'bakery']),
            'primary_image_path' => null,
        ];
    }

    /** A product with no barcode (identified by brand/name only). */
    public function withoutGtin(): static
    {
        return $this->state(fn () => ['gtin' => null]);
    }
}
