<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeType;
use App\Models\GradeFee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AddOptionalFeeToEnrollment
{
    /**
     * Charge an optional fee to one enrollment. The fee must be optional
     * and belong to the enrollment's grade and academic year; mandatory
     * shortfalls are covered by the automatic charging instead.
     *
     * @throws ValidationException
     */
    public function handle(int $enrollmentId, int $gradeFeeId): EnrollmentFee
    {
        Gate::authorize(Permission::EnrollmentsManage->value);

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        if ($enrollment->isEnded()) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.enrollment_ended'),
            ]);
        }

        $gradeFee = GradeFee::query()->findOrFail($gradeFeeId);
        $feeType = FeeType::query()->find($gradeFee->fee_type_id);

        if ($feeType === null || $feeType->is_mandatory) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.not_optional'),
            ]);
        }

        if ($gradeFee->grade_id !== $enrollment->grade_id
            || $gradeFee->academic_year_id !== $enrollment->academic_year_id) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.grade_mismatch'),
            ]);
        }

        if (EnrollmentFee::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('grade_fee_id', $gradeFee->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.already_charged'),
            ]);
        }

        try {
            return DB::transaction(fn (): EnrollmentFee => EnrollmentFee::query()->create([
                'enrollment_id' => $enrollment->id,
                'grade_fee_id' => $gradeFee->id,
                'amount_minor' => $gradeFee->amount_minor,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'grade_fee_id' => __('fees.errors.already_charged'),
            ]);
        }
    }
}
