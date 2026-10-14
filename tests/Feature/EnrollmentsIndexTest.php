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
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\Payment;
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

    public function test_five_hundred_enrollments_render_within_query_bound(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'perf', 'name' => 'Performance']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $gradeFee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ])->gradeFee;

        // Bulk-insert the performance rows. Student factories define
        // the personal values; finance rows use fixed valid values,
        // with one insert per table to keep this guard fast.
        $now = now();
        $students = Student::factory()->count(500)->make();
        Student::query()->insert($students->map(fn (Student $student): array => [
            'student_number' => $student->student_number,
            'name' => $student->name,
            'phone' => $student->phone,
            'secondary_phone' => null,
            'home_address' => null,
            'mother_name' => null,
            'blood_type' => null,
            'date_of_birth' => null,
            'guardian_name' => null,
            'guardian_relationship' => null,
            'archived_at' => null,
            'archive_note' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $studentIds = Student::query()
            ->whereIn('student_number', $students->pluck('student_number')->all())
            ->pluck('id', 'student_number');

        Enrollment::query()->insert($students->map(fn (Student $student): array => [
            'student_id' => $studentIds[$student->student_number],
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
            'status' => EnrollmentStatus::Active->value,
            'status_note' => null,
            'status_changed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $enrollmentIds = Enrollment::query()
            ->whereIn('student_id', $studentIds->values()->all())
            ->pluck('id', 'student_id');

        EnrollmentFee::query()->insert($studentIds->values()->map(fn (int $studentId): array => [
            'enrollment_id' => $enrollmentIds[$studentId],
            'grade_fee_id' => $gradeFee->id,
            'amount_minor' => $gradeFee->amount_minor,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $chargeIds = EnrollmentFee::query()
            ->whereIn('enrollment_id', $enrollmentIds->values()->all())
            ->pluck('id', 'enrollment_id');

        Payment::query()->insert($studentIds->values()->map(fn (int $studentId, int $index): array => [
            'enrollment_id' => $enrollmentIds[$studentId],
            'enrollment_fee_id' => $chargeIds[$enrollmentIds[$studentId]],
            'amount_minor' => $index % 2 === 0 ? 100000 : 10000,
            'method' => 'cash',
            'transaction_number' => null,
            'paid_on' => date('Y-m-d'),
            'note' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $latest = Enrollment::query()->orderByDesc('id')->firstOrFail();
        $page = Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $page->call('applyFilters')
            ->assertSee(route('enrollments.show', $latest), false);

        $this->assertLessThanOrEqual(35, $count);
    }

    public function test_sorts_by_student_name_both_directions(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        foreach (['Charlie Sorted', 'Alpha Sorted', 'Bravo Sorted'] as $name) {
            $student = Student::factory()->create(['name' => $name]);
            app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        }

        $page = Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->assertSee(__('enrollments.sort_default'));

        $page->set('sort', 'name_asc')
            ->assertSeeInOrder(['Alpha Sorted', 'Bravo Sorted', 'Charlie Sorted']);

        $page->set('sort', 'name_desc')
            ->assertSeeInOrder(['Charlie Sorted', 'Bravo Sorted', 'Alpha Sorted']);
    }

    public function test_sorts_by_total_due_both_directions(): void
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

        // Plain: 1000. Discounted: 1000 - 10% = 900. With books: 1000 + 150 = 1150.
        $plain = Student::factory()->create(['name' => 'Plain Totals']);
        app(EnrollStudent::class)->handle($plain->id, $year->id, $grade->id);

        $cheap = Student::factory()->create(['name' => 'Cheap Totals']);
        $cheapEnrollment = app(EnrollStudent::class)->handle($cheap->id, $year->id, $grade->id);
        $discount = app(CreateDiscount::class)->handle(['name' => 'Sibling', 'percent' => '10']);
        app(ApplyDiscountToEnrollment::class)->handle($cheapEnrollment->id, $discount->id);

        $pricey = Student::factory()->create(['name' => 'Pricey Totals']);
        $priceyEnrollment = app(EnrollStudent::class)->handle($pricey->id, $year->id, $grade->id);
        $booksFee = GradeFee::query()
            ->where('grade_id', $grade->id)
            ->where('fee_type_id', $books->id)
            ->firstOrFail();
        app(AddOptionalFeeToEnrollment::class)->handle($priceyEnrollment->id, $booksFee->id);

        $page = Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters');

        $page->set('sort', 'total_asc')
            ->assertSeeInOrder(['Cheap Totals', 'Plain Totals', 'Pricey Totals']);

        $page->set('sort', 'total_desc')
            ->assertSeeInOrder(['Pricey Totals', 'Plain Totals', 'Cheap Totals']);
    }

    public function test_unknown_sort_falls_back_to_newest_first(): void
    {
        $this->actingAs(User::factory()->create());

        $school = School::factory()->create(['code' => 'test', 'name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = $this->year(2030);

        $first = Student::factory()->create(['name' => 'First Created']);
        app(EnrollStudent::class)->handle($first->id, $year->id, $grade->id);

        $second = Student::factory()->create(['name' => 'Second Created']);
        app(EnrollStudent::class)->handle($second->id, $year->id, $grade->id);

        Livewire::test('pages::enrollments.index')
            ->set('school_id', $school->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('applyFilters')
            ->set('sort', 'not-a-sort')
            ->assertSeeInOrder(['Second Created', 'First Created']);
    }
}
