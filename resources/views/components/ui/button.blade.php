@props(['variant' => 'primary', 'size' => 'md', 'spinner' => true, 'loadingTarget' => null, 'href' => null])

@php
$base = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xs border font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60';

$variants = [
    'primary' => 'border-transparent bg-primary text-on-primary hover:bg-primary-hover active:bg-primary-hover',
    'secondary' => 'border-line-strong bg-surface text-ink hover:bg-surface-raised active:bg-surface-raised',
    'danger' => 'border-transparent bg-negative text-on-negative hover:opacity-90 active:opacity-80',
    'warning' => 'border-transparent bg-accent text-on-accent hover:opacity-90 active:opacity-80',
    'ghost' => 'border-transparent text-primary hover:bg-surface-raised active:bg-surface-raised',
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
