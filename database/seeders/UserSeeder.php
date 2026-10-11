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
        User::firstOrCreate(
            ['email' => 'finance@school.local'],
            [
                'name' => 'Finance',
                'password' => Hash::make('password'),
                'display_locale' => 'en',
            ]
        );
    }
}
