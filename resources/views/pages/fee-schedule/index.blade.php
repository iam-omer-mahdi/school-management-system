<?php

use App\Actions\CreateGradeFee;
use App\Actions\DeleteGradeFee;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Support\Money;
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

    public bool $showForm = false;

    public bool $showConfirm = false;

    public ?int $deletingId = null;

    /** @return \Illuminate\Database\Eloquent\Collection<int, School> */
    #[Computed]
    public function schools(): \Illuminate\Database\Eloquent\Collection
    {
        return School::query()->select(['id', 'code', 'name'])->orderBy('id')->get();
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
        return FeeType::query()->select(['id', 'name', 'is_mandatory'])->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, GradeFee> */
    #[Computed]
    public function schedule(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->grade_id === null || $this->academic_year_id === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return GradeFee::query()
            ->where('grade_id', $this->grade_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->with('feeType:id,name,is_mandatory,system_key')
            ->orderBy('id')
            ->get();
    }

    /** @return array{applied: int, skipped: int} */
    #[Computed]
    public function preview(): array
    {
        if ($this->grade_id === null || $this->academic_year_id === null) {
            return ['applied' => 0, 'skipped' => 0];
        }

        $applied = 0;
        $skipped = 0;

        foreach (Enrollment::query()->where('grade_id', $this->grade_id)->where('academic_year_id', $this->academic_year_id)->get(['status']) as $enrollment) {
            if ($enrollment->isCharged()) {
                $applied++;
            } else {
                $skipped++;
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

    public function save(): void
    {
        $this->validate([
            'grade_id' => ['required', 'exists:grades,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'fee_type_id' => ['required', 'exists:fee_types,id'],
            'amount' => ['required', 'string'],
        ]);

        if ($this->parsedAmount === null || $this->parsedAmount <= 0) {
            $this->addError('amount', __('validation.numeric', ['attribute' => __('fees.amount')]));

            return;
        }

        $this->showConfirm = true;
    }

    public function confirmSave(CreateGradeFee $action): void
    {
        $result = $action->handle([
            'grade_id' => (int) $this->grade_id,
            'academic_year_id' => (int) $this->academic_year_id,
            'fee_type_id' => (int) $this->fee_type_id,
            'amount' => $this->amount,
        ]);

        $this->showConfirm = false;
        $this->showForm = false;
        $this->reset(['fee_type_id', 'amount']);
        unset($this->schedule, $this->preview, $this->parsedAmount);
        session()->flash('status', __('fees.applied_skipped', ['applied' => $result->applied, 'skipped' => $result->skipped]));
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function delete(DeleteGradeFee $action): void
    {
        $action->handle((int) $this->deletingId);

        $this->deletingId = null;
        unset($this->schedule);
        session()->flash('status', __('fees.schedule_deleted'));
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('fees.schedule')">
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm" wire:click="$set('showForm', true)" :spinner="false">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('fees.schedule_create') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.field :label="__('fees.school')" for="sched-school">
                <x-ui.select id="sched-school" wire:model.live="school_id">
                    <option value="">—</option>
                    @foreach($this->schools as $school)
                        <option value="{{ $school->id }}">{{ __('schools.'.$school->code) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('fees.grade')" for="sched-grade" required :error="$errors->first('grade_id')">
                <x-ui.select id="sched-grade" wire:model.live="grade_id" :error="$errors->first('grade_id')">
                    <option value="">—</option>
                    @foreach($this->grades as $grade)
                        <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('fees.academic_year')" for="sched-year" required :error="$errors->first('academic_year_id')">
                <x-ui.select id="sched-year" wire:model.live="academic_year_id" :error="$errors->first('academic_year_id')">
                    <option value="">—</option>
                    @foreach($this->years as $year)
                        <option value="{{ $year->id }}">{{ $year->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
    </x-ui.card>

    @if($showForm)
        <x-ui.card :title="__('fees.schedule_create')">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('fees.fee_type')" for="sched-type" required :error="$errors->first('fee_type_id')">
                        <x-ui.select id="sched-type" wire:model.live="fee_type_id" :error="$errors->first('fee_type_id')">
                            <option value="">—</option>
                            @foreach($this->feeTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('fees.amount')" for="sched-amount" required :error="$errors->first('amount')">
                        <x-ui.input id="sched-amount" type="text" wire:model.blur="amount" :error="$errors->first('amount')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" />
                    </x-ui.field>
                </div>
                @if($this->parsedAmount)
                    <p class="text-sm text-gray-600 dark:text-gray-400"><x-ui.money :minor="$this->parsedAmount" /></p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($grade_id === null || $academic_year_id === null)
        <x-ui.empty-state :title="__('common.no_results')" :description="__('fees.select_grade_year')" />
    @elseif($this->schedule->isEmpty())
        <x-ui.empty-state :title="__('common.no_results')" :description="__('fees.no_fees_set')" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('fees.fee_type') }}</th>
                <th>{{ __('fees.amount') }}</th>
                <th>{{ __('fees.is_mandatory') }}</th>
                <th>{{ __('common.actions') }}</th>
            </x-slot:head>
            @foreach($this->schedule as $fee)
                <tr wire:key="fee-{{ $fee->id }}">
                    <td class="font-medium text-gray-900 dark:text-white">{{ $fee->feeType->name }}</td>
                    <td><x-ui.money :minor="$fee->amount_minor" /></td>
                    <td>
                        @if($fee->feeType->is_mandatory)
                            <x-ui.badge color="blue">{{ __('fees.mandatory') }}</x-ui.badge>
                        @else
                            <x-ui.badge color="gray">{{ __('fees.optional') }}</x-ui.badge>
                        @endif
                    </td>
                    <td>
                        <x-ui.button variant="ghost" size="sm" wire:click="confirmDelete({{ $fee->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    @if($showConfirm)
        <x-ui.modal :title="__('fees.confirm_amount')" close="$set('showConfirm', false)">
            <p class="text-2xl font-bold text-gray-900 dark:text-white"><x-ui.money :minor="$this->parsedAmount ?? 0" /></p>
            <p class="mt-2">{{ __('fees.will_apply', ['applied' => $this->preview['applied'], 'skipped' => $this->preview['skipped']]) }}</p>
            @if($errors->first('amount') || $errors->first('fee_type_id'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('amount') ?? $errors->first('fee_type_id') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showConfirm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="confirmSave" loading-target="confirmSave" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($deletingId)
        <x-ui.modal :title="__('common.confirm_title')" close="$set('deletingId', null)">
            <p>{{ __('fees.confirm_delete_schedule') }}</p>
            @if($errors->first('grade_fee'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('grade_fee') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete" loading-target="delete" wire:loading.attr="disabled">{{ __('common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
