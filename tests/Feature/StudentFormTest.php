<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Forms\StudentForm;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_fill_from_formats_date_of_birth(): void
    {
        $this->actingAs(User::factory()->create());

        $student = Student::factory()->create(['date_of_birth' => '2010-05-06']);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->assertSet('form.date_of_birth', '2010-05-06');
    }

    public function test_fill_from_keeps_missing_date_of_birth_empty(): void
    {
        $this->actingAs(User::factory()->create());

        $student = Student::factory()->create(['date_of_birth' => null]);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->assertSet('form.date_of_birth', null);
    }

    public function test_future_date_of_birth_is_rejected_on_create(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::students.create')
            ->set('form.name', 'Sara Ahmed')
            ->set('form.phone', '0911111111')
            ->set('form.date_of_birth', '2099-01-01')
            ->call('save')
            ->assertHasErrors(['form.date_of_birth']);

        $this->assertSame(0, Student::count());
    }

    public function test_future_date_of_birth_is_rejected_on_edit(): void
    {
        $this->actingAs(User::factory()->create());
        $student = Student::factory()->create(['date_of_birth' => '2010-05-06']);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->set('form.date_of_birth', '2099-01-01')
            ->call('update')
            ->assertHasErrors(['form.date_of_birth']);

        $this->assertSame('2010-05-06', $student->refresh()->date_of_birth?->format('Y-m-d'));
    }

    public function test_date_of_birth_outside_school_age_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $tooYoung = now()->subYears(StudentForm::MIN_AGE_YEARS)->addDay()->toDateString();
        $tooOld = now()->subYears(StudentForm::MAX_AGE_YEARS)->subDay()->toDateString();

        Livewire::test('pages::students.create')
            ->set('form.name', 'Too Young')
            ->set('form.phone', '0911111111')
            ->set('form.date_of_birth', $tooYoung)
            ->call('save')
            ->assertHasErrors(['form.date_of_birth']);

        Livewire::test('pages::students.create')
            ->set('form.name', 'Too Old')
            ->set('form.phone', '0911111112')
            ->set('form.date_of_birth', $tooOld)
            ->call('save')
            ->assertHasErrors(['form.date_of_birth']);

        $this->assertSame(0, Student::count());
    }

    public function test_date_of_birth_age_boundaries_are_inclusive(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::students.create')
            ->set('form.name', 'Youngest')
            ->set('form.phone', '0911111113')
            ->set('form.date_of_birth', now()->subYears(StudentForm::MIN_AGE_YEARS)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::students.create')
            ->set('form.name', 'Oldest')
            ->set('form.phone', '0911111114')
            ->set('form.date_of_birth', now()->subYears(StudentForm::MAX_AGE_YEARS)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Student::count());
    }
}
