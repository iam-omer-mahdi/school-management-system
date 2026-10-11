<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class CreateStudent
{
    public function __construct(
        private readonly GenerateStudentNumber $numbers,
        private readonly EnrollStudent $enroller,
    ) {}

    /**
     * @param array<string, mixed> $studentData
     */
    public function handle(array $studentData, ?int $academicYearId = null, ?int $gradeId = null): Student
    {
        return DB::transaction(function () use ($studentData, $academicYearId, $gradeId): Student {
            $student = Student::query()->create([
                ...$studentData,
                'student_number' => $this->numbers->handle(),
            ]);

            if ($academicYearId !== null && $gradeId !== null) {
                // EnrollStudent opens its own transaction; nested transactions use savepoints.
                $this->enroller->handle($student->id, $academicYearId, $gradeId);
            }

            return $student;
        });
    }

    public function enrollExisting(Student $student, int $academicYearId, int $gradeId): Enrollment
    {
        return $this->enroller->handle($student->id, $academicYearId, $gradeId);
    }
}
