<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordPayment
{
    /**
     * Record one payment submission as one row per fee, all inside one
     * transaction. Every row shares method, transaction number and
     * date. The entered total is allocated across charged fees and
     * must equal the sum of the per-fee amounts exactly. Each fee can never receive more than
     * its remaining balance, and the total can never exceed the sum
     * of every fee's remaining balance.
     *
     * Money contract: minor units only. The caller parses user input
     * once; this action never parses decimals. Never trust a client
     * amount: all caps are recomputed here.
     *
     * Lock order: the enrollment row first, then its fee rows, so
     * concurrent submits re-read the same paid and refunded sums and
     * cannot overpay. Returns the created rows with a flag telling the UI
     * whether the transaction number already exists on another
     * enrollment (warning only, never a block: reuse inside one
     * enrollment is expected for split submissions).
     *
     * @param  array{enrollment_id: int, total_minor?: int, rows?: array<int, array{grade_fee_id?: int, amount_minor?: int}>, method?: string, transaction_number?: string|null, paid_on?: string, note?: string|null}  $data
     * @return array{payments: Collection<int, Payment>, transaction_reused: bool}
     *
     * @throws ValidationException
     */
    public function handle(array $data): array
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

        $paidOn = (string) ($data['paid_on'] ?? '');

        if ($paidOn === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) {
            throw ValidationException::withMessages([
                'paid_on' => __('validation.date', ['attribute' => __('payments.paid_on')]),
            ]);
        }

        if (! checkdate((int) substr($paidOn, 5, 2), (int) substr($paidOn, 8, 2), (int) substr($paidOn, 0, 4))) {
            throw ValidationException::withMessages([
                'paid_on' => __('validation.date', ['attribute' => __('payments.paid_on')]),
            ]);
        }

        if ($paidOn > date('Y-m-d')) {
            throw ValidationException::withMessages([
                'paid_on' => __('validation.before_or_equal', ['attribute' => __('payments.paid_on'), 'date' => date('Y-m-d')]),
            ]);
        }

        $note = trim((string) ($data['note'] ?? ''));

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

        return DB::transaction(function () use ($data, $allocated, $firstIndex, $total, $method, $transactionNumber, $paidOn, $note): array {
            $enrollment = Enrollment::query()
                ->whereKey($data['enrollment_id'])
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                throw ValidationException::withMessages([
                    'enrollment_id' => __('students.errors.not_found'),
                ]);
            }

            if ($enrollment->isEnded()) {
                throw ValidationException::withMessages([
                    'enrollment_id' => __('payments.errors.ended_no_payments'),
                ]);
            }

            /** @var Collection<int, EnrollmentFee> $charges */
            $charges = EnrollmentFee::query()
                ->where('enrollment_id', $enrollment->id)
                ->with('gradeFee.feeType:id,system_key')
                ->lockForUpdate()
                ->get();

            if ($charges->isEmpty()) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.errors.no_charges'),
                ]);
            }

            $discountMinor = (int) ($enrollment->discount()->lockForUpdate()->value('amount_minor') ?? 0);

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

            $discountLeft = $discountMinor;
            $remainingByFee = [];
            $chargesByGradeFee = [];
            $sumRemaining = 0;

            foreach ($charges as $charge) {
                $feeDiscount = 0;

                if ($charge->gradeFee->feeType->isTuition() && $discountLeft > 0) {
                    $feeDiscount = min($discountLeft, (int) $charge->amount_minor);
                    $discountLeft -= $feeDiscount;
                }

                $paidMinor = (int) ($paidByFee[$charge->id] ?? 0);
                $refundedMinor = (int) ($refundedByFee[$charge->id] ?? 0);
                $remaining = (int) $charge->amount_minor - $feeDiscount - ($paidMinor - $refundedMinor);

                $remainingByFee[$charge->id] = $remaining;
                $chargesByGradeFee[(int) $charge->grade_fee_id] = $charge;
                $sumRemaining += max(0, $remaining);
            }

            if ($total > $sumRemaining) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.errors.total_exceeds_remaining', [
                        'amount' => Money::format($total - $sumRemaining),
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

                if ($amount > $remainingByFee[$charge->id]) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('payments.errors.amount_exceeds_remaining'),
                    ]);
                }
            }

            /** @var Collection<int, Payment> $created */
            $created = new Collection;

            foreach ($allocated as $gradeFeeId => $amount) {
                $charge = $chargesByGradeFee[$gradeFeeId];

                $created->push(Payment::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'enrollment_fee_id' => $charge->id,
                    'amount_minor' => $amount,
                    'method' => $method,
                    'transaction_number' => $transactionNumber === '' ? null : $transactionNumber,
                    'paid_on' => $paidOn,
                    'note' => $note === '' ? null : $note,
                ]));
            }

            $transactionReused = $transactionNumber !== '' && Payment::query()
                ->where('transaction_number', $transactionNumber)
                ->where('enrollment_id', '!=', $enrollment->id)
                ->whereNull('voided_at')
                ->exists();

            return ['payments' => $created, 'transaction_reused' => $transactionReused];
        });
    }
}
