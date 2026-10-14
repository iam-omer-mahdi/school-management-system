<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\UpdateEnrollmentStatus;
use App\Actions\VoidPayment;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentKind;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentPageTest extends TestCase
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

    private function scheduleTuition(Grade $grade, AcademicYear $year, string $amount = '1000'): GradeFee
    {
        return app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => $amount,
        ])->gradeFee;
    }

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        $student = Student::factory()->create();

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    public function test_split_payment_through_modal_updates_totals(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);
        $tuitionCharge = $enrollment->fees()->where('grade_fee_id', $tuitionFee->id)->firstOrFail();
        $booksCharge = $enrollment->fees()->where('grade_fee_id', $booksFee->id)->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '650')
            ->set('payAlloc.'.$tuitionCharge->grade_fee_id, '500')
            ->set('payAlloc.'.$booksCharge->grade_fee_id, '150')
            ->assertSee(__('payments.unallocated'))
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(2, Payment::query()->where('enrollment_id', $enrollment->id)->count());
        $page->assertSee(Money::format(65000))
            ->assertSee(Money::format(50000));
    }

    public function test_save_blocked_until_allocated_equals_total(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1000')
            ->set('payAlloc.'.$charge->grade_fee_id, '400')
            ->call('savePayment');

        $page->assertHasErrors(['payTotal']);
        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_overpayment_offered_and_recorded_on_confirm(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1200')
            ->set('payAlloc.'.$charge->grade_fee_id, '1000')
            ->assertSee(__('payments.record_overpayment'))
            ->call('savePayment');

        // Unconfirmed overpayment keeps Save disabled server-side too.
        $page->assertHasErrors(['payTotal']);

        $page->set('confirmOverpayment', true)
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->where('kind', 'overpayment')->count());
        $page->assertSee(__('payments.overpaid'));
    }

    public function test_transaction_reuse_warns_without_blocking(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $first = $this->enroll($grade, $year);
        $firstCharge = $first->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $first->id,
            'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $firstCharge->grade_fee_id, 'amount_minor' => 10000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'bank_transfer',
            'transaction_number' => 'SHARED-TXN',
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $second = $this->enroll($grade, $year);
        $secondCharge = $second->fees()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $second->id])
            ->call('openPaymentModal')
            ->set('payMethod', 'bank_transfer')
            ->set('payTxn', 'SHARED-TXN')
            ->assertSee(__('payments.transaction_reused'))
            ->set('payTotal', '100')
            ->set('payAlloc.'.$secondCharge->grade_fee_id, '100')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(1, Payment::query()->where('enrollment_id', $second->id)->count());
    }

    public function test_void_payment_through_page_keeps_row_visible(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $payment = app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 20000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 20000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ])['payments']->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openVoidModal', 'payment', $payment->id)
            ->call('confirmVoid');

        $page->assertHasErrors(['void_reason']);

        $page->set('voidReason', 'Entered twice')
            ->call('confirmVoid')
            ->assertHasNoErrors();

        $this->assertNotNull($payment->refresh()->voided_at);
        $page->assertSee($payment->reference())
            ->assertSee(__('payments.void'));
    }

    public function test_ended_enrollment_hides_add_but_shows_closed_figures(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 30000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 30000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('payments.errors.ended_no_payments'))
            ->assertDontSee(__('payments.add_payment'))
            ->assertSee(__('payments.closed_without_payment'))
            ->assertSee(Money::format(70000))
            ->assertSee(Money::format(30000));
    }

    public function test_status_modal_warns_about_remaining_before_ending(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 30000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 30000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openStatusModal', 'left')
            ->assertSee(__('payments.will_no_longer_be_owed', ['amount' => Money::format(70000)]))
            ->call('confirmStatusChange')
            ->assertHasNoErrors();

        $this->assertSame(EnrollmentStatus::Left, $enrollment->refresh()->status);
    }

    public function test_refund_flow_through_page(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 50000,
            'rows' => [],
            'overpayment_minor' => 50000,
            'overpayment_confirmed' => true,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openRefundModal')
            ->set('refundAmount', '200')
            ->set('refundNote', 'Partial return')
            ->call('saveRefund')
            ->assertHasNoErrors();

        $page->assertSee(Money::format(20000))
            ->assertSee(__('payments.refunds'));
    }

    public function test_payment_errors_are_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1000')
            ->set('payAlloc.'.$charge->grade_fee_id, '100')
            ->call('savePayment');

        $page->assertHasErrors(['payTotal']);

        foreach ($page->errors()->all() as $message) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message);
        }

        $this->assertSame('غير مخصص', __('payments.unallocated'));
    }

    public function test_void_rejects_payment_from_another_enrollment(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year);

        $first = $this->enroll($grade, $year);
        $second = $this->enroll($grade, $year);

        $make = fn (Enrollment $e): Payment => app(RecordPayment::class)->handle([
            'enrollment_id' => $e->id,
            'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 10000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ])['payments']->firstOrFail();

        $foreign = $make($second);

        Livewire::test('pages::enrollments.show', ['enrollment' => $first->id])
            ->call('openVoidModal', 'payment', $foreign->id)
            ->set('voidReason', 'Sneaky')
            ->call('confirmVoid')
            ->assertHasErrors(['payment_id']);

        $this->assertNull($foreign->refresh()->voided_at);

        try {
            app(VoidPayment::class)->handle($first->id, $foreign->id, 'Sneaky');
            $this->fail('Expected ValidationException for cross-enrollment void');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_id', $e->errors());
        }
    }

    public function test_tampered_status_and_void_type_are_rejected_without_server_error(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openStatusModal', 'bogus')
            ->assertHasErrors(['status']);

        $this->assertSame(EnrollmentStatus::Active, $enrollment->refresh()->status);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openVoidModal', 'bogus', 1)
            ->assertHasErrors(['voidTargetType']);
    }

    public function test_pay_gate_shows_specific_reason_for_each_state(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $numericReason = __('validation.numeric', ['attribute' => __('payments.amount')]);
        $txnReason = __('validation.required', ['attribute' => __('payments.transaction_number')]);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->assertSee($numericReason, false);

        $page->set('payTotal', '1000')
            ->assertSee(__('payments.unallocated').': '.Money::format(100000), false)
            ->assertDontSee($txnReason, false);

        $page->set('payAlloc.'.$charge->grade_fee_id, '1200')
            ->assertSee(__('payments.errors.total_mismatch'));

        $page->set('payAlloc.'.$charge->grade_fee_id, '1000')
            ->set('payMethod', 'bank_transfer')
            ->assertSee($txnReason, false);

        $page->set('payTxn', 'TXN-1')
            ->assertDontSee($txnReason, false)
            ->assertDontSee($numericReason, false)
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_pay_gate_overpayment_needs_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $reason = __('payments.unallocated').': '.Money::format(20000);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1200')
            ->set('payAlloc.'.$charge->grade_fee_id, '1000')
            ->assertSee($reason, false)
            ->call('savePayment');

        $page->assertHasErrors(['payTotal']);
        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());

        $page->set('confirmOverpayment', true)
            ->assertDontSee($reason, false)
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(2, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_method_switch_clears_stale_transaction_error(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $txnReason = __('validation.required', ['attribute' => __('payments.transaction_number')]);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1000')
            ->set('payAlloc.'.$charge->grade_fee_id, '1000')
            ->set('payMethod', 'bank_transfer')
            ->call('savePayment')
            ->assertHasErrors(['transaction_number']);

        $page->set('payMethod', 'cash')
            ->assertHasNoErrors();

        $page->set('payMethod', 'bank_transfer')
            ->assertHasNoErrors()
            ->assertSee($txnReason, false);

        $page->set('payTxn', 'FRESH-TXN')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame('FRESH-TXN', Payment::query()->where('enrollment_id', $enrollment->id)->firstOrFail()->transaction_number);
    }

    public function test_switching_method_clears_transaction_number_and_stays_stable(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1000')
            ->set('payAlloc.'.$charge->grade_fee_id, '1000')
            ->set('payMethod', 'bank_transfer')
            ->set('payTxn', 'STALE-TXN');

        for ($i = 0; $i < 5; $i++) {
            $page->set('payMethod', 'cash')->assertSet('payTxn', null);
            $page->set('payMethod', 'bank_transfer')->assertSet('payTxn', null);
        }

        $page->assertHasNoErrors()
            ->assertSee(__('validation.required', ['attribute' => __('payments.transaction_number')]), false);

        $page->set('payTxn', 'FRESH-TXN')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame('FRESH-TXN', Payment::query()->where('enrollment_id', $enrollment->id)->firstOrFail()->transaction_number);
    }

    public function test_voided_payment_checkbox_is_disabled_but_printable(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $payment = $enrollment->payments()->firstOrFail();
        app(VoidPayment::class)->handle($enrollment->id, $payment->id, 'Entered twice');

        $receiptUrl = route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => (string) $payment->id]);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee('value="'.$payment->id.'" disabled', false)
            ->assertSee($receiptUrl, false);

        $page->set('selectedReceiptIds', [(string) $payment->id])
            ->assertDontSee(__('payments.print_receipt'), false);
    }

    public function test_applying_discount_after_full_payment_is_blocked(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year, '2000');
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 200000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 200000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->set('discountChoice', (string) $discount->id)
            ->call('applyDiscount')
            ->assertHasErrors(['discount_id'])
            ->assertSee(__('fees.errors.discount_exceeds_paid'), false);

        $this->assertSame(0, $enrollment->refresh()->discount()->count());
    }

    public function test_removing_optional_fee_with_payments_is_blocked(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 15000,
            'rows' => [['grade_fee_id' => $booksFee->id, 'amount_minor' => 15000]],
            'overpayment_minor' => 0,
            'overpayment_confirmed' => false,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('removeOptional', $booksFee->id)
            ->assertHasErrors(['grade_fee_id']);

        $this->assertSame(2, $enrollment->refresh()->fees()->count());

        // Voiding keeps the payment history, so the charge stays
        // locked: removal is still rejected with a clear message.
        $payment = $enrollment->payments()->firstOrFail();
        app(VoidPayment::class)->handle($enrollment->id, $payment->id, 'Entered twice');

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('removeOptional', $booksFee->id)
            ->assertHasErrors(['grade_fee_id']);

        $this->assertSame(2, $enrollment->refresh()->fees()->count());
    }

    public function test_gate_keystroke_stays_within_query_bound(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $page->set('payTotal', '100')
            ->set('payMethod', 'bank_transfer');

        $count = 0;
        $page->set('payTxn', 'TXN-1');

        $this->assertLessThan(20, $count);
    }

    public function test_cash_split_and_decimal_amounts_through_modal(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year, '5000');

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '1,250.50',
        ])->gradeFee;

        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '6,250.50')
            ->set('payAlloc.'.$tuitionFee->id, '5000')
            ->set('payAlloc.'.$booksFee->id, '1,250.50')
            ->call('savePayment')
            ->assertHasNoErrors();

        $rows = Payment::query()->where('enrollment_id', $enrollment->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(Money::fromDecimal('5000'), (int) $rows[0]->amount_minor);
        $this->assertSame(Money::fromDecimal('1250.50'), (int) $rows[1]->amount_minor);
        $this->assertEquals(Money::fromDecimal('6250.50'), $rows->sum('amount_minor'));
    }

    public function test_ended_enrollment_save_blocked_through_direct_component_call(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '100')
            ->set('payAlloc.'.$tuitionFee->id, '100')
            ->call('savePayment')
            ->assertHasErrors();

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_displayed_totals_stay_fresh_after_void_and_refund(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $payment = app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 120000,
            'rows' => [['grade_fee_id' => $tuitionFee->id, 'amount_minor' => 100000]],
            'overpayment_minor' => 20000,
            'overpayment_confirmed' => true,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ])['payments'];

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(Money::format(100000), false)
            ->assertSee(Money::format(20000), false);

        $page->call('openVoidModal', 'payment', $payment->firstWhere('kind', PaymentKind::Fee)->id)
            ->set('voidReason', 'Entered twice')
            ->call('confirmVoid')
            ->assertHasNoErrors()
            ->assertSee(Money::format(100000), false);

        $page->call('openRefundModal')
            ->set('refundAmount', '50')
            ->set('refundNote', 'Partial return')
            ->call('saveRefund')
            ->assertHasNoErrors()
            ->assertSee(Money::format(15000), false);
    }

    public function test_discounted_overpayment_stores_minor_units_once(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year, '200000');
        $enrollment = $this->enroll($grade, $year);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '200000')
            ->set('payAlloc.'.$tuitionFee->id, '180000')
            ->set('confirmOverpayment', true)
            ->call('savePayment')
            ->assertHasNoErrors();

        $rows = Payment::query()->where('enrollment_id', $enrollment->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $this->assertTrue($rows[0]->kind === PaymentKind::Fee);
        $this->assertSame(Money::fromDecimal('180000'), (int) $rows[0]->amount_minor);

        $this->assertTrue($rows[1]->kind === PaymentKind::Overpayment);
        $this->assertSame(Money::fromDecimal('20000'), (int) $rows[1]->amount_minor);

        $this->assertEquals(
            Money::fromDecimal('200000'),
            $rows->sum('amount_minor')
        );
    }
}
