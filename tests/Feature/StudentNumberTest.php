<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateStudent;
use App\Actions\GenerateStudentNumber;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_taken_student_number(): void
    {
        Student::factory()->create(['student_number' => 'STU-000001']);

        $candidate = app(GenerateStudentNumber::class)->handle();

        $this->assertNotSame('STU-000001', $candidate);
    }

    public function test_sequential_creates_have_unique_numbers(): void
    {
        $creator = app(CreateStudent::class);
        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $student = $creator->handle([
                'name' => 'Student '.$i,
                'phone' => '09'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            ]);

            $numbers[] = $student->student_number;
        }

        $this->assertCount(5, array_unique($numbers));
        $this->assertSame(5, Student::query()->distinct('student_number')->count('student_number'));
    }

    public function test_duplicate_insert_is_rejected_by_unique_constraint(): void
    {
        Student::factory()->create(['student_number' => 'STU-000007']);

        $this->expectException(QueryException::class);

        Student::factory()->create(['student_number' => 'STU-000007']);
    }

    /**
     * Simulate a concurrent insert winning the race: fail the first
     * students insert with a genuine unique violation, then let the
     * retry go through.
     */
    private function failNextStudentInserts(int $times): void
    {
        $remaining = $times;

        DB::listen(function ($query) use (&$remaining): void {
            if ($remaining > 0 && str_starts_with((string) $query->sql, 'insert into `students`')) {
                $remaining--;

                throw new UniqueConstraintViolationException(
                    'mysql',
                    (string) $query->sql,
                    $query->bindings,
                    new \RuntimeException('Simulated collision.')
                );
            }
        });
    }

    public function test_create_retries_after_a_number_collision(): void
    {
        Student::factory()->create();

        $this->failNextStudentInserts(1);

        $student = app(CreateStudent::class)->handle([
            'name' => 'Retry Student',
            'phone' => '0911111111',
        ]);

        $this->assertNotEmpty($student->student_number);
        $this->assertSame(2, Student::count());
        $this->assertSame(2, Student::query()->distinct('student_number')->count('student_number'));
    }

    public function test_create_fails_cleanly_after_repeated_collisions(): void
    {
        Student::factory()->create();

        $this->failNextStudentInserts(5);

        try {
            app(CreateStudent::class)->handle([
                'name' => 'Unlucky Student',
                'phone' => '0922222222',
            ]);
            $this->fail('Expected ValidationException after repeated collisions');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_number', $e->errors());
        }

        $this->assertSame(1, Student::count());
    }
}
