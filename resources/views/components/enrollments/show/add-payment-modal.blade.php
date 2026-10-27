<?php

use App\Actions\RecordPayment;
use App\Livewire\Forms\PaymentForm;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Support\PaymentCalculation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $enrollmentId;

    public PaymentForm $form;

    public bool $showModal = false;

    public bool $lastTxnReused = false;

    /** @var array<int> */
    #[Locked]
    public array $lastPaymentIds = [];

    public function mount(int $enrollmentId): void
    {
        $this->enrollmentId = $enrollmentId;
        $this->form->date = date('Y-m-d');
    }

    #[Computed]
    public function enrollment(): Enrollment
    {
        return Enrollment::query()->with(['fees.gradeFee.feeType', 'fees.payments', 'discount', 'refunds'])->findOrFail($this->enrollmentId);
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
    public function payRemaining(): array
    {
        return PaymentCalculation::remainingByGradeFee($this->balance, $this->enrollment->fees);
    }

    #[Computed]
    public function payUnallocated(): ?int
    {
        return PaymentCalculation::unallocated($this->parsedTotal, $this->parsedAlloc);
    }

    #[Computed]
    public function paymentDisabledReason(): ?string
    {
        return PaymentCalculation::paymentDisabledReason($this->enrollment->isEnded(), $this->enrollment->fees->isNotEmpty(), array_sum($this->payRemaining));
    }

    #[Computed]
    public function payBlockReason(): ?string
    {
        return PaymentCalculation::payBlockReason($this->parsedTotal, $this->parsedAlloc, $this->payRemaining, $this->form->method, $this->form->txn);
    }

    #[Computed]
    public function txnReuseWarning(): bool
    {
        if (! $this->showModal || $this->form->method !== 'bank_transfer' || trim((string) $this->form->txn) === '') {
            return false;
        }

        return Payment::query()
            ->where('transaction_number', trim((string) $this->form->txn))
            ->where('enrollment_id', '!=', $this->enrollmentId)
            ->whereNull('voided_at')
            ->exists();
    }

    public function updated(string $property): void
    {
        if ($property === 'form.method') {
            $this->form->txn = null;
            $this->resetValidation(['form.txn', 'transaction_number']);
            unset($this->txnReuseWarning);
        }

        unset($this->parsedTotal, $this->parsedAlloc, $this->payUnallocated, $this->payBlockReason, $this->payRemaining, $this->paymentDisabledReason);
    }

    #[On('open-payment-modal')]
    public function openPaymentModal(): void
    {
        $this->resetValidation();
        $this->form->resetForm(date('Y-m-d'));
        $this->lastTxnReused = false;
        $this->lastPaymentIds = [];
        unset($this->txnReuseWarning, $this->payUnallocated, $this->parsedTotal, $this->parsedAlloc, $this->payBlockReason, $this->payRemaining, $this->paymentDisabledReason, $this->balance, $this->enrollment);
        $this->showModal = true;
    }

    public function closePaymentModal(): void
    {
        $this->resetValidation();
        $this->showModal = false;
    }

    public function savePayment(RecordPayment $action): void
    {
        $total = $this->form->parsedTotal();
        $allocs = $this->form->parsedAlloc();

        if ($total === null || $total <= 0 || $allocs === null) {
            $this->addError('form.total', __('validation.numeric', ['attribute' => __('payments.amount')]));

            return;
        }

        $result = $action->handle([
            'enrollment_id' => $this->enrollmentId,
            'total_minor' => $total,
            'rows' => $this->form->rows(),
            'method' => $this->form->method,
            'transaction_number' => $this->form->txn,
            'paid_on' => $this->form->date ?? date('Y-m-d'),
            'note' => $this->form->note,
        ]);

        $this->lastPaymentIds = $result['payments']->pluck('id')->all();
        $this->lastTxnReused = $result['transaction_reused'];
        $this->showModal = false;
        $this->form->reset(['total', 'alloc']);
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->payUnallocated, $this->parsedTotal, $this->parsedAlloc, $this->txnReuseWarning, $this->payBlockReason, $this->payRemaining, $this->paymentDisabledReason);
        session()->flash('status', __('payments.payment_recorded'));
        $this->dispatch('enrollment-changed');
    }

    #[On('enrollment-changed')]
    public function handleEnrollmentChanged(): void
    {
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->payRemaining, $this->parsedTotal, $this->parsedAlloc, $this->payUnallocated, $this->payBlockReason, $this->paymentDisabledReason, $this->txnReuseWarning);
    }
};
?>

@php
    $enrollment = $this->enrollment;
    $balance = $this->balance;
    $isEnded = $enrollment->isEnded();
@endphp

<div>
    @if($lastTxnReused || $lastPaymentIds !== [])
        <x-ui.card>
            @if($lastTxnReused)
                <x-ui.alert type="warning">{{ __('payments.transaction_reused') }}</x-ui.alert>
            @endif
            @if($lastPaymentIds !== [])
                <p class="mt-3">
                    <x-ui.button href="{{ route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => implode(',', $lastPaymentIds)]) }}" variant="secondary" size="sm" :spinner="false">{{ __('payments.print_receipt') }}</x-ui.button>
                </p>
            @endif
        </x-ui.card>
    @endif

    @if($showModal)
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
                        <x-ui.select id="pay-method" wire:model.live="form.method">
                            <option value="cash">{{ __('payments.cash') }}</option>
                            <option value="bank_transfer">{{ __('payments.bank_transfer') }}</option>
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('payments.paid_on')" for="pay-date" required :error="$errors->first('paid_on')">
                        <x-ui.input id="pay-date" type="date" wire:model.blur="form.date" :error="$errors->first('paid_on')" required />
                    </x-ui.field>
                </div>
                @if($form->method === 'bank_transfer')
                    <x-ui.field :label="__('payments.transaction_number')" for="pay-txn" required :error="$errors->first('transaction_number')">
                        <x-ui.input id="pay-txn" type="text" wire:model.live.debounce.500ms="form.txn" :error="$errors->first('transaction_number')" autocomplete="off" dir="ltr" required />
                    </x-ui.field>
                    @if($this->txnReuseWarning)
                        <x-ui.alert type="warning">{{ __('payments.transaction_reused') }}</x-ui.alert>
                    @endif
                @endif
                <x-ui.field :label="__('fees.note')" for="pay-note">
                    <x-ui.input id="pay-note" type="text" wire:model.blur="form.note" autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('payments.amount')" for="pay-total" required :error="$errors->first('form.total')">
                    <x-ui.input id="pay-total" type="text" wire:model.live.debounce.500ms="form.total" :error="$errors->first('form.total')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" data-money required />
                </x-ui.field>
                @foreach($enrollment->fees as $charge)
                    @php $feeRemaining = $balance['fees'][$charge->id]['remaining'] ?? 0; @endphp
                    <x-ui.field :label="$charge->gradeFee->feeType->isTuition() ? __('fees.tuition') : $charge->gradeFee->feeType->name" for="pay-alloc-{{ $charge->grade_fee_id }}">
                        <div class="flex items-center gap-3">
                            <x-ui.input id="pay-alloc-{{ $charge->grade_fee_id }}" type="text" wire:model.live.debounce.500ms="form.alloc.{{ $charge->grade_fee_id }}" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" data-money :disabled="$feeRemaining <= 0" />
                            <span class="shrink-0 text-xs text-muted "><x-ui.money :minor="max(0, $feeRemaining)" /></span>
                        </div>
                    </x-ui.field>
                @endforeach
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-muted ">{{ __('payments.unallocated') }}:</span>
                    @if($this->payUnallocated === null)
                        <span class="text-muted ">—</span>
                    @else
                        <x-ui.money :minor="max(0, $this->payUnallocated)" />
                    @endif
                </p>
                @if($this->payBlockReason !== null)
                    <p class="text-sm text-warning ">{{ $this->payBlockReason }}</p>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closePaymentModal" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="savePayment" loading-target="savePayment" wire:loading.attr="disabled" :disabled="$this->payBlockReason !== null">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
