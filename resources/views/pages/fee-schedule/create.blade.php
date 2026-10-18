<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use App\Actions\CreateGradeFee;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public ?int $school_id = null;

    public ?int $grade_id = null;

    public ?int $academic_year_id = null;

    public ?int $fee_type_id = null;

    public string $amount = '';

    public function mount(): void
    {
        Gate::authorize(Permission::FeesManage->value);
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
            return new \Illuminate\Database\Eloquent\Collection();
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

    /** @return \Illuminate\Database\Eloquent\Collection<int, FeeType> */
    #[Computed]
    public function feeTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return FeeType::query()->select(['id', 'name', 'system_key', 'is_mandatory'])->orderBy('name')->get();
    }

    /** @return array{applied: int, skipped: int} */
    #[Computed]
    public function preview(): array
    {
        if ($this->grade_id === null || $this->academic_year_id === null) {
            return ['applied' => 0, 'skipped' => 0];
        }

        $counts = Enrollment::query()
            ->where('grade_id', $this->grade_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->selectRaw('status, COUNT(*) AS enrollments_count')
            ->groupBy('status')
            ->pluck('enrollments_count', 'status');

        $applied = 0;
        $skipped = 0;

        foreach ($counts as $status => $count) {
            if (EnrollmentStatus::tryFrom((string) $status)?->isCharged()) {
                $applied += (int) $count;
            } else {
                $skipped += (int) $count;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    #[Computed]
    public function parsedAmount(): ?int
    {
        try {
            return Money::fromDecimal($this->amount);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'school_id' => __('fees.school'),
            'grade_id' => __('fees.grade'),
            'academic_year_id' => __('fees.academic_year'),
            'fee_type_id' => __('fees.fee_type'),
            'amount' => __('fees.amount'),
        ];
    }

    public function updatedSchoolId(): void
    {
        $this->grade_id = null;
    }

    public function save(CreateGradeFee $action): void
    {
        $this->validate([
            'grade_id' => ['required', Rule::exists('grades', 'id')->where('school_id', $this->school_id)],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'fee_type_id' => ['required', 'exists:fee_types,id'],
            'amount' => ['required', 'string'],
        ]);

        if ($this->parsedAmount === null || $this->parsedAmount <= 0) {
            $this->addError('amount', __('validation.numeric', ['attribute' => __('fees.amount')]));

            return;
        }

        $result = $action->handle([
            'grade_id' => (int) $this->grade_id,
            'academic_year_id' => (int) $this->academic_year_id,
            'fee_type_id' => (int) $this->fee_type_id,
            'amount' => $this->amount,
        ]);

        session()->flash('status', __('fees.applied_skipped', ['applied' => $result->applied, 'skipped' => $result->skipped]));

        $this->redirectRoute('fee-schedule.index', [
            'school_id' => $this->school_id,
            'grade_id' => $this->grade_id,
            'academic_year_id' => $this->academic_year_id,
        ], navigate: true);
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('fees.schedule_create')" :backUrl="route('fee-schedule.index')" />

    <x-ui.card>
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('fees.school')" for="set-fee-school" :error="$errors->first('school_id')">
                    <x-ui.select id="set-fee-school" wire:model.live="school_id" :error="$errors->first('school_id')">
                        <option value="">—</option>
                        @foreach($this->schools as $school)
                            <option value="{{ $school->id }}">{{ $school->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('fees.grade')" for="set-fee-grade" required :error="$errors->first('grade_id')">
                    <x-ui.select id="set-fee-grade" wire:model.live="grade_id" :error="$errors->first('grade_id')">
                        <option value="">—</option>
                        @foreach($this->grades as $grade)
                            <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('fees.academic_year')" for="set-fee-year" required :error="$errors->first('academic_year_id')">
                <x-ui.select id="set-fee-year" wire:model.live="academic_year_id" :error="$errors->first('academic_year_id')">
                    <option value="">—</option>
                    @foreach($this->years as $year)
                        <option value="{{ $year->id }}">{{ $year->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('fees.fee_type')" for="set-fee-type" required :error="$errors->first('fee_type_id')">
                    <x-ui.select id="set-fee-type" wire:model.live="fee_type_id" :error="$errors->first('fee_type_id')">
                        <option value="">—</option>
                        @foreach($this->feeTypes as $type)
                            <option value="{{ $type->id }}">@if($type->isTuition()){{ __('fees.tuition') }}@else{{ $type->name }}@endif</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('fees.amount')" for="set-fee-amount" required :error="$errors->first('amount')">
                    <x-ui.input id="set-fee-amount" type="text" wire:model.blur="amount" :error="$errors->first('amount')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" data-money />
                </x-ui.field>
            </div>
            @if($this->parsedAmount)
                <p class="text-2xl font-bold text-gray-900 dark:text-white"><x-ui.money :minor="$this->parsedAmount" /></p>
            @endif
            @if($grade_id !== null && $academic_year_id !== null)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('fees.will_apply', ['applied' => $this->preview['applied'], 'skipped' => $this->preview['skipped']]) }}</p>
            @endif
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                <x-ui.button href="{{ route('fee-schedule.index') }}" wire:navigate variant="secondary" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
