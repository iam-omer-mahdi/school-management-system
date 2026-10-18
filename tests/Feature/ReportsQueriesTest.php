<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Reports\FinancialSummary;
use App\Support\Reports\ReportQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /** @return array{year: AcademicYear, grade: Grade} */
    private function seedFiveHundred(): array
    {
        $school = School::factory()->create(['name' => 'Performance']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create([
            'name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01',
        ]);

        $gradeFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => FeeType::query()->where('system_key', 'tuition')->firstOrFail()->id,
            'amount' => '1000',
        ])->gradeFee;

        $now = now();
        $students = Student::factory()->count(500)->make();
        Student::query()->insert($students->map(fn (Student $s): array => [
            'student_number' => $s->student_number, 'name' => $s->name, 'phone' => $s->phone,
            'secondary_phone' => null, 'home_address' => null, 'mother_name' => null,
            'blood_type' => null, 'date_of_birth' => null, 'guardian_name' => null,
            'guardian_relationship' => null, 'archived_at' => null, 'archive_note' => null,
            'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $studentIds = Student::query()
            ->whereIn('student_number', $students->pluck('student_number')->all())
            ->pluck('id', 'student_number');

        Enrollment::query()->insert($students->map(fn (Student $s): array => [
            'student_id' => $studentIds[$s->student_number],
            'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'status' => EnrollmentStatus::Active->value, 'status_note' => null,
            'status_changed_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $enrollmentIds = Enrollment::query()
            ->whereIn('student_id', $studentIds->values()->all())
            ->pluck('id', 'student_id');

        EnrollmentFee::query()->insert($studentIds->values()->map(fn (int $sid): array => [
            'enrollment_id' => $enrollmentIds[$sid], 'grade_fee_id' => $gradeFee->id,
            'amount_minor' => $gradeFee->amount_minor, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $chargeIds = EnrollmentFee::query()
            ->whereIn('enrollment_id', $enrollmentIds->values()->all())
            ->pluck('id', 'enrollment_id');

        Payment::query()->insert($studentIds->values()->map(fn (int $sid): array => [
            'enrollment_id' => $enrollmentIds[$sid],
            'enrollment_fee_id' => $chargeIds[$enrollmentIds[$sid]],
            'amount_minor' => 10000, 'method' => 'cash', 'transaction_number' => null,
            'paid_on' => '2030-10-01', 'note' => null,
            'voided_at' => null, 'void_reason' => null,
            'created_at' => $now, 'updated_at' => $now,
        ])->all());

        return ['year' => $year, 'grade' => $grade];
    }

    private function countQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $run();

        return $count;
    }

    public function test_each_report_runs_fixed_small_query_counts(): void
    {
        ['year' => $year] = $this->seedFiveHundred();
        $filters = ['academic_year_id' => $year->id];

        $this->assertLessThanOrEqual(2, $this->countQueries(
            fn () => FinancialSummary::forSelection($filters)
        ), 'forSelection');
        $this->assertLessThanOrEqual(2, $this->countQueries(
            fn () => FinancialSummary::yearSummary($filters)
        ), 'yearSummary');
        $this->assertLessThanOrEqual(3, $this->countQueries(
            fn () => ReportQueries::byFeeType($filters)
        ), 'byFeeType');
        $this->assertLessThanOrEqual(2, $this->countQueries(
            fn () => ReportQueries::callListQuery($filters)->get()
        ), 'callList');
        $this->assertLessThanOrEqual(2, $this->countQueries(
            fn () => ReportQueries::forgoneByGrade($filters)
        ), 'forgoneByGrade');
        $this->assertLessThanOrEqual(2, $this->countQueries(
            fn () => ReportQueries::discounts($filters)
        ), 'discounts');
        $this->assertLessThanOrEqual(10, $this->countQueries(
            fn () => ReportQueries::refundsQuery($filters)->get()
        ), 'refunds');
        $this->assertLessThanOrEqual(5, $this->countQueries(
            fn () => ReportQueries::collections($filters)
        ), 'collections');
        $this->assertLessThanOrEqual(3, $this->countQueries(
            fn () => ReportQueries::monthlyCollections($year->id)
        ), 'monthlyCollections');

        // Sanity: the bulk totals reconcile.
        $summary = FinancialSummary::forSelection($filters);
        $this->assertSame(50000000, $summary['grand_total']);
        $this->assertSame(5000000, $summary['current']);
        $this->assertSame($summary['grand_total'], $summary['current'] + $summary['still_owed'] + $summary['forgone']);
    }

    public function test_enroll_action_still_charges_and_reports_see_it(): void
    {
        ['year' => $year, 'grade' => $grade] = $this->seedFiveHundred();

        $student = Student::factory()->create(['name' => 'Late Enrollee']);
        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $summary = FinancialSummary::forSelection(['academic_year_id' => $year->id]);
        $this->assertSame(501, $summary['count']);
    }

    public function test_reports_label_grades_with_the_school_name(): void
    {
        ['year' => $year] = $this->seedFiveHundred();

        $summary = FinancialSummary::yearSummary(['academic_year_id' => $year->id]);

        $this->assertSame('Performance', $summary['grades'][0]['school_name']);
    }

    public function test_call_and_refund_totals_are_aggregated_not_loaded(): void
    {
        ['year' => $year] = $this->seedFiveHundred();
        $filters = ['academic_year_id' => $year->id];

        $rows = ReportQueries::callListQuery($filters)->get(['owed_minor']);

        $this->assertSame(
            ['count' => $rows->count(), 'owed' => (int) $rows->sum('owed_minor')],
            ReportQueries::callListTotals($filters)
        );
        $this->assertSame(['count' => 500, 'owed' => 45000000], ReportQueries::callListTotals($filters));
        $this->assertLessThanOrEqual(1, $this->countQueries(
            fn () => ReportQueries::callListTotals($filters)
        ), 'callListTotals');

        $now = now();

        Refund::query()->insert(Enrollment::query()->limit(2)->pluck('id')->map(fn (int $enrollmentId): array => [
            'enrollment_id' => $enrollmentId,
            'enrollment_fee_id' => EnrollmentFee::query()->where('enrollment_id', $enrollmentId)->value('id'),
            'amount_minor' => 2500, 'method' => 'cash', 'transaction_number' => null,
            'refunded_on' => '2030-10-02', 'note' => '',
            'voided_at' => null, 'void_reason' => null,
            'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $this->assertSame(['count' => 2, 'amount' => 5000], ReportQueries::refundsTotals($filters));
        $this->assertLessThanOrEqual(2, $this->countQueries(
            fn () => ReportQueries::refundsTotals($filters)
        ), 'refundsTotals');
    }
}
