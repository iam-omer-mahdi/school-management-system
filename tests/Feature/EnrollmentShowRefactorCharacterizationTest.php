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
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\EnrollmentBalance;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class EnrollmentShowRefactorCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Grade, AcademicYear} */
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
        return app(EnrollStudent::class)->handle(Student::factory()->create()->id, $year->id, $grade->id);
    }

    public function test_split_payment_by_cash_and_bank(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year, '5000');
        $booksFee = $this->optionalFee($grade, $year, '1500');
        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '6500')
            ->set('payAlloc.'.$tuitionFee->id, '5000')
            ->set('payAlloc.'.$booksFee->id, '1500')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(2, Payment::query()->where('enrollment_id', $enrollment->id)->count());

        $second = $this->enroll($grade, $year);
        $secondCharge = $second->fees()->firstOrFail();
        Livewire::test('pages::enrollments.show', ['enrollment' => $second->id])
            ->call('openPaymentModal')
            ->set('payTotal', '100')
            ->set('payAlloc.'.$secondCharge->grade_fee_id, '100')
            ->set('payMethod', 'bank_transfer')
            ->set('payTxn', 'BANK-1')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame('BANK-1', Payment::query()->where('enrollment_id', $second->id)->firstOrFail()->transaction_number);
    }

    public function test_amount_above_remaining_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year, '2000');
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        $enrollment = $this->enroll($grade, $year);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '2000')
            ->set('payAlloc.'.$charge->grade_fee_id, '2000')
            ->assertSee(__('payments.errors.amount_exceeds_remaining'));

        $page->call('savePayment')->assertHasErrors(['amount']);
        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());

        $page->set('payTotal', '1800')
            ->set('payAlloc.'.$charge->grade_fee_id, '1800')
            ->call('savePayment')
            ->assertHasNoErrors();
    }

    public function test_allocations_must_equal_total(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1000')
            ->set('payAlloc.'.$charge->grade_fee_id, '400')
            ->call('savePayment')
            ->assertHasErrors(['amount']);

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_payment_blocked_on_ended_enrollment(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('payments.errors.ended_no_payments'))
            ->call('openPaymentModal')
            ->set('payTotal', '100')
            ->set('payAlloc.'.$tuitionFee->id, '100')
            ->call('savePayment')
            ->assertHasErrors();

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_refund_on_ended_enrollment_partial_and_cap(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openRefundModal')
            ->set('refundTotal', '500')
            ->set('refundAlloc.'.$charge->grade_fee_id, '500')
            ->set('refundNote', 'Too much')
            ->assertSee(__('payments.errors.refund_amount_exceeds_refundable'));

        $page->call('saveRefund')->assertHasErrors(['amount']);

        $page->set('refundTotal', '150')
            ->set('refundAlloc.'.$charge->grade_fee_id, '150')
            ->set('refundNote', 'Partial return')
            ->call('saveRefund')
            ->assertHasNoErrors();

        $this->assertSame(1, Refund::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_refund_blocked_on_charged_enrollment(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertDontSee('wire:click="openRefundModal"', false)
            ->call('openRefundModal')
            ->set('refundTotal', '100')
            ->set('refundAlloc.'.$charge->grade_fee_id, '100')
            ->set('refundNote', 'Too early')
            ->call('saveRefund')
            ->assertHasErrors(['amount']);

        $this->assertSame(0, Refund::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_voids_require_reason_and_refund_dependency_blocks_payment_void(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        $payment = $enrollment->refresh()->payments()->firstOrFail();

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openVoidModal', 'payment', $payment->id)
            ->call('confirmVoid');
        $page->assertHasErrors(['void_reason']);

        $page->set('voidReason', 'Entered twice')->call('confirmVoid')->assertHasNoErrors();
        $this->assertNotNull($payment->refresh()->voided_at);
        $page->assertSee($payment->reference())->assertSee(__('payments.void'));

        $enrollment2 = $this->enroll($grade, $year);
        $charge2 = $enrollment2->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment2->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge2->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        app(UpdateEnrollmentStatus::class)->handle($enrollment2, EnrollmentStatus::Left);
        app(RecordRefund::class)->handle([
            'enrollment_id' => $enrollment2->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge2->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'refunded_on' => date('Y-m-d'), 'note' => 'Full return',
        ]);
        $payment2 = $enrollment2->refresh()->payments()->firstOrFail();

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment2->id])
            ->call('openVoidModal', 'payment', $payment2->id)
            ->set('voidReason', 'Try void with refund')
            ->call('confirmVoid')
            ->assertHasErrors(['payment_id']);

        $this->assertNull($payment2->refresh()->voided_at);

        $refund = $enrollment2->refresh()->refunds()->firstOrFail();
        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment2->id])
            ->call('openVoidModal', 'refund', $refund->id)
            ->set('voidReason', 'Undo refund')
            ->call('confirmVoid')
            ->assertHasNoErrors();
        $this->assertNotNull($refund->refresh()->voided_at);
    }

    public function test_receipt_checkboxes_and_print_links(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        $payment = $enrollment->refresh()->payments()->firstOrFail();
        $url = route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => (string) $payment->id]);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee($payment->reference(), false)
            ->assertSee($url, false);

        app(VoidPayment::class)->handle($enrollment->id, $payment->id, 'Entered twice');

        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee('value="'.$payment->id.'" disabled', false)
            ->assertSee($url, false);
        $page->set('selectedReceiptIds', [(string) $payment->id])
            ->assertDontSee(__('payments.print_receipt'), false);
    }

    public function test_status_warning_discount_and_optional_flows(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $booksFee = $this->optionalFee($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 30000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 30000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openStatusModal', 'left')
            ->assertSee(__('payments.will_no_longer_be_owed', ['amount' => Money::format(70000)]))
            ->call('confirmStatusChange')
            ->assertHasNoErrors();
        $this->assertSame(EnrollmentStatus::Left, $enrollment->refresh()->status);

        $enrollment2 = $this->enroll($grade, $year);
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        $page = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment2->id])
            ->set('discountChoice', (string) $discount->id)
            ->call('applyDiscount')
            ->assertHasNoErrors();
        $this->assertSame(1, $enrollment2->refresh()->discount()->count());
        $page->call('removeDiscount')->assertHasNoErrors();
        $this->assertSame(0, $enrollment2->refresh()->discount()->count());

        $page2 = Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment2->id])
            ->set('optionalChoice', (string) $booksFee->id)
            ->call('addOptional')
            ->assertHasNoErrors();
        $this->assertSame(2, $enrollment2->refresh()->fees()->count());
        $page2->call('removeOptional', $booksFee->id)->assertHasNoErrors();
        $this->assertSame(1, $enrollment2->refresh()->fees()->count());
    }

    public function test_reactivation_after_full_refund_owes_full_charge(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = $this->scheduleTuition($grade, $year, '5000');
        $enrollment = $this->enroll($grade, $year);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '4000')
            ->set('payAlloc.'.$tuitionFee->id, '4000')
            ->call('savePayment')
            ->assertHasNoErrors();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openRefundModal')
            ->set('refundTotal', '4000')
            ->set('refundAlloc.'.$tuitionFee->id, '4000')
            ->set('refundNote', 'Full return')
            ->call('saveRefund')
            ->assertHasNoErrors();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Active);

        $balance = EnrollmentBalance::forEnrollment($enrollment->refresh()->load(['fees.gradeFee.feeType', 'fees.payments', 'discount', 'payments', 'refunds']));
        $this->assertSame(500000, $balance['charged']);
        $this->assertSame(0, $balance['net_paid']);
        $this->assertSame(500000, $balance['owed']);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.total_due'), false)
            ->assertSee(Money::format(500000), false);
    }

    public function test_key_behaviors_are_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('payTotal', '1000')
            ->set('payAlloc.'.$charge->grade_fee_id, '100')
            ->call('savePayment')
            ->assertHasErrors();

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', __('payments.unallocated'));
    }

    public function test_page_load_query_baseline(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee($enrollment->student->name);

        $this->assertLessThanOrEqual(40, $count, 'Page load queries: '.$count);
    }

    public function test_pay_total_keystroke_query_baseline(): void
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

        $page->set('payTotal', '100');

        $this->assertLessThan(20, $count, 'PayTotal keystroke queries: '.$count);
    }
}
