<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateStudent;
use App\Actions\GenerateStudentNumber;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'status' => 'active',
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
}
