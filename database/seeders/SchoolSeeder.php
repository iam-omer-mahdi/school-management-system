<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SchoolType;
use App\Models\Grade;
use App\Models\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        // The grade structure lives on the enum, so a fresh install and the
        // admin-created-school path cannot drift apart (plan D9).
        foreach (SchoolType::cases() as $type) {
            $school = School::firstOrCreate(
                ['type' => $type->value],
                ['name' => $type->name]
            );

            foreach ($type->gradeNumbers() as $number) {
                Grade::firstOrCreate([
                    'school_id' => $school->id,
                    'number' => $number,
                ]);
            }
        }
    }
}
