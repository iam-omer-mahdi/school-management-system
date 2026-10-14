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
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
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

    private function locales(): array
    {
        return ['en', 'ar'];
    }

    public function test_parent_renders_header_summary_and_all_panels(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $student = Student::factory()->create(['name' => 'Show Header']);
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        foreach ($this->locales() as $locale) {
            $this->withSession(['locale' => $locale]);
            app()->setLocale($locale);

            Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
                ->assertSee('Show Header')
                ->assertSee($student->student_number)
                ->assertSee(route('students.show', $student))
                ->assertSee($year->name)
                ->assertSee(__('enrollments.status_active'))
                ->assertSee(__('fees.total_due'))
                ->assertSee(Money::format(100000))
                ->assertSee(__('enrollments.change_status'))
                ->assertSee(__('fees.charges'))
                ->assertSee(__('fees.discount'))
                ->assertSee(__('payments.payment'))
                ->assertSee(__('payments.add_payment'), false)
                ->assertSee(__('payments.no_payments'))
                ->assertSee(__('payments.refunds'))
                ->assertSee(__('payments.no_refunds'))
                ->assertSee(route('enrollments.index'));
        }
    }

    public function test_split_payment_updates_every_panel(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => '5000',
        ])->gradeFee;
        $booksType = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $booksType->id, 'amount' => '1500',
        ])->gradeFee;
        $enrollment = $this->enroll($grade, $year);
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('form.total', '6500')
            ->set('form.alloc.'.$tuitionFee->id, '5000')
            ->set('form.alloc.'.$booksFee->id, '1500')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertDispatched('enrollment-changed');

        $this->assertSame(2, Payment::query()->where('enrollment_id', $enrollment->id)->count());

        Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee(Money::format(500000), false)
            ->assertSee(Money::format(150000), false);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.total_due'), false)
            ->assertSee(Money::format(0), false);
    }

    public function test_payment_guards_still_hold_through_modal(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year, '2000');
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        $enrollment = $this->enroll($grade, $year);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);
        $charge = $enrollment->fees()->firstOrFail();

        $page = Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('form.total', '2000')
            ->set('form.alloc.'.$charge->grade_fee_id, '2000')
            ->assertSee(__('payments.errors.amount_exceeds_remaining'));

        $page->call('savePayment')->assertHasErrors(['amount']);
        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());

        $page->set('form.total', '1000')
            ->set('form.alloc.'.$charge->grade_fee_id, '400')
            ->call('savePayment')
            ->assertHasErrors(['amount']);

        $page->set('form.total', '1800')
            ->set('form.alloc.'.$charge->grade_fee_id, '1800')
            ->call('savePayment')
            ->assertHasNoErrors();
    }

    public function test_payment_blocked_on_ended_enrollment(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => '1000',
        ])->gradeFee;
        $enrollment = $this->enroll($grade, $year);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->assertSee(__('payments.errors.ended_no_payments'))
            ->call('openPaymentModal')
            ->set('form.total', '100')
            ->set('form.alloc.'.$tuitionFee->id, '100')
            ->call('savePayment')
            ->assertHasErrors();

        $this->assertSame(0, Payment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_void_round_trip_across_void_modal_and_panels(): void
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

        Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'payment', id: $payment->id)
            ->call('confirmVoid')
            ->assertHasErrors(['void_reason']);

        Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'payment', id: $payment->id)
            ->set('reason', 'Entered twice')
            ->call('confirmVoid')
            ->assertHasNoErrors()
            ->assertDispatched('enrollment-changed');

        $this->assertNotNull($payment->refresh()->voided_at);

        Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
            ->assertSee($payment->reference())
            ->assertSee(__('payments.void'))
            ->assertSee('value="'.$payment->id.'" disabled', false);
    }

    public function test_status_warning_discount_optional_and_reactivation(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $booksType = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $booksType->id, 'amount' => '150',
        ])->gradeFee;
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $enrollment->id, 'total_minor' => 30000,
            'rows' => [['grade_fee_id' => $charge->grade_fee_id, 'amount_minor' => 30000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'left')
            ->assertSee(__('payments.will_no_longer_be_owed', ['amount' => Money::format(70000)]))
            ->call('confirmStatusChange')
            ->assertHasNoErrors();
        $this->assertSame(EnrollmentStatus::Left, $enrollment->refresh()->status);

        $enrollment2 = $this->enroll($grade, $year);
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        $charges = Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment2->id])
            ->set('discountChoice', (string) $discount->id)
            ->call('applyDiscount')
            ->assertHasNoErrors();
        $this->assertSame(1, $enrollment2->refresh()->discount()->count());
        $charges->call('removeDiscount')->assertHasNoErrors();
        $this->assertSame(0, $enrollment2->refresh()->discount()->count());

        $charges->set('optionalChoice', (string) $booksFee->id)
            ->call('addOptional')
            ->assertHasNoErrors();
        $this->assertSame(2, $enrollment2->refresh()->fees()->count());
        $charges->call('removeOptional', $booksFee->id)->assertHasNoErrors();
        $this->assertSame(1, $enrollment2->refresh()->fees()->count());
    }

    public function test_reactivation_after_full_refund_owes_full_charge(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $tuitionFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id, 'amount' => '5000',
        ])->gradeFee;
        $enrollment = $this->enroll($grade, $year);

        Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('form.total', '4000')
            ->set('form.alloc.'.$tuitionFee->id, '4000')
            ->call('savePayment')
            ->assertHasNoErrors();

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id])
            ->call('openRefundModal')
            ->set('form.total', '4000')
            ->set('form.alloc.'.$tuitionFee->id, '4000')
            ->set('form.note', 'Full return')
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

    public function test_page_load_queries_are_constant_regardless_of_volume(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);

        // Few: tuition only, one payment, ended, one partial refund.
        $few = $this->enroll($grade, $year);
        $fewCharge = $few->fees()->firstOrFail();
        app(RecordPayment::class)->handle([
            'enrollment_id' => $few->id, 'total_minor' => 40000,
            'rows' => [['grade_fee_id' => $fewCharge->grade_fee_id, 'amount_minor' => 40000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        app(UpdateEnrollmentStatus::class)->handle($few, EnrollmentStatus::Left);
        app(RecordRefund::class)->handle([
            'enrollment_id' => $few->id, 'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $fewCharge->grade_fee_id, 'amount_minor' => 10000]],
            'method' => 'cash', 'transaction_number' => null, 'refunded_on' => date('Y-m-d'), 'note' => 'Partial',
        ]);

        // Many: tuition + three optionals, six payment rows, ended, three refunds.
        $many = $this->enroll($grade, $year);
        $manyCharge = $many->fees()->firstOrFail();
        $optionals = [];
        foreach (['Books', 'Lab', 'Bus'] as $name) {
            $type = FeeType::factory()->create(['name' => $name, 'is_mandatory' => false]);
            $optionals[] = app(CreateGradeFee::class)->handle([
                'grade_id' => $grade->id, 'academic_year_id' => $year->id,
                'fee_type_id' => $type->id, 'amount' => '150',
            ])->gradeFee;
        }
        foreach ($optionals as $optional) {
            app(AddOptionalFeeToEnrollment::class)->handle($many->id, $optional->id);
        }
        $rows = [['grade_fee_id' => $manyCharge->grade_fee_id, 'amount_minor' => 40000]];
        foreach ($optionals as $optional) {
            $rows[] = ['grade_fee_id' => $optional->id, 'amount_minor' => 15000];
        }
        app(RecordPayment::class)->handle([
            'enrollment_id' => $many->id, 'total_minor' => 85000,
            'rows' => $rows, 'method' => 'cash', 'transaction_number' => null,
            'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        app(RecordPayment::class)->handle([
            'enrollment_id' => $many->id, 'total_minor' => 10000,
            'rows' => [['grade_fee_id' => $manyCharge->grade_fee_id, 'amount_minor' => 10000]],
            'method' => 'cash', 'transaction_number' => null, 'paid_on' => date('Y-m-d'), 'note' => null,
        ]);
        app(UpdateEnrollmentStatus::class)->handle($many, EnrollmentStatus::Left);
        foreach ([10000, 5000, 5000] as $refundMinor) {
            app(RecordRefund::class)->handle([
                'enrollment_id' => $many->id, 'total_minor' => $refundMinor,
                'rows' => [['grade_fee_id' => $manyCharge->grade_fee_id, 'amount_minor' => $refundMinor]],
                'method' => 'cash', 'transaction_number' => null, 'refunded_on' => date('Y-m-d'), 'note' => 'Return',
            ]);
        }

        // One listener only: extra listeners in the same test would
        // cross-count every render (all closures stay registered).
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        Livewire::test('pages::enrollments.show', ['enrollment' => $few->id])
            ->assertSee($few->student->name);
        $fewCount = $count;

        $count = 0;
        Livewire::test('pages::enrollments.show', ['enrollment' => $many->id])
            ->assertSee($many->student->name);
        $manyCount = $count;

        // Every relation loads in one query no matter how many rows it
        // holds: 4 fees + 6 payment rows + 3 refunds cost the same as
        // 1 fee + 1 payment + 1 refund. No N+1. A delta of 2 tolerates
        // ±1 run-to-run wobble observed on loaded shapes; a per-row
        // N+1 would diverge by 9+ with 3 extra fees.
        $this->assertEqualsWithDelta($fewCount, $manyCount, 2, "few={$fewCount} many={$manyCount}");
    }

    public function test_save_queries_are_bounded(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);
        $charge = $enrollment->fees()->firstOrFail();

        $payment = Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal')
            ->set('form.total', '100')
            ->set('form.alloc.'.$charge->grade_fee_id, '100');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $payment->call('savePayment')->assertHasNoErrors()->assertDispatched('enrollment-changed');
        $this->assertSame(15, $count, 'savePayment queries: '.$count);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        $refund = Livewire::test('enrollments.show.refund-modal', ['enrollmentId' => $enrollment->id])
            ->call('openRefundModal')
            ->set('form.total', '100')
            ->set('form.alloc.'.$charge->grade_fee_id, '100')
            ->set('form.note', 'Probe');

        $count = 0;
        $refund->call('saveRefund')->assertHasNoErrors()->assertDispatched('enrollment-changed');
        // Includes the refund-history chain (refunds now render inside
        // refund-modal after the duplicate-section merge).
        $this->assertSame(15, $count, 'saveRefund queries: '.$count);

        $saved = $enrollment->refresh()->payments()->firstOrFail();
        $void = Livewire::test('enrollments.show.void-modal', ['enrollmentId' => $enrollment->id])
            ->dispatch('request-void', type: 'payment', id: $saved->id)
            ->set('reason', 'Probe');

        $count = 0;
        $void->call('confirmVoid')->assertHasErrors(['payment_id']);
        // Blocked: the refund depends on this payment, so nothing is voided.
        $this->assertSame(3, $count, 'confirmVoid queries: '.$count);
        $this->assertNull($saved->refresh()->voided_at);
    }

    public function test_status_save_queries_are_bounded(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $status = Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'suspended');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $status->call('confirmStatusChange')->assertHasNoErrors()->assertDispatched('enrollment-changed');
        // Pristine shape (no payments yet): save path + one re-render
        // graph. With payment/refund history present the re-render adds
        // the history-chain follow-ups (+2); both are single graphs.
        $this->assertSame(12, $count, 'confirmStatusChange queries: '.$count);
    }

    public function test_sibling_refresh_runs_its_graph_exactly_once(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        // One listener per measurement; reset the counter between renders.
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        foreach ([
            'enrollments.show.payments-panel',
            'enrollments.show.charges-panel',
            'enrollments.show.status-panel',
        ] as $component) {
            $page = Livewire::test($component, ['enrollmentId' => $enrollment->id]);
            $initial = $count;

            $count = 0;
            $page->dispatch('enrollment-changed');
            $refresh = $count;

            // The handler only unsets memoized computeds; the re-render
            // runs the component's graph exactly once, never twice.
            $this->assertSame($initial, $refresh, "{$component}: initial={$initial} refresh={$refresh}");

            $count = 0;
        }
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

        // EXACT budget: page load runs one lean Eloquent graph per
        // component (parent ~11 + children ~38 + session/auth ~2).
        // Any added query fails the suite. Accepted as constant: the
        // count does not grow with fees, payments or refunds (see the
        // constancy test below).
        $this->assertSame(51, $count, 'Page load queries: '.$count);
    }

    public function test_pay_total_keystroke_query_baseline(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $this->scheduleTuition($grade, $year);
        $enrollment = $this->enroll($grade, $year);

        $page = Livewire::test('enrollments.show.add-payment-modal', ['enrollmentId' => $enrollment->id])
            ->call('openPaymentModal');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $page->set('form.total', '100');

        // Before the refactor one keystroke ran the whole single-file
        // page graph (14 queries). After, only the add-payment modal's
        // own graph runs: strictly lower.
        $this->assertSame(7, $count, 'PayTotal keystroke queries: '.$count);
    }
}
