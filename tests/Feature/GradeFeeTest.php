<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\DeleteGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GradeFeeTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_creates_grade_fee_with_minor_amount(): void
    {
        [$grade, $year] = $this->fixtures();

        $result = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1,250.50',
        ]);

        $this->assertSame(125050, $result->gradeFee->amount_minor);
        $this->assertSame(0, $result->applied);
        $this->assertSame(0, $result->skipped);
        $this->assertDatabaseHas('grade_fees', [
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'amount_minor' => 125050,
        ]);
    }

    public function test_rejects_duplicate_schedule(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuition = $this->tuition();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        try {
            app(CreateGradeFee::class)->handle([
                'grade_id' => $grade->id,
                'academic_year_id' => $year->id,
                'fee_type_id' => $tuition->id,
                'amount' => '2000',
            ]);
            $this->fail('Expected ValidationException for duplicate schedule');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fee_type_id', $e->errors());
        }

        $this->assertSame(1, GradeFee::count());
    }

    public function test_rejects_invalid_amounts(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuition = $this->tuition();

        foreach (['', 'abc', '0', '-5'] as $amount) {
            try {
                app(CreateGradeFee::class)->handle([
                    'grade_id' => $grade->id,
                    'academic_year_id' => $year->id,
                    'fee_type_id' => $tuition->id,
                    'amount' => $amount,
                ]);
                $this->fail("Expected ValidationException for amount [{$amount}]");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }

        $this->assertSame(0, GradeFee::count());
    }

    public function test_deletes_unused_fee_but_blocks_charged_fee(): void
    {
        [$grade, $year] = $this->fixtures();

        $bus = FeeType::factory()->create(['name' => 'Bus', 'is_mandatory' => false]);

        $free = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $bus->id,
            'amount' => '300',
        ])->gradeFee;

        app(DeleteGradeFee::class)->handle($free->id);

        $this->assertFalse(GradeFee::query()->whereKey($free->id)->exists());

        $charged = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ])->gradeFee;

        $student = Student::factory()->create();
        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        try {
            app(DeleteGradeFee::class)->handle($charged->id);
            $this->fail('Expected ValidationException when deleting a charged fee');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grade_fee', $e->errors());
        }

        $this->assertTrue(GradeFee::query()->whereKey($charged->id)->exists());
    }

    public function test_mandatory_fee_charges_only_active_and_suspended_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();
        $enroller = app(EnrollStudent::class);

        $ids = [];

        foreach (EnrollmentStatus::cases() as $status) {
            $student = Student::factory()->create();
            $enrollment = $enroller->handle($student->id, $year->id, $grade->id);
            app(UpdateEnrollmentStatus::class)->handle($enrollment, $status);
            $ids[$status->value] = $enrollment->id;
        }

        $result = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $this->assertSame(2, $result->applied);
        $this->assertSame(3, $result->skipped);

        foreach (['active', 'suspended'] as $charged) {
            $this->assertSame(
                1,
                Enrollment::find($ids[$charged])->fees()->count(),
                "Expected a charge for status [{$charged}]"
            );
        }

        foreach (['transferred', 'expelled', 'left'] as $skipped) {
            $this->assertSame(
                0,
                Enrollment::find($ids[$skipped])->fees()->count(),
                "Expected no charge for status [{$skipped}]"
            );
        }
    }

    public function test_optional_fee_does_not_charge_existing_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $bus = FeeType::factory()->create(['name' => 'Bus', 'is_mandatory' => false]);

        $result = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $bus->id,
            'amount' => '300',
        ]);

        $this->assertSame(0, $result->applied);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $enrollment->refresh()->fees()->count());
    }
}
