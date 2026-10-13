<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? __('payments.receipt') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            body {
                background: #fff;
            }

            .print-actions {
                display: none;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-white font-sans text-gray-900 antialiased">
    <main class="mx-auto w-full max-w-2xl p-4 sm:p-6">
        {{ $slot }}
    </main>
</body>
</html>
