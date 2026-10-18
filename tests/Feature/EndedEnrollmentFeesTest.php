<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EndedEnrollmentFeesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['name' => 'Test']);
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

    /**
     * @return array<string, array{EnrollmentStatus}>
     */
    public static function endedStatuses(): array
    {
        return [
            'transferred' => [EnrollmentStatus::Transferred],
            'left' => [EnrollmentStatus::Left],
            'expelled' => [EnrollmentStatus::Expelled],
        ];
    }

    #[DataProvider('endedStatuses')]
    public function test_ended_enrollments_reject_optional_adds(EnrollmentStatus $status): void
    {
        [$grade, $year] = $this->fixtures();

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $gradeFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, $status);

        try {
            app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $gradeFee->id);
            $this->fail('Expected ValidationException for optional add on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee_id', $e->errors());
            $this->assertSame(__('fees.errors.enrollment_ended'), $e->errors()['grade_fee_id'][0]);
        }

        $this->assertSame(0, $enrollment->refresh()->fees()->count());
    }

    #[DataProvider('endedStatuses')]
    public function test_ended_enrollments_reject_discount_applies(EnrollmentStatus $status): void
    {
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $enrollment = $this->enroll($grade, $year);
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, $status);

        try {
            app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);
            $this->fail('Expected ValidationException for discount apply on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_id', $e->errors());
            $this->assertSame(__('fees.errors.enrollment_ended'), $e->errors()['discount_id'][0]);
        }

        $this->assertSame(0, $enrollment->refresh()->discount()->count());
    }

    #[DataProvider('endedStatuses')]
    public function test_ended_enrollments_reject_discount_removal(EnrollmentStatus $status): void
    {
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $enrollment = $this->enroll($grade, $year);
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, $status);

        try {
            app(RemoveEnrollmentDiscount::class)->handle($enrollment->id);
            $this->fail('Expected ValidationException for discount removal on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_id', $e->errors());
            $this->assertSame(__('fees.errors.enrollment_ended'), $e->errors()['discount_id'][0]);
        }

        $this->assertSame(1, $enrollment->refresh()->discount()->count());
    }

    #[DataProvider('endedStatuses')]
    public function test_ended_enrollments_reject_optional_removal(EnrollmentStatus $status): void
    {
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $gradeFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $gradeFee->id);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, $status);

        try {
            app(RemoveOptionalFeeFromEnrollment::class)->handle($enrollment->id, $gradeFee->id);
            $this->fail('Expected ValidationException for optional removal on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee_id', $e->errors());
            $this->assertSame(__('fees.errors.enrollment_ended'), $e->errors()['grade_fee_id'][0]);
        }

        $this->assertSame(2, $enrollment->refresh()->fees()->count());
    }

    public function test_ended_enrollment_hides_remove_buttons_and_rejects_removal_calls(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $gradeFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $gradeFee->id);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertDontSee(__('fees.remove_discount'), false)
            ->assertDontSee(__('common.delete'), false);

        $page = Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id]);

        $page->call('removeDiscount')
            ->assertHasErrors(['discount_id'])
            ->call('removeOptional', $gradeFee->id)
            ->assertHasErrors(['grade_fee_id']);

        $this->assertSame(1, $enrollment->refresh()->discount()->count());
        $this->assertSame(2, $enrollment->refresh()->fees()->count());
    }

    public function test_charged_enrollments_still_allow_adds(): void
    {
        [$grade, $year] = $this->fixtures();

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $gradeFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $active = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($active->id, $gradeFee->id);
        $this->assertSame(1, $active->refresh()->fees()->count());

        $suspended = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($suspended, EnrollmentStatus::Suspended);
        app(AddOptionalFeeToEnrollment::class)->handle($suspended->id, $gradeFee->id);
        $this->assertSame(1, $suspended->refresh()->fees()->count());
    }

    public function test_ended_enrollment_hides_add_and_remove_forms(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.errors.enrollment_ended'))
            ->assertDontSee(__('fees.add_optional_fee'))
            ->assertDontSee(__('fees.select_discount'));
    }

    public function test_students_page_links_to_enrollment_details(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->assertSee(route('enrollments.show', $enrollment), false)
            ->assertSee(__('enrollments.view_details'));
    }
}
