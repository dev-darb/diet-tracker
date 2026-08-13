<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Attach a completed profile so the user passes the onboarding gate.
     */
    public function onboarded(): static
    {
        return $this->afterCreating(function (User $user) {
            UserProfile::factory()->for($user)->create();
        });
    }

    /**
     * Flag the user as an admin (BUILD_PLAN §11). `is_admin` is not mass
     * assignable, so we set it via the factory's attribute array directly.
     */
    public function admin(): static
    {
        return $this->state(fn () => ['is_admin' => true]);
    }
}
