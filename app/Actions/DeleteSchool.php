<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteSchool
{
    /**
     * Grades never block a delete on their own — every school has them — so
     * they are removed with the school. Enrollments and grade fees do block,
     * and the foreign keys would refuse the delete anyway (plan §6).
     *
     * @throws ValidationException
     */
    public function handle(int $schoolId): void
    {
        Gate::authorize(Permission::SchoolsManage->value);

        $school = School::query()->findOrFail($schoolId);

        if ($this->holdsStudentData($school)) {
            throw ValidationException::withMessages([
                'school' => __('schools.errors.has_data'),
            ]);
        }

        DB::transaction(function () use ($school): void {
            Grade::query()->where('school_id', $school->id)->delete();
            $school->delete();
        });
    }

    private function holdsStudentData(School $school): bool
    {
        $gradeIds = Grade::query()->where('school_id', $school->id)->select('id');

        return Enrollment::query()->whereIn('grade_id', $gradeIds)->exists()
            || GradeFee::query()->whereIn('grade_id', $gradeIds)->exists();
    }
}
