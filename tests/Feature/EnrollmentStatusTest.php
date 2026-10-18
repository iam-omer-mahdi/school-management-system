<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyMandatoryFeesToEnrollment;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnrollmentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{Grade, AcademicYear, FeeType}
     */
    private function fixtures(): array
    {
        $school = School::factory()->create(['name' => 'Test']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
        $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        return [$grade, $year, $tuition];
    }

    private function enroll(Grade $grade, AcademicYear $year): Enrollment
    {
        $student = Student::factory()->create();

        return app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);
    }

    /**
     * @return array<string, array{EnrollmentStatus, EnrollmentStatus}>
     */
    public static function everyTransitionPair(): array
    {
        $cases = [];

        foreach (EnrollmentStatus::cases() as $from) {
            foreach (EnrollmentStatus::cases() as $to) {
                $cases[$from->value.'_to_'.$to->value] = [$from, $to];
            }
        }

        return $cases;
    }

    #[DataProvider('everyTransitionPair')]
    public function test_every_transition_pair_is_allowed(EnrollmentStatus $from, EnrollmentStatus $to): void
    {
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, $from, 'first');
        $afterFirst = $enrollment->refresh()->status_note;
        app(UpdateEnrollmentStatus::class)->handle($enrollment->refresh(), $to, 'second');

        $fresh = $enrollment->refresh();

        $this->assertSame($to, $fresh->status);
        // A no-op transition leaves the row (including the note) untouched.
        $this->assertSame($from === $to ? $afterFirst : 'second', $fresh->status_note);

        if ($from === $to && $from === EnrollmentStatus::Active) {
            // Both calls were no-ops on a fresh enrollment: never stamped.
            $this->assertNull($fresh->status_changed_at);
        } else {
            $this->assertNotNull($fresh->status_changed_at);
        }
    }

    public function test_noop_transition_keeps_timestamp(): void
    {
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Suspended, 'note');
        $stamped = $enrollment->refresh()->status_changed_at;

        $this->travel(1)->second();
        app(UpdateEnrollmentStatus::class)->handle($enrollment->refresh(), EnrollmentStatus::Suspended);

        $this->assertTrue($stamped->equalTo($enrollment->refresh()->status_changed_at));
    }

    public function test_ended_to_charged_applies_missing_mandatory_fees(): void
    {
        [$grade, $year, $tuition] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        // Suspended while no schedule exists: nothing to charge.
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        // Tuition is scheduled while the enrollment is ended: skipped.
        $result = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        $this->assertSame(0, $result->applied);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $enrollment->refresh()->fees()->count());

        // Preview lists the missing tuition before confirmation.
        $missing = app(UpdateEnrollmentStatus::class)->missingFor($enrollment->refresh());

        $this->assertSame(1, $missing->count());
        $this->assertSame(100000, $missing->firstOrFail()->amount_minor);

        // Reactivation charges exactly the missing fee.
        app(UpdateEnrollmentStatus::class)->handle($enrollment->refresh(), EnrollmentStatus::Active);

        $this->assertSame(1, $enrollment->refresh()->fees()->count());
        $this->assertSame(100000, $enrollment->fees()->firstOrFail()->amount_minor);

        // Idempotent: a second pass adds nothing.
        $again = app(ApplyMandatoryFeesToEnrollment::class)->handle($enrollment->refresh());

        $this->assertSame(0, $again);
        $this->assertSame(1, $enrollment->refresh()->fees()->count());
        $this->assertSame(0, app(UpdateEnrollmentStatus::class)->missingFor($enrollment->refresh())->count());
    }

    public function test_charged_to_ended_removes_nothing(): void
    {
        [$grade, $year, $tuition] = $this->fixtures();

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        $enrollment = $this->enroll($grade, $year);

        $this->assertSame(1, $enrollment->fees()->count());

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Transferred);

        $this->assertSame(1, $enrollment->refresh()->fees()->count());
    }

    public function test_status_change_rolls_back_when_fee_application_fails(): void
    {
        [$grade, $year, $tuition] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Left);

        app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        // Fail the charge with a genuine query error so the status
        // change must roll back with it. Only the first charge fails;
        // the guard is spent afterwards like the collision helper in
        // StudentNumberTest, so later tests are unaffected.
        $remaining = 1;

        DB::listen(function ($query) use (&$remaining): void {
            if ($remaining > 0 && str_starts_with((string) $query->sql, 'insert into `enrollment_fees`')) {
                $remaining--;

                throw new QueryException(
                    'mysql',
                    (string) $query->sql,
                    $query->bindings,
                    new \RuntimeException('Simulated charge failure.')
                );
            }
        });

        try {
            app(UpdateEnrollmentStatus::class)->handle($enrollment->refresh(), EnrollmentStatus::Active);
            $this->fail('Expected the fee failure to propagate');
        } catch (QueryException) {
            // Expected: the status change rolls back with the failure.
        }

        $this->assertSame(EnrollmentStatus::Left, $enrollment->refresh()->status);
        $this->assertSame(0, $enrollment->refresh()->fees()->count());
    }

    public function test_sequential_status_changes_use_fresh_rows(): void
    {
        [$grade, $year] = $this->fixtures();
        $enrollment = $this->enroll($grade, $year);

        // Two sequential transitions decided from locked re-reads both land.
        app(UpdateEnrollmentStatus::class)->handle($enrollment, EnrollmentStatus::Suspended, 'one');
        app(UpdateEnrollmentStatus::class)->handle($enrollment->refresh(), EnrollmentStatus::Left, 'two');

        $fresh = $enrollment->refresh();

        $this->assertSame(EnrollmentStatus::Left, $fresh->status);
        $this->assertSame('two', $fresh->status_note);
    }

    public function test_new_mandatory_fee_skips_ended_and_charges_active_and_suspended(): void
    {
        [$grade, $year, $tuition] = $this->fixtures();
        $enroller = app(EnrollStudent::class);
        $changer = app(UpdateEnrollmentStatus::class);

        $ids = [];

        foreach (EnrollmentStatus::cases() as $status) {
            $student = Student::factory()->create();
            $enrollment = $enroller->handle($student->id, $year->id, $grade->id);
            $changer->handle($enrollment, $status);
            $ids[$status->value] = $enrollment->id;
        }

        $result = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ]);

        $this->assertSame(2, $result->applied);
        $this->assertSame(3, $result->skipped);

        foreach (['active', 'suspended'] as $charged) {
            $this->assertSame(
                1,
                Enrollment::find($ids[$charged])->fees()->count(),
                "Expected a charge for status [{$charged}]"
            );
        }

        foreach (['transferred', 'expelled', 'left'] as $skipped) {
            $this->assertSame(
                0,
                Enrollment::find($ids[$skipped])->fees()->count(),
                "Expected no charge for status [{$skipped}]"
            );
        }
    }
}
