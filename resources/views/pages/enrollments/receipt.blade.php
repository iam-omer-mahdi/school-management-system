<?php

use App\Models\Enrollment;
use App\Models\Payment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::print')] class extends Component
{
    public int $enrollmentId;

    /** @var array<int> */
    public array $paymentIds = [];

    public function mount(Enrollment $enrollment): void
    {
        $this->enrollmentId = $enrollment->id;

        $ids = collect(explode(',', (string) request()->query('payments', '')))
            ->map(fn ($value) => (int) trim((string) $value))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $rows = Payment::query()
            ->whereIn('id', $ids->all())
            ->with('enrollmentFee.feeType:id,name,system_key')
            ->orderBy('id')
            ->get();

        abort_if($rows->count() !== $ids->count(), 404);

        abort_if($rows->contains(fn (Payment $payment) => (int) $payment->enrollment_id !== (int) $enrollment->id), 404);

        $this->paymentIds = $rows->pluck('id')->all();
    }

    #[Computed]
    public function enrollment(): Enrollment
    {
        return Enrollment::query()
            ->with([
                'student:id,student_number,name',
                'academicYear:id,name',
                'grade:id,school_id,number',
                'grade.school:id,code,name',
            ])
            ->findOrFail($this->enrollmentId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Payment> */
    #[Computed]
    public function payments(): \Illuminate\Database\Eloquent\Collection
    {
        return Payment::query()
            ->whereIn('id', $this->paymentIds)
            ->with('enrollmentFee.feeType:id,name,system_key')
            ->orderBy('id')
            ->get();
    }
};
?>

@php
    $enrollment = $this->enrollment;
    $payments = $this->payments;
    $validTotal = $payments->reject(fn ($payment) => $payment->isVoided())->sum('amount_minor');
@endphp

<div class="space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ __('payments.receipt') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('schools.'.$enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }} · {{ $enrollment->academicYear->name }}</p>
        </div>
        <div class="print-actions flex shrink-0 gap-2">
            <x-ui.button variant="primary" size="sm" onclick="window.print()" :spinner="false">{{ __('payments.print') }}</x-ui.button>
            <x-ui.button href="{{ route('enrollments.show', $enrollment) }}" wire:navigate variant="secondary" size="sm" :spinner="false">{{ __('common.back') }}</x-ui.button>
        </div>
    </div>

    <dl class="grid grid-cols-1 gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt class="text-gray-500">{{ __('students.name') }}</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $enrollment->student->name }}</dd></div>
        <div><dt class="text-gray-500">{{ __('students.student_number') }}</dt><dd class="mt-0.5 font-medium text-gray-900" dir="ltr">{{ $enrollment->student->student_number }}</dd></div>
    </dl>

    <ul class="space-y-2 text-sm">
        @foreach($payments as $payment)
            <li wire:key="receipt-{{ $payment->id }}" class="rounded-lg border border-gray-200 px-3 py-2.5">
                @if($payment->isVoided())
                    <p class="mb-1 text-sm font-bold uppercase tracking-wider text-red-700">{{ __('payments.void') }}@if($payment->void_reason): {{ $payment->void_reason }}@endif</p>
                @endif
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium text-gray-900">
                        @if($payment->isOverpayment())
                            {{ __('payments.overpaid') }}
                        @elseif($payment->enrollmentFee?->gradeFee?->feeType?->isTuition())
                            {{ __('fees.tuition') }}
                        @else
                            {{ $payment->enrollmentFee?->gradeFee?->feeType?->name }}
                        @endif
                    </span>
                    <x-ui.money :minor="$payment->amount_minor" />
                </div>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                    <span class="font-mono" dir="ltr">{{ $payment->reference() }}</span>
                    <span>{{ $payment->method === \App\Enums\PaymentMethod::BankTransfer ? __('payments.bank_transfer') : __('payments.cash') }}</span>
                    @if($payment->transaction_number !== null)
                        <span class="font-mono" dir="ltr">{{ $payment->transaction_number }}</span>
                    @endif
                    <span dir="ltr">{{ $payment->paid_on?->format('Y-m-d') }}</span>
                </div>
            </li>
        @endforeach
    </ul>

    <p class="flex items-center justify-between border-t border-gray-200 pt-4 text-base font-bold text-gray-900">
        <span>{{ __('payments.total') }}</span>
        <x-ui.money :minor="$validTotal" />
    </p>
</div>
