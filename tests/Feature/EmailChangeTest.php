<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RequestEmailChangeOtp;
use App\Mail\ResetCodeMail;
use App\Models\EmailChangeOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function sentCode(): string
    {
        $code = null;

        Mail::assertSent(ResetCodeMail::class, function (ResetCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code);

        return (string) $code;
    }

    public function test_email_unchanged_until_code_verified(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@school.local']);
        $this->actingAs($user);

        Livewire::test('pages::settings')
            ->set('new_email', 'new@school.local')
            ->call('sendEmailCode')
            ->assertSet('emailCodeSent', true)
            ->assertSet('pending_email', 'new@school.local');

        $this->assertSame('old@school.local', $user->refresh()->email);
        $this->assertDatabaseHas('email_change_otps', ['user_id' => $user->id, 'email' => 'new@school.local']);

        $otp = EmailChangeOtp::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertTrue(Hash::check($this->sentCode(), $otp->code_hash));
    }

    public function test_correct_code_changes_email_and_burns_otp(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@school.local']);
        $this->actingAs($user);

        Livewire::test('pages::settings')
            ->set('new_email', 'new@school.local')
            ->call('sendEmailCode')
            ->set('email_code', $this->sentCode())
            ->call('verifyEmailCode')
            ->assertHasNoErrors();

        $this->assertSame('new@school.local', $user->refresh()->email);
        $this->assertTrue(EmailChangeOtp::where('user_id', $user->id)->latest('id')->firstOrFail()->isUsed());
    }

    public function test_wrong_code_increments_and_five_failures_invalidate(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@school.local']);
        $this->actingAs($user);

        $component = Livewire::test('pages::settings')
            ->set('showEmailForm', true)
            ->set('new_email', 'new@school.local')
            ->call('sendEmailCode');

        for ($i = 1; $i <= 4; $i++) {
            $component->set('email_code', '000000')->call('verifyEmailCode')->assertHasErrors(['email_code']);
            $this->assertSame($i, EmailChangeOtp::latest('id')->firstOrFail()->attempts);
        }

        $component->set('email_code', '000000')->call('verifyEmailCode')->assertSee(__('settings.email_code_invalidated'));

        $this->assertTrue(EmailChangeOtp::latest('id')->firstOrFail()->isUsed());
        $this->assertSame('old@school.local', $user->refresh()->email);
    }

    public function test_expired_code_fails(): void
    {
        $user = User::factory()->create(['email' => 'old@school.local']);

        $action = app(RequestEmailChangeOtp::class);
        $code = $action->request($user, 'new@school.local');

        $this->travel(11)->minutes();

        try {
            $action->verify($user, 'new@school.local', $code);
            $this->fail('Expected ValidationException for expired code');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email_code', $e->errors());
        }

        $this->travelBack();
        $this->assertSame('old@school.local', $user->refresh()->email);
    }

    public function test_same_and_taken_emails_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@school.local']);
        User::factory()->create(['email' => 'taken@school.local']);
        $this->actingAs($user);

        Livewire::test('pages::settings')
            ->set('new_email', 'old@school.local')
            ->call('sendEmailCode')
            ->assertHasErrors(['new_email']);

        Livewire::test('pages::settings')
            ->set('new_email', 'taken@school.local')
            ->call('sendEmailCode')
            ->assertHasErrors(['new_email']);

        Mail::assertNothingSent();
    }

    public function test_send_rate_limit_enforced(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@school.local']);
        $this->actingAs($user);

        $component = Livewire::test('pages::settings')
            ->set('new_email', 'new@school.local')
            ->call('sendEmailCode')
            ->assertSet('emailCodeSent', true);

        $component
            ->set('new_email', 'other@school.local')
            ->call('resendEmailCode')
            ->assertHasErrors(['new_email']);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create(['email' => 'old@school.local', 'password' => 'old-password-123']);
        $this->actingAs($user);

        Livewire::test('pages::settings')
            ->set('current_password', 'wrong-password')
            ->set('new_password', 'brand-new-password-123')
            ->set('new_password_confirmation', 'brand-new-password-123')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password-123', (string) $user->refresh()->password));

        Livewire::test('pages::settings')
            ->set('current_password', 'old-password-123')
            ->set('new_password', 'brand-new-password-123')
            ->set('new_password_confirmation', 'brand-new-password-123')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password-123', (string) $user->refresh()->password));
    }
}
