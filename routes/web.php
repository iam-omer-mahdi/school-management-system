<?php

declare(strict_types=1);

use App\Enums\DisplayLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::post('/locale', function (Request $request) {
    $data = $request->validate([
        'locale' => ['required', Rule::in(DisplayLocale::values())],
    ]);

    $request->session()->put('locale', $data['locale']);

    if ($request->user() !== null) {
        $request->user()->forceFill(['display_locale' => $data['locale']])->save();
    }

    return back();
})->name('locale.switch');

Route::middleware('guest')->group(function (): void {
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/forgot-password', 'pages::auth.forgot-password')->name('password.request');
    Route::livewire('/recover-questions', 'pages::auth.recover-questions')->name('recovery.questions');
});

Route::middleware('auth')->group(function (): void {
    Route::livewire('/first-login', 'pages::auth.first-login')->name('first-login');

    Route::livewire('/', 'pages::dashboard')->name('dashboard');

    Route::livewire('/schools', 'pages::schools.index')->name('schools.index');

    Route::livewire('/academic-years', 'pages::academic-years.index')->name('academic-years.index');

    Route::livewire('/students', 'pages::students.index')->name('students.index');
    Route::livewire('/students/create', 'pages::students.create')->name('students.create');
    Route::livewire('/students/{student}', 'pages::students.show')->name('students.show');

    Route::livewire('/enrollments', 'pages::enrollments.index')->name('enrollments.index');
    Route::livewire('/enrollments/{enrollment}/receipt', 'pages::enrollments.receipt')->name('enrollments.receipt');
    Route::livewire('/enrollments/{enrollment}', 'pages::enrollments.show')->name('enrollments.show');

    Route::livewire('/fee-types', 'pages::fee-types.index')->name('fee-types.index');
    Route::livewire('/fee-schedule', 'pages::fee-schedule.index')->name('fee-schedule.index');
    Route::livewire('/fee-schedule/create', 'pages::fee-schedule.create')->name('fee-schedule.create');
    Route::livewire('/discounts', 'pages::discounts.index')->name('discounts.index');

    Route::livewire('/reports', 'pages::reports.index')->name('reports.index');
    Route::livewire('/reports/print', 'pages::reports.print')->name('reports.print');

    Route::livewire('/staff', 'pages::staff.index')->name('staff.index');
    Route::livewire('/staff/roles/create', 'pages::staff.roles.create')->name('staff.roles.create');
    Route::livewire('/staff/roles/{role}/edit', 'pages::staff.roles.edit')->name('staff.roles.edit');

    Route::livewire('/help', 'pages::help')->name('help');

    Route::livewire('/settings', 'pages::settings')->name('settings');

    Route::post('/logout', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
