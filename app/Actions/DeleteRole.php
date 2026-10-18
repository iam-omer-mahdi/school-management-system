<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;

final class DeleteRole
{
    /**
     * A role in use is not deleted: its holders would lose access silently,
     * so they are moved to another role first (plan §4.3).
     *
     * @throws ValidationException
     */
    public function handle(int $roleId): void
    {
        Gate::authorize(Permission::StaffManage->value);

        $role = SpatieRole::query()->findOrFail($roleId);

        if ($role->name === Role::Administrator->value) {
            throw ValidationException::withMessages([
                'role' => __('staff.errors.administrator_protected'),
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => __('staff.errors.role_in_use'),
            ]);
        }

        DB::transaction(function () use ($role): void {
            $role->delete();
        });
    }
}
