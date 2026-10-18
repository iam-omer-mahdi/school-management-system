<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\SchoolType;
use App\Models\School;
use App\Support\SchoolName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateSchool
{
    /**
     * @throws ValidationException
     */
    public function handle(string $name, string $type): School
    {
        Gate::authorize(Permission::SchoolsManage->value);

        $name = SchoolName::validated($name);
        $schoolType = SchoolType::tryFrom($type);

        if ($schoolType === null) {
            throw ValidationException::withMessages([
                'type' => __('schools.errors.invalid_type'),
            ]);
        }

        try {
            return DB::transaction(function () use ($name, $schoolType): School {
                $school = School::query()->create(['name' => $name, 'type' => $schoolType]);

                foreach ($schoolType->gradeNumbers() as $number) {
                    $school->grades()->create(['number' => $number]);
                }

                return $school;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('schools.errors.name_taken'),
            ]);
        }
    }
}
