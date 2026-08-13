<?php

namespace Database\Factories;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\ProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVersion>
 */
class ProductVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'canonical_product_id' => CanonicalProduct::factory(),
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => 30,
            'serving_size_unit' => 'g',
            'calories' => fake()->randomFloat(2, 40, 550),
            'protein' => fake()->randomFloat(2, 0, 30),
            'carbs' => fake()->randomFloat(2, 0, 80),
            'sugars' => fake()->randomFloat(2, 0, 60),
            'fat' => fake()->randomFloat(2, 0, 40),
            'saturated_fat' => fake()->randomFloat(2, 0, 20),
            'fibre' => fake()->randomFloat(2, 0, 15),
            'salt' => fake()->randomFloat(2, 0, 3),
            'ingredients' => fake()->sentence(),
            'allergens' => [],
            'effective_from' => now(),
            'verified_at' => null,
            'status' => ProductVerificationStatus::Verified,
        ];
    }

    public function perServing(): static
    {
        return $this->state(fn () => ['serving_basis' => ServingBasis::PerServing]);
    }

    public function status(ProductVerificationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * A version with some nutrients "not stated" (null/unknown) — the shape Open
     * Food Facts routinely returns (brief §2.1). Fibre and salt are the usual
     * omissions.
     */
    public function withUnknownNutrients(array $unknown = ['fibre', 'salt']): static
    {
        return $this->state(fn () => array_fill_keys($unknown, null));
    }
}
