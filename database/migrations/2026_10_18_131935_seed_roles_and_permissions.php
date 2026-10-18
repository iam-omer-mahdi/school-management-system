<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Seed every ability plus the four role presets, then give Administrator to
     * any account that predates roles. Without that last step a deploy locks
     * every existing user out (plan §6). Safe to re-run.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            SpatiePermission::findOrCreate($permission->value);
        }

        foreach (Role::cases() as $role) {
            SpatieRole::findOrCreate($role->value)->syncPermissions(
                collect(Permission::withImpliedViews($role->presetPermissions()))
                    ->map(fn (Permission $permission): string => $permission->value)
                    ->all()
            );
        }

        // with('roles'): Spatie's assignRole() reads $this->roles directly, and
        // lazy loading is disabled outside production.
        User::query()
            ->with('roles')
            ->whereDoesntHave('roles')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $user->assignRole(Role::Administrator->value);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Deliberately not reversed: deleting the roles would leave every account
     * without access. Re-run the migration instead.
     */
    public function down(): void {}
};
