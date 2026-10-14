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
            ['charged' => 100000, 'discount' => 10000, 'paid' => 30000, 'remaining' => 60000],
            EnrollmentBalance::fee(100000, 10000, 30000)
        );
    }

    public function test_charged_owed_is_remaining_and_ended_owes_nothing(): void
    {
        $this->assertSame(60000, EnrollmentBalance::owed(100000, 10000, 30000, false));
        $this->assertSame(0, EnrollmentBalance::owed(100000, 10000, 30000, true));
    }

    public function test_closed_without_payment_only_counts_when_ended(): void
    {
        $this->assertSame(0, EnrollmentBalance::closedWithoutPayment(100000, 10000, 30000, false));
        $this->assertSame(60000, EnrollmentBalance::closedWithoutPayment(100000, 10000, 30000, true));
    }

    public function test_overpaid_outstanding_subtracts_refunds(): void
    {
        $this->assertSame(30000, EnrollmentBalance::overpaidOutstanding(50000, 20000));
        $this->assertSame(0, EnrollmentBalance::overpaidOutstanding(50000, 50000));
    }

    public function test_cash_received_subtracts_refunds(): void
    {
        $this->assertSame(130000, EnrollmentBalance::cashReceived(150000, 20000));
    }
}
