<?php

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\ArchiveStudent;
use App\Actions\EnrollStudent;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\RestoreStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Livewire\Forms\StudentForm;
use App\Models\AcademicYear;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Models\Student;
use App\Support\Money;
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

    /** @var array<int, string> */
    public array $discountChoice = [];

    /** @var array<int, string> */
    public array $optionalChoice = [];

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
                'fees.gradeFee.feeType',
                'discount',
            ])
            ->orderByDesc('id')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Discount> */
    #[Computed]
    public function catalogDiscounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Discount::query()->select(['id', 'name', 'percent_bp'])->orderBy('name')->get();
    }

    /**
     * Optional schedule rows each enrollment could still take, keyed by
     * enrollment id. Loaded once for all visible enrollments.
     *
     * @return array<int, \Illuminate\Database\Eloquent\Collection<int, GradeFee>>
     */
    #[Computed]
    public function optionalOptions(): array
    {
        $enrollments = $this->enrollments;

        if ($enrollments->isEmpty()) {
            return [];
        }

        $pairs = $enrollments->map(fn (Enrollment $e) => [$e->grade_id, $e->academic_year_id])->unique();

        $optionals = GradeFee::query()
            ->whereHas('feeType', fn ($query) => $query->where('is_mandatory', false))
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as [$gradeId, $yearId]) {
                    $query->orWhere(fn ($q) => $q->where('grade_id', $gradeId)->where('academic_year_id', $yearId));
                }
            })
            ->with('feeType:id,name')
            ->get();

        $options = [];

        foreach ($enrollments as $enrollment) {
            $charged = $enrollment->fees->pluck('grade_fee_id')->all();
            $options[$enrollment->id] = $optionals
                ->where('grade_id', $enrollment->grade_id)
                ->where('academic_year_id', $enrollment->academic_year_id)
                ->reject(fn (GradeFee $fee) => in_array($fee->id, $charged, true))
                ->values();
        }

        return $options;
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
            'discountChoice.*' => __('fees.discount'),
            'optionalChoice.*' => __('fees.fee_type'),
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
        unset($this->enrollments, $this->latestEnrollment, $this->optionalOptions);
        session()->flash('status', __('enrollments.status_changed'));
    }

    public function applyDiscount(ApplyDiscountToEnrollment $action, int $enrollmentId): void
    {
        $choice = $this->discountChoice[$enrollmentId] ?? null;

        $this->validate(['discountChoice.'.$enrollmentId => ['required', 'exists:discounts,id']]);

        $action->handle($enrollmentId, (int) $choice);
        unset($this->enrollments, $this->optionalOptions);
        session()->flash('status', __('fees.discount_applied'));
    }

    public function removeDiscount(RemoveEnrollmentDiscount $action, int $enrollmentId): void
    {
        $action->handle($enrollmentId);
        unset($this->enrollments);
        session()->flash('status', __('fees.discount_removed'));
    }

    public function addOptional(AddOptionalFeeToEnrollment $action, int $enrollmentId): void
    {
        $this->validate(['optionalChoice.'.$enrollmentId => ['required', 'exists:grade_fees,id']]);

        $action->handle($enrollmentId, (int) $this->optionalChoice[$enrollmentId]);
        unset($this->optionalChoice[$enrollmentId], $this->enrollments, $this->optionalOptions);
        session()->flash('status', __('fees.charge_added'));
    }

    public function removeOptional(RemoveOptionalFeeFromEnrollment $action, int $enrollmentId, int $gradeFeeId): void
    {
        $action->handle($enrollmentId, $gradeFeeId);
        unset($this->enrollments, $this->optionalOptions);
        session()->flash('status', __('fees.charge_removed'));
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
            <ul class="space-y-3 text-sm">
                @foreach($this->enrollments as $enrollment)
                    @php
                        $tuitionMinor = null;
                        $otherMinor = 0;
                        foreach ($enrollment->fees as $charge) {
                            if ($charge->gradeFee->feeType->isTuition()) {
                                $tuitionMinor = ($tuitionMinor ?? 0) + $charge->amount_minor;
                            } else {
                                $otherMinor += $charge->amount_minor;
                            }
                        }
                        $appliedDiscount = $enrollment->discount;
                        $netMinor = $tuitionMinor !== null ? $tuitionMinor - ($appliedDiscount?->amount_minor ?? 0) : null;
                        $totalMinor = $netMinor !== null ? $netMinor + $otherMinor : null;
                        $choice = $this->catalogDiscounts->firstWhere('id', (int) ($discountChoice[$enrollment->id] ?? 0));
                        $previewMinor = ($tuitionMinor !== null && $choice) ? \App\Support\Money::percentOf($tuitionMinor, $choice->percent_bp) : null;
                        $options = $this->optionalOptions[$enrollment->id] ?? collect();
                    @endphp
                    <li wire:key="enrollment-{{ $enrollment->id }}" class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-3 dark:border-gray-600 dark:bg-gray-700/40">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $enrollment->academicYear->name }}</span>
                            <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">—</span>
                            <span class="text-gray-700 dark:text-gray-300">{{ __('schools.'.$enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</span>
                            <x-ui.badge :status="$enrollment->status->value" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
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
                        <div>
                            <x-ui.field :label="__('enrollments.status_note')" for="status_note-{{ $enrollment->id }}">
                                <x-ui.input id="status_note-{{ $enrollment->id }}" type="text" wire:model.blur="status_note" />
                            </x-ui.field>
                        </div>

                        <div>
                            <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">{{ __('fees.charges') }}</h3>
                            @if($enrollment->fees->isEmpty())
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('fees.no_charges') }}</p>
                            @else
                                <ul class="space-y-1">
                                    @foreach($enrollment->fees as $charge)
                                        <li wire:key="charge-{{ $charge->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-white px-2 py-1.5 dark:bg-gray-800">
                                            <span class="text-gray-700 dark:text-gray-300">{{ $charge->gradeFee->feeType->name }}</span>
                                            <span class="flex items-center gap-2">
                                                <x-ui.money :minor="$charge->amount_minor" />
                                                @if(! $charge->gradeFee->feeType->is_mandatory)
                                                    <x-ui.button variant="ghost" size="sm" wire:click="removeOptional({{ $enrollment->id }}, {{ $charge->grade_fee_id }})" loading-target="removeOptional" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($options->isNotEmpty())
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                    <x-ui.field :label="__('fees.add_optional_fee')" for="optional-{{ $enrollment->id }}" class="flex-1" :error="$errors->first('optionalChoice.'.$enrollment->id)">
                                        <x-ui.select id="optional-{{ $enrollment->id }}" wire:model.live="optionalChoice.{{ $enrollment->id }}" :error="$errors->first('optionalChoice.'.$enrollment->id)">
                                            <option value="">—</option>
                                            @foreach($options as $option)
                                                <option value="{{ $option->id }}">{{ $option->feeType->name }} — {{ \App\Support\Money::format($option->amount_minor) }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>
                                    <x-ui.button variant="secondary" size="sm" wire:click="addOptional({{ $enrollment->id }})" loading-target="addOptional" wire:loading.attr="disabled">{{ __('common.create') }}</x-ui.button>
                                </div>
                            @endif
                        </div>

                        <div>
                            <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">{{ __('fees.discount') }}</h3>
                            @if($appliedDiscount)
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="text-gray-700 dark:text-gray-300">{{ $appliedDiscount->name }}</span>
                                    <x-ui.percent :bp="$appliedDiscount->percent_bp" />
                                    <span aria-hidden="true">—</span>
                                    <x-ui.money :minor="$appliedDiscount->amount_minor" />
                                    <x-ui.button variant="ghost" size="sm" wire:click="removeDiscount({{ $enrollment->id }})" loading-target="removeDiscount" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('fees.remove_discount') }}</x-ui.button>
                                </p>
                            @endif
                            @if($tuitionMinor === null)
                                <p><x-ui.badge color="gray">{{ __('fees.errors.fees_not_set') }}</x-ui.badge></p>
                            @elseif($this->catalogDiscounts->isEmpty())
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('fees.empty_catalog') }} <a href="{{ route('discounts.index') }}" wire:navigate class="font-medium text-indigo-700 hover:underline dark:text-indigo-400">{{ __('fees.discounts') }}</a></p>
                            @else
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                    <x-ui.field :label="__('fees.select_discount')" for="discount-{{ $enrollment->id }}" class="flex-1" :error="$errors->first('discountChoice.'.$enrollment->id)">
                                        <x-ui.select id="discount-{{ $enrollment->id }}" wire:model.live="discountChoice.{{ $enrollment->id }}" :error="$errors->first('discountChoice.'.$enrollment->id)">
                                            <option value="">—</option>
                                            @foreach($this->catalogDiscounts as $catalog)
                                                <option value="{{ $catalog->id }}">{{ $catalog->name }} ({{ \App\Support\Percent::format($catalog->percent_bp) }})</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>
                                    <x-ui.button variant="secondary" size="sm" wire:click="applyDiscount({{ $enrollment->id }})" loading-target="applyDiscount" wire:loading.attr="disabled">{{ $appliedDiscount ? __('fees.replace_discount') : __('fees.apply_discount') }}</x-ui.button>
                                </div>
                                @if($previewMinor !== null && $choice)
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400"><x-ui.money :minor="$previewMinor" /> <span aria-hidden="true">→</span> {{ __('fees.tuition_net') }} <x-ui.money :minor="$tuitionMinor - $previewMinor" /></p>
                                @endif
                            @endif
                            @if($errors->first('discount_id'))
                                <p class="mt-1 text-sm text-red-600 dark:text-red-500" role="alert">{{ $errors->first('discount_id') }}</p>
                            @endif
                        </div>

                        @if($totalMinor !== null)
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 border-t border-gray-200 pt-2 text-sm dark:border-gray-600 sm:grid-cols-4">
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.tuition') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$tuitionMinor" /></dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.discount') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$appliedDiscount?->amount_minor ?? 0" /></dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.tuition_net') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$netMinor" /></dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.total_due') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$totalMinor" /></dd></div>
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
