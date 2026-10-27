@props(['loadingLabel' => null])

<div {{ $attributes->merge(['class' => 'relative overflow-x-auto rounded-sm border border-line bg-surface']) }}>
    <table class="flowbite-table w-full text-sm">
        @if(isset($head))
            <thead><tr>{{ $head }}</tr></thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
    <div wire:loading.delay role="status" class="border-t border-line p-3 text-center text-sm text-muted">
        {{ $loadingLabel ?? __('common.loading') }}
    </div>
</div>
