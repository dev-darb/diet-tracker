<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\ProductSource;
use App\Models\ProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSource>
 */
class ProductSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_version_id' => ProductVersion::factory(),
            'source_url' => fake()->url(),
            'source_type' => SourceType::OpenFoodFacts,
            'retrieved_at' => now(),
            'confidence' => fake()->randomFloat(3, 0.5, 1),
            'evidence_summary' => fake()->sentence(),
        ];
    }
}
