<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_login_works_and_redirects_to_dashboard(): void
    {
        User::factory()->create([
            'email' => 'finance@school.local',
            'password' => 'password',
        ]);

        Livewire::test('pages::auth.login')
            ->set('email', 'finance@school.local')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'finance@school.local',
            'password' => 'password',
        ]);

        Livewire::test('pages::auth.login')
            ->set('email', 'finance@school.local')
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_settings_page_does_not_expose_name_field(): void
    {
        $user = User::factory()->create(['email' => 'finance@school.local']);

        $this->actingAs($user)->get('/settings')->assertOk();
        $this->actingAs($user)->get('/settings')->assertDontSee('name="name"', false);
    }

    public function test_otp_and_security_question_pages_render(): void
    {
        $this->get('/forgot-password')->assertOk();
        $this->get('/recover-questions')->assertOk();
    }
}
