<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'display_locale' => 'en',
        ];
    }

    /**
     * A factory user holds the Administrator role unless the test says
     * otherwise: most tests are about what a module does, not about who may
     * call it. Tests about access state their role with withRole().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->loadMissing('roles')->assignRole(Role::Administrator->value);
        });
    }

    /**
     * Hold exactly these roles — pass none for an account with no access at all.
     */
    public function withRole(string ...$roles): static
    {
        return $this->afterCreating(function (User $user) use ($roles): void {
            $user->loadMissing('roles')->syncRoles($roles);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Attach hashed security answers for the current recovery questions.
     * Answers are normalized (trimmed and lowercased) like the recovery action.
     */
    public function withSecurityAnswers(
        string $answer1 = 'invented-nickname',
        string $answer2 = 'first-corner-shop',
        string $answer3 = 'first-cover-to-cover-book'
    ): static {
        $normalize = fn (string $value): string => mb_strtolower(trim($value), 'UTF-8');

        return $this->state(fn (array $attributes) => [
            'security_answer_1' => Hash::make($normalize($answer1)),
            'security_answer_2' => Hash::make($normalize($answer2)),
            'security_answer_3' => Hash::make($normalize($answer3)),
        ]);
    }
}
