<?php

use App\Actions\EnrollStudent;
use App\Actions\UpdateStudentStatus;
use App\Enums\StudentStatus;
use App\Livewire\Forms\StudentForm;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Locked]
    public int $studentId;

    public StudentForm $form;

    public bool $editing = false;

    public bool $showEnroll = false;

    public ?int $school_id = null;

    public ?int $grade_id = null;

    public ?int $academic_year_id = null;

    public ?string $status_note = null;

    public function mount(Student $student): void
    {
        $this->studentId = $student->id;
        $this->form->fillFrom($student);
    }

    #[Computed]
    public function student(): Student
    {
        return Student::query()
            ->select(['id', 'student_number', 'name', 'phone', 'secondary_phone', 'home_address', 'mother_name', 'blood_type', 'date_of_birth', 'guardian_name', 'guardian_relationship', 'status', 'status_note', 'status_changed_at'])
            ->findOrFail($this->studentId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Enrollment> */
    #[Computed]
    public function enrollments(): \Illuminate\Database\Eloquent\Collection
    {
        return Enrollment::query()
            ->where('student_id', $this->studentId)
            ->with([
                'academicYear:id,name',
                'grade:id,school_id,number',
                'grade.school:id,code,name',
            ])
            ->orderByDesc('id')
            ->get();
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

    public function update(): void
    {
        $student = $this->student;
        $student->update($this->form->data());
        $this->editing = false;
        unset($this->student);
        session()->flash('status', __('students.updated'));
    }

    public function enroll(EnrollStudent $action): void
    {
        $this->validate([
            'grade_id' => ['required', 'exists:grades,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
        ]);

        $action->handle($this->studentId, (int) $this->academic_year_id, (int) $this->grade_id);

        $this->showEnroll = false;
        $this->reset(['school_id', 'grade_id', 'academic_year_id']);
        unset($this->enrollments);
        session()->flash('status', __('students.enrolled'));
    }

    public function changeStatus(UpdateStudentStatus $action, string $status): void
    {
        $newStatus = StudentStatus::from($status);
        $action->handle($this->student, $newStatus, $this->status_note);
        $this->status_note = null;
        unset($this->student);
        session()->flash('status', __('students.status_changed'));
    }
};
?>

<div class="space-y-4">
    <div class="flex items-center gap-2">
        <a href="{{ route('students.index') }}" wire:navigate class="text-sm text-blue-700 hover:underline">{{ __('common.back') }}</a>
        <h1 class="text-xl font-semibold">{{ $this->student->name }}</h1>
        <x-status-badge :status="$this->student->status->value" />
    </div>

    @if(session('status'))
        <p class="rounded bg-green-100 p-2 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    <div class="rounded bg-white p-4 shadow">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">{{ __('students.details') }}</h2>
            <button wire:click="$set('editing', true)" class="text-sm text-blue-700 hover:underline">{{ __('common.edit') }}</button>
        </div>
        @if(!$editing)
            <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">{{ __('students.student_number') }}</dt><dd>{{ $this->student->student_number }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.name') }}</dt><dd>{{ $this->student->name }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.phone') }}</dt><dd>{{ $this->student->phone }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.secondary_phone') }}</dt><dd>{{ $this->student->secondary_phone }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.mother_name') }}</dt><dd>{{ $this->student->mother_name }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.home_address') }}</dt><dd>{{ $this->student->home_address }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.guardian_name') }}</dt><dd>{{ $this->student->guardian_name }}</dd></div>
                <div><dt class="text-gray-500">{{ __('students.guardian_relationship') }}</dt><dd>{{ $this->student->guardian_relationship }}</dd></div>
            </dl>
        @else
            <form wire:submit="update" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-field :label="__('students.name')" :error="$errors->first('form.name')">
                    <input type="text" wire:model.blur="form.name" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.phone')" :error="$errors->first('form.phone')">
                    <input type="text" wire:model.blur="form.phone" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.secondary_phone')">
                    <input type="text" wire:model.blur="form.secondary_phone" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.mother_name')">
                    <input type="text" wire:model.blur="form.mother_name" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.home_address')">
                    <input type="text" wire:model.blur="form.home_address" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.blood_type')">
                    <input type="text" wire:model.blur="form.blood_type" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.date_of_birth')">
                    <input type="date" wire:model.blur="form.date_of_birth" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('students.guardian_name')">
                    <input type="text" wire:model.blur="form.guardian_name" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <div class="sm:col-span-2 flex gap-2">
                    <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white">{{ __('common.save') }}</button>
                    <button type="button" wire:click="$set('editing', false)" class="rounded border px-4 py-2">{{ __('common.cancel') }}</button>
                </div>
            </form>
        @endif
    </div>

    <div class="rounded bg-white p-4 shadow">
        <h2 class="font-medium">{{ __('students.status') }}</h2>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            @foreach(\App\Enums\StudentStatus::cases() as $case)
                <button wire:key="status-{{ $case->value }}" wire:click="changeStatus('{{ $case->value }}')" wire:loading.attr="disabled" class="rounded border px-3 py-1 hover:bg-gray-100">{{ __('students.status_'.$case->value) }}</button>
            @endforeach
        </div>
        <div class="mt-2">
            <x-field :label="__('students.status_note')">
                <input type="text" wire:model.blur="status_note" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
        </div>
        @if(!$this->student->isActive())
            <p class="mt-2 text-sm text-yellow-800">{{ __('students.errors.inactive_cannot_enroll') }}</p>
        @endif
    </div>

    <div class="rounded bg-white p-4 shadow">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">{{ __('students.enrollments') }}</h2>
            <button wire:click="$set('showEnroll', true)" class="rounded bg-gray-900 px-3 py-1 text-sm text-white">{{ __('students.enroll') }}</button>
        </div>
        @if($showEnroll)
            <form wire:submit="enroll" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <x-field :label="__('students.school')">
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
                <div class="sm:col-span-3 flex gap-2">
                    <button type="submit" wire:loading.attr="disabled" class="rounded bg-gray-900 px-4 py-2 text-white">{{ __('students.enroll') }}</button>
                    <button type="button" wire:click="$set('showEnroll', false)" class="rounded border px-4 py-2">{{ __('common.cancel') }}</button>
                </div>
                @error('student_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @error('academic_year_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            </form>
        @endif
        @if($this->enrollments->isEmpty())
            <p class="mt-2 text-sm text-gray-600">{{ __('students.no_enrollments') }}</p>
        @else
            <ul class="mt-3 space-y-1 text-sm">
                @foreach($this->enrollments as $enrollment)
                    <li wire:key="enrollment-{{ $enrollment->id }}">{{ $enrollment->academicYear->name }} — {{ __('schools.'.$enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
