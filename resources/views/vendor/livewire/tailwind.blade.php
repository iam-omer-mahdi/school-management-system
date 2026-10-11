@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollAttr = ($scrollTo !== false)
    ? 'onclick="(document.querySelector(\''.$scrollTo.'\') || document.body).scrollIntoView()"'
    : '';

$linkBase = 'flex h-10 items-center justify-center border border-gray-300 px-4 text-sm font-medium transition focus:z-10 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:border-gray-700 dark:focus:ring-indigo-800';
$linkIdle = 'bg-white text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white';
$linkDisabled = 'cursor-default bg-white text-gray-300 dark:bg-gray-800 dark:text-gray-600';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('pagination.nav') }}" class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
            <p class="text-sm text-gray-700 dark:text-gray-400">
                <span>{{ __('pagination.showing') }}</span>
                <span class="font-medium">{{ $paginator->firstItem() }}</span>
                <span>{{ __('pagination.to') }}</span>
                <span class="font-medium">{{ $paginator->lastItem() }}</span>
                <span>{{ __('pagination.of') }}</span>
                <span class="font-medium">{{ $paginator->total() }}</span>
                <span>{{ __('pagination.results') }}</span>
            </p>

            <ul class="inline-flex items-center -space-x-px text-sm rtl:space-x-reverse">
                <li>
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}" class="{{ $linkBase }} {{ $linkDisabled }} rounded-s-lg" aria-hidden="true">
                            <svg class="h-5 w-5 rtl:rotate-180" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" {!! $scrollAttr !!} wire:loading.attr="disabled" class="{{ $linkBase }} {{ $linkIdle }} rounded-s-lg disabled:cursor-not-allowed disabled:opacity-60" aria-label="{{ __('pagination.previous') }}">
                            <svg class="h-5 w-5 rtl:rotate-180" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    @endif
                </li>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li aria-disabled="true">
                            <span class="{{ $linkBase }} -ms-px bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $element }}</span>
                        </li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <li wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page" class="{{ $linkBase }} -ms-px border-indigo-500 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-500 dark:bg-indigo-900/40 dark:text-indigo-300">{{ $page }}</span>
                                @else
                                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" {!! $scrollAttr !!} class="{{ $linkBase }} {{ $linkIdle }} -ms-px" aria-label="{{ __('pagination.goto', ['page' => $page]) }}">
                                        {{ $page }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    @endif
                @endforeach

                <li>
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" {!! $scrollAttr !!} wire:loading.attr="disabled" class="{{ $linkBase }} {{ $linkIdle }} -ms-px rounded-e-lg disabled:cursor-not-allowed disabled:opacity-60" aria-label="{{ __('pagination.next') }}">
                            <svg class="h-5 w-5 rtl:rotate-180" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}" class="{{ $linkBase }} {{ $linkDisabled }} -ms-px rounded-e-lg" aria-hidden="true">
                            <svg class="h-5 w-5 rtl:rotate-180" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    @endif
                </li>
            </ul>
        </nav>
    @endif
</div>
