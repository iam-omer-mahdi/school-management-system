<?php

use App\Actions\ArchiveStudent;
use App\Actions\EnrollStudent;
use App\Actions\RestoreStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
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

    public ?string $archive_note = null;

    public function mount(Student $student): void
    {
        $this->studentId = $student->id;
        $this->form->fillFrom($student);
    }

    #[Computed]
    public function student(): Student
    {
        return Student::query()
            ->select(['id', 'student_number', 'name', 'phone', 'secondary_phone', 'home_address', 'mother_name', 'blood_type', 'date_of_birth', 'guardian_name', 'guardian_relationship', 'archived_at', 'archive_note'])
            ->findOrFail($this->studentId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Enrollment> */
    #[Computed]
    public function enrollments(): \Illuminate\Database\Eloquent\Collection
    {
        return Enrollment::query()
            ->where('student_id', $this->studentId)
            ->with([
                'academicYear:id,name,starts_on',
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

    #[Computed]
    public function latestEnrollment(): ?Enrollment
    {
        return $this->enrollments
            ->sortByDesc(fn (Enrollment $e) => [$e->academicYear?->starts_on?->format('Y-m-d') ?? '', $e->id])
            ->first();
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'grade_id' => __('students.grade'),
            'academic_year_id' => __('students.academic_year'),
        ];
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
        unset($this->enrollments, $this->latestEnrollment);
        session()->flash('status', __('students.enrolled'));
    }

    public function changeEnrollmentStatus(UpdateEnrollmentStatus $action, int $enrollmentId, string $status): void
    {
        $action->handle($enrollmentId, EnrollmentStatus::from($status), $this->status_note);
        $this->status_note = null;
        unset($this->enrollments, $this->latestEnrollment);
        session()->flash('status', __('enrollments.status_changed'));
    }

    public function archive(ArchiveStudent $action): void
    {
        $action->handle($this->studentId, $this->archive_note);
        $this->archive_note = null;
        unset($this->student);
        session()->flash('status', __('students.saved'));
    }

    public function restore(RestoreStudent $action): void
    {
        $action->handle($this->studentId);
        unset($this->student);
        session()->flash('status', __('students.saved'));
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="$this->student->name" :backUrl="route('students.index')">
        <x-slot:actions>
            @if($this->student->isArchived())
                <x-ui.badge color="gray">{{ __('students.archived') }}</x-ui.badge>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if($this->student->isArchived())
        <x-ui.alert type="warning">{{ __('students.archived_notice') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900">{{ __('students.details') }}</h2>
                @if(! $editing)
                    <x-ui.button variant="ghost" size="sm" wire:click="$set('editing', true)" :spinner="false">{{ __('common.edit') }}</x-ui.button>
                @endif
            </div>
        </x-slot:header>
        @if(! $editing)
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.student_number') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->student_number }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.name') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->name }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.phone') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->phone }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.secondary_phone') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->secondary_phone }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.mother_name') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->mother_name }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.home_address') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->home_address }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.guardian_name') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->guardian_name }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.guardian_relationship') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $this->student->guardian_relationship }}</dd></div>
            </dl>
        @else
            <form wire:submit="update" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.field :label="__('students.student_number')">
                        <x-ui.input type="text" value="{{ $this->student->student_number }}" readonly disabled aria-label="{{ __('students.student_number') }}" />
                    </x-ui.field>
                </div>
                <x-ui.field :label="__('students.name')" for="edit-name" required :error="$errors->first('form.name')">
                    <x-ui.input id="edit-name" type="text" wire:model.blur="form.name" :error="$errors->first('form.name')" required />
                </x-ui.field>
                <x-ui.field :label="__('students.phone')" for="edit-phone" required :error="$errors->first('form.phone')">
                    <x-ui.input id="edit-phone" type="tel" wire:model.blur="form.phone" :error="$errors->first('form.phone')" required />
                </x-ui.field>
                <x-ui.field :label="__('students.secondary_phone')" for="edit-secondary">
                    <x-ui.input id="edit-secondary" type="tel" wire:model.blur="form.secondary_phone" />
                </x-ui.field>
                <x-ui.field :label="__('students.mother_name')" for="edit-mother">
                    <x-ui.input id="edit-mother" type="text" wire:model.blur="form.mother_name" />
                </x-ui.field>
                <x-ui.field :label="__('students.home_address')" for="edit-address">
                    <x-ui.input id="edit-address" type="text" wire:model.blur="form.home_address" />
                </x-ui.field>
                <x-ui.field :label="__('students.blood_type')" for="edit-blood">
                    <x-ui.input id="edit-blood" type="text" wire:model.blur="form.blood_type" />
                </x-ui.field>
                <x-ui.field :label="__('students.date_of_birth')" for="edit-dob">
                    <x-ui.input id="edit-dob" type="date" wire:model.blur="form.date_of_birth" />
                </x-ui.field>
                <x-ui.field :label="__('students.guardian_name')" for="edit-guardian">
                    <x-ui.input id="edit-guardian" type="text" wire:model.blur="form.guardian_name" />
                </x-ui.field>
                <div class="flex flex-wrap gap-2 sm:col-span-2">
                    <x-ui.button type="submit" variant="primary" loading-target="update" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('editing', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('students.archived')">
        @if(! $this->student->isArchived())
            @if($this->latestEnrollment && in_array($this->latestEnrollment->status->value, ['active', 'suspended'], true))
                <x-ui.alert type="warning">{{ __('students.archive_warning') }}</x-ui.alert>
            @endif
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-ui.field :label="__('students.archive_note')" for="archive_note">
                    <x-ui.input id="archive_note" type="text" wire:model.blur="archive_note" />
                </x-ui.field>
                <x-ui.button variant="danger" size="sm" wire:click="archive" loading-target="archive" wire:loading.attr="disabled">{{ __('students.archive') }}</x-ui.button>
            </div>
        @else
            <x-ui.button variant="secondary" size="sm" wire:click="restore" loading-target="restore" wire:loading.attr="disabled">{{ __('students.restore') }}</x-ui.button>
        @endif
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900">{{ __('students.enrollments') }}</h2>
                <x-ui.button variant="primary" size="sm" wire:click="$set('showEnroll', true)" :spinner="false">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('students.enroll') }}
                </x-ui.button>
            </div>
        </x-slot:header>
        @if($showEnroll)
            <form wire:submit="enroll" class="mb-5 grid grid-cols-1 gap-4 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:grid-cols-3 dark:border-gray-600 dark:bg-gray-700/40">
                @if($this->latestEnrollment && $this->latestEnrollment->status === \App\Enums\EnrollmentStatus::Expelled)
                    <x-ui.alert type="warning" class="sm:col-span-3">{{ __('students.expelled_warning') }}</x-ui.alert>
                @endif
                <x-ui.field :label="__('students.school')" for="enroll-school">
                    <x-ui.select id="enroll-school" wire:model.live="school_id">
                        <option value="">—</option>
                        @foreach($this->schools as $school)
                            <option value="{{ $school->id }}">{{ __('schools.'.$school->code) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('students.grade')" for="enroll-grade" required :error="$errors->first('grade_id')">
                    <x-ui.select id="enroll-grade" wire:model.live="grade_id" :error="$errors->first('grade_id')">
                        <option value="">—</option>
                        @foreach($this->grades as $grade)
                            <option value="{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('students.academic_year')" for="enroll-year" required :error="$errors->first('academic_year_id')">
                    <x-ui.select id="enroll-year" wire:model.live="academic_year_id" :error="$errors->first('academic_year_id')">
                        <option value="">—</option>
                        @foreach($this->years as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="flex flex-wrap gap-2 sm:col-span-3">
                    <x-ui.button type="submit" variant="primary" loading-target="enroll" wire:loading.attr="disabled">{{ __('students.enroll') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showEnroll', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
                @error('student_id')<p class="text-sm text-red-600 sm:col-span-3" role="alert">{{ $message }}</p>@enderror
            </form>
        @endif
        @if($this->enrollments->isEmpty())
            <p class="text-sm text-gray-600">{{ __('students.no_enrollments') }}</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach($this->enrollments as $enrollment)
                    <li wire:key="enrollment-{{ $enrollment->id }}" class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-600 dark:bg-gray-700/40">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $enrollment->academicYear->name }}</span>
                            <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">—</span>
                            <span class="text-gray-700 dark:text-gray-300">{{ __('schools.'.$enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</span>
                            <x-ui.badge :status="$enrollment->status->value" />
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @foreach(\App\Enums\EnrollmentStatus::cases() as $case)
                                <x-ui.button
                                    wire:key="status-{{ $enrollment->id }}-{{ $case->value }}"
                                    variant="{{ $enrollment->status === $case ? 'primary' : ($case === \App\Enums\EnrollmentStatus::Expelled ? 'danger' : 'secondary') }}"
                                    size="sm"
                                    wire:click="changeEnrollmentStatus({{ $enrollment->id }}, '{{ $case->value }}')"
                                    loading-target="changeEnrollmentStatus"
                                    wire:loading.attr="disabled"
                                >{{ __('enrollments.status_'.$case->value) }}</x-ui.button>
                            @endforeach
                        </div>
                        <div class="mt-2">
                            <x-ui.field :label="__('enrollments.status_note')" for="status_note-{{ $enrollment->id }}">
                                <x-ui.input id="status_note-{{ $enrollment->id }}" type="text" wire:model.blur="status_note" />
                            </x-ui.field>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
