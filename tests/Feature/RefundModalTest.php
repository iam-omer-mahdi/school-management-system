<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Refund;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class RefundModalTest extends TestCase
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

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        return app(EnrollStudent::class)->handle(Student::factory()->create()->id, $year->id, $grade->id);
    }

    private function endedPaidEnrollment(Grade $grade, AcademicYear $year, string $amount = '1000'): Enrollment
    {
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => $amount,
        ]);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        $minor = Money::fromDecimal($amount);
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => $minor,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => $minor]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        return $enrollment->refresh();
    }

    public function test_saves_partial_refund_and_dispatches_refresh(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->endedPaidEnrollment($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id])
            ->call('openRefundModal')
            ->set('form.total', '200')
            ->set('form.alloc.'.$charge->grade_fee_id, '200')
            ->set('form.note', 'Partial return')
            ->call('saveRefund')
            ->assertHasNoErrors()
            ->assertDispatched('enrollment-changed');

        $this->assertSame(1, Refund::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_cap_and_note_gates(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->endedPaidEnrollment($grade, $year, '400');
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id])
            ->call('openRefundModal')
            ->set('form.total', '500')
            ->set('form.alloc.'.$charge->grade_fee_id, '500')
            ->set('form.note', 'Too much')
            ->assertSee(__('payments.errors.refund_amount_exceeds_refundable'));

        $page->call('saveRefund')->assertHasErrors(['amount']);

        $page->set('form.total', '100')
            ->set('form.alloc.'.$charge->grade_fee_id, '100')
            ->set('form.note', '')
            ->assertSee(__('validation.required', ['attribute' => __('fees.note')]), false);
    }

    public function test_hidden_trigger_on_charged_enrollment(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => '1000',
        ]);
        $enrollment = $this->enroll($grade, $year);

        Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id])
            ->assertDontSee('wire:click="openRefundModal"', false);
    }

    public function test_modal_still_works_after_external_refresh(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->endedPaidEnrollment($grade, $year);

        $page = Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id]);
        $page->dispatch('enrollment-changed');
        $page->call('openRefundModal')
            ->call('closeRefundModal')
            ->assertHasNoErrors();
    }

    public function test_refund_flow_is_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->endedPaidEnrollment($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id])
            ->call('openRefundModal')
            ->set('form.total', '100')
            ->set('form.alloc.'.$charge->grade_fee_id, '100')
            ->set('form.note', 'إرجاع')
            ->call('saveRefund')
            ->assertHasNoErrors();
    }
}
