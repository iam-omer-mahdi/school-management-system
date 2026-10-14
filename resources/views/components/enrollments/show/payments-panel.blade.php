<?php

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

    /** @var array<int, string> */
    public array $selectedReceiptIds = [];

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
                'payments.enrollmentFee.gradeFee.feeType:id,name,system_key',
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

    public function requestVoid(string $type, int $id): void
    {
        if ($type !== 'payment' && $type !== 'refund') {
            throw ValidationException::withMessages([
                'voidTargetType' => __('validation.in', ['attribute' => __('payments.void')]),
            ]);
        }

        $this->dispatch('request-void', type: $type, id: $id);
    }

    #[On('enrollment-changed')]
    public function handleEnrollmentChanged(): void
    {
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance);
    }
};
?>

@php
    $enrollment = $this->enrollment;
    $balance = $this->balance;
    $isEnded = $enrollment->isEnded();
@endphp

<div class="space-y-6">
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
                    <th>{{ __('payments.refunded') }}</th>
                    <th>{{ __('payments.remaining') }}</th>
                </x-slot:head>
                @foreach($enrollment->fees as $charge)
                    @php $feeBalance = $balance['fees'][$charge->id] ?? ['charged' => $charge->amount_minor, 'discount' => 0, 'paid' => 0, 'refunded' => 0, 'remaining' => $charge->amount_minor]; @endphp
                    <tr wire:key="pp-charge-{{ $charge->id }}">
                        <td class="font-medium text-gray-900 dark:text-white">@if($charge->gradeFee->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $charge->gradeFee->feeType->name }}@endif</td>
                        <td><x-ui.money :minor="$feeBalance['charged']" /></td>
                        <td><x-ui.money :minor="$feeBalance['discount']" /></td>
                        <td><x-ui.money :minor="$feeBalance['paid']" /></td>
                        <td><x-ui.money :minor="$feeBalance['refunded']" /></td>
                        <td><x-ui.money :minor="$feeBalance['remaining']" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
        @if($isEnded)
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.closed_without_payment') }}: <x-ui.money :minor="$balance['closed_without_payment']" /></p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.net_paid') }}: <x-ui.money :minor="$balance['net_paid']" /> · {{ __('fees.total_due') }}: <x-ui.money :minor="$balance['owed']" /></p>
        @endif
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('payments.payment') }}</h2>
        </x-slot:header>
        @php
            $history = $enrollment->payments->sortByDesc('id');
            $voidedHistoryIds = $history->filter(fn ($payment) => $payment->isVoided())->map(fn ($payment) => $payment->id)->all();
            $selectedHistory = collect($selectedReceiptIds)->map(fn ($id) => (int) $id)->reject(fn ($id) => in_array($id, $voidedHistoryIds, true))->values()->all();
        @endphp
        @if($history->isEmpty())
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.no_payments') }}</p>
        @else
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($history as $payment)
                    <li wire:key="pp-payment-{{ $payment->id }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-700/40">
                        <input type="checkbox" wire:model.live="selectedReceiptIds" value="{{ $payment->id }}" @disabled($payment->isVoided()) aria-label="{{ $payment->reference() }}" class="h-4 w-4 shrink-0 rounded-sm border-gray-300 bg-gray-100 text-indigo-600 focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="font-mono text-gray-700 dark:text-gray-300" dir="ltr">{{ $payment->reference() }}</span>
                        <span class="text-gray-700 dark:text-gray-300">
                            @if($payment->enrollmentFee?->gradeFee?->feeType?->isTuition())
                                {{ __('fees.tuition') }}
                            @else
                                {{ $payment->enrollmentFee?->gradeFee?->feeType?->name }}
                            @endif
                        </span>
                        <x-ui.money :minor="$payment->amount_minor" />
                        <span class="text-gray-500 dark:text-gray-400">{{ $payment->method === \App\Enums\PaymentMethod::BankTransfer ? __('payments.bank_transfer') : __('payments.cash') }}</span>
                        <x-ui.button href="{{ route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => (string) $payment->id]) }}" variant="ghost" size="sm" :spinner="false">{{ __('payments.print') }}</x-ui.button>
                        @if($payment->isVoided())
                            <x-ui.badge color="red">{{ __('payments.void') }}: {{ $payment->void_reason }}</x-ui.badge>
                        @else
                            <x-ui.button variant="ghost" size="sm" wire:click="requestVoid('payment', {{ $payment->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('payments.void') }}</x-ui.button>
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
</div>
