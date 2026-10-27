@props(['title' => null, 'close' => null])

<div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4 focus:outline-none" role="dialog" aria-modal="true" tabindex="-1" @if($title) aria-label="{{ $title }}" @endif>
    <div class="absolute inset-0 bg-overlay" @if($close) data-modal-close wire:click="{{ $close }}" @endif></div>
    <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-sm border border-line bg-surface">
        @if($title || isset($header))
            <div class="flex items-start justify-between border-b border-line p-4 sm:p-5">
                @if(isset($header))
                    {{ $header }}
                @else
                    <h2 class="text-base font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if($close)
                    <button type="button" wire:click="{{ $close }}" data-modal-close class="ms-auto inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xs text-muted hover:bg-surface-raised hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" aria-label="{{ __('common.dismiss') }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
                        <span class="sr-only">{{ __('common.dismiss') }}</span>
                    </button>
                @endif
            </div>
        @endif
        <div class="space-y-4 p-4 text-sm text-muted sm:p-5">{{ $slot }}</div>
        @if(isset($footer))
            <div class="flex flex-col-reverse gap-2 border-t border-line p-4 sm:flex-row sm:justify-end sm:p-5">{{ $footer }}</div>
        @endif
    </div>
</div>
