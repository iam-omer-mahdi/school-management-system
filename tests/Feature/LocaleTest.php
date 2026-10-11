<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_renders_ltr(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
        $response->assertSee('lang="en"', false);
    }

    public function test_arabic_renders_rtl(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/login');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('lang="ar"', false);
    }

    public function test_locale_switch_persists_for_user(): void
    {
        $user = User::factory()->create(['display_locale' => 'en']);

        $this->actingAs($user)->post('/locale', ['locale' => 'ar'])->assertRedirect();

        $this->assertEquals('ar', $user->fresh()?->display_locale);
        $this->assertEquals('ar', session('locale'));
    }

    public function test_western_digits_used(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/login');
        // Western digits 0-9 appear in OTP hint (6 digits).
        $response->assertSee('6', false);
    }
}
