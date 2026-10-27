@props(['title', 'description' => null, 'backUrl' => null, 'backLabel' => null])

<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        @if($backUrl)
            <a href="{{ $backUrl }}" wire:navigate class="mb-1 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                <svg class="h-4 w-4 shrink-0 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                {{ $backLabel ?? __('common.back') }}
            </a>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>
        @if($description)
            <p class="mt-1 text-sm text-muted">{{ $description }}</p>
        @endif
    </div>
    @if(isset($actions))
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
