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
            'period_start' => now()->subDays(6)->toDateString(),
            'period_end' => now()->toDateString(),
            'title' => 'Fibre is your biggest opportunity this week',
            'body' => 'You\'re averaging around 18g/day, below the general 30g guide.',
            'priority' => 'high',
            'focus_key' => 'fibre',
            'structured_inputs' => ['weekly' => ['has_data' => true], 'pantry' => []],
            'pantry_item_ids' => [],
            'provider' => 'rule_based',
            'model' => 'deterministic',
            'dismissed_at' => null,
        ];
    }

    /** A dismissed insight (hidden for its period). */
    public function dismissed(): self
    {
        return $this->state(fn () => ['dismissed_at' => now()]);
    }
}
