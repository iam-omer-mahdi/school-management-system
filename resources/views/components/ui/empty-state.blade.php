@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800']) }}>
    <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">
        <svg class="h-6 w-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
    </div>
    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>
    @if($description)
        <p class="mx-auto mt-1 max-w-sm text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
    @endif
    @if(isset($actions))
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $actions }}</div>
    @endif
</div>
