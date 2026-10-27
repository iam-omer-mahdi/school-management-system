<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? __('reports.title') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page {
            size: A4 {{ !empty($landscape) ? 'landscape' : 'portrait' }};
            margin: 12mm 12mm 16mm 12mm;
        }
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .print-actions,
            .no-print {
                display: none !important;
            }
            /* Repeat table headers and totals on every page, keep rows whole. */
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
            tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            /* Force light ink on paper even in dark mode. */
            .report-table,
            .report-table thead th,
            .report-table tbody td,
            .report-table tfoot td {
                color: #000 !important;
                background: #fff !important;
            }
            .report-table thead th,
            .report-table tfoot td {
                background: #f3f4f6 !important;
            }
            a {
                color: #000 !important;
                text-decoration: none !important;
            }
        }
        /* Repeats on every printed page (browser adds page numbers). */
        .print-page-foot {
            display: none;
        }
        @media print {
            .print-page-foot {
                display: block;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                font-size: 10px;
                color: #444;
                border-top: 1px solid #999;
                padding-top: 2mm;
                background: #fff;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-white font-sans text-black antialiased">
    <main class="mx-auto w-full {{ !empty($landscape) ? 'max-w-6xl' : 'max-w-4xl' }} p-4 sm:p-6">
        {{ $slot }}
        <div class="print-page-foot">
            <span>{{ $title ?? __('reports.title') }}</span>
            <span style="float: inline-end" dir="ltr">{{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </main>
</body>
</html>
