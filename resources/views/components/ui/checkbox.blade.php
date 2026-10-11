@props(['label' => null])

<label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-300">
    <input type="checkbox" {{ $attributes->merge(['class' => 'h-4 w-4 shrink-0 rounded-sm border-gray-300 bg-gray-100 text-indigo-600 shadow-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700 dark:ring-offset-gray-800 dark:focus:ring-indigo-600']) }} />
    @if($label)
        <span>{{ $label }}</span>
    @endif
    {{ $slot }}
</label>
