<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\EnrollStudent;
use App\Actions\UpdateStudentStatus;
use App\Enums\StudentStatus;
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

    public function test_inactive_students_cannot_enroll_until_reactivated(): void
    {
        $student = Student::factory()->create(['status' => StudentStatus::Suspended]);
        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();

        $this->expectException(ValidationException::class);

        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    public function test_reactivation_makes_student_available_again(): void
    {
        $student = Student::factory()->create(['status' => StudentStatus::Suspended]);
        $year = AcademicYear::factory()->create(['name' => '26/27']);
        $grade = Grade::firstOrFail();

        app(UpdateStudentStatus::class)->handle($student, StudentStatus::Active);

        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $this->assertEquals($student->id, $enrollment->student_id);
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

        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateStudentStatus::class)->handle($student->refresh(), StudentStatus::Left, 'moved');

        $this->assertEquals(1, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_student_list_bounded_query_count(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Student::factory()->count(20)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('students.index'))->assertOk();

        $count = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(15, $count, 'Student list should stay within bounded queries, got '.$count);
    }
}
