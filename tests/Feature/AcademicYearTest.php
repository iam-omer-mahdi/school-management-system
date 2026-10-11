<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_year_crud_and_old_years_stay_open(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::academic-years.index')
            ->set('form.name', '26/27')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::academic-years.index')
            ->set('form.name', '27/28')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', ['name' => '26/27']);
        $this->assertDatabaseHas('academic_years', ['name' => '27/28']);

        $year = AcademicYear::where('name', '26/27')->firstOrFail();

        Livewire::test('pages::academic-years.index')
            ->call('edit', $year->id)
            ->set('form.name', '26/27')
            ->set('form.starts_on', '2026-09-01')
            ->call('save')
            ->assertHasNoErrors();

        // Both years still exist (creating new year does not close older years).
        $this->assertEquals(2, AcademicYear::count());
    }

    public function test_academic_year_name_must_be_unique(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        AcademicYear::factory()->create(['name' => '26/27']);

        Livewire::test('pages::academic-years.index')
            ->set('form.name', '26/27')
            ->call('save')
            ->assertHasErrors(['form.name']);
    }

    public function test_cannot_delete_year_with_enrollments(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->seed(SchoolSeeder::class);
        $student = Student::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();
        Enrollment::factory()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
        ]);

        Livewire::test('pages::academic-years.index')
            ->call('confirmDelete', $year->id)
            ->call('delete')
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('academic_years', ['name' => '26/27']);
    }
}
