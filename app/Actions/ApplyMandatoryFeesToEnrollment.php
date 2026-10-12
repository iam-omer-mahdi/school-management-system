<?php

declare(strict_types=1);

namespace App\Actions;

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
     * Only enrollments whose own status is active or suspended are charged;
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

    /**
     * Mandatory schedule rows not yet charged to this enrollment.
     * Used by the status-change modal to preview what a reactivation
     * would add, and by the status change itself to apply them.
     *
     * @return Collection<int, GradeFee>
     */
    public function missingFor(Enrollment $enrollment): Collection
    {
        $chargedIds = EnrollmentFee::query()
            ->where('enrollment_id', $enrollment->id)
            ->pluck('grade_fee_id')
            ->all();

        return GradeFee::query()
            ->where('grade_id', $enrollment->grade_id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->whereHas('feeType', fn (Builder $query): Builder => $query->where('is_mandatory', true))
            ->when($chargedIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $chargedIds))
            ->get(['id', 'grade_id', 'academic_year_id', 'fee_type_id', 'amount_minor']);
    }

    private function isChargeable(Enrollment $enrollment): bool
    {
        return $enrollment->status->isCharged();
    }

    private function charge(Enrollment $enrollment): int
    {
        $charged = 0;

        foreach ($this->missingFor($enrollment) as $gradeFee) {
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
