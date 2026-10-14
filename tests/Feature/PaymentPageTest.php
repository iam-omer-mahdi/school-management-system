<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
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
            'rows' => [['grade_fee_id' => $firstCharge->grade_fee_id, 'amount' => '100']],
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
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount' => '200']],
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
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount' => '300']],
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
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount' => '300']],
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
            'rows' => [['grade_fee_id' => null, 'amount' => '500']],
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
}
