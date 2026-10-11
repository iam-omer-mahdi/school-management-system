<?php

use App\Livewire\Forms\StudentForm;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public StudentForm $form;

    public bool $enrollNow = false;

    public ?int $school_id = null;

    public ?int $grade_id = null;

    public ?int $academic_year_id = null;

    public function save(): void
    {
        $this->validateEnrollSelection();

        $student = $this->form->store(
            app(\App\Actions\CreateStudent::class),
            $this->enrollNow ? $this->academic_year_id : null,
            $this->enrollNow ? $this->grade_id : null,
        );

        $this->redirectRoute('students.show', ['student' => $student->id], navigate: true);
    }

    protected function validateEnrollSelection(): void
    {
        if (! $this->enrollNow) {
            return;
        }

        $this->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'grade_id' => ['required', 'exists:grades,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
        ]);
    }

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
};
?>

<div class="space-y-4">
    <div class="flex items-center gap-2">
        <a href="{{ route('students.index') }}" wire:navigate class="text-sm text-blue-700 hover:underline">{{ __('common.back') }}</a>
        <h1 class="text-xl font-semibold">{{ __('students.create') }}</h1>
    </div>

    <form wire:submit="save" class="space-y-4 rounded bg-white p-4 shadow">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-field :label="__('students.name')" :error="$errors->first('form.name')">
                <input type="text" wire:model.blur="form.name" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.phone')" :error="$errors->first('form.phone')">
                <input type="text" wire:model.blur="form.phone" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.secondary_phone')" :error="$errors->first('form.secondary_phone')">
                <input type="text" wire:model.blur="form.secondary_phone" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.mother_name')" :error="$errors->first('form.mother_name')">
                <input type="text" wire:model.blur="form.mother_name" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.home_address')" :error="$errors->first('form.home_address')">
                <input type="text" wire:model.blur="form.home_address" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.blood_type')" :error="$errors->first('form.blood_type')">
                <input type="text" wire:model.blur="form.blood_type" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.date_of_birth')" :error="$errors->first('form.date_of_birth')">
                <input type="date" wire:model.blur="form.date_of_birth" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.guardian_name')" :error="$errors->first('form.guardian_name')">
                <input type="text" wire:model.blur="form.guardian_name" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <x-field :label="__('students.guardian_relationship')" :error="$errors->first('form.guardian_relationship')">
                <input type="text" wire:model.blur="form.guardian_relationship" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
        </div>

        <label class="flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" wire:model.live="enrollNow" />
            {{ __('students.enroll_now') }}
        </label>

        @if($enrollNow)
            <div class="grid grid-cols-1 gap-3 rounded border p-3 sm:grid-cols-3">
                <x-field :label="__('students.school')" :error="$errors->first('school_id')">
                    <select wire:model.live="school_id" class="w-full rounded border-gray-300 px-3 py-2">
                        <option value="">—</option>
                        @foreach($this->schools as $school)
                            <option value="{{ $school->id }}">{{ __('schools.'.$school->code) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('students.grade')" :error="$errors->first('grade_id')">
                    <select wire:model.live="grade_id" class="w-full rounded border-gray-300 px-3 py-2">
                        <option value="">—</option>
                        @foreach($this->grades as $grade)
                            <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('students.academic_year')" :error="$errors->first('academic_year_id')">
                    <select wire:model.live="academic_year_id" class="w-full rounded border-gray-300 px-3 py-2">
                        <option value="">—</option>
                        @foreach($this->years as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
        @endif

        <button type="submit" wire:loading.attr="disabled" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">
            <span wire:loading.remove>{{ __('common.save') }}</span>
            <span wire:loading>{{ __('common.loading') }}</span>
        </button>
    </form>
</div>
