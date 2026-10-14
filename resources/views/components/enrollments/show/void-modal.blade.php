<?php

use App\Actions\VoidPayment;
use App\Actions\VoidRefund;
use App\Support\PaymentCalculation;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $enrollmentId;

    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public ?string $targetType = null;

    public ?string $reason = null;

    public function mount(int $enrollmentId): void
    {
        $this->enrollmentId = $enrollmentId;
    }

    #[On('request-void')]
    public function openVoid(string $type, int $id): void
    {
        if ($type !== 'payment' && $type !== 'refund') {
            throw ValidationException::withMessages([
                'voidTargetType' => __('validation.in', ['attribute' => __('payments.void')]),
            ]);
        }

        $this->resetValidation();
        $this->targetType = $type;
        $this->targetId = $id;
        $this->reason = null;
    }

    public function closeVoidModal(): void
    {
        $this->resetValidation();
        $this->targetType = null;
        $this->targetId = null;
        $this->reason = null;
    }

    public function confirmVoid(VoidPayment $voidPayment, VoidRefund $voidRefund): void
    {
        if ($this->targetType === 'refund') {
            $voidRefund->handle($this->enrollmentId, (int) $this->targetId, $this->reason);
            session()->flash('status', __('payments.refund_voided'));
        } elseif ($this->targetType === 'payment') {
            $voidPayment->handle($this->enrollmentId, (int) $this->targetId, $this->reason);
            session()->flash('status', __('payments.payment_voided'));
        } else {
            throw ValidationException::withMessages([
                'voidTargetType' => __('validation.in', ['attribute' => __('payments.void')]),
            ]);
        }

        $this->closeVoidModal();
        PaymentCalculation::flush($this->enrollmentId);
        $this->dispatch('enrollment-changed');
    }

    #[On('enrollment-changed')]
    public function handleEnrollmentChanged(): void
    {
        $this->resetValidation();
    }
};
?>

<div>
    @if($targetType !== null && $targetId !== null)
        <x-ui.modal :title="__('payments.void')" close="closeVoidModal">
            <p class="text-sm">{{ $targetType === 'refund' ? __('payments.refund') : __('payments.payment') }}</p>
            <div class="mt-3">
                <x-ui.field :label="__('payments.void_reason')" for="void-reason" required :error="$errors->first('void_reason')">
                    <x-ui.input id="void-reason" type="text" wire:model.blur="reason" :error="$errors->first('void_reason')" autocomplete="off" required />
                </x-ui.field>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeVoidModal" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="confirmVoid" loading-target="confirmVoid" wire:loading.attr="disabled">{{ __('payments.void') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
