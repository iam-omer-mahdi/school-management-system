<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Discount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteDiscount
{
    /**
     * Delete a catalog discount. Blocked while any enrollment discount
     * references it, so applied snapshots are never orphaned.
     *
     * @throws ValidationException
     */
    public function handle(int $discountId): void
    {
        $discount = Discount::query()->findOrFail($discountId);

        if ($discount->enrollmentDiscounts()->exists()) {
            throw ValidationException::withMessages([
                'discount' => __('fees.errors.discount_in_use'),
            ]);
        }

        DB::transaction(function () use ($discount): void {
            $discount->delete();
        });
    }
}
