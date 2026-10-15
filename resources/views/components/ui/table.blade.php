@props(['loadingLabel' => null])

<div {{ $attributes->merge(['class' => 'relative overflow-x-auto rounded-lg border border-gray-700 bg-white dark:border-gray-700 dark:bg-gray-800']) }}>
    <table class="flowbite-table w-full text-sm">
        @if(isset($head))
            <thead><tr>{{ $head }}</tr></thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
    <div wire:loading.delay class="border-t border-gray-200 p-3 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ $loadingLabel ?? __('common.loading') }}
    </div>
</div>
