<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RefundKind;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\EnrollmentBalance;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RecordRefund
{
    /**
     * Refund part or all of the outstanding overpayment. Only the
     * overpayment kind exists for now; the kind column stays so Step 4
     * can add fee-money refunds. A refund never changes per-fee
     * paid/remaining. Allowed on ended enrollments and archived
     * students. Rows are locked so concurrent submits cannot
     * over-refund.
     *
     * @param  array{enrollment_id: int, amount: string, method: string, transaction_number?: string|null, refunded_on: string, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Refund
    {
        $method = PaymentMethod::tryFrom((string) ($data['method'] ?? ''));

        if ($method === null) {
            throw ValidationException::withMessages([
                'method' => __('validation.in', ['attribute' => __('payments.method')]),
            ]);
        }

        $transactionNumber = trim((string) ($data['transaction_number'] ?? ''));

        if ($method === PaymentMethod::BankTransfer && $transactionNumber === '') {
            throw ValidationException::withMessages([
                'transaction_number' => __('validation.required', ['attribute' => __('payments.transaction_number')]),
            ]);
        }

        if (mb_strlen($transactionNumber) > 100) {
            throw ValidationException::withMessages([
                'transaction_number' => __('validation.max.string', ['attribute' => __('payments.transaction_number'), 'max' => 100]),
            ]);
        }

        $refundedOn = (string) ($data['refunded_on'] ?? '');

        if ($refundedOn === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $refundedOn)) {
            throw ValidationException::withMessages([
                'refunded_on' => __('validation.date', ['attribute' => __('payments.refunded_on')]),
            ]);
        }

        if ($refundedOn > date('Y-m-d')) {
            throw ValidationException::withMessages([
                'refunded_on' => __('validation.before_or_equal', ['attribute' => __('payments.refunded_on'), 'date' => date('Y-m-d')]),
            ]);
        }

        $note = trim((string) ($data['note'] ?? ''));

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => __('validation.required', ['attribute' => __('fees.note')]),
            ]);
        }

        if (mb_strlen($note) > 255) {
            throw ValidationException::withMessages([
                'note' => __('validation.max.string', ['attribute' => __('fees.note'), 'max' => 255]),
            ]);
        }

        try {
            $amountMinor = Money::fromDecimal((string) ($data['amount'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => __('validation.numeric', ['attribute' => __('payments.amount')]),
            ]);
        }

        if ($amountMinor <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('validation.numeric', ['attribute' => __('payments.amount')]),
            ]);
        }

        return DB::transaction(function () use ($data, $amountMinor, $method, $transactionNumber, $refundedOn, $note): Refund {
            $enrollment = Enrollment::query()
                ->whereKey($data['enrollment_id'])
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                throw ValidationException::withMessages([
                    'enrollment_id' => __('students.errors.not_found'),
                ]);
            }

            $overpaid = Payment::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('kind', PaymentKind::Overpayment)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->sum('amount_minor');

            $refunded = Refund::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->sum('amount_minor');

            $outstanding = EnrollmentBalance::overpaidOutstanding((int) $overpaid, (int) $refunded);

            if ($amountMinor > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.errors.refund_exceeds_outstanding'),
                ]);
            }

            return Refund::query()->create([
                'enrollment_id' => $enrollment->id,
                'kind' => RefundKind::Overpayment,
                'amount_minor' => $amountMinor,
                'method' => $method,
                'transaction_number' => $transactionNumber === '' ? null : $transactionNumber,
                'refunded_on' => $refundedOn,
                'note' => $note,
            ]);
        });
    }
}
