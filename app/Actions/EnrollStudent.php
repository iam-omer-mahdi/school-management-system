<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnrollStudent
{
    /**
     * @throws ValidationException
     */
    public function handle(int $studentId, int $academicYearId, int $gradeId): Enrollment
    {
        return DB::transaction(function () use ($studentId, $academicYearId, $gradeId): Enrollment {
            $student = DB::table('students')->where('id', $studentId)->first();

            if ($student === null) {
                throw ValidationException::withMessages([
                    'student_id' => __('students.errors.not_found'),
                ]);
            }

            if ($student->status !== 'active') {
                throw ValidationException::withMessages([
                    'student_id' => __('students.errors.inactive_cannot_enroll'),
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

            return Enrollment::query()->create([
                'student_id' => $studentId,
                'academic_year_id' => $academicYearId,
                'grade_id' => $gradeId,
            ]);
        });
    }
}
