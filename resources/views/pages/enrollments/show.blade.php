<?php

use App\Models\Enrollment;
use App\Support\PaymentCalculation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Locked]
    public int $enrollmentId;

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
                'grade.school:id,name',
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
    $tuitionMinor = null;
    foreach ($balance['fees'] as $feeBalance) {
        if ($feeBalance['is_tuition']) {
            $tuitionMinor = ($tuitionMinor ?? 0) + $feeBalance['charged'];
        }
    }
    $netMinor = $tuitionMinor !== null ? $tuitionMinor - $balance['discount'] : null;
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
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.school') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $enrollment->grade->school->name }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.grade') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ __('schools.grade_number', ['number' => $enrollment->grade->number]) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.academic_year') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $enrollment->academicYear->name }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('enrollments.status') }}</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ __('enrollments.status_'.$enrollment->status->value) }}</dd></div>
        </dl>
    </x-ui.card>

    @if($netMinor !== null)
        <x-ui.card>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.tuition') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$tuitionMinor" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.discount') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['discount']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.tuition_net') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$netMinor" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.paid') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['paid']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.refunded') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['refunded']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.net_paid') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['net_paid']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('fees.total_due') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['owed']" /></dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('payments.received') }}</dt><dd class="font-medium text-gray-900 dark:text-white"><x-ui.money :minor="$balance['cash_received']" /></dd></div>
            </dl>
            @if($isEnded)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('payments.closed_without_payment') }}: <x-ui.money :minor="$balance['closed_without_payment']" /></p>
            @endif
        </x-ui.card>
    @endif

    <livewire:enrollments.show.status-panel :enrollment-id="$enrollment->id" wire:key="status-panel-{{ $enrollment->id }}" />

    <livewire:enrollments.show.charges-panel :enrollment-id="$enrollment->id" wire:key="charges-panel-{{ $enrollment->id }}" />

    <livewire:enrollments.show.payments-panel :enrollment-id="$enrollment->id" wire:key="payments-panel-{{ $enrollment->id }}" />

    <livewire:enrollments.show.refund-modal :enrollment-id="$enrollment->id" wire:key="refund-{{ $enrollment->id }}" />

    <livewire:enrollments.show.add-payment-modal :enrollment-id="$enrollment->id" wire:key="add-payment-{{ $enrollment->id }}" />

    <livewire:enrollments.show.void-modal :enrollment-id="$enrollment->id" wire:key="void-{{ $enrollment->id }}" />
</div>
