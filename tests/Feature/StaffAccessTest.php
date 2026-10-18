<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_staff_account_is_active_and_not_forced_to_change_its_password(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
    }

    public function test_an_administrator_can_set_the_access_flags(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'must_change_password' => true,
        ]);

        $this->assertFalse($user->refresh()->is_active);
        $this->assertTrue($user->must_change_password);
    }
}
