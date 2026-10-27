<?php

use App\Enums\EnrollmentStatus;
use App\Enums\Permission;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
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
    public ?int $academic_year_id = null;

    #[Url]
    public ?int $school_id = null;

    #[Url]
    public ?int $grade_id = null;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = '';

    public ?int $applied_school_id = null;

    public ?int $applied_grade_id = null;

    public ?int $applied_academic_year_id = null;

    public bool $applied = false;

    public function mount(): void
    {
        Gate::authorize(Permission::EnrollmentsView->value);
    }

    public function applyFilters(): void
    {
        if ($this->school_id === null || $this->grade_id === null || $this->academic_year_id === null) {
            return;
        }

        $this->applied_school_id = $this->school_id;
        $this->applied_grade_id = $this->grade_id;
        $this->applied_academic_year_id = $this->academic_year_id;
        $this->applied = true;
        $this->resetPage();
        unset($this->enrollments, $this->breakdown);
    }

    public function updatedSchoolId(): void
    {
        $this->reset(['grade_id']);
        unset($this->grades);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Enrollment> */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Enrollment::query();

        $query->where('academic_year_id', $this->applied_academic_year_id);
        $query->where('grade_id', $this->applied_grade_id);

        if ($this->status !== '' && EnrollmentStatus::tryFrom($this->status) !== null) {
            $query->where('status', $this->status);
        }

        if (trim($this->search) !== '') {
            $query->whereHas('student', fn ($students) => $students->search($this->search));
        }

        return $query;
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Enrollment> */
    #[Computed]
    public function enrollments(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        if (! $this->applied) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15, 1);
        }

        $query = $this->baseQuery()
            ->with([
                'student:id,student_number,name,archived_at',
                'grade:id,school_id,number',
                'grade.school:id,name',
                'academicYear:id,name',
                'fees:id,enrollment_id,grade_fee_id,amount_minor',
                'fees.gradeFee:id,fee_type_id',
                'fees.gradeFee.feeType:id,system_key',
                'fees.payments' => fn ($payments) => $payments->whereNull('voided_at')->select(['id', 'enrollment_id', 'enrollment_fee_id', 'amount_minor', 'voided_at']),
                'discount:enrollment_id,amount_minor',
                'refunds' => fn ($refunds) => $refunds->whereNull('voided_at')->select(['id', 'enrollment_id', 'enrollment_fee_id', 'amount_minor', 'voided_at']),
            ]);

        match ($this->sort) {
            'name_asc' => $query->orderBy(Student::select('name')->whereColumn('students.id', 'enrollments.student_id'), 'asc'),
            'name_desc' => $query->orderBy(Student::select('name')->whereColumn('students.id', 'enrollments.student_id'), 'desc'),
            'total_asc', 'total_desc' => $query
                ->withSum('fees as fees_total', 'amount_minor')
                ->withSum('discount as discount_total', 'amount_minor')
                ->withSum(['payments as paid_total' => fn ($payments) => $payments->whereNull('voided_at')], 'amount_minor')
                ->withSum(['refunds as refunded_total' => fn ($refunds) => $refunds->whereNull('voided_at')], 'amount_minor')
                ->orderByRaw("CASE WHEN status IN ('active', 'suspended') THEN COALESCE(fees_total, 0) - COALESCE(discount_total, 0) - (COALESCE(paid_total, 0) - COALESCE(refunded_total, 0)) ELSE 0 END ".($this->sort === 'total_asc' ? 'asc' : 'desc')),
            default => $query->orderByDesc('id'),
        };

        return $query->paginate(15);
    }

    /** @return array<string, int> */
    #[Computed]
    public function breakdown(): array
    {
        $result = [];

        foreach (EnrollmentStatus::cases() as $case) {
            $result[$case->value] = 0;
        }

        if (! $this->applied) {
            return $result;
        }

        $counts = $this->baseQuery()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        foreach (EnrollmentStatus::cases() as $case) {
            $result[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $result;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, School> */
    #[Computed]
    public function schools(): \Illuminate\Database\Eloquent\Collection
    {
        return School::query()->select(['id', 'name'])->orderBy('id')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Grade> */
    #[Computed]
    public function grades(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->school_id === null) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return Grade::query()
            ->select(['id', 'school_id', 'number'])
            ->where('school_id', $this->school_id)
            ->orderBy('number')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AcademicYear> */
    #[Computed]
    public function years(): \Illuminate\Database\Eloquent\Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderBy('name')->get();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('enrollments.title')" />

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.field :label="__('fees.school')" for="enrollments-school">
                <x-ui.select id="enrollments-school" wire:model.live="school_id">
                    <option value="">—</option>
                    @foreach($this->schools as $school)
                        <option value="{{ $school->id }}">{{ $school->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('fees.grade')" for="enrollments-grade">
                <x-ui.select id="enrollments-grade" wire:model.live="grade_id" :disabled="$school_id === null">
                    <option value="">—</option>
                    @foreach($this->grades as $grade)
                        <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('fees.academic_year')" for="enrollments-year">
                <x-ui.select id="enrollments-year" wire:model.live="academic_year_id">
                    <option value="">—</option>
                    @foreach($this->years as $year)
                        <option value="{{ $year->id }}">{{ $year->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="mt-4 flex flex-col-reverse gap-3 border-t border-line pt-4 sm:flex-row sm:justify-end ">
            <x-ui.button variant="primary" wire:click="applyFilters" loading-target="applyFilters" wire:loading.attr="disabled" :spinner="false" :disabled="$school_id === null || $grade_id === null || $academic_year_id === null">{{ __('enrollments.apply') }}</x-ui.button>
        </div>
    </x-ui.card>

    @if(! $applied)
        <x-ui.empty-state :title="__('common.no_results')" :description="__('enrollments.select_filters')" />
    @else
    <x-ui.card>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <x-ui.field :label="__('enrollments.status')" for="enrollments-status" class="sm:w-52 sm:shrink-0">
                <x-ui.select id="enrollments-status" wire:model.live="status">
                    <option value="">{{ __('enrollments.filter_all') }}</option>
                    @foreach(\App\Enums\EnrollmentStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ __('enrollments.status_'.$case->value) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('enrollments.sort')" for="enrollments-sort" class="sm:w-56 sm:shrink-0">
                <x-ui.select id="enrollments-sort" wire:model.live="sort">
                    <option value="">{{ __('enrollments.sort_default') }}</option>
                    <option value="name_asc">{{ __('enrollments.sort_name_asc') }}</option>
                    <option value="name_desc">{{ __('enrollments.sort_name_desc') }}</option>
                    @can(\App\Enums\Permission::FeesView->value)
                        <option value="total_asc">{{ __('enrollments.sort_total_asc') }}</option>
                        <option value="total_desc">{{ __('enrollments.sort_total_desc') }}</option>
                    @endcan
                </x-ui.select>
            </x-ui.field>
            <div class="flex flex-wrap items-center gap-2 text-sm sm:ms-auto">
                <span class="font-medium text-ink ">{{ __('enrollments.total', ['count' => $this->enrollments->total()]) }}</span>
                @foreach($this->breakdown as $status => $count)
                    @if($count > 0)
                        <x-ui.badge :status="$status" />
                        <span class="text-muted ">{{ $count }}</span>
                    @endif
                @endforeach
            </div>
        </div>
        <div class="relative mt-4">
            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-subtle">
                <x-ui.icon name="search" class="h-4 w-4" />
            </span>
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('enrollments.search_placeholder')" aria-label="{{ __('enrollments.search_placeholder') }}" class="ps-9" />
        </div>
        <div class="mt-4 border-t border-line pt-4 ">
            @if($this->enrollments->isEmpty())
                <x-ui.empty-state :title="__('common.no_results')" />
            @else
                <x-ui.table>
                    <x-slot:head>
                        <th>{{ __('students.student_number') }}</th>
                        <th>{{ __('students.name') }}</th>
                        <th>{{ __('fees.school') }}</th>
                        <th>{{ __('fees.grade') }}</th>
                        <th>{{ __('fees.academic_year') }}</th>
                        <th>{{ __('enrollments.status') }}</th>
                        @can(\App\Enums\Permission::FeesView->value)
                            <th>{{ __('fees.total_due') }}</th>
                        @endcan
                    </x-slot:head>
            @foreach($this->enrollments as $enrollment)
                @php
                    $balance = \App\Support\EnrollmentBalance::forEnrollment($enrollment);
                @endphp
                        <tr wire:key="enrollment-{{ $enrollment->id }}" class="transition hover:bg-surface-raised ">
                            <td>{{ $enrollment->student->student_number }}</td>
                            <td>
                                <a href="{{ route('enrollments.show', $enrollment) }}" wire:navigate class="font-medium text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-focus ">{{ $enrollment->student->name }}</a>
                                @if($enrollment->student->archived_at !== null)
                                    <x-ui.badge color="gray">{{ __('students.archived') }}</x-ui.badge>
                                @endif
                            </td>
                            <td>{{ $enrollment->grade->school->name }}</td>
                            <td>{{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</td>
                            <td>{{ $enrollment->academicYear->name }}</td>
                    <td><x-ui.badge :status="$enrollment->status->value" /></td>
                    @can(\App\Enums\Permission::FeesView->value)
                        <td><x-ui.money :minor="$balance['owed']" /></td>
                    @endcan
                        </tr>
                    @endforeach
                </x-ui.table>
                <div class="mt-4">{{ $this->enrollments->links() }}</div>
            @endif
        </div>
    </x-ui.card>
    @endif
</div>
