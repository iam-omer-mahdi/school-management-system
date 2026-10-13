<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\ArchiveStudent;
use App\Actions\CreateDiscount;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function year(int $startYear): AcademicYear
    {
        return AcademicYear::factory()->create([
            'name' => sprintf('%d/%d', $startYear, $startYear + 1),
            'starts_on' => sprintf('%d-09-01', $startYear),
            'ends_on' => sprintf('%d-06-01', $startYear + 1),
        ]);
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    public function test_enrollments_page_requires_auth(): void
    {
        $this->get(route('enrollments.index'))->assertRedirect(route('login'));
        $this->get(route('enrollments.show', 1))->assertRedirect(route('login'));
    }

    public function test_shows_prompt_and_no_enrollments_until_applied(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $student = Student::factory()->create(['name' => 'Hidden Until Applied']);
        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::enrollments.index')
            ->assertSet('applied', false)
            ->assertSee(__('enrollments.select_filters'))
            ->assertDontSee('Hidden Until Applied');
    }

    public function test_apply_requires_school_grade_and_year(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $page = Livewire::test('pages::enrollments.index')
            ->assertSee('disabled="disabled"', false)
            ->call('applyFilters')
            ->assertSet('applied', false)
            ->assertSee(__('enrollments.select_filters'));

        $page->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->assertDontSee('disabled="disabled"', false)
            ->call('applyFilters')
            ->assertSet('applied', true);
    }

    public function test_filters_by_year_school_grade_and_status(): void
    {
        $this->actingAs(User::factory()->create());

        $schoolA = School::factory()->create(['code' => 'test-a', 'name' => 'A']);
        $gradeA = Grade::factory()->create(['school_id' => $schoolA->id, 'number' => 1]);
        $schoolB = School::factory()->create(['code' => 'test-b', 'name' => 'B']);
        $gradeB = Grade::factory()->create(['school_id' => $schoolB->id, 'number' => 2]);
        $year = $this->year(2030);
        $otherYear = $this->year(2031);

        $active = Student::factory()->create(['name' => 'Active Filtered']);
        app(EnrollStudent::class)->handle($active->id, $year->id, $gradeA->id);

        $left = Student::factory()->create(['name' => 'Left Filtered']);
        $leftEnrollment = app(EnrollStudent::class)->handle($left->id, $year->id, $gradeA->id);
        app(UpdateEnrollmentStatus::class)->handle($leftEnrollment, EnrollmentStatus::Left);

        $otherSchool = Student::factory()->create(['name' => 'Other School']);
        app(EnrollStudent::class)->handle($otherSchool->id, $year->id, $gradeB->id);

        $otherYearStudent = Student::factory()->create(['name' => 'Other Year']);
        app(EnrollStudent::class)->handle($otherYearStudent->id, $otherYear->id, $gradeA->id);

        $page = Livewire::test('pages::enrollments.index')
            ->set('school_id', $schoolA->id)
            ->set('grade_id', $gradeA->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSet('applied', true)
            ->set('status', 'left');

        $page->assertSee('Left Filtered')
            ->assertDontSee('Active Filtered')
            ->assertDontSee('Other School');

        // Status refines live without re-applying.
        $page->set('status', '')
            ->assertSee('Left Filtered')
            ->assertSee('Active Filtered');

        // Changing the draft year changes nothing visible until Apply.
        $page->set('academic_year_id', $otherYear->id)
            ->assertSet('applied', true)
            ->assertSee(__('enrollments.total', ['count' => 2]))
            ->assertSee('Left Filtered')
            ->assertSee('Active Filtered')
            ->assertDontSee('Other Year');

        $page->call('applyFilters')
            ->assertSee('Other Year')
            ->assertDontSee('Active Filtered');

        // Changing the draft grade also freezes the old rows until Apply.
        $page->set('school_id', $schoolA->id)
            ->set('grade_id', $gradeA->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee('Active Filtered');

        $page->set('grade_id', $gradeB->id)
            ->assertSee('Active Filtered')
            ->assertDontSee('Other School');

        $page->call('applyFilters')
            ->assertSee('Other School')
            ->assertDontSee('Active Filtered');

        // Changing the school clears the draft grade: Apply disables,
        // old rows persist until a complete trio is applied again.
        $page->set('school_id', $schoolA->id)
            ->assertSet('grade_id', null)
            ->assertSee('disabled="disabled"', false)
            ->assertSee('Other School')
            ->assertDontSee('Active Filtered')
            ->assertDontSee('Left Filtered');
    }

    public function test_draft_change_on_page_two_keeps_page_and_rows(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);
        $otherYear = $this->year(2031);

        foreach (range(1, 16) as $i) {
            $student = Student::factory()->create(['name' => sprintf('Paged %02d', $i)]);
            app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        }

        $page = Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->call('gotoPage', 2)
            ->assertSee('Paged 01')
            ->assertDontSee('Paged 16');

        // Draft change must not reset the page nor swap the rows.
        $page->set('academic_year_id', $otherYear->id)
            ->assertSee('Paged 01')
            ->assertDontSee('Paged 16');

        $page->call('applyFilters')
            ->assertDontSee('Paged 01');
    }

    public function test_search_combines_with_applied_year_not_draft_year(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);
        $otherYear = $this->year(2031);

        $wanted = Student::factory()->create(['name' => 'Applied Year Wanted']);
        app(EnrollStudent::class)->handle($wanted->id, $year->id, $grade->id);

        Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->set('academic_year_id', $otherYear->id)
            ->set('search', 'Wanted')
            ->assertSee('Applied Year Wanted');
    }

    public function test_grade_options_follow_school_and_reset_on_change(): void
    {
        $this->actingAs(User::factory()->create());

        $schoolA = School::factory()->create(['code' => 'test-a', 'name' => 'A']);
        $gradeA = Grade::factory()->create(['school_id' => $schoolA->id, 'number' => 1]);
        $schoolB = School::factory()->create(['code' => 'test-b', 'name' => 'B']);
        $gradeB = Grade::factory()->create(['school_id' => $schoolB->id, 'number' => 2]);

        $optionA = '<option value="'.$gradeA->id.'">'.__('schools.grade_number', ['number' => 1]).'</option>';
        $optionB = '<option value="'.$gradeB->id.'">'.__('schools.grade_number', ['number' => 2]).'</option>';

        $page = Livewire::test('pages::enrollments.index')
            ->assertDontSee($optionA, false)
            ->assertDontSee($optionB, false);

        $page->set('school_id', $schoolA->id)
            ->assertSee($optionA, false)
            ->assertDontSee($optionB, false);

        $page->set('grade_id', $gradeA->id)
            ->set('school_id', $schoolB->id)
            ->assertSet('grade_id', null)
            ->assertSee($optionB, false)
            ->assertDontSee($optionA, false);
    }

    public function test_count_and_breakdown_match_filtered_set(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $first = Student::factory()->create();
        app(EnrollStudent::class)->handle($first->id, $year->id, $grade->id);

        $second = Student::factory()->create();
        $secondEnrollment = app(EnrollStudent::class)->handle($second->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($secondEnrollment, EnrollmentStatus::Suspended);

        $third = Student::factory()->create();
        $thirdEnrollment = app(EnrollStudent::class)->handle($third->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($thirdEnrollment, EnrollmentStatus::Left);

        Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee(__('enrollments.total', ['count' => 3]))
            ->assertSee(__('enrollments.status_active'))
            ->assertSee(__('enrollments.status_suspended'))
            ->assertSee(__('enrollments.status_left'));
    }

    public function test_archived_students_enrollments_are_listed_with_badge(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $student = Student::factory()->create(['name' => 'Archived Listed']);
        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(ArchiveStudent::class)->handle($student->id);

        Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee('Archived Listed')
            ->assertSee(__('students.archived'));
    }

    public function test_search_by_name_and_number(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $wanted = Student::factory()->create(['name' => 'Zahra Searchable']);
        app(EnrollStudent::class)->handle($wanted->id, $year->id, $grade->id);

        $other = Student::factory()->create(['name' => 'Unrelated Other']);
        app(EnrollStudent::class)->handle($other->id, $year->id, $grade->id);

        $page = Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->set('search', 'Zahra');

        $page->assertSee('Zahra Searchable')
            ->assertDontSee('Unrelated Other');

        $page->set('search', $wanted->refresh()->student_number)
            ->assertSee('Zahra Searchable')
            ->assertDontSee('Unrelated Other');
    }

    public function test_rows_link_to_show_and_show_correct_total(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        $books = FeeType::factory()->create(['name' => 'Books', 'is_mandatory' => false]);
        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $books->id,
            'amount' => '150',
        ]);

        $student = Student::factory()->create(['name' => 'Totals Linked']);
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        $booksFee = GradeFee::query()
            ->where('grade_id', $grade->id)
            ->where('fee_type_id', $books->id)
            ->firstOrFail();
        app(AddOptionalFeeToEnrollment::class)->handle($enrollment->id, $booksFee->id);

        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($enrollment->id, $discount->id);

        // 1000 tuition - 10% + 150 books = 1050 total due.
        Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee(route('enrollments.show', $enrollment), false)
            ->assertSee(Money::format(105000));
    }

    public function test_renders_without_n_plus_one_queries(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        foreach (range(1, 8) as $i) {
            $student = Student::factory()->create(['name' => 'N Plus One '.$i]);
            app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        }

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee('N Plus One 8');

        $this->assertLessThanOrEqual(30, $count);
    }
}
