<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PaymentKind;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single definition of enrollment money math. Nothing stores
 * paid, remaining or owed columns; every figure below derives from
 * charge snapshots, the discount snapshot and non-voided rows.
 *
 * Identities (all in integer minor units):
 * - fee remaining = charged - discount - paid (fee rows only)
 * - owed = 0 when ended, else charged - discount - paid
 * - closed_without_payment = charged - discount - paid when ended, else 0
 * - overpaid_outstanding = non-voided overpayment rows - non-voided refunds
 * - cash_received = non-voided payments - non-voided refunds
 *
 * The enrollments index due sort uses the equivalent SQL expression
 * (COALESCE sums with a CASE on status); its test asserts parity.
 */
final class EnrollmentBalance
{
    /**
     * @return array{charged: int, discount: int, paid: int, remaining: int}
     */
    public static function fee(int $chargedMinor, int $discountMinor, int $paidMinor): array
    {
        return [
            'charged' => $chargedMinor,
            'discount' => $discountMinor,
            'paid' => $paidMinor,
            'remaining' => $chargedMinor - $discountMinor - $paidMinor,
        ];
    }

    public static function owed(int $chargedMinor, int $discountMinor, int $paidMinor, bool $ended): int
    {
        if ($ended) {
            return 0;
        }

        return $chargedMinor - $discountMinor - $paidMinor;
    }

    public static function closedWithoutPayment(int $chargedMinor, int $discountMinor, int $paidMinor, bool $ended): int
    {
        if (! $ended) {
            return 0;
        }

        return $chargedMinor - $discountMinor - $paidMinor;
    }

    public static function overpaidOutstanding(int $overpaidMinor, int $refundedMinor): int
    {
        return $overpaidMinor - $refundedMinor;
    }

    public static function cashReceived(int $paymentsMinor, int $refundsMinor): int
    {
        return $paymentsMinor - $refundsMinor;
    }

    /**
     * Assemble every figure for one enrollment from loaded relations:
     * fees (with payments and gradeFee.feeType), discount, payments and
     * refunds. Voided rows are ignored here, never by the caller.
     *
     * The single tuition discount is allocated to tuition fee rows in
     * order (normally exactly one such row exists).
     *
     * @return array{charged: int, discount: int, paid: int, owed: int, overpaid: int, refunded: int, overpaid_outstanding: int, closed_without_payment: int, cash_received: int, fees: array<int, array{fee_id: int, charged: int, discount: int, paid: int, remaining: int, is_tuition: bool}>}
     */
    public static function forEnrollment(Enrollment $enrollment): array
    {
        $ended = $enrollment->isEnded();
        $discountMinor = $enrollment->discount?->amount_minor ?? 0;

        $charged = 0;
        $paid = 0;
        $overpaid = 0;
        $fees = [];
        $discountLeft = $discountMinor;

        /** @var Collection<int, EnrollmentFee> $charges */
        $charges = $enrollment->fees;

        foreach ($charges as $charge) {
            $isTuition = $charge->gradeFee->feeType->isTuition();
            $feeDiscount = 0;

            if ($isTuition && $discountLeft > 0) {
                $feeDiscount = min($discountLeft, $charge->amount_minor);
                $discountLeft -= $feeDiscount;
            }

            $feePaid = $charge->payments
                ->whereNull('voided_at')
                ->where('kind', PaymentKind::Fee)
                ->sum('amount_minor');

            $charged += $charge->amount_minor;
            $paid += $feePaid;

            $fees[$charge->id] = array_merge(
                ['fee_id' => $charge->id, 'is_tuition' => $isTuition],
                self::fee($charge->amount_minor, $feeDiscount, $feePaid)
            );
        }

        foreach ($enrollment->payments as $payment) {
            if ($payment->isVoided() || ! $payment->isOverpayment()) {
                continue;
            }

            $overpaid += $payment->amount_minor;
        }

        $refunded = $enrollment->refunds
            ->whereNull('voided_at')
            ->sum('amount_minor');

        $received = $enrollment->payments
            ->whereNull('voided_at')
            ->sum('amount_minor');

        return [
            'charged' => $charged,
            'discount' => $discountMinor,
            'paid' => $paid,
            'owed' => self::owed($charged, $discountMinor, $paid, $ended),
            'overpaid' => $overpaid,
            'refunded' => $refunded,
            'overpaid_outstanding' => self::overpaidOutstanding($overpaid, $refunded),
            'closed_without_payment' => self::closedWithoutPayment($charged, $discountMinor, $paid, $ended),
            'cash_received' => self::cashReceived($received, $refunded),
            'fees' => $fees,
        ];
    }
}
