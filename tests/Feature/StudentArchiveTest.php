<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\ArchiveStudent;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RestoreStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentArchiveTest extends TestCase
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

    public function test_archive_and_restore_touch_no_enrollments_or_fees(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $this->assertSame(1, $enrollment->fees()->count());

        $archived = app(ArchiveStudent::class)->handle($student, 'pause');

        $this->assertNotNull($archived->archived_at);
        $this->assertSame('pause', $archived->archive_note);
        $this->assertTrue($archived->isArchived());

        // Enrollments and fees are untouched.
        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(1, $enrollment->refresh()->fees()->count());
        $this->assertSame(EnrollmentStatus::Active, $enrollment->refresh()->status);

        $restored = app(RestoreStudent::class)->handle($student->refresh());

        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archive_note);
        $this->assertFalse($restored->isArchived());
        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(1, $enrollment->refresh()->fees()->count());
    }

    public function test_archived_student_existing_enrollment_accepts_discount_and_status_change(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        app(ArchiveStudent::class)->handle($student);

        // Discounts still apply to existing enrollments of archived students.
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        $applied = app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        $this->assertSame(1000, $applied->percent_bp);

        // Status changes still apply too.
        app(UpdateEnrollmentStatus::class)->handle($enrollment->refresh(), EnrollmentStatus::Suspended, 'pause');

        $this->assertSame(EnrollmentStatus::Suspended, $enrollment->refresh()->status);
    }
}
