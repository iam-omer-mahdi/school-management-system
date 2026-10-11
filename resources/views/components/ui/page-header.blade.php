@props(['title', 'description' => null, 'backUrl' => null, 'backLabel' => null])

<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        @if($backUrl)
            <a href="{{ $backUrl }}" wire:navigate class="mb-1 inline-flex items-center gap-1 text-sm font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">
                <svg class="h-4 w-4 shrink-0 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                {{ $backLabel ?? __('common.back') }}
            </a>
        @endif
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $title }}</h1>
        @if($description)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
    @if(isset($actions))
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
