<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\School;
use App\Support\SchoolName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Only the name is editable; the type is fixed at creation (plan D6). */
final class RenameSchool
{
    /**
     * @throws ValidationException
     */
    public function handle(int $schoolId, string $name): School
    {
        Gate::authorize(Permission::SchoolsManage->value);

        $name = SchoolName::validated($name);

        $school = School::query()->findOrFail($schoolId);

        try {
            DB::transaction(function () use ($school, $name): void {
                $school->update(['name' => $name]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('schools.errors.name_taken'),
            ]);
        }

        return $school;
    }
}
