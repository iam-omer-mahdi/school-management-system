<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RemoveEnrollmentDiscount;
use App\Models\AcademicYear;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        return [$grade, $year];
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    private function discount(string $name, string $percent): Discount
    {
        return app(CreateDiscount::class)->handle(['name' => $name, 'percent' => $percent]);
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

        $student = Student::factory()->create();

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    public function test_applies_catalog_discount_with_resolved_amount_and_snapshot(): void
    {
        $enrollment = $this->enrolledWithTuition('1000');
        $discount = $this->discount('Sibling', '10');

        $applied = app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        $this->assertSame($discount->id, $applied->discount_id);
        $this->assertSame('Sibling', $applied->name);
        $this->assertSame(1000, $applied->percent_bp);
        $this->assertSame(10000, $applied->amount_minor);
    }

    public function test_discount_rounds_half_up(): void
    {
        $enrollment = $this->enrolledWithTuition('0.03');
        $discount = $this->discount('Half', '50');

        $applied = app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        $this->assertSame(2, $applied->amount_minor);
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

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        $discount = $this->discount('Sibling', '10');

        try {
            app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);
            $this->fail('Expected ValidationException without a tuition charge');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_id', $e->errors());
        }
    }

    public function test_one_discount_per_enrollment_with_replace_and_remove(): void
    {
        $enrollment = $this->enrolledWithTuition('1000');
        $sibling = $this->discount('Sibling', '10');
        $staff = $this->discount('Staff', '20');

        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $sibling->id);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $staff->id);

        $this->assertSame(1, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());

        $applied = EnrollmentDiscount::where('enrollment_id', $enrollment->id)->firstOrFail();

        $this->assertSame($staff->id, $applied->discount_id);
        $this->assertSame('Staff', $applied->name);
        $this->assertSame(2000, $applied->percent_bp);
        $this->assertSame(20000, $applied->amount_minor);

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

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $this->discount('Sibling', '10')->id);

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
