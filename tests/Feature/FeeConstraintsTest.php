<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Enrollment, GradeFee}
     */
    private function charged(): array
    {
        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        $gradeFee = GradeFee::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount_minor' => 100000,
        ]);

        $enrollment = Enrollment::factory()->create([
            'student_id' => Student::factory()->create(),
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
        ]);

        return [$enrollment, $gradeFee];
    }

    public function test_grade_fee_amount_must_be_positive(): void
    {
        [$enrollment, $gradeFee] = $this->charged();

        $this->expectException(QueryException::class);

        DB::table('grade_fees')->insert([
            'grade_id' => $gradeFee->grade_id,
            'academic_year_id' => $gradeFee->academic_year_id,
            'fee_type_id' => FeeType::factory()->create(['name' => 'Bus'])->id,
            'amount_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_enrollment_fee_amount_must_be_positive(): void
    {
        [$enrollment, $gradeFee] = $this->charged();

        $this->expectException(QueryException::class);

        DB::table('enrollment_fees')->insert([
            'enrollment_id' => $enrollment->id,
            'grade_fee_id' => $gradeFee->id,
            'amount_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_discount_percent_must_be_within_range(): void
    {
        [$enrollment] = $this->charged();

        foreach ([0, 10001] as $percent) {
            try {
                DB::table('enrollment_discounts')->insert([
                    'enrollment_id' => $enrollment->id,
                    'percent_bp' => $percent,
                    'amount_minor' => 1000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail("Expected QueryException for percent_bp [{$percent}]");
            } catch (QueryException) {
                // Expected: the database rejects out-of-range percentages.
            }

            DB::table('enrollment_discounts')->where('enrollment_id', $enrollment->id)->delete();
        }

        $this->assertSame(0, DB::table('enrollment_discounts')->count());
    }
}
