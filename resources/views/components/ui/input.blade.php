@props(['error' => null])

@php
$invalid = filled($error);
$base = 'block w-full rounded-lg border p-2.5 text-sm shadow-xs transition placeholder:text-gray-400 focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60 dark:placeholder-gray-400';
$state = $invalid
    ? 'border-red-500 bg-red-50 text-red-900 focus:border-red-500 focus:ring-red-300 dark:border-red-500 dark:bg-gray-700 dark:text-red-400 dark:focus:ring-red-800'
    : 'border-gray-300 bg-gray-50 text-gray-900 focus:border-indigo-500 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-indigo-500 dark:focus:ring-indigo-800';
$isPassword = $attributes->get('type') === 'password';
$classes = $base.' '.$state.($isPassword ? ' pe-9' : '');
@endphp

@if($isPassword)
<div class="relative">
    <input {{ $attributes->merge(['type' => 'text', 'class' => $classes]) }} data-password @if($invalid) aria-invalid="true" @endif />
    <button type="button" data-password-toggle aria-pressed="false" aria-label="{{ __('common.show_password') }}" class="absolute inset-y-0 end-1 my-auto flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-300 dark:hover:text-white dark:focus:ring-indigo-400">
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
    </button>
</div>
@else
<input {{ $attributes->merge(['type' => 'text', 'class' => $classes]) }} @if($invalid) aria-invalid="true" @endif />
@endif
