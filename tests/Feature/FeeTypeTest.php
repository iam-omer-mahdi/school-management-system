<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateFeeType;
use App\Actions\DeleteFeeType;
use App\Models\FeeType;
use App\Models\GradeFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeeTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_mandatory_and_optional_types(): void
    {
        $bus = app(CreateFeeType::class)->handle(['name' => 'Bus', 'is_mandatory' => false]);

        $this->assertFalse($bus->is_mandatory);
        $this->assertFalse($bus->isTuition());

        $exam = app(CreateFeeType::class)->handle(['name' => 'Exam', 'is_mandatory' => true]);

        $this->assertTrue($exam->is_mandatory);
    }

    public function test_normalizes_whitespace(): void
    {
        $type = app(CreateFeeType::class)->handle(['name' => '  Bus   Fees  ', 'is_mandatory' => false]);

        $this->assertSame('Bus Fees', $type->name);
    }

    public function test_rejects_duplicate_names_case_insensitively(): void
    {
        app(CreateFeeType::class)->handle(['name' => 'Bus', 'is_mandatory' => false]);

        try {
            app(CreateFeeType::class)->handle(['name' => 'bus', 'is_mandatory' => false]);
            $this->fail('Expected ValidationException for duplicate fee type name');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedNames(): array
    {
        return [
            'stored' => ['Tuition'],
            'stored lowercase' => ['tuition'],
            'english label' => ['Tuition'],
            'arabic label' => ['الرسوم الدراسية'],
            'arabic without article' => ['رسوم دراسية'],
        ];
    }

    /**
     * Reserved names are rejected in both interface languages.
     */
    #[DataProvider('reservedNames')]
    public function test_rejects_reserved_names_in_both_languages(string $name): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->withSession(['locale' => $locale]);
            app()->setLocale($locale);

            try {
                app(CreateFeeType::class)->handle(['name' => $name, 'is_mandatory' => false]);
                $this->fail("Expected ValidationException for reserved name [{$name}] in [{$locale}]");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('name', $e->errors());
            }
        }
    }

    public function test_cannot_delete_tuition(): void
    {
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        try {
            app(DeleteFeeType::class)->handle($tuition->id);
            $this->fail('Expected ValidationException when deleting tuition');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fee_type', $e->errors());
        }

        $this->assertTrue(FeeType::query()->where('system_key', 'tuition')->exists());
    }

    public function test_cannot_delete_used_type_but_can_delete_unused(): void
    {
        $bus = app(CreateFeeType::class)->handle(['name' => 'Bus', 'is_mandatory' => true]);
        $books = app(CreateFeeType::class)->handle(['name' => 'Books', 'is_mandatory' => false]);

        GradeFee::factory()->create(['fee_type_id' => $bus->id]);

        try {
            app(DeleteFeeType::class)->handle($bus->id);
            $this->fail('Expected ValidationException when deleting a used fee type');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fee_type', $e->errors());
        }

        app(DeleteFeeType::class)->handle($books->id);

        $this->assertFalse(FeeType::query()->whereKey($books->id)->exists());
    }

    public function test_tuition_is_seeded_by_migration(): void
    {
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        $this->assertSame('Tuition', $tuition->name);
        $this->assertTrue($tuition->is_mandatory);
        $this->assertTrue($tuition->isTuition());
    }

    public function test_factories_never_create_tuition(): void
    {
        $types = FeeType::factory()->count(5)->create();

        $this->assertSame(0, $types->filter->isTuition()->count());
    }
}
