<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Enums\Permission;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Support\Reports\FinancialSummary;
use App\Support\Reports\ReportQueries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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

    public ?int $fee_type_id = null;

    public string $date_from = '';

    public string $date_to = '';

    public ?int $student_id = null;

    public string $call_sort = 'owed_desc';

    public bool $landscape = false;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);

        $this->tab = (string) request()->query('tab', 'year_summary');
        $academicYearId = request()->query('academic_year_id');
        $schoolId = request()->query('school_id');
        $gradeId = request()->query('grade_id');
        $feeTypeId = request()->query('fee_type_id');
        $studentId = request()->query('student_id');
        $statuses = request()->query('statuses', []);
        $this->academic_year_id = $academicYearId !== null ? (int) $academicYearId : null;
        $this->school_id = $schoolId !== null ? (int) $schoolId : null;
        $this->grade_id = $gradeId !== null ? (int) $gradeId : null;
        $this->statuses = is_array($statuses) ? array_values($statuses) : [];
        $this->fee_type_id = $feeTypeId !== null ? (int) $feeTypeId : null;
        $this->date_from = (string) request()->query('date_from', '');
        $this->date_to = (string) request()->query('date_to', '');
        $this->student_id = $studentId !== null ? (int) $studentId : null;
        $sort = (string) request()->query('call_sort', 'owed_desc');
        $this->call_sort = in_array($sort, ['owed_desc', 'owed_asc', 'name_asc', 'name_desc'], true) ? $sort : 'owed_desc';
        $this->landscape = (bool) request()->query('landscape', false);
    }

    /** @return array{academic_year_id: int|null, school_id: int|null, grade_id: int|null, statuses: array<string>} */
    public function reportFilters(): array
    {
        return [
            'academic_year_id' => $this->academic_year_id,
            'school_id' => $this->school_id,
            'grade_id' => $this->grade_id,
            'statuses' => $this->statuses,
        ];
    }

    /** @return array<string, mixed> */
    private function datedFilters(): array
    {
        return array_merge($this->reportFilters(), [
            'fee_type_id' => $this->fee_type_id,
            'date_from' => $this->date_from !== '' ? $this->date_from : null,
            'date_to' => $this->date_to !== '' ? $this->date_to : null,
        ]);
    }

    #[Computed]
    public function statusLabel(): string
    {
        if ($this->statuses === []) {
            return __('reports.all_statuses');
        }

        return implode(', ', array_map(fn (string $s): string => __('enrollments.status_'.$s), $this->statuses));
    }

    #[Computed]
    public function yearSummaryData(): array
    {
        return FinancialSummary::yearSummary($this->reportFilters());
    }

    #[Computed]
    public function feeTypeData(): array
    {
        return ReportQueries::byFeeType($this->reportFilters());
    }

    #[Computed]
    public function callListFull(): Collection
    {
        return ReportQueries::callListQuery($this->reportFilters(), $this->call_sort)->get();
    }

    #[Computed]
    public function collectionsData(): array
    {
        return ReportQueries::collections($this->datedFilters());
    }

    #[Computed]
    public function discountsData(): Collection
    {
        return ReportQueries::discounts($this->reportFilters());
    }

    #[Computed]
    public function forgoneData(): Collection
    {
        return ReportQueries::forgoneByGrade($this->reportFilters());
    }

    #[Computed]
    public function forgoneDetails(): Collection
    {
        return ReportQueries::forgoneListQuery($this->reportFilters());
    }

    #[Computed]
    public function refundsFull(): Collection
    {
        return ReportQueries::refundsQuery($this->datedFilters())->get();
    }

    #[Computed]
    public function statementStudent(): ?Student
    {
        if ($this->student_id === null) {
            return null;
        }

        return Student::query()->with([
            'enrollments' => fn ($e) => $e->orderBy('academic_year_id'),
            'enrollments.academicYear',
            'enrollments.grade.school',
            'enrollments.fees.gradeFee.feeType',
            'enrollments.fees.payments' => fn ($p) => $p->whereNull('voided_at'),
            'enrollments.discount',
            'enrollments.refunds' => fn ($r) => $r->whereNull('voided_at'),
        ])->find($this->student_id);
    }
};
?>

@php
    $yearName = $academic_year_id ? AcademicYear::query()->whereKey($academic_year_id)->value('name') : null;
    $schoolName = $school_id ? __('schools.'.((string) School::query()->whereKey($school_id)->value('name'))) : null;
    $gradeName = $grade_id ? __('schools.grade_number', ['number' => Grade::query()->whereKey($grade_id)->value('number')]) : null;
@endphp

<div>
    <div class="print-actions mb-4 flex justify-end gap-2">
        <x-ui.button variant="primary" size="sm" onclick="window.print()">{{ __('reports.print') }}</x-ui.button>
        <x-ui.button variant="secondary" size="sm" onclick="window.close()">{{ __('common.back') }}</x-ui.button>
    </div>

    <x-reports.header
        :title="__('reports.'.$tab)"
        :academicYear="$yearName"
        :school="$schoolName"
        :grade="$gradeName"
        :statusLabel="$this->statusLabel"
    />

    @if($tab === 'tuition_vs_other')
        @php($feeTypes = $this->feeTypeData)
        @if($feeTypes['fee_types']->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._fee_types', ['feeTypes' => $feeTypes])
        @endif
    @elseif($tab === 'call_list')
        @php($items = $this->callListFull)
        @if($items->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._call_list', ['items' => $items, 'totalOwed' => (int) $items->sum('owed_minor'), 'totalCount' => $items->count(), 'linkNames' => false, 'sort' => null])
        @endif
    @elseif($tab === 'collections')
        @php($coll = $this->collectionsData)
        @if($coll['days']->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._collections', ['collections' => $coll, 'showAudit' => true])
        @endif
    @elseif($tab === 'discounts')
        @php($discounts = $this->discountsData)
        @if($discounts->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._discounts', ['discounts' => $discounts])
        @endif
    @elseif($tab === 'forgone_by_leavers')
        @php($forgone = $this->forgoneData)
        @if($forgone->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._forgone', ['summary' => $forgone, 'details' => $this->forgoneDetails, 'linkNames' => false])
        @endif
    @elseif($tab === 'refunds')
        @php($refunds = $this->refundsFull)
        @if($refunds->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._refunds', ['refunds' => $refunds, 'linkNames' => false])
        @endif
    @elseif($tab === 'student_statement')
        @php($student = $this->statementStudent)
        @if($student === null)
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._statement', ['student' => $student])
        @endif
    @else
        @php($summary = $this->yearSummaryData)
        @if($summary['grades']->isEmpty())
            <p class="text-sm">{{ __('reports.no_records') }}</p>
        @else
            @include('pages.reports.partials._year_summary', ['summary' => $summary, 'showStatus' => $statuses === []])
        @endif
    @endif

    <x-reports.footer />
</div>
