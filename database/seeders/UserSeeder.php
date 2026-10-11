<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Local development only: never seed a default account in production.
        if (app()->isProduction()) {
            return;
        }

        $password = (string) config('auth.seed_admin_password', '');

        if (! app()->environment('local', 'testing') && $password === '') {
            throw new RuntimeException('SEED_ADMIN_PASSWORD must be set to run UserSeeder outside local and testing environments.');
        }

        User::firstOrCreate(
            ['email' => 'finance@school.local'],
            [
                'name' => 'Finance',
                'password' => Hash::make($password !== '' ? $password : 'password'),
                'display_locale' => 'en',
            ]
        );
    }
}
