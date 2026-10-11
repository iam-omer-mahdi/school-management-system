<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? __('common.app_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 text-gray-900 antialiased">
@if(auth()->check())
    <div class="flex min-h-screen">
        @persist('sidebar')
        <aside class="w-60 shrink-0 bg-gray-900 text-gray-100 p-4 space-y-2">
            <div class="text-lg font-semibold px-2 py-1">{{ __('common.app_name') }}</div>
            <nav class="flex flex-col gap-1">
                <a href="{{ route('dashboard') }}" wire:navigate.hover class="rounded px-3 py-2 hover:bg-gray-700">{{ __('common.dashboard') }}</a>
                <a href="{{ route('students.index') }}" wire:navigate.hover class="rounded px-3 py-2 hover:bg-gray-700">{{ __('common.students') }}</a>
                <a href="{{ route('academic-years.index') }}" wire:navigate.hover class="rounded px-3 py-2 hover:bg-gray-700">{{ __('common.academic_years') }}</a>
                <a href="{{ route('schools.index') }}" wire:navigate.hover class="rounded px-3 py-2 hover:bg-gray-700">{{ __('common.schools') }}</a>
                <a href="{{ route('settings') }}" wire:navigate.hover class="rounded px-3 py-2 hover:bg-gray-700">{{ __('common.settings') }}</a>
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="pt-4">
                @csrf
                <button type="submit" class="w-full rounded bg-gray-700 px-3 py-2 hover:bg-gray-600">{{ __('common.logout') }}</button>
            </form>
        </aside>
        @endpersist
        <div class="flex-1 min-w-0">
            @persist('topbar')
            <header class="flex items-center justify-between bg-white border-b px-4 py-2">
                <div class="text-sm text-gray-500">{{ auth()->user()->email }}</div>
                <form method="POST" action="{{ route('locale.switch') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button type="submit" class="rounded border px-3 py-1 text-sm hover:bg-gray-100">
                        {{ app()->getLocale() === 'ar' ? __('common.english') : __('common.arabic') }}
                    </button>
                </form>
            </header>
            @endpersist
            <main class="p-4">
                {{ $slot }}
            </main>
        </div>
    </div>
@else
    <main class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            @persist('locale-guest')
            <form method="POST" action="{{ route('locale.switch') }}" class="mb-3 text-end">
                @csrf
                <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                <button type="submit" class="rounded border bg-white px-3 py-1 text-sm hover:bg-gray-100">
                    {{ app()->getLocale() === 'ar' ? __('common.english') : __('common.arabic') }}
                </button>
            </form>
            @endpersist
            {{ $slot }}
        </div>
    </main>
@endif
</body>
</html>
