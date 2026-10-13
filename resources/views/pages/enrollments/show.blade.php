<?php

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\GradeFee;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Locked]
    public int $enrollmentId;

    public ?string $statusModalNote = null;

    public ?string $statusTargetStatus = null;

    public ?string $discountChoice = null;

    public ?string $optionalChoice = null;

    public function mount(Enrollment $enrollment): void
    {
        $this->enrollmentId = $enrollment->id;
    }

    #[Computed]
    public function enrollment(): Enrollment
    {
        return Enrollment::query()
            ->with([
                'student:id,student_number,name,archived_at',
                'academicYear:id,name,starts_on',
                'grade:id,school_id,number',
                'grade.school:id,code,name',
                'fees.gradeFee.feeType',
                'discount',
            ])
            ->findOrFail($this->enrollmentId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Discount> */
    #[Computed]
    public function catalogDiscounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Discount::query()->select(['id', 'name', 'percent_bp'])->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, GradeFee> */
    #[Computed]
    public function optionalOptions(): \Illuminate\Database\Eloquent\Collection
    {
        $enrollment = $this->enrollment;
        $charged = $enrollment->fees->pluck('grade_fee_id')->all();

        return GradeFee::query()
            ->where('grade_id', $enrollment->grade_id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->whereHas('feeType', fn ($query) => $query->where('is_mandatory', false))
            ->whereNotIn('id', $charged)
            ->with('feeType:id,name,system_key')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, GradeFee> */
    #[Computed]
    public function missingFees(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->statusTargetStatus === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return app(UpdateEnrollmentStatus::class)->missingFor($this->enrollmentId)->load('feeType:id,name,system_key');
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'discountChoice' => __('fees.discount'),
            'optionalChoice' => __('fees.fee_type'),
        ];
    }

    public function openStatusModal(string $status): void
    {
        $this->statusTargetStatus = $status;
        $this->statusModalNote = null;
        unset($this->missingFees);
    }

    public function confirmStatusChange(UpdateEnrollmentStatus $action): void
    {
        $action->handle(
            $this->enrollmentId,
            EnrollmentStatus::from((string) $this->statusTargetStatus),
            $this->statusModalNote
        );

        $this->statusTargetStatus = null;
        $this->statusModalNote = null;
        unset($this->enrollment, $this->optionalOptions, $this->missingFees);
        session()->flash('status', __('enrollments.status_changed'));
    }

    public function applyDiscount(ApplyDiscountToEnrollment $action): void
    {
        $this->validate(['discountChoice' => ['required', 'exists:discounts,id']]);

        $action->handle($this->enrollmentId, (int) $this->discountChoice);
        unset($this->enrollment, $this->optionalOptions);
        session()->flash('status', __('fees.discount_applied'));
    }

    public function removeDiscount(RemoveEnrollmentDiscount $action): void
    {
        $action->handle($this->enrollmentId);
        unset($this->enrollment);
        session()->flash('status', __('fees.discount_removed'));
    }

    public function addOptional(AddOptionalFeeToEnrollment $action): void
    {
        $this->validate(['optionalChoice' => ['required', 'exists:grade_fees,id']]);

        $action->handle($this->enrollmentId, (int) $this->optionalChoice);
        $this->reset('optionalChoice');
        unset($this->enrollment, $this->optionalOptions);
        session()->flash('status', __('fees.charge_added'));
    }

    public function removeOptional(RemoveOptionalFeeFromEnrollment $action, int $gradeFeeId): void
    {
        $action->handle($this->enrollmentId, $gradeFeeId);
        unset($this->enrollment, $this->optionalOptions);
        session()->flash('status', __('fees.charge_removed'));
    }
};
?>

@php
    $enrollment = $this->enrollment;
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
    $choice = $this->catalogDiscounts->firstWhere('id', (int) ($discountChoice ?? 0));
    $previewMinor = ($tuitionMinor !== null && $choice) ? \App\Support\Money::percentOf($tuitionMinor, $choice->percent_bp) : null;
    $options = $this->optionalOptions;
    $isEnded = $enrollment->isEnded();
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="$enrollment->student->name" :backUrl="route('enrollments.index')">
        <x-slot:actions>
            <x-ui.badge :status="$enrollment->status->value" />
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <dl class="grid grid-cols-1 gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.student_number') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white"><a href="{{ route('students.show', $enrollment->student) }}" wire:navigate class="text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ $enrollment->student->student_number }}</a></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('students.name') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white"><a href="{{ route('students.show', $enrollment->student) }}" wire:navigate class="text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ $enrollment->student->name }}</a></dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.school') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ __('schools.'.$enrollment->grade->school->code) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.grade') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.academic_year') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $enrollment->academicYear->name }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('enrollments.status') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ __('enrollments.status_'.$enrollment->status->value) }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('enrollments.change_status') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('enrollments.status') }}: <x-ui.badge :status="$enrollment->status->value" /></p>
        </x-slot:header>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('enrollments.change_status') }}">
            @foreach(\App\Enums\EnrollmentStatus::cases() as $case)
                @if($enrollment->status === $case)
                    <x-ui.button
                        wire:key="status-{{ $case->value }}"
                        variant="{{ $case === \App\Enums\EnrollmentStatus::Expelled ? 'danger' : 'primary' }}"
                        size="sm"
                        :spinner="false"
                        disabled
                        aria-pressed="true"
                    ><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" /></svg>{{ __('enrollments.status_'.$case->value) }}</x-ui.button>
                @else
                    <x-ui.button
                        wire:key="status-{{ $case->value }}"
                        variant="secondary"
                        size="sm"
                        wire:click="openStatusModal('{{ $case->value }}')"
                        :spinner="false"
                    >{{ __('enrollments.status_'.$case->value) }}</x-ui.button>
                @endif
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('fees.charges') }}</h2>
        </x-slot:header>
        @if($enrollment->fees->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('fees.no_charges') }}</p>
        @else
            <ul class="space-y-2">
                @foreach($enrollment->fees as $charge)
                    <li wire:key="charge-{{ $charge->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-700/40">
                        <span class="text-gray-700 dark:text-gray-300">@if($charge->gradeFee->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $charge->gradeFee->feeType->name }}@endif</span>
                        <span class="flex items-center gap-2">
                            <x-ui.money :minor="$charge->amount_minor" />
                            @if(! $charge->gradeFee->feeType->is_mandatory)
                                <x-ui.button variant="ghost" size="sm" wire:click="removeOptional({{ $charge->grade_fee_id }})" loading-target="removeOptional" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if($isEnded)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('fees.errors.enrollment_ended') }}</p>
        @elseif($options->isNotEmpty())
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-ui.field :label="__('fees.add_optional_fee')" for="optional-fee" class="flex-1" :error="$errors->first('optionalChoice')">
                    <x-ui.select id="optional-fee" wire:model.live="optionalChoice" :error="$errors->first('optionalChoice')">
                        <option value="">—</option>
                        @foreach($options as $option)
                            <option value="{{ $option->id }}">@if($option->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $option->feeType->name }}@endif — {{ \App\Support\Money::format($option->amount_minor) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.button variant="secondary" size="sm" wire:click="addOptional" loading-target="addOptional" wire:loading.attr="disabled">{{ __('fees.add') }}</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('fees.discount') }}</h2>
        </x-slot:header>
        @if($appliedDiscount)
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-gray-700 dark:text-gray-300">{{ $appliedDiscount->name }}</span>
                <x-ui.percent :bp="$appliedDiscount->percent_bp" />
                <span aria-hidden="true">—</span>
                <x-ui.money :minor="$appliedDiscount->amount_minor" />
                <x-ui.button variant="ghost" size="sm" wire:click="removeDiscount" loading-target="removeDiscount" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('fees.remove_discount') }}</x-ui.button>
            </p>
        @endif
        @if($tuitionMinor === null)
            <p class="mt-3"><x-ui.badge color="gray">{{ __('fees.errors.fees_not_set') }}</x-ui.badge></p>
        @elseif($this->catalogDiscounts->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('fees.empty_catalog') }} <a href="{{ route('discounts.index') }}" wire:navigate class="font-medium text-indigo-700 hover:underline dark:text-indigo-400">{{ __('fees.discounts') }}</a></p>
        @elseif($isEnded)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('fees.errors.enrollment_ended') }}</p>
        @else
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-ui.field :label="__('fees.select_discount')" for="discount" class="flex-1" :error="$errors->first('discountChoice')">
                    <x-ui.select id="discount" wire:model.live="discountChoice" :error="$errors->first('discountChoice')">
                        <option value="">—</option>
                        @foreach($this->catalogDiscounts as $catalog)
                            <option value="{{ $catalog->id }}">{{ $catalog->name }} ({{ \App\Support\Percent::format($catalog->percent_bp) }})</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.button variant="secondary" size="sm" wire:click="applyDiscount" loading-target="applyDiscount" wire:loading.attr="disabled">{{ $appliedDiscount ? __('fees.replace_discount') : __('fees.apply_discount') }}</x-ui.button>
            </div>
            @if($previewMinor !== null && $choice)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400"><x-ui.money :minor="$previewMinor" /> <span aria-hidden="true">→</span> {{ __('fees.tuition_net') }} <x-ui.money :minor="$tuitionMinor - $previewMinor" /></p>
            @endif
        @endif
        @if($errors->first('discount_id'))
            <p class="mt-2 text-sm text-red-600 dark:text-red-500" role="alert">{{ $errors->first('discount_id') }}</p>
        @endif
    </x-ui.card>

    @if($totalMinor !== null)
        <x-ui.card>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.tuition') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$tuitionMinor" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.discount') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$appliedDiscount?->amount_minor ?? 0" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.tuition_net') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$netMinor" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.total_due') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$totalMinor" /></dd></div>
            </dl>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('enrollments.payments')">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('enrollments.payments_placeholder') }}</p>
    </x-ui.card>

    @if($statusTargetStatus)
        @php
            $targetStatus = \App\Enums\EnrollmentStatus::from($statusTargetStatus);
            $needsPreview = ! $enrollment->status->isCharged() && $targetStatus->isCharged();
        @endphp
        <x-ui.modal :title="__('enrollments.change_status')" close="$set('statusTargetStatus', null)">
            <p class="font-medium text-gray-900 dark:text-white">{{ __('enrollments.status_'.$statusTargetStatus) }}</p>
            @if($needsPreview)
                @if($this->missingFees->isNotEmpty())
                    <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-white">{{ __('enrollments.missing_fees_title') }}</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach($this->missingFees as $missing)
                            <li wire:key="missing-{{ $missing->id }}" class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-700/40">
                                <span>@if($missing->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $missing->feeType->name }}@endif</span>
                                <x-ui.money :minor="$missing->amount_minor" />
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3">{{ __('enrollments.missing_fees_confirm') }}</p>
                @else
                    <p class="mt-3">{{ __('enrollments.no_missing_fees') }}</p>
                @endif
            @endif
            <div class="mt-4">
                <x-ui.field :label="__('enrollments.status_note')" for="status-modal-note" :error="$errors->first('status_note')">
                    <x-ui.input id="status-modal-note" type="text" wire:model.blur="statusModalNote" :error="$errors->first('status_note')" />
                </x-ui.field>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('statusTargetStatus', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="confirmStatusChange" loading-target="confirmStatusChange" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
