<?php

declare(strict_types=1);

use App\Actions\SaveRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;

function makeAdministrator(): User
{
    return User::factory()->create()->assignRole(Role::Administrator->value);
}

/**
 * A user who can manage staff without holding the Administrator role, which
 * is who the Administrator-only guards are meant for.
 */
function makeStaffOfficer(): User
{
    $role = SpatieRole::create(['name' => 'Staff Officer']);
    $role->syncPermissions([Permission::StaffManage->value]);

    return User::factory()->withRole($role->name)->create();
}

function findRole(string $name): SpatieRole
{
    return SpatieRole::findByName($name);
}

test('the list shows the roles with their counts', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->assertSee(Role::Administrator->value)
        ->assertSee(Role::Registrar->value)
        ->assertSee(Role::Finance->value)
        ->assertSee(Role::ReadOnly->value)
        ->assertSee(trans_choice('staff.users_count', 1))
        ->assertSee(trans_choice('staff.permissions_count', 11));
});

test('the matrix offers every permission', function () {
    $this->actingAs(makeAdministrator());

    $component = Livewire::test('pages::staff.index')->call('newRole');

    foreach (Permission::cases() as $permission) {
        $component->assertSee($permission->value);
    }

    $component->assertSee(__('staff.modules.students'))->assertSee(__('staff.modules.staff'));
});

test('an administrator can create a role', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('newRole')
        ->set('roleName', 'Gatekeeper')
        ->set('selectedPermissions', [Permission::StudentsView->value])
        ->call('saveRole')
        ->assertHasNoErrors();

    $role = findRole('Gatekeeper');

    expect($role->permissions->pluck('name')->all())->toBe([Permission::StudentsView->value]);
});

test('a role name must be unique', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('newRole')
        ->set('roleName', Role::Registrar->value)
        ->set('selectedPermissions', [Permission::StudentsView->value])
        ->call('saveRole')
        ->assertHasErrors('name');

    expect(SpatieRole::query()->count())->toBe(4);
});

test('a role needs at least one permission', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('newRole')
        ->set('roleName', 'Empty')
        ->set('selectedPermissions', [])
        ->call('saveRole')
        ->assertHasErrors('permissions');

    expect(SpatieRole::query()->where('name', 'Empty')->exists())->toBeFalse();
});

test('ticking manage ticks view', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('newRole')
        ->set('selectedPermissions', [Permission::StudentsManage->value])
        ->assertSet('selectedPermissions', [Permission::StudentsManage->value, Permission::StudentsView->value]);
});

test('a saved role gains the views its manages imply', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('newRole')
        ->set('roleName', 'Enroller')
        ->set('selectedPermissions', [Permission::EnrollmentsManage->value])
        ->call('saveRole')
        ->assertHasNoErrors();

    $role = findRole('Enroller');

    expect($role->hasPermissionTo(Permission::EnrollmentsView->value))->toBeTrue()
        ->and($role->hasPermissionTo(Permission::EnrollmentsManage->value))->toBeTrue();
});

test('an administrator can change a roles permissions', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('editRole', findRole(Role::ReadOnly->value)->id)
        ->set('selectedPermissions', [Permission::StudentsView->value])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect(findRole(Role::ReadOnly->value)->permissions->pluck('name')->all())
        ->toBe([Permission::StudentsView->value]);
});

test('an administrator can rename a role', function () {
    $this->actingAs(makeAdministrator());

    Livewire::test('pages::staff.index')
        ->call('editRole', findRole(Role::Registrar->value)->id)
        ->set('roleName', 'Admissions')
        ->call('saveRole')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('roles', ['name' => 'Admissions']);
    $this->assertDatabaseMissing('roles', ['name' => Role::Registrar->value]);
});

test('the administrator role cannot be renamed', function () {
    $this->actingAs(makeStaffOfficer());
    $administrator = findRole(Role::Administrator->value);

    Livewire::test('pages::staff.index')
        ->call('editRole', $administrator->id)
        ->set('roleName', 'Boss')
        ->call('saveRole')
        ->assertHasErrors(['name' => __('staff.errors.administrator_protected')]);

    $this->assertDatabaseHas('roles', ['id' => $administrator->id, 'name' => Role::Administrator->value]);
});

test('the administrator role must keep staff access', function () {
    $this->actingAs(makeStaffOfficer());

    Livewire::test('pages::staff.index')
        ->call('editRole', findRole(Role::Administrator->value)->id)
        ->set('selectedPermissions', [Permission::StudentsView->value])
        ->call('saveRole')
        ->assertHasErrors(['permissions' => __('staff.errors.administrator_keeps_staff')]);

    expect(findRole(Role::Administrator->value)->hasPermissionTo(Permission::StaffManage->value))->toBeTrue();
});

test('the administrator role cannot be deleted', function () {
    $this->actingAs(makeStaffOfficer());
    $administrator = findRole(Role::Administrator->value);

    Livewire::test('pages::staff.index')
        ->call('confirmDeleteRole', $administrator->id)
        ->call('deleteRole')
        ->assertHasErrors(['role' => __('staff.errors.administrator_protected')]);

    $this->assertDatabaseHas('roles', ['id' => $administrator->id]);
});

test('a role assigned to a user cannot be deleted', function () {
    $this->actingAs(makeAdministrator());
    $readOnly = findRole(Role::ReadOnly->value);
    User::factory()->withRole($readOnly->name)->create();

    Livewire::test('pages::staff.index')
        ->call('confirmDeleteRole', $readOnly->id)
        ->call('deleteRole')
        ->assertHasErrors(['role' => __('staff.errors.role_in_use')]);

    $this->assertDatabaseHas('roles', ['id' => $readOnly->id]);
});

test('an unused role can be deleted', function () {
    $this->actingAs(makeAdministrator());
    $spare = SpatieRole::create(['name' => 'Spare']);

    Livewire::test('pages::staff.index')
        ->call('confirmDeleteRole', $spare->id)
        ->call('deleteRole')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('roles', ['id' => $spare->id]);
});

test('a user cannot strip their own staff access through a role', function () {
    $this->actingAs(makeStaffOfficer());

    Livewire::test('pages::staff.index')
        ->call('editRole', findRole('Staff Officer')->id)
        ->set('selectedPermissions', [Permission::StudentsView->value])
        ->call('saveRole')
        ->assertHasErrors(['permissions' => __('staff.errors.self_lockout')]);

    expect(findRole('Staff Officer')->hasPermissionTo(Permission::StaffManage->value))->toBeTrue();
});

test('the role actions are closed to users without staff manage', function () {
    $this->actingAs(User::factory()->withRole()->create());

    expect(fn () => app(SaveRole::class)->handle(null, [
        'name' => 'Sneaky',
        'permissions' => [Permission::StudentsView->value],
    ]))->toThrow(AuthorizationException::class);
});
