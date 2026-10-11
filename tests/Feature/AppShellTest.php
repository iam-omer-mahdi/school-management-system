<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_drawer_markup_uses_flowbite_data_attributes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="mobile-sidebar"', false);
        $response->assertSee('data-drawer-target="mobile-sidebar"', false);
        $response->assertSee('data-drawer-show="mobile-sidebar"', false);
        $response->assertSee('data-drawer-placement="left"', false);
        $response->assertSee('data-drawer-hide="mobile-sidebar"', false);
    }

    public function test_rtl_drawer_is_placed_on_the_right(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->withSession(['locale' => 'ar'])->get('/');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('data-drawer-placement="right"', false);
    }

    public function test_theme_toggle_is_present_for_guests_and_users(): void
    {
        $this->get('/login')->assertSee('data-theme-toggle', false);

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/')->assertSee('data-theme-toggle', false);
    }

    public function test_recovery_pages_link_back_to_login(): void
    {
        $loginUrl = route('login');

        $this->get('/forgot-password')->assertSee($loginUrl);
        $this->get('/recover-questions')->assertSee($loginUrl);
    }

    public function test_theme_and_language_switches_are_grouped_in_the_header(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('role="group"', false);
        $response->assertSee('data-theme-toggle', false);
        $response->assertSee(route('locale.switch'), false);
    }

    public function test_settings_pairs_theme_switch_with_language(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/settings');

        $response->assertOk();
        $response->assertSee('data-theme-set="light"', false);
        $response->assertSee('data-theme-set="dark"', false);
        $response->assertSee(route('locale.switch'), false);
    }

    public function test_shell_has_no_alpine_state(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertDontSee('x-data', false);
        $response->assertDontSee('sidebarOpen', false);
    }
}
