@props(['title' => null, 'description' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800']) }}>
    @if($title || $description || isset($header))
        <div class="mb-4">
            @if(isset($header))
                {{ $header }}
            @else
                @if($title)
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>
                @endif
                @if($description)
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
                @endif
            @endif
        </div>
    @endif
    {{ $slot }}
    @if(isset($footer))
        <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">{{ $footer }}</div>
    @endif
</section>
