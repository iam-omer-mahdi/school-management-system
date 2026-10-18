<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;

final class UpdateStaffAccess
{
    /**
     * Roles and the active flag are set together, because both decide whether
     * someone can get in — and both can lock the last administrator out
     * (plan §6), which this refuses to do.
     *
     * @param  array<int, string>  $roles
     *
     * @throws ValidationException
     */
    public function handle(int $userId, array $roles, bool $isActive): User
    {
        Gate::authorize(Permission::StaffManage->value);

        Validator::make(['roles' => $roles], [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ], [], ['roles' => __('staff.roles')])->validate();

        $user = User::query()->with('roles')->findOrFail($userId);

        $this->guardAgainstLockout($user, $roles, $isActive);

        DB::transaction(function () use ($user, $roles, $isActive): void {
            $user->syncRoles($roles);
            $user->update(['is_active' => $isActive]);
        });

        return $user;
    }

    /**
     * @param  array<int, string>  $roles
     *
     * @throws ValidationException
     */
    private function guardAgainstLockout(User $user, array $roles, bool $isActive): void
    {
        // Self first, so a lone administrator gets the precise reason.
        if (Auth::id() === $user->id && ! $isActive) {
            throw ValidationException::withMessages([
                'active' => __('staff.errors.self_deactivate'),
            ]);
        }

        // Losing the role or switching the account off both end an
        // administrator, so both need another *active* administrator to exist.
        $losesAdministrator = $user->hasRole(Role::Administrator->value)
            && (! in_array(Role::Administrator->value, $roles, true) || ! $isActive);

        $anotherActiveAdministrator = User::query()
            ->role(Role::Administrator->value)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->exists();

        if ($losesAdministrator && ! $anotherActiveAdministrator) {
            throw ValidationException::withMessages([
                'roles' => __('staff.errors.last_administrator'),
            ]);
        }

        if (Auth::id() !== $user->id) {
            return;
        }

        if (! $this->rolesGrantStaffManage($roles)) {
            throw ValidationException::withMessages([
                'roles' => __('staff.errors.self_lockout'),
            ]);
        }
    }

    /** @param  array<int, string>  $roles */
    private function rolesGrantStaffManage(array $roles): bool
    {
        return SpatieRole::query()
            ->whereIn('name', $roles)
            ->whereHas('permissions', fn ($query) => $query->where('name', Permission::StaffManage->value))
            ->exists();
    }
}
