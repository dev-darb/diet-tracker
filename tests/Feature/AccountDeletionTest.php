<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_their_account_and_profile(): void
    {
        $user = User::factory()->onboarded()->create();
        $profileId = $user->profile->id;

        Volt::actingAs($user)->test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
        $this->assertNull(UserProfile::find($profileId));
    }

    public function test_correct_password_is_required_to_delete_account(): void
    {
        $user = User::factory()->onboarded()->create();

        Volt::actingAs($user)->test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($user->fresh());
    }
}
