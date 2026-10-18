<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeType;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RemoveOptionalFeeFromEnrollment
{
    /**
     * Remove an optional charge. Mandatory charges are never removable.
     *
     * @throws ValidationException
     */
    public function handle(int $enrollmentId, int $gradeFeeId): void
    {
        Gate::authorize(Permission::EnrollmentsManage->value);

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        if ($enrollment->isEnded()) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.enrollment_ended'),
            ]);
        }

        $charge = EnrollmentFee::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('grade_fee_id', $gradeFeeId)
            ->first();

        if ($charge === null) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.charge_not_found'),
            ]);
        }

        $feeType = FeeType::query()->find($charge->gradeFee->fee_type_id);

        if ($feeType === null || $feeType->is_mandatory) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.mandatory_locked'),
            ]);
        }

        // Any payment row (voided or not) references this charge for
        // receipt history, and the foreign key restricts its deletion,
        // so a paid charge can never be removed.
        $hasPayments = Payment::query()
            ->where('enrollment_fee_id', $charge->id)
            ->exists();

        if ($hasPayments) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.charge_has_payments'),
            ]);
        }

        DB::transaction(function () use ($charge): void {
            $charge->delete();
        });
    }
}
