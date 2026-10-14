<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentKind;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VoidPayment
{
    /**
     * Void one payment with a required reason. The row stays visible
     * and is excluded from every total. The payment must belong to
     * the given enrollment: ids from another enrollment are rejected.
     * Voiding an overpayment row is blocked while non-voided refunds
     * against it would exceed the remaining overpayment. Allowed on
     * ended enrollments and archived students: voiding is a
     * correction, never new money.
     *
     * @throws ValidationException
     */
    public function handle(int $enrollmentId, int $paymentId, ?string $reason): Payment
    {
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

        return DB::transaction(function () use ($enrollmentId, $paymentId, $reason): Payment {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();

            if ($payment === null || (int) $payment->enrollment_id !== (int) $enrollmentId) {
                throw ValidationException::withMessages([
                    'payment_id' => __('validation.exists', ['attribute' => __('payments.payment')]),
                ]);
            }

            if ($payment->isVoided()) {
                throw ValidationException::withMessages([
                    'payment_id' => __('payments.errors.already_voided'),
                ]);
            }

            if ($payment->kind === PaymentKind::Overpayment) {
                $otherOverpaid = Payment::query()
                    ->where('enrollment_id', $payment->enrollment_id)
                    ->where('kind', PaymentKind::Overpayment)
                    ->whereNull('voided_at')
                    ->where('id', '!=', $payment->id)
                    ->lockForUpdate()
                    ->sum('amount_minor');

                $refunded = Refund::query()
                    ->where('enrollment_id', $payment->enrollment_id)
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->sum('amount_minor');

                if ((int) $refunded > (int) $otherOverpaid) {
                    throw ValidationException::withMessages([
                        'payment_id' => __('payments.errors.void_blocked_by_refunds'),
                    ]);
                }
            }

            $payment->voided_at = now();
            $payment->void_reason = $reason;
            $payment->save();

            return $payment->refresh();
        });
    }
}
