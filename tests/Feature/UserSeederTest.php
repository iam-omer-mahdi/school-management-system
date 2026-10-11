<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_with_dev_fallback_in_testing(): void
    {
        config()->set('auth.seed_admin_password', null);

        $this->seed(UserSeeder::class);

        $user = User::where('email', 'finance@school.local')->firstOrFail();

        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_requires_password_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        config()->set('auth.seed_admin_password', null);

        try {
            $this->seed(UserSeeder::class);
            $this->fail('Expected RuntimeException for missing seed password');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SEED_ADMIN_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_seeds_with_configured_password_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        config()->set('auth.seed_admin_password', 'staging-secret-123');

        $this->seed(UserSeeder::class);

        $user = User::where('email', 'finance@school.local')->firstOrFail();

        $this->assertTrue(Hash::check('staging-secret-123', $user->password));
    }

    public function test_never_seeds_in_production(): void
    {
        $this->app['env'] = 'production';
        config()->set('auth.seed_admin_password', 'production-secret-123');

        // --force skips the production confirmation prompt for db:seed itself;
        // the seeder must still refuse to create the account.
        $this->artisan('db:seed', ['--class' => UserSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, User::count());
    }
}
