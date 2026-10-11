<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StudentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\GradeFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

final class ApplyMandatoryFeesToEnrollment
{
    /**
     * Charge every mandatory grade fee of the enrollment's grade and year.
     * Only enrollments of active or suspended students are charged;
     * transferred, expelled and left enrollments keep whatever they have.
     * Missing charges are created idempotently; concurrent duplicates are
     * ignored because the unique constraint is the final guard.
     */
    public function handle(Enrollment $enrollment): int
    {
        if (! $this->isChargeable($enrollment)) {
            return 0;
        }

        return $this->charge($enrollment);
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    public function handleMany(Collection $enrollments): int
    {
        $charged = 0;

        foreach ($enrollments as $enrollment) {
            $charged += $this->handle($enrollment);
        }

        return $charged;
    }

    private function isChargeable(Enrollment $enrollment): bool
    {
        $status = $enrollment->relationLoaded('student')
            ? $enrollment->student->status
            : $enrollment->student()->value('status');

        $value = $status instanceof StudentStatus ? $status->value : (string) $status;

        return in_array($value, [StudentStatus::Active->value, StudentStatus::Suspended->value], true);
    }

    private function charge(Enrollment $enrollment): int
    {
        $gradeFees = GradeFee::query()
            ->where('grade_id', $enrollment->grade_id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->whereHas('feeType', fn (Builder $query): Builder => $query->where('is_mandatory', true))
            ->get(['id', 'amount_minor']);

        $charged = 0;

        foreach ($gradeFees as $gradeFee) {
            try {
                EnrollmentFee::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'grade_fee_id' => $gradeFee->id,
                    'amount_minor' => $gradeFee->amount_minor,
                ]);

                $charged++;
            } catch (UniqueConstraintViolationException) {
                // Already charged (e.g. by a concurrent request); skip it.
            }
        }

        return $charged;
    }
}
