<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordRefund
{
    /**
     * Refund money already paid against the fees of an ended
     * enrollment. Charged enrollments cannot be refunded. The entered
     * total is allocated across fees and must equal the sum of the
     * per-fee amounts exactly; each fee can only be refunded up to its
     * refundable balance (paid minus already refunded). A refund never
     * changes charges or per-fee paid. Rows are locked so concurrent
     * submits cannot over-refund.
     *
     * Money contract: minor units only. The caller parses user input
     * once; this action never parses decimals.
     *
     * @param  array{enrollment_id: int, total_minor?: int, rows?: array<int, array{grade_fee_id?: int, amount_minor?: int}>, method?: string, transaction_number?: string|null, refunded_on?: string, note?: string|null}  $data
     * @return Collection<int, Refund>
     *
     * @throws ValidationException
     */
    public function handle(array $data): Collection
    {
        $method = PaymentMethod::tryFrom((string) ($data['method'] ?? ''));

        if ($method === null) {
            throw ValidationException::withMessages([
                'method' => __('validation.in', ['attribute' => __('payments.method')]),
            ]);
        }

        $transactionNumber = trim((string) ($data['transaction_number'] ?? ''));

        if ($method === PaymentMethod::Cash && $transactionNumber !== '') {
            throw ValidationException::withMessages([
                'transaction_number' => __('payments.errors.cash_transaction_not_allowed'),
            ]);
        }

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

        if (! checkdate((int) substr($refundedOn, 5, 2), (int) substr($refundedOn, 8, 2), (int) substr($refundedOn, 0, 4))) {
            throw ValidationException::withMessages([
                'refunded_on' => __('validation.date', ['attribute' => __('payments.refunded_on')]),
            ]);
        }

        if ($refundedOn > BusinessDate::today()) {
            throw ValidationException::withMessages([
                'refunded_on' => __('validation.before_or_equal', ['attribute' => __('payments.refunded_on'), 'date' => BusinessDate::today()]),
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

        $total = $data['total_minor'] ?? null;

        if (! is_int($total) || $total <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('validation.numeric', ['attribute' => __('payments.amount')]),
            ]);
        }

        $rows = $data['rows'] ?? [];
        $allocated = [];
        $firstIndex = [];

        foreach (array_values($rows) as $index => $row) {
            $feeId = $row['grade_fee_id'] ?? null;

            if (! is_int($feeId) || $feeId <= 0) {
                throw ValidationException::withMessages([
                    "rows.{$index}.amount" => __('fees.errors.grade_mismatch'),
                ]);
            }

            $minor = $row['amount_minor'] ?? null;

            if (! is_int($minor) || $minor <= 0) {
                throw ValidationException::withMessages([
                    "rows.{$index}.amount" => __('validation.numeric', ['attribute' => __('payments.amount')]),
                ]);
            }

            $allocated[$feeId] = ($allocated[$feeId] ?? 0) + $minor;
            $firstIndex[$feeId] ??= $index;
        }

        if (array_sum($allocated) !== $total) {
            throw ValidationException::withMessages([
                'amount' => __('payments.errors.total_mismatch'),
            ]);
        }

        return DB::transaction(function () use ($data, $allocated, $firstIndex, $total, $method, $transactionNumber, $refundedOn, $note): Collection {
            $enrollment = Enrollment::query()
                ->whereKey($data['enrollment_id'])
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                throw ValidationException::withMessages([
                    'enrollment_id' => __('students.errors.not_found'),
                ]);
            }

            if (! $enrollment->isEnded()) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.errors.refund_charged_only'),
                ]);
            }

            /** @var Collection<int, EnrollmentFee> $charges */
            $charges = EnrollmentFee::query()
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->get();

            if ($charges->isEmpty()) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.errors.no_charges'),
                ]);
            }

            $paidByFee = Payment::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNull('voided_at')
                ->selectRaw('enrollment_fee_id, COALESCE(SUM(amount_minor), 0) as paid_total')
                ->groupBy('enrollment_fee_id')
                ->lockForUpdate()
                ->pluck('paid_total', 'enrollment_fee_id');

            $refundedByFee = Refund::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNull('voided_at')
                ->selectRaw('enrollment_fee_id, COALESCE(SUM(amount_minor), 0) as refunded_total')
                ->groupBy('enrollment_fee_id')
                ->lockForUpdate()
                ->pluck('refunded_total', 'enrollment_fee_id');

            $refundableByFee = [];
            $chargesByGradeFee = [];
            $sumRefundable = 0;

            foreach ($charges as $charge) {
                $paidMinor = (int) ($paidByFee[$charge->id] ?? 0);
                $refundedMinor = (int) ($refundedByFee[$charge->id] ?? 0);
                $refundable = max(0, $paidMinor - $refundedMinor);

                $refundableByFee[$charge->id] = $refundable;
                $chargesByGradeFee[(int) $charge->grade_fee_id] = $charge;
                $sumRefundable += $refundable;
            }

            if ($total > $sumRefundable) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.errors.refund_exceeds_refundable', [
                        'amount' => Money::format($total - $sumRefundable),
                    ]),
                ]);
            }

            foreach ($allocated as $gradeFeeId => $amount) {
                $index = $firstIndex[$gradeFeeId];
                $charge = $chargesByGradeFee[$gradeFeeId] ?? null;

                if ($charge === null) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('fees.errors.charge_not_found'),
                    ]);
                }

                if ($amount > $refundableByFee[$charge->id]) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('payments.errors.refund_amount_exceeds_refundable'),
                    ]);
                }
            }

            /** @var Collection<int, Refund> $created */
            $created = new Collection;

            foreach ($allocated as $gradeFeeId => $amount) {
                $charge = $chargesByGradeFee[$gradeFeeId];

                $created->push(Refund::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'enrollment_fee_id' => $charge->id,
                    'amount_minor' => $amount,
                    'method' => $method,
                    'transaction_number' => $transactionNumber === '' ? null : $transactionNumber,
                    'refunded_on' => $refundedOn,
                    'note' => $note,
                ]));
            }

            return $created;
        });
    }
}
