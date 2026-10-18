<?php

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\GradeFee;
use App\Support\Money;
use App\Support\PaymentCalculation;
use App\Support\Percent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $enrollmentId;

    public ?string $discountChoice = null;

    public ?string $optionalChoice = null;

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

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'discountChoice' => __('fees.discount'),
            'optionalChoice' => __('fees.fee_type'),
        ];
    }

    public function applyDiscount(ApplyDiscountToEnrollment $action): void
    {
        $this->validate(['discountChoice' => ['required', 'exists:discounts,id']]);

        $action->handle($this->enrollmentId, (int) $this->discountChoice);
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->optionalOptions);
        session()->flash('status', __('fees.discount_applied'));
        $this->dispatch('enrollment-changed');
    }

    public function removeDiscount(RemoveEnrollmentDiscount $action): void
    {
        $action->handle($this->enrollmentId);
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance);
        session()->flash('status', __('fees.discount_removed'));
        $this->dispatch('enrollment-changed');
    }

    public function addOptional(AddOptionalFeeToEnrollment $action): void
    {
        $this->validate(['optionalChoice' => ['required', 'exists:grade_fees,id']]);

        $action->handle($this->enrollmentId, (int) $this->optionalChoice);
        $this->reset('optionalChoice');
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->optionalOptions);
        session()->flash('status', __('fees.charge_added'));
        $this->dispatch('enrollment-changed');
    }

    public function removeOptional(RemoveOptionalFeeFromEnrollment $action, int $gradeFeeId): void
    {
        $action->handle($this->enrollmentId, $gradeFeeId);
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->optionalOptions);
        session()->flash('status', __('fees.charge_removed'));
        $this->dispatch('enrollment-changed');
    }

    #[On('enrollment-changed')]
    public function handleEnrollmentChanged(): void
    {
        PaymentCalculation::flush($this->enrollmentId);
        unset($this->enrollment, $this->balance, $this->optionalOptions, $this->catalogDiscounts);
    }
};
?>

@php
    $enrollment = $this->enrollment;
    $balance = $this->balance;
    $isEnded = $enrollment->isEnded();
    $appliedDiscount = $enrollment->discount;
    $choice = $this->catalogDiscounts->firstWhere('id', (int) ($discountChoice ?? 0));
    $tuitionMinor = null;
    foreach ($balance['fees'] as $feeBalance) {
        if ($feeBalance['is_tuition']) {
            $tuitionMinor = ($tuitionMinor ?? 0) + $feeBalance['charged'];
        }
    }
    $previewMinor = ($tuitionMinor !== null && $choice) ? Money::percentOf($tuitionMinor, $choice->percent_bp) : null;
    $options = $this->optionalOptions;
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
                    <th>{{ __('common.actions') }}</th>
                </x-slot:head>
                @foreach($enrollment->fees as $charge)
                    @php $feeBalance = $balance['fees'][$charge->id] ?? ['charged' => $charge->amount_minor, 'discount' => 0, 'paid' => 0, 'refunded' => 0, 'remaining' => $charge->amount_minor]; @endphp
                    <tr wire:key="cp-charge-{{ $charge->id }}">
                        <td class="font-medium text-gray-900 dark:text-white">@if($charge->gradeFee->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $charge->gradeFee->feeType->name }}@endif</td>
                        <td><x-ui.money :minor="$feeBalance['charged']" /></td>
                        <td><x-ui.money :minor="$feeBalance['discount']" /></td>
                        <td><x-ui.money :minor="$feeBalance['paid']" /></td>
                        <td><x-ui.money :minor="$feeBalance['refunded']" /></td>
                        <td><x-ui.money :minor="$feeBalance['remaining']" /></td>
                        <td>
                            @if(! $charge->gradeFee->feeType->is_mandatory && ! $isEnded)
                                @can(\App\Enums\Permission::EnrollmentsManage->value)
                                    <x-ui.button variant="ghost" size="sm" wire:click="removeOptional({{ $charge->grade_fee_id }})" loading-target="removeOptional" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                                @endcan
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
                            <option value="{{ $option->id }}">@if($option->feeType->isTuition()){{ __('fees.tuition') }}@else{{ $option->feeType->name }}@endif — {{ Money::format($option->amount_minor) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @can(\App\Enums\Permission::EnrollmentsManage->value)
                    <x-ui.button variant="secondary" size="sm" wire:click="addOptional" loading-target="addOptional" wire:loading.attr="disabled">{{ __('fees.add') }}</x-ui.button>
                @endcan
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
                    @can(\App\Enums\Permission::EnrollmentsManage->value)
                        <x-ui.button variant="ghost" size="sm" wire:click="removeDiscount" loading-target="removeDiscount" wire:loading.attr="disabled" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('fees.remove_discount') }}</x-ui.button>
                    @endcan
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
                            <option value="{{ $catalog->id }}">{{ $catalog->name }} ({{ Percent::format($catalog->percent_bp) }})</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @can(\App\Enums\Permission::EnrollmentsManage->value)
                    <x-ui.button variant="secondary" size="sm" wire:click="applyDiscount" loading-target="applyDiscount" wire:loading.attr="disabled">{{ $appliedDiscount ? __('fees.replace_discount') : __('fees.apply_discount') }}</x-ui.button>
                @endcan
            </div>
            @if($previewMinor !== null && $choice)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400"><x-ui.money :minor="$previewMinor" /> <span aria-hidden="true">→</span> {{ __('fees.tuition_net') }} <x-ui.money :minor="$tuitionMinor - $previewMinor" /></p>
            @endif
        @endif
        @if($errors->first('discount_id'))
            <p class="mt-2 text-sm text-red-600 dark:text-red-500" role="alert">{{ $errors->first('discount_id') }}</p>
        @endif
    </x-ui.card>
</div>
