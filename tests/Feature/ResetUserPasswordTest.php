<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RequestPasswordOtp;
use App\Models\PasswordResetOtp;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class ResetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_resets_password_and_clears_recovery_state(): void
    {
        $user = User::factory()->create([
            'email' => 'finance@school.local',
            'password' => 'old-password-123',
            'remember_token' => 'known-remember-token',
        ]);

        app(RequestPasswordOtp::class)->request('finance@school.local');
        app(PasswordResetService::class)->issueToken('finance@school.local');

        config()->set('session.driver', 'database');

        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'test',
            'last_activity' => time(),
        ]);

        $this->artisan('user:reset-password', ['email' => 'finance@school.local'])
            ->expectsQuestion('New password', 'brand-new-password-123')
            ->expectsQuestion('Confirm new password', 'brand-new-password-123')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertTrue(Hash::check('brand-new-password-123', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('old-password-123', $user->password));
        $this->assertNotSame('known-remember-token', $user->remember_token);
        $this->assertSame(0, PasswordResetOtp::where('email', 'finance@school.local')->count());
        $this->assertSame(0, PasswordResetToken::where('email', 'finance@school.local')->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_fails_clearly_when_email_argument_is_not_a_string(): void
    {
        $this->artisan('user:reset-password', ['email' => ['finance@school.local']])
            ->expectsOutputToContain('The email argument must be a string.')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_fails_for_unknown_email(): void
    {
        $this->artisan('user:reset-password', ['email' => 'ghost@school.local'])
            ->expectsOutputToContain('No user found')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_fails_when_confirmation_does_not_match(): void
    {
        User::factory()->create([
            'email' => 'finance@school.local',
            'password' => 'old-password-123',
        ]);

        $this->artisan('user:reset-password', ['email' => 'finance@school.local'])
            ->expectsQuestion('New password', 'brand-new-password-123')
            ->expectsQuestion('Confirm new password', 'different-password-123')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('old-password-123', User::firstOrFail()->password));
    }

    public function test_fails_for_weak_password(): void
    {
        Password::defaults(fn (): Password => Password::min(12));

        try {
            User::factory()->create([
                'email' => 'finance@school.local',
                'password' => 'old-password-123',
            ]);

            $this->artisan('user:reset-password', ['email' => 'finance@school.local'])
                ->expectsQuestion('New password', 'short')
                ->expectsQuestion('Confirm new password', 'short')
                ->assertExitCode(1);

            $this->assertTrue(Hash::check('old-password-123', User::firstOrFail()->password));
        } finally {
            Password::defaults(fn (): ?Password => null);
        }
    }
}
