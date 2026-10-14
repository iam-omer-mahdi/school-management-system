<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\EnrollmentFee;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single definition of enrollment money math. Nothing stores
 * paid, remaining or owed columns; every figure below derives from
 * charge snapshots, the discount snapshot and non-voided rows.
 *
 * Identities (all in integer minor units):
 * - fee remaining = charged - discount - net paid
 * - fee refundable = paid - refunded (never negative)
 * - owed = 0 when ended, else charged - discount - net paid
 * - net paid = paid - refunded
 * - closed_without_payment = charged - discount - net paid when ended, else 0
 * - cash_received = paid - refunded
 *
 * Refunds never change charges or per-fee paid; they only consume the
 * refundable portion of an already-paid fee. Payments and refunds are
 * always fee-linked, so every total derives from the fee rows.
 *
 * The enrollments index due sort uses the equivalent SQL expression
 * (COALESCE sums with a CASE on status); its test asserts parity.
 */
final class EnrollmentBalance
{
    /**
     * @return array{charged: int, discount: int, paid: int, refunded: int, remaining: int, refundable: int}
     */
    public static function fee(int $chargedMinor, int $discountMinor, int $paidMinor, int $refundedMinor): array
    {
        $netPaid = self::netPaid($paidMinor, $refundedMinor);

        return [
            'charged' => $chargedMinor,
            'discount' => $discountMinor,
            'paid' => $paidMinor,
            'refunded' => $refundedMinor,
            'remaining' => $chargedMinor - $discountMinor - $netPaid,
            'refundable' => max(0, $netPaid),
        ];
    }

    public static function owed(int $chargedMinor, int $discountMinor, int $netPaidMinor, bool $ended): int
    {
        if ($ended) {
            return 0;
        }

        return $chargedMinor - $discountMinor - $netPaidMinor;
    }

    public static function netPaid(int $paidMinor, int $refundedMinor): int
    {
        return $paidMinor - $refundedMinor;
    }

    public static function refundable(int $paidMinor, int $refundedMinor): int
    {
        return max(0, $paidMinor - $refundedMinor);
    }

    public static function closedWithoutPayment(int $chargedMinor, int $discountMinor, int $netPaidMinor, bool $ended): int
    {
        if (! $ended) {
            return 0;
        }

        return $chargedMinor - $discountMinor - $netPaidMinor;
    }

    /**
     * Assemble every figure for one enrollment from loaded relations:
     * fees (with payments and gradeFee.feeType), discount and refunds.
     * Voided rows are ignored here, never by the caller.
     *
     * The single tuition discount is allocated to tuition fee rows in
     * order (normally exactly one such row exists).
     *
     * @return array{charged: int, discount: int, paid: int, refunded: int, net_paid: int, refundable: int, owed: int, closed_without_payment: int, cash_received: int, fees: array<int, array{fee_id: int, is_tuition: bool, charged: int, discount: int, paid: int, refunded: int, remaining: int, refundable: int}>}
     */
    public static function forEnrollment(Enrollment $enrollment): array
    {
        $ended = $enrollment->isEnded();
        $discount = $enrollment->getRelationValue('discount');
        $discountMinor = $discount instanceof EnrollmentDiscount ? (int) $discount->amount_minor : 0;

        $refundsByFee = [];

        foreach ($enrollment->refunds as $refund) {
            if ($refund->isVoided()) {
                continue;
            }

            $feeId = (int) $refund->enrollment_fee_id;
            $refundsByFee[$feeId] = ($refundsByFee[$feeId] ?? 0) + (int) $refund->amount_minor;
        }

        $charged = 0;
        $paid = 0;
        $refunded = 0;
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

            $feePaid = (int) $charge->payments
                ->whereNull('voided_at')
                ->sum('amount_minor');

            $feeRefunded = $refundsByFee[$charge->id] ?? 0;

            $charged += $charge->amount_minor;
            $paid += $feePaid;
            $refunded += $feeRefunded;

            $fees[$charge->id] = array_merge(
                ['fee_id' => $charge->id, 'is_tuition' => $isTuition],
                self::fee($charge->amount_minor, $feeDiscount, $feePaid, $feeRefunded)
            );
        }

        $netPaid = self::netPaid($paid, $refunded);

        return [
            'charged' => $charged,
            'discount' => $discountMinor,
            'paid' => $paid,
            'refunded' => $refunded,
            'net_paid' => $netPaid,
            'refundable' => max(0, $netPaid),
            'owed' => self::owed($charged, $discountMinor, $netPaid, $ended),
            'closed_without_payment' => self::closedWithoutPayment($charged, $discountMinor, $netPaid, $ended),
            'cash_received' => $netPaid,
            'fees' => $fees,
        ];
    }
}
