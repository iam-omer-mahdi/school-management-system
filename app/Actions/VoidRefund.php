<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class VoidRefund
{
    /**
     * Void one refund with a required reason. The row stays visible
     * and is excluded from every total, which restores the refundable
     * balance of the fee it had consumed. The refund must belong to
     * the given enrollment: ids from another enrollment are rejected.
     * Allowed on ended enrollments and archived students: voiding is a
     * correction, never new money.
     *
     * @throws ValidationException
     */
    public function handle(int $enrollmentId, int $refundId, ?string $reason): Refund
    {
        Gate::authorize(Permission::PaymentsManage->value);

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'void_reason' => __('validation.required', ['attribute' => __('payments.void_reason')]),
            ]);
        }

        if (mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'void_reason' => __('validation.max.string', ['attribute' => __('payments.void_reason'), 'max' => 255]),
            ]);
        }

        return DB::transaction(function () use ($enrollmentId, $refundId, $reason): Refund {
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();

            if ($refund === null || (int) $refund->enrollment_id !== (int) $enrollmentId) {
                throw ValidationException::withMessages([
                    'refund_id' => __('validation.exists', ['attribute' => __('payments.refund')]),
                ]);
            }

            if ($refund->isVoided()) {
                throw ValidationException::withMessages([
                    'refund_id' => __('payments.errors.already_voided'),
                ]);
            }

            $refund->voided_at = now();
            $refund->void_reason = $reason;
            $refund->save();

            return $refund->refresh();
        });
    }
}
