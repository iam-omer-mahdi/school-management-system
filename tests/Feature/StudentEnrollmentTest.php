<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ArchiveStudent;
use App\Actions\EnrollStudent;
use App\Actions\RestoreStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SchoolSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_student_creation_without_enroll(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::students.create')
            ->set('form.name', 'Ahmed Ali')
            ->set('form.phone', '0912345678')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('students.show', ['student' => Student::firstOrFail()->id]));

        $student = Student::firstOrFail();
        $this->assertNotEmpty($student->student_number);
        $this->assertEquals(0, Enrollment::count());
    }

    public function test_student_creation_with_enroll_now_in_one_save(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $grade = Grade::firstOrFail();
        $year = AcademicYear::factory()->create(['name' => '26/27']);

        Livewire::test('pages::students.create')
            ->set('form.name', 'Sara Ahmed')
            ->set('form.phone', '0911111111')
            ->set('enrollNow', true)
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(1, Student::count());
        $this->assertEquals(1, Enrollment::count());
        $this->assertSame(
            EnrollmentStatus::Active,
            Enrollment::firstOrFail()->status
        );
    }

    public function test_changing_school_clears_grade_on_enroll_now_form(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $other = Grade::query()->where('school_id', '!=', $grade->school_id)->firstOrFail();

        Livewire::test('pages::students.create')
            ->set('enrollNow', true)
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('school_id', $other->school_id)
            ->assertSet('grade_id', null);
    }

    public function test_enroll_now_rejects_grade_from_another_school(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $other = Grade::query()->where('school_id', '!=', $grade->school_id)->firstOrFail();
        $year = AcademicYear::factory()->create(['name' => '26/27']);

        Livewire::test('pages::students.create')
            ->set('form.name', 'Sara Ahmed')
            ->set('form.phone', '0911111111')
            ->set('enrollNow', true)
            ->set('school_id', $other->school_id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('save')
            ->assertHasErrors('grade_id');

        $this->assertSame(0, Student::count());
        $this->assertSame(0, Enrollment::count());
    }

    public function test_student_number_unique_auto_generated_not_editable(): void
    {
        $a = Student::factory()->create();
        $b = Student::factory()->create();

        $this->assertNotEquals($a->student_number, $b->student_number);

        // Show page must not render student_number as an editable input.
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('students.show', $a));
        $response->assertOk();
        $response->assertDontSee('name="form.student_number"', false);
    }

    public function test_archived_students_cannot_enroll_until_restored(): void
    {
        $student = Student::factory()->create();
        app(ArchiveStudent::class)->handle($student);

        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();

        try {
            app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
            $this->fail('Expected ValidationException for an archived student');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_id', $e->errors());
        }

        app(RestoreStudent::class)->handle($student->refresh());

        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $this->assertEquals($student->id, $enrollment->student_id);
    }

    public function test_student_with_ended_enrollment_can_enroll_in_new_year(): void
    {
        $student = Student::factory()->create();
        $oldYear = AcademicYear::factory()->create(['name' => '25/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-06-01']);
        $newYear = AcademicYear::factory()->create(['name' => '26/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-01']);
        $grade = Grade::firstOrFail();

        $old = app(EnrollStudent::class)->handle($student->id, $oldYear->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($old, EnrollmentStatus::Expelled);

        $enrollment = app(EnrollStudent::class)->handle($student->id, $newYear->id, $grade->id);

        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
    }

    public function test_no_double_enrollment_in_same_year(): void
    {
        $student = Student::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();

        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $this->expectException(ValidationException::class);

        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    public function test_search_works_across_name_number_phone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Student::factory()->create(['name' => 'UniqueName Karim', 'phone' => '0900000001', 'student_number' => 'STU-000101']);
        Student::factory()->create(['name' => 'Someone Else', 'phone' => '0900000002', 'student_number' => 'STU-000102']);

        Livewire::test('pages::students.index')
            ->set('search', 'UniqueName')
            ->assertSee('UniqueName')
            ->assertDontSee('Someone Else');

        Livewire::test('pages::students.index')
            ->set('search', 'STU-000102')
            ->assertSee('STU-000102');

        Livewire::test('pages::students.index')
            ->set('search', '0900000001')
            ->assertSee('0900000001');
    }

    public function test_past_enrollment_data_kept_after_status_change(): void
    {
        $student = Student::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();

        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left, 'moved');

        $this->assertEquals(1, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_student_list_filter_and_latest_enrollment_column(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $plain = Student::factory()->create(['name' => 'A Plain']);
        $archived = Student::factory()->create(['name' => 'B Archived']);
        app(ArchiveStudent::class)->handle($archived);

        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();
        app(EnrollStudent::class)->handle($plain->id, $year->id, $grade->id);

        // Default shows active students with the latest enrollment year.
        Livewire::test('pages::students.index')
            ->assertSee('A Plain')
            ->assertDontSee('B Archived')
            ->assertSee('26/27');

        // Archived filter shows only archived students.
        Livewire::test('pages::students.index')
            ->set('archive', 'archived')
            ->assertSee('B Archived')
            ->assertDontSee('A Plain');

        // All shows everyone.
        Livewire::test('pages::students.index')
            ->set('archive', 'all')
            ->assertSee('A Plain')
            ->assertSee('B Archived');
    }

    public function test_student_list_bounded_query_count(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();

        foreach (Student::factory()->count(20)->create() as $student) {
            app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('students.index'))->assertOk();

        $count = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(15, $count, 'Student list should stay within bounded queries, got '.$count);
    }

    /**
     * A duplicate is caught by the table's unique key, not by a check-then-insert,
     * so two simultaneous enrollments cannot slip past the guard and crash.
     */
    public function test_enrolling_a_student_twice_reports_already_enrolled(): void
    {
        $grade = Grade::firstOrFail();
        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $student = Student::factory()->create();

        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        try {
            app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
            $this->fail('Expected ValidationException for a duplicate enrollment');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('students.errors.already_enrolled'),
                $exception->errors()['academic_year_id'][0]
            );
        }

        $this->assertSame(1, Enrollment::query()->where('student_id', $student->id)->count());
    }
}
