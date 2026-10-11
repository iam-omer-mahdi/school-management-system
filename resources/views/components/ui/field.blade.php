@props(['label' => null, 'error' => null, 'hint' => null, 'required' => false, 'for' => null])

<div {{ $attributes->merge(['class' => 'block']) }}>
    @if($label)
        <label @if($for) for="{{ $for }}" @endif class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
            {{ $label }}
            @if($required)
                <span class="ms-1 text-red-600 dark:text-red-500" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('common.required') }})</span>
            @endif
        </label>
    @endif
    {{ $slot }}
    @if($hint && ! $error)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
    @if($error)
        <p class="mt-2 text-sm text-red-600 dark:text-red-500" role="alert">{{ $error }}</p>
    @endif
</div>
