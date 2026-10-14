<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pure payment/refund math extracted from the enrollment show page.
 * No Livewire, no queries here except through the explicit balance()
 * cache below. Money parsing stays on Money::fromDecimal; display
 * stays on Money::format / x-ui.money. Caps enforced inside Actions
 * stay inside Actions; these helpers only drive the live gates.
 */
final class PaymentCalculation
{
    /** @var array<int, array{charged: int, discount: int, paid: int, refunded: int, net_paid: int, refundable: int, owed: int, closed_without_payment: int, cash_received: int, fees: array<int, array{fee_id: int, is_tuition: bool, charged: int, discount: int, paid: int, refunded: int, remaining: int, refundable: int}>}> */
    private static array $balanceCache = [];

    /**
     * Shared per-request balance so sibling panels do not each
     * recompute EnrollmentBalance. Flush after every write.
     *
     * @return array{charged: int, discount: int, paid: int, refunded: int, net_paid: int, refundable: int, owed: int, closed_without_payment: int, cash_received: int, fees: array<int, array{fee_id: int, is_tuition: bool, charged: int, discount: int, paid: int, refunded: int, remaining: int, refundable: int}>}
     */
    public static function balance(Enrollment $enrollment): array
    {
        $key = (int) $enrollment->id;

        if (! array_key_exists($key, self::$balanceCache)) {
            self::$balanceCache[$key] = EnrollmentBalance::forEnrollment($enrollment);
        }

        return self::$balanceCache[$key];
    }

    public static function flush(?int $enrollmentId = null): void
    {
        if ($enrollmentId === null) {
            self::$balanceCache = [];

            return;
        }

        unset(self::$balanceCache[(int) $enrollmentId]);
    }

    public static function parseTotal(string $value): ?int
    {
        try {
            return Money::fromDecimal($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, int>|null
     */
    public static function parseAllocations(array $values): ?array
    {
        $parsed = [];

        foreach ($values as $feeId => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            try {
                $parsed[(int) $feeId] = Money::fromDecimal((string) $value);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<int, int>|null  $allocs
     */
    public static function unallocated(?int $total, ?array $allocs): ?int
    {
        if ($total === null || $allocs === null) {
            return null;
        }

        return $total - array_sum($allocs);
    }

    /**
     * Per-fee remaining keyed by grade fee id.
     *
     * @param  array{fees: array<int, array{remaining: int}>}  $balance
     * @param  Collection<int, EnrollmentFee>|iterable<int, EnrollmentFee>  $charges
     * @return array<int, int>
     */
    public static function remainingByGradeFee(array $balance, iterable $charges): array
    {
        $map = [];

        foreach ($charges as $charge) {
            $map[(int) $charge->grade_fee_id] = (int) ($balance['fees'][$charge->id]['remaining'] ?? 0);
        }

        return $map;
    }

    /**
     * @param  array{fees: array<int, array{refundable: int}>}  $balance
     * @param  Collection<int, EnrollmentFee>|iterable<int, EnrollmentFee>  $charges
     * @return array<int, int>
     */
    public static function refundableByGradeFee(array $balance, iterable $charges): array
    {
        $map = [];

        foreach ($charges as $charge) {
            $map[(int) $charge->grade_fee_id] = (int) ($balance['fees'][$charge->id]['refundable'] ?? 0);
        }

        return $map;
    }

    public static function paymentDisabledReason(bool $ended, bool $hasFees, int $sumRemaining): ?string
    {
        if ($ended) {
            return __('payments.errors.ended_no_payments');
        }

        if (! $hasFees) {
            return __('payments.errors.no_charges');
        }

        if ($sumRemaining <= 0) {
            return __('payments.errors.nothing_to_pay');
        }

        return null;
    }

    /**
     * @param  array<int, int>|null  $allocs
     * @param  array<int, int>  $remainingByGradeFee
     */
    public static function payBlockReason(?int $total, ?array $allocs, array $remainingByGradeFee, string $method, ?string $txn): ?string
    {
        if ($total === null || $total <= 0 || $allocs === null) {
            return __('validation.numeric', ['attribute' => __('payments.amount')]);
        }

        if ($total - array_sum($allocs) !== 0) {
            return __('payments.errors.total_mismatch');
        }

        foreach ($allocs as $feeId => $minor) {
            if ($minor > ($remainingByGradeFee[$feeId] ?? 0)) {
                return __('payments.errors.amount_exceeds_remaining');
            }
        }

        if ($method === 'bank_transfer' && trim((string) $txn) === '') {
            return __('validation.required', ['attribute' => __('payments.transaction_number')]);
        }

        return null;
    }

    /**
     * @param  array<int, int>|null  $allocs
     * @param  array<int, int>  $refundableByGradeFee
     */
    public static function refundBlockReason(bool $ended, ?int $total, ?array $allocs, array $refundableByGradeFee, string $method, ?string $txn, ?string $note): ?string
    {
        if (! $ended) {
            return __('payments.errors.refund_charged_only');
        }

        if ($total === null || $total <= 0 || $allocs === null) {
            return __('validation.numeric', ['attribute' => __('payments.amount')]);
        }

        if ($total - array_sum($allocs) !== 0) {
            return __('payments.errors.total_mismatch');
        }

        foreach ($allocs as $feeId => $minor) {
            if ($minor > ($refundableByGradeFee[$feeId] ?? 0)) {
                return __('payments.errors.refund_amount_exceeds_refundable');
            }
        }

        if ($method === 'bank_transfer' && trim((string) $txn) === '') {
            return __('validation.required', ['attribute' => __('payments.transaction_number')]);
        }

        if (trim((string) $note) === '') {
            return __('validation.required', ['attribute' => __('fees.note')]);
        }

        return null;
    }

    /**
     * @param  array<int, int>  $allocs
     * @return array<int, array{grade_fee_id: int, amount_minor: int}>
     */
    public static function toRows(array $allocs): array
    {
        $rows = [];

        foreach ($allocs as $feeId => $minor) {
            if ($minor > 0) {
                $rows[] = ['grade_fee_id' => (int) $feeId, 'amount_minor' => $minor];
            }
        }

        return $rows;
    }
}
