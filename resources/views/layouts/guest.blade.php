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
        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand text-brand-on">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2c3.6 0 6.5 2.4 6.5 5.4 0 .6-.5 1.1-1.1 1.1H6.6c-.6 0-1.1-.5-1.1-1.1C5.5 4.4 8.4 2 12 2Z"/>
                    <path d="M6.8 10h10.4c.5 0 .8.4.8.9 0 4.3-2.7 8-6 11.1-3.3-3.1-6-6.8-6-11.1 0-.5.3-.9.8-.9Z" opacity=".75"/>
                </svg>
            </span>
            <div>
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
