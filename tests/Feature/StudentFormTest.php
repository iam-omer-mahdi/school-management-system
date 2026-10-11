<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
