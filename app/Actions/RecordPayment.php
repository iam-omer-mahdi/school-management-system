<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\Payment;
use App\Support\EnrollmentBalance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RecordPayment
{
    /**
     * Record one payment submission as one row per fee with a non-zero
     * amount, plus an optional overpayment row, all inside one
     * transaction. Every row shares method, transaction number and
     * date. Fee rows can never exceed their fee's remaining balance;
     * overpayment rows have no cap but need a charged enrollment.
     *
     * Lock order: the enrollment row first, then its fee rows, so
     * concurrent submits re-read the same paid sums and cannot
     * overpay. Returns the created rows with a flag telling the UI
     * whether the transaction number already exists on another
     * enrollment (warning only, never a block: reuse inside one
     * enrollment is expected for split submissions).
     *
     * @param  array{enrollment_id: int, rows: array<int, array{enrollment_fee_id?: int|null, amount: string}>, method: string, transaction_number?: string|null, paid_on: string, note?: string|null}  $data
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

        $rows = $data['rows'] ?? [];

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'amount' => __('validation.required', ['attribute' => __('payments.amount')]),
            ]);
        }

        $amounts = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'amount' => __('validation.required', ['attribute' => __('payments.amount')]),
                ]);
            }

            try {
                $minor = Money::fromDecimal((string) ($row['amount'] ?? ''));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "rows.{$index}.amount" => __('validation.numeric', ['attribute' => __('payments.amount')]),
                ]);
            }

            if ($minor <= 0) {
                throw ValidationException::withMessages([
                    "rows.{$index}.amount" => __('validation.numeric', ['attribute' => __('payments.amount')]),
                ]);
            }

            $feeId = isset($row['enrollment_fee_id']) && $row['enrollment_fee_id'] !== null && $row['enrollment_fee_id'] !== ''
                ? (int) $row['enrollment_fee_id']
                : null;

            $amounts[] = ['enrollment_fee_id' => $feeId, 'amount_minor' => $minor];
        }

        return DB::transaction(function () use ($data, $amounts, $method, $transactionNumber, $paidOn, $note): array {
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

            $feeIds = array_values(array_filter(array_column($amounts, 'enrollment_fee_id')));

            $fees = $feeIds === []
                ? new Collection
                : EnrollmentFee::query()
                    ->whereIn('id', $feeIds)
                    ->with('gradeFee.feeType:id,system_key')
                    ->lockForUpdate()
                    ->get();

            $discountMinor = $enrollment->discount()->lockForUpdate()->value('amount_minor') ?? 0;

            /** @var Collection<int, Payment> $created */
            $created = new Collection;

            foreach ($amounts as $index => $allocation) {
                if ($allocation['enrollment_fee_id'] === null) {
                    $created->push($this->store(
                        $enrollment->id,
                        null,
                        PaymentKind::Overpayment,
                        $allocation['amount_minor'],
                        $method,
                        $transactionNumber,
                        $paidOn,
                        $note
                    ));

                    continue;
                }

                $fee = $fees->firstWhere('id', $allocation['enrollment_fee_id']);

                if ($fee === null || (int) $fee->enrollment_id !== (int) $enrollment->id) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('fees.errors.grade_mismatch'),
                    ]);
                }

                $feeDiscount = $fee->gradeFee->feeType->isTuition() ? (int) $discountMinor : 0;

                $paidMinor = Payment::query()
                    ->where('enrollment_fee_id', $fee->id)
                    ->where('kind', PaymentKind::Fee)
                    ->whereNull('voided_at')
                    ->sum('amount_minor');

                $remaining = EnrollmentBalance::fee((int) $fee->amount_minor, $feeDiscount, (int) $paidMinor)['remaining'];

                if ($allocation['amount_minor'] > $remaining) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('payments.errors.amount_exceeds_remaining'),
                    ]);
                }

                $created->push($this->store(
                    $enrollment->id,
                    $fee->id,
                    PaymentKind::Fee,
                    $allocation['amount_minor'],
                    $method,
                    $transactionNumber,
                    $paidOn,
                    $note
                ));
            }

            $transactionReused = $transactionNumber !== '' && Payment::query()
                ->where('transaction_number', $transactionNumber)
                ->where('enrollment_id', '!=', $enrollment->id)
                ->whereNull('voided_at')
                ->exists();

            return ['payments' => $created, 'transaction_reused' => $transactionReused];
        });
    }

    private function store(
        int $enrollmentId,
        ?int $enrollmentFeeId,
        PaymentKind $kind,
        int $amountMinor,
        PaymentMethod $method,
        string $transactionNumber,
        string $paidOn,
        string $note
    ): Payment {
        return Payment::query()->create([
            'enrollment_id' => $enrollmentId,
            'enrollment_fee_id' => $enrollmentFeeId,
            'amount_minor' => $amountMinor,
            'kind' => $kind,
            'method' => $method,
            'transaction_number' => $transactionNumber === '' ? null : $transactionNumber,
            'paid_on' => $paidOn,
            'note' => $note === '' ? null : $note,
        ]);
    }
}
