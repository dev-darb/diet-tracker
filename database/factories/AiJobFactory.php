<?php

namespace Database\Factories;

use App\Models\AiJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiJob>
 */
class AiJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_type' => fake()->randomElement(['product_identification', 'product_research', 'diet_insight']),
            'provider' => 'openrouter',
            'model' => 'anthropic/claude-3.5-sonnet',
            'latency_ms' => fake()->numberBetween(200, 5000),
            'input_tokens' => fake()->numberBetween(100, 4000),
            'output_tokens' => fake()->numberBetween(50, 1500),
            'cost' => fake()->randomFloat(6, 0.0001, 0.05),
            'status' => 'succeeded',
            'retries' => 0,
            'confidence' => fake()->randomFloat(3, 0.3, 1),
            'result_status' => 'ok',
        ];
    }
}
