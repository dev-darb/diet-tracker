<?php

namespace Database\Factories;

use App\Enums\PrimaryGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserProfile>
 */
class UserProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'primary_goal' => fake()->randomElement(PrimaryGoal::cases())->value,
            'dietary_preferences' => [],
            'allergies' => [],
            'avoided_foods' => [],
            'onboarding_completed_at' => now(),
        ];
    }

    /**
     * A profile that has not finished onboarding.
     */
    public function pendingOnboarding(): static
    {
        return $this->state(fn () => ['onboarding_completed_at' => null]);
    }
}
