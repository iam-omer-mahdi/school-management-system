<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateFeeType;
use App\Actions\CreateGradeFee;
use App\Actions\SetEnrollmentDiscount;
use App\Mail\ResetCodeMail;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ValidationTranslationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string> */
    private function flatKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                array_push($keys, ...$this->flatKeys($value, $full));
            } else {
                $keys[] = $full;
            }
        }

        return $keys;
    }

    private function assertLangParity(string $file): void
    {
        $en = require lang_path('en/'.$file);
        $ar = require lang_path('ar/'.$file);

        $this->assertSame($this->flatKeys($en), $this->flatKeys($ar), "Translation keys differ in {$file}");
    }

    private function asArabic(): void
    {
        // Livewire test calls bypass HTTP middleware by design, so set the
        // locale the way SetLocale would for a real request. The session
        // value covers full-page loads; setLocale covers update requests.
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
    }

    private function assertMessagesAreArabic(MessageBag $bag): void
    {
        $this->assertTrue($bag->isNotEmpty(), 'Expected validation errors.');

        foreach ($bag->all() as $message) {
            $this->assertStringStartsNotWith('validation.', $message);
            $this->assertStringStartsNotWith('auth.', $message);
            $this->assertStringStartsNotWith('passwords.', $message);

            foreach (['The ', ' field is ', ' field must ', 'required', 'must be', 'invalid', 'confirmation does', 'already been taken'] as $english) {
                $this->assertStringNotContainsString($english, $message);
            }

            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message);
        }
    }

    public function test_translation_keys_match_between_locales(): void
    {
        foreach (['validation.php', 'auth.php', 'passwords.php', 'pagination.php', 'fees.php', 'students.php'] as $file) {
            $this->assertLangParity($file);
        }

        $enJson = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $arJson = json_decode((string) file_get_contents(lang_path('ar.json')), true);

        $this->assertIsArray($enJson);
        $this->assertIsArray($arJson);
        $this->assertSame(array_keys($enJson), array_keys($arJson));
    }

    public function test_login_errors_are_arabic(): void
    {
        $this->asArabic();

        $component = Livewire::test('pages::auth.login')->call('login');

        $component->assertHasErrors(['email', 'password']);
        $this->assertMessagesAreArabic($component->errors());

        $this->assertSame('حقل البريد الإلكتروني مطلوب.', $component->errors()->first('email'));
    }

    public function test_login_failure_message_is_arabic(): void
    {
        $this->asArabic();
        User::factory()->create(['email' => 'finance@school.local']);

        $component = Livewire::test('pages::auth.login')
            ->set('email', 'finance@school.local')
            ->set('password', 'wrong')
            ->call('login');

        $component->assertHasErrors(['email']);
        $this->assertMessagesAreArabic($component->errors());
    }

    public function test_forgot_password_errors_are_arabic(): void
    {
        $this->asArabic();
        Mail::fake();
        User::factory()->create(['email' => 'finance@school.local']);

        $emailStep = Livewire::test('pages::auth.forgot-password')->call('sendCode');
        $emailStep->assertHasErrors(['emailInput']);
        $this->assertMessagesAreArabic($emailStep->errors());

        $badEmail = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'not-an-email')
            ->call('sendCode');
        $badEmail->assertHasErrors(['emailInput']);
        $this->assertMessagesAreArabic($badEmail->errors());

        $codeStep = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode')
            ->set('code', '12')
            ->call('verifyCode');
        $codeStep->assertHasErrors(['code']);
        $this->assertMessagesAreArabic($codeStep->errors());
    }

    public function test_password_confirmation_error_is_arabic(): void
    {
        $this->asArabic();
        Mail::fake();
        User::factory()->create(['email' => 'finance@school.local']);

        $code = null;
        $component = Livewire::test('pages::auth.forgot-password')
            ->set('emailInput', 'finance@school.local')
            ->call('sendCode');

        Mail::assertSent(
            ResetCodeMail::class,
            function (ResetCodeMail $mail) use (&$code): bool {
                $code = $mail->code;

                return true;
            }
        );

        $component->set('code', (string) $code)->call('verifyCode')->assertSet('step', 3);

        $component
            ->set('password', 'brand-new-password-123')
            ->set('password_confirmation', 'different-password-123')
            ->call('resetPassword')
            ->assertHasErrors(['password']);

        $this->assertMessagesAreArabic($component->errors());
    }

    public function test_security_question_errors_are_arabic(): void
    {
        $this->asArabic();

        $lookup = Livewire::test('pages::auth.recover-questions')->call('lookup');
        $lookup->assertHasErrors(['emailInput']);
        $this->assertMessagesAreArabic($lookup->errors());

        $answers = Livewire::test('pages::auth.recover-questions')
            ->set('emailInput', 'finance@school.local')
            ->call('lookup')
            ->call('verifyAnswers');
        $answers->assertHasErrors(['answer_1', 'answer_2', 'answer_3']);
        $this->assertMessagesAreArabic($answers->errors());
    }

    public function test_settings_errors_are_arabic(): void
    {
        $this->asArabic();
        $user = User::factory()->create(['email' => 'finance@school.local']);
        $this->actingAs($user);

        $email = Livewire::test('pages::settings')
            ->set('new_email', 'not-an-email')
            ->call('sendEmailCode');

        $email->assertHasErrors(['new_email']);
        $this->assertMessagesAreArabic($email->errors());

        $password = Livewire::test('pages::settings')
            ->set('current_password', 'password')
            ->set('new_password', 'short')
            ->set('new_password_confirmation', 'short')
            ->call('updatePassword');

        $password->assertHasErrors(['new_password']);
        $this->assertMessagesAreArabic($password->errors());

        $security = Livewire::test('pages::settings')->call('updateSecurity');
        $security->assertHasErrors(['answer_1', 'answer_2', 'answer_3']);
        $this->assertMessagesAreArabic($security->errors());
    }

    public function test_fee_errors_are_arabic(): void
    {
        $this->asArabic();

        $bagFor = function (callable $fails): MessageBag {
            try {
                $fails();
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                return new MessageBag($e->errors());
            }
        };

        $this->assertMessagesAreArabic($bagFor(
            fn () => app(CreateFeeType::class)->handle(['name' => '', 'is_mandatory' => false])
        ));

        app(CreateFeeType::class)->handle(['name' => 'Bus', 'is_mandatory' => false]);

        $this->assertMessagesAreArabic($bagFor(
            fn () => app(CreateFeeType::class)->handle(['name' => 'bus', 'is_mandatory' => false])
        ));

        $this->assertMessagesAreArabic($bagFor(
            fn () => app(CreateGradeFee::class)->handle([
                'grade_id' => 1, 'academic_year_id' => 1, 'fee_type_id' => 1, 'amount' => 'abc',
            ])
        ));

        $enrollment = Enrollment::factory()->create();

        $this->assertMessagesAreArabic($bagFor(
            fn () => app(SetEnrollmentDiscount::class)->handle($enrollment->id, 'abc', null)
        ));
    }

    public function test_academic_year_errors_are_arabic(): void
    {
        $this->asArabic();
        $user = User::factory()->create();
        $this->actingAs($user);

        $empty = Livewire::test('pages::academic-years.index')->call('save');
        $empty->assertHasErrors(['form.starts_on', 'form.ends_on']);
        $this->assertMessagesAreArabic($empty->errors());

        $dates = Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', '2026-10')
            ->set('form.ends_on', '2026-09')
            ->call('save');
        $dates->assertHasErrors(['form.ends_on']);
        $this->assertMessagesAreArabic($dates->errors());

        AcademicYear::query()->create(['name' => '2026/2027', 'starts_on' => '2026-01-01', 'ends_on' => '2027-03-01']);
        $duplicate = Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', '2026-05')
            ->set('form.ends_on', '2027-03')
            ->call('save');
        $duplicate->assertHasErrors(['form.starts_on']);
        $this->assertMessagesAreArabic($duplicate->errors());
        $this->assertStringContainsString('2026', (string) $duplicate->errors()->first('form.starts_on'));
    }

    public function test_student_errors_are_arabic(): void
    {
        $this->asArabic();
        $user = User::factory()->create();
        $this->actingAs($user);

        $empty = Livewire::test('pages::students.create')->call('save');
        $empty->assertHasErrors(['form.name', 'form.phone']);
        $this->assertMessagesAreArabic($empty->errors());
        $this->assertSame('حقل الاسم مطلوب.', $empty->errors()->first('form.name'));

        $enroll = Livewire::test('pages::students.create')
            ->set('form.name', 'Sara')
            ->set('form.phone', '0700000000')
            ->set('enrollNow', true)
            ->call('save');
        $enroll->assertHasErrors(['school_id', 'grade_id', 'academic_year_id']);
        $this->assertMessagesAreArabic($enroll->errors());

        $birth = Livewire::test('pages::students.create')
            ->set('form.name', 'Sara')
            ->set('form.phone', '0700000000')
            ->set('form.date_of_birth', 'not-a-date')
            ->call('save');
        $birth->assertHasErrors(['form.date_of_birth']);
        $this->assertMessagesAreArabic($birth->errors());

        $student = Student::query()->create([
            'student_number' => 'STU-000001',
            'name' => 'Sara',
            'phone' => '0700000000',
            'status' => 'active',
        ]);

        $show = Livewire::test('pages::students.show', ['student' => $student->id])
            ->set('showEnroll', true)
            ->call('enroll');
        $show->assertHasErrors(['grade_id', 'academic_year_id']);
        $this->assertMessagesAreArabic($show->errors());
    }

    public function test_locale_switch_changes_validation_language_immediately(): void
    {
        $this->withSession(['locale' => 'en']);
        app()->setLocale('en');

        $english = Livewire::test('pages::auth.login')->call('login');
        $this->assertStringContainsString('required', (string) $english->errors()->first('email'));

        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');

        $arabic = Livewire::test('pages::auth.login')->call('login');
        $this->assertStringContainsString('مطلوب', (string) $arabic->errors()->first('email'));
    }

    public function test_locale_route_validation_is_arabic(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->post('/locale', ['locale' => 'xx']);

        $response->assertSessionHasErrors('locale');

        /** @var ViewErrorBag $bag */
        $bag = session()->get('errors');
        $this->assertMessagesAreArabic($bag->getBag('default'));
    }
}
