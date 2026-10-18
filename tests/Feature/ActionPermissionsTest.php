<?php

declare(strict_types=1);

use App\Actions\AddOptionalFeeToEnrollment;
use App\Actions\ApplyDiscountToEnrollment;
use App\Actions\ApplyMandatoryFeesToEnrollment;
use App\Actions\ArchiveStudent;
use App\Actions\CreateDiscount;
use App\Actions\CreateFeeType;
use App\Actions\CreateGradeFee;
use App\Actions\CreateStudent;
use App\Actions\DeleteDiscount;
use App\Actions\DeleteFeeType;
use App\Actions\DeleteGradeFee;
use App\Actions\EnrollStudent;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\RemoveEnrollmentDiscount;
use App\Actions\RemoveOptionalFeeFromEnrollment;
use App\Actions\RestoreStudent;
use App\Actions\UpdateEnrollmentStatus;
use App\Actions\VoidPayment;
use App\Actions\VoidRefund;
use App\Enums\EnrollmentStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * One representative call per guarded action, with the permission that should
 * let it through. The guard runs before validation and before any query, so
 * the arguments only have to be plausible — a refusal never touches the data.
 */
dataset('guarded writes', [
    'create student' => [fn () => app(CreateStudent::class)->handle([]), Permission::StudentsManage],
    'archive student' => [fn () => app(ArchiveStudent::class)->handle(1), Permission::StudentsManage],
    'restore student' => [fn () => app(RestoreStudent::class)->handle(1), Permission::StudentsManage],

    'enroll student' => [fn () => app(EnrollStudent::class)->handle(1, 1, 1), Permission::EnrollmentsManage],
    'change enrollment status' => [fn () => app(UpdateEnrollmentStatus::class)->handle(1, EnrollmentStatus::Active), Permission::EnrollmentsManage],
    'apply discount' => [fn () => app(ApplyDiscountToEnrollment::class)->handle(1, 1), Permission::EnrollmentsManage],
    'remove discount' => [fn () => app(RemoveEnrollmentDiscount::class)->handle(1), Permission::EnrollmentsManage],
    'add optional fee' => [fn () => app(AddOptionalFeeToEnrollment::class)->handle(1, 1), Permission::EnrollmentsManage],
    'remove optional fee' => [fn () => app(RemoveOptionalFeeFromEnrollment::class)->handle(1, 1), Permission::EnrollmentsManage],
    'charge mandatory fees' => [fn () => app(ApplyMandatoryFeesToEnrollment::class)->handle(new Enrollment), Permission::EnrollmentsManage],

    'create fee type' => [fn () => app(CreateFeeType::class)->handle([]), Permission::FeesManage],
    'delete fee type' => [fn () => app(DeleteFeeType::class)->handle(1), Permission::FeesManage],
    'create grade fee' => [fn () => app(CreateGradeFee::class)->handle([]), Permission::FeesManage],
    'delete grade fee' => [fn () => app(DeleteGradeFee::class)->handle(1), Permission::FeesManage],
    'create discount' => [fn () => app(CreateDiscount::class)->handle([]), Permission::FeesManage],
    'delete discount' => [fn () => app(DeleteDiscount::class)->handle(1), Permission::FeesManage],

    'record payment' => [fn () => app(RecordPayment::class)->handle([]), Permission::PaymentsManage],
    'void payment' => [fn () => app(VoidPayment::class)->handle(1, 1, null), Permission::PaymentsManage],
    'record refund' => [fn () => app(RecordRefund::class)->handle([]), Permission::PaymentsManage],
    'void refund' => [fn () => app(VoidRefund::class)->handle(1, 1, null), Permission::PaymentsManage],
]);

test('a write refuses an account that does not hold its permission', function (Closure $call) {
    $this->actingAs(User::factory()->withRole()->create());

    expect($call)->toThrow(AuthorizationException::class);
})->with('guarded writes');

test('a write lets through an account holding its permission', function (Closure $call, Permission $permission) {
    $role = SpatieRole::create(['name' => 'Holds One Permission']);
    $role->syncPermissions([$permission->value]);

    $this->actingAs(User::factory()->withRole($role->name)->create());

    $refused = false;

    try {
        $call();
    } catch (AuthorizationException) {
        $refused = true;
    } catch (Throwable) {
        // The guard let it through and the action itself rejected the stand-in
        // arguments. That is not what this test is about.
    }

    expect($refused)->toBeFalse();
})->with('guarded writes');

test('the seeded read only role cannot write anything', function (Closure $call) {
    $this->actingAs(User::factory()->withRole(Role::ReadOnly->value)->create());

    expect($call)->toThrow(AuthorizationException::class);
})->with('guarded writes');

test('every action carries a guard unless it is one of the known exceptions', function () {
    $exempt = [
        'GenerateStudentNumber',   // generates a number, writes nothing
        'RequestEmailChangeOtp',   // the account's own email
        'RequestPasswordOtp',      // recovery, before anyone is signed in
        'SecurityQuestionRecovery', // recovery, before anyone is signed in
    ];

    $actions = collect(glob(app_path('Actions/*.php')))
        ->map(fn (string $path): string => basename($path, '.php'))
        ->reject(fn (string $name): bool => in_array($name, $exempt, true));

    expect($actions)->not->toBeEmpty();

    // Asserted as a list so a failure names the actions that are missing one.
    $unguarded = $actions
        ->filter(fn (string $action): bool => ! str_contains(
            file_get_contents(app_path("Actions/{$action}.php")),
            'Gate::authorize'
        ))
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});
