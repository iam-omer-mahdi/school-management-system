<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

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

test('the two language files cover the same root keys', function () {
    expect(array_keys(__('help', [], 'ar')))
        ->toBe(array_keys(__('help', [], 'en')));
});

test('searching filters the sections', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help', ['q' => 'RECEIPT']))
        ->assertOk()
        ->assertSee(__('help.sections.payments.intro'))
        ->assertDontSee(__('help.sections.schools.intro'));
});

test('searching matches step and note text', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help', ['q' => 'audit trail']))
        ->assertOk()
        ->assertSee(__('help.sections.payments.intro'))
        ->assertDontSee(__('help.sections.refunds.intro'));
});

test('a search with no matches shows the empty state', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help', ['q' => 'zzzz']))
        ->assertOk()
        ->assertSee(__('help.no_results_title'))
        ->assertSee(__('help.no_results_description', ['term' => 'zzzz']))
        ->assertDontSee(__('help.sections.payments.intro'));
});

test('searching works in arabic', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->withSession(['locale' => 'ar'])
        ->get(route('help', ['q' => 'استرداد']))
        ->assertOk()
        ->assertSee(__('help.sections.refunds.intro', [], 'ar'))
        ->assertDontSee(__('help.sections.payments.intro', [], 'ar'));
});

test('choosing a topic shows only that section', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help', ['topic' => 'refunds']))
        ->assertOk()
        ->assertSee(__('help.sections.refunds.intro'))
        ->assertDontSee(__('help.sections.payments.intro'))
        ->assertSee(__('help.all_categories'));
});

test('an unknown topic falls back to every section', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help', ['topic' => 'bogus']))
        ->assertOk()
        ->assertSee(__('help.sections.payments.intro'))
        ->assertSee(__('help.sections.refunds.intro'));
});

test('topic and search filters combine', function () {
    $this->actingAs(User::factory()->withRole()->create());

    $this->get(route('help', ['topic' => 'refunds', 'q' => 'audit trail']))
        ->assertOk()
        ->assertSee(__('help.no_results_title'))
        ->assertDontSee(__('help.sections.payments.intro'));

    $this->get(route('help', ['topic' => 'payments', 'q' => 'audit trail']))
        ->assertOk()
        ->assertSee(__('help.sections.payments.intro'));
});

test('the topic buttons set and validate the category', function () {
    $this->actingAs(User::factory()->withRole()->create());

    Livewire::test('pages::help')
        ->call('setCategory', 'refunds')
        ->assertSet('category', 'refunds')
        ->assertSee(__('help.sections.refunds.intro'))
        ->assertDontSee(__('help.sections.payments.intro'))
        ->call('setCategory', 'bogus')
        ->assertSet('category', 'all');
});
