<?php

use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Support\PaymentCalculation;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $enrollmentId;

    public ?string $statusModalNote = null;

    #[Locked]
    public ?string $statusTargetStatus = null;

    public function mount(int $enrollmentId): void
    {
        $this->enrollmentId = $enrollmentId;
    }

    #[Computed]
    public function enrollment(): Enrollment
    {
        return Enrollment::query()
            ->with([
                'fees.gradeFee.feeType',
                'fees.payments',
                'discount',
                'refunds',
            ])
            ->findOrFail($this->enrollmentId);
    }

    /** @return array{charged: int, discount: int, paid: int, refunded: int, net_paid: int, refundable: int, owed: int, closed_without_payment: int, cash_received: int, fees: array<int, array{fee_id: int, is_tuition: bool, charged: int, discount: int, paid: int, refunded: int, remaining: int, refundable: int}>} */
    #[Computed]
    public function balance(): array
    {
        return PaymentCalculation::balance($this->enrollment);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\GradeFee> */
    #[Computed]
    public function missingFees(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->statusTargetStatus === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return app(UpdateEnrollmentStatus::class)->missingFor($this->enrollmentId)->load('feeType:id,name,system_key');
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

        $action->handle($this->enrollmentId, $target, $this->statusModalNote);

        $this->statusTargetStatus = null;
        $this->statusModalNote = null;
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->missingFees);
        session()->flash('status', __('enrollments.status_changed'));
        $this->dispatch('enrollment-changed');
    }

    #[On('enrollment-changed')]
    public function handleEnrollmentChanged(): void
    {
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->missingFees);
    }
};
?>

@php
    $enrollment = $this->enrollment;
    $balance = $this->balance;
@endphp

<div class="space-y-6">
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-ink ">{{ __('enrollments.change_status') }}</h2>
            <p class="mt-1 text-sm text-muted ">{{ __('enrollments.status') }}: <x-ui.badge :status="$enrollment->status->value" /></p>
        </x-slot:header>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('enrollments.change_status') }}">
            @foreach(EnrollmentStatus::cases() as $case)
                @if($enrollment->status === $case)
                    <x-ui.button
                        wire:key="sp-status-{{ $case->value }}"
                        variant="{{ $case === EnrollmentStatus::Expelled ? 'danger' : 'primary' }}"
                        size="sm"
                        :spinner="false"
                        disabled
                        aria-pressed="true"
                    ><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" /></svg>{{ __('enrollments.status_'.$case->value) }}</x-ui.button>
                @else
                    <x-ui.button
                        wire:key="sp-status-{{ $case->value }}"
                        variant="secondary"
                        size="sm"
                        wire:click="openStatusModal('{{ $case->value }}')"
                        :spinner="false"
                    >{{ __('enrollments.status_'.$case->value) }}</x-ui.button>
                @endif
            @endforeach
        </div>
    </x-ui.card>

    @if($statusTargetStatus && ($targetStatus = EnrollmentStatus::tryFrom($statusTargetStatus)))
        @php
            $needsPreview = ! $enrollment->status->isCharged() && $targetStatus->isCharged();
        @endphp
        <x-ui.modal :title="__('enrollments.change_status')" close="$set('statusTargetStatus', null)">
            <p class="font-medium text-ink ">{{ __('enrollments.status_'.$statusTargetStatus) }}</p>
            @if($enrollment->status->isCharged() && $targetStatus && ! $targetStatus->isCharged() && $balance['owed'] > 0)
                <div class="mt-3"><x-ui.alert type="warning">{{ __('payments.will_no_longer_be_owed', ['amount' => \App\Support\Money::format($balance['owed'])]) }}</x-ui.alert></div>
            @endif
            @if($needsPreview)
                @if($this->missingFees->isNotEmpty())
                    <h3 class="mt-4 text-sm font-semibold text-ink ">{{ __('enrollments.missing_fees_title') }}</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach($this->missingFees as $missing)
                            <li wire:key="sp-missing-{{ $missing->id }}" class="flex items-center justify-between gap-2 rounded-sm bg-surface-raised px-3 py-2.5 ">
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
