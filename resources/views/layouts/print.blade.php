<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? __('common.app_name') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page {
            size: A4 {{ !empty($landscape) ? 'landscape' : 'portrait' }};
            margin: 12mm 15mm 15mm 15mm;
            @bottom-right {
                content: counter(page) " / " counter(pages);
            }
        }
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-actions,
            aside,
            header,
            nav,
            .no-print {
                display: none !important;
            }
            table {
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
            .page-break {
                page-break-before: always;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-white font-sans text-gray-900 antialiased">
    <main class="mx-auto w-full {{ !empty($landscape) ? 'max-w-6xl' : 'max-w-4xl' }} p-4 sm:p-6">
        {{ $slot }}
    </main>
</body>
</html>
