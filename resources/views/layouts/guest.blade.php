<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <script>
        (function () {
            var stored = localStorage.getItem('oaktree-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-canvas text-content">
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-10">
        <div class="mb-6 flex flex-col items-center gap-3">
            <img src="{{ asset('images/logo.png') }}" alt=""
                 width="187" height="53"
                 class="h-12 w-auto rounded-lg bg-white p-1.5">
            <div class="text-center">
                <h1 class="text-lg font-semibold tracking-tight">Oak Tree Venture Hub</h1>
                <p class="text-xs text-content-muted">Internal communications</p>
            </div>
        </div>

        <div class="w-full max-w-sm panel p-6">
            {{ $slot }}
        </div>

        <p class="mt-6 max-w-sm text-center text-xs text-content-subtle">
            Accounts are issued by your administrator. If you cannot sign in, contact IT support.
        </p>
    </div>

    @livewireScripts
</body>
</html>
