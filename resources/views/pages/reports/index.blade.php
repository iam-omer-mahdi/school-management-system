<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Enums\EnrollmentStatus;
use App\Enums\Permission;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Support\Reports\ExcelExport;
use App\Support\Reports\FinancialSummary;
use App\Support\Reports\ReportExport;
use App\Support\Reports\ReportQueries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] class extends Component
{
    use WithPagination;

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
    public ?int $fee_type_id = null;

    #[Url]
    public string $date_from = '';

    #[Url]
    public string $date_to = '';

    #[Url]
    public ?int $student_id = null;

    #[Url]
    public string $call_sort = 'owed_desc';

    public string $student_search = '';

    public ?int $applied_academic_year_id = null;

    public ?int $applied_school_id = null;

    public ?int $applied_grade_id = null;

    /** @var array<string> */
    public array $applied_statuses = [];

    public ?int $applied_fee_type_id = null;

    public string $applied_date_from = '';

    public string $applied_date_to = '';

    public ?int $applied_student_id = null;

    public bool $applied = false;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);

        $this->academic_year_id ??= AcademicYear::query()->orderByDesc('starts_on')->value('id');
        $this->applyFilters();
    }

    public function applyFilters(): void
    {
        $this->applied_academic_year_id = $this->academic_year_id;
        $this->applied_school_id = $this->school_id;
        $this->applied_grade_id = $this->grade_id;
        $this->applied_statuses = array_values(array_intersect(
            $this->statuses,
            array_map(fn (EnrollmentStatus $s): string => $s->value, EnrollmentStatus::cases())
        ));
        $this->applied_fee_type_id = $this->fee_type_id;
        $this->applied_date_from = $this->validDate($this->date_from);
        $this->applied_date_to = $this->validDate($this->date_to);
        $this->applied_student_id = $this->student_id;
        $this->applied = true;
        $this->resetPage('callPage');
        $this->resetPage('refundsPage');
    }

    public function setPreset(string $preset): void
    {
        $this->statuses = match ($preset) {
            'charged' => FinancialSummary::CHARGED,
            'ended' => FinancialSummary::ENDED,
            default => [],
        };
    }

    public function updatedSchoolId(): void
    {
        $this->grade_id = null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function setCallSort(string $column): void
    {
        $current = $this->validCallSort($this->call_sort);

        $this->call_sort = match ($column) {
            'name' => $current === 'name_asc' ? 'name_desc' : 'name_asc',
            default => $current === 'owed_asc' ? 'owed_desc' : 'owed_asc',
        };
        $this->resetPage('callPage');
    }

    private function validCallSort(string $value): string
    {
        return in_array($value, ['owed_desc', 'owed_asc', 'name_asc', 'name_desc'], true)
            ? $value
            : 'owed_desc';
    }

    #[Computed]
    public function callSort(): string
    {
        return $this->validCallSort($this->call_sort);
    }

    public function pickStudent(int $id): void
    {
        $this->student_id = $id;
        $this->student_search = (string) Student::query()->whereKey($id)->value('name');
    }

    private function validDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /** @return array{academic_year_id: int|null, school_id: int|null, grade_id: int|null, statuses: array<string>} */
    public function reportFilters(): array
    {
        return [
            'academic_year_id' => $this->applied_academic_year_id,
            'school_id' => $this->applied_school_id,
            'grade_id' => $this->applied_grade_id,
            'statuses' => $this->applied_statuses,
        ];
    }

    /** @return array{academic_year_id: int|null, school_id: int|null, grade_id: int|null, statuses: array<string>, fee_type_id: int|null, date_from: string, date_to: string} */
    private function datedFilters(): array
    {
        return array_merge($this->reportFilters(), [
            'fee_type_id' => $this->applied_fee_type_id,
            'date_from' => $this->applied_date_from !== '' ? $this->applied_date_from : null,
            'date_to' => $this->applied_date_to !== '' ? $this->applied_date_to : null,
        ]);
    }

    #[Computed]
    public function schools(): Collection
    {
        return School::query()->select(['id', 'name'])->orderBy('id')->get();
    }

    #[Computed]
    public function grades(): Collection
    {
        if ($this->school_id === null) {
            return new Collection();
        }

        return Grade::query()->select(['id', 'number'])->where('school_id', $this->school_id)->orderBy('number')->get();
    }

    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderByDesc('starts_on')->get();
    }

    #[Computed]
    public function feeTypes(): Collection
    {
        return FeeType::query()->select(['id', 'name'])->orderBy('name')->get();
    }

    #[Computed]
    public function statusLabel(): string
    {
        if ($this->applied_statuses === []) {
            return __('reports.all_statuses');
        }

        return implode(', ', array_map(fn (string $s): string => __('enrollments.status_'.$s), $this->applied_statuses));
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
    public function callListPage(): mixed
    {
        return ReportQueries::callListQuery($this->reportFilters(), $this->callSort)->paginate(20, pageName: 'callPage');
    }

    #[Computed]
    public function callListTotals(): array
    {
        return ReportQueries::callListTotals($this->reportFilters());
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
    public function refundsPage(): mixed
    {
        return ReportQueries::refundsQuery($this->datedFilters())->paginate(15, pageName: 'refundsPage');
    }

    #[Computed]
    public function refundsTotals(): array
    {
        return ReportQueries::refundsTotals($this->datedFilters());
    }

    #[Computed]
    public function studentMatches(): Collection
    {
        if (trim($this->student_search) === '') {
            return new Collection();
        }

        return Student::query()->search($this->student_search)->limit(10)->get(['id', 'student_number', 'name']);
    }

    #[Computed]
    public function statementStudent(): ?Student
    {
        if ($this->applied_student_id === null) {
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
        ])->find($this->applied_student_id);
    }

    public function exportExcel(): mixed
    {
        $isAr = app()->getLocale() === 'ar';
        $meta = [
            __('reports.title') => __('reports.'.$this->tab),
            __('fees.academic_year') => $this->applied_academic_year_id
                ? (string) AcademicYear::query()->whereKey($this->applied_academic_year_id)->value('name') : '',
            __('fees.school') => $this->applied_school_id
                ? (string) School::query()->whereKey($this->applied_school_id)->value('name') : __('reports.all_schools'),
            __('reports.status_filter') => $this->statusLabel,
            __('reports.generated_at') => now()->format('Y-m-d H:i'),
        ];

        if ($this->tab === 'student_statement' && $this->statementStudent !== null) {
            $meta[__('students.name')] = $this->statementStudent->name;
        }

        [$file, $title, $built] = match ($this->tab) {
            'tuition_vs_other' => ['tuition_vs_other', __('reports.tuition_vs_other'), ReportExport::feeTypes($this->feeTypeData)],
            'call_list' => ['outstanding_call_list', __('reports.call_list'), ReportExport::callList(ReportQueries::callListQuery($this->reportFilters(), $this->callSort)->cursor())],
            'collections' => ['collections', __('reports.collections'), ReportExport::collections($this->collectionsData)],
            'discounts' => ['discounts', __('reports.discounts'), ReportExport::discounts($this->discountsData)],
            'forgone_by_leavers' => ['forgone_by_leavers', __('reports.forgone_by_leavers'), ReportExport::forgone($this->forgoneDetails)],
            'refunds' => ['refunds', __('reports.refunds'), ReportExport::refunds(ReportQueries::refundsQuery($this->datedFilters())->cursor())],
            'student_statement' => $this->statementExport(),
            default => ['year_summary', __('reports.year_summary'), ReportExport::yearSummary($this->yearSummaryData)],
        };

        if ($built === null) {
            return null;
        }

        return ExcelExport::download($file, $title, $meta, $built['columns'], $built['rows'], $built['formats'], $isAr);
    }

    /** @return array{0: string, 1: string, 2: array|null} */
    private function statementExport(): array
    {
        $student = $this->statementStudent;

        if ($student === null) {
            return ['', '', null];
        }

        $balances = [];

        foreach ($student->enrollments as $enr) {
            $balances[$enr->id] = \App\Support\EnrollmentBalance::forEnrollment($enr);
        }

        return ['student_statement', __('reports.student_statement'), ReportExport::statement($student, $balances)];
    }

    /** @return array<string, mixed> */
    public function printParams(bool $landscape = false): array
    {
        return array_filter([
            'tab' => $this->tab,
            'academic_year_id' => $this->applied_academic_year_id,
            'school_id' => $this->applied_school_id,
            'grade_id' => $this->applied_grade_id,
            'statuses' => $this->applied_statuses,
            'fee_type_id' => $this->applied_fee_type_id,
            'date_from' => $this->applied_date_from !== '' ? $this->applied_date_from : null,
            'date_to' => $this->applied_date_to !== '' ? $this->applied_date_to : null,
            'student_id' => $this->applied_student_id,
            'call_sort' => $this->tab === 'call_list' && $this->callSort !== 'owed_desc' ? $this->call_sort : null,
            'landscape' => $landscape ? 1 : null,
        ]);
    }
};
?>

<div class="space-y-8 lg:space-y-10">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-ui.page-header :title="__('reports.title')" />
        <div class="no-print flex flex-wrap items-center gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="exportExcel">{{ __('reports.export_excel') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="route('reports.print', $this->printParams())" target="_blank">{{ __('reports.print') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="route('reports.print', $this->printParams(true))" target="_blank">{{ __('reports.print_landscape') }}</x-ui.button>
        </div>
    </div>

    <div class="no-print border-b border-line text-sm font-medium">
        <ul class="flex flex-wrap gap-x-2">
            @foreach(['year_summary' => __('reports.year_summary'), 'tuition_vs_other' => __('reports.tuition_vs_other'), 'call_list' => __('reports.call_list'), 'collections' => __('reports.collections'), 'discounts' => __('reports.discounts'), 'forgone_by_leavers' => __('reports.forgone_by_leavers'), 'refunds' => __('reports.refunds'), 'student_statement' => __('reports.student_statement')] as $key => $label)
                <li><button type="button" wire:click="setTab('{{ $key }}')" class="inline-flex min-h-11 cursor-pointer items-center border-b-2 px-3 py-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus {{ $tab === $key ? 'border-tab-active font-semibold text-ink' : 'border-transparent text-muted hover:border-line-strong hover:text-ink' }}">{{ $label }}</button></li>
            @endforeach
        </ul>
    </div>

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if($tab !== 'student_statement')
                <x-ui.field :label="__('fees.academic_year')">
                    <x-ui.select wire:model.live="academic_year_id">
                        @foreach($this->years as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('fees.school')">
                    <x-ui.select wire:model.live="school_id">
                        <option value="">{{ __('reports.all_schools') }}</option>
                        @foreach($this->schools as $school)
                            <option value="{{ $school->id }}">{{ $school->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('fees.grade')">
                    <x-ui.select wire:model.live="grade_id" :disabled="$school_id === null">
                        <option value="">{{ __('reports.all_grades') }}</option>
                        @foreach($this->grades as $grade)
                            <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif
            @if(in_array($tab, ['year_summary', 'tuition_vs_other', 'call_list', 'collections', 'discounts', 'forgone_by_leavers', 'refunds'], true))
                <div class="sm:col-span-2 lg:col-span-4">
                    <span class="block text-sm font-medium text-muted ">{{ __('reports.status_filter') }}</span>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <button type="button" wire:click="setPreset('all')" class="inline-flex min-h-11 cursor-pointer items-center text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ __('reports.all_statuses') }}</button>
                        <span aria-hidden="true">·</span>
                        <button type="button" wire:click="setPreset('charged')" class="inline-flex min-h-11 cursor-pointer items-center text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ __('reports.preset_charged') }}</button>
                        <span aria-hidden="true">·</span>
                        <button type="button" wire:click="setPreset('ended')" class="inline-flex min-h-11 cursor-pointer items-center text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ __('reports.preset_ended') }}</button>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-6 gap-y-3">
                        @foreach(\App\Enums\EnrollmentStatus::cases() as $case)
                            <label class="inline-flex min-h-11 items-center gap-2 text-xs text-muted">
                                <input type="checkbox" wire:model.live="statuses" value="{{ $case->value }}" class="h-5 w-5 rounded-xs border-line-strong accent-primary focus:ring-2 focus:ring-focus">
                                {{ __('enrollments.status_'.$case->value) }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            @if(in_array($tab, ['collections', 'refunds'], true))
                <x-ui.field :label="__('reports.date_from')"><x-ui.input type="date" wire:model.live="date_from" /></x-ui.field>
                <x-ui.field :label="__('reports.date_to')"><x-ui.input type="date" wire:model.live="date_to" /></x-ui.field>
            @endif
            @if($tab === 'refunds')
                <x-ui.field :label="__('reports.fee_type')">
                    <x-ui.select wire:model.live="fee_type_id">
                        <option value="">{{ __('reports.all_fee_types') }}</option>
                        @foreach($this->feeTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif
            @if($tab === 'student_statement')
                <div class="sm:col-span-2">
                    <x-ui.field :label="__('students.name')">
                        <x-ui.input type="search" wire:model.live.debounce.300ms="student_search" :placeholder="__('reports.student_search_placeholder')" />
                    </x-ui.field>
                    @if($this->studentMatches->isNotEmpty())
                        <ul class="mt-2 max-h-40 overflow-y-auto rounded-sm border border-line bg-surface ">
                            @foreach($this->studentMatches as $match)
                                <li class="border-b border-line last:border-none ">
                                    <button type="button" wire:click="pickStudent({{ $match->id }})" class="flex min-h-11 w-full cursor-pointer items-center gap-1 px-3 py-2 text-start text-xs hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                        <span class="font-medium text-ink">{{ $match->name }}</span>
                                        <span class="text-muted" dir="ltr">({{ $match->student_number }})</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
        <div class="no-print mt-4 flex justify-end border-t border-line pt-4 ">
            <x-ui.button variant="primary" size="sm" wire:click="applyFilters">{{ __('reports.apply') }}</x-ui.button>
        </div>
    </x-ui.card>

    @if(! $applied)
        <x-ui.empty-state :title="__('common.no_results')" :description="__('enrollments.select_filters')" />
    @else
        <x-ui.card>
            @if($tab === 'year_summary')
                @php($summary = $this->yearSummaryData)
                @if($summary['grades']->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._year_summary', ['summary' => $summary, 'showStatus' => $applied_statuses === []])
                @endif
            @elseif($tab === 'tuition_vs_other')
                @php($feeTypes = $this->feeTypeData)
                @if($feeTypes['fee_types']->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._fee_types', ['feeTypes' => $feeTypes])
                @endif
            @elseif($tab === 'call_list')
                @php($page = $this->callListPage)
                @php($totals = $this->callListTotals)
                @if($totals['count'] === 0)
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._call_list', ['items' => $page->items(), 'totalOwed' => $totals['owed'], 'totalCount' => $totals['count'], 'linkNames' => true, 'sort' => $this->callSort])
                    <div class="no-print mt-4">{{ $page->links() }}</div>
                @endif
            @elseif($tab === 'collections')
                @php($coll = $this->collectionsData)
                @if($coll['days']->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._collections', ['collections' => $coll, 'showAudit' => true])
                @endif
            @elseif($tab === 'discounts')
                @php($discounts = $this->discountsData)
                @if($discounts->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._discounts', ['discounts' => $discounts])
                @endif
            @elseif($tab === 'forgone_by_leavers')
                @php($forgone = $this->forgoneData)
                @php($forgoneDetails = $this->forgoneDetails)
                @if($forgone->isEmpty())
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._forgone', ['summary' => $forgone, 'details' => $forgoneDetails, 'linkNames' => true])
                @endif
            @elseif($tab === 'refunds')
                @php($refunds = $this->refundsPage)
                @php($refTotals = $this->refundsTotals)
                @if($refTotals['count'] === 0)
                    <x-ui.empty-state :title="__('reports.no_records')" />
                @else
                    @include('pages.reports.partials._refunds', ['refunds' => $refunds->items(), 'linkNames' => true])
                    <div class="no-print mt-4">{{ $refunds->links() }}</div>
                @endif
            @elseif($tab === 'student_statement')
                @php($student = $this->statementStudent)
                @if($student === null)
                    <x-ui.empty-state :title="__('reports.select_student')" />
                @else
                    @include('pages.reports.partials._statement', ['student' => $student])
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
