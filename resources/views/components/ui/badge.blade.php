@props(['status' => null, 'color' => null])

@php
$palette = [
    'green' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    'red' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    'orange' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    'gray' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
];

$statusColors = [
    'active' => 'green',
    'transferred' => 'blue',
    'expelled' => 'red',
    'left' => 'yellow',
    'suspended' => 'orange',
];

$resolved = $color ?? $statusColors[$status ?? ''] ?? 'gray';
$label = $status !== null ? __('students.status_'.$status) : $slot;
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap '.($palette[$resolved] ?? $palette['gray'])]) }}>{{ $label }}</span>
