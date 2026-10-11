@props(['type' => 'info', 'dismissible' => false])

@php
$styles = [
    'success' => 'text-green-800 bg-green-50 dark:bg-gray-800 dark:text-green-400',
    'error' => 'text-red-800 bg-red-50 dark:bg-gray-800 dark:text-red-400',
    'warning' => 'text-yellow-800 bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300',
    'info' => 'text-blue-800 bg-blue-50 dark:bg-gray-800 dark:text-blue-400',
];
$role = $type === 'error' ? 'alert' : 'status';
$alertId = 'ui-alert-'.uniqid();
@endphp

<div
    id="{{ $alertId }}"
    {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg p-4 text-sm '.($styles[$type] ?? $styles['info'])]) }}
    role="{{ $role }}"
    aria-live="polite"
>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
    @if($dismissible)
        <button type="button" onclick="document.getElementById('{{ $alertId }}').remove()" class="ms-auto inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg focus:outline-none focus:ring-2 focus:ring-current" aria-label="{{ __('common.dismiss') }}">
            <span class="sr-only">{{ __('common.dismiss') }}</span>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    @endif
</div>
