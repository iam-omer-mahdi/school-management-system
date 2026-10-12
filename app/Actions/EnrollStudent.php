<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Enrollment;
use App\Models\Grade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnrollStudent
{
    public function __construct(
        private readonly ApplyMandatoryFeesToEnrollment $mandatoryFees,
    ) {}

    /**
     * Enroll any non-archived student, regardless of earlier enrollment
     * statuses. The new enrollment starts active and gets all mandatory
     * fees. Archived students are rejected; existing enrollments of an
     * archived student are untouched by that rule.
     *
     * Lock order (shared with CreateGradeFee): grades, then enrollments
     * and charges. Locking the grade first means a mandatory fee created
     * at the same moment as an enrollment cannot be missed by either side.
     *
     * @throws ValidationException
     */
    public function handle(int $studentId, int $academicYearId, int $gradeId): Enrollment
    {
        return DB::transaction(function () use ($studentId, $academicYearId, $gradeId): Enrollment {
            $grade = Grade::query()->whereKey($gradeId)->lockForUpdate()->first();

            if ($grade === null) {
                throw ValidationException::withMessages([
                    'grade_id' => __('fees.errors.grade_not_found'),
                ]);
            }

            $student = DB::table('students')->where('id', $studentId)->first();

            if ($student === null) {
                throw ValidationException::withMessages([
                    'student_id' => __('students.errors.not_found'),
                ]);
            }

            if ($student->archived_at !== null) {
                throw ValidationException::withMessages([
                    'student_id' => __('students.errors.archived_cannot_enroll'),
                ]);
            }

            $exists = Enrollment::query()
                ->where('student_id', $studentId)
                ->where('academic_year_id', $academicYearId)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('students.errors.already_enrolled'),
                ]);
            }

            $enrollment = Enrollment::query()->create([
                'student_id' => $studentId,
                'academic_year_id' => $academicYearId,
                'grade_id' => $gradeId,
                'status' => 'active',
            ]);

            $this->mandatoryFees->handle($enrollment);

            return $enrollment;
        });
    }
}
