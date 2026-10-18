<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\DeleteDiscount;
use App\Actions\EnrollStudent;
use App\Models\AcademicYear;
use App\Models\Discount;
use App\Models\EnrollmentDiscount;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiscountCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function create(string $name, string $percent): Discount
    {
        return app(CreateDiscount::class)->handle(['name' => $name, 'percent' => $percent]);
    }

    public function test_creates_discount_with_normalized_name_and_basis_points(): void
    {
        $discount = $this->create('  Sibling   Discount  ', '12.5');

        $this->assertSame('Sibling Discount', $discount->name);
        $this->assertSame(1250, $discount->percent_bp);
        $this->assertDatabaseHas('discounts', ['name' => 'Sibling Discount', 'percent_bp' => 1250]);
    }

    public function test_rejects_duplicate_names_case_insensitively(): void
    {
        $this->create('Sibling', '10');

        try {
            $this->create('sibling', '15');
            $this->fail('Expected ValidationException for duplicate discount name');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertSame(1, Discount::count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function arabicVariants(): array
    {
        return [
            'alef hamza above' => ['أبناء', 'ابناء'],
            'ta marbuta' => ['منحة', 'منحه'],
            'tashkeel' => ['مِنحة', 'منحة'],
            'tatweel' => ['مـنحة', 'منحة'],
            'zero-width space' => ["منحة\u{200B}", 'منحة'],
            'nbsp padding' => ["\u{00A0}منحة\u{00A0}", 'منحة'],
        ];
    }

    #[DataProvider('arabicVariants')]
    public function test_rejects_duplicate_names_across_arabic_variants(string $first, string $second): void
    {
        $this->create($first, '10');

        try {
            $this->create($second, '15');
            $this->fail("Expected ValidationException for variant [{$second}] of [{$first}]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertSame(1, Discount::count());
    }

    public function test_rejects_out_of_range_percentages(): void
    {
        foreach (['0', '0.00', '100.01', 'abc', ''] as $percent) {
            try {
                $this->create('Discount '.$percent, $percent);
                $this->fail("Expected ValidationException for percent [{$percent}]");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('percent', $e->errors());
            }
        }

        $this->assertSame(0, Discount::count());
    }

    public function test_accepts_percentage_bounds(): void
    {
        $this->assertSame(1, $this->create('Min', '0.01')->percent_bp);
        $this->assertSame(10000, $this->create('Max', '100')->percent_bp);
    }

    public function test_deletes_unused_discount_but_blocks_used_discount(): void
    {
        $free = $this->create('Unused', '10');

        app(DeleteDiscount::class)->handle($free->id);

        $this->assertFalse(Discount::query()->whereKey($free->id)->exists());

        $used = $this->create('Used', '10');

        EnrollmentDiscount::factory()->create(['discount_id' => $used->id]);

        try {
            app(DeleteDiscount::class)->handle($used->id);
            $this->fail('Expected ValidationException when deleting a used discount');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors());
        }

        $this->assertTrue(Discount::query()->whereKey($used->id)->exists());
    }

    public function test_apply_replaces_discount_and_keeps_snapshot(): void
    {
        $grade = Grade::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $first = $this->create('Sibling', '10');
        $second = $this->create('Staff', '20');

        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $first->id);
        $applied = app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $second->id);

        $this->assertSame(1, EnrollmentDiscount::where('enrollment_id', $enrollment->id)->count());
        $this->assertSame($second->id, $applied->discount_id);
        $this->assertSame('Staff', $applied->name);
        $this->assertSame(2000, $applied->percent_bp);
        $this->assertSame(20000, $applied->amount_minor);
    }
}
