@props(['error' => null])

@php
$invalid = filled($error);
$base = 'block min-h-11 w-full rounded-xs border p-2.5 pe-10 text-start text-sm transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60';
$state = $invalid
    ? 'border-negative bg-negative-surface text-negative focus:border-negative focus:ring-negative'
    : 'border-line-strong bg-surface text-ink focus:border-primary focus:ring-focus';
@endphp

<select {{ $attributes->merge(['class' => $base.' '.$state]) }} @if($invalid) aria-invalid="true" @endif>{{ $slot }}</select>
