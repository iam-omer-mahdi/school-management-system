<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ArchiveStudent;
use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentModalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Grade, AcademicYear}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

        return [$grade, $year];
    }

    private function tuition(): FeeType
    {
        return FeeType::query()->where('system_key', 'tuition')->firstOrFail();
    }

    public function test_status_modal_previews_missing_fees_and_applies_them(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->tuition()->id,
            'amount' => '1000',
        ]);

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'active')
            ->assertSee(__('enrollments.missing_fees_confirm'))
            ->assertSee(Money::format(100000))
            ->set('statusModalNote', 'back')
            ->call('confirmStatusChange')
            ->assertHasNoErrors();

        $fresh = $enrollment->refresh();

        $this->assertSame(EnrollmentStatus::Active, $fresh->status);
        $this->assertSame('back', $fresh->status_note);
        $this->assertSame(1, $fresh->fees()->count());

        // Nothing missing anymore: the preview says so instead of listing fees.
        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'left')
            ->call('confirmStatusChange')
            ->assertHasNoErrors();

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'active')
            ->assertSee(__('enrollments.no_missing_fees'))
            ->assertDontSee(__('enrollments.missing_fees_title'));
    }

    public function test_status_modal_without_missing_fees_only_changes_status(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('enrollments.show.status-panel', ['enrollmentId' => $enrollment->id])
            ->call('openStatusModal', 'suspended')
            ->assertSee(__('enrollments.change_status'))
            ->assertDontSee(__('enrollments.missing_fees_title'))
            ->call('confirmStatusChange')
            ->assertHasNoErrors();

        $this->assertSame(EnrollmentStatus::Suspended, $enrollment->refresh()->status);
        $this->assertSame(0, $enrollment->refresh()->fees()->count());
    }

    public function test_archive_modal_warns_and_keeps_history(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->call('openArchiveModal')
            ->assertSee(__('students.archive_warning'))
            ->assertSee(__('students.confirm_archive'))
            ->set('archive_note', 'pause')
            ->call('archive')
            ->assertHasNoErrors();

        $fresh = $student->refresh();

        $this->assertTrue($fresh->isArchived());
        $this->assertSame('pause', $fresh->archive_note);
        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->set('showRestoreModal', true)
            ->assertSee(__('students.confirm_restore'))
            ->call('restore')
            ->assertHasNoErrors();

        $this->assertFalse($student->refresh()->isArchived());
    }

    public function test_enroll_form_expelled_warning_is_non_blocking(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();
        $next = AcademicYear::factory()->create(['name' => '2031/2032', 'starts_on' => '2031-09-01', 'ends_on' => '2032-06-01']);

        $student = Student::factory()->create();
        $old = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($old, EnrollmentStatus::Expelled);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->set('showEnroll', true)
            ->assertSee(__('students.expelled_warning'))
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $next->id)
            ->call('enroll')
            ->assertHasNoErrors();

        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_changing_school_clears_grade_on_enroll_form(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade] = $this->fixtures();

        $other = School::factory()->create(['name' => 'High']);

        Livewire::test('pages::students.show', ['student' => Student::factory()->create()->id])
            ->set('showEnroll', true)
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('school_id', $other->id)
            ->assertSet('grade_id', null);
    }

    public function test_enroll_rejects_grade_from_another_school(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade] = $this->fixtures();

        $other = School::factory()->create(['name' => 'High']);
        $year = AcademicYear::factory()->create(['name' => '2031/2032', 'starts_on' => '2031-09-01', 'ends_on' => '2032-06-01']);
        $student = Student::factory()->create();

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->set('showEnroll', true)
            ->set('school_id', $other->id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->call('enroll')
            ->assertHasErrors('grade_id');

        $this->assertSame(0, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_status_group_labels_current_and_disables_it(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee(__('enrollments.change_status'))
            ->assertSee('role="group"', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertDontSee("openStatusModal('active')", false)
            ->assertSee("openStatusModal('suspended')", false);
    }

    public function test_current_expelled_keeps_solid_danger_while_other_expelled_is_flat(): void
    {
        $this->actingAs(User::factory()->create());
        [$grade, $year] = $this->fixtures();

        $student = Student::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Expelled);

        Livewire::test('pages::enrollments.show', ['enrollment' => $enrollment->id])
            ->assertSee('aria-pressed="true"', false)
            ->assertDontSee("openStatusModal('expelled')", false)
            ->assertSee("openStatusModal('active')", false);
    }

    public function test_archived_student_page_shows_inactive_badge(): void
    {
        $this->actingAs(User::factory()->create());

        $student = Student::factory()->create();
        app(ArchiveStudent::class)->handle($student);

        Livewire::test('pages::students.show', ['student' => $student->id])
            ->assertSee(__('students.archived'));
    }
}
