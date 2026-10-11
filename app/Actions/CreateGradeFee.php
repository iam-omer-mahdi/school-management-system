<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateGradeFee
{
    public function __construct(
        private readonly ApplyMandatoryFeesToEnrollment $mandatoryFees,
    ) {}

    /**
     * Set a fee amount for a grade and academic year. Amounts are
     * create-only and frozen into enrollment charges at assignment time.
     *
     * Lock order (shared with EnrollStudent): grades, then enrollments and
     * charges. Locking the grade first means a mandatory fee created at the
     * same moment as an enrollment cannot be missed by either side.
     *
     * @param  array{grade_id: int, academic_year_id: int, fee_type_id: int, amount?: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): GradeFee
    {
        try {
            $amountMinor = Money::fromDecimal((string) ($data['amount'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => __('validation.numeric', ['attribute' => __('fees.amount')]),
            ]);
        }

        if ($amountMinor <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('validation.min.numeric', ['attribute' => __('fees.amount'), 'min' => 1]),
            ]);
        }

        return DB::transaction(function () use ($data, $amountMinor): GradeFee {
            $grade = Grade::query()
                ->whereKey($data['grade_id'])
                ->lockForUpdate()
                ->first();

            if ($grade === null) {
                throw ValidationException::withMessages([
                    'grade_id' => __('fees.errors.grade_not_found'),
                ]);
            }

            if (! AcademicYear::query()->whereKey($data['academic_year_id'])->exists()) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('validation.exists', ['attribute' => __('fees.academic_year')]),
                ]);
            }

            $feeType = FeeType::query()->find($data['fee_type_id']);

            if ($feeType === null) {
                throw ValidationException::withMessages([
                    'fee_type_id' => __('validation.exists', ['attribute' => __('fees.fee_type')]),
                ]);
            }

            try {
                $gradeFee = GradeFee::query()->create([
                    'grade_id' => $grade->id,
                    'academic_year_id' => $data['academic_year_id'],
                    'fee_type_id' => $feeType->id,
                    'amount_minor' => $amountMinor,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'fee_type_id' => __('fees.errors.schedule_taken'),
                ]);
            }

            if ($feeType->is_mandatory) {
                $enrollments = Enrollment::query()
                    ->where('grade_id', $grade->id)
                    ->where('academic_year_id', $data['academic_year_id'])
                    ->with('student:id,status')
                    ->get();

                $this->mandatoryFees->handleMany($enrollments);
            }

            return $gradeFee;
        });
    }
}
