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
use App\Actions\UpdateEnrollmentStatus;
use App\Actions\VoidPayment;
use App\Actions\VoidRefund;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentKind;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        return [$grade, $year];
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    private function tuitionFee(Grade $grade, AcademicYear $year): GradeFee
    {
        return app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ])->gradeFee;
    }

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        $student = Student::factory()->create();

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    /**
     * @return array{enrollment_id: int, total_minor: int, rows: array<int, array{grade_fee_id: int, amount_minor: int}>, overpayment_minor: int, overpayment_confirmed: bool, method: string, transaction_number: null, paid_on: string, note: null}
     */
    private function fullPayment(Enrollment $enrollment): array
    {
        $fee = $enrollment->fees()->firstOrFail();

        return [
            'enrollment_id' => $enrollment->id,
            'total_minor' => 100000,
            'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 100000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ];
    }

    /**
     * @return array{enrollment_id: int, total_minor: int, rows: array<int>, overpayment_minor: int, overpayment_confirmed: bool, method: string, transaction_number: null, paid_on: string, note: null}
     */
    private function pureOverpayment(Enrollment $enrollment, int $totalMinor): array
    {
        return [
            'enrollment_id' => $enrollment->id,
            'total_minor' => $totalMinor,
            'rows' => [],
            'overpayment_minor' => $totalMinor,
            'overpayment_confirmed' => true,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ];
    }

    public function test_records_partial_and_full_payments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $partial = app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 40000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $this->assertSame(40000, $partial['payments']->firstOrFail()->amount_minor);
        $this->assertFalse($partial['transaction_reused']);

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 60000,
            'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 60000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $this->assertEquals(100000, Payment::query()->where('enrollment_id', $enrollment->id)->sum('amount_minor'));
    }

    public function test_splits_one_total_across_several_fees_in_one_transaction(): void
    {
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->tuitionFee($grade, $year);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);
        $tuitionCharge = $enrollment->fees()->where('grade_fee_id', $tuitionFee->id)->firstOrFail();
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        $result = app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 65000,
            'rows' => [
                ['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 50000],
                ['grade_fee_id' => $booksFee->id, 'amount_minor' => 15000],
            ],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'bank_transfer',
            'transaction_number' => 'TXN-1',
            'paid_on' => date('Y-m-d'),
            'note' => 'Split',
        ]);

        $this->assertSame(2, $result['payments']->count());
        $this->assertSame(['TXN-1'], $result['payments']->pluck('transaction_number')->unique()->all());
        $this->assertSame(65000, $result['payments']->sum('amount_minor'));
    }

    public function test_fee_row_cannot_exceed_remaining(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        app(RecordPayment::class)->handle($this->fullPayment($enrollment));

        try {
            app(RecordPayment::class)->handle($this->fullPayment($enrollment));
            $this->fail('Expected ValidationException for overpayment of a fee row');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows.0.amount', $e->errors());
            $this->assertSame(__('payments.errors.amount_exceeds_remaining'), $e->errors()['rows.0.amount'][0]);
        }
    }

    public function test_bank_transfer_requires_transaction_number_and_cash_does_not(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $base = [
            'enrollment_id' => $enrollment->id,
            'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 10000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ];

        try {
            app(RecordPayment::class)->handle($base + ['method' => 'bank_transfer', 'transaction_number' => null]);
            $this->fail('Expected ValidationException for missing transaction number');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transaction_number', $e->errors());
        }

        $created = app(RecordPayment::class)->handle($base + ['method' => 'cash', 'transaction_number' => null]);
        $this->assertNull($created['payments']->firstOrFail()->transaction_number);

        $created = app(RecordPayment::class)->handle($base + ['method' => 'bank_transfer', 'transaction_number' => 'TXN-9']);
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

        $make = fn (Enrollment $e, int $feeId): array => [
            'enrollment_id' => $e->id,
            'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $feeId, 'amount_minor' => 10000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'bank_transfer',
            'transaction_number' => 'SHARED-TXN',
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ];

        $this->assertFalse(app(RecordPayment::class)->handle($make($first, $firstFee->grade_fee_id))['transaction_reused']);
        $this->assertTrue(app(RecordPayment::class)->handle($make($second, $secondFee->grade_fee_id))['transaction_reused']);
    }

    public function test_overpayment_row_excluded_from_fees_but_counted_in_cash(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $result = app(RecordPayment::class)->handle($this->pureOverpayment($enrollment, 50000));

        $overpayment = $result['payments']->firstOrFail();
        $this->assertTrue($overpayment->isOverpayment());
        $this->assertNull($overpayment->enrollment_fee_id);
        $this->assertSame(PaymentKind::Overpayment, $overpayment->kind);
    }

    public function test_payments_blocked_on_ended_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        try {
            app(RecordPayment::class)->handle($this->fullPayment($enrollment));
            $this->fail('Expected ValidationException for payment on ended enrollment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.ended_no_payments'), $e->errors()['enrollment_id'][0]);
        }
    }

    public function test_void_excludes_from_totals_and_requires_reason(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $payment = app(RecordPayment::class)->handle($this->fullPayment($enrollment))['payments']->firstOrFail();

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

    public function test_refund_caps_at_outstanding_and_leaves_fees_untouched(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        app(RecordPayment::class)->handle($this->pureOverpayment($enrollment, 50000));

        $refund = app(RecordRefund::class)->handle([
            'enrollment_id' => $enrollment->id,
            'amount' => '200',
            'method' => 'cash',
            'transaction_number' => null,
            'refunded_on' => date('Y-m-d'),
            'note' => 'Partial return',
        ]);

        $this->assertSame(20000, $refund->amount_minor);
        $this->assertSame(1, $enrollment->refresh()->fees()->count());

        try {
            app(RecordRefund::class)->handle([
                'enrollment_id' => $enrollment->id,
                'amount' => '400',
                'method' => 'cash',
                'transaction_number' => null,
                'refunded_on' => date('Y-m-d'),
                'note' => 'Too much',
            ]);
            $this->fail('Expected ValidationException for refund above outstanding');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.refund_exceeds_outstanding'), $e->errors()['amount'][0]);
        }
    }

    public function test_void_refund_restores_overpaid_and_void_overpayment_blocked_by_refunds(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $overpayment = app(RecordPayment::class)->handle($this->pureOverpayment($enrollment, 50000))['payments']->firstOrFail();

        $refund = app(RecordRefund::class)->handle([
            'enrollment_id' => $enrollment->id,
            'amount' => '500',
            'method' => 'cash',
            'transaction_number' => null,
            'refunded_on' => date('Y-m-d'),
            'note' => 'Full return',
        ]);

        try {
            app(VoidPayment::class)->handle($overpayment->enrollment_id, $overpayment->id, 'Undo');
            $this->fail('Expected ValidationException when voiding refunded overpayment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.void_blocked_by_refunds'), $e->errors()['payment_id'][0]);
        }

        app(VoidRefund::class)->handle($refund->enrollment_id, $refund->id, 'Mistake');
        $this->assertNotNull($refund->refresh()->voided_at);

        app(VoidPayment::class)->handle($overpayment->enrollment_id, $overpayment->id, 'Undo');
        $this->assertNotNull($overpayment->refresh()->voided_at);
    }

    public function test_refund_and_void_allowed_on_ended_enrollments(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $overpayment = app(RecordPayment::class)->handle($this->pureOverpayment($enrollment, 50000))['payments']->firstOrFail();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $refund = app(RecordRefund::class)->handle([
            'enrollment_id' => $enrollment->id,
            'amount' => '200',
            'method' => 'bank_transfer',
            'transaction_number' => 'TXN-R',
            'refunded_on' => date('Y-m-d'),
            'note' => 'Return',
        ]);

        $this->assertSame(20000, $refund->amount_minor);

        app(VoidRefund::class)->handle($refund->enrollment_id, $refund->id, 'Mistake');
        app(VoidPayment::class)->handle($overpayment->enrollment_id, $overpayment->id, 'Undo');

        $this->assertNotNull($refund->refresh()->voided_at);
        $this->assertNotNull($overpayment->refresh()->voided_at);
    }

    public function test_discount_blocked_when_paid_would_exceed_charged(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 100000,
            'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 100000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

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

    public function test_mismatched_overpayment_is_rejected_and_stores_nothing(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        $payload = [
            'enrollment_id' => $enrollment->id,
            'total_minor' => 100000,
            'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 80000]],
            'overpayment_minor' => 10000,
            'overpayment_confirmed' => true,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ];

        try {
            app(RecordPayment::class)->handle($payload);
            $this->fail('Expected ValidationException for mismatched overpayment');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.total_mismatch'), $e->errors()['amount'][0]);
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_overpayment_without_confirmation_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        try {
            app(RecordPayment::class)->handle([
                'enrollment_id' => $enrollment->id,
                'total_minor' => 100000,
                'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 80000]],
                'overpayment_minor' => 20000,
                'overpayment_confirmed' => false,
                'method' => 'cash',
                'transaction_number' => null,
                'paid_on' => date('Y-m-d'),
                'note' => null,
            ]);
            $this->fail('Expected ValidationException for missing overpayment confirmation');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.overpayment_confirmation_required'), $e->errors()['amount'][0]);
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_tampered_fee_row_amount_is_rejected(): void
    {
        [$grade, $year] = $this->fixtures();
        $this->tuitionFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $fee = $enrollment->fees()->firstOrFail();

        try {
            app(RecordPayment::class)->handle([
                'enrollment_id' => $enrollment->id,
                'total_minor' => 120000,
                'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 120000]],
                'overpayment_minor' => 0,
                'overpayment_confirmed' => false,
                'method' => 'cash',
                'transaction_number' => null,
                'paid_on' => date('Y-m-d'),
                'note' => null,
            ]);
            $this->fail('Expected ValidationException for fee row above remaining');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.amount_exceeds_remaining'), $e->errors()['rows.0.amount'][0]);
        }

        try {
            app(RecordPayment::class)->handle([
                'enrollment_id' => $enrollment->id,
                'total_minor' => 50000,
                'rows' => [['grade_fee_id' => $fee->grade_fee_id, 'amount_minor' => 80000]],
                'overpayment_minor' => 0,
                'overpayment_confirmed' => false,
                'method' => 'cash',
                'transaction_number' => null,
                'paid_on' => date('Y-m-d'),
                'note' => null,
            ]);
            $this->fail('Expected ValidationException for rows exceeding the total');
        } catch (ValidationException $e) {
            $this->assertSame(__('payments.errors.total_mismatch'), $e->errors()['amount'][0]);
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }
}
