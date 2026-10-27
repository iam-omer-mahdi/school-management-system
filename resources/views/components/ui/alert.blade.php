@props(['type' => 'info', 'dismissible' => false])

@php
$styles = [
    'success' => 'bg-positive-surface text-positive',
    'error' => 'bg-negative-surface text-negative',
    'warning' => 'bg-warning-surface text-warning',
    'info' => 'bg-info-surface text-info',
];
$role = $type === 'error' ? 'alert' : 'status';
$alertId = 'ui-alert-'.uniqid();
@endphp

<div
    id="{{ $alertId }}"
    {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-xs p-4 text-sm '.($styles[$type] ?? $styles['info'])]) }}
    role="{{ $role }}"
    aria-live="polite"
>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
    @if($dismissible)
        <button type="button" onclick="document.getElementById('{{ $alertId }}').remove()" class="ms-auto inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xs hover:bg-surface/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" aria-label="{{ __('common.dismiss') }}">
            <span class="sr-only">{{ __('common.dismiss') }}</span>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    @endif
</div>
