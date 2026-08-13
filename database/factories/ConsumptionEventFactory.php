<?php

namespace Database\Factories;

use App\Enums\ConsumptionType;
use App\Models\ConsumptionEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumptionEvent>
 */
class ConsumptionEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ConsumptionType::Single,
            'name' => null,
            'consumed_at' => now(),
            'calories' => fake()->randomFloat(2, 50, 800),
            'protein' => fake()->randomFloat(2, 0, 50),
            'carbs' => fake()->randomFloat(2, 0, 100),
            'sugars' => fake()->randomFloat(2, 0, 60),
            'fat' => fake()->randomFloat(2, 0, 50),
            'saturated_fat' => fake()->randomFloat(2, 0, 25),
            'fibre' => fake()->randomFloat(2, 0, 20),
            'salt' => fake()->randomFloat(2, 0, 4),
        ];
    }

    public function meal(): static
    {
        return $this->state(fn () => [
            'type' => ConsumptionType::Meal,
            'name' => fake()->words(2, true),
        ]);
    }
}
