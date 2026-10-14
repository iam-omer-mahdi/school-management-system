<?php

use App\Actions\RecordRefund;
use App\Livewire\Forms\RefundForm;
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

    public RefundForm $form;

    public bool $showModal = false;

    public function mount(int $enrollmentId): void
    {
        $this->enrollmentId = $enrollmentId;
        $this->form->date = date('Y-m-d');
    }

    #[Computed]
    public function enrollment(): Enrollment
    {
        return Enrollment::query()
            ->with([
                'fees.gradeFee.feeType',
                'fees.payments',
                'discount',
                'refunds.enrollmentFee.gradeFee.feeType:id,name,system_key',
            ])
            ->findOrFail($this->enrollmentId);
    }

    /** @return array{charged: int, discount: int, paid: int, refunded: int, net_paid: int, refundable: int, owed: int, closed_without_payment: int, cash_received: int, fees: array<int, array{fee_id: int, is_tuition: bool, charged: int, discount: int, paid: int, refunded: int, remaining: int, refundable: int}>} */
    #[Computed]
    public function balance(): array
    {
        return PaymentCalculation::balance($this->enrollment);
    }

    #[Computed]
    public function parsedTotal(): ?int
    {
        return $this->form->parsedTotal();
    }

    /** @return array<int, int>|null */
    #[Computed]
    public function parsedAlloc(): ?array
    {
        return $this->form->parsedAlloc();
    }

    /** @return array<int, int> */
    #[Computed]
    public function refundRemaining(): array
    {
        return PaymentCalculation::refundableByGradeFee($this->balance, $this->enrollment->fees);
    }

    #[Computed]
    public function refundUnallocated(): ?int
    {
        return PaymentCalculation::unallocated($this->parsedTotal, $this->parsedAlloc);
    }

    #[Computed]
    public function refundBlockReason(): ?string
    {
        return PaymentCalculation::refundBlockReason(
            $this->enrollment->isEnded(),
            $this->parsedTotal,
            $this->parsedAlloc,
            $this->refundRemaining,
            $this->form->method,
            $this->form->txn,
            $this->form->note,
        );
    }

    public function updated(string $property): void
    {
        if ($property === 'form.method') {
            $this->form->txn = null;
            $this->resetValidation(['form.txn', 'transaction_number']);
        }

        unset($this->parsedTotal, $this->parsedAlloc, $this->refundUnallocated, $this->refundBlockReason, $this->refundRemaining);
    }

    public function openRefundModal(): void
    {
        $this->resetValidation();
        $this->form->resetForm(date('Y-m-d'));
        unset($this->refundBlockReason, $this->parsedTotal, $this->parsedAlloc, $this->refundUnallocated, $this->refundRemaining, $this->balance, $this->enrollment);
        $this->showModal = true;
    }

    public function closeRefundModal(): void
    {
        $this->resetValidation();
        $this->showModal = false;
    }

    public function requestVoid(string $type, int $id): void
    {
        if ($type !== 'payment' && $type !== 'refund') {
            throw ValidationException::withMessages([
                'voidTargetType' => __('validation.in', ['attribute' => __('payments.void')]),
            ]);
        }

        $this->dispatch('request-void', type: $type, id: $id);
    }

    public function saveRefund(RecordRefund $action): void
    {
        $total = $this->form->parsedTotal();
        $allocs = $this->form->parsedAlloc();

        if ($total === null || $total <= 0 || $allocs === null) {
            $this->addError('form.total', __('validation.numeric', ['attribute' => __('payments.amount')]));

            return;
        }

        $action->handle([
            'enrollment_id' => $this->enrollmentId,
            'total_minor' => $total,
            'rows' => $this->form->rows(),
            'method' => $this->form->method,
            'transaction_number' => $this->form->txn,
            'refunded_on' => $this->form->date ?? date('Y-m-d'),
            'note' => $this->form->note,
        ]);

        $this->showModal = false;
        $this->form->reset(['total', 'alloc', 'txn', 'note']);
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->refundBlockReason, $this->parsedTotal, $this->parsedAlloc, $this->refundUnallocated, $this->refundRemaining);
        session()->flash('status', __('payments.refund_recorded'));
        $this->dispatch('enrollment-changed');
    }

    #[On('enrollment-changed')]
    public function handleEnrollmentChanged(): void
    {
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->refundRemaining, $this->parsedTotal, $this->parsedAlloc, $this->refundUnallocated, $this->refundBlockReason);
    }
};
?>

@php
    $enrollment = $this->enrollment;
    $balance = $this->balance;
    $isEnded = $enrollment->isEnded();
@endphp

<div>
    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('payments.refunds') }}</h2>
                @if($isEnded && $balance['refundable'] > 0)
                    <x-ui.button variant="secondary" size="sm" wire:click="openRefundModal" :spinner="false">{{ __('payments.refund') }}</x-ui.button>
                @endif
            </div>
        </x-slot:header>
        <p class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-500 dark:text-gray-400">{{ __('payments.refundable') }}:</span>
            <x-ui.money :minor="$balance['refundable']" />
        </p>
        @php $refundHistory = $enrollment->refunds->sortByDesc('id'); @endphp
        @if($refundHistory->isEmpty())
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.no_refunds') }}</p>
        @else
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($refundHistory as $refund)
                    <li wire:key="rm-refund-{{ $refund->id }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-700/40">
                        <span class="text-gray-700 dark:text-gray-300">
                            @if($refund->enrollmentFee?->gradeFee?->feeType?->isTuition())
                                {{ __('fees.tuition') }}
                            @else
                                {{ $refund->enrollmentFee?->gradeFee?->feeType?->name }}
                            @endif
                        </span>
                        <x-ui.money :minor="$refund->amount_minor" />
                        <span class="text-gray-500 dark:text-gray-400">{{ $refund->method === \App\Enums\PaymentMethod::BankTransfer ? __('payments.bank_transfer') : __('payments.cash') }}</span>
                        @if($refund->transaction_number !== null)
                            <span class="font-mono text-gray-500 dark:text-gray-400" dir="ltr">{{ $refund->transaction_number }}</span>
                        @endif
                        <span class="text-gray-500 dark:text-gray-400">{{ $refund->refunded_on?->format('Y-m-d') }}</span>
                        @if($refund->isVoided())
                            <x-ui.badge color="red">{{ __('payments.void') }}: {{ $refund->void_reason }}</x-ui.badge>
                        @else
                            <x-ui.button variant="ghost" size="sm" wire:click="requestVoid('refund', {{ $refund->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('payments.void') }}</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if($showModal)
        <x-ui.modal :title="__('payments.refund')" close="closeRefundModal">
            <form wire:submit="saveRefund" class="space-y-4">
                @if($errors->any())
                    <x-ui.alert type="error">
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('payments.refundable') }}: <x-ui.money :minor="$balance['refundable']" /></p>
                <x-ui.field :label="__('payments.amount')" for="refund-total" required :error="$errors->first('form.total')">
                    <x-ui.input id="refund-total" type="text" wire:model.live.debounce.500ms="form.total" :error="$errors->first('form.total')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" required />
                </x-ui.field>
                @foreach($enrollment->fees as $charge)
                    @php $feeRefundable = $balance['fees'][$charge->id]['refundable'] ?? 0; @endphp
                    @if($feeRefundable > 0)
                        <x-ui.field :label="$charge->gradeFee->feeType->isTuition() ? __('fees.tuition') : $charge->gradeFee->feeType->name" for="refund-alloc-{{ $charge->grade_fee_id }}">
                            <div class="flex items-center gap-3">
                                <x-ui.input id="refund-alloc-{{ $charge->grade_fee_id }}" type="text" wire:model.live.debounce.500ms="form.alloc.{{ $charge->grade_fee_id }}" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" />
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400"><x-ui.money :minor="$feeRefundable" /></span>
                            </div>
                        </x-ui.field>
                    @endif
                @endforeach
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('payments.unallocated') }}:</span>
                    @if($this->refundUnallocated === null)
                        <span class="text-gray-500 dark:text-gray-400">—</span>
                    @else
                        <x-ui.money :minor="max(0, $this->refundUnallocated)" />
                    @endif
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('payments.method')" for="refund-method" required>
                        <x-ui.select id="refund-method" wire:model.live="form.method">
                            <option value="cash">{{ __('payments.cash') }}</option>
                            <option value="bank_transfer">{{ __('payments.bank_transfer') }}</option>
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('payments.refunded_on')" for="refund-date" required :error="$errors->first('refunded_on')">
                        <x-ui.input id="refund-date" type="date" wire:model.blur="form.date" :error="$errors->first('refunded_on')" required />
                    </x-ui.field>
                </div>
                @if($form->method === 'bank_transfer')
                    <x-ui.field :label="__('payments.transaction_number')" for="refund-txn" required :error="$errors->first('transaction_number')">
                        <x-ui.input id="refund-txn" type="text" wire:model.blur="form.txn" :error="$errors->first('transaction_number')" autocomplete="off" dir="ltr" required />
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('fees.note')" for="refund-note" required :error="$errors->first('note')">
                    <x-ui.input id="refund-note" type="text" wire:model.blur="form.note" :error="$errors->first('note')" autocomplete="off" required />
                </x-ui.field>
                @if($this->refundBlockReason !== null)
                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ $this->refundBlockReason }}</p>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeRefundModal" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="saveRefund" loading-target="saveRefund" wire:loading.attr="disabled" :disabled="$this->refundBlockReason !== null">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
