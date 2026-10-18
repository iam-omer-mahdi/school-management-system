<?php

use App\Livewire\Forms\StudentForm;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use Illuminate\Validation\Rule;
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

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'school_id' => __('students.school'),
            'grade_id' => __('students.grade'),
            'academic_year_id' => __('students.academic_year'),
        ];
    }

    protected function validateEnrollSelection(): void
    {
        if (! $this->enrollNow) {
            return;
        }

        $this->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'grade_id' => ['required', Rule::exists('grades', 'id')->where('school_id', $this->school_id)],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
        ]);
    }

    public function updatedSchoolId(): void
    {
        $this->grade_id = null;
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

<div class="space-y-6">
    <x-ui.page-header :title="__('students.create')" :backUrl="route('students.index')" />

    <x-ui.card>
        <form wire:submit="save" class="space-y-8">
            <fieldset>
                <legend class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('students.section_basic') }}</legend>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('students.section_basic_hint') }}</p>
                <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                    <x-ui.field :label="__('students.name')" for="name" required :error="$errors->first('form.name')">
                        <x-ui.input id="name" type="text" wire:model.blur="form.name" :error="$errors->first('form.name')" required />
                    </x-ui.field>
                    <x-ui.field :label="__('students.date_of_birth')" for="date_of_birth" :error="$errors->first('form.date_of_birth')">
                        <x-ui.input id="date_of_birth" type="date" wire:model.blur="form.date_of_birth" :error="$errors->first('form.date_of_birth')" :min="now()->subYears(\App\Livewire\Forms\StudentForm::MAX_AGE_YEARS)->toDateString()" :max="now()->subYears(\App\Livewire\Forms\StudentForm::MIN_AGE_YEARS)->toDateString()" />
                    </x-ui.field>
                    <x-ui.field :label="__('students.mother_name')" for="mother_name" :error="$errors->first('form.mother_name')">
                        <x-ui.input id="mother_name" type="text" wire:model.blur="form.mother_name" :error="$errors->first('form.mother_name')" />
                    </x-ui.field>
                    <x-ui.field :label="__('students.blood_type')" for="blood_type" :error="$errors->first('form.blood_type')">
                        <x-ui.select id="blood_type" wire:model.blur="form.blood_type" :error="$errors->first('form.blood_type')">
                            <option value="">—</option>
                            @foreach(\App\Livewire\Forms\StudentForm::BLOOD_TYPES as $bloodType)
                                <option value="{{ $bloodType }}">{{ $bloodType }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </fieldset>

            <fieldset class="border-t border-gray-200 pt-7 dark:border-gray-700">
                <legend class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('students.section_contact') }}</legend>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('students.section_contact_hint') }}</p>
                <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                    <x-ui.field :label="__('students.phone')" for="phone" required :error="$errors->first('form.phone')">
                        <x-ui.input id="phone" type="tel" wire:model.blur="form.phone" :error="$errors->first('form.phone')" required />
                    </x-ui.field>
                    <x-ui.field :label="__('students.secondary_phone')" for="secondary_phone" :error="$errors->first('form.secondary_phone')">
                        <x-ui.input id="secondary_phone" type="tel" wire:model.blur="form.secondary_phone" :error="$errors->first('form.secondary_phone')" />
                    </x-ui.field>
                    <x-ui.field :label="__('students.home_address')" for="home_address" :error="$errors->first('form.home_address')" class="sm:col-span-2">
                        <x-ui.input id="home_address" type="text" wire:model.blur="form.home_address" :error="$errors->first('form.home_address')" />
                    </x-ui.field>
                </div>
            </fieldset>

            <fieldset class="border-t border-gray-200 pt-7 dark:border-gray-700">
                <legend class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('students.section_guardian') }}</legend>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('students.section_guardian_hint') }}</p>
                <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                    <x-ui.field :label="__('students.guardian_name')" for="guardian_name" :error="$errors->first('form.guardian_name')">
                        <x-ui.input id="guardian_name" type="text" wire:model.blur="form.guardian_name" :error="$errors->first('form.guardian_name')" />
                    </x-ui.field>
                    <x-ui.field :label="__('students.guardian_relationship')" for="guardian_relationship" :error="$errors->first('form.guardian_relationship')">
                        <x-ui.input id="guardian_relationship" type="text" wire:model.blur="form.guardian_relationship" :error="$errors->first('form.guardian_relationship')" />
                    </x-ui.field>
                </div>
            </fieldset>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 sm:p-6 dark:border-gray-600 dark:bg-gray-700/40">
                <x-ui.checkbox wire:model.live="enrollNow" :label="__('students.enroll_now')" />

                @if($enrollNow)
                    <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-3">
                        <x-ui.field :label="__('students.school')" for="school_id" required :error="$errors->first('school_id')">
                            <x-ui.select id="school_id" wire:model.live="school_id" :error="$errors->first('school_id')">
                                <option value="">—</option>
                                @foreach($this->schools as $school)
                                    <option value="{{ $school->id }}">{{ __('schools.'.$school->code) }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field :label="__('students.grade')" for="grade_id" required :error="$errors->first('grade_id')">
                            <x-ui.select id="grade_id" wire:model.live="grade_id" :error="$errors->first('grade_id')">
                                <option value="">—</option>
                                @foreach($this->grades as $grade)
                                    <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field :label="__('students.academic_year')" for="academic_year_id" required :error="$errors->first('academic_year_id')">
                            <x-ui.select id="academic_year_id" wire:model.live="academic_year_id" :error="$errors->first('academic_year_id')">
                                <option value="">—</option>
                                @foreach($this->years as $year)
                                    <option value="{{ $year->id }}">{{ $year->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 sm:flex-row sm:justify-end dark:border-gray-700">
                <x-ui.button href="{{ route('students.index') }}" wire:navigate variant="secondary" :spinner="false" class="w-full sm:w-auto">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled" class="w-full sm:w-auto">{{ __('common.save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
