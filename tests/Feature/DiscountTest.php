<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\SetEnrollmentDiscount;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        return [$grade, $year];
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    private function enrolledWithTuition(string $tuitionAmount): Enrollment
    {
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => $tuitionAmount,
        ]);

        $student = Student::factory()->create(['status' => StudentStatus::Active]);

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    public function test_sets_percentage_discount_with_resolved_amount(): void
    {
        $enrollment = $this->enrolledWithTuition('1000');

        $discount = app(SetEnrollmentDiscount::class)->handle($enrollment->id, '10', null);

        $this->assertSame(1000, $discount->percent_bp);
        $this->assertSame(10000, $discount->amount_minor);
    }

    public function test_discount_rounds_half_up(): void
    {
        $enrollment = $this->enrolledWithTuition('0.03');

        $discount = app(SetEnrollmentDiscount::class)->handle($enrollment->id, '50', null);

        $this->assertSame(2, $discount->amount_minor);
    }

    public function test_rejects_out_of_range_percentages(): void
    {
        $enrollment = $this->enrolledWithTuition('1000');

        foreach (['0', '0.00', '100.01', 'abc', ''] as $percent) {
            try {
                app(SetEnrollmentDiscount::class)->handle($enrollment->id, $percent, null);
                $this->fail("Expected ValidationException for percent [{$percent}]");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('percent', $e->errors());
            }
        }

        $this->assertSame(0, EnrollmentDiscount::count());
    }

    public function test_requires_tuition_charge(): void
    {
        [$grade, $year] = $this->fixtures();

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ]);

        $student = Student::factory()->create(['status' => StudentStatus::Active]);
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        try {
            app(SetEnrollmentDiscount::class)->handle($enrollment->id, '10', null);
            $this->fail('Expected ValidationException without a tuition charge');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percent', $e->errors());
        }
    }

    public function test_one_discount_per_enrollment_with_edit_and_remove(): void
    {
        $enrollment = $this->enrolledWithTuition('1000');

        app(SetEnrollmentDiscount::class)->handle($enrollment->id, '10', 'Sibling');
        app(SetEnrollmentDiscount::class)->handle($enrollment->id, '20', 'Updated');

        $this->assertSame(1, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());

        $discount = EnrollmentDiscount::where('enrollment_id', $enrollment->id)->firstOrFail();

        $this->assertSame(2000, $discount->percent_bp);
        $this->assertSame(20000, $discount->amount_minor);
        $this->assertSame('Updated', $discount->note);

        app(RemoveEnrollmentDiscount::class)->handle($enrollment->id);

        $this->assertSame(0, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());

        try {
            app(RemoveEnrollmentDiscount::class)->handle($enrollment->id);
            $this->fail('Expected ValidationException when removing a missing discount');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percent', $e->errors());
        }
    }

    public function test_totals_break_down_correctly(): void
    {
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $bus = FeeType::factory()->create(['name' => 'Bus', 'is_mandatory' => true]);

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $bus->id,
            'amount' => '300',
        ]);

        $student = Student::factory()->create(['status' => StudentStatus::Active]);
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        app(SetEnrollmentDiscount::class)->handle($enrollment->id, '10', null);

        $enrollment->load(['fees.gradeFee.feeType', 'discount']);

        $tuition = 0;
        $other = 0;

        foreach ($enrollment->fees as $charge) {
            if ($charge->gradeFee->feeType->isTuition()) {
                $tuition += $charge->amount_minor;
            } else {
                $other += $charge->amount_minor;
            }
        }

        $discount = $enrollment->discount->amount_minor;

        $this->assertSame(100000, $tuition);
        $this->assertSame(10000, $discount);
        $this->assertSame(90000, $tuition - $discount);
        $this->assertSame(30000, $other);
        $this->assertSame(120000, $tuition - $discount + $other);
    }
}
