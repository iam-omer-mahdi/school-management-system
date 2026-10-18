<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeesPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SchoolSeeder::class);
    }

    public function test_fee_pages_require_auth(): void
    {
        $this->get(route('fee-types.index'))->assertRedirect(route('login'));
        $this->get(route('fee-schedule.index'))->assertRedirect(route('login'));
        $this->get(route('fee-schedule.create'))->assertRedirect(route('login'));
    }

    public function test_sidebar_shows_grouped_fees_section(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('common.fees'))
            ->assertSee(route('fee-types.index'))
            ->assertSee(route('fee-schedule.index'));
    }

    public function test_fee_type_create_and_delete_flow(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::fee-types.index')
            ->set('name', 'Bus')
            ->set('is_mandatory', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fee_types', ['name' => 'Bus']);

        $type = FeeType::query()->where('name', 'Bus')->firstOrFail();

        Livewire::test('pages::fee-types.index')
            ->call('confirmDelete', $type->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertFalse(FeeType::query()->where('name', 'Bus')->exists());
    }

    public function test_fee_type_duplicate_shows_error(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::fee-types.index')
            ->set('name', 'Bus')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::fee-types.index')
            ->set('name', 'bus')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_tuition_cannot_be_deleted_from_page(): void
    {
        $this->actingAs(User::factory()->create());

        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        Livewire::test('pages::fee-types.index')
            ->call('confirmDelete', $tuition->id)
            ->call('delete')
            ->assertHasErrors(['fee_type']);

        $this->assertTrue(FeeType::query()->where('system_key', 'tuition')->exists());
    }

    public function test_schedule_create_reports_applied_and_skipped_and_redirects_to_index(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $year = AcademicYear::factory()->create(['name' => '30/31']);
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        $active = Student::factory()->create();
        app(EnrollStudent::class)->handle($active->id, $year->id, $grade->id);

        $ended = Student::factory()->create();
        $endedEnrollment = app(EnrollStudent::class)->handle($ended->id, $year->id, $grade->id);
        app(UpdateEnrollmentStatus::class)->handle($endedEnrollment, EnrollmentStatus::Left);

        Livewire::test('pages::fee-schedule.create')
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->set('fee_type_id', $tuition->id)
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('fee-schedule.index', [
                'school_id' => $grade->school_id,
                'grade_id' => $grade->id,
                'academic_year_id' => $year->id,
            ]));

        $this->assertSame(1, GradeFee::count());
        $this->assertSame(1, $active->enrollments()->firstOrFail()->fees()->count());
        $this->assertSame(0, $endedEnrollment->refresh()->fees()->count());
    }

    public function test_set_fee_page_validates_grade_year_type_and_amount(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::fee-schedule.create')
            ->call('save')
            ->assertHasErrors(['grade_id', 'academic_year_id', 'fee_type_id', 'amount']);

        $this->assertSame(0, GradeFee::count());
    }

    public function test_set_fee_page_starts_blank(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::fee-schedule.create')
            ->assertSet('school_id', null)
            ->assertSet('grade_id', null)
            ->assertSet('academic_year_id', null)
            ->assertSet('fee_type_id', null)
            ->assertSet('amount', '');
    }

    public function test_set_fee_page_changing_school_clears_grade(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $other = Grade::query()->where('school_id', '!=', $grade->school_id)->firstOrFail();

        Livewire::test('pages::fee-schedule.create')
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('school_id', $other->school_id)
            ->assertSet('grade_id', null);
    }

    public function test_set_fee_page_rejects_grade_from_another_school(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $other = Grade::query()->where('school_id', '!=', $grade->school_id)->firstOrFail();
        $year = AcademicYear::factory()->create(['name' => '30/31']);
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        Livewire::test('pages::fee-schedule.create')
            ->set('school_id', $other->school_id)
            ->set('grade_id', $grade->id)
            ->set('academic_year_id', $year->id)
            ->set('fee_type_id', $tuition->id)
            ->set('amount', '1000')
            ->call('save')
            ->assertHasErrors('grade_id');

        $this->assertSame(0, GradeFee::count());
    }

    public function test_schedule_index_changing_school_clears_grade(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $other = Grade::query()->where('school_id', '!=', $grade->school_id)->firstOrFail();

        Livewire::test('pages::fee-schedule.index')
            ->set('school_id', $grade->school_id)
            ->set('grade_id', $grade->id)
            ->set('school_id', $other->school_id)
            ->assertSet('grade_id', null);
    }

    public function test_index_has_no_set_fee_modal_state(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::fee-schedule.index')
            ->assertSee(route('fee-schedule.create'))
            ->assertDontSee('set-fee-type', false)
            ->assertDontSee('set-fee-amount', false);
    }

    public function test_schedule_delete_flow_blocks_charged_fee(): void
    {
        $this->actingAs(User::factory()->create());

        $grade = Grade::firstOrFail();
        $year = AcademicYear::factory()->create(['name' => '30/31']);
        $tuition = FeeType::query()->where('system_key', 'tuition')->firstOrFail();

        $fee = app(CreateGradeFee::class)->handle([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $tuition->id,
            'amount' => '1000',
        ])->gradeFee;

        $student = Student::factory()->create();
        app(EnrollStudent::class)->handle($student->id, $year->id, $grade->id);

        Livewire::test('pages::fee-schedule.index')
            ->call('confirmDelete', $fee->id)
            ->call('delete')
            ->assertHasErrors(['grade_fee']);

        $this->assertTrue(GradeFee::query()->whereKey($fee->id)->exists());
    }
}
