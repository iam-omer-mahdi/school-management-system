<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\EnrollStudent;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentEnrollmentsAccordionTest extends TestCase
{
    use RefreshDatabase;

    private function enrollInYear(Student $student, Grade $grade, int $startYear): Enrollment
    {
        $year = AcademicYear::factory()->create([
            'name' => sprintf('%d/%d', $startYear, $startYear + 1),
            'starts_on' => sprintf('%d-09-01', $startYear),
            'ends_on' => sprintf('%d-06-01', $startYear + 1),
        ]);

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    public function test_latest_enrollment_expanded_by_default(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $student = Student::factory()->create();

        $older = $this->enrollInYear($student, $grade, 2030);
        $latest = $this->enrollInYear($student, $grade, 2031);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->assertSee('id="enrollment-body-'.$latest->id.'"', false)
            ->assertSee(route('enrollments.show', $latest), false)
            ->assertSee(__('enrollments.view_details'))
            ->assertDontSee('id="enrollment-body-'.$older->id.'"', false);
    }

    public function test_toggle_expands_one_and_collapses_the_other(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $student = Student::factory()->create();

        $older = $this->enrollInYear($student, $grade, 2030);
        $latest = $this->enrollInYear($student, $grade, 2031);

        $page = Livewire::test('pages::students.show', ['student' => $student->id])
            ->call('toggleExpanded', $older->id);

        $page->assertSee('id="enrollment-body-'.$older->id.'"', false)
            ->assertDontSee('id="enrollment-body-'.$latest->id.'"', false);

        $page->call('toggleExpanded', $older->id)
            ->assertDontSee('id="enrollment-body-'.$older->id.'"', false)
            ->assertDontSee('id="enrollment-body-'.$latest->id.'"', false);
    }

    public function test_enrollments_paginate_five_per_page(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $student = Student::factory()->create();

        foreach (range(2030, 2035) as $startYear) {
            $this->enrollInYear($student, $grade, $startYear);
        }

        $page = Livewire::test('pages::students.show', ['student' => $student->id]);

        foreach (range(2031, 2035) as $startYear) {
            $page->assertSee(sprintf('%d/%d', $startYear, $startYear + 1));
        }

        $page->assertDontSee('2030/2031');

        $page->call('gotoPage', 2)
            ->assertSee('2030/2031')
            ->assertDontSee('2035/2036');
    }

    public function test_enrolling_opens_new_enrollment_on_first_page(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $student = Student::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->set('showEnroll', true)
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('enroll')
            ->assertHasNoErrors()
            ->assertSet('expandedEnrollment', Enrollment::query()->where('student_id', $student->id)->orderByDesc('id')->firstOrFail()->id);
    }
}
