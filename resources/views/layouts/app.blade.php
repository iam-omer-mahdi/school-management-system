<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title ?? __('common.app_name') }}</title>
    <script>
        try {
            var storedTheme = localStorage.getItem('color-theme');
            if (storedTheme === 'dark' || (!storedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
@if(auth()->check())
    @php($drawerOnRight = app()->getLocale() === 'ar')
    <div class="flex min-h-screen">
        <aside class="hidden w-64 shrink-0 flex-col bg-gray-900 p-4 text-gray-100 lg:flex dark:border-e dark:border-gray-800 dark:bg-gray-950">
            <div class="flex items-center gap-2 px-2 py-2">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-600 font-semibold text-white">S</span>
                <span class="truncate text-base font-semibold">{{ __('common.app_name') }}</span>
            </div>
            <div class="mt-4 flex-1">
                @include('layouts.nav-links')
            </div>
            <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-700 pt-4 dark:border-gray-800">
                @csrf
                <button type="submit" class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-300 transition hover:bg-gray-700 hover:text-white focus:outline-none focus:ring-4 focus:ring-indigo-500">
                    <x-ui.icon name="logout" class="h-5 w-5 shrink-0" :flipRtl="true" />
                    {{ __('common.logout') }}
                </button>
            </form>
        </aside>

        <div id="mobile-sidebar" tabindex="-1" aria-labelledby="mobile-sidebar-label" class="fixed top-0 z-40 h-screen w-72 max-w-[85vw] overflow-y-auto bg-gray-900 p-4 text-gray-100 transition-transform lg:hidden dark:bg-gray-950 {{ $drawerOnRight ? 'right-0 translate-x-full border-s border-gray-700' : 'left-0 -translate-x-full border-e border-gray-700' }}" data-drawer-placement="{{ $drawerOnRight ? 'right' : 'left' }}">
            <h2 id="mobile-sidebar-label" class="sr-only">{{ __('common.main_nav') }}</h2>
            <div class="flex items-center justify-between px-2 py-2">
                <span class="truncate text-base font-semibold">{{ __('common.app_name') }}</span>
                <button type="button" data-drawer-hide="mobile-sidebar" aria-controls="mobile-sidebar" class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-gray-300 hover:bg-gray-700 hover:text-white focus:outline-none focus:ring-4 focus:ring-indigo-500" aria-label="{{ __('common.close_menu') }}">
                    <x-ui.icon name="x" class="h-5 w-5" />
                </button>
            </div>
            <div class="mt-4">
                @include('layouts.nav-links', ['drawerId' => 'mobile-sidebar'])
            </div>
            <form method="POST" action="{{ route('logout') }}" class="mt-4 border-t border-gray-700 pt-4">
                @csrf
                <button type="submit" class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-300 transition hover:bg-gray-700 hover:text-white focus:outline-none focus:ring-4 focus:ring-indigo-500">
                    <x-ui.icon name="logout" class="h-5 w-5 shrink-0" :flipRtl="true" />
                    {{ __('common.logout') }}
                </button>
            </form>
        </div>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-30 flex items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-2.5 sm:px-4 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex min-w-0 items-center gap-1 sm:gap-2">
                    <button type="button" data-drawer-target="mobile-sidebar" data-drawer-show="mobile-sidebar" data-drawer-placement="{{ $drawerOnRight ? 'right' : 'left' }}" aria-controls="mobile-sidebar" class="inline-flex shrink-0 cursor-pointer items-center rounded-lg p-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-indigo-300 lg:hidden dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:ring-indigo-800" aria-label="{{ __('common.open_menu') }}">
                        <x-ui.icon name="menu" class="h-5 w-5" />
                    </button>
                    <span class="hidden min-w-0 truncate text-sm text-gray-500 min-[420px]:block dark:text-gray-400">{{ auth()->user()->email }}</span>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @include('layouts.display-controls')
                </div>
            </header>
            <main class="mx-auto w-full max-w-5xl p-4 sm:p-6">
                {{ $slot }}
            </main>
        </div>
    </div>
@else
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="mb-3 flex items-center justify-end gap-2">
                @include('layouts.display-controls')
            </div>
            <div class="mb-4 text-center">
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('common.app_name') }}</div>
            </div>
            {{ $slot }}
        </div>
    </main>
@endif
<script data-navigate-once="true">
    (function () {
        function setTheme(mode) {
            try {
                localStorage.setItem('color-theme', mode);
            } catch (e) {}
            if (window.applyTheme) {
                window.applyTheme();
            } else {
                document.documentElement.classList.toggle('dark', mode === 'dark');
            }
        }

        function toggleTheme() {
            setTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
        }

        document.addEventListener('click', function (event) {
            if (!event.target || !event.target.closest) {
                return;
            }
            var explicit = event.target.closest('[data-theme-set]');
            if (explicit && explicit.getAttribute('data-theme-set')) {
                setTheme(explicit.getAttribute('data-theme-set'));
                return;
            }
            if (event.target.closest('[data-theme-toggle]')) {
                toggleTheme();
            }
        });

        document.addEventListener('livewire:navigated', function () {
            if (window.applyTheme) {
                window.applyTheme();
            }
            if (window.initFlowbite) {
                window.initFlowbite();
            }
        });
    })();
</script>
</body>
</html>
