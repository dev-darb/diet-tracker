<?php

namespace Tests\Feature;

use App\Enums\PrimaryGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_screen_is_displayed(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_account_details_can_be_updated(): void
    {
        $user = User::factory()->onboarded()->create();

        Volt::actingAs($user)->test('profile')
            ->set('name', 'New Name')
            ->set('email', 'new@example.com')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_preferences_can_be_updated(): void
    {
        $user = User::factory()->onboarded()->create();

        Volt::actingAs($user)->test('profile')
            ->set('primary_goal', PrimaryGoal::GainMuscle->value)
            ->call('toggleAllergy', 'Shellfish')
            ->set('height_cm', '182')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $profile = $user->fresh()->profile;
        $this->assertSame(PrimaryGoal::GainMuscle, $profile->primary_goal);
        $this->assertSame(['Shellfish'], $profile->allergies);
        $this->assertSame(182, $profile->height_cm);
    }

    public function test_invalid_health_values_are_rejected(): void
    {
        $user = User::factory()->onboarded()->create();

        Volt::actingAs($user)->test('profile')
            ->set('primary_goal', PrimaryGoal::EatHealthier->value)
            ->set('height_cm', '5')   // below minimum
            ->call('saveProfile')
            ->assertHasErrors('height_cm');
    }
}
