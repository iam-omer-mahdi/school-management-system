<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\EnrollmentFee;
use App\Support\Money;
use App\Support\Percent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SetEnrollmentDiscount
{
    /**
     * Set (or replace) the single tuition discount of an enrollment.
     * Discounts apply to tuition only, so a tuition charge must exist;
     * without one there is nothing to discount against.
     *
     * @throws ValidationException
     */
    public function handle(int $enrollmentId, string $percentInput, ?string $note = null): EnrollmentDiscount
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        try {
            $basisPoints = Percent::parse($percentInput);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'percent' => __('validation.numeric', ['attribute' => __('fees.discount')]),
            ]);
        }

        $tuitionMinor = $this->tuitionMinor($enrollment);

        if ($tuitionMinor === null) {
            throw ValidationException::withMessages([
                'percent' => __('fees.errors.fees_not_set'),
            ]);
        }

        if ($note !== null && mb_strlen($note) > 255) {
            throw ValidationException::withMessages([
                'note' => __('validation.max.string', ['attribute' => __('fees.note'), 'max' => 255]),
            ]);
        }

        $amountMinor = Money::percentOf($tuitionMinor, $basisPoints);

        return DB::transaction(function () use ($enrollment, $basisPoints, $amountMinor, $note): EnrollmentDiscount {
            try {
                return $this->upsert($enrollment->id, $basisPoints, $amountMinor, $note);
            } catch (UniqueConstraintViolationException) {
                // Lost a race with another discount write; the row now
                // exists, so the second attempt updates it.
                return $this->upsert($enrollment->id, $basisPoints, $amountMinor, $note);
            }
        });
    }

    private function upsert(int $enrollmentId, int $basisPoints, int $amountMinor, ?string $note): EnrollmentDiscount
    {
        return EnrollmentDiscount::query()->updateOrCreate(
            ['enrollment_id' => $enrollmentId],
            ['percent_bp' => $basisPoints, 'amount_minor' => $amountMinor, 'note' => $note],
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
