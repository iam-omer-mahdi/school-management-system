<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use App\Actions\ArchiveStudent;
use App\Actions\EnrollStudent;
use App\Actions\RestoreStudent;
use App\Enums\EnrollmentStatus;
use App\Livewire\Forms\StudentForm;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $studentId;

    public StudentForm $form;

    public bool $editing = false;

    public bool $showEnroll = false;

    public ?int $expandedEnrollment = null;

    public ?int $school_id = null;

    public ?int $grade_id = null;

    public ?int $academic_year_id = null;

    public bool $showArchiveModal = false;

    public bool $showRestoreModal = false;

    public ?string $archive_note = null;

    public function mount(Student $student): void
    {
        Gate::authorize(Permission::StudentsView->value);

        $this->studentId = $student->id;
        $this->form->fillFrom($student);
        $this->expandedEnrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->with('academicYear:id,name,starts_on')
            ->get()
            ->sortByDesc(fn (Enrollment $e) => [$e->academicYear?->starts_on?->format('Y-m-d') ?? '', $e->id])
            ->first()?->id;
    }

    #[Computed]
    public function student(): Student
    {
        return Student::query()
            ->select(['id', 'student_number', 'name', 'phone', 'secondary_phone', 'home_address', 'mother_name', 'blood_type', 'date_of_birth', 'guardian_name', 'guardian_relationship', 'archived_at', 'archive_note'])
            ->findOrFail($this->studentId);
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Enrollment> */
    #[Computed]
    public function enrollments(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Enrollment::query()
            ->where('student_id', $this->studentId)
            ->with([
                'academicYear:id,name,starts_on',
                'grade:id,school_id,number',
                'grade.school:id,name',
                'fees.gradeFee.feeType',
                'fees.payments' => fn ($payments) => $payments->whereNull('voided_at')->select(['id', 'enrollment_id', 'enrollment_fee_id', 'amount_minor', 'voided_at']),
                'discount',
                'refunds' => fn ($refunds) => $refunds->whereNull('voided_at')->select(['id', 'enrollment_id', 'enrollment_fee_id', 'amount_minor', 'voided_at']),
            ])
            ->orderByDesc('id')
            ->paginate(5);
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

    #[Computed]
    public function latestEnrollment(): ?Enrollment
    {
        return Enrollment::query()
            ->where('student_id', $this->studentId)
            ->with('academicYear:id,name,starts_on')
            ->get()
            ->sortByDesc(fn (Enrollment $e) => [$e->academicYear?->starts_on?->format('Y-m-d') ?? '', $e->id])
            ->first();
    }

    public function toggleExpanded(int $enrollmentId): void
    {
        $this->expandedEnrollment = $this->expandedEnrollment === $enrollmentId ? null : $enrollmentId;
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
        Gate::authorize(Permission::StudentsManage->value);

        $student = $this->student;
        $student->update($this->form->data());
        $this->editing = false;
        unset($this->student);
        session()->flash('status', __('students.updated'));
    }

    public function enroll(EnrollStudent $action): void
    {
        $this->validate([
            'grade_id' => ['required', Rule::exists('grades', 'id')->where('school_id', $this->school_id)],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
        ]);

        $action->handle($this->studentId, (int) $this->academic_year_id, (int) $this->grade_id);

        $enrollment = Enrollment::query()
            ->where('student_id', $this->studentId)
            ->orderByDesc('id')
            ->first();

        $this->showEnroll = false;
        $this->reset(['school_id', 'grade_id', 'academic_year_id']);
        $this->resetPage();
        $this->expandedEnrollment = $enrollment?->id;
        unset($this->enrollments, $this->latestEnrollment);
        session()->flash('status', __('students.enrolled'));
    }

    public function updatedSchoolId(): void
    {
        $this->grade_id = null;
    }

    public function openArchiveModal(): void
    {
        $this->archive_note = null;
        $this->showArchiveModal = true;
    }

    public function archive(ArchiveStudent $action): void
    {
        $action->handle($this->studentId, $this->archive_note);
        $this->archive_note = null;
        $this->showArchiveModal = false;
        unset($this->student);
        session()->flash('status', __('students.saved'));
    }

    public function restore(RestoreStudent $action): void
    {
        $action->handle($this->studentId);
        $this->showRestoreModal = false;
        unset($this->student);
        session()->flash('status', __('students.saved'));
    }
};
?>

<div class="space-y-8 lg:space-y-10">
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
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-ink ">{{ __('students.details') }}</h2>
                @if(! $editing)
                    @can(\App\Enums\Permission::StudentsManage->value)
                        <div class="flex shrink-0 items-center gap-2">
                            <x-ui.button variant="warning" size="sm" wire:click="$set('editing', true)" :spinner="false">{{ __('common.edit') }}</x-ui.button>
                            @if(! $this->student->isArchived())
                                <x-ui.button variant="danger" size="sm" wire:click="openArchiveModal" :spinner="false">{{ __('students.archive') }}</x-ui.button>
                            @else
                                <x-ui.button variant="secondary" size="sm" wire:click="$set('showRestoreModal', true)" :spinner="false">{{ __('students.restore') }}</x-ui.button>
                            @endif
                        </div>
                    @endcan
                @endif
            </div>
        </x-slot:header>
        @if(! $editing)
            <dl class="grid grid-cols-1 gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
                <div><dt class="text-muted ">{{ __('students.student_number') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->student_number }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.name') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->name }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.phone') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->phone }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.secondary_phone') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->secondary_phone }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.mother_name') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->mother_name }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.home_address') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->home_address }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.guardian_name') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->guardian_name }}</dd></div>
                <div><dt class="text-muted ">{{ __('students.guardian_relationship') }}</dt><dd class="mt-0.5 font-medium text-ink ">{{ $this->student->guardian_relationship }}</dd></div>
            </dl>
        @else
            <form wire:submit="update" class="space-y-8">
                <div class="sm:col-span-2">
                    <x-ui.field :label="__('students.student_number')">
                        <x-ui.input type="text" value="{{ $this->student->student_number }}" readonly disabled aria-label="{{ __('students.student_number') }}" />
                    </x-ui.field>
                </div>
                <fieldset>
                    <legend class="text-sm font-semibold text-ink ">{{ __('students.section_basic') }}</legend>
                    <p class="mt-1 text-sm text-muted ">{{ __('students.section_basic_hint') }}</p>
                    <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <x-ui.field :label="__('students.name')" for="edit-name" required :error="$errors->first('form.name')">
                            <x-ui.input id="edit-name" type="text" wire:model.blur="form.name" :error="$errors->first('form.name')" required />
                        </x-ui.field>
                        <x-ui.field :label="__('students.date_of_birth')" for="edit-dob" :error="$errors->first('form.date_of_birth')">
                            <x-ui.input id="edit-dob" type="date" wire:model.blur="form.date_of_birth" :error="$errors->first('form.date_of_birth')" :min="now()->subYears(\App\Livewire\Forms\StudentForm::MAX_AGE_YEARS)->toDateString()" :max="now()->subYears(\App\Livewire\Forms\StudentForm::MIN_AGE_YEARS)->toDateString()" />
                        </x-ui.field>
                        <x-ui.field :label="__('students.mother_name')" for="edit-mother" :error="$errors->first('form.mother_name')">
                            <x-ui.input id="edit-mother" type="text" wire:model.blur="form.mother_name" :error="$errors->first('form.mother_name')" />
                        </x-ui.field>
                        <x-ui.field :label="__('students.blood_type')" for="edit-blood" :error="$errors->first('form.blood_type')">
                            <x-ui.select id="edit-blood" wire:model.blur="form.blood_type" :error="$errors->first('form.blood_type')">
                                <option value="">—</option>
                                @foreach(\App\Livewire\Forms\StudentForm::BLOOD_TYPES as $bloodType)
                                    <option value="{{ $bloodType }}">{{ $bloodType }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                </fieldset>
                <fieldset class="border-t border-line pt-7 ">
                    <legend class="text-sm font-semibold text-ink ">{{ __('students.section_contact') }}</legend>
                    <p class="mt-1 text-sm text-muted ">{{ __('students.section_contact_hint') }}</p>
                    <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <x-ui.field :label="__('students.phone')" for="edit-phone" required :error="$errors->first('form.phone')">
                            <x-ui.input id="edit-phone" type="tel" wire:model.blur="form.phone" :error="$errors->first('form.phone')" required />
                        </x-ui.field>
                        <x-ui.field :label="__('students.secondary_phone')" for="edit-secondary" :error="$errors->first('form.secondary_phone')">
                            <x-ui.input id="edit-secondary" type="tel" wire:model.blur="form.secondary_phone" :error="$errors->first('form.secondary_phone')" />
                        </x-ui.field>
                        <x-ui.field :label="__('students.home_address')" for="edit-address" class="sm:col-span-2" :error="$errors->first('form.home_address')">
                            <x-ui.input id="edit-address" type="text" wire:model.blur="form.home_address" :error="$errors->first('form.home_address')" />
                        </x-ui.field>
                    </div>
                </fieldset>
                <fieldset class="border-t border-line pt-7 ">
                    <legend class="text-sm font-semibold text-ink ">{{ __('students.section_guardian') }}</legend>
                    <p class="mt-1 text-sm text-muted ">{{ __('students.section_guardian_hint') }}</p>
                    <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <x-ui.field :label="__('students.guardian_name')" for="edit-guardian" :error="$errors->first('form.guardian_name')">
                            <x-ui.input id="edit-guardian" type="text" wire:model.blur="form.guardian_name" :error="$errors->first('form.guardian_name')" />
                        </x-ui.field>
                        <x-ui.field :label="__('students.guardian_relationship')" for="edit-guardian-relationship" :error="$errors->first('form.guardian_relationship')">
                            <x-ui.input id="edit-guardian-relationship" type="text" wire:model.blur="form.guardian_relationship" :error="$errors->first('form.guardian_relationship')" />
                        </x-ui.field>
                    </div>
                </fieldset>
                <div class="flex flex-col-reverse gap-3 border-t border-line pt-6 sm:flex-row sm:justify-end ">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('editing', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading-target="update" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>

    @if($showArchiveModal)
        <x-ui.modal :title="__('students.archive')" close="$set('showArchiveModal', false)">
            <p>{{ __('students.confirm_archive') }}</p>
            @if($this->latestEnrollment && in_array($this->latestEnrollment->status->value, ['active', 'suspended'], true))
                <div class="mt-4"><x-ui.alert type="warning">{{ __('students.archive_warning') }}</x-ui.alert></div>
            @endif
            <div class="mt-4">
                <x-ui.field :label="__('students.archive_note')" for="archive_note" :error="$errors->first('archive_note')">
                    <x-ui.input id="archive_note" type="text" wire:model.blur="archive_note" :error="$errors->first('archive_note')" />
                </x-ui.field>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showArchiveModal', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="archive" loading-target="archive" wire:loading.attr="disabled">{{ __('students.archive') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($showRestoreModal)
        <x-ui.modal :title="__('students.restore')" close="$set('showRestoreModal', false)">
            <p>{{ __('students.confirm_restore') }}</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showRestoreModal', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="restore" loading-target="restore" wire:loading.attr="disabled">{{ __('students.restore') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-ink ">{{ __('students.enrollments') }}</h2>
                @can(\App\Enums\Permission::EnrollmentsManage->value)
                    <x-ui.button variant="primary" size="sm" wire:click="$set('showEnroll', true)" :spinner="false">
                        <x-ui.icon name="plus" class="h-4 w-4" />
                        {{ __('students.enroll') }}
                    </x-ui.button>
                @endcan
            </div>
        </x-slot:header>
        @if($showEnroll)
            <form wire:submit="enroll" class="mb-6 space-y-6 rounded-sm border border-line bg-surface-raised p-5 sm:p-6 ">
                <div>
                    <h3 class="text-sm font-semibold text-ink ">{{ __('students.enroll_title') }}</h3>
                    <p class="mt-1 text-sm text-muted ">{{ __('students.school') }} · {{ __('students.grade') }} · {{ __('students.academic_year') }}</p>
                </div>
                @if($this->latestEnrollment && $this->latestEnrollment->status === \App\Enums\EnrollmentStatus::Expelled)
                    <x-ui.alert type="warning">{{ __('students.expelled_warning') }}</x-ui.alert>
                @endif
                <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-3">
                    <x-ui.field :label="__('students.school')" for="enroll-school">
                        <x-ui.select id="enroll-school" wire:model.live="school_id">
                            <option value="">—</option>
                            @foreach($this->schools as $school)
                                <option value="{{ $school->id }}">{{ $school->name }}</option>
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
                </div>
                <div class="flex flex-col-reverse gap-3 border-t border-line pt-5 sm:flex-row sm:justify-end ">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showEnroll', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading-target="enroll" wire:loading.attr="disabled">{{ __('students.enroll') }}</x-ui.button>
                </div>
                @error('student_id')<p class="text-sm text-negative" role="alert">{{ $message }}</p>@enderror
            </form>
        @endif
        @if($this->enrollments->isEmpty())
            <p class="text-sm text-muted ">{{ __('students.no_enrollments') }}</p>
        @else
            <ul class="space-y-4 text-sm">
                @foreach($this->enrollments as $enrollment)
                    @php
                        $balance = \App\Support\EnrollmentBalance::forEnrollment($enrollment);
                        $hasTuition = collect($balance['fees'])->contains(fn (array $fee): bool => $fee['is_tuition']);
                        $isOpen = $this->expandedEnrollment === $enrollment->id;
                    @endphp
                    <li wire:key="enrollment-{{ $enrollment->id }}" class="rounded-sm border border-line bg-surface-raised ">
                        <button
                            type="button"
                            wire:click="toggleExpanded({{ $enrollment->id }})"
                            aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                            aria-controls="enrollment-body-{{ $enrollment->id }}"
                            class="flex w-full cursor-pointer items-center gap-x-3 gap-y-2 p-5 text-start focus:outline-none focus:ring-2 focus:ring-focus focus:ring-inset sm:px-6 "
                        >
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="text-base font-semibold text-ink ">{{ $enrollment->academicYear->name }}</span>
                                    <span class="text-subtle " aria-hidden="true">—</span>
                                    <span class="text-muted ">{{ $enrollment->grade->school->name }} {{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</span>
                                    <x-ui.badge :status="$enrollment->status->value" />
                                </span>
                                <span class="mt-1 block text-xs text-muted ">
                                    @can(\App\Enums\Permission::FeesView->value)
                                        @if($hasTuition)<x-ui.money :minor="$balance['owed']" /> · {{ __('fees.total_due') }} · <x-ui.money :minor="$balance['paid']" /> · {{ __('payments.paid') }}@else{{ __('fees.errors.fees_not_set') }}@endif
                                    @endcan
                                </span>
                            </span>
                            <svg class="h-5 w-5 shrink-0 text-muted transition-transform @if($isOpen) rotate-180 @endif" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                            <span class="sr-only">{{ $enrollment->academicYear->name }}</span>
                        </button>
                        @if($isOpen)
                        <div id="enrollment-body-{{ $enrollment->id }}" class="border-t border-line px-5 py-4 sm:px-6 ">
                            <x-ui.button href="{{ route('enrollments.show', $enrollment) }}" wire:navigate variant="secondary" size="sm" :spinner="false">{{ __('enrollments.view_details') }}</x-ui.button>
                        </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($this->enrollments->hasPages())
                <div class="mt-4">{{ $this->enrollments->links() }}</div>
            @endif
        @endif
    </x-ui.card>
</div>
