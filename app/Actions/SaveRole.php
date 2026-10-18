<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\TextNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Creates a role, or renames one and replaces its permissions. Roles are the
 * only source of access (plan D2), so an unusual combination becomes a role
 * here instead of a per-user exception.
 */
final class SaveRole
{
    /**
     * @param  array{name?: string, permissions?: array<int, string>}  $data
     *
     * @throws ValidationException
     */
    public function handle(?int $roleId, array $data): SpatieRole
    {
        Gate::authorize(Permission::StaffManage->value);

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ], [], [
            'name' => __('staff.role_name'),
            'permissions' => __('staff.permissions'),
        ])->validate();

        $name = TextNormalizer::normalize((string) $validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('staff.role_name')]),
            ]);
        }

        $permissions = Permission::expandValues($validated['permissions']);

        if ($roleId === null) {
            return $this->create($name, $permissions);
        }

        return $this->update($roleId, $name, $permissions);
    }

    /** @param  array<int, string>  $permissions */
    private function create(string $name, array $permissions): SpatieRole
    {
        try {
            return DB::transaction(function () use ($name, $permissions): SpatieRole {
                $role = SpatieRole::create(['name' => $name]);
                $role->syncPermissions($permissions);

                return $role;
            });
        } catch (RoleAlreadyExists|UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('staff.errors.role_name_taken'),
            ]);
        }
    }

    /** @param  array<int, string>  $permissions */
    private function update(int $roleId, string $name, array $permissions): SpatieRole
    {
        $role = SpatieRole::query()->findOrFail($roleId);

        $this->guardAdministrator($role, $name, $permissions);
        $this->guardAgainstSelfLockout($role, $permissions);

        try {
            DB::transaction(function () use ($role, $name, $permissions): void {
                $role->update(['name' => $name]);
                $role->syncPermissions($permissions);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('staff.errors.role_name_taken'),
            ]);
        }

        return $role;
    }

    /**
     * The Administrator role is the way back into this page, so it keeps its
     * name and its access to staff and roles (plan §6).
     *
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException
     */
    private function guardAdministrator(SpatieRole $role, string $name, array $permissions): void
    {
        if ($role->name !== Role::Administrator->value) {
            return;
        }

        if ($name !== Role::Administrator->value) {
            throw ValidationException::withMessages([
                'name' => __('staff.errors.administrator_protected'),
            ]);
        }

        if (! in_array(Permission::StaffManage->value, $permissions, true)) {
            throw ValidationException::withMessages([
                'permissions' => __('staff.errors.administrator_keeps_staff'),
            ]);
        }
    }

    /**
     * Editing the role you are signed in with must not take away the last
     * permission that lets you back in (plan §6).
     *
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException
     */
    private function guardAgainstSelfLockout(SpatieRole $role, array $permissions): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->hasRole($role->name)) {
            return;
        }

        if (in_array(Permission::StaffManage->value, $permissions, true)) {
            return;
        }

        $keepsAccessElsewhere = SpatieRole::query()
            ->whereKeyNot($role->id)
            ->whereHas('permissions', fn ($query) => $query->where('name', Permission::StaffManage->value))
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->exists();

        if (! $keepsAccessElsewhere) {
            throw ValidationException::withMessages([
                'permissions' => __('staff.errors.self_lockout'),
            ]);
        }
    }
}
