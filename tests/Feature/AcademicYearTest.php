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

    public function test_academic_year_name_is_generated_from_months(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', '2026-05')
            ->set('form.ends_on', '2027-03')
            ->assertSet('form.name', '2026/2027')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', ['name' => '2026/2027']);

        $year = AcademicYear::where('name', '2026/2027')->firstOrFail();
        $this->assertSame('2026-05-01', $year->starts_on->format('Y-m-d'));
        $this->assertSame('2027-03-01', $year->ends_on->format('Y-m-d'));
    }

    public function test_second_year_starting_in_same_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        AcademicYear::factory()->create(['name' => '2026/2027', 'starts_on' => '2026-01-01', 'ends_on' => '2027-03-01']);

        $component = Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', '2026-09')
            ->set('form.ends_on', '2027-06')
            ->call('save')
            ->assertHasErrors(['form.starts_on']);

        $this->assertStringContainsString('2026', (string) $component->errors()->first('form.starts_on'));
    }

    public function test_end_month_before_start_month_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', '2026-09')
            ->set('form.ends_on', '2026-03')
            ->call('save')
            ->assertHasErrors(['form.ends_on']);
    }

    public function test_years_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $year = AcademicYear::factory()->create(['name' => '2026/2027']);

        $component = Livewire::test('pages::academic-years.index');

        $this->assertFalse(method_exists($component->instance(), 'edit'));
        $this->assertDatabaseHas('academic_years', ['id' => $year->id, 'name' => '2026/2027']);
    }

    public function test_cannot_delete_year_with_enrollments(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->seed(SchoolSeeder::class);
        $student = Student::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '2026/2027']);
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

        $this->assertDatabaseHas('academic_years', ['name' => '2026/2027']);
    }
}
