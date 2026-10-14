<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateDiscount;
use App\Actions\CreateFeeType;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\VoidPayment;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Support\EnrollmentBalance;
use App\Support\Reports\FinancialSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialSummaryParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_aggregates_equal_enrollment_balance_on_seeded_dataset(): void
    {
        // 1. Create structure
        $school = School::factory()->create(['code' => 'elementary', 'name' => 'Elementary']);
        $grade1 = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $grade2 = Grade::factory()->create(['school_id' => $school->id, 'number' => 2]);
        $year = AcademicYear::factory()->create(['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-01']);

        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();
        $bus = app(CreateFeeType::class)->handle(['name' => 'Bus Service', 'is_mandatory' => false]);

        // Fees for grade 1
        $g1Tuition = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade1->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000.00',
        ]);
        $g1Bus = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade1->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $bus->id,
            'amount' => '200.00',
        ]);

        // Fees for grade 2
        $g2Tuition = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade2->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1200.00',
        ]);

        // Catalog discount
        $discount10 = app(CreateDiscount::class)->handle(['name' => 'Sibling 10%', 'percent' => '10']);

        // Student 1: Active, Grade 1, has discount, paid tuition partially, paid bus in full
        $s1 = Student::factory()->create(['name' => 'Student 1']);
        $e1 = app(EnrollStudent::class)->handle($s1->id, $year->id, $grade1->id);
        $e1->discount()->create(['discount_id' => $discount10->id, 'name' => 'Sibling 10%', 'percent_bp' => 1000, 'amount_minor' => 10000]);
        $e1BusCharge = $e1->fees()->create(['grade_fee_id' => $g1Bus->id, 'amount_minor' => 20000]);
        $e1TuitionCharge = $e1->fees()->where('grade_fee_id', $g1Tuition->id)->firstOrFail();

        // Pay tuition partially
        app(RecordPayment::class)->handle([
            'enrollment_id' => $e1->id,
            'total_minor' => 50000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 50000]],
            'method' => 'cash',
            'paid_on' => '2026-09-10',
        ]);
        // Pay bus
        app(RecordPayment::class)->handle([
            'enrollment_id' => $e1->id,
            'total_minor' => 20000,
            'rows' => [['grade_fee_id' => $g1Bus->id, 'amount_minor' => 20000]],
            'method' => 'bank_transfer',
            'transaction_number' => 'TXN-111',
            'paid_on' => '2026-09-11',
        ]);

        // Student 2: Left (Ended), Grade 1, paid, refunded, voided refund
        $s2 = Student::factory()->create(['name' => 'Student 2']);
        $e2 = app(EnrollStudent::class)->handle($s2->id, $year->id, $grade1->id);
        $e2TuitionCharge = $e2->fees()->where('grade_fee_id', $g1Tuition->id)->firstOrFail();
        $p2 = app(RecordPayment::class)->handle([
            'enrollment_id' => $e2->id,
            'total_minor' => 60000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 60000]],
            'method' => 'cash',
            'paid_on' => '2026-09-12',
        ]);
        $e2->update(['status' => EnrollmentStatus::Left->value]);
        // Refund 20000
        $ref2 = app(RecordRefund::class)->handle([
            'enrollment_id' => $e2->id,
            'total_minor' => 20000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 20000]],
            'method' => 'cash',
            'refunded_on' => '2026-09-15',
            'note' => 'Leaving refund',
        ]);

        // Student 3: Suspended, Grade 2, voided payment
        $s3 = Student::factory()->create(['name' => 'Student 3']);
        $e3 = app(EnrollStudent::class)->handle($s3->id, $year->id, $grade2->id);
        $e3TuitionCharge = $e3->fees()->where('grade_fee_id', $g2Tuition->id)->firstOrFail();
        $p3 = app(RecordPayment::class)->handle([
            'enrollment_id' => $e3->id,
            'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $g2Tuition->id, 'amount_minor' => 40000]],
            'method' => 'cash',
            'paid_on' => '2026-09-16',
        ]);
        app(VoidPayment::class)->handle($e3->id, $p3['payments']->first()->id, 'Accidental entry');
        $e3->update(['status' => EnrollmentStatus::Suspended->value]);

        // Calculate expected values enrollment by enrollment via EnrollmentBalance
        $allEnrollments = Enrollment::query()->with(['fees.gradeFee.feeType', 'fees.payments', 'discount', 'refunds'])->get();

        $expectedGrandTotal = 0;
        $expectedCurrent = 0;
        $expectedStillOwed = 0;
        $expectedForgone = 0;

        foreach ($allEnrollments as $enrollment) {
            $bal = EnrollmentBalance::forEnrollment($enrollment);
            $grand = $bal['charged'] - $bal['discount'];
            $expectedGrandTotal += $grand;
            $expectedCurrent += $bal['net_paid'];
            $expectedStillOwed += $bal['owed'];
            $expectedForgone += $bal['closed_without_payment'];
        }

        // Now run FinancialSummary::forSelection()
        $summary = FinancialSummary::forSelection(['academic_year_id' => $year->id]);

        $this->assertSame($expectedGrandTotal, $summary['grand_total'], 'Grand total mismatch');
        $this->assertSame($expectedCurrent, $summary['current'], 'Current mismatch');
        $this->assertSame($expectedStillOwed, $summary['still_owed'], 'Still owed mismatch');
        $this->assertSame($expectedForgone, $summary['forgone'], 'Forgone mismatch');

        // Core identity: Grand total = Current + Still owed + Forgone
        $this->assertSame($summary['grand_total'], $summary['current'] + $summary['still_owed'] + $summary['forgone']);

        // Parity test on Year Summary by Grade
        $yearSummary = FinancialSummary::yearSummary(['academic_year_id' => $year->id]);
        $this->assertSame($summary['grand_total'], $yearSummary['totals']['grand_total']);
        $this->assertSame($summary['current'], $yearSummary['totals']['current']);
        $this->assertSame($summary['still_owed'], $yearSummary['totals']['still_owed']);
        $this->assertSame($summary['forgone'], $yearSummary['totals']['forgone']);

        // Sum of grades equals school total
        $gradesGrandTotal = $yearSummary['grades']->sum('grand_total');
        $this->assertSame($yearSummary['totals']['grand_total'], $gradesGrandTotal);
    }
}
