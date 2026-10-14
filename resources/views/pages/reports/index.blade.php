<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\School;
use App\Models\Student;
use App\Support\Money;
use App\Support\Reports\ExcelExport;
use App\Support\Reports\FinancialSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Url]
    public string $tab = 'year_summary';

    #[Url]
    public ?int $academic_year_id = null;

    #[Url]
    public ?int $school_id = null;

    #[Url]
    public ?int $grade_id = null;

    /** @var array<string> */
    #[Url]
    public array $statuses = [];

    #[Url]
    public string $date_from = '';

    #[Url]
    public string $date_to = '';

    #[Url]
    public ?int $student_id = null;

    public string $student_search = '';

    // Applied state (Apply button pattern)
    public ?int $applied_academic_year_id = null;
    public ?int $applied_school_id = null;
    public ?int $applied_grade_id = null;
    /** @var array<string> */
    public array $applied_statuses = [];
    public string $applied_date_from = '';
    public string $applied_date_to = '';
    public ?int $applied_student_id = null;
    public bool $applied = false;

    public function mount(): void
    {
        if ($this->academic_year_id === null) {
            $latestYear = AcademicYear::query()->orderByDesc('starts_on')->first();
            $this->academic_year_id = $latestYear?->id;
        }

        // Apply initially with defaults
        $this->applyFilters();
    }

    public function applyFilters(): void
    {
        $this->applied_academic_year_id = $this->academic_year_id;
        $this->applied_school_id = $this->school_id;
        $this->applied_grade_id = $this->grade_id;
        $this->applied_statuses = $this->statuses;
        $this->applied_date_from = $this->date_from;
        $this->applied_date_to = $this->date_to;
        $this->applied_student_id = $this->student_id;
        $this->applied = true;
    }

    public function setPreset(string $preset): void
    {
        if ($preset === 'charged') {
            $this->statuses = ['active', 'suspended'];
        } elseif ($preset === 'ended') {
            $this->statuses = ['left', 'expelled', 'transferred'];
        } else {
            $this->statuses = [];
        }
    }

    public function updatedSchoolId(): void
    {
        $this->grade_id = null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    /** @return array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: array<string>} */
    public function currentFilters(): array
    {
        return [
            'academic_year_id' => $this->applied_academic_year_id,
            'school_id' => $this->applied_school_id,
            'grade_id' => $this->applied_grade_id,
            'statuses' => $this->applied_statuses,
        ];
    }

    #[Computed]
    public function schools(): Collection
    {
        return School::query()->select(['id', 'code', 'name'])->orderBy('id')->get();
    }

    #[Computed]
    public function grades(): Collection
    {
        if ($this->school_id === null) {
            return new Collection();
        }

        return Grade::query()
            ->select(['id', 'school_id', 'number'])
            ->where('school_id', $this->school_id)
            ->orderBy('number')
            ->get();
    }

    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderByDesc('starts_on')->get();
    }

    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return $this->applied_academic_year_id
            ? AcademicYear::query()->find($this->applied_academic_year_id)
            : null;
    }

    #[Computed]
    public function currentSchool(): ?School
    {
        return $this->applied_school_id
            ? School::query()->find($this->applied_school_id)
            : null;
    }

    #[Computed]
    public function currentGrade(): ?Grade
    {
        return $this->applied_grade_id
            ? Grade::query()->find($this->applied_grade_id)
            : null;
    }

    #[Computed]
    public function statusFilterLabel(): string
    {
        if (empty($this->applied_statuses)) {
            return __('reports.all_statuses');
        }

        return implode(', ', array_map(
            fn ($s) => __('enrollments.status_'.$s),
            $this->applied_statuses
        ));
    }

    // --- DATA PROVIDERS ---

    #[Computed]
    public function yearSummaryData(): array
    {
        return FinancialSummary::yearSummary($this->currentFilters());
    }

    #[Computed]
    public function feeTypeData(): array
    {
        return FinancialSummary::byFeeType($this->currentFilters());
    }

    #[Computed]
    public function callListData(): Collection
    {
        $filters = $this->currentFilters();
        // Force active and suspended
        $statuses = empty($filters['statuses'])
            ? ['active', 'suspended']
            : array_values(array_intersect($filters['statuses'], ['active', 'suspended']));

        if (empty($statuses)) {
            return collect();
        }

        $filters['statuses'] = $statuses;
        $base = FinancialSummary::baseQuery($filters);

        $feesSub = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as charged_minor'))
            ->groupBy('enrollment_id');

        $discountsSub = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as discount_minor'))
            ->groupBy('enrollment_id');

        $paymentsSub = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as paid_minor'))
            ->groupBy('enrollment_id');

        $refundsSub = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as refunded_minor'))
            ->groupBy('enrollment_id');

        $query = (clone $base)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($feesSub, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discountsSub, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($paymentsSub, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refundsSub, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'enrollments.id as enrollment_id',
                'enrollments.status',
                'students.student_number',
                'students.name as student_name',
                'students.phone',
                'students.secondary_phone',
                'students.guardian_name',
                'grades.number as grade_number',
                'schools.code as school_code',
                DB::raw('(COALESCE(f.charged_minor, 0) - COALESCE(d.discount_minor, 0) - (COALESCE(p.paid_minor, 0) - COALESCE(r.refunded_minor, 0))) as owed_minor'),
            ])
            ->whereRaw('(COALESCE(f.charged_minor, 0) - COALESCE(d.discount_minor, 0) - (COALESCE(p.paid_minor, 0) - COALESCE(r.refunded_minor, 0))) > 0')
            ->orderByDesc('owed_minor');

        return $query->get();
    }

    #[Computed]
    public function collectionsData(): array
    {
        $base = FinancialSummary::baseQuery($this->currentFilters());
        $enrollmentIds = (clone $base)->select('enrollments.id');

        // Valid payments
        $payQ = Payment::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at');

        if ($this->applied_date_from !== '') {
            $payQ->where('paid_on', '>=', $this->applied_date_from);
        }
        if ($this->applied_date_to !== '') {
            $payQ->where('paid_on', '<=', $this->applied_date_to);
        }

        $payments = $payQ->orderBy('paid_on')->get();

        // Valid refunds
        $refQ = Refund::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at');

        if ($this->applied_date_from !== '') {
            $refQ->where('refunded_on', '>=', $this->applied_date_from);
        }
        if ($this->applied_date_to !== '') {
            $refQ->where('refunded_on', '<=', $this->applied_date_to);
        }

        $refunds = $refQ->orderBy('refunded_on')->get();

        // Group by date
        $days = [];
        $totalCash = 0;
        $totalBank = 0;

        foreach ($payments as $p) {
            $d = $p->paid_on?->format('Y-m-d') ?? '';
            if (! isset($days[$d])) {
                $days[$d] = ['date' => $d, 'cash' => 0, 'bank' => 0, 'txns' => [], 'events' => 0];
            }
            $days[$d]['events']++;
            if ($p->method->value === 'cash') {
                $days[$d]['cash'] += $p->amount_minor;
                $totalCash += $p->amount_minor;
            } else {
                $days[$d]['bank'] += $p->amount_minor;
                $totalBank += $p->amount_minor;
                if ($p->transaction_number) {
                    $days[$d]['txns'][] = $p->transaction_number;
                }
            }
        }

        foreach ($refunds as $r) {
            $d = $r->refunded_on?->format('Y-m-d') ?? '';
            if (! isset($days[$d])) {
                $days[$d] = ['date' => $d, 'cash' => 0, 'bank' => 0, 'txns' => [], 'events' => 0];
            }
            $days[$d]['events']++;
            if ($r->method->value === 'cash') {
                $days[$d]['cash'] -= $r->amount_minor;
                $totalCash -= $r->amount_minor;
            } else {
                $days[$d]['bank'] -= $r->amount_minor;
                $totalBank -= $r->amount_minor;
                if ($r->transaction_number) {
                    $days[$d]['txns'][] = $r->transaction_number;
                }
            }
        }

        ksort($days);

        foreach ($days as &$day) {
            $day['net'] = $day['cash'] + $day['bank'];
            $day['txns'] = array_values(array_unique($day['txns']));
        }
        unset($day);

        // Voided audit rows
        $voidedPayments = Payment::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNotNull('voided_at')
            ->orderByDesc('voided_at')
            ->get();

        $voidedRefunds = Refund::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNotNull('voided_at')
            ->orderByDesc('voided_at')
            ->get();

        return [
            'days' => collect(array_values($days)),
            'total_cash' => $totalCash,
            'total_bank' => $totalBank,
            'net_total' => $totalCash + $totalBank,
            'voided_payments' => $voidedPayments,
            'voided_refunds' => $voidedRefunds,
        ];
    }

    #[Computed]
    public function discountsData(): Collection
    {
        $base = FinancialSummary::baseQuery($this->currentFilters());
        $enrollmentIds = (clone $base)->select('enrollments.id');

        return DB::table('enrollment_discounts')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->select([
                'name',
                DB::raw('COUNT(DISTINCT enrollment_id) as students_count'),
                DB::raw('SUM(amount_minor) as total_amount_minor'),
            ])
            ->groupBy('name')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function forgoneData(): Collection
    {
        $filters = $this->currentFilters();
        // Ended enrollments only
        $statuses = empty($filters['statuses'])
            ? ['left', 'expelled', 'transferred']
            : array_values(array_intersect($filters['statuses'], ['left', 'expelled', 'transferred']));

        if (empty($statuses)) {
            return collect();
        }

        $filters['statuses'] = $statuses;
        $base = FinancialSummary::baseQuery($filters);

        $feesSub = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as charged_minor'))
            ->groupBy('enrollment_id');

        $discountsSub = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as discount_minor'))
            ->groupBy('enrollment_id');

        $paymentsSub = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as paid_minor'))
            ->groupBy('enrollment_id');

        $refundsSub = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as refunded_minor'))
            ->groupBy('enrollment_id');

        $rows = (clone $base)
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($feesSub, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discountsSub, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($paymentsSub, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refundsSub, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'grades.id as grade_id',
                'grades.number as grade_number',
                'schools.code as school_code',
                DB::raw('COUNT(enrollments.id) as students_count'),
                DB::raw('SUM(COALESCE(f.charged_minor, 0) - COALESCE(d.discount_minor, 0) - (COALESCE(p.paid_minor, 0) - COALESCE(r.refunded_minor, 0))) as forgone_minor'),
            ])
            ->groupBy('grades.id', 'grades.number', 'schools.code')
            ->orderBy('schools.code')
            ->orderBy('grades.number')
            ->get();

        return $rows;
    }

    #[Computed]
    public function refundsData(): Collection
    {
        $base = FinancialSummary::baseQuery($this->currentFilters());
        $enrollmentIds = (clone $base)->select('enrollments.id');

        $query = Refund::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at')
            ->with([
                'enrollment.student:id,student_number,name',
                'enrollment.grade:id,school_id,number',
                'enrollment.grade.school:id,code,name',
                'enrollmentFee.gradeFee.feeType:id,name,system_key',
            ]);

        if ($this->applied_date_from !== '') {
            $query->where('refunded_on', '>=', $this->applied_date_from);
        }
        if ($this->applied_date_to !== '') {
            $query->where('refunded_on', '<=', $this->applied_date_to);
        }

        return $query->orderByDesc('refunded_on')->get();
    }

    #[Computed]
    public function studentStatementData(): ?array
    {
        if (! $this->applied_student_id) {
            return null;
        }

        $student = Student::query()
            ->with([
                'enrollments' => fn ($e) => $e->orderBy('academic_year_id'),
                'enrollments.academicYear',
                'enrollments.grade.school',
                'enrollments.fees.gradeFee.feeType',
                'enrollments.fees.payments' => fn ($p) => $p->whereNull('voided_at'),
                'enrollments.discount',
                'enrollments.refunds' => fn ($r) => $r->whereNull('voided_at'),
            ])
            ->find($this->applied_student_id);

        if (! $student) {
            return null;
        }

        return ['student' => $student];
    }

    // --- EXCEL EXPORT ---

    public function exportExcel(): mixed
    {
        $isAr = app()->getLocale() === 'ar';
        $meta = [
            __('reports.title') => __('reports.'.$this->tab),
            __('fees.academic_year') => $this->currentYear?->name ?? __('reports.all_statuses'),
            __('fees.school') => $this->currentSchool ? __('schools.'.$this->currentSchool->code) : __('reports.all_schools'),
            __('reports.status_filter') => $this->statusFilterLabel,
            __('reports.generated_at') => now()->format('Y-m-d H:i:s'),
        ];

        return match ($this->tab) {
            'year_summary' => $this->exportYearSummaryExcel($meta, $isAr),
            'tuition_vs_other' => $this->exportFeeTypeExcel($meta, $isAr),
            'call_list' => $this->exportCallListExcel($meta, $isAr),
            'collections' => $this->exportCollectionsExcel($meta, $isAr),
            'discounts' => $this->exportDiscountsExcel($meta, $isAr),
            'forgone_by_leavers' => $this->exportForgoneExcel($meta, $isAr),
            'refunds' => $this->exportRefundsExcel($meta, $isAr),
            default => null,
        };
    }

    private function exportYearSummaryExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('fees.school'),
            __('fees.grade'),
            __('reports.enrollments_count'),
            __('reports.grand_total'),
            __('reports.current'),
            __('reports.remaining'),
            __('reports.still_owed'),
            __('reports.forgone'),
            __('reports.percent_paid'),
        ];

        $data = $this->yearSummaryData;
        $rows = [];

        foreach ($data['grades'] as $g) {
            $rows[] = [
                __('schools.'.$g['school_code']),
                __('schools.grade_number', ['number' => $g['grade_number']]),
                $g['count'],
                ExcelExport::money($g['grand_total']),
                ExcelExport::money($g['current']),
                ExcelExport::money($g['remaining']),
                ExcelExport::money($g['still_owed']),
                ExcelExport::money($g['forgone']),
                $g['percent_paid'] !== null ? round($g['percent_paid'], 1).'%' : '—',
            ];
        }

        // Totals
        $t = $data['totals'];
        $rows[] = [
            __('payments.total'),
            '',
            $t['count'],
            ExcelExport::money($t['grand_total']),
            ExcelExport::money($t['current']),
            ExcelExport::money($t['remaining']),
            ExcelExport::money($t['still_owed']),
            ExcelExport::money($t['forgone']),
            $t['percent_paid'] !== null ? round($t['percent_paid'], 1).'%' : '—',
        ];

        return ExcelExport::download('year_summary', __('reports.year_summary'), $meta, $columns, $rows, $isAr);
    }

    private function exportFeeTypeExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('reports.fee_type'),
            __('reports.grand_total'),
            __('reports.current'),
            __('reports.remaining'),
            __('reports.percent_paid'),
        ];

        $data = $this->feeTypeData;
        $rows = [];

        foreach ($data['fee_types'] as $ft) {
            $rows[] = [
                $ft['is_tuition'] ? __('fees.tuition') : $ft['name'],
                ExcelExport::money($ft['grand_total']),
                ExcelExport::money($ft['current']),
                ExcelExport::money($ft['remaining']),
                $ft['percent_paid'] !== null ? round($ft['percent_paid'], 1).'%' : '—',
            ];
        }

        $t = $data['totals'];
        $rows[] = [
            __('payments.total'),
            ExcelExport::money($t['grand_total']),
            ExcelExport::money($t['current']),
            ExcelExport::money($t['remaining']),
            $t['percent_paid'] !== null ? round($t['percent_paid'], 1).'%' : '—',
        ];

        return ExcelExport::download('tuition_vs_other_fees', __('reports.tuition_vs_other'), $meta, $columns, $rows, $isAr);
    }

    private function exportCallListExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('students.student_number'),
            __('students.name'),
            __('students.phone'),
            __('students.secondary_phone'),
            __('students.guardian_name'),
            __('fees.grade'),
            __('enrollments.status'),
            __('reports.still_owed'),
        ];

        $rows = [];
        foreach ($this->callListData as $item) {
            $rows[] = [
                $item->student_number,
                $item->student_name,
                $item->phone,
                $item->secondary_phone ?? '—',
                $item->guardian_name ?? '—',
                __('schools.'.$item->school_code).' '.__('schools.grade_number', ['number' => $item->grade_number]),
                __('enrollments.status_'.$item->status),
                ExcelExport::money((int) $item->owed_minor),
            ];
        }

        return ExcelExport::download('outstanding_call_list', __('reports.call_list'), $meta, $columns, $rows, $isAr);
    }

    private function exportCollectionsExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('payments.paid_on'),
            __('payments.cash'),
            __('payments.bank_transfer'),
            __('reports.transaction_numbers'),
            __('reports.cash_net'),
        ];

        $data = $this->collectionsData;
        $rows = [];

        foreach ($data['days'] as $day) {
            $rows[] = [
                $day['date'],
                ExcelExport::money($day['cash']),
                ExcelExport::money($day['bank']),
                implode(', ', $day['txns']),
                ExcelExport::money($day['net']),
            ];
        }

        $rows[] = [
            __('payments.total'),
            ExcelExport::money($data['total_cash']),
            ExcelExport::money($data['total_bank']),
            '',
            ExcelExport::money($data['net_total']),
        ];

        return ExcelExport::download('collections', __('reports.collections'), $meta, $columns, $rows, $isAr);
    }

    private function exportDiscountsExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('reports.discount_name'),
            __('reports.students_count'),
            __('reports.total_amount'),
        ];

        $rows = [];
        foreach ($this->discountsData as $d) {
            $rows[] = [
                $d->name,
                (int) $d->students_count,
                ExcelExport::money((int) $d->total_amount_minor),
            ];
        }

        return ExcelExport::download('discounts_report', __('reports.discounts'), $meta, $columns, $rows, $isAr);
    }

    private function exportForgoneExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('fees.school'),
            __('fees.grade'),
            __('reports.students_count'),
            __('reports.forgone'),
        ];

        $rows = [];
        foreach ($this->forgoneData as $f) {
            $rows[] = [
                __('schools.'.$f->school_code),
                __('schools.grade_number', ['number' => $f->grade_number]),
                (int) $f->students_count,
                ExcelExport::money((int) $f->forgone_minor),
            ];
        }

        return ExcelExport::download('forgone_by_leavers', __('reports.forgone_by_leavers'), $meta, $columns, $rows, $isAr);
    }

    private function exportRefundsExcel(array $meta, bool $isAr): mixed
    {
        $columns = [
            __('payments.paid_on'),
            __('students.student_number'),
            __('students.name'),
            __('fees.grade'),
            __('reports.fee_type'),
            __('payments.method'),
            __('payments.amount'),
            __('reports.note'),
        ];

        $rows = [];
        foreach ($this->refundsData as $r) {
            $rows[] = [
                $r->refunded_on?->format('Y-m-d') ?? '',
                $r->enrollment->student->student_number,
                $r->enrollment->student->name,
                __('schools.'.$r->enrollment->grade->school->code).' '.__('schools.grade_number', ['number' => $r->enrollment->grade->number]),
                $r->enrollmentFee->gradeFee->feeType->isTuition() ? __('fees.tuition') : $r->enrollmentFee->gradeFee->feeType->name,
                $r->method->value === 'cash' ? __('payments.cash') : __('payments.bank_transfer'),
                ExcelExport::money($r->amount_minor),
                $r->note,
            ];
        }

        return ExcelExport::download('refunds_report', __('reports.refunds'), $meta, $columns, $rows, $isAr);
    }
};
?>

<div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-ui.page-header :title="__('reports.title')" />
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="exportExcel" :spinner="false">
                <x-ui.icon name="document-text" class="h-4 w-4 me-1" />
                {{ __('reports.export_excel') }}
            </x-ui.button>
            <a href="{{ route('reports.print', [
                'tab' => $tab,
                'academic_year_id' => $applied_academic_year_id,
                'school_id' => $applied_school_id,
                'grade_id' => $applied_grade_id,
                'statuses' => $applied_statuses,
                'date_from' => $applied_date_from,
                'date_to' => $applied_date_to,
                'student_id' => $applied_student_id,
            ]) }}" target="_blank" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-xs hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ __('reports.print') }}
            </a>
            <a href="{{ route('reports.print', [
                'tab' => $tab,
                'academic_year_id' => $applied_academic_year_id,
                'school_id' => $applied_school_id,
                'grade_id' => $applied_grade_id,
                'statuses' => $applied_statuses,
                'date_from' => $applied_date_from,
                'date_to' => $applied_date_to,
                'student_id' => $applied_student_id,
                'landscape' => 1,
            ]) }}" target="_blank" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-xs hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ __('reports.print_landscape') }}
            </a>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-gray-200 text-sm font-medium text-gray-500 dark:border-gray-700 dark:text-gray-400">
        <ul class="flex flex-wrap -mb-px gap-2">
            @php
                $tabs = [
                    'year_summary' => __('reports.year_summary'),
                    'tuition_vs_other' => __('reports.tuition_vs_other'),
                    'call_list' => __('reports.call_list'),
                    'collections' => __('reports.collections'),
                    'discounts' => __('reports.discounts'),
                    'forgone_by_leavers' => __('reports.forgone_by_leavers'),
                    'refunds' => __('reports.refunds'),
                    'student_statement' => __('reports.student_statement'),
                ];
            @endphp
            @foreach($tabs as $key => $label)
                <li>
                    <button type="button" wire:click="setTab('{{ $key }}')" class="inline-block p-3 rounded-t-lg border-b-2 cursor-pointer transition {{ $tab === $key ? 'border-indigo-600 text-indigo-600 dark:border-indigo-500 dark:text-indigo-400 font-semibold' : 'border-transparent hover:border-gray-300 hover:text-gray-600 dark:hover:text-gray-300' }}">
                        {{ $label }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Filters Card --}}
    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if($tab !== 'student_statement')
                <x-ui.field :label="__('fees.academic_year')" for="report-year">
                    <x-ui.select id="report-year" wire:model.live="academic_year_id">
                        <option value="">{{ __('reports.all_statuses') }}</option>
                        @foreach($this->years as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('fees.school')" for="report-school">
                    <x-ui.select id="report-school" wire:model.live="school_id">
                        <option value="">{{ __('reports.all_schools') }}</option>
                        @foreach($this->schools as $school)
                            <option value="{{ $school->id }}">{{ __('schools.'.$school->code) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('fees.grade')" for="report-grade">
                    <x-ui.select id="report-grade" wire:model.live="grade_id" :disabled="$school_id === null">
                        <option value="">{{ __('reports.all_grades') }}</option>
                        @foreach($this->grades as $grade)
                            <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif

            @if(in_array($tab, ['year_summary', 'tuition_vs_other', 'call_list', 'collections', 'discounts', 'forgone_by_leavers', 'refunds'], true))
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('reports.status_filter') }}</label>
                    <div class="mt-1 flex flex-wrap gap-2 text-xs">
                        <button type="button" wire:click="setPreset('all')" class="cursor-pointer text-indigo-600 hover:underline dark:text-indigo-400">{{ __('reports.all_statuses') }}</button>
                        <span>·</span>
                        <button type="button" wire:click="setPreset('charged')" class="cursor-pointer text-indigo-600 hover:underline dark:text-indigo-400">{{ __('reports.preset_charged') }}</button>
                        <span>·</span>
                        <button type="button" wire:click="setPreset('ended')" class="cursor-pointer text-indigo-600 hover:underline dark:text-indigo-400">{{ __('reports.preset_ended') }}</button>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach(\App\Enums\EnrollmentStatus::cases() as $case)
                            <label class="inline-flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                                <input type="checkbox" wire:model.live="statuses" value="{{ $case->value }}" class="rounded border-gray-300 text-indigo-600 shadow-xs focus:ring-indigo-500">
                                {{ __('enrollments.status_'.$case->value) }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(in_array($tab, ['collections', 'refunds'], true))
                <x-ui.field :label="__('reports.date_from')" for="report-date-from">
                    <x-ui.input type="date" id="report-date-from" wire:model.live="date_from" />
                </x-ui.field>
                <x-ui.field :label="__('reports.date_to')" for="report-date-to">
                    <x-ui.input type="date" id="report-date-to" wire:model.live="date_to" />
                </x-ui.field>
            @endif

            @if($tab === 'student_statement')
                <div class="sm:col-span-2">
                    <x-ui.field :label="__('students.name')" for="report-student-search">
                        <x-ui.input type="search" id="report-student-search" wire:model.live.debounce.300ms="student_search" :placeholder="__('reports.student_search_placeholder')" />
                    </x-ui.field>
                    @if(trim($student_search) !== '')
                        @php
                            $searchResults = \App\Models\Student::query()->search($student_search)->limit(10)->get();
                        @endphp
                        <ul class="mt-2 max-h-40 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800">
                            @forelse($searchResults as $s)
                                <li class="border-b border-gray-100 last:border-none dark:border-gray-700">
                                    <button type="button" wire:click="$set('student_id', {{ $s->id }})" class="w-full text-start px-3 py-1.5 text-xs hover:bg-indigo-50 dark:hover:bg-gray-700">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $s->name }}</span>
                                        <span class="text-gray-500" dir="ltr">({{ $s->student_number }})</span>
                                    </button>
                                </li>
                            @empty
                                <li class="p-2 text-xs text-gray-500">{{ __('common.no_results') }}</li>
                            @endforelse
                        </ul>
                    @endif
                </div>
            @endif
        </div>
        <div class="mt-4 flex justify-end border-t border-gray-200 pt-4 dark:border-gray-700">
            <x-ui.button variant="primary" wire:click="applyFilters" loading-target="applyFilters" :spinner="false">
                {{ __('reports.apply') }}
            </x-ui.button>
        </div>
    </x-ui.card>

    {{-- Main Report Output Area --}}
    @if(! $applied)
        <x-ui.empty-state :title="__('common.no_results')" :description="__('enrollments.select_filters')" />
    @else
        <x-ui.card>
            {{-- Tab 1: Year Summary --}}
            @if($tab === 'year_summary')
                @php $summary = $this->yearSummaryData; @endphp
                @if($summary['grades']->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('fees.school') }}</th>
                                <th>{{ __('fees.grade') }}</th>
                                <th>{{ __('reports.enrollments_count') }}</th>
                                <th>{{ __('reports.grand_total') }}</th>
                                <th>{{ __('reports.current') }}</th>
                                <th>{{ __('reports.remaining') }}</th>
                                <th>{{ __('reports.still_owed') }}</th>
                                <th>{{ __('reports.forgone') }}</th>
                                <th>{{ __('reports.percent_paid') }}</th>
                            </x-slot:head>
                            @foreach($summary['grades'] as $g)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td>{{ __('schools.'.$g['school_code']) }}</td>
                                    <td>{{ __('schools.grade_number', ['number' => $g['grade_number']]) }}</td>
                                    <td>
                                        <span class="font-medium">{{ $g['count'] }}</span>
                                        @if(empty($applied_statuses))
                                            <div class="mt-0.5 flex flex-wrap gap-1 text-[11px] text-gray-500">
                                                @foreach($g['by_status'] as $st => $cnt)
                                                    @if($cnt > 0)
                                                        <span>{{ __('enrollments.status_'.$st) }}: {{ $cnt }}</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td><x-ui.money :minor="$g['grand_total']" /></td>
                                    <td><x-ui.money :minor="$g['current']" /></td>
                                    <td><x-ui.money :minor="$g['remaining']" /></td>
                                    <td><x-ui.money :minor="$g['still_owed']" /></td>
                                    <td><x-ui.money :minor="$g['forgone']" /></td>
                                    <td>{{ $g['percent_paid'] !== null ? number_format($g['percent_paid'], 1).'%' : '—' }}</td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td colspan="2">{{ __('payments.total') }}</td>
                                    <td>
                                        <span>{{ $summary['totals']['count'] }}</span>
                                        @if(empty($applied_statuses))
                                            <div class="mt-0.5 flex flex-wrap gap-1 text-[11px] font-normal text-gray-600 dark:text-gray-400">
                                                @foreach($summary['totals']['by_status'] as $st => $cnt)
                                                    @if($cnt > 0)
                                                        <span>{{ __('enrollments.status_'.$st) }}: {{ $cnt }}</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td><x-ui.money :minor="$summary['totals']['grand_total']" /></td>
                                    <td><x-ui.money :minor="$summary['totals']['current']" /></td>
                                    <td><x-ui.money :minor="$summary['totals']['remaining']" /></td>
                                    <td><x-ui.money :minor="$summary['totals']['still_owed']" /></td>
                                    <td><x-ui.money :minor="$summary['totals']['forgone']" /></td>
                                    <td>{{ $summary['totals']['percent_paid'] !== null ? number_format($summary['totals']['percent_paid'], 1).'%' : '—' }}</td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 italic">{{ __('reports.footnote_year_summary') }}</p>
                @endif

            {{-- Tab 2: Tuition vs Other Fees --}}
            @elseif($tab === 'tuition_vs_other')
                @php $ftData = $this->feeTypeData; @endphp
                @if($ftData['fee_types']->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('reports.fee_type') }}</th>
                                <th>{{ __('reports.grand_total') }}</th>
                                <th>{{ __('reports.current') }}</th>
                                <th>{{ __('reports.remaining') }}</th>
                                <th>{{ __('reports.percent_paid') }}</th>
                            </x-slot:head>
                            @foreach($ftData['fee_types'] as $ft)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="font-medium">
                                        {{ $ft['is_tuition'] ? __('fees.tuition') : $ft['name'] }}
                                    </td>
                                    <td><x-ui.money :minor="$ft['grand_total']" /></td>
                                    <td><x-ui.money :minor="$ft['current']" /></td>
                                    <td><x-ui.money :minor="$ft['remaining']" /></td>
                                    <td>{{ $ft['percent_paid'] !== null ? number_format($ft['percent_paid'], 1).'%' : '—' }}</td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td>{{ __('payments.total') }}</td>
                                    <td><x-ui.money :minor="$ftData['totals']['grand_total']" /></td>
                                    <td><x-ui.money :minor="$ftData['totals']['current']" /></td>
                                    <td><x-ui.money :minor="$ftData['totals']['remaining']" /></td>
                                    <td>{{ $ftData['totals']['percent_paid'] !== null ? number_format($ftData['totals']['percent_paid'], 1).'%' : '—' }}</td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>
                @endif

            {{-- Tab 3: Outstanding Call List --}}
            @elseif($tab === 'call_list')
                @php $callList = $this->callListData; @endphp
                @if($callList->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('students.student_number') }}</th>
                                <th>{{ __('students.name') }}</th>
                                <th>{{ __('students.phone') }}</th>
                                <th>{{ __('students.secondary_phone') }}</th>
                                <th>{{ __('students.guardian_name') }}</th>
                                <th>{{ __('fees.grade') }}</th>
                                <th>{{ __('enrollments.status') }}</th>
                                <th>{{ __('reports.still_owed') }}</th>
                            </x-slot:head>
                            @foreach($callList as $item)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td dir="ltr" class="font-mono text-xs">{{ $item->student_number }}</td>
                                    <td>
                                        <a href="{{ route('enrollments.show', $item->enrollment_id) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                            {{ $item->student_name }}
                                        </a>
                                    </td>
                                    <td dir="ltr">{{ $item->phone }}</td>
                                    <td dir="ltr">{{ $item->secondary_phone ?? '—' }}</td>
                                    <td>{{ $item->guardian_name ?? '—' }}</td>
                                    <td>{{ __('schools.'.$item->school_code) }} {{ __('schools.grade_number', ['number' => $item->grade_number]) }}</td>
                                    <td><x-ui.badge :status="$item->status" /></td>
                                    <td class="font-bold text-red-600 dark:text-red-400"><x-ui.money :minor="(int) $item->owed_minor" /></td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td colspan="7">{{ __('payments.total') }} ({{ $callList->count() }} {{ __('students.students') }})</td>
                                    <td class="text-red-600 dark:text-red-400"><x-ui.money :minor="(int) $callList->sum('owed_minor')" /></td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>
                @endif

            {{-- Tab 4: Collections --}}
            @elseif($tab === 'collections')
                @php $coll = $this->collectionsData; @endphp
                @if($coll['days']->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('payments.paid_on') }}</th>
                                <th>{{ __('payments.cash') }}</th>
                                <th>{{ __('payments.bank_transfer') }}</th>
                                <th>{{ __('reports.transaction_numbers') }}</th>
                                <th>{{ __('reports.cash_net') }}</th>
                            </x-slot:head>
                            @foreach($coll['days'] as $day)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td dir="ltr" class="font-mono text-xs">{{ $day['date'] }}</td>
                                    <td><x-ui.money :minor="$day['cash']" /></td>
                                    <td><x-ui.money :minor="$day['bank']" /></td>
                                    <td dir="ltr" class="font-mono text-xs">{{ implode(', ', $day['txns']) ?: '—' }}</td>
                                    <td class="font-bold"><x-ui.money :minor="$day['net']" /></td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td>{{ __('payments.total') }}</td>
                                    <td><x-ui.money :minor="$coll['total_cash']" /></td>
                                    <td><x-ui.money :minor="$coll['total_bank']" /></td>
                                    <td></td>
                                    <td><x-ui.money :minor="$coll['net_total']" /></td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>

                    {{-- Audit section for voided transactions --}}
                    @if($coll['voided_payments']->isNotEmpty() || $coll['voided_refunds']->isNotEmpty())
                        <div class="mt-8 border-t border-gray-300 pt-5">
                            <h3 class="text-sm font-bold text-red-700 uppercase tracking-wider">{{ __('reports.audit_voided') }}</h3>
                            <div class="mt-3 space-y-2 text-xs">
                                @foreach($coll['voided_payments'] as $vp)
                                    <div class="flex items-center justify-between rounded border border-red-200 bg-red-50 p-2 dark:border-red-900/50 dark:bg-red-950/20">
                                        <div>
                                            <span class="font-mono font-bold">{{ $vp->reference() }}</span> ({{ __('payments.payment') }}) - {{ $vp->paid_on?->format('Y-m-d') }}
                                            <div class="text-gray-500">{{ __('reports.void_reason') }}: {{ $vp->void_reason }}</div>
                                        </div>
                                        <div class="line-through text-gray-500"><x-ui.money :minor="$vp->amount_minor" /></div>
                                    </div>
                                @endforeach
                                @foreach($coll['voided_refunds'] as $vr)
                                    <div class="flex items-center justify-between rounded border border-red-200 bg-red-50 p-2 dark:border-red-900/50 dark:bg-red-950/20">
                                        <div>
                                            <span class="font-mono font-bold">REF-{{ sprintf('%06d', $vr->id) }}</span> ({{ __('reports.refunds') }}) - {{ $vr->refunded_on?->format('Y-m-d') }}
                                            <div class="text-gray-500">{{ __('reports.void_reason') }}: {{ $vr->void_reason }}</div>
                                        </div>
                                        <div class="line-through text-gray-500"><x-ui.money :minor="$vr->amount_minor" /></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

            {{-- Tab 5: Discounts --}}
            @elseif($tab === 'discounts')
                @php $discounts = $this->discountsData; @endphp
                @if($discounts->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('reports.discount_name') }}</th>
                                <th>{{ __('reports.students_count') }}</th>
                                <th>{{ __('reports.total_amount') }}</th>
                            </x-slot:head>
                            @foreach($discounts as $d)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="font-medium">{{ $d->name }}</td>
                                    <td>{{ $d->students_count }}</td>
                                    <td><x-ui.money :minor="(int) $d->total_amount_minor" /></td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td>{{ __('payments.total') }}</td>
                                    <td>{{ $discounts->sum('students_count') }}</td>
                                    <td><x-ui.money :minor="(int) $discounts->sum('total_amount_minor')" /></td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>
                @endif

            {{-- Tab 6: Forgone by Leavers --}}
            @elseif($tab === 'forgone_by_leavers')
                @php $forgone = $this->forgoneData; @endphp
                @if($forgone->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('fees.school') }}</th>
                                <th>{{ __('fees.grade') }}</th>
                                <th>{{ __('reports.students_count') }}</th>
                                <th>{{ __('reports.forgone') }}</th>
                            </x-slot:head>
                            @foreach($forgone as $f)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td>{{ __('schools.'.$f->school_code) }}</td>
                                    <td>{{ __('schools.grade_number', ['number' => $f->grade_number]) }}</td>
                                    <td>{{ $f->students_count }}</td>
                                    <td><x-ui.money :minor="(int) $f->forgone_minor" /></td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td colspan="2">{{ __('payments.total') }}</td>
                                    <td>{{ $forgone->sum('students_count') }}</td>
                                    <td><x-ui.money :minor="(int) $forgone->sum('forgone_minor')" /></td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>
                @endif

            {{-- Tab 7: Refunds --}}
            @elseif($tab === 'refunds')
                @php $refunds = $this->refundsData; @endphp
                @if($refunds->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <th>{{ __('payments.paid_on') }}</th>
                                <th>{{ __('students.student_number') }}</th>
                                <th>{{ __('students.name') }}</th>
                                <th>{{ __('fees.grade') }}</th>
                                <th>{{ __('reports.fee_type') }}</th>
                                <th>{{ __('payments.method') }}</th>
                                <th>{{ __('payments.amount') }}</th>
                                <th>{{ __('reports.note') }}</th>
                            </x-slot:head>
                            @foreach($refunds as $r)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td dir="ltr" class="font-mono text-xs">{{ $r->refunded_on?->format('Y-m-d') }}</td>
                                    <td dir="ltr" class="font-mono text-xs">{{ $r->enrollment->student->student_number }}</td>
                                    <td class="font-medium">{{ $r->enrollment->student->name }}</td>
                                    <td>{{ __('schools.'.$r->enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $r->enrollment->grade->number]) }}</td>
                                    <td>{{ $r->enrollmentFee->gradeFee->feeType->isTuition() ? __('fees.tuition') : $r->enrollmentFee->gradeFee->feeType->name }}</td>
                                    <td>{{ $r->method->value === 'cash' ? __('payments.cash') : __('payments.bank_transfer') }}</td>
                                    <td class="font-bold text-red-600 dark:text-red-400"><x-ui.money :minor="$r->amount_minor" /></td>
                                    <td class="text-xs text-gray-500">{{ $r->note }}</td>
                                </tr>
                            @endforeach
                            <x-slot:foot>
                                <tr class="font-bold bg-gray-100 dark:bg-gray-800">
                                    <td colspan="6">{{ __('payments.total') }}</td>
                                    <td class="text-red-600 dark:text-red-400"><x-ui.money :minor="(int) $refunds->sum('amount_minor')" /></td>
                                    <td></td>
                                </tr>
                            </x-slot:foot>
                        </x-ui.table>
                    </div>
                @endif

            {{-- Tab 8: Student Account Statement --}}
            @elseif($tab === 'student_statement')
                @php $stmt = $this->studentStatementData; @endphp
                @if(! $stmt)
                    <x-ui.empty-state :title="__('reports.select_student')" />
                @else
                    @php $student = $stmt['student']; @endphp
                    <div class="space-y-6">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                            <div>
                                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ $student->name }}</h2>
                                <p class="text-xs text-gray-500" dir="ltr">{{ __('students.student_number') }}: {{ $student->student_number }}</p>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                <div>{{ __('students.phone') }}: <span dir="ltr">{{ $student->phone }}</span></div>
                                @if($student->guardian_name)
                                    <div>{{ __('students.guardian_name') }}: {{ $student->guardian_name }}</div>
                                @endif
                            </div>
                        </div>

                        @forelse($student->enrollments as $enr)
                            @php $bal = \App\Support\EnrollmentBalance::forEnrollment($enr); @endphp
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
                                    <div class="font-bold text-sm text-gray-900 dark:text-white">
                                        {{ $enr->academicYear->name }} · {{ __('schools.'.$enr->grade->school->code) }} {{ __('schools.grade_number', ['number' => $enr->grade->number]) }}
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge :status="$enr->status->value" />
                                        <span class="text-xs font-semibold">{{ __('reports.still_owed') }}: <x-ui.money :minor="$bal['owed']" /></span>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="text-gray-500 border-b border-gray-100 dark:border-gray-800">
                                                <th class="py-1 text-start">{{ __('fees.fee_types') }}</th>
                                                <th class="py-1 text-end">{{ __('reports.amount') }}</th>
                                                <th class="py-1 text-end">{{ __('fees.discount') }}</th>
                                                <th class="py-1 text-end">{{ __('payments.paid') }}</th>
                                                <th class="py-1 text-end">{{ __('reports.refunds') }}</th>
                                                <th class="py-1 text-end">{{ __('reports.remaining') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($bal['fees'] as $fId => $fData)
                                                @php
                                                    $fModel = $enr->fees->firstWhere('id', $fId);
                                                    $ft = $fModel?->gradeFee?->feeType;
                                                @endphp
                                                <tr class="border-b border-gray-50 dark:border-gray-800/50">
                                                    <td class="py-1.5">{{ $ft?->isTuition() ? __('fees.tuition') : $ft?->name }}</td>
                                                    <td class="py-1.5 text-end"><x-ui.money :minor="$fData['charged']" /></td>
                                                    <td class="py-1.5 text-end"><x-ui.money :minor="$fData['discount']" /></td>
                                                    <td class="py-1.5 text-end"><x-ui.money :minor="$fData['paid']" /></td>
                                                    <td class="py-1.5 text-end"><x-ui.money :minor="$fData['refunded']" /></td>
                                                    <td class="py-1.5 text-end font-medium"><x-ui.money :minor="$fData['remaining']" /></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="font-bold border-t border-gray-200 dark:border-gray-700">
                                                <td class="py-1.5">{{ __('payments.total') }}</td>
                                                <td class="py-1.5 text-end"><x-ui.money :minor="$bal['charged']" /></td>
                                                <td class="py-1.5 text-end"><x-ui.money :minor="$bal['discount']" /></td>
                                                <td class="py-1.5 text-end"><x-ui.money :minor="$bal['paid']" /></td>
                                                <td class="py-1.5 text-end"><x-ui.money :minor="$bal['refunded']" /></td>
                                                <td class="py-1.5 text-end"><x-ui.money :minor="$bal['charged'] - $bal['discount'] - $bal['net_paid']" /></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">{{ __('reports.no_records') }}</p>
                        @endforelse
                    </div>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
