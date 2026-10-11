<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Local development only: never seed a default account in production.
        if (app()->isProduction()) {
            return;
        }

        User::firstOrCreate(
            ['email' => 'finance@school.local'],
            [
                'name' => 'Finance',
                'password' => Hash::make((string) env('SEED_ADMIN_PASSWORD', 'password')),
                'display_locale' => 'en',
            ]
        );
    }
}
