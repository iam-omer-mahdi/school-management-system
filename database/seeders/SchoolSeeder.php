<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $structure = [
            ['code' => 'elementary', 'name' => 'Elementary', 'grades' => [1, 2, 3, 4, 5, 6]],
            ['code' => 'middle', 'name' => 'Middle', 'grades' => [1, 2, 3]],
            ['code' => 'high', 'name' => 'High', 'grades' => [1, 2, 3]],
        ];

        foreach ($structure as $schoolData) {
            $school = School::firstOrCreate(
                ['code' => $schoolData['code']],
                ['name' => $schoolData['name']]
            );

            foreach ($schoolData['grades'] as $number) {
                Grade::firstOrCreate([
                    'school_id' => $school->id,
                    'number' => $number,
                ]);
            }
        }
    }
}
