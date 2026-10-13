<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateDiscount;
use App\Livewire\Forms\StudentForm;
use App\Models\Discount;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FormInputsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_rejects_non_percentage_with_clear_message(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->withSession(['locale' => $locale]);
            app()->setLocale($locale);

            foreach (['abc', '0', '0.00', '100.01', '', '101'] as $percent) {
                try {
                    app(CreateDiscount::class)->handle(['name' => 'Discount '.$percent.$locale, 'percent' => $percent]);
                    $this->fail("Expected ValidationException for percent [{$percent}] in [{$locale}]");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('percent', $e->errors());
                    $this->assertSame(__('fees.errors.percent_invalid'), $e->errors()['percent'][0]);
                }
            }
        }

        $this->assertSame(0, Discount::count());
    }

    public function test_discount_accepts_decimals_within_range(): void
    {
        $this->assertSame(100, app(CreateDiscount::class)->handle(['name' => 'Min', 'percent' => '1'])->percent_bp);
        $this->assertSame(1250, app(CreateDiscount::class)->handle(['name' => 'Half', 'percent' => '12.5'])->percent_bp);
        $this->assertSame(10000, app(CreateDiscount::class)->handle(['name' => 'Max', 'percent' => '100'])->percent_bp);
    }

    public function test_discount_form_uses_number_input_with_range(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::discounts.index')
            ->call('create')
            ->assertSee('type="number"', false)
            ->assertSee('min="1"', false)
            ->assertSee('max="100"', false)
            ->assertSee(__('fees.percentage_hint'));
    }

    public function test_student_blood_type_accepts_only_known_types(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (StudentForm::BLOOD_TYPES as $type) {
            Livewire::test('pages::students.create')
                ->set('form.name', 'Sara')
                ->set('form.phone', '0700000000')
                ->set('form.blood_type', $type)
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect();
        }

        $invalid = Livewire::test('pages::students.create')
            ->set('form.name', 'Sara')
            ->set('form.phone', '0700000000')
            ->set('form.blood_type', 'XYZ')
            ->call('save');

        $invalid->assertHasErrors(['form.blood_type']);
    }

    public function test_student_forms_offer_blood_type_select(): void
    {
        $this->actingAs(User::factory()->create());

        $student = Student::factory()->create();

        Livewire::test('pages::students.create')
            ->assertSee('<select', false)
            ->assertSee('O-', false);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->call('$set', 'editing', true)
            ->assertSee('<select', false)
            ->assertSee('O-', false);
    }

    public function test_fee_schedule_dropdown_translates_tuition_in_arabic(): void
    {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');
        $this->actingAs(User::factory()->create());

        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        Livewire::test('pages::fee-schedule.create')
            ->assertSee(__('fees.tuition'))
            ->assertDontSee('>'.$tuition->name.'<', false);
    }
}
