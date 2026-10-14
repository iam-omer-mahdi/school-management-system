<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnrollmentBalance;
use Tests\TestCase;

class EnrollmentBalanceTest extends TestCase
{
    public function test_fee_remaining_subtracts_discount_and_paid(): void
    {
        $this->assertSame(
            ['charged' => 100000, 'discount' => 10000, 'paid' => 30000, 'refunded' => 0, 'remaining' => 60000, 'refundable' => 30000],
            EnrollmentBalance::fee(100000, 10000, 30000, 0)
        );
    }

    public function test_fee_refundable_is_paid_minus_refunded(): void
    {
        $balance = EnrollmentBalance::fee(100000, 0, 30000, 5000);

        $this->assertSame(25000, $balance['refundable']);
        $this->assertSame(70000, $balance['remaining']);
    }

    public function test_fee_refundable_never_goes_negative(): void
    {
        $this->assertSame(0, EnrollmentBalance::fee(100000, 0, 30000, 40000)['refundable']);
    }

    public function test_charged_owed_is_remaining_and_ended_owes_nothing(): void
    {
        $this->assertSame(60000, EnrollmentBalance::owed(100000, 10000, 30000, false));
        $this->assertSame(0, EnrollmentBalance::owed(100000, 10000, 30000, true));
    }

    public function test_net_paid_subtracts_refunds(): void
    {
        $this->assertSame(130000, EnrollmentBalance::netPaid(150000, 20000));
        $this->assertSame(0, EnrollmentBalance::netPaid(50000, 50000));
    }

    public function test_refundable_is_net_paid_floored_at_zero(): void
    {
        $this->assertSame(130000, EnrollmentBalance::refundable(150000, 20000));
        $this->assertSame(0, EnrollmentBalance::refundable(20000, 50000));
    }

    public function test_closed_without_payment_only_counts_when_ended(): void
    {
        $this->assertSame(0, EnrollmentBalance::closedWithoutPayment(100000, 10000, 30000, false));
        $this->assertSame(60000, EnrollmentBalance::closedWithoutPayment(100000, 10000, 30000, true));
    }

    public function test_closed_without_payment_uses_net_paid(): void
    {
        // Charged 100000, discount 10000, net paid 30000: closed with 60000.
        $this->assertSame(40000, EnrollmentBalance::closedWithoutPayment(100000, 10000, 50000, true));
    }
}
