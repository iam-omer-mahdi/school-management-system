<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VoidModalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Grade, AcademicYear} */
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

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        return app(EnrollStudent::class)->handle(Student::factory()->create()->id, $year->id, $grade->id);
    }

    private function paidEnrollment(Grade $grade, AcademicYear $year): Enrollment
    {
        if (! GradeFee::query()->where('grade_id', $grade->id)->where('academic_year_id', $year->id)->exists()) {
            app(CreateGradeFee::class)->handle([
                'grade_id' => $grade->id, 'academic_year_id' => $year->id,
                'fee_type_id' => $this->tuition()->id, 'amount' => '1000',
            ]);
        }
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);

        return $enrollment->refresh();
    }

    public function test_void_payment_via_event_keeps_row_visible(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $payment = $enrollment->payments()->firstOrFail();

        $page = Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'payment', id: $payment->id)
            ->assertSee(__('payments.void_reason'), false)
            ->call('confirmVoid');

        $page->assertHasErrors(['void_reason']);

        $page->set('reason', 'Entered twice')
            ->call('confirmVoid')
            ->assertHasNoErrors()
            ->assertDispatched('enrollment-changed');

        $this->assertNotNull($payment->refresh()->voided_at);
    }

    public function test_forged_void_type_and_foreign_id_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $other = $this->paidEnrollment($grade, $year);
        $foreign = $other->payments()->firstOrFail();

        Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'bogus', id: 1)
            ->assertHasErrors(['voidTargetType']);

        Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'payment', id: $foreign->id)
            ->set('reason', 'Sneaky')
            ->call('confirmVoid')
            ->assertHasErrors(['payment_id']);

        $this->assertNull($foreign->refresh()->voided_at);
    }

    public function test_payment_void_blocked_by_refund_dependency(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);
        app(RecordRefund::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'refunded_on' => date('Y-m-d'), 'note' => 'Full return',
        ]);
        $payment = $enrollment->refresh()->payments()->firstOrFail();

        Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'payment', id: $payment->id)
            ->set('reason', 'Try void with refund')
            ->call('confirmVoid')
            ->assertHasErrors(['payment_id']);

        $this->assertNull($payment->refresh()->voided_at);
    }

    public function test_void_refund_via_event(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);
        app(RecordRefund::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 10000]],
            'method' => 'cash', 'transaction_number' => null, 'refunded_on' => date('Y-m-d'), 'note' => 'Partial',
        ]);
        $refund = $enrollment->refresh()->refunds()->firstOrFail();

        Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'refund', id: $refund->id)
            ->set('reason', 'Undo refund')
            ->call('confirmVoid')
            ->assertHasNoErrors()
            ->assertDispatched('enrollment-changed');

        $this->assertNotNull($refund->refresh()->voided_at);
    }

    public function test_modal_still_opens_after_external_refresh(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->paidEnrollment($grade, $year);
        $payment = $enrollment->payments()->firstOrFail();

        $page = Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id]);
        $page->dispatch('enrollment-changed');
        $page->dispatch('request-void', type: 'payment', id: $payment->id)
            ->assertSee(__('payments.void_reason'), false)
            ->call('closeVoidModal')
            ->assertHasNoErrors();
    }
}
