<?php

namespace Database\Factories;

use App\Models\ProductResolutionJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductResolutionJob>
 */
class ProductResolutionJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'detected_fields' => [
                'brand' => fake()->company(),
                'name' => fake()->words(2, true),
                'barcode' => fake()->ean13(),
            ],
            'detection_confidence' => fake()->randomFloat(3, 0.4, 1),
            'matched_product_id' => null,
            'status' => 'pending',
            'model_provider' => null,
            'model_name' => null,
            'latency_ms' => null,
        ];
    }
}
