<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class StaffPageTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    /** @return array<string, string> */
    private function newStaffPayload(array $overrides = []): array
    {
        return [
            'name' => 'Registrar One',
            'email' => 'registrar@school.local',
            'password' => 'initial-pass-1',
            'password_confirmation' => 'initial-pass-1',
            ...$overrides,
        ];
    }

    public function test_the_page_is_closed_to_users_without_staff_manage(): void
    {
        $this->actingAs(User::factory()->withRole()->create());

        $this->get(route('staff.index'))->assertForbidden();
    }

    public function test_an_administrator_can_open_the_page(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin);

        $this->get(route('staff.index'))->assertOk()->assertSee($admin->name);
    }

    public function test_the_nav_entry_follows_the_permission(): void
    {
        $this->actingAs(User::factory()->withRole()->create());
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('staff.index'));

        $this->actingAs($this->administrator());
        $this->get(route('dashboard'))->assertOk()->assertSee(route('staff.index'));
    }

    public function test_an_administrator_can_create_a_staff_account(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test('pages::staff.index')
            ->call('create')
            ->set($this->newStaffPayload(['email' => 'Registrar@School.local']))
            ->set('newRoles', [Role::Registrar->value])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'registrar@school.local')->firstOrFail();

        $this->assertSame('Registrar One', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole(Role::Registrar->value));
        $this->assertTrue(Hash::check('initial-pass-1', (string) $user->password));
    }

    public function test_a_staff_email_must_be_unique(): void
    {
        $this->actingAs($this->administrator());
        User::factory()->create(['email' => 'registrar@school.local']);

        Livewire::test('pages::staff.index')
            ->call('create')
            ->set($this->newStaffPayload())
            ->set('newRoles', [Role::Registrar->value])
            ->call('save')
            ->assertHasErrors('email');

        $this->assertSame(2, User::query()->count());
    }

    public function test_a_staff_account_needs_a_password_of_at_least_eight_characters(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test('pages::staff.index')
            ->call('create')
            ->set($this->newStaffPayload(['password' => 'short', 'password_confirmation' => 'short']))
            ->set('newRoles', [Role::Registrar->value])
            ->call('save')
            ->assertHasErrors('password');

        $this->assertFalse(User::query()->where('email', 'registrar@school.local')->exists());
    }

    public function test_a_staff_account_needs_at_least_one_role(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test('pages::staff.index')
            ->call('create')
            ->set($this->newStaffPayload())
            ->set('newRoles', [])
            ->call('save')
            ->assertHasErrors('roles');

        $this->assertFalse(User::query()->where('email', 'registrar@school.local')->exists());
    }

    public function test_the_add_form_offers_read_only_as_the_default_role(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test('pages::staff.index')
            ->call('create')
            ->assertSet('newRoles', [Role::ReadOnly->value]);
    }

    public function test_an_administrator_can_change_a_staff_members_roles(): void
    {
        $this->actingAs($this->administrator());
        $staff = User::factory()->withRole(Role::ReadOnly->value)->create();

        Livewire::test('pages::staff.index')
            ->call('editAccess', $staff->id)
            ->set('editingRoles', [Role::Finance->value])
            ->call('updateAccess')
            ->assertHasNoErrors();

        $staff->refresh();

        $this->assertTrue($staff->hasRole(Role::Finance->value));
        $this->assertFalse($staff->hasRole(Role::ReadOnly->value));
        $this->assertTrue($staff->is_active);
    }

    public function test_an_administrator_can_deactivate_a_staff_member(): void
    {
        $this->actingAs($this->administrator());
        $staff = User::factory()->withRole(Role::ReadOnly->value)->create();

        Livewire::test('pages::staff.index')
            ->call('editAccess', $staff->id)
            ->set('editingActive', false)
            ->call('updateAccess')
            ->assertHasNoErrors();

        $this->assertFalse($staff->refresh()->is_active);
    }

    public function test_a_deactivated_staff_member_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'gone@school.local',
            'password' => 'secret-pass-1',
            'is_active' => false,
        ]);

        Livewire::test('pages::auth.login')
            ->set('email', 'gone@school.local')
            ->set('password', 'secret-pass-1')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_last_administrator_cannot_lose_the_administrator_role(): void
    {
        $admin = $this->administrator();

        // Someone who can manage staff without being an administrator, so the
        // last-administrator guard is what stops them, not the self-lockout one.
        $officerRole = SpatieRole::create(['name' => 'Staff Officer']);
        $officerRole->syncPermissions([Permission::StaffManage->value]);
        $officer = User::factory()->withRole('Staff Officer')->create();
        $this->actingAs($officer);

        Livewire::test('pages::staff.index')
            ->call('editAccess', $admin->id)
            ->set('editingRoles', [Role::Finance->value])
            ->call('updateAccess')
            ->assertHasErrors('roles');

        $this->assertTrue($admin->refresh()->hasRole(Role::Administrator->value));
    }

    public function test_an_inactive_administrator_does_not_count_as_a_way_back_in(): void
    {
        $spare = $this->administrator();
        $spare->update(['is_active' => false]);

        $admin = $this->administrator();

        // Someone who can manage staff without being an administrator, so the
        // last-administrator guard is what stops them, not the self-lockout one.
        $officerRole = SpatieRole::create(['name' => 'Staff Officer']);
        $officerRole->syncPermissions([Permission::StaffManage->value]);
        $officer = User::factory()->withRole('Staff Officer')->create();
        $this->actingAs($officer);

        Livewire::test('pages::staff.index')
            ->call('editAccess', $admin->id)
            ->set('editingActive', false)
            ->call('updateAccess')
            ->assertHasErrors('roles');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_an_administrator_cannot_remove_their_own_access_to_staff_and_roles(): void
    {
        $admin = $this->administrator();
        // a second administrator keeps the last-administrator guard out of the way
        User::factory()->create()->assignRole(Role::Administrator->value);
        $this->actingAs($admin);

        Livewire::test('pages::staff.index')
            ->call('editAccess', $admin->id)
            ->set('editingRoles', [Role::Finance->value])
            ->call('updateAccess')
            ->assertHasErrors('roles');

        $this->assertTrue($admin->refresh()->hasRole(Role::Administrator->value));
    }

    public function test_an_administrator_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin);

        Livewire::test('pages::staff.index')
            ->call('editAccess', $admin->id)
            ->set('editingActive', false)
            ->call('updateAccess')
            ->assertHasErrors('active');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_a_user_without_staff_manage_cannot_open_the_page_component(): void
    {
        $victim = User::factory()->withRole(Role::ReadOnly->value)->create();
        $this->actingAs($victim);

        Livewire::test('pages::staff.index')
            ->assertStatus(403);
    }
}
