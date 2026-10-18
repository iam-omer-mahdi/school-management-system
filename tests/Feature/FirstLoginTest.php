<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function makeFlaggedStaff(): User
{
    return User::factory()->create([
        'email' => 'registrar@school.local',
        'password' => 'admin-set-password',
        'must_change_password' => true,
    ]);
}

test('a flagged user is sent to the setup page from every page', function () {
    $this->actingAs(makeFlaggedStaff());

    foreach (['dashboard', 'students.index', 'settings', 'staff.index'] as $route) {
        $this->get(route($route))->assertRedirect(route('first-login'));
    }
});

test('the setup page opens for a flagged user', function () {
    $this->actingAs(makeFlaggedStaff());

    $this->get(route('first-login'))->assertOk();
});

test('an unflagged user is left alone', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('logout and the locale switch stay reachable while flagged', function () {
    $user = makeFlaggedStaff();

    $this->actingAs($user)->post(route('locale.switch'), ['locale' => 'ar'])->assertRedirect();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test("livewire's update endpoint is not redirected", function () {
    $response = $this->actingAs(makeFlaggedStaff())->post(route('default-livewire.update'));

    expect($response->headers->get('Location'))->not->toBe(route('first-login'));
});

test('an unflagged user cannot open the setup page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('first-login'))->assertRedirect(route('dashboard'));
});

test('completing the setup clears the flag, the password and the answers', function () {
    $user = makeFlaggedStaff();
    $this->actingAs($user);

    Livewire::test('pages::auth.first-login')
        ->set('password', 'brand-new-password')
        ->set('password_confirmation', 'brand-new-password')
        ->set('answer_1', 'Alpha')
        ->set('answer_2', 'Beta')
        ->set('answer_3', 'Gamma')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('brand-new-password', $user->password))->toBeTrue()
        ->and(Hash::check('admin-set-password', $user->password))->toBeFalse()
        ->and(Hash::check('alpha', (string) $user->security_answer_1))->toBeTrue()
        ->and(Hash::check('beta', (string) $user->security_answer_2))->toBeTrue()
        ->and(Hash::check('gamma', (string) $user->security_answer_3))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('the setup needs the password and all three answers', function () {
    $user = makeFlaggedStaff();
    $this->actingAs($user);

    Livewire::test('pages::auth.first-login')
        ->call('save')
        ->assertHasErrors(['password', 'answer_1', 'answer_2', 'answer_3']);

    $user->refresh();

    expect($user->must_change_password)->toBeTrue()
        ->and(Hash::check('admin-set-password', $user->password))->toBeTrue();
});

test('the setup refuses a short or unconfirmed password', function () {
    $this->actingAs(makeFlaggedStaff());

    Livewire::test('pages::auth.first-login')
        ->set('password', 'short')
        ->set('password_confirmation', 'different')
        ->set('answer_1', 'Alpha')
        ->set('answer_2', 'Beta')
        ->set('answer_3', 'Gamma')
        ->call('save')
        ->assertHasErrors('password');
});

test('a one character answer is refused', function () {
    $this->actingAs(makeFlaggedStaff());

    Livewire::test('pages::auth.first-login')
        ->set('password', 'brand-new-password')
        ->set('password_confirmation', 'brand-new-password')
        ->set('answer_1', 'A')
        ->set('answer_2', 'Beta')
        ->set('answer_3', 'Gamma')
        ->call('save')
        ->assertHasErrors('answer_1');
});

test('the login page never reveals that an administrator set the password', function () {
    makeFlaggedStaff();

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(__('auth.first_login_notice'));
});
