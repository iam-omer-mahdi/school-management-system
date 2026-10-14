<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Models\AcademicYear;
use App\Models\Discount;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentCardTest extends TestCase
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

    public function test_card_applies_and_replaces_discount_with_live_preview(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $sibling = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);

        // Live preview shows the resolved discount and the new tuition net.
        Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->set('discountChoice', (string) $sibling->id)
            ->assertSee(Money::format(10000))
            ->assertSee(Money::format(90000))
            ->call('applyDiscount')
            ->assertHasNoErrors();

        $this->assertSame(
            10000,
            EnrollmentDiscount::where('enrollment_id', $enrollment->id)->firstOrFail()->amount_minor
        );

        $staff = app(CreateDiscount::class)->handle(['name' => 'Staff', 'percent' => '20']);

        Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->set('discountChoice', (string) $staff->id)
            ->call('applyDiscount')
            ->assertHasNoErrors();

        $this->assertSame(1, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());
        $this->assertSame('Staff', EnrollmentDiscount::where('enrollment_id', $enrollment->id)->firstOrFail()->name);

        Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->call('removeDiscount')
            ->assertHasNoErrors();

        $this->assertSame(0, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());
    }

    public function test_card_disables_discount_without_tuition_charge(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.errors.fees_not_set'));
    }

    public function test_card_links_discounts_page_when_catalog_is_empty(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $this->assertSame(0, Discount::count());

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(route('discounts.index'));
    }

    public function test_card_shows_totals_and_manages_optional_fees(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        $booksFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ])->gradeFee;

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        // Tuition 1000 - 10% + books 150 = 1050 total due.
        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('fees.total_due'))
            ->assertSee(Money::format(90000));

        Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->set('optionalChoice', (string) $booksFee->id)
            ->call('addOptional')
            ->assertHasNoErrors();

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(Money::format(105000));

        $this->assertSame(2, $enrollment->refresh()->fees()->count());

        Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
            ->call('removeOptional', $booksFee->id)
            ->assertHasNoErrors();

        $this->assertSame(1, $enrollment->refresh()->fees()->count());
    }
}
