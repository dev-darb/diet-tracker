<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_app_screens(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->get('/pantry')->assertRedirect('/login');
        $this->get('/scan')->assertRedirect('/login');
        $this->get('/eat')->assertRedirect('/login');
        $this->get('/health')->assertRedirect('/login');
    }

    public function test_onboarded_user_can_visit_all_nav_screens(): void
    {
        $user = User::factory()->onboarded()->create();

        foreach (['/home', '/pantry', '/scan', '/eat', '/health', '/profile'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }

    public function test_home_renders_bottom_nav_with_prominent_scan(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get('/home')
            ->assertOk()
            ->assertSee('Scan')
            ->assertSee(route('scan'))
            ->assertSee(route('pantry'))
            ->assertSee(route('health'));
    }

    public function test_welcome_page_redirects_authenticated_users_home(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('home'));
    }

    public function test_welcome_page_is_shown_to_guests(): void
    {
        $this->get('/')->assertOk()->assertSee('Create an account');
    }
}
