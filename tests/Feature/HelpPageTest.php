<?php

declare(strict_types=1);

use App\Models\User;

test('every signed-in user can read the help page', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help'))->assertOk();
});

test('a guest is sent to the login page', function () {
    $this->get(route('help'))->assertRedirect(route('login'));
});

test('the sidebar offers help to a user without any role', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('dashboard'))->assertOk()->assertSee(route('help'));
});

test('the two language files cover the same sections', function () {
    expect(array_keys(__('help.sections', [], 'ar')))
        ->toBe(array_keys(__('help.sections', [], 'en')));
});

test('every section renders in both languages', function () {
    $this->actingAs(User::factory()->withRole()->create());

    foreach (['en', 'ar'] as $locale) {
        $response = $this->withSession(['locale' => $locale])->get(route('help'))->assertOk();

        $response->assertSee(__('help.title', [], $locale));

        foreach (array_keys(__('help.sections', [], 'en')) as $key) {
            $response->assertSee(__("help.sections.{$key}.title", [], $locale));
        }
    }
});
