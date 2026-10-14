<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnrollmentBalance;
use Tests\TestCase;

class FinancialSummaryMathTest extends TestCase
{
    public function test_basic_balance_identity(): void
    {
        $charged = 100000;
        $discount = 20000;
        $paid = 50000;
        $refunded = 10000;

        $fee = EnrollmentBalance::fee($charged, $discount, $paid, $refunded);

        $this->assertSame(40000, $fee['remaining']);
        $this->assertSame(40000, $fee['refundable']);

        $netPaid = EnrollmentBalance::netPaid($paid, $refunded);
        $this->assertSame(40000, $netPaid);

        // Active
        $owedActive = EnrollmentBalance::owed($charged, $discount, $netPaid, false);
        $this->assertSame(40000, $owedActive);
        $forgoneActive = EnrollmentBalance::closedWithoutPayment($charged, $discount, $netPaid, false);
        $this->assertSame(0, $forgoneActive);

        // Grand total identity
        $grandTotal = $charged - $discount;
        $this->assertSame($grandTotal, $netPaid + $owedActive + $forgoneActive);

        // Ended
        $owedEnded = EnrollmentBalance::owed($charged, $discount, $netPaid, true);
        $this->assertSame(0, $owedEnded);
        $forgoneEnded = EnrollmentBalance::closedWithoutPayment($charged, $discount, $netPaid, true);
        $this->assertSame(40000, $forgoneEnded);

        // Grand total identity for ended
        $this->assertSame($grandTotal, $netPaid + $owedEnded + $forgoneEnded);
    }
}
