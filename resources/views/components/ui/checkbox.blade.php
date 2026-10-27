@props(['label' => null])

<label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-ink">
    <input type="checkbox" {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0 rounded-xs border-line-strong bg-surface accent-primary focus:ring-2 focus:ring-focus focus:outline-none disabled:cursor-not-allowed disabled:opacity-60']) }} />
    @if($label)
        <span>{{ $label }}</span>
    @endif
    {{ $slot }}
</label>
