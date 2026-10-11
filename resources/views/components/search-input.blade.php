@props(['placeholder' => null])

<input
    {{ $attributes->merge(['type' => 'search', 'placeholder' => $placeholder ?? __('common.search'), 'class' => 'w-full rounded border-gray-300 px-3 py-2']) }}
/>
