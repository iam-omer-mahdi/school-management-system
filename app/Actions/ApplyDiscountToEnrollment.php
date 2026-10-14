<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\EnrollmentFee;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyDiscountToEnrollment
{
    /**
     * Apply a catalog discount to an enrollment (or replace the current
     * one; at most one per enrollment). The discount name and percentage
     * are snapshotted and the amount is resolved against the tuition
     * charge, so later catalog changes never rewrite applied discounts.
     * Discounts apply to tuition only, so a tuition charge must exist.
     *
     * @throws ValidationException
     */
    public function handle(int $enrollmentId, int $discountId): EnrollmentDiscount
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        if ($enrollment->isEnded()) {
            throw ValidationException::withMessages([
                'discount_id' => __('fees.errors.enrollment_ended'),
            ]);
        }

        $discount = Discount::query()->findOrFail($discountId);

        $tuitionMinor = $this->tuitionMinor($enrollment);

        if ($tuitionMinor === null) {
            throw ValidationException::withMessages([
                'discount_id' => __('fees.errors.fees_not_set'),
            ]);
        }

        $amountMinor = Money::percentOf($tuitionMinor, $discount->percent_bp);

        $tuitionCharged = (int) EnrollmentFee::query()
            ->where('enrollment_fees.enrollment_id', $enrollment->id)
            ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
            ->join('fee_types', 'fee_types.id', '=', 'grade_fees.fee_type_id')
            ->where('fee_types.system_key', 'tuition')
            ->sum('enrollment_fees.amount_minor');

        $tuitionPaid = (int) Payment::query()
            ->where('payments.enrollment_id', $enrollment->id)
            ->whereNull('payments.voided_at')
            ->join('enrollment_fees', 'enrollment_fees.id', '=', 'payments.enrollment_fee_id')
            ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
            ->join('fee_types', 'fee_types.id', '=', 'grade_fees.fee_type_id')
            ->where('fee_types.system_key', 'tuition')
            ->sum('payments.amount_minor');

        $tuitionRefunded = (int) Refund::query()
            ->where('refunds.enrollment_id', $enrollment->id)
            ->whereNull('refunds.voided_at')
            ->join('enrollment_fees', 'enrollment_fees.id', '=', 'refunds.enrollment_fee_id')
            ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
            ->join('fee_types', 'fee_types.id', '=', 'grade_fees.fee_type_id')
            ->where('fee_types.system_key', 'tuition')
            ->sum('refunds.amount_minor');

        if ($tuitionPaid - $tuitionRefunded + $amountMinor > $tuitionCharged) {
            throw ValidationException::withMessages([
                'discount_id' => __('fees.errors.discount_exceeds_paid'),
            ]);
        }

        return DB::transaction(function () use ($enrollment, $discount, $amountMinor): EnrollmentDiscount {
            try {
                return $this->upsert($enrollment->id, $discount, $amountMinor);
            } catch (UniqueConstraintViolationException) {
                // Lost a race with another discount write; the row now
                // exists, so the second attempt updates it.
                return $this->upsert($enrollment->id, $discount, $amountMinor);
            }
        });
    }

    private function upsert(int $enrollmentId, Discount $discount, int $amountMinor): EnrollmentDiscount
    {
        return EnrollmentDiscount::query()->updateOrCreate(
            ['enrollment_id' => $enrollmentId],
            [
                'discount_id' => $discount->id,
                'name' => $discount->name,
                'percent_bp' => $discount->percent_bp,
                'amount_minor' => $amountMinor,
            ],
        );
    }

    private function tuitionMinor(Enrollment $enrollment): ?int
    {
        $value = EnrollmentFee::query()
            ->where('enrollment_fees.enrollment_id', $enrollment->id)
            ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
            ->join('fee_types', 'fee_types.id', '=', 'grade_fees.fee_type_id')
            ->where('fee_types.system_key', 'tuition')
            ->value('enrollment_fees.amount_minor');

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
