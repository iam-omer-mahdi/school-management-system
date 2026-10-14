<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveEnrollmentDiscount
{
    /**
     * @throws ValidationException
     */
    public function handle(int $enrollmentId): void
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        if ($enrollment->isEnded()) {
            throw ValidationException::withMessages([
                'discount_id' => __('fees.errors.enrollment_ended'),
            ]);
        }

        $discount = EnrollmentDiscount::query()
            ->where('enrollment_id', $enrollmentId)
            ->first();

        if ($discount === null) {
            throw ValidationException::withMessages([
                'percent' => __('fees.errors.discount_missing'),
            ]);
        }

        DB::transaction(function () use ($discount): void {
            $discount->delete();
        });
    }
}
