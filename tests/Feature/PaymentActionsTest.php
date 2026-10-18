<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\UpdateEnrollmentStatus;
use App\Actions\VoidPayment;
use App\Actions\VoidRefund;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\EnrollmentBalance;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        return [$grade, $year];
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    private function tuitionFee(Grade $grade, AcademicYear $year, string $amount = '1000'): GradeFee
    {
        return app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => $amount,
        ])->gradeFee;
    }

    private function optionalFee(Grade $grade, AcademicYear $year, string $amount = '150'): GradeFee
    {
        $type = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);

        return app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $type->id,
            'amount' => $amount,
        ])->gradeFee;
    }

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        $student = Student::factory()->create();

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    /**
     * @param  array<int, array{grade_fee_id: int, amount_minor: int}>  $rows
     * @return array{enrollment_id: int, total_minor: int, rows: array<int, array{grade_fee_id: int, amount_minor: int}>, method: string, transaction_number: string|null, paid_on: string, note: string|null}
     */
    private function payment(int $enrollmentId, int $totalMinor, array $rows, string $method = 'cash', ?string $transactionNumber = null, ?string $note = null): array
    {
        return [
            'enrollment_id' => $enrollmentId,
            'total_minor' => $totalMinor,
            'rows' => $rows,
            'method' => $method,
            'transaction_number' => $transactionNumber,
            'paid_on' => date('Y-m-d'),
            'note' => $note,
        ];
    }

    /**
     * @param  array<int, array{grade_fee_id: int, amount_minor: int}>  $rows
     * @return array{enrollment_id: int, total_minor: int, rows: array<int, array{grade_fee_id: int, amount_minor: int}>, method: string, transaction_number: string|null, refunded_on: string, note: string|null}
     */
    private function refund(int $enrollmentId, int $totalMinor, array $rows, string $method = 'cash', ?string $transactionNumber = null, ?string $note = 'Return'): array
    {
        return [
            'enrollment_id' => $enrollmentId,
            'total_minor' => $totalMinor,
            'rows' => $rows,
            'method' => $method,
            'transaction_number' => $transactionNumber,
            'refunded_on' => date('Y-m-d'),
            'note' => $note,
        ];
    }

    public function test_records_partial_and_full_payments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $partial = app(RecordPayment::class)->handle(
            $this->payment($enrollment->id, 40000, [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 40000]])
        );

        $this->assertSame(40000, $partial['payments']->firstOrFail()->amount_minor);
        $this->assertFalse($partial['transaction_reused']);

        app(RecordPayment::class)->handle(
            $this->payment($enrollment->id, 60000, [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 60000]])
        );

        $this->assertEquals(100000, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
    }

    public function test_splits_one_total_across_several_fees_in_one_transaction(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->tuitionFee($grade, $year);
        $booksFee = $this->optionalFee($grade, $year);

        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        $result = app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            65000,
            [
                ['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 50000],
                ['grade_fee_id' => $booksFee->id, 'amount_minor' => 15000],
            ],
            method: 'bank_transfer',
            transactionNumber: 'TXN-1',
            note: 'Split',
        ));

        $this->assertSame(2, $result['payments']->count());
        $this->assertSame(['TXN-1'], $result['payments']->pluck('transaction_number')->unique()->all());
        $this->assertSame(65000, $result['payments']->sum('amount_minor'));

        $chargeIds = $enrollment->fees()->pluck('id')->all();
        $this->assertEqualsCanonicalizing($chargeIds, $result['payments']->pluck('enrollment_fee_id')->all());
    }

    public function test_allocations_must_equal_the_entered_total(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                100000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 80000]]
            ));
            $this->fail('Expected ValidationException for allocations not matching the total');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.total_mismatch'), $e->errors()['amount'][0]);
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_total_above_remaining_is_rejected_and_stores_nothing(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                120000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 120000]]
            ));
            $this->fail('Expected ValidationException for total above remaining');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('payments.errors.total_exceeds_remaining', ['amount' => Money::format(20000)]),
                $e->errors()['amount'][0]
            );
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_fee_row_cannot_exceed_its_remaining(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->tuitionFee($grade, $year);
        $booksFee = $this->optionalFee($grade, $year);

        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            100000,
            [['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 100000]]
        ));

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                15000,
                [['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 15000]]
            ));
            $this->fail('Expected ValidationException for a fee row above its remaining');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.amount_exceeds_remaining'), $e->errors()['rows.0.amount'][0]);
        }

        $this->assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_bank_transfer_requires_transaction_number_and_cash_does_not(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();
        $rows = [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]];

        try {
            app(RecordPayment::class)->handle($this->payment($enrollment->id, 10000, $rows, method: 'bank_transfer'));
            $this->fail('Expected ValidationException for missing transaction number');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transaction_number', $e->errors());
        }

        $created = app(RecordPayment::class)->handle($this->payment($enrollment->id, 10000, $rows, method: 'cash'));
        $this->assertNull($created['payments']->firstOrFail()->transaction_number);

        $created = app(RecordPayment::class)->handle($this->payment($enrollment->id, 10000, $rows, method: 'bank_transfer', transactionNumber: 'TXN-9'));
        $this->assertSame('TXN-9', $created['payments']->firstOrFail()->transaction_number);
    }

    public function test_transaction_reuse_warns_only_across_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);

        $first = $this->enroll($grade, $year);
        $firstFee = $first->fees()->firstOrFail();
        $second = $this->enroll($grade, $year);
        $secondFee = $second->fees()->firstOrFail();

        $make = fn (int $enrollmentId, int $feeId): array => $this->payment(
            $enrollmentId,
            10000,
            [['grade_fee_id' => $feeId, 'amount_minor' => 10000]],
            method: 'bank_transfer',
            transactionNumber: 'SHARED-TXN',
        );

        $this->assertFalse(app(RecordPayment::class)->handle($make($first->id, $firstFee->grade_fee_id))['transaction_reused']);
        $this->assertTrue(app(RecordPayment::class)->handle($make($second->id, $secondFee->grade_fee_id))['transaction_reused']);
    }

    public function test_payments_blocked_on_ended_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                100000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 100000]]
            ));
            $this->fail('Expected ValidationException for payment on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.ended_no_payments'), $e->errors()['enrollment_id'][0]);
        }
    }

    public function test_payment_on_enrollment_without_charges_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                10000,
                [['grade_fee_id' => 1, 'amount_minor' => 10000]]
            ));
            $this->fail('Expected ValidationException for a charge-less enrollment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.no_charges'), $e->errors()['amount'][0]);
        }
    }

    public function test_void_excludes_from_totals_and_requires_reason(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $payment = app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            100000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 100000]]
        ))['payments']->firstOrFail();

        try {
            app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, null);
            $this->fail('Expected ValidationException for missing void reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('void_reason', $e->errors());
        }

        $voided = app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, 'Entered twice');
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Entered twice', $voided->void_reason);

        try {
            app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, 'Again');
            $this->fail('Expected ValidationException for double void');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.already_voided'), $e->errors()['payment_id'][0]);
        }
    }

    public function test_void_rejects_payment_from_another_enrollment(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->tuitionFee($grade, $year);

        $first = $this->enroll($grade, $year);
        $second = $this->enroll($grade, $year);

        $foreign = app(RecordPayment::class)->handle($this->payment(
            $second->id,
            10000,
            [['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 10000]]
        ))['payments']->firstOrFail();

        try {
            app(VoidPayment::class)->handle($first->id, $foreign->id, 'Sneaky');
            $this->fail('Expected ValidationException for cross-enrollment void');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_id', $e->errors());
        }

        $this->assertNull($foreign->refresh()->voided_at);
    }

    public function test_void_rejects_refund_from_another_enrollment(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);

        $first = $this->enroll($grade, $year);
        $second = $this->enroll($grade, $year);
        $fee = $second->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $second->id,
            10000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
        ));
        app(UpdateEnrollmentStatus::class)->handle($second, EnrollmentStatus::Left);

        $foreign = app(RecordRefund::class)->handle($this->refund(
            $second->id,
            10000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
        ))->firstOrFail();

        try {
            app(VoidRefund::class)->handle($first->id, $foreign->id, 'Sneaky');
            $this->fail('Expected ValidationException for cross-enrollment refund void');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('refund_id', $e->errors());
        }

        $this->assertNull($foreign->refresh()->voided_at);
    }

    public function test_discounted_tuition_pays_exactly_the_owed_amount(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year, '2000');
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                200000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 200000]]
            ));
            $this->fail('Expected ValidationException for paying beyond the discounted amount');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('payments.errors.total_exceeds_remaining', ['amount' => Money::format(20000)]),
                $e->errors()['amount'][0]
            );
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            180000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 180000]]
        ));

        $this->assertEquals(180000, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
    }

    public function test_discount_blocked_when_paid_would_exceed_charged(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            100000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 100000]]
        ));

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);

        try {
            app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);
            $this->fail('Expected ValidationException for discount over paid tuition');
        } catch (ValidationException $e) {
            $this->assertSame(__('fees.errors.discount_exceeds_paid'), $e->errors()['discount_id'][0]);
        }

        $this->assertEquals(100000, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
        $this->assertSame(0, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());
    }

    public function test_removing_optional_fee_with_payments_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $booksFee = $this->optionalFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            15000,
            [['grade_fee_id' => $booksFee->id, 'amount_minor' => 15000]]
        ));

        try {
            app(RemoveOptionalFeeFromEnrollment::class)->handle($enrollment->id, $booksFee->id);
            $this->fail('Expected ValidationException for removing a paid charge');
        } catch (ValidationException $e) {
            $this->assertSame(__('fees.errors.charge_has_payments'), $e->errors()['grade_fee_id'][0]);
        }

        $this->assertSame(2, $enrollment->refresh()->fees()->count());
    }

    public function test_refund_blocked_on_charged_enrollment(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            40000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 40000]]
        ));

        try {
            app(RecordRefund::class)->handle($this->refund(
                $enrollment->id,
                10000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
            ));
            $this->fail('Expected ValidationException for refund on a charged enrollment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.refund_charged_only'), $e->errors()['amount'][0]);
        }

        $this->assertSame(0, Refund::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_refund_is_fee_linked_and_capped_at_refundable(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            40000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 40000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $refund = app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            20000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 20000]]
        ))->firstOrFail();

        $this->assertSame(20000, $refund->amount_minor);
        $this->assertSame($fee->id, $refund->enrollment_fee_id);
        $this->assertSame(1, $enrollment->refresh()->fees()->count());
        $this->assertEquals(40000, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));

        try {
            app(RecordRefund::class)->handle($this->refund(
                $enrollment->id,
                30000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 30000]]
            ));
            $this->fail('Expected ValidationException for refund above refundable');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('payments.errors.refund_exceeds_refundable', ['amount' => Money::format(10000)]),
                $e->errors()['amount'][0]
            );
        }
    }

    public function test_refund_per_fee_row_cannot_exceed_refundable(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->tuitionFee($grade, $year);
        $booksFee = $this->optionalFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            115000,
            [
                ['grade_fee_id' => $booksFee->id, 'amount_minor' => 15000],
                ['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 100000],
            ]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        try {
            app(RecordRefund::class)->handle($this->refund(
                $enrollment->id,
                16000,
                [['grade_fee_id' => $booksFee->id, 'amount_minor' => 16000]]
            ));
            $this->fail('Expected ValidationException for a fee row above its refundable');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.refund_amount_exceeds_refundable'), $e->errors()['rows.0.amount'][0]);
        }
    }

    public function test_refund_requires_note(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            40000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 40000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        try {
            app(RecordRefund::class)->handle($this->refund(
                $enrollment->id,
                10000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]],
                note: '',
            ));
            $this->fail('Expected ValidationException for missing refund note');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }
    }

    public function test_void_refund_restores_refundable_and_void_payment_blocked_by_refunds(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $payment = app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            50000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 50000]]
        ))['payments']->firstOrFail();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $refund = app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            50000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 50000]]
        ))->firstOrFail();

        try {
            app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, 'Undo');
            $this->fail('Expected ValidationException when voiding a refunded payment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.void_blocked_by_refunds'), $e->errors()['payment_id'][0]);
        }

        app(VoidRefund::class)->handle($refund->enrollment_id, $refund->id, 'Mistake');
        $this->assertNotNull($refund->refresh()->voided_at);

        $replacement = app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            50000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 50000]]
        ))->firstOrFail();

        $this->assertSame(50000, $replacement->amount_minor);
        app(VoidRefund::class)->handle($replacement->enrollment_id, $replacement->id, 'Duplicate');

        app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, 'Undo');
        $this->assertNotNull($payment->refresh()->voided_at);
    }

    public function test_refund_and_void_allowed_on_ended_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $payment = app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            50000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 50000]]
        ))['payments']->firstOrFail();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $refund = app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            20000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 20000]],
            method: 'bank_transfer',
            transactionNumber: 'TXN-R',
        ))->firstOrFail();

        $this->assertSame(20000, $refund->amount_minor);

        app(VoidRefund::class)->handle($refund->enrollment_id, $refund->id, 'Mistake');
        app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, 'Undo');

        $this->assertNotNull($refund->refresh()->voided_at);
        $this->assertNotNull($payment->refresh()->voided_at);
    }

    public function test_refund_without_any_paid_fee_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        try {
            app(RecordRefund::class)->handle($this->refund(
                $enrollment->id,
                10000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
            ));
            $this->fail('Expected ValidationException for a refund with no paid fee');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('payments.errors.refund_exceeds_refundable', ['amount' => Money::format(10000)]),
                $e->errors()['amount'][0]
            );
        }
    }

    public function test_reactivated_enrollment_owes_net_paid_after_full_refund(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year, '50');
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            4000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 4000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            4000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 4000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Active);

        $balance = EnrollmentBalance::forEnrollment($enrollment->refresh()->load([
            'fees.gradeFee.feeType',
            'fees.payments',
            'discount',
            'refunds',
        ]));

        $this->assertSame(5000, $balance['owed']);

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            5000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 5000]]
        ));

        $this->assertEquals(9000, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
        $this->assertEquals(4000, Refund::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
    }

    public function test_partial_refund_sets_payment_cap_after_reactivation(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year, '50');
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            4000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 4000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            1500,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 1500]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Active);

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                2600,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 2600]]
            ));
            $this->fail('Expected ValidationException for payment above the net-paid cap');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('payments.errors.total_exceeds_remaining', ['amount' => Money::format(100)]),
                $e->errors()['amount'][0]
            );
        }

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            2500,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 2500]]
        ));

        $this->assertEquals(6500, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
    }

    public function test_discount_guard_uses_net_paid_after_refund_and_reactivation(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year, '50');
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            5000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 5000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        app(RecordRefund::class)->handle($this->refund(
            $enrollment->id,
            4500,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 4500]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Active);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '80']);
        $applied = app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        $this->assertSame(4000, $applied->amount_minor);
    }

    public function test_cash_payment_with_transaction_number_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        try {
            app(RecordPayment::class)->handle($this->payment(
                $enrollment->id,
                10000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]],
                method: 'cash',
                transactionNumber: 'CASH-TXN',
            ));
            $this->fail('Expected ValidationException for a cash transaction number');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.cash_transaction_not_allowed'), $e->errors()['transaction_number'][0]);
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_cash_refund_with_transaction_number_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            10000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        try {
            app(RecordRefund::class)->handle($this->refund(
                $enrollment->id,
                10000,
                [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]],
                method: 'cash',
                transactionNumber: 'CASH-TXN',
            ));
            $this->fail('Expected ValidationException for a cash refund transaction number');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.cash_transaction_not_allowed'), $e->errors()['transaction_number'][0]);
        }

        $this->assertSame(0, Refund::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_payment_rejects_impossible_and_future_dates(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();
        $rows = [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]];

        $payload = $this->payment($enrollment->id, 10000, $rows);

        try {
            app(RecordPayment::class)->handle(array_merge($payload, ['paid_on' => '2026-02-30']));
            $this->fail('Expected ValidationException for an impossible payment date');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('validation.date', ['attribute' => __('payments.paid_on')]),
                $e->errors()['paid_on'][0]
            );
        }

        try {
            app(RecordPayment::class)->handle(array_merge($payload, ['paid_on' => '2999-01-01']));
            $this->fail('Expected ValidationException for a future payment date');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('validation.before_or_equal', ['attribute' => __('payments.paid_on'), 'date' => BusinessDate::today()]),
                $e->errors()['paid_on'][0]
            );
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    /**
     * 22:30 UTC is already the next day at the school (UTC+2), so the server's
     * UTC calendar day is one behind the school's. A payment dated the school's
     * today is not in the future and must be accepted.
     */
    public function test_payment_accepts_the_school_day_when_utc_is_a_day_behind(): void
    {
        $this->travelTo(Carbon::parse('2027-06-30 22:30:00', 'UTC'));

        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $result = app(RecordPayment::class)->handle(array_merge(
            $this->payment($enrollment->id, 10000, [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]),
            ['paid_on' => '2027-07-01'],
        ));

        $this->assertCount(1, $result['payments']);
    }

    /**
     * Same one-day offset as the payment case, for refunds.
     */
    public function test_refund_accepts_the_school_day_when_utc_is_a_day_behind(): void
    {
        $this->travelTo(Carbon::parse('2027-06-30 22:30:00', 'UTC'));

        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            10000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
        ));
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $refunds = app(RecordRefund::class)->handle(array_merge(
            $this->refund($enrollment->id, 10000, [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]),
            ['refunded_on' => '2027-07-01'],
        ));

        $this->assertCount(1, $refunds);
    }

    public function test_refund_rejects_impossible_and_future_dates(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle($this->payment(
            $enrollment->id,
            10000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
        ));

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $payload = $this->refund(
            $enrollment->id,
            10000,
            [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]]
        );

        try {
            app(RecordRefund::class)->handle(array_merge($payload, ['refunded_on' => '2026-02-30']));
            $this->fail('Expected ValidationException for an impossible refund date');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('validation.date', ['attribute' => __('payments.refunded_on')]),
                $e->errors()['refunded_on'][0]
            );
        }

        try {
            app(RecordRefund::class)->handle(array_merge($payload, ['refunded_on' => '2999-01-01']));
            $this->fail('Expected ValidationException for a future refund date');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('validation.before_or_equal', ['attribute' => __('payments.refunded_on'), 'date' => BusinessDate::today()]),
                $e->errors()['refunded_on'][0]
            );
        }

        $this->assertSame(0, Refund::query()->where('enrollment_id', $enrollment->id)->count());
    }
}
