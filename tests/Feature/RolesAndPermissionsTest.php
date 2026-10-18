<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function expectedPermissionNames(array $permissions): array
    {
        return collect(Permission::withImpliedViews($permissions))
            ->map(fn (Permission $permission): string => $permission->value)
            ->sort()
            ->values()
            ->all();
    }

    public function test_every_permission_in_the_enum_is_seeded(): void
    {
        $seeded = SpatiePermission::query()->pluck('name')->sort()->values()->all();

        $expected = collect(Permission::cases())
            ->map(fn (Permission $permission): string => $permission->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $seeded);
        $this->assertCount(11, $expected);
    }

    public function test_the_four_roles_are_seeded(): void
    {
        $this->assertSame(4, SpatieRole::query()->count());

        foreach (Role::cases() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role->value]);
        }
    }

    public function test_each_role_holds_exactly_its_preset_permissions(): void
    {
        foreach (Role::cases() as $role) {
            $actual = SpatieRole::findByName($role->value)->permissions->pluck('name')->sort()->values()->all();

            $this->assertSame(
                $this->expectedPermissionNames($role->presetPermissions()),
                $actual,
                $role->value.' should hold exactly its preset permissions'
            );
        }
    }

    public function test_no_role_holds_manage_without_view(): void
    {
        foreach (SpatieRole::with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $implied = Permission::from($permission->name)->impliedView();

                if ($implied !== null) {
                    $this->assertTrue(
                        $role->hasPermissionTo($implied->value),
                        $role->name.' holds '.$permission->name.' without '.$implied->value
                    );
                }
            }
        }
    }

    public function test_read_only_views_every_module_it_is_meant_to(): void
    {
        $readOnly = SpatieRole::findByName(Role::ReadOnly->value);

        foreach ([
            Permission::StudentsView,
            Permission::EnrollmentsView,
            Permission::FeesView,
            Permission::PaymentsView,
            Permission::ReportsView,
        ] as $permission) {
            $this->assertTrue($readOnly->hasPermissionTo($permission->value), $permission->value);
        }

        foreach ([
            Permission::StudentsManage,
            Permission::EnrollmentsManage,
            Permission::FeesManage,
            Permission::PaymentsManage,
            Permission::SchoolsManage,
            Permission::StaffManage,
        ] as $permission) {
            $this->assertFalse($readOnly->hasPermissionTo($permission->value), $permission->value);
        }
    }

    /**
     * A deploy promotes every staff account that predates roles so nobody is
     * locked out. Re-running the data migration is what a deploy does.
     */
    public function test_users_without_a_role_are_promoted_to_administrator(): void
    {
        $orphan = User::factory()->create();
        $deactivated = User::factory()->create(['is_active' => false]);
        $registrar = User::factory()->create();
        $registrar->assignRole(Role::Registrar->value);

        $this->runRoleSeedingMigration();

        $this->assertTrue($orphan->refresh()->hasRole(Role::Administrator->value));
        $this->assertTrue($deactivated->refresh()->hasRole(Role::Administrator->value));
        $this->assertFalse($registrar->refresh()->hasRole(Role::Administrator->value));
        $this->assertTrue($registrar->hasRole(Role::Registrar->value));
    }

    private function runRoleSeedingMigration(): void
    {
        $path = (string) collect(glob(database_path('migrations/*_seed_roles_and_permissions.php')))->first();

        $this->assertNotSame('', $path, 'the role seeding migration should exist');

        (require $path)->up();
    }
}
