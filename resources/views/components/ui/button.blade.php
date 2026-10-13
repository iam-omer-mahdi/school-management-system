@props(['variant' => 'primary', 'size' => 'md', 'spinner' => true, 'loadingTarget' => null, 'href' => null])

@php
$base = 'inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg border font-medium whitespace-nowrap transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-60';

$variants = [
    'primary' => 'border-transparent bg-indigo-600 text-white shadow-xs hover:bg-indigo-700 focus:ring-indigo-300 active:bg-indigo-800 dark:bg-indigo-600 dark:hover:bg-indigo-700 dark:focus:ring-indigo-800',
    'secondary' => 'border-gray-300 bg-white text-gray-900 shadow-xs hover:bg-gray-100 focus:ring-gray-100 active:bg-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:hover:border-gray-600 dark:hover:bg-gray-700 dark:focus:ring-gray-700',
    'danger' => 'border-transparent bg-red-700 text-white shadow-xs hover:bg-red-800 focus:ring-red-300 active:bg-red-900 dark:bg-red-600 dark:hover:bg-red-700 dark:focus:ring-red-900',
    'warning' => 'border-transparent bg-amber-500 text-white shadow-xs hover:bg-amber-600 focus:ring-amber-300 active:bg-amber-700 dark:bg-amber-500 dark:hover:bg-amber-600 dark:focus:ring-amber-800',
    'ghost' => 'border-transparent text-indigo-700 hover:bg-indigo-100 focus:ring-indigo-200 active:bg-indigo-200 dark:text-indigo-300 dark:hover:bg-gray-700 dark:focus:ring-gray-700',
];

$sizes = [
    'sm' => 'px-3 py-2 text-xs',
    'md' => 'px-5 py-2.5 text-sm',
    'lg' => 'px-5 py-3 text-base',
];

$classes = $base.' '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
$target = $loadingTarget ?? $attributes->get('wire:click', $attributes->get('wire:submit'));
// Respect an explicit type (e.g. type="submit" inside forms); default to button
// so the component never submits a form by accident. Rendered exactly once to
// avoid duplicate attributes, where browsers would honor the first one.
$type = $href ? null : ($attributes->get('type') ?? 'button');
$buttonAttributes = $attributes->except('type')->merge(['class' => $classes]);
@endphp

@if($href)
<a href="{{ $href }}" {{ $buttonAttributes }}>
@else
<button type="{{ $type }}" {{ $buttonAttributes }}>
@endif
    @if($spinner && ! $href)
        <span wire:loading.delay @if($target) wire:target="{{ $target }}" @endif class="inline-flex">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
        </span>
    @endif
    {{ $slot }}
@if($href)
</a>
@else
</button>
@endif
