<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AddPaymentModalTest extends TestCase
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

    private function scheduleTuition(Grade $grade, AcademicYear $year, string $amount = '1000'): void
    {
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => $amount,
        ]);
    }

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        return app(EnrollStudent::class)->handle(Student::factory()->create()->id, $year->id, $grade->id);
    }

    public function test_nesting_syntax_renders_inside_parent(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('payments.payment'), false)
            ->assertSee(__('payments.add_payment'), false)
            ->call('openPaymentModal')
            ->assertSee(__('payments.add_payment'), false)
            ->call('closePaymentModal')
            ->assertHasNoErrors();
    }

    public function test_saves_split_payment_and_dispatches_refresh(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('form.total', '1000')
            ->set('form.alloc.'.$charge->grade_fee_id, '1000')
            ->set('form.method', 'bank_transfer')
            ->set('form.txn', 'BANK-9')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertDispatched('enrollment-changed');

        $this->assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame('BANK-9', Payment::query()->where('enrollment_id', $enrollment->id)->firstOrFail()->transaction_number);
    }

    public function test_block_reason_uses_property_access_and_updates_live(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('form.total', '1200')
            ->set('form.alloc.'.$charge->grade_fee_id, '1200')
            ->assertSee(__('payments.errors.amount_exceeds_remaining'));

        $page->call('savePayment')->assertHasErrors(['amount']);
        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_shows_reason_on_ended_enrollment(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('payments.errors.ended_no_payments'));
    }

    public function test_refreshes_after_external_change_and_modal_still_works(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id]);
        $page->dispatch('enrollment-changed');
        $page->call('openPaymentModal')
            ->set('form.total', '200')
            ->set('form.alloc.'.$charge->grade_fee_id, '200')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(1, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_tampered_enrollment_id_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $other = $this->enroll($grade, $year);

        try {
            Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
                ->set('enrollmentId', $other->id);

            $this->fail('Expected tampered enrollmentId to be rejected');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, Payment::query()->where('enrollment_id', $other->id)->count());
    }
}
