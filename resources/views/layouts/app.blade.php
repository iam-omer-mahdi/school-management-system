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
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
@if(auth()->check() && ! auth()->user()->must_change_password)
    @php($drawerOnRight = app()->getLocale() === 'ar')
    <div class="flex min-h-screen">
        <aside class="sticky top-0 hidden h-screen w-[260px] shrink-0 flex-col overflow-y-auto border-e border-line bg-sidebar text-sidebar-fg lg:flex">
            <div class="flex items-center gap-3 border-b border-line px-5 py-5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xs bg-primary font-semibold text-on-primary">S</span>
                <span class="truncate text-base font-semibold tracking-tight">{{ __('common.app_name') }}</span>
            </div>
            <div class="flex-1 px-3 py-5">
                @include('layouts.nav-links')
            </div>
            <form method="POST" action="{{ route('logout') }}" class="border-t border-line px-3 py-4">
                @csrf
                <button type="submit" class="flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-xs px-3 py-2 text-sm font-medium text-sidebar-muted transition hover:bg-sidebar-hover hover:text-sidebar-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    <x-ui.icon name="logout" class="h-5 w-5 shrink-0" :flipRtl="true" />
                    {{ __('common.logout') }}
                </button>
            </form>
        </aside>

        <div id="mobile-sidebar" tabindex="-1" aria-labelledby="mobile-sidebar-label" class="fixed top-0 z-40 h-screen w-72 max-w-[85vw] overflow-y-auto bg-sidebar text-sidebar-fg transition-transform lg:hidden {{ $drawerOnRight ? 'right-0 translate-x-full border-s border-line' : 'left-0 -translate-x-full border-e border-line' }}" data-drawer-placement="{{ $drawerOnRight ? 'right' : 'left' }}">
            <h2 id="mobile-sidebar-label" class="sr-only">{{ __('common.main_nav') }}</h2>
            <div class="flex items-center justify-between border-b border-line px-5 py-4">
                <span class="truncate text-base font-semibold">{{ __('common.app_name') }}</span>
                <button type="button" data-drawer-hide="mobile-sidebar" aria-controls="mobile-sidebar" class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xs text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" aria-label="{{ __('common.close_menu') }}">
                    <x-ui.icon name="x" class="h-5 w-5" />
                </button>
            </div>
            <div class="px-3 py-4">
                @include('layouts.nav-links', ['drawerId' => 'mobile-sidebar'])
            </div>
            <form method="POST" action="{{ route('logout') }}" class="border-t border-line px-3 py-4">
                @csrf
                <button type="submit" class="flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-xs px-3 py-2 text-sm font-medium text-sidebar-muted transition hover:bg-sidebar-hover hover:text-sidebar-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    <x-ui.icon name="logout" class="h-5 w-5 shrink-0" :flipRtl="true" />
                    {{ __('common.logout') }}
                </button>
            </form>
        </div>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-30 flex h-14 items-center justify-between gap-2 border-b border-line bg-surface px-3 sm:px-6">
                <div class="flex min-w-0 items-center gap-1 sm:gap-2">
                    <button type="button" data-drawer-target="mobile-sidebar" data-drawer-show="mobile-sidebar" data-drawer-placement="{{ $drawerOnRight ? 'right' : 'left' }}" aria-controls="mobile-sidebar" class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xs text-muted hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden" aria-label="{{ __('common.open_menu') }}">
                        <x-ui.icon name="menu" class="h-5 w-5" />
                    </button>
                    <span class="hidden min-w-0 truncate text-sm text-muted min-[420px]:block">{{ auth()->user()->email }}</span>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @include('layouts.display-controls')
                </div>
            </header>
            <main class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                {{ $slot }}
            </main>
        </div>
    </div>
@else
    <main class="flex min-h-screen items-center justify-center bg-canvas p-4">
        <div class="w-full max-w-md">
            <div class="mb-3 flex items-center justify-end gap-2">
                @include('layouts.display-controls')
            </div>
            <div class="mb-4 text-center">
                <div class="text-2xl font-semibold tracking-tight text-ink">{{ __('common.app_name') }}</div>
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
                window.applyTheme(false);
            }
            if (window.initFlowbite) {
                window.initFlowbite();
            }
        });
    })();
</script>
</body>
</html>
