@props(['title' => null, 'description' => null])

<section {{ $attributes->merge(['class' => 'rounded-sm border border-line bg-surface p-6 sm:p-8']) }}>
    @if($title || $description || isset($header))
        <div class="mb-4">
            @if(isset($header))
                {{ $header }}
            @else
                @if($title)
                    <h2 class="text-base font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if($description)
                    <p class="mt-1 text-sm text-muted">{{ $description }}</p>
                @endif
            @endif
        </div>
    @endif
    {{ $slot }}
    @if(isset($footer))
        <div class="mt-4 border-t border-line pt-4">{{ $footer }}</div>
    @endif
</section>
