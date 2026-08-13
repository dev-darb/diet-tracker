<?php

namespace Tests\Feature;

use App\Enums\PrimaryGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_are_redirected_to_onboarding(): void
    {
        $user = User::factory()->create(); // no profile yet

        $this->actingAs($user)->get('/home')->assertRedirect(route('onboarding'));
    }

    public function test_onboarding_screen_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/onboarding')->assertOk()->assertSee('What brings you here?');
    }

    public function test_already_onboarded_users_skip_onboarding(): void
    {
        $user = User::factory()->onboarded()->create();

        // Onboarding redirects completed users back to home on mount.
        Volt::actingAs($user)->test('onboarding')->assertRedirect(route('home'));
    }

    public function test_user_can_complete_onboarding(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('onboarding')
            ->set('primary_goal', PrimaryGoal::EatHealthier->value)
            ->set('dietary_pattern', 'vegetarian')
            ->call('togglePreference', 'High protein')
            ->call('toggleAllergy', 'Peanuts')
            ->set('avoided_foods', 'pork, coriander')
            ->set('height_cm', '178')
            ->set('weight_kg', '74.5')
            ->call('complete')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $profile = $user->fresh()->profile;

        $this->assertNotNull($profile);
        $this->assertTrue($profile->hasCompletedOnboarding());
        $this->assertSame(PrimaryGoal::EatHealthier, $profile->primary_goal);
        $this->assertSame('vegetarian', $profile->dietary_pattern->value);
        $this->assertSame(['High protein'], $profile->dietary_preferences);
        $this->assertSame(['Peanuts'], $profile->allergies);
        $this->assertSame(['pork', 'coriander'], $profile->avoided_foods);
        $this->assertSame(178, $profile->height_cm);
        $this->assertSame('74.50', $profile->weight_kg);
    }

    public function test_onboarding_requires_a_primary_goal(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('onboarding')
            ->call('next')
            ->assertHasErrors('primary_goal');

        $this->assertNull($user->fresh()->profile);
    }

    public function test_optional_body_info_is_not_mandatory(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('onboarding')
            ->set('primary_goal', PrimaryGoal::LoseWeight->value)
            ->call('complete')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $profile = $user->fresh()->profile;

        $this->assertTrue($profile->hasCompletedOnboarding());
        $this->assertNull($profile->height_cm);
        $this->assertNull($profile->weight_kg);
        $this->assertNull($profile->date_of_birth);
    }
}
