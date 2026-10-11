<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GradeFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteGradeFee
{
    /**
     * @throws ValidationException
     */
    public function handle(int $gradeFeeId): void
    {
        $gradeFee = GradeFee::query()->findOrFail($gradeFeeId);

        if ($gradeFee->enrollmentFees()->exists()) {
            throw ValidationException::withMessages([
                'grade_fee' => __('fees.errors.fee_in_use'),
            ]);
        }

        DB::transaction(function () use ($gradeFee): void {
            $gradeFee->delete();
        });
    }
}
