<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentFeesTest extends TestCase
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

    private function schedule(Grade $grade, AcademicYear $year, FeeType $type, string $amount): void
    {
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $type->id,
            'amount' => $amount,
        ]);
    }

    public function test_enrolling_charges_existing_mandatory_fees(): void
    {
        [$grade, $year] = $this->fixtures();

        $this->schedule($grade, $year, $this->tuition(), '1000');

        $bus = FeeType::factory()->create(['name' => 'Bus', 'is_mandatory' => true]);
        $this->schedule($grade, $year, $bus, '300');

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $this->schedule($grade, $year, $books, '150');

        $enrollment = $this->enroll($grade, $year)->refresh();

        $this->assertSame(2, $enrollment->fees()->count());
        $this->assertSame(
            100000,
            $enrollment->fees()->where('grade_fees.fee_type_id', $this->tuition()->id)
                ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
                ->value('enrollment_fees.amount_minor')
        );
    }

    public function test_enroll_before_tuition_then_create_tuition_charges_it(): void
    {
        [$grade, $year] = $this->fixtures();

        $enrollment = $this->enroll($grade, $year);

        $this->assertSame(0, $enrollment->refresh()->fees()->count());

        $result = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $this->assertSame(1, $result->applied);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(1, $enrollment->refresh()->fees()->count());
        $this->assertSame(100000, $enrollment->fees()->firstOrFail()->amount_minor);
    }

    public function test_frozen_amount_never_changes_for_next_year_schedule(): void
    {
        [$grade, $year] = $this->fixtures();

        $next = AcademicYear::factory()->create(['name' => '2031/2032', 'starts_on' => '2031-09-01', 'ends_on' => '2032-06-01']);

        $this->schedule($grade, $year, $this->tuition(), '1000');

        $enrollment = $this->enroll($grade, $year);

        $this->schedule($grade, $next, $this->tuition(), '1500');

        $this->assertSame(100000, $enrollment->refresh()->fees()->firstOrFail()->amount_minor);
    }

    public function test_add_and_remove_optional_fee(): void
    {
        [$grade, $year] = $this->fixtures();

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);

        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);

        $charge = app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        $this->assertSame(15000, $charge->amount_minor);
        $this->assertSame(1, $enrollment->refresh()->fees()->count());

        try {
            app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);
            $this->fail('Expected ValidationException for duplicate charge');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee_id', $e->errors());
        }

        app(RemoveOptionalFeeFromEnrollment::class)->handle($enrollment->id, $booksFee->id);

        $this->assertSame(0, $enrollment->refresh()->fees()->count());

        try {
            app(RemoveOptionalFeeFromEnrollment::class)->handle($enrollment->id, $booksFee->id);
            $this->fail('Expected ValidationException when removing a missing charge');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee_id', $e->errors());
        }
    }

    public function test_cannot_add_mandatory_fee_manually(): void
    {
        [$grade, $year] = $this->fixtures();

        $tuitionFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);

        try {
            app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $tuitionFee->id);
            $this->fail('Expected ValidationException when adding a mandatory fee manually');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee_id', $e->errors());
        }
    }

    public function test_cannot_remove_mandatory_charge(): void
    {
        [$grade, $year] = $this->fixtures();

        $tuitionFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);

        try {
            app(RemoveOptionalFeeFromEnrollment::class)->handle($enrollment->id, $tuitionFee->id);
            $this->fail('Expected ValidationException when removing a mandatory charge');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee_id', $e->errors());
        }

        $this->assertSame(1, $enrollment->refresh()->fees()->count());
    }

    public function test_ended_enrollments_keep_charges_visible(): void
    {
        [$grade, $year] = $this->fixtures();

        $this->schedule($grade, $year, $this->tuition(), '1000');

        $enrollment = $this->enroll($grade, $year);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Transferred);

        $enrollment = $enrollment->refresh();

        $this->assertSame(1, $enrollment->fees()->count());
        $this->assertSame(10000, $enrollment->discount()->firstOrFail()->amount_minor);
    }
}
