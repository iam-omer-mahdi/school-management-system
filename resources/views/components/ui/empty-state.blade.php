@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-sm border border-line bg-surface p-8 text-center']) }}>
    <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xs bg-accent-tile text-on-accent-tile">
        <svg class="h-6 w-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
    </div>
    <h2 class="text-base font-semibold text-ink">{{ $title }}</h2>
    @if($description)
        <p class="mx-auto mt-1 max-w-sm text-sm text-muted">{{ $description }}</p>
    @endif
    @if(isset($actions))
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $actions }}</div>
    @endif
</div>
