<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\GradeFee;
use App\Models\Payment;
use App\Support\EnrollmentBalance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordPayment
{
    /**
     * Record one payment submission as one row per fee with a non-zero
     * amount, plus an optional overpayment row, all inside one
     * transaction. Every row shares method, transaction number and
     * date. Fee rows can never exceed their fee's remaining balance;
     * overpayment rows have no cap but need a charged enrollment.
     *
     * Money contract: minor units only. The caller parses user input
     * once; this action never parses decimals. The overpayment is
     * recomputed here as entered total minus fee allocations and the
     * save is rejected when a passed overpayment differs or its
     * confirmation flag is missing. Never trust a client amount.
     *
     * Lock order: the enrollment row first, then its fee rows, so
     * concurrent submits re-read the same paid sums and cannot
     * overpay. Returns the created rows with a flag telling the UI
     * whether the transaction number already exists on another
     * enrollment (warning only, never a block: reuse inside one
     * enrollment is expected for split submissions).
     *
     * @param  array{enrollment_id: int, total_minor?: int, rows?: array<int, array{grade_fee_id?: int, amount_minor?: int}>, overpayment_minor?: int, overpayment_confirmed?: bool, method?: string, transaction_number?: string|null, paid_on?: string, note?: string|null}  $data
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

        $total = $data['total_minor'] ?? null;

        if (! is_int($total) || $total <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('validation.numeric', ['attribute' => __('payments.amount')]),
            ]);
        }

        $rows = $data['rows'] ?? [];
        $amounts = [];

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

            $amounts[] = ['grade_fee_id' => $feeId, 'amount_minor' => $minor];
        }

        $expected = $total - array_sum(array_column($amounts, 'amount_minor'));

        if ($expected < 0) {
            throw ValidationException::withMessages([
                'amount' => __('payments.errors.total_mismatch'),
            ]);
        }

        $overpayment = $data['overpayment_minor'] ?? 0;

        if ($overpayment < 0 || $overpayment !== $expected) {
            throw ValidationException::withMessages([
                'amount' => __('payments.errors.total_mismatch'),
            ]);
        }

        if ($expected > 0 && ($data['overpayment_confirmed'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'amount' => __('payments.errors.overpayment_confirmation_required'),
            ]);
        }

        return DB::transaction(function () use ($data, $amounts, $expected, $method, $transactionNumber, $paidOn, $note): array {
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

            $feeIds = array_column($amounts, 'grade_fee_id');

            $gradeFees = $feeIds === []
                ? new Collection
                : GradeFee::query()
                    ->whereIn('id', $feeIds)
                    ->with('feeType:id,system_key')
                    ->lockForUpdate()
                    ->get();

            $discountMinor = $enrollment->discount()->lockForUpdate()->value('amount_minor') ?? 0;

            /** @var Collection<int, Payment> $created */
            $created = new Collection;

            foreach ($amounts as $index => $allocation) {
                $gradeFee = $gradeFees->firstWhere('id', $allocation['grade_fee_id']);

                if ($gradeFee === null
                    || (int) $gradeFee->grade_id !== (int) $enrollment->grade_id
                    || (int) $gradeFee->academic_year_id !== (int) $enrollment->academic_year_id) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('fees.errors.grade_mismatch'),
                    ]);
                }

                $charge = EnrollmentFee::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('grade_fee_id', $gradeFee->id)
                    ->lockForUpdate()
                    ->first();

                if ($charge === null) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('fees.errors.charge_not_found'),
                    ]);
                }

                $feeDiscount = $gradeFee->feeType->isTuition() ? (int) $discountMinor : 0;

                $paidMinor = Payment::query()
                    ->where('enrollment_fee_id', $charge->id)
                    ->where('kind', PaymentKind::Fee)
                    ->whereNull('voided_at')
                    ->sum('amount_minor');

                $remaining = EnrollmentBalance::fee((int) $charge->amount_minor, $feeDiscount, (int) $paidMinor)['remaining'];

                if ($allocation['amount_minor'] > $remaining) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.amount" => __('payments.errors.amount_exceeds_remaining'),
                    ]);
                }

                $created->push($this->store(
                    $enrollment->id,
                    $charge->id,
                    PaymentKind::Fee,
                    $allocation['amount_minor'],
                    $method,
                    $transactionNumber,
                    $paidOn,
                    $note
                ));
            }

            if ($expected > 0) {
                $created->push($this->store(
                    $enrollment->id,
                    null,
                    PaymentKind::Overpayment,
                    $expected,
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
