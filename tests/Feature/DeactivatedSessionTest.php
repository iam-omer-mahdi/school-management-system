<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('signs out an account deactivated after it signed in', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    $user->update(['is_active' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});
