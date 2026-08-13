<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_admin(): void
    {
        $this->get('/admin/products')->assertRedirect('/login');
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get('/admin/products')->assertForbidden();
        $this->actingAs($user)->get('/admin/products/create')->assertForbidden();
    }

    public function test_admin_users_can_reach_the_console(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/products');
        $this->actingAs($admin)->get('/admin/products')->assertOk()->assertSee('Products');
        $this->actingAs($admin)->get('/admin/products/create')->assertOk();
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        // A malicious payload must not be able to self-promote.
        $user->fill(['is_admin' => true]);
        $user->save();

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_make_admin_command_promotes_a_user(): void
    {
        $user = User::factory()->create(['email' => 'promote@example.com']);

        $this->assertFalse($user->isAdmin());

        $this->artisan('app:make-admin', ['email' => 'promote@example.com'])
            ->expectsOutputToContain('is now an admin')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_make_admin_command_fails_for_unknown_email(): void
    {
        $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])
            ->assertFailed();
    }
}
