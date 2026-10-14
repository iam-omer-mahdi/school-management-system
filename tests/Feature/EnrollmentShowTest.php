<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentShowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        return [$grade, $year];
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        $student = Student::factory()->create();

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    private function scheduleTuition(Grade $grade, AcademicYear $year, string $amount = '1000'): void
    {
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => $amount,
        ]);
    }

    public function test_show_requires_auth_and_missing_enrollment_404s(): void
    {
        $this->get(route('enrollments.show', 1))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());

        $this->get(route('enrollments.show', 999999))->assertNotFound();
    }

    public function test_show_renders_header_totals_and_placeholder(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $student = Student::factory()->create(['name' => 'Show Header']);
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee('Show Header')
            ->assertSee($student->student_number)
            ->assertSee(route('students.show', $student))
            ->assertSee($year->name)
            ->assertSee(__('enrollments.status_active'))
            ->assertSee(__('fees.total_due'))
            ->assertSee(Money::format(100000))
            ->assertSee(__('payments.payment'))
            ->assertSee(__('payments.no_payments'))
            ->assertSee(__('payments.refunds'))
            ->assertSee(route('enrollments.index'));
    }

    public function test_no_raw_tuition_name_in_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.tuition'))
            ->assertDontSee('Tuition');

        Livewire::test('pages::enrollments.index')
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertDontSee('Tuition');
    }

    public function test_grade_and_year_are_read_only(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee($year->name)
            ->assertSee(__('schools.grade_number', ['number' => $grade->number]))
            ->assertDontSee('id="enroll-grade"', false)
            ->assertDontSee('id="enroll-year"', false)
            ->assertDontSee('id="enroll-school"', false);
    }

    public function test_status_change_applies_and_removes_nothing(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'suspended')
            ->assertSee(__('enrollments.change_status'))
            ->call('confirmStatusChange')
            ->assertHasNoErrors();

        $this->assertSame(EnrollmentStatus::Suspended, $enrollment->refresh()->status);
        $this->assertSame(1, $enrollment->refresh()->fees()->count());
    }

    public function test_optional_fee_add_and_remove_through_page(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $page = Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->set('optionalChoice', (string) $booksFee->id)
            ->call('addOptional')
            ->assertHasNoErrors();

        $this->assertSame(2, $enrollment->refresh()->fees()->count());

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(Money::format(115000));

        $page->call('removeOptional', $booksFee->id)
            ->assertHasNoErrors();

        $this->assertSame(1, $enrollment->refresh()->fees()->count());

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(Money::format(100000));
    }

    public function test_discount_apply_replace_and_remove_through_page(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $sibling = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);

        $page = Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->set('discountChoice', (string) $sibling->id)
            ->assertSee(Money::format(10000))
            ->assertSee(Money::format(90000))
            ->call('applyDiscount')
            ->assertHasNoErrors();

        $this->assertSame(10000, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->firstOrFail()->amount_minor);

        $staff = app(CreateDiscount::class)->handle(['name' => 'Staff', 'percent' => '20']);

        $page->set('discountChoice', (string) $staff->id)
            ->call('applyDiscount')
            ->assertHasNoErrors();

        $this->assertSame(1, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());

        $page->call('removeDiscount')
            ->assertHasNoErrors();

        $this->assertSame(0, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());
    }

    public function test_ended_enrollment_hides_adds_and_rejects_removals(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.errors.enrollment_ended'))
            ->assertDontSee(__('fees.add_optional_fee'))
            ->assertDontSee(__('fees.select_discount'));

        $page = Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id]);

        $page->call('removeOptional', $booksFee->id)
            ->assertHasErrors(['grade_fee_id']);

        $page->call('removeDiscount')
            ->assertHasErrors(['discount_id']);

        $this->assertSame(2, $enrollment->refresh()->fees()->count());
        $this->assertSame(1, $enrollment->refresh()->discount()->count());
    }

    public function test_ended_enrollment_rejects_direct_add_calls(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Transferred);

        try {
            app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);
            $this->fail('Expected ValidationException for optional add on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertSame(__('fees.errors.enrollment_ended'), $e->errors()['grade_fee_id'][0]);
        }

        $this->assertSame(1, $enrollment->refresh()->fees()->count());
    }

    public function test_new_keys_are_translated(): void
    {
        foreach (['en' => 'Enrollments', 'ar' => 'التسجيلات'] as $locale => $title) {
            $this->withSession(['locale' => $locale]);
            app()->setLocale($locale);
            $this->assertSame($title, __('enrollments.title'));
        }

        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->assertSame('عرض التفاصيل', __('enrollments.view_details'));
        $this->assertSame('المدفوعات', __('enrollments.payments'));
        $this->assertSame('ستُدار المدفوعات هنا.', __('enrollments.payments_placeholder'));
        $this->assertSame('الكل', __('enrollments.filter_all'));
    }
}
