@props(['error' => null])

@php
$invalid = filled($error);
$base = 'block w-full rounded-lg border p-2.5 pr-10 text-start text-sm shadow-xs transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60';
$state = $invalid
    ? 'border-red-500 bg-red-50 text-red-900 focus:border-red-500 focus:ring-red-300 dark:border-red-500 dark:bg-gray-700 dark:text-red-400 dark:focus:ring-red-800'
    : 'border-gray-300 bg-gray-50 text-gray-900 focus:border-indigo-500 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-indigo-500 dark:focus:ring-indigo-800';
@endphp

<select {{ $attributes->merge(['class' => $base.' '.$state]) }} @if($invalid) aria-invalid="true" @endif>{{ $slot }}</select>
