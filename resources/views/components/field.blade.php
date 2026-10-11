@props(['label' => null, 'error' => null])

<label class="block">
    @if($label)
        <span class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</span>
    @endif
    {{ $slot }}
    @if($error)
        <span class="mt-1 block text-sm text-red-600">{{ $error }}</span>
    @endif
</label>
