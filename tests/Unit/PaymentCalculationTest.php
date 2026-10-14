<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PaymentCalculation;
use Tests\TestCase;

final class PaymentCalculationTest extends TestCase
{
    public function test_parse_allocations_skips_blanks_and_rejects_invalid(): void
    {
        $this->assertSame([1 => 50000, 2 => 15000], PaymentCalculation::parseAllocations([1 => '500', 2 => '150', 3 => '  ']));
        $this->assertNull(PaymentCalculation::parseAllocations([1 => 'abc']));
        $this->assertNull(PaymentCalculation::parseTotal('abc'));
        $this->assertSame(650050, PaymentCalculation::parseTotal('6,500.50'));
    }

    public function test_unallocated_is_total_minus_sum(): void
    {
        $this->assertSame(0, PaymentCalculation::unallocated(65000, [1 => 50000, 2 => 15000]));
        $this->assertSame(10000, PaymentCalculation::unallocated(75000, [1 => 65000]));
        $this->assertNull(PaymentCalculation::unallocated(null, [1 => 100]));
        $this->assertNull(PaymentCalculation::unallocated(100, null));
    }

    public function test_pay_block_reason_covers_mismatch_cap_and_txn(): void
    {
        $remaining = [1 => 180000];

        $this->assertSame(
            __('payments.errors.total_mismatch'),
            PaymentCalculation::payBlockReason(180000, [1 => 100000], $remaining, 'cash', null)
        );

        $this->assertSame(
            __('payments.errors.amount_exceeds_remaining'),
            PaymentCalculation::payBlockReason(200000, [1 => 200000], $remaining, 'cash', null)
        );

        $this->assertSame(
            __('validation.required', ['attribute' => __('payments.transaction_number')]),
            PaymentCalculation::payBlockReason(100000, [1 => 100000], [1 => 100000], 'bank_transfer', '')
        );

        $this->assertNull(
            PaymentCalculation::payBlockReason(100000, [1 => 100000], [1 => 100000], 'bank_transfer', 'TXN-1')
        );

        $this->assertSame(
            __('validation.numeric', ['attribute' => __('payments.amount')]),
            PaymentCalculation::payBlockReason(null, [], $remaining, 'cash', null)
        );
    }

    public function test_refund_block_reason_covers_ended_cap_and_note(): void
    {
        $this->assertSame(
            __('payments.errors.refund_charged_only'),
            PaymentCalculation::refundBlockReason(false, 10000, [1 => 10000], [1 => 10000], 'cash', null, 'Note')
        );

        $this->assertSame(
            __('payments.errors.refund_amount_exceeds_refundable'),
            PaymentCalculation::refundBlockReason(true, 50000, [1 => 50000], [1 => 40000], 'cash', null, 'Note')
        );

        $this->assertSame(
            __('validation.required', ['attribute' => __('fees.note')]),
            PaymentCalculation::refundBlockReason(true, 10000, [1 => 10000], [1 => 40000], 'cash', null, '  ')
        );

        $this->assertNull(
            PaymentCalculation::refundBlockReason(true, 10000, [1 => 10000], [1 => 40000], 'cash', null, 'Return')
        );
    }

    public function test_payment_disabled_reason(): void
    {
        $this->assertSame(__('payments.errors.ended_no_payments'), PaymentCalculation::paymentDisabledReason(true, true, 100));
        $this->assertSame(__('payments.errors.no_charges'), PaymentCalculation::paymentDisabledReason(false, false, 0));
        $this->assertSame(__('payments.errors.nothing_to_pay'), PaymentCalculation::paymentDisabledReason(false, true, 0));
        $this->assertNull(PaymentCalculation::paymentDisabledReason(false, true, 100));
    }

    public function test_to_rows_drops_non_positive(): void
    {
        $this->assertSame(
            [['grade_fee_id' => 1, 'amount_minor' => 50000]],
            PaymentCalculation::toRows([1 => 50000, 2 => 0])
        );
    }
}
