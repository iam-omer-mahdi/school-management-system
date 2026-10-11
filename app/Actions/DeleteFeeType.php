<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FeeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteFeeType
{
    /**
     * @throws ValidationException
     */
    public function handle(int $feeTypeId): void
    {
        $type = FeeType::query()->findOrFail($feeTypeId);

        if ($type->isTuition()) {
            throw ValidationException::withMessages([
                'fee_type' => __('fees.errors.tuition_protected'),
            ]);
        }

        if ($type->gradeFees()->exists()) {
            throw ValidationException::withMessages([
                'fee_type' => __('fees.errors.type_in_use'),
            ]);
        }

        DB::transaction(function () use ($type): void {
            $type->delete();
        });
    }
}
