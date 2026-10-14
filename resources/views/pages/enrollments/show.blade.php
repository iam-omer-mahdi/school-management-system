<?php

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\UpdateEnrollmentStatus;
use App\Actions\VoidPayment;
use App\Actions\VoidRefund;
use App\Enums\EnrollmentStatus;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\GradeFee;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Locked]
    public int $enrollmentId;

    public ?string $statusModalNote = null;

    #[Locked]
    public ?string $statusTargetStatus = null;

    public ?string $discountChoice = null;

    public ?string $optionalChoice = null;

    public bool $showPaymentModal = false;

    public string $payMethod = 'cash';

    public ?string $payTxn = null;

    public ?string $payDate = null;

    public ?string $payNote = null;

    public string $payTotal = '';

    /** @var array<int, string> */
    public array $payAlloc = [];

    public bool $confirmOverpayment = false;

    public bool $lastTxnReused = false;

    /** @var array<int> */
    #[Locked]
    public array $lastPaymentIds = [];

    /** @var array<int, string> */
    public array $selectedReceiptIds = [];

    public bool $showRefundModal = false;

    public string $refundAmount = '';

    public string $refundMethod = 'cash';

    public ?string $refundTxn = null;

    public ?string $refundDate = null;

    public ?string $refundNote = null;

    #[Locked]
    public ?int $voidTargetId = null;

    #[Locked]
    public ?string $voidTargetType = null;

    public ?string $voidReason = null;

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
                'fees.payments',
                'discount',
                'payments.enrollmentFee.gradeFee.feeType:id,name,system_key',
                'payments',
                'refunds',
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
            'payTotal' => __('payments.amount'),
            'payAlloc.*' => __('payments.amount'),
            'payMethod' => __('payments.method'),
            'payTxn' => __('payments.transaction_number'),
            'payDate' => __('payments.paid_on'),
            'refundAmount' => __('payments.amount'),
            'refundMethod' => __('payments.method'),
            'refundTxn' => __('payments.transaction_number'),
            'refundDate' => __('payments.refunded_on'),
            'refundNote' => __('fees.note'),
            'voidReason' => __('payments.void_reason'),
        ];
    }

    #[Computed]
    public function txnReuseWarning(): bool
    {
        if (! $this->showPaymentModal || $this->payMethod !== 'bank_transfer' || trim((string) $this->payTxn) === '') {
            return false;
        }

        return \App\Models\Payment::query()
            ->where('transaction_number', trim((string) $this->payTxn))
            ->where('enrollment_id', '!=', $this->enrollmentId)
            ->whereNull('voided_at')
            ->exists();
    }

    #[Computed]
    public function parsedPayTotal(): ?int
    {
        try {
            return Money::fromDecimal($this->payTotal);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Every entered per-fee amount parsed once to minor units, or null
     * when any entered value is not a valid amount. Blank inputs are
     * skipped. Keys are grade fee ids.
     *
     * @return array<int, int>|null
     */
    #[Computed]
    public function parsedPayAlloc(): ?array
    {
        $parsed = [];

        foreach ($this->payAlloc as $feeId => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            try {
                $parsed[(int) $feeId] = Money::fromDecimal((string) $value);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return $parsed;
    }

    /**
     * Total minus allocated, or null when either side is not a valid
     * amount. Drives the live Unallocated line and the overpayment
     * checkbox. The Pay button itself is gated by payBlockReason.
     */
    #[Computed]
    public function payUnallocated(): ?int
    {
        $total = $this->parsedPayTotal;
        $allocs = $this->parsedPayAlloc;

        if ($total === null || $allocs === null) {
            return null;
        }

        return $total - array_sum($allocs);
    }

    /**
     * The single source of truth for the Pay button: null when the
     * form can be saved, otherwise the specific reason it cannot.
     * Every input it reads is synced live, so the button and this
     * text update as the user types or switches method.
     */
    #[Computed]
    public function payBlockReason(): ?string
    {
        $total = $this->parsedPayTotal;
        $allocs = $this->parsedPayAlloc;

        if ($total === null || $total <= 0 || $allocs === null) {
            return __('validation.numeric', ['attribute' => __('payments.amount')]);
        }

        $unallocated = $total - array_sum($allocs);

        if ($unallocated < 0) {
            return __('payments.errors.total_mismatch');
        }

        if ($unallocated > 0 && ! $this->confirmOverpayment) {
            return __('payments.unallocated').': '.Money::format($unallocated);
        }

        if ($this->payMethod === 'bank_transfer' && trim((string) $this->payTxn) === '') {
            return __('validation.required', ['attribute' => __('payments.transaction_number')]);
        }

        return null;
    }

    public function updatedPayMethod(): void
    {
        $this->payTxn = null;
        $this->resetValidation(['payTxn', 'transaction_number']);
        unset($this->txnReuseWarning);
    }

    public function openPaymentModal(): void
    {
        $this->resetValidation();
        $this->reset(['payMethod', 'payTxn', 'payDate', 'payNote', 'payTotal', 'payAlloc', 'confirmOverpayment', 'lastTxnReused', 'lastPaymentIds']);
        $this->payMethod = 'cash';
        $this->payDate = date('Y-m-d');
        unset($this->txnReuseWarning, $this->payUnallocated, $this->parsedPayTotal, $this->parsedPayAlloc);
        $this->showPaymentModal = true;
    }

    public function closePaymentModal(): void
    {
        $this->resetValidation();
        $this->showPaymentModal = false;
    }

    public function savePayment(RecordPayment $action): void
    {
        $this->validate(['payTotal' => ['required', 'string']]);

        $total = $this->parsedPayTotal;
        $allocs = $this->parsedPayAlloc;

        if ($total === null || $total <= 0 || $allocs === null) {
            $this->addError('payTotal', __('validation.numeric', ['attribute' => __('payments.amount')]));

            return;
        }

        $rows = [];

        foreach ($allocs as $feeId => $minor) {
            if ($minor <= 0) {
                $this->addError('payTotal', __('validation.numeric', ['attribute' => __('payments.amount')]));

                return;
            }

            $rows[] = ['grade_fee_id' => $feeId, 'amount_minor' => $minor];
        }

        $unallocated = $total - array_sum($allocs);

        if ($unallocated < 0 || ($unallocated > 0 && ! $this->confirmOverpayment)) {
            $this->addError('payTotal', __('validation.numeric', ['attribute' => __('payments.amount')]));

            return;
        }

        $result = $action->handle([
            'enrollment_id' => $this->enrollmentId,
            'total_minor' => $total,
            'rows' => $rows,
            'overpayment_minor' => max(0, $unallocated),
            'overpayment_confirmed' => (bool) $this->confirmOverpayment,
            'method' => $this->payMethod,
            'transaction_number' => $this->payTxn,
            'paid_on' => $this->payDate ?? date('Y-m-d'),
            'note' => $this->payNote,
        ]);

        $this->lastPaymentIds = $result['payments']->pluck('id')->all();
        $this->lastTxnReused = $result['transaction_reused'];
        $this->showPaymentModal = false;
        $this->reset(['payTotal', 'payAlloc', 'confirmOverpayment']);
        unset($this->enrollment, $this->payUnallocated, $this->parsedPayTotal, $this->parsedPayAlloc, $this->txnReuseWarning);
        session()->flash('status', __('payments.payment_recorded'));
    }

    public function openStatusModal(string $status): void
    {
        if (EnrollmentStatus::tryFrom($status) === null) {
            throw ValidationException::withMessages([
                'status' => __('validation.in', ['attribute' => __('enrollments.status')]),
            ]);
        }

        $this->resetValidation();
        $this->statusTargetStatus = $status;
        $this->statusModalNote = null;
        unset($this->missingFees);
    }

    public function confirmStatusChange(UpdateEnrollmentStatus $action): void
    {
        $target = EnrollmentStatus::tryFrom((string) $this->statusTargetStatus);

        if ($target === null) {
            throw ValidationException::withMessages([
                'status' => __('validation.in', ['attribute' => __('enrollments.status')]),
            ]);
        }

        $action->handle(
            $this->enrollmentId,
            $target,
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

    public function openRefundModal(): void
    {
        $this->resetValidation();
        $this->reset(['refundAmount', 'refundMethod', 'refundTxn', 'refundDate', 'refundNote']);
        $this->refundMethod = 'cash';
        $this->refundDate = date('Y-m-d');
        $this->showRefundModal = true;
    }

    public function closeRefundModal(): void
    {
        $this->resetValidation();
        $this->showRefundModal = false;
    }

    public function saveRefund(RecordRefund $action): void
    {
        $action->handle([
            'enrollment_id' => $this->enrollmentId,
            'amount' => $this->refundAmount,
            'method' => $this->refundMethod,
            'transaction_number' => $this->refundTxn,
            'refunded_on' => $this->refundDate ?? date('Y-m-d'),
            'note' => $this->refundNote,
        ]);

        $this->showRefundModal = false;
        $this->reset(['refundAmount', 'refundTxn', 'refundNote']);
        unset($this->enrollment);
        session()->flash('status', __('payments.refund_recorded'));
    }

    public function openVoidModal(string $type, int $id): void
    {
        if ($type !== 'payment' && $type !== 'refund') {
            throw ValidationException::withMessages([
                'voidTargetType' => __('validation.in', ['attribute' => __('payments.void')]),
            ]);
        }

        $this->resetValidation();
        $this->voidTargetType = $type;
        $this->voidTargetId = $id;
        $this->voidReason = null;
    }

    public function closeVoidModal(): void
    {
        $this->resetValidation();
        $this->voidTargetType = null;
        $this->voidTargetId = null;
        $this->voidReason = null;
    }

    public function confirmVoid(VoidPayment $voidPayment, VoidRefund $voidRefund): void
    {
        if ($this->voidTargetType === 'refund') {
            $voidRefund->handle($this->enrollmentId, (int) $this->voidTargetId, $this->voidReason);
            session()->flash('status', __('payments.refund_voided'));
        } elseif ($this->voidTargetType === 'payment') {
            $voidPayment->handle($this->enrollmentId, (int) $this->voidTargetId, $this->voidReason);
            session()->flash('status', __('payments.payment_voided'));
        } else {
            throw ValidationException::withMessages([
                'voidTargetType' => __('validation.in', ['attribute' => __('payments.void')]),
            ]);
        }

        $this->closeVoidModal();
        unset($this->enrollment);
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
    $balance = \App\Support\EnrollmentBalance::forEnrollment($enrollment);
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
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('fees.fee_type') }}</th>
                    <th>{{ __('fees.amount') }}</th>
                    <th>{{ __('fees.discount') }}</th>
                    <th>{{ __('payments.paid') }}</th>
                    <th>{{ __('payments.remaining') }}</th>
                    <th>{{ __('common.actions') }}</th>
                </x-slot:head>
                @foreach($enrollment->fees as $charge)
                    @php $feeBalance = $balance['fees'][$charge->id] ?? ['charged' => $charge->amount_minor, 'discount' => 0, 'paid' => 0, 'remaining' => $charge->amount_minor]; @endphp
                    <tr wire:key="charge-{{ $charge->id }}">
                        <td class="font-medium text-gray-900 dark:text-white">@if($charge->gradeFee->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $charge->gradeFee->feeType->name }}@endif</td>
                        <td><x-ui.money :minor="$feeBalance['charged']" /></td>
                        <td><x-ui.money :minor="$feeBalance['discount']" /></td>
                        <td><x-ui.money :minor="$feeBalance['paid']" /></td>
                        <td><x-ui.money :minor="$feeBalance['remaining']" /></td>
                        <td>
                            @if(! $charge->gradeFee->feeType->is_mandatory && ! $isEnded)
                                <x-ui.button variant="ghost" size="sm" wire:click="removeOptional({{ $charge->grade_fee_id }})" loading-target="removeOptional" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
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
                @if(! $isEnded)
                    <x-ui.button variant="ghost" size="sm" wire:click="removeDiscount" loading-target="removeDiscount" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('fees.remove_discount') }}</x-ui.button>
                @endif
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
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.paid') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['paid']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.total_due') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['owed']" /></dd></div>
                @if($balance['overpaid_outstanding'] > 0)
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.overpaid') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['overpaid_outstanding']" /></dd></div>
                @endif
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.received') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['cash_received']" /></dd></div>
            </dl>
            @if($isEnded)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.closed_without_payment') }}: <x-ui.money :minor="$balance['closed_without_payment']" /></p>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('payments.payment') }}</h2>
                @if(! $isEnded)
                    <x-ui.button variant="primary" size="sm" wire:click="openPaymentModal" :spinner="false">
                        <x-ui.icon name="plus" class="h-4 w-4" />
                        {{ __('payments.add_payment') }}
                    </x-ui.button>
                @endif
            </div>
        </x-slot:header>
        @if($isEnded)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('payments.errors.ended_no_payments') }}</p>
        @endif
        @if($lastTxnReused)
            <x-ui.alert type="warning">{{ __('payments.transaction_reused') }}</x-ui.alert>
        @endif
        @if($lastPaymentIds !== [])
            <p class="mt-3">
                <x-ui.button href="{{ route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => implode(',', $lastPaymentIds)]) }}" variant="secondary" size="sm" :spinner="false">{{ __('payments.print_receipt') }}</x-ui.button>
            </p>
        @endif
        @php
            $history = $enrollment->payments->sortByDesc('id');
            $selectedHistory = collect($selectedReceiptIds)->map(fn ($id) => (int) $id)->all();
        @endphp
        @if($history->isEmpty())
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.no_payments') }}</p>
        @else
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($history as $payment)
                    <li wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-700/40">
                        <input type="checkbox" wire:model.live="selectedReceiptIds" value="{{ $payment->id }}" aria-label="{{ $payment->reference() }}" class="h-4 w-4 shrink-0 rounded-sm border-gray-300 bg-gray-100 text-indigo-600 focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="font-mono text-gray-700 dark:text-gray-300" dir="ltr">{{ $payment->reference() }}</span>
                        <span class="text-gray-700 dark:text-gray-300">
                            @if($payment->isOverpayment())
                                {{ __('payments.overpaid') }}
                            @elseif($payment->enrollmentFee?->gradeFee?->feeType?->isTuition())
                                {{ __('fees.tuition') }}
                            @else
                                {{ $payment->enrollmentFee?->gradeFee?->feeType?->name }}
                            @endif
                        </span>
                        <x-ui.money :minor="$payment->amount_minor" />
                        <span class="text-gray-500 dark:text-gray-400">{{ $payment->method === \App\Enums\PaymentMethod::BankTransfer ? __('payments.bank_transfer') : __('payments.cash') }}</span>
                        @if($payment->isVoided())
                            <x-ui.badge color="red">{{ __('payments.void') }}: {{ $payment->void_reason }}</x-ui.badge>
                        @else
                            <x-ui.button href="{{ route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => (string) $payment->id]) }}" variant="ghost" size="sm" :spinner="false">{{ __('payments.print') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm" wire:click="openVoidModal('payment', {{ $payment->id }})" loading-target="confirmVoid" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('payments.void') }}</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($selectedHistory !== [])
                <p class="mt-3">
                    <x-ui.button href="{{ route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => implode(',', $selectedHistory)]) }}" variant="secondary" size="sm" :spinner="false">{{ __('payments.print_receipt') }}</x-ui.button>
                </p>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('payments.refunds') }}</h2>
                @if($balance['overpaid_outstanding'] > 0)
                    <x-ui.button variant="secondary" size="sm" wire:click="openRefundModal" :spinner="false">{{ __('payments.refund') }}</x-ui.button>
                @endif
            </div>
        </x-slot:header>
        <p class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-500 dark:text-gray-400">{{ __('payments.overpaid') }}:</span>
            <x-ui.money :minor="$balance['overpaid_outstanding']" />
        </p>
        @php $refundHistory = $enrollment->refunds->sortByDesc('id'); @endphp
        @if($refundHistory->isEmpty())
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.no_refunds') }}</p>
        @else
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($refundHistory as $refund)
                    <li wire:key="refund-{{ $refund->id }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-700/40">
                        <x-ui.money :minor="$refund->amount_minor" />
                        <span class="text-gray-500 dark:text-gray-400">{{ $refund->method === \App\Enums\PaymentMethod::BankTransfer ? __('payments.bank_transfer') : __('payments.cash') }}</span>
                        @if($refund->transaction_number !== null)
                            <span class="font-mono text-gray-500 dark:text-gray-400" dir="ltr">{{ $refund->transaction_number }}</span>
                        @endif
                        <span class="text-gray-500 dark:text-gray-400">{{ $refund->refunded_on?->format('Y-m-d') }}</span>
                        @if($refund->isVoided())
                            <x-ui.badge color="red">{{ __('payments.void') }}: {{ $refund->void_reason }}</x-ui.badge>
                        @else
                            <x-ui.button variant="ghost" size="sm" wire:click="openVoidModal('refund', {{ $refund->id }})" loading-target="confirmVoid" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('payments.void') }}</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if($showPaymentModal)
        <x-ui.modal :title="__('payments.add_payment')" close="closePaymentModal">
            <form wire:submit="savePayment" class="space-y-4">
                @if($errors->any())
                    <x-ui.alert type="error">
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('payments.method')" for="pay-method" required>
                        <x-ui.select id="pay-method" wire:model.live="payMethod">
                            <option value="cash">{{ __('payments.cash') }}</option>
                            <option value="bank_transfer">{{ __('payments.bank_transfer') }}</option>
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('payments.paid_on')" for="pay-date" required :error="$errors->first('paid_on')">
                        <x-ui.input id="pay-date" type="date" wire:model.blur="payDate" :error="$errors->first('paid_on')" required />
                    </x-ui.field>
                </div>
                @if($payMethod === 'bank_transfer')
                    <x-ui.field :label="__('payments.transaction_number')" for="pay-txn" required :error="$errors->first('transaction_number')">
                        <x-ui.input id="pay-txn" type="text" wire:model.live.debounce.500ms="payTxn" :error="$errors->first('transaction_number')" autocomplete="off" dir="ltr" required />
                    </x-ui.field>
                    @if($this->txnReuseWarning)
                        <x-ui.alert type="warning">{{ __('payments.transaction_reused') }}</x-ui.alert>
                    @endif
                @endif
                <x-ui.field :label="__('fees.note')" for="pay-note">
                    <x-ui.input id="pay-note" type="text" wire:model.blur="payNote" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('payments.amount')" for="pay-total" required :error="$errors->first('payTotal')">
                    <x-ui.input id="pay-total" type="text" wire:model.live.debounce.500ms="payTotal" :error="$errors->first('payTotal')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" required />
                </x-ui.field>
                @foreach($enrollment->fees as $charge)
                    @php $feeRemaining = $balance['fees'][$charge->id]['remaining'] ?? 0; @endphp
                    <x-ui.field :label="$charge->gradeFee->feeType->isTuition() ? __('fees.tuition') : $charge->gradeFee->feeType->name" for="pay-alloc-{{ $charge->grade_fee_id }}">
                        <div class="flex items-center gap-3">
                            <x-ui.input id="pay-alloc-{{ $charge->grade_fee_id }}" type="text" wire:model.live.debounce.500ms="payAlloc.{{ $charge->grade_fee_id }}" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" :disabled="$feeRemaining <= 0" />
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400"><x-ui.money :minor="max(0, $feeRemaining)" /></span>
                        </div>
                    </x-ui.field>
                @endforeach
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('payments.unallocated') }}:</span>
                    @if($this->payUnallocated === null)
                        <span class="text-gray-500 dark:text-gray-400">—</span>
                    @else
                        <x-ui.money :minor="max(0, $this->payUnallocated)" />
                    @endif
                </p>
                @if($this->payUnallocated !== null && $this->payUnallocated > 0)
                    <x-ui.checkbox wire:model.live="confirmOverpayment" :label="__('payments.record_overpayment').' ('. \App\Support\Money::format($this->payUnallocated) .')'" />
                @endif
                @if($this->payBlockReason() !== null)
                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ $this->payBlockReason() }}</p>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closePaymentModal" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="savePayment" loading-target="savePayment" wire:loading.attr="disabled" :disabled="$this->payBlockReason() !== null">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($showRefundModal)
        <x-ui.modal :title="__('payments.refund')" close="closeRefundModal">
            <form wire:submit="saveRefund" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('payments.overpaid') }}: <x-ui.money :minor="$balance['overpaid_outstanding']" /></p>
                <x-ui.field :label="__('payments.amount')" for="refund-amount" required :error="$errors->first('amount')">
                    <x-ui.input id="refund-amount" type="text" wire:model.blur="refundAmount" :error="$errors->first('amount')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" required />
                </x-ui.field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('payments.method')" for="refund-method" required>
                        <x-ui.select id="refund-method" wire:model.live="refundMethod">
                            <option value="cash">{{ __('payments.cash') }}</option>
                            <option value="bank_transfer">{{ __('payments.bank_transfer') }}</option>
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('payments.refunded_on')" for="refund-date" required :error="$errors->first('refunded_on')">
                        <x-ui.input id="refund-date" type="date" wire:model.blur="refundDate" :error="$errors->first('refunded_on')" required />
                    </x-ui.field>
                </div>
                @if($refundMethod === 'bank_transfer')
                    <x-ui.field :label="__('payments.transaction_number')" for="refund-txn" required :error="$errors->first('transaction_number')">
                        <x-ui.input id="refund-txn" type="text" wire:model.blur="refundTxn" :error="$errors->first('transaction_number')" autocomplete="off" dir="ltr" required />
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('fees.note')" for="refund-note" required :error="$errors->first('note')">
                    <x-ui.input id="refund-note" type="text" wire:model.blur="refundNote" :error="$errors->first('note')" autocomplete="off" required />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeRefundModal" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="saveRefund" loading-target="saveRefund" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($voidTargetType !== null && $voidTargetId !== null)
        <x-ui.modal :title="__('payments.void')" close="closeVoidModal">
            <p class="text-sm">{{ $voidTargetType === 'refund' ? __('payments.refund') : __('payments.payment') }}</p>
            <div class="mt-3">
                <x-ui.field :label="__('payments.void_reason')" for="void-reason" required :error="$errors->first('void_reason')">
                    <x-ui.input id="void-reason" type="text" wire:model.blur="voidReason" :error="$errors->first('void_reason')" autocomplete="off" required />
                </x-ui.field>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeVoidModal" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="confirmVoid" loading-target="confirmVoid" wire:loading.attr="disabled">{{ __('payments.void') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($statusTargetStatus && ($targetStatus = \App\Enums\EnrollmentStatus::tryFrom($statusTargetStatus)))
        @php
            $needsPreview = ! $enrollment->status->isCharged() && $targetStatus->isCharged();
        @endphp
        <x-ui.modal :title="__('enrollments.change_status')" close="$set('statusTargetStatus', null)">
            <p class="font-medium text-gray-900 dark:text-white">{{ __('enrollments.status_'.$statusTargetStatus) }}</p>
            @if($enrollment->status->isCharged() && $targetStatus && ! $targetStatus->isCharged() && $balance['owed'] > 0)
                <div class="mt-3"><x-ui.alert type="warning">{{ __('payments.will_no_longer_be_owed', ['amount' => \App\Support\Money::format($balance['owed'])]) }}</x-ui.alert></div>
            @endif
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
