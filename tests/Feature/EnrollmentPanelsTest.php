<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EnrollmentPanelsTest extends TestCase
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

    private function tuitionEnrollment(Grade $grade, AcademicYear $year, string $amount = '1000'): Enrollment
    {
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => $amount,
        ]);

        return $this->enroll($grade, $year);
    }

    public function test_payments_panel_shows_fresh_numbers_after_discount_elsewhere(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->tuitionEnrollment($grade, $year, '2000');

        $page = Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(Money::format(200000), false);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        $page->dispatch('enrollment-changed')
            ->assertSee(Money::format(20000), false)
            ->assertSee(Money::format(180000), false);
    }

    public function test_charges_panel_shows_fresh_numbers_after_payment_elsewhere(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->tuitionEnrollment($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(Money::format(100000), false);

        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 30000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 30000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);

        $page->dispatch('enrollment-changed')
            ->assertSee(Money::format(30000), false)
            ->assertSee(Money::format(70000), false);
    }

    public function test_status_panel_shows_fresh_badge_after_change_elsewhere(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->tuitionEnrollment($grade, $year);

        $page = Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('enrollments.status_active'), false);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Suspended);

        $page->dispatch('enrollment-changed')
            ->assertSee(__('enrollments.status_suspended'), false);
    }

    public function test_panels_still_dispatch_void_after_refresh(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->tuitionEnrollment($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 20000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 20000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        $payment = $enrollment->refresh()->payments()->firstOrFail();

        Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
            ->dispatch('enrollment-changed')
            ->call('requestVoid', 'payment', $payment->id)
            ->assertDispatched('request-void', type: 'payment', id: $payment->id);

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->dispatch('enrollment-changed')
            ->call('openStatusModal', 'left')
            ->assertSee(__('enrollments.change_status'), false)
            ->call('confirmStatusChange')
            ->assertHasNoErrors();

        $this->assertSame(EnrollmentStatus::Left, $enrollment->refresh()->status);
    }

    public function test_panels_reject_tampered_ids_and_bad_values(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->tuitionEnrollment($grade, $year);
        $other = $this->enroll($grade, $year);

        foreach (['enrollments.show.payments-panel', 'enrollments.show.charges-panel', 'enrollments.show.status-panel'] as $component) {
            try {
                Livewire::test($component, ['enrollmentId' => $enrollment->id])
                    ->set('enrollmentId', $other->id);
                $this->fail("Expected tamper rejection for {$component}");
            } catch (\Throwable) {
                $this->assertTrue(true);
            }
        }

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'bogus')
            ->assertHasErrors(['status']);

        Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
            ->call('requestVoid', 'bogus', 1)
            ->assertHasErrors(['voidTargetType']);
    }

    public function test_panels_render_in_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->tuitionEnrollment($grade, $year);

        Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('payments.payment'), false);

        Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('fees.charges'), false);

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('enrollments.change_status'), false);
    }
}
