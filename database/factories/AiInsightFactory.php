<?php

namespace Database\Factories;

use App\Models\AiInsight;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInsight>
 */
class AiInsightFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'insight_type' => 'weekly_focus',
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'structured_inputs' => ['avg_calories' => 2100],
            'provider' => 'openrouter',
            'model' => 'anthropic/claude-3.5-sonnet',
        ];
    }
}
