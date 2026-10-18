<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class CreateStudent
{
    public function __construct(
        private readonly GenerateStudentNumber $numbers,
        private readonly EnrollStudent $enroller,
    ) {}

    /**
     * @param  array<string, mixed>  $studentData
     */
    public function handle(array $studentData, ?int $academicYearId = null, ?int $gradeId = null): Student
    {
        Gate::authorize(Permission::StudentsManage->value);

        $student = $this->createWithUniqueNumber($studentData);

        if ($academicYearId !== null && $gradeId !== null) {
            $this->enroller->handle($student->id, $academicYearId, $gradeId);
        }

        return $student;
    }

    /**
     * Generate the number and insert the row inside the same attempt, so a
     * concurrent insert cannot slip between the check and the write. Each
     * attempt runs in its own transaction, so a rolled-back attempt never
     * leaves a row behind. After five collisions a general domain error is
     * raised instead of leaking the database exception.
     *
     * @param  array<string, mixed>  $studentData
     *
     * @throws ValidationException
     */
    private function createWithUniqueNumber(array $studentData): Student
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(fn (): Student => Student::query()->create([
                    ...$studentData,
                    'student_number' => $this->numbers->handle(),
                ]));
            } catch (UniqueConstraintViolationException) {
                if ($attempts >= 5) {
                    Log::warning('Student number generation exhausted retries.', ['attempts' => $attempts]);

                    throw ValidationException::withMessages([
                        'student_number' => __('students.errors.number_unavailable'),
                    ]);
                }
            }
        }
    }

    public function enrollExisting(Student $student, int $academicYearId, int $gradeId): Enrollment
    {
        return $this->enroller->handle($student->id, $academicYearId, $gradeId);
    }
}
