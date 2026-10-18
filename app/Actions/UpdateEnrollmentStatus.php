<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrollmentStatus;
use App\Enums\Permission;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeFee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateEnrollmentStatus
{
    public function __construct(
        private readonly ApplyMandatoryFeesToEnrollment $mandatoryFees,
    ) {}

    /**
     * Move an enrollment to any status (no-op when unchanged). Moving
     * into an ended status never removes charges or discounts; moving
     * from an ended status back to a charged one applies the missing
     * mandatory fees to that enrollment only.
     *
     * Lock order (shared with EnrollStudent and CreateGradeFee): grades,
     * then enrollments and charges, all inside one transaction, so a
     * concurrent fee schedule cannot slip between the status read and
     * the fee application. If applying fees fails, the status change
     * rolls back with it.
     *
     * @throws ValidationException
     */
    public function handle(Enrollment|int $enrollment, EnrollmentStatus $status, ?string $note = null): Enrollment
    {
        Gate::authorize(Permission::EnrollmentsManage->value);

        $enrollmentId = $enrollment instanceof Enrollment ? $enrollment->id : $enrollment;

        if ($note !== null && mb_strlen($note) > 255) {
            throw ValidationException::withMessages([
                'status_note' => __('validation.max.string', ['attribute' => __('enrollments.status_note'), 'max' => 255]),
            ]);
        }

        return DB::transaction(function () use ($enrollmentId, $status, $note): Enrollment {
            // Unlocked read for the grade id only; the transition below
            // is decided from the locked re-read, never from this row.
            $gradeId = Enrollment::query()->whereKey($enrollmentId)->value('grade_id');

            if ($gradeId === null) {
                Enrollment::query()->findOrFail($enrollmentId);
            }

            Grade::query()->whereKey($gradeId)->lockForUpdate()->first();

            $fresh = Enrollment::query()->whereKey($enrollmentId)->lockForUpdate()->firstOrFail();

            $previous = $fresh->status;

            if ($previous === $status) {
                return $fresh;
            }

            $fresh->status = $status;
            $fresh->status_note = $note;
            $fresh->status_changed_at = now();
            $fresh->save();

            if (! $previous->isCharged() && $status->isCharged()) {
                $this->mandatoryFees->handle($fresh);
            }

            return $fresh->refresh();
        });
    }

    /**
     * Mandatory schedule rows the enrollment is still missing. The UI
     * shows these in the status-change modal (fee type, amount) and
     * requires confirmation when the list is not empty.
     *
     * @return Collection<int, GradeFee>
     */
    public function missingFor(Enrollment|int $enrollment): Collection
    {
        $row = $enrollment instanceof Enrollment ? $enrollment : Enrollment::query()->findOrFail($enrollment);

        return $this->mandatoryFees->missingFor($row);
    }
}
