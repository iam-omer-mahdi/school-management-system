<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RequestPasswordOtp;
use App\Actions\SecurityQuestionRecovery;
use App\Mail\ResetCodeMail;
use App\Models\PasswordResetOtp;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'finance@school.local'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => 'old-password-123',
            'remember_token' => 'known-remember-token',
        ]);
    }

    private function makeUserWithQuestions(): User
    {
        $user = $this->makeUser();

        app(SecurityQuestionRecovery::class)->updateQuestions($user, [
            'answer_1' => '  Nile ',
            'answer_2' => 'Cairo',
            'answer_3' => 'School',
        ]);

        return $user->refresh();
    }

    /** @return string the plain OTP code from the last sent mail */
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

    public function test_reset_step_unreachable_without_proof(): void
    {
        $this->makeUser();

        // Direct submit without any proof stays on the email step.
        Livewire::test('pages::auth.forgot-password')
            ->call('resetPassword')
            ->assertSet('step', 1);

        $this->assertTrue(Hash::check('old-password-123', (string) User::firstOrFail()->password));

        // Forged session token also lands back on the email step.
        $this->withSession(['pwd_reset' => ['email' => 'finance@school.local', 'token' => 'forged']]);

        Livewire::test('pages::auth.forgot-password')->assertSet('step', 1);
    }

    public function test_unknown_email_gets_neutral_response_and_no_otp(): void
    {
        Mail::fake();

        Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'ghost@school.local')
            ->call('sendCode')
            ->assertSet('step', 2)
            ->assertSee(__('auth.otp_neutral'));

        $this->assertDatabaseMissing('password_reset_otps', ['email' => 'ghost@school.local']);
        Mail::assertNothingSent();
    }

    public function test_known_email_sends_hashed_otp(): void
    {
        Mail::fake();
        $this->makeUser();

        Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode')
            ->assertSet('step', 2)
            ->assertSee(__('auth.otp_neutral'));

        $code = $this->sentCode();
        $otp = PasswordResetOtp::where('email', 'finance@school.local')->latest('id')->firstOrFail();

        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertSame(0, $otp->attempts);
    }

    public function test_correct_otp_issues_session_token(): void
    {
        Mail::fake();
        $this->makeUser();

        $component = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode');

        $component->set('code', $this->sentCode())->call('verifyCode')->assertSet('step', 3);

        $pending = session()->get('pwd_reset');
        $this->assertIsArray($pending);
        $this->assertSame('finance@school.local', $pending['email']);
        $this->assertNotNull(
            app(PasswordResetService::class)->findValidToken('finance@school.local', $pending['token'])
        );
    }

    public function test_wrong_otp_increments_and_five_failures_invalidate(): void
    {
        Mail::fake();
        $this->makeUser();

        $component = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode');

        for ($i = 1; $i <= 4; $i++) {
            $component->set('code', '000000')->call('verifyCode')->assertHasErrors(['code']);
            $this->assertSame($i, PasswordResetOtp::latest('id')->firstOrFail()->attempts);
        }

        // Fifth failure invalidates the OTP and forces a new request.
        $component->set('code', '000000')->call('verifyCode')->assertSee(__('auth.otp_invalidated'));

        $otp = PasswordResetOtp::latest('id')->firstOrFail();
        $this->assertNotNull($otp->used_at);

        $component->set('code', $this->sentCode())->call('verifyCode')->assertSee(__('auth.otp_invalidated'));
    }

    public function test_expired_and_reused_otp_fail(): void
    {
        $this->makeUser();
        $action = app(RequestPasswordOtp::class);

        $code = $action->request('finance@school.local');
        $this->assertNotNull($code);

        $this->travel(11)->minutes();

        try {
            $action->verify('finance@school.local', (string) $code);
            $this->fail('Expected ValidationException for expired OTP');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->travelBack();
        $fresh = $action->request('finance@school.local');
        $this->assertNotNull($fresh);

        $action->verify('finance@school.local', (string) $fresh);

        try {
            $action->verify('finance@school.local', (string) $fresh);
            $this->fail('Expected ValidationException for reused OTP');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }

    public function test_send_rate_limit_enforced(): void
    {
        Mail::fake();
        $this->makeUser();

        $component = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode')
            ->assertSet('step', 2);

        // Immediate resend is throttled (1 per 60 seconds).
        $component->call('resend')->assertHasErrors(['emailInput']);
    }

    public function test_verify_rate_limit_enforced(): void
    {
        Mail::fake();
        $this->makeUser();

        $component = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode');

        // More than 10 verify attempts within 10 minutes are throttled.
        for ($i = 0; $i < 11; $i++) {
            $component->set('code', '000000')->call('verifyCode');
        }

        $component->assertSee('Too many attempts');
    }

    public function test_full_reset_changes_password_and_burns_proofs(): void
    {
        Mail::fake();
        $this->makeUser();

        $component = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode');

        $component->set('code', $this->sentCode())->call('verifyCode')->assertSet('step', 3);

        $component
            ->set('password', 'brand-new-password-123')
            ->set('password_confirmation', 'brand-new-password-123')
            ->call('resetPassword')
            ->assertRedirect(route('login'));

        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('brand-new-password-123', (string) $user->password));
        $this->assertNotSame('known-remember-token', $user->remember_token);

        $this->assertDatabaseMissing('password_reset_otps', ['email' => 'finance@school.local']);
        $this->assertSame(0, PasswordResetOtp::where('email', 'finance@school.local')->count());
    }

    public function test_reset_token_is_single_use_and_expires(): void
    {
        $this->makeUser();
        $service = app(PasswordResetService::class);

        $token = $service->issueToken('finance@school.local');

        $service->completeReset('finance@school.local', $token, 'brand-new-password-123');
        $this->assertTrue(Hash::check('brand-new-password-123', (string) User::firstOrFail()->password));

        // Reusing the same token fails.
        try {
            $service->completeReset('finance@school.local', $token, 'another-password-123');
            $this->fail('Expected ValidationException for reused token');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        // Expired tokens fail.
        $fresh = $service->issueToken('finance@school.local');
        $this->travel(16)->minutes();
        $this->assertNull($service->findValidToken('finance@school.local', $fresh));
        $this->travelBack();
    }

    public function test_security_questions_path_issues_token_and_resets(): void
    {
        $this->makeUserWithQuestions();

        $component = Livewire::test('pages::auth.recover-questions')
            ->set('emailInput', 'finance@school.local')
            ->call('lookup')
            ->assertSet('step', 2);

        // Answers are normalized (trim + lowercase) before the hashed compare.
        $component
            ->set('answer_1', 'nile')
            ->set('answer_2', 'CAIRO')
            ->set('answer_3', '  School  ')
            ->call('verifyAnswers')
            ->assertRedirect(route('password.request'));

        $pending = session()->get('pwd_reset');
        $this->assertIsArray($pending);

        Livewire::test('pages::auth.forgot-password')
            ->assertSet('step', 3)
            ->set('password', 'question-reset-123')
            ->set('password_confirmation', 'question-reset-123')
            ->call('resetPassword')
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('question-reset-123', (string) User::firstOrFail()->password));
    }

    public function test_security_questions_unknown_email_stays_neutral(): void
    {
        $this->makeUserWithQuestions();

        $component = Livewire::test('pages::auth.recover-questions')
            ->set('emailInput', 'ghost@school.local')
            ->call('lookup')
            ->assertSet('step', 2);

        $component
            ->set('answer_1', 'x')
            ->set('answer_2', 'y')
            ->set('answer_3', 'z')
            ->call('verifyAnswers')
            ->assertHasErrors(['answer_1']);

        $this->assertNull(session()->get('pwd_reset'));
        $this->assertSame(0, PasswordResetToken::count());
    }

    public function test_security_questions_are_same_for_every_email(): void
    {
        $this->makeUserWithQuestions();

        $known = Livewire::test('pages::auth.recover-questions')
            ->set('emailInput', 'finance@school.local')
            ->call('lookup');

        $unknown = Livewire::test('pages::auth.recover-questions')
            ->set('emailInput', 'ghost@school.local')
            ->call('lookup');

        $this->assertSame($known->get('questions'), $unknown->get('questions'));
        $this->assertCount(3, $known->get('questions'));
    }

    public function test_security_questions_attempt_limited(): void
    {
        $this->makeUserWithQuestions();

        $component = Livewire::test('pages::auth.recover-questions')
            ->set('emailInput', 'finance@school.local')
            ->call('lookup');

        for ($i = 0; $i < 6; $i++) {
            $component
                ->set('answer_1', 'wrong')
                ->set('answer_2', 'wrong')
                ->set('answer_3', 'wrong')
                ->call('verifyAnswers');
        }

        $component->assertSee('Too many attempts');
    }
}
