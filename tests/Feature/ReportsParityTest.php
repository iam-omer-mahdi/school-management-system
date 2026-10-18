<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateFeeType;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\UpdateEnrollmentStatus;
use App\Actions\VoidPayment;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\EnrollmentBalance;
use App\Support\Money;
use App\Support\Reports\ExcelExport;
use App\Support\Reports\FinancialSummary;
use App\Support\Reports\ReportQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function year(int $startYear, string $suffix = ''): AcademicYear
    {
        return AcademicYear::factory()->create([
            'name' => sprintf('%d/%d%s', $startYear, $startYear + 1, $suffix),
            'starts_on' => sprintf('%d-09-01', $startYear),
            'ends_on' => sprintf('%d-06-01', $startYear + 1),
        ]);
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    /**
     * Seeds: discounts, an optional fee, a split-submission payment,
     * refunds, a voided payment, ended and reactivated enrollments.
     *
     * @return array{year: AcademicYear, grade1: Grade, grade3: Grade, emptyYear: AcademicYear}
     */
    private function seedDataset(): array
    {
        $school = School::factory()->create(['name' => 'Elementary']);
        $grade1 = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $grade2 = Grade::factory()->create(['school_id' => $school->id, 'number' => 2]);
        $grade3 = Grade::factory()->create(['school_id' => $school->id, 'number' => 3]);
        $year = $this->year(2025);
        $emptyYear = $this->year(2024);

        $bus = app(CreateFeeType::class)->handle(['name' => 'Bus Service', 'is_mandatory' => false]);
        $g1Tuition = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade1->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => '1000',
        ])->gradeFee;
        $g1Bus = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade1->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $bus->id, 'amount' => '200',
        ])->gradeFee;
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade2->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => '1200',
        ]);

        $discount10 = app(CreateDiscount::class)->handle(['name' => 'Sibling 10%', 'percent' => '10']);

        // Active, discounted, optional bus, one split submission (2 rows, 1 event).
        $e1 = app(EnrollStudent::class)->handle(
            Student::factory()->create(['name' => 'Report One'])->id, $year->id, $grade1->id
        );
        app(ApplyDiscountToEnrollment::class)->handle($e1->id, $discount10->id);
        app(AddOptionalFeeToEnrollment::class)->handle($e1->id, $g1Bus->id);
        app(RecordPayment::class)->handle([
            'enrollment_id' => $e1->id, 'total_minor' => 50000,
            'rows' => [
                ['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 30000],
                ['grade_fee_id' => $g1Bus->id, 'amount_minor' => 20000],
            ],
            'method' => 'bank_transfer', 'transaction_number' => 'TXN-E1',
            'paid_on' => '2026-02-01',
        ]);

        // Left with a partial refund.
        $e2 = app(EnrollStudent::class)->handle(
            Student::factory()->create(['name' => 'Report Two'])->id, $year->id, $grade1->id
        );
        app(RecordPayment::class)->handle([
            'enrollment_id' => $e2->id, 'total_minor' => 60000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 60000]],
            'method' => 'cash', 'paid_on' => '2026-02-02',
        ]);
        app(UpdateEnrollmentStatus::class)->handle($e2, EnrollmentStatus::Left, 'Moved away');
        app(RecordRefund::class)->handle([
            'enrollment_id' => $e2->id, 'total_minor' => 20000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 20000]],
            'method' => 'cash', 'refunded_on' => '2026-02-03', 'note' => 'Leaving refund',
        ]);

        // Suspended with a voided payment (excluded everywhere).
        $e3 = app(EnrollStudent::class)->handle(
            Student::factory()->create(['name' => 'Report Three'])->id, $year->id, $grade2->id
        );
        $paid = app(RecordPayment::class)->handle([
            'enrollment_id' => $e3->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $e3->fees()->firstOrFail()->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'paid_on' => '2026-02-04',
        ]);
        app(VoidPayment::class)->handle($e3->id, $paid['payments']->first()->id, 'Accidental entry');
        app(UpdateEnrollmentStatus::class)->handle($e3, EnrollmentStatus::Suspended, 'Paused');

        // Left, refunded, then reactivated: counts as charged again.
        $e4 = app(EnrollStudent::class)->handle(
            Student::factory()->create(['name' => 'Report Four'])->id, $year->id, $grade1->id
        );
        app(RecordPayment::class)->handle([
            'enrollment_id' => $e4->id, 'total_minor' => 100000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 100000]],
            'method' => 'cash', 'paid_on' => '2026-02-05',
        ]);
        app(UpdateEnrollmentStatus::class)->handle($e4, EnrollmentStatus::Left, 'Gone');
        app(RecordRefund::class)->handle([
            'enrollment_id' => $e4->id, 'total_minor' => 20000,
            'rows' => [['grade_fee_id' => $g1Tuition->id, 'amount_minor' => 20000]],
            'method' => 'cash', 'refunded_on' => '2026-02-06', 'note' => 'Partial return',
        ]);
        app(UpdateEnrollmentStatus::class)->handle($e4, EnrollmentStatus::Active, 'Back');

        // Year without any fee schedule: zero balances, never errors.
        $lonely = Student::factory()->create(['name' => 'Report Five']);
        Enrollment::query()->create([
            'student_id' => $lonely->id, 'academic_year_id' => $emptyYear->id,
            'grade_id' => $grade1->id, 'status' => 'active',
        ]);

        return ['year' => $year, 'grade1' => $grade1, 'grade3' => $grade3, 'emptyYear' => $emptyYear];
    }

    /** @return array{grand: int, current: int, owed: int, forgone: int} */
    private function expectedFromBalances(int $yearId): array
    {
        $enrollments = Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->with(['fees.gradeFee.feeType', 'fees.payments', 'discount', 'refunds'])
            ->get();

        $totals = ['grand' => 0, 'current' => 0, 'owed' => 0, 'forgone' => 0];

        foreach ($enrollments as $enrollment) {
            $bal = EnrollmentBalance::forEnrollment($enrollment);
            $totals['grand'] += $bal['charged'] - $bal['discount'];
            $totals['current'] += $bal['net_paid'];
            $totals['owed'] += $bal['owed'];
            $totals['forgone'] += $bal['closed_without_payment'];
        }

        return $totals;
    }

    public function test_sql_aggregates_equal_enrollment_balance(): void
    {
        ['year' => $year] = $this->seedDataset();
        $expected = $this->expectedFromBalances($year->id);
        $summary = FinancialSummary::forSelection(['academic_year_id' => $year->id]);

        $this->assertSame($expected['grand'], $summary['grand_total']);
        $this->assertSame($expected['current'], $summary['current']);
        $this->assertSame($expected['owed'], $summary['still_owed']);
        $this->assertSame($expected['forgone'], $summary['forgone']);
        $this->assertSame(
            $summary['grand_total'],
            $summary['current'] + $summary['still_owed'] + $summary['forgone']
        );

        $byGrade = FinancialSummary::yearSummary(['academic_year_id' => $year->id]);
        $this->assertSame($summary['grand_total'], $byGrade['totals']['grand_total']);
        $this->assertSame($summary['current'], $byGrade['totals']['current']);
        $this->assertSame($byGrade['totals']['grand_total'], $byGrade['grades']->sum('grand_total'));
        $this->assertSame($byGrade['totals']['count'], $byGrade['grades']->sum('count'));

        $byFee = ReportQueries::byFeeType(['academic_year_id' => $year->id]);
        $this->assertSame($summary['grand_total'], $byFee['totals']['grand_total']);
        $this->assertSame($summary['current'], $byFee['totals']['current']);
    }

    public function test_identity_holds_per_status_filter(): void
    {
        ['year' => $year] = $this->seedDataset();
        $filterSets = [
            [], ['active'], ['suspended'], ['left'],
            FinancialSummary::CHARGED, FinancialSummary::ENDED,
        ];
        $unfiltered = FinancialSummary::forSelection(['academic_year_id' => $year->id]);
        $statusSum = 0;

        foreach (['active', 'suspended', 'left', 'expelled', 'transferred'] as $status) {
            $one = FinancialSummary::forSelection(['academic_year_id' => $year->id, 'statuses' => [$status]]);
            $statusSum += $one['grand_total'];
            $this->assertSame($one['grand_total'], $one['current'] + $one['still_owed'] + $one['forgone']);
        }

        $this->assertSame($unfiltered['grand_total'], $statusSum);

        foreach ($filterSets as $statuses) {
            $summary = FinancialSummary::forSelection(['academic_year_id' => $year->id, 'statuses' => $statuses]);
            $this->assertSame(
                $summary['grand_total'],
                $summary['current'] + $summary['still_owed'] + $summary['forgone'],
                'Identity failed for statuses: '.implode(',', $statuses)
            );
        }
    }

    public function test_zero_enrollment_grade_and_year_without_fees(): void
    {
        ['grade3' => $grade3, 'emptyYear' => $emptyYear] = $this->seedDataset();

        $empty = FinancialSummary::forSelection(['grade_id' => $grade3->id]);
        $this->assertSame(0, $empty['count']);
        $this->assertSame(0, $empty['grand_total']);
        $this->assertNull($empty['percent_paid']);

        $noFees = FinancialSummary::forSelection(['academic_year_id' => $emptyYear->id]);
        $this->assertSame(1, $noFees['count']);
        $this->assertSame(0, $noFees['grand_total']);
        $this->assertNull($noFees['percent_paid']);
    }

    public function test_discount_snapshot_uses_minor_unit_rounding(): void
    {
        $this->seedDataset();

        $enrollment = Enrollment::query()->whereHas('student', fn ($q) => $q->where('name', 'Report One'))->firstOrFail();
        $snapshot = (int) $enrollment->discount()->value('amount_minor');

        // 10% of 1000.00 tuition in minor units, half up.
        $this->assertSame(Money::percentOf(100000, 1000), $snapshot);
        $this->assertSame(10000, $snapshot);
    }

    public function test_reactivated_enrollment_counts_as_charged(): void
    {
        ['year' => $year] = $this->seedDataset();
        $filters = ['academic_year_id' => $year->id];

        $callIds = ReportQueries::callListQuery($filters)->pluck('enrollment_id')->all();
        $reactivated = Enrollment::query()
            ->whereHas('student', fn ($q) => $q->where('name', 'Report Four'))
            ->value('id');

        $this->assertContains($reactivated, $callIds);

        $forgone = ReportQueries::forgoneByGrade($filters);
        $this->assertSame(60000, (int) $forgone->sum('forgone_minor'));

        $details = ReportQueries::forgoneListQuery($filters);
        $this->assertSame(['Report Two'], $details->pluck('student_name')->all());
        $this->assertSame(60000, (int) $details->sum('forgone_minor'));
    }

    public function test_ended_only_status_selection_yields_empty_call_list(): void
    {
        ['year' => $year] = $this->seedDataset();

        $empty = ReportQueries::callListQuery([
            'academic_year_id' => $year->id, 'statuses' => ['left', 'expelled', 'transferred'],
        ])->get();

        $this->assertTrue($empty->isEmpty());

        $charged = ReportQueries::callListQuery([
            'academic_year_id' => $year->id, 'statuses' => ['active', 'suspended'],
        ])->get();

        $this->assertSame(3, $charged->count());
    }

    public function test_call_list_sorts_by_name_and_owed(): void
    {
        ['year' => $year] = $this->seedDataset();
        $filters = ['academic_year_id' => $year->id];

        $byOwed = ReportQueries::callListQuery($filters, 'owed_desc')->pluck('student_name')->all();
        $this->assertSame(['Report Three', 'Report One', 'Report Four'], $byOwed);

        $byName = ReportQueries::callListQuery($filters, 'name_asc')->pluck('student_name')->all();
        $this->assertSame(['Report Four', 'Report One', 'Report Three'], $byName);

        $this->actingAs(User::factory()->create());

        Livewire::test('pages::reports.index')
            ->set('academic_year_id', $year->id)
            ->set('tab', 'call_list')
            ->call('applyFilters')
            ->call('setCallSort', 'name')
            ->assertSeeInOrder(['Report Four', 'Report One', 'Report Three']);
    }

    public function test_collections_count_events_not_rows(): void
    {
        ['year' => $year] = $this->seedDataset();

        $coll = ReportQueries::collections([
            'academic_year_id' => $year->id, 'date_from' => '2026-02-01', 'date_to' => '2026-02-06',
        ]);

        // e1's split submission is two rows but one event.
        $first = $coll['days']->firstWhere('date', '2026-02-01');
        $this->assertSame(1, $first['events']);
        $this->assertSame(50000, $first['net']);
        $this->assertSame(['TXN-E1'], $first['txns']);

        // Refunds are negative lines, net cash accounts for them.
        $refundDay = $coll['days']->firstWhere('date', '2026-02-03');
        $this->assertSame(-20000, $refundDay['refunds']);
        $this->assertSame(5, $coll['events']);
        $this->assertSame(160000, $coll['total_cash']);
        $this->assertSame(50000, $coll['total_bank']);
        $this->assertSame(40000, $coll['total_refunds']);
        $this->assertSame(170000, $coll['net_total']);

        // The voided 40000 payment is audit-only.
        $this->assertTrue($coll['days']->firstWhere('date', '2026-02-04') === null);
        $this->assertSame(1, $coll['voided_payments']->count());
    }

    public function test_reports_pages_render_print_and_export(): void
    {
        ['year' => $year] = $this->seedDataset();
        $this->actingAs(User::factory()->create());

        $this->get(route('reports.index'))->assertOk();
        $this->get(route('reports.print', ['tab' => 'year_summary', 'academic_year_id' => $year->id]))->assertOk();
        $this->get(route('reports.print', [
            'tab' => 'call_list', 'academic_year_id' => $year->id, 'landscape' => 1,
        ]))->assertOk();

        Livewire::test('pages::reports.index')
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee(__('reports.year_summary'))
            ->set('tab', 'call_list')
            ->call('exportExcel')
            ->assertFileDownloaded('outstanding_call_list.xlsx');

        $leaver = Enrollment::query()
            ->whereHas('student', fn ($q) => $q->where('name', 'Report Two'))
            ->with('student')
            ->firstOrFail();

        // Forgone tab shows the summary plus per-student details with a profile link.
        Livewire::test('pages::reports.index')
            ->set('academic_year_id', $year->id)
            ->set('tab', 'forgone_by_leavers')
            ->set('statuses', ['left'])
            ->call('applyFilters')
            ->assertSee('Report Two')
            ->assertSee(route('students.show', $leaver->student_id), false);

        // Ended-only selection on the call list is empty, not unfiltered.
        Livewire::test('pages::reports.index')
            ->set('academic_year_id', $year->id)
            ->set('tab', 'call_list')
            ->set('statuses', ['left', 'expelled', 'transferred'])
            ->call('applyFilters')
            ->assertSee(__('reports.no_records'));

        app()->setLocale('ar');

        Livewire::test('pages::reports.index')
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee('التقارير المالية');

        app()->setLocale('en');
    }

    public function test_dashboard_renders_year_selector_and_charts(): void
    {
        ['year' => $year] = $this->seedDataset();
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee((string) $year->name, false);
    }

    public function test_excel_download_streams_valid_xlsx(): void
    {
        $response = ExcelExport::download(
            'check', 'Check', [__('reports.title') => 'T'],
            [__('payments.amount')], [[ExcelExport::money(150000)]], [ExcelExport::MONEY], false
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertSame('PK', substr($content, 0, 2));
    }
}
