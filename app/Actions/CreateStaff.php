<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class CreateStaff
{
    /**
     * The administrator sets the first password and hands it over out of band
     * (plan D12). The account is flagged so the member's first sign-in forces
     * a new password and the three security answers.
     *
     * @param  array{name?: string, email?: string, password?: string, password_confirmation?: string, roles?: array<int, string>}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): User
    {
        Gate::authorize(Permission::StaffManage->value);

        $passwordRules = ['required', 'string', 'min:8', 'confirmed'];
        $defaults = Password::defaults();
        if ($defaults !== null) {
            $passwordRules[] = $defaults;
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $passwordRules,
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ], [], [
            'name' => __('staff.name'),
            'email' => __('staff.email'),
            'password' => __('staff.initial_password'),
            'roles' => __('staff.roles'),
        ])->validate();

        try {
            return DB::transaction(function () use ($validated): User {
                $user = User::query()->create([
                    'name' => TextNormalizer::normalize((string) $validated['name']),
                    'email' => mb_strtolower(trim((string) $validated['email'])),
                    'password' => (string) $validated['password'],
                    'is_active' => true,
                    'must_change_password' => true,
                ]);

                $user->syncRoles($validated['roles']);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => __('staff.errors.email_taken'),
            ]);
        }
    }
}
