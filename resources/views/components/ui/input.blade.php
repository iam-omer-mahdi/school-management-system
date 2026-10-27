@props(['error' => null])

@php
$invalid = filled($error);
$base = 'block min-h-11 w-full rounded-xs border p-2.5 text-sm transition placeholder:text-muted focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60';
$state = $invalid
    ? 'border-negative bg-negative-surface text-negative focus:border-negative focus:ring-negative'
    : 'border-line-strong bg-surface text-ink focus:border-primary focus:ring-focus';
$isPassword = $attributes->get('type') === 'password';
$classes = $base.' '.$state.($isPassword ? ' pe-11' : '');
@endphp

@if($isPassword)
<div class="relative">
    <input {{ $attributes->merge(['type' => 'text', 'class' => $classes]) }} data-password @if($invalid) aria-invalid="true" @endif />
    <button type="button" data-password-toggle aria-pressed="false" aria-label="{{ __('common.show_password') }}" class="absolute inset-y-0 end-1 my-auto flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xs text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
    </button>
</div>
@else
<input {{ $attributes->merge(['type' => 'text', 'class' => $classes]) }} @if($invalid) aria-invalid="true" @endif />
@endif
