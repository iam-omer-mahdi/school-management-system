<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\VoidPayment;
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
use Tests\TestCase;

class ReceiptPageTest extends TestCase
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

    private function paidEnrollment(Grade $grade, AcademicYear $year, string $amount = '400'): Enrollment
    {
        if (! GradeFee::query()->where('grade_id', $grade->id)->where('academic_year_id', $year->id)->where('fee_type_id', $this->tuition()->id)->exists()) {
            app(CreateGradeFee::class)->handle([
                'grade_id' => $grade->id,
                'academic_year_id' => $year->id,
                'fee_type_id' => $this->tuition()->id,
                'amount' => '1000',
            ]);
        }

        $student = Student::factory()->create(['name' => 'Receipt Student']);
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        $charge = $enrollment->fees()->firstOrFail();

        $minor = Money::fromDecimal($amount);

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => $minor,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => $minor]],
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        return $enrollment->refresh();
    }

    private function receiptUrl(Enrollment $enrollment, string $payments): string
    {
        return route('enrollments.receipt', ['enrollment' => $enrollment->id, 'payments' => $payments]);
    }

    public function test_receipt_requires_auth(): void
    {
        $this->get(route('enrollments.receipt', 1))->assertRedirect(route('login'));
    }

    public function test_receipt_uses_the_same_missing_enrollment_gate_as_the_show_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('enrollments.show', 999999))->assertNotFound();
        $this->get(route('enrollments.receipt', ['enrollment' => 999999, 'payments' => '1']))->assertNotFound();
    }

    public function test_prints_one_payment_with_reference(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $payment = $enrollment->payments()->firstOrFail();

        $this->get($this->receiptUrl($enrollment, (string) $payment->id))
            ->assertOk()
            ->assertSee(__('payments.receipt'), false)
            ->assertSee($payment->reference(), false)
            ->assertSee(__('payments.reference'), false)
            ->assertSee(__('payments.transaction_number'), false)
            ->assertSee(__('payments.paid_on'), false)
            ->assertSee('Receipt Student')
            ->assertSee($enrollment->student->student_number)
            ->assertSee(Money::format(40000), false)
            ->assertSee(__('payments.cash'));
    }

    public function test_prints_several_payments_with_total(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);

        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 10000]],
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $ids = Payment::query()->where('enrollment_id', $enrollment->id)->pluck('id')->implode(',');

        $this->get($this->receiptUrl($enrollment, $ids))
            ->assertOk()
            ->assertSee(Money::format(40000), false)
            ->assertSee(Money::format(10000), false)
            ->assertSee(Money::format(50000), false);
    }

    public function test_voided_payment_shows_void_mark_and_reason(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $payment = $enrollment->payments()->firstOrFail();

        app(VoidPayment::class)->handle($payment->enrollment_id, $payment->id, 'Entered twice');

        $this->get($this->receiptUrl($enrollment, (string) $payment->id))
            ->assertOk()
            ->assertSee(__('payments.void'))
            ->assertSee('Entered twice');
    }

    public function test_multi_select_total_excludes_voided_rows(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);

        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id,
            'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 10000]],
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
        ]);

        $voided = $enrollment->payments()->orderBy('id')->firstOrFail();
        app(VoidPayment::class)->handle($enrollment->id, $voided->id, 'Entered twice');

        $ids = Payment::query()->where('enrollment_id', $enrollment->id)->pluck('id')->implode(',');

        $this->get($this->receiptUrl($enrollment, $ids))
            ->assertOk()
            ->assertSee(__('payments.void'))
            ->assertSee(Money::format(10000), false)
            ->assertDontSee(Money::format(50000), false);
    }

    public function test_rejects_ids_from_other_enrollments(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $other = $this->paidEnrollment($grade, $year);

        $foreign = $other->payments()->firstOrFail()->id;

        $this->get($this->receiptUrl($enrollment, (string) $foreign))->assertNotFound();
        $this->get(route('enrollments.receipt', $enrollment))->assertNotFound();
    }

    public function test_receipt_renders_in_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $payment = $enrollment->payments()->firstOrFail();

        $this->get($this->receiptUrl($enrollment, (string) $payment->id))
            ->assertOk()
            ->assertSee('إيصال', false)
            ->assertSee('نقدًا')
            ->assertSee($payment->reference(), false);
    }
}
