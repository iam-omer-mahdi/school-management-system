@props(['status' => null, 'color' => null])

@php
$palette = [
    'green' => 'bg-positive-surface text-positive',
    'blue' => 'bg-info-surface text-info',
    'red' => 'bg-negative-surface text-negative',
    'yellow' => 'bg-warning-surface text-warning',
    'orange' => 'bg-accent-tile text-on-accent-tile',
    'gray' => 'bg-surface-raised text-muted',
];

$statusColors = [
    'active' => 'green',
    'transferred' => 'blue',
    'expelled' => 'red',
    'left' => 'yellow',
    'suspended' => 'orange',
];

$resolved = $color ?? $statusColors[$status ?? ''] ?? 'gray';
$label = $status !== null ? __('enrollments.status_'.$status) : $slot;
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap '.($palette[$resolved] ?? $palette['gray'])]) }}>{{ $label }}</span>
