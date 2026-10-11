@props(['status'])

@php
    $map = [
        'active' => 'bg-green-100 text-green-800',
        'transferred' => 'bg-blue-100 text-blue-800',
        'expelled' => 'bg-red-100 text-red-800',
        'left' => 'bg-yellow-100 text-yellow-800',
        'suspended' => 'bg-orange-100 text-orange-800',
    ];
    $classes = $map[$status] ?? 'bg-gray-100 text-gray-800';
    $label = __('students.status_'.$status);
@endphp

<span {{ $attributes->merge(['class' => 'inline-block rounded px-2 py-0.5 text-xs font-medium '.$classes]) }}>{{ $label }}</span>
