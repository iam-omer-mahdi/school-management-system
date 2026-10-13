<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Seeder;

/**
 * Dev-only performance fixture: 500 students enrolled across the 6
 * grades of the elementary (primary) school in one fixed academic year.
 *
 * Uses direct factory inserts (no action guards, no fee charges) for
 * speed. NOT registered in DatabaseSeeder: run explicitly with
 * `php artisan db:seed --class=DevPerformanceSeeder` on a disposable
 * dev database only. Aborts in production.
 *
 * Perf check afterwards: open /enrollments (Apply), /students and a
 * multi-enrollment student page, and watch query counts / render time,
 * e.g. with DB::listen counting queries per request.
 */
class DevPerformanceSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->isProduction(), 403, 'DevPerformanceSeeder is dev-only.');

        $started = microtime(true);

        $school = School::query()->where('code', 'elementary')->firstOrFail();

        $grades = Grade::query()
            ->where('school_id', $school->id)
            ->orderBy('number')
            ->get(['id', 'school_id', 'number']);

        abort_if($grades->count() !== 6, 500, 'Expected 6 elementary grades. Run SchoolSeeder first.');

        $year = AcademicYear::query()->firstOrCreate(
            ['name' => '2026/2027'],
            ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-01']
        );

        $students = Student::factory()->count(500)->create();

        $students->each(function (Student $student, int $index) use ($grades, $year): void {
            Enrollment::factory()->create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'grade_id' => $grades[$index % $grades->count()]->id,
            ]);
        });

        $elapsed = round(microtime(true) - $started, 2);

        $this->command->info(sprintf(
            'Seeded %d students and %d enrollments in %s s (peak %s MB).',
            $students->count(),
            Enrollment::query()->where('academic_year_id', $year->id)->count(),
            $elapsed,
            round(memory_get_peak_usage(true) / 1024 / 1024, 1)
        ));
    }
}
