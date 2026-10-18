<?php

declare(strict_types=1);

use App\Actions\CreateGradeFee;
use App\Actions\EnrollStudent;
use App\Enums\Permission;
use App\Models\AcademicYear;
use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An account with no role of its own, holding exactly these permissions: a
 * page asks what the account may do, not which role it came from.
 */
function pagePermissionUser(string ...$permissions): User
{
    $user = User::factory()->withRole()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * An enrollment with tuition charged and a discount in the catalog, so the
 * payment, charge and discount controls really render.
 */
function enrollmentWithControls(): Enrollment
{
    $school = School::factory()->create(['name' => 'Permissions School']);
    $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);
    $year = AcademicYear::factory()->create(['name' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-01']);

    app(CreateGradeFee::class)->handle([
        'grade_id' => $grade->id,
        'academic_year_id' => $year->id,
        'fee_type_id' => FeeType::query()->where('system_key', 'tuition')->firstOrFail()->id,
        'amount' => '1000',
    ]);

    Discount::factory()->create(['percent_bp' => 1000]);

    return app(EnrollStudent::class)->handle(Student::factory()->create()->id, $year->id, $grade->id);
}

it('closes a module page to an account without its view permission', function (string $route) {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route($route))->assertForbidden();
})->with([
    'students.index',
    'enrollments.index',
    'fee-types.index',
    'fee-schedule.index',
    'discounts.index',
    'reports.index',
    'reports.print',
]);

it('opens a page to an account holding its view permission', function () {
    $this->seed(SchoolSeeder::class);

    $this->actingAs(pagePermissionUser(Permission::StudentsView->value));
    $this->get(route('students.index'))->assertOk();

    $this->actingAs(pagePermissionUser(Permission::EnrollmentsView->value));
    $this->get(route('enrollments.index'))->assertOk();

    $this->actingAs(pagePermissionUser(Permission::FeesView->value));
    $this->get(route('fee-types.index'))->assertOk();
    $this->get(route('fee-schedule.index'))->assertOk();
    $this->get(route('discounts.index'))->assertOk();

    $this->actingAs(pagePermissionUser(Permission::ReportsView->value));
    $this->get(route('reports.index'))->assertOk();
});

it('keeps the students create page for accounts that may manage students', function () {
    $this->actingAs(pagePermissionUser(Permission::StudentsView->value));
    $this->get(route('students.create'))->assertForbidden();

    $this->actingAs(pagePermissionUser(Permission::StudentsManage->value));
    $this->get(route('students.create'))->assertOk();
});

it('keeps the fee schedule create page for accounts that may manage fees', function () {
    $this->seed(SchoolSeeder::class);

    $this->actingAs(pagePermissionUser(Permission::FeesView->value));
    $this->get(route('fee-schedule.create'))->assertForbidden();

    $this->actingAs(pagePermissionUser(Permission::FeesManage->value));
    $this->get(route('fee-schedule.create'))->assertOk();
});

it('closes the student and enrollment detail pages to accounts without the view permission', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->create();

    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('students.show', $student))->assertForbidden();
    $this->get(route('enrollments.show', $enrollment))->assertForbidden();
    $this->get(route('enrollments.receipt', $enrollment))->assertForbidden();
});

it('opens the student and enrollment detail pages to accounts holding the view permission', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->create();

    $this->actingAs(pagePermissionUser(Permission::StudentsView->value));
    $this->get(route('students.show', $student))->assertOk();

    $this->actingAs(pagePermissionUser(Permission::EnrollmentsView->value));
    $this->get(route('enrollments.show', $enrollment))->assertOk();
});

it('leaves the schools page open to every signed-in account', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('schools.index'))->assertOk();
});

it('hides every nav link the account has no permission for', function () {
    $this->actingAs(pagePermissionUser(Permission::ReportsView->value));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('reports.index'))
        ->assertSee(route('help'))
        ->assertDontSee(route('students.index'))
        ->assertDontSee(route('enrollments.index'))
        ->assertDontSee(route('fee-types.index'))
        ->assertDontSee(route('fee-schedule.index'))
        ->assertDontSee(route('discounts.index'))
        ->assertDontSee(route('staff.index'))
        ->assertDontSee(__('common.fees'));
});

it('shows every nav link to an administrator', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('students.index'))
        ->assertSee(route('enrollments.index'))
        ->assertSee(route('fee-types.index'))
        ->assertSee(route('fee-schedule.index'))
        ->assertSee(route('discounts.index'))
        ->assertSee(route('reports.index'))
        ->assertSee(route('staff.index'));
});

it('hides the students write controls from an account that may only look', function () {
    $this->actingAs(pagePermissionUser(Permission::StudentsView->value));

    $this->get(route('students.index'))->assertDontSee(__('students.create'));

    $this->actingAs(User::factory()->create());
    $this->get(route('students.index'))->assertSee(__('students.create'));
});

it('hides the fee write controls from an account that may only look', function () {
    $this->seed(SchoolSeeder::class);

    $this->actingAs(pagePermissionUser(Permission::FeesView->value));

    $this->get(route('fee-types.index'))->assertDontSee(__('fees.fee_type_create'));
    $this->get(route('fee-schedule.index'))->assertDontSee(__('fees.schedule_create'));
    $this->get(route('discounts.index'))->assertDontSee(__('fees.discount_create'));

    $this->actingAs(User::factory()->create());
    $this->get(route('fee-types.index'))->assertSee(__('fees.fee_type_create'));
    $this->get(route('fee-schedule.index'))->assertSee(__('fees.schedule_create'));
    $this->get(route('discounts.index'))->assertSee(__('fees.discount_create'));
});

it('refuses a student edit from a view-only account', function () {
    $student = Student::factory()->create(['name' => 'Original Name']);

    $this->actingAs(pagePermissionUser(Permission::StudentsView->value));

    Livewire::test('pages::students.show', ['student' => $student->id])
        ->set('editing', true)
        ->set('form.name', 'Renamed by a viewer')
        ->call('update')
        ->assertStatus(403);

    expect($student->refresh()->name)->toBe('Original Name');
});

it('hides money from an account without the fees or payments view permissions', function () {
    $this->actingAs(User::factory()->create());
    $enrollment = enrollmentWithControls();

    $this->actingAs(pagePermissionUser(
        Permission::StudentsView->value,
        Permission::EnrollmentsView->value,
    ));

    $this->get(route('enrollments.show', $enrollment))
        ->assertOk()
        ->assertDontSee(__('fees.total_due'))
        ->assertDontSee(__('fees.charges'))
        ->assertDontSee(__('payments.payment'));

    $this->get(route('enrollments.index'))->assertOk()->assertDontSee(__('fees.total_due'));
    $this->get(route('students.show', $enrollment->student))->assertOk()->assertDontSee(__('fees.total_due'));
    $this->get(route('dashboard'))->assertOk()->assertDontSee(__('reports.grand_total'));
    $this->get(route('enrollments.receipt', $enrollment))->assertForbidden();
});

it('keeps the payment history for an account that may see payments', function () {
    $this->actingAs(User::factory()->create());
    $enrollment = enrollmentWithControls();

    $this->actingAs(pagePermissionUser(Permission::EnrollmentsView->value, Permission::PaymentsView->value));

    $this->get(route('enrollments.show', $enrollment))
        ->assertOk()
        ->assertSee(__('payments.payment'))
        ->assertDontSee(__('fees.charges'));
});

it('hides the enrollment write controls from an account that may only look', function () {
    $this->actingAs(User::factory()->create());
    $enrollment = enrollmentWithControls();

    $this->actingAs(pagePermissionUser(
        Permission::EnrollmentsView->value,
        Permission::FeesView->value,
        Permission::PaymentsView->value,
    ));

    $this->get(route('enrollments.show', $enrollment))
        ->assertOk()
        ->assertDontSee(__('enrollments.change_status'));

    Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
        ->assertDontSee(__('payments.add_payment'));

    Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
        ->assertDontSee(__('fees.apply_discount'));
});

it('shows the enrollment write controls to an account that may write', function () {
    $this->actingAs(User::factory()->create());
    $enrollment = enrollmentWithControls();

    $this->get(route('enrollments.show', $enrollment))
        ->assertOk()
        ->assertSee(__('enrollments.change_status'));

    Livewire::test('enrollments.show.payments-panel', ['enrollmentId' => $enrollment->id])
        ->assertSee(__('payments.add_payment'));

    Livewire::test('enrollments.show.charges-panel', ['enrollmentId' => $enrollment->id])
        ->assertSee(__('fees.apply_discount'));
});
