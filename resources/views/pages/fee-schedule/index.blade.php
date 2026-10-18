<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use App\Actions\DeleteGradeFee;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Url]
    public ?int $school_id = null;

    #[Url]
    public ?int $grade_id = null;

    #[Url]
    public ?int $academic_year_id = null;

    public ?int $deletingId = null;

    public function mount(): void
    {
        Gate::authorize(Permission::FeesView->value);
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

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'school_id' => __('fees.school'),
            'grade_id' => __('fees.grade'),
            'academic_year_id' => __('fees.academic_year'),
        ];
    }

    public function updatedSchoolId(): void
    {
        $this->grade_id = null;
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
            @can(\App\Enums\Permission::FeesManage->value)
                <x-ui.button href="{{ route('fee-schedule.create') }}" wire:navigate variant="primary" size="sm" :spinner="false">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('fees.schedule_create') }}
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('fees.schedule') }}</h2>
                @if($grade_id !== null && $academic_year_id !== null && $this->schedule->isNotEmpty())
                    <x-ui.badge color="gray">{{ $this->schedule->count() }}</x-ui.badge>
                @endif
            </div>
        </x-slot:header>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.field :label="__('fees.school')" for="sched-school">
                <x-ui.select id="sched-school" wire:model.live="school_id">
                    <option value="">—</option>
                    @foreach($this->schools as $school)
                        <option value="{{ $school->id }}">{{ $school->name }}</option>
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
        <div class="mt-6 border-t border-gray-200 pt-6 dark:border-gray-700">
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
                            <td class="font-medium text-gray-900 dark:text-white">@if($fee->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $fee->feeType->name }}@endif</td>
                            <td><x-ui.money :minor="$fee->amount_minor" /></td>
                            <td>
                                @if($fee->feeType->is_mandatory)
                                    <x-ui.badge color="blue">{{ __('fees.mandatory') }}</x-ui.badge>
                                @else
                                    <x-ui.badge color="gray">{{ __('fees.optional') }}</x-ui.badge>
                                @endif
                            </td>
                            <td>
                                @can(\App\Enums\Permission::FeesManage->value)
                                    <x-ui.button variant="ghost" size="sm" wire:click="confirmDelete({{ $fee->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </x-ui.card>

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
