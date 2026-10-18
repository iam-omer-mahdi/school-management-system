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

        $startsOn = now()->subYears(2)->format('Y-m');
        $endsOn = now()->subYear()->format('Y-m');
        $name = now()->subYears(2)->format('Y').'/'.now()->subYear()->format('Y');

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', $startsOn)
            ->set('form.ends_on', $endsOn)
            ->assertSet('form.name', $name)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', ['name' => $name]);

        $year = AcademicYear::where('name', $name)->firstOrFail();
        $this->assertSame($startsOn.'-01', $year->starts_on->format('Y-m-d'));
        $this->assertSame($endsOn.'-01', $year->ends_on->format('Y-m-d'));
    }

    public function test_second_year_starting_in_same_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $startYear = (int) now()->subYear()->format('Y');

        AcademicYear::factory()->create([
            'name' => $startYear.'/'.($startYear + 1),
            'starts_on' => $startYear.'-01-01',
            'ends_on' => ($startYear + 1).'-03-01',
        ]);

        $component = Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', $startYear.'-09')
            ->set('form.ends_on', ($startYear + 1).'-06')
            ->call('save')
            ->assertHasErrors(['form.starts_on']);

        $this->assertStringContainsString((string) $startYear, (string) $component->errors()->first('form.starts_on'));
    }

    public function test_end_month_before_start_month_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $year = (int) now()->subYear()->format('Y');

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', $year.'-09')
            ->set('form.ends_on', $year.'-03')
            ->call('save')
            ->assertHasErrors(['form.ends_on']);
    }

    public function test_year_spanning_more_than_one_year_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', now()->subYears(2)->format('Y-m'))
            ->set('form.ends_on', now()->format('Y-m'))
            ->call('save')
            ->assertHasErrors(['form.ends_on']);

        $this->assertSame(0, AcademicYear::count());
    }

    public function test_same_year_span_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $year = (int) now()->format('Y');

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', $year.'-01')
            ->set('form.ends_on', $year.'-12')
            ->call('save')
            ->assertHasErrors(['form.ends_on']);

        $this->assertSame(0, AcademicYear::count());
    }

    public function test_start_month_older_than_three_years_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', now()->subYears(3)->subMonth()->format('Y-m'))
            ->set('form.ends_on', now()->subYears(2)->format('Y-m'))
            ->call('save')
            ->assertHasErrors(['form.starts_on']);

        $this->assertSame(0, AcademicYear::count());
    }

    public function test_start_month_at_the_three_year_boundary_is_accepted(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::academic-years.index')
            ->set('form.starts_on', now()->subYears(3)->format('Y-m'))
            ->set('form.ends_on', now()->subYears(2)->format('Y-m'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, AcademicYear::count());
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
