<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\School;
use App\Models\Student;
use App\Support\Money;
use App\Support\Reports\FinancialSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::print')] class extends Component
{
    public string $tab = 'year_summary';
    public ?int $academic_year_id = null;
    public ?int $school_id = null;
    public ?int $grade_id = null;
    /** @var array<string> */
    public array $statuses = [];
    public string $date_from = '';
    public string $date_to = '';
    public ?int $student_id = null;
    public bool $landscape = false;

    public function mount(): void
    {
        $this->tab = (string) request()->query('tab', 'year_summary');
        $this->academic_year_id = request()->query('academic_year_id') ? (int) request()->query('academic_year_id') : null;
        $this->school_id = request()->query('school_id') ? (int) request()->query('school_id') : null;
        $this->grade_id = request()->query('grade_id') ? (int) request()->query('grade_id') : null;
        $statuses = request()->query('statuses', []);
        $this->statuses = is_array($statuses) ? $statuses : [];
        $this->date_from = (string) request()->query('date_from', '');
        $this->date_to = (string) request()->query('date_to', '');
        $this->student_id = request()->query('student_id') ? (int) request()->query('student_id') : null;
        $this->landscape = (bool) request()->query('landscape', false);
    }

    /** @return array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: array<string>} */
    public function currentFilters(): array
    {
        return [
            'academic_year_id' => $this->academic_year_id,
            'school_id' => $this->school_id,
            'grade_id' => $this->grade_id,
            'statuses' => $this->statuses,
        ];
    }

    #[Computed]
    public function currentYear(): ?AcademicYear
    {
        return $this->academic_year_id ? AcademicYear::query()->find($this->academic_year_id) : null;
    }

    #[Computed]
    public function currentSchool(): ?School
    {
        return $this->school_id ? School::query()->find($this->school_id) : null;
    }

    #[Computed]
    public function currentGrade(): ?Grade
    {
        return $this->grade_id ? Grade::query()->find($this->grade_id) : null;
    }

    #[Computed]
    public function statusFilterLabel(): string
    {
        if (empty($this->statuses)) {
            return __('reports.all_statuses');
        }

        return implode(', ', array_map(fn ($s) => __('enrollments.status_'.$s), $this->statuses));
    }

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

        $payQ = Payment::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at');

        if ($this->date_from !== '') {
            $payQ->where('paid_on', '>=', $this->date_from);
        }
        if ($this->date_to !== '') {
            $payQ->where('paid_on', '<=', $this->date_to);
        }

        $payments = $payQ->orderBy('paid_on')->get();

        $refQ = Refund::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at');

        if ($this->date_from !== '') {
            $refQ->where('refunded_on', '>=', $this->date_from);
        }
        if ($this->date_to !== '') {
            $refQ->where('refunded_on', '<=', $this->date_to);
        }

        $refunds = $refQ->orderBy('refunded_on')->get();

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

        return [
            'days' => collect(array_values($days)),
            'total_cash' => $totalCash,
            'total_bank' => $totalBank,
            'net_total' => $totalCash + $totalBank,
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

        return (clone $base)
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

        if ($this->date_from !== '') {
            $query->where('refunded_on', '>=', $this->date_from);
        }
        if ($this->date_to !== '') {
            $query->where('refunded_on', '<=', $this->date_to);
        }

        return $query->orderByDesc('refunded_on')->get();
    }

    #[Computed]
    public function studentStatementData(): ?array
    {
        if (! $this->student_id) {
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
            ->find($this->student_id);

        if (! $student) {
            return null;
        }

        return ['student' => $student];
    }
};
?>

@php
    $reportTitle = __('reports.'.$tab);
    $yearName = $this->currentYear?->name;
    $schoolName = $this->currentSchool ? __('schools.'.$this->currentSchool->code) : null;
    $gradeName = $this->currentGrade ? __('schools.grade_number', ['number' => $this->currentGrade->number]) : null;
@endphp

<div>
    <div class="print-actions mb-4 flex justify-end gap-2">
        <x-ui.button variant="primary" size="sm" onclick="window.print()" :spinner="false">
            {{ __('reports.print') }}
        </x-ui.button>
        <x-ui.button variant="secondary" size="sm" onclick="window.close()" :spinner="false">
            {{ __('common.back') }}
        </x-ui.button>
    </div>

    <x-reports.header
        :title="$reportTitle"
        :academicYear="$yearName"
        :school="$schoolName"
        :grade="$gradeName"
        :statusLabel="$this->statusFilterLabel"
    />

    {{-- Content per tab --}}
    @if($tab === 'year_summary')
        @php $summary = $this->yearSummaryData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('fees.school') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('fees.grade') }}</th>
                    <th class="p-2 border-e border-gray-300 text-center">{{ __('reports.enrollments_count') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.grand_total') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.current') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.remaining') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.still_owed') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.forgone') }}</th>
                    <th class="p-2 text-center">{{ __('reports.percent_paid') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary['grades'] as $g)
                    <tr class="border-b border-gray-200">
                        <td class="p-2 border-e border-gray-200">{{ __('schools.'.$g['school_code']) }}</td>
                        <td class="p-2 border-e border-gray-200">{{ __('schools.grade_number', ['number' => $g['grade_number']]) }}</td>
                        <td class="p-2 border-e border-gray-200 text-center">{{ $g['count'] }}</td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$g['grand_total']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$g['current']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$g['remaining']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$g['still_owed']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$g['forgone']" /></td>
                        <td class="p-2 text-center">{{ $g['percent_paid'] !== null ? number_format($g['percent_paid'], 1).'%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td colspan="2" class="p-2 border-e border-gray-300">{{ __('payments.total') }}</td>
                    <td class="p-2 border-e border-gray-300 text-center">{{ $summary['totals']['count'] }}</td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$summary['totals']['grand_total']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$summary['totals']['current']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$summary['totals']['remaining']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$summary['totals']['still_owed']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$summary['totals']['forgone']" /></td>
                    <td class="p-2 text-center">{{ $summary['totals']['percent_paid'] !== null ? number_format($summary['totals']['percent_paid'], 1).'%' : '—' }}</td>
                </tr>
            </tfoot>
        </table>
        <p class="mt-3 text-[11px] text-gray-500 italic">{{ __('reports.footnote_year_summary') }}</p>

    @elseif($tab === 'tuition_vs_other')
        @php $ftData = $this->feeTypeData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('reports.fee_type') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.grand_total') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.current') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('reports.remaining') }}</th>
                    <th class="p-2 text-center">{{ __('reports.percent_paid') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ftData['fee_types'] as $ft)
                    <tr class="border-b border-gray-200">
                        <td class="p-2 border-e border-gray-200 font-medium">{{ $ft['is_tuition'] ? __('fees.tuition') : $ft['name'] }}</td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$ft['grand_total']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$ft['current']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$ft['remaining']" /></td>
                        <td class="p-2 text-center">{{ $ft['percent_paid'] !== null ? number_format($ft['percent_paid'], 1).'%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td class="p-2 border-e border-gray-300">{{ __('payments.total') }}</td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$ftData['totals']['grand_total']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$ftData['totals']['current']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$ftData['totals']['remaining']" /></td>
                    <td class="p-2 text-center">{{ $ftData['totals']['percent_paid'] !== null ? number_format($ftData['totals']['percent_paid'], 1).'%' : '—' }}</td>
                </tr>
            </tfoot>
        </table>

    @elseif($tab === 'call_list')
        @php $callList = $this->callListData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('students.student_number') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('students.name') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('students.phone') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('students.secondary_phone') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('students.guardian_name') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('fees.grade') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('enrollments.status') }}</th>
                    <th class="p-2 text-end">{{ __('reports.still_owed') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($callList as $item)
                    <tr class="border-b border-gray-200">
                        <td dir="ltr" class="p-2 border-e border-gray-200 font-mono">{{ $item->student_number }}</td>
                        <td class="p-2 border-e border-gray-200 font-medium">{{ $item->student_name }}</td>
                        <td dir="ltr" class="p-2 border-e border-gray-200">{{ $item->phone }}</td>
                        <td dir="ltr" class="p-2 border-e border-gray-200">{{ $item->secondary_phone ?? '—' }}</td>
                        <td class="p-2 border-e border-gray-200">{{ $item->guardian_name ?? '—' }}</td>
                        <td class="p-2 border-e border-gray-200">{{ __('schools.'.$item->school_code) }} {{ __('schools.grade_number', ['number' => $item->grade_number]) }}</td>
                        <td class="p-2 border-e border-gray-200">{{ __('enrollments.status_'.$item->status) }}</td>
                        <td class="p-2 text-end font-bold"><x-ui.money :minor="(int) $item->owed_minor" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td colspan="7" class="p-2 border-e border-gray-300">{{ __('payments.total') }} ({{ $callList->count() }})</td>
                    <td class="p-2 text-end"><x-ui.money :minor="(int) $callList->sum('owed_minor')" /></td>
                </tr>
            </tfoot>
        </table>

    @elseif($tab === 'collections')
        @php $coll = $this->collectionsData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('payments.paid_on') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('payments.cash') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('payments.bank_transfer') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('reports.transaction_numbers') }}</th>
                    <th class="p-2 text-end">{{ __('reports.cash_net') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($coll['days'] as $day)
                    <tr class="border-b border-gray-200">
                        <td dir="ltr" class="p-2 border-e border-gray-200 font-mono">{{ $day['date'] }}</td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$day['cash']" /></td>
                        <td class="p-2 border-e border-gray-200 text-end"><x-ui.money :minor="$day['bank']" /></td>
                        <td dir="ltr" class="p-2 border-e border-gray-200 font-mono text-[11px]">{{ implode(', ', $day['txns']) ?: '—' }}</td>
                        <td class="p-2 text-end font-bold"><x-ui.money :minor="$day['net']" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td class="p-2 border-e border-gray-300">{{ __('payments.total') }}</td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$coll['total_cash']" /></td>
                    <td class="p-2 border-e border-gray-300 text-end"><x-ui.money :minor="$coll['total_bank']" /></td>
                    <td class="p-2 border-e border-gray-300"></td>
                    <td class="p-2 text-end"><x-ui.money :minor="$coll['net_total']" /></td>
                </tr>
            </tfoot>
        </table>

    @elseif($tab === 'discounts')
        @php $discounts = $this->discountsData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('reports.discount_name') }}</th>
                    <th class="p-2 border-e border-gray-300 text-center">{{ __('reports.students_count') }}</th>
                    <th class="p-2 text-end">{{ __('reports.total_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($discounts as $d)
                    <tr class="border-b border-gray-200">
                        <td class="p-2 border-e border-gray-200 font-medium">{{ $d->name }}</td>
                        <td class="p-2 border-e border-gray-200 text-center">{{ $d->students_count }}</td>
                        <td class="p-2 text-end"><x-ui.money :minor="(int) $d->total_amount_minor" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td class="p-2 border-e border-gray-300">{{ __('payments.total') }}</td>
                    <td class="p-2 border-e border-gray-300 text-center">{{ $discounts->sum('students_count') }}</td>
                    <td class="p-2 text-end"><x-ui.money :minor="(int) $discounts->sum('total_amount_minor')" /></td>
                </tr>
            </tfoot>
        </table>

    @elseif($tab === 'forgone_by_leavers')
        @php $forgone = $this->forgoneData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('fees.school') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('fees.grade') }}</th>
                    <th class="p-2 border-e border-gray-300 text-center">{{ __('reports.students_count') }}</th>
                    <th class="p-2 text-end">{{ __('reports.forgone') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($forgone as $f)
                    <tr class="border-b border-gray-200">
                        <td class="p-2 border-e border-gray-200">{{ __('schools.'.$f->school_code) }}</td>
                        <td class="p-2 border-e border-gray-200">{{ __('schools.grade_number', ['number' => $f->grade_number]) }}</td>
                        <td class="p-2 border-e border-gray-200 text-center">{{ $f->students_count }}</td>
                        <td class="p-2 text-end font-bold"><x-ui.money :minor="(int) $f->forgone_minor" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td colspan="2" class="p-2 border-e border-gray-300">{{ __('payments.total') }}</td>
                    <td class="p-2 border-e border-gray-300 text-center">{{ $forgone->sum('students_count') }}</td>
                    <td class="p-2 text-end"><x-ui.money :minor="(int) $forgone->sum('forgone_minor')" /></td>
                </tr>
            </tfoot>
        </table>

    @elseif($tab === 'refunds')
        @php $refunds = $this->refundsData; @endphp
        <table class="w-full text-xs text-left border-collapse border border-gray-300">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="p-2 border-e border-gray-300">{{ __('payments.paid_on') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('students.student_number') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('students.name') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('fees.grade') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('reports.fee_type') }}</th>
                    <th class="p-2 border-e border-gray-300">{{ __('payments.method') }}</th>
                    <th class="p-2 border-e border-gray-300 text-end">{{ __('payments.amount') }}</th>
                    <th class="p-2">{{ __('reports.note') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($refunds as $r)
                    <tr class="border-b border-gray-200">
                        <td dir="ltr" class="p-2 border-e border-gray-200 font-mono">{{ $r->refunded_on?->format('Y-m-d') }}</td>
                        <td dir="ltr" class="p-2 border-e border-gray-200 font-mono">{{ $r->enrollment->student->student_number }}</td>
                        <td class="p-2 border-e border-gray-200 font-medium">{{ $r->enrollment->student->name }}</td>
                        <td class="p-2 border-e border-gray-200">{{ __('schools.'.$r->enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $r->enrollment->grade->number]) }}</td>
                        <td class="p-2 border-e border-gray-200">{{ $r->enrollmentFee->gradeFee->feeType->isTuition() ? __('fees.tuition') : $r->enrollmentFee->gradeFee->feeType->name }}</td>
                        <td class="p-2 border-e border-gray-200">{{ $r->method->value === 'cash' ? __('payments.cash') : __('payments.bank_transfer') }}</td>
                        <td class="p-2 border-e border-gray-200 text-end font-bold text-red-600"><x-ui.money :minor="$r->amount_minor" /></td>
                        <td class="p-2 text-gray-500">{{ $r->note }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold bg-gray-100 border-t-2 border-gray-400">
                    <td colspan="6" class="p-2 border-e border-gray-300">{{ __('payments.total') }}</td>
                    <td class="p-2 border-e border-gray-300 text-end text-red-600"><x-ui.money :minor="(int) $refunds->sum('amount_minor')" /></td>
                    <td class="p-2"></td>
                </tr>
            </tfoot>
        </table>

    @elseif($tab === 'student_statement')
        @php $stmt = $this->studentStatementData; @endphp
        @if($stmt)
            @php $student = $stmt['student']; @endphp
            <div class="mb-4">
                <h2 class="text-base font-bold">{{ $student->name }}</h2>
                <div class="text-xs text-gray-600">{{ __('students.student_number') }}: <span dir="ltr">{{ $student->student_number }}</span> · {{ __('students.phone') }}: <span dir="ltr">{{ $student->phone }}</span></div>
            </div>

            @foreach($student->enrollments as $enr)
                @php $bal = \App\Support\EnrollmentBalance::forEnrollment($enr); @endphp
                <div class="mb-6 border border-gray-300 p-3 rounded">
                    <div class="flex justify-between text-xs font-bold border-b border-gray-200 pb-2 mb-2">
                        <span>{{ $enr->academicYear->name }} · {{ __('schools.'.$enr->grade->school->code) }} {{ __('schools.grade_number', ['number' => $enr->grade->number]) }} ({{ __('enrollments.status_'.$enr->status->value) }})</span>
                        <span>{{ __('reports.still_owed') }}: <x-ui.money :minor="$bal['owed']" /></span>
                    </div>
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-600">
                                <th class="text-start py-1">{{ __('fees.fee_types') }}</th>
                                <th class="text-end py-1">{{ __('reports.amount') }}</th>
                                <th class="text-end py-1">{{ __('fees.discount') }}</th>
                                <th class="text-end py-1">{{ __('payments.paid') }}</th>
                                <th class="text-end py-1">{{ __('reports.refunds') }}</th>
                                <th class="text-end py-1">{{ __('reports.remaining') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bal['fees'] as $fId => $fData)
                                @php
                                    $fModel = $enr->fees->firstWhere('id', $fId);
                                    $ft = $fModel?->gradeFee?->feeType;
                                @endphp
                                <tr class="border-b border-gray-100">
                                    <td class="py-1">{{ $ft?->isTuition() ? __('fees.tuition') : $ft?->name }}</td>
                                    <td class="py-1 text-end"><x-ui.money :minor="$fData['charged']" /></td>
                                    <td class="py-1 text-end"><x-ui.money :minor="$fData['discount']" /></td>
                                    <td class="py-1 text-end"><x-ui.money :minor="$fData['paid']" /></td>
                                    <td class="py-1 text-end"><x-ui.money :minor="$fData['refunded']" /></td>
                                    <td class="py-1 text-end"><x-ui.money :minor="$fData['remaining']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-bold border-t border-gray-300">
                                <td class="py-1">{{ __('payments.total') }}</td>
                                <td class="py-1 text-end"><x-ui.money :minor="$bal['charged']" /></td>
                                <td class="py-1 text-end"><x-ui.money :minor="$bal['discount']" /></td>
                                <td class="py-1 text-end"><x-ui.money :minor="$bal['paid']" /></td>
                                <td class="py-1 text-end"><x-ui.money :minor="$bal['refunded']" /></td>
                                <td class="py-1 text-end"><x-ui.money :minor="$bal['charged'] - $bal['discount'] - $bal['net_paid']" /></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endforeach
        @endif
    @endif

    <x-reports.footer />
</div>
