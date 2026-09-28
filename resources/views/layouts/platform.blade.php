<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name').' — Platform' }}</title>

    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

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
    {{--
        Deliberately not the hub chrome: no rooms, no presence, no tenant
        meta tag — an operator session has nothing to do with a workspace.
    --}}
    <div class="flex h-full flex-col">
        <header class="z-sticky shrink-0 border-b border-line bg-surface">
            <div class="flex h-14 items-center justify-between gap-3 px-3 sm:px-4">
                <a href="{{ route('platform.tenants') }}" class="flex items-center gap-2 px-1 py-1">
                    <img src="{{ asset('images/logo-compact.png') }}" alt=""
                         width="1020" height="420" class="h-8 w-auto rounded bg-white p-0.5">
                    <span class="rounded-md bg-brand-tint px-2 py-0.5 text-xs font-semibold text-brand-text">
                        Platform
                    </span>
                </a>

                @auth('platform')
                    <div class="flex items-center gap-3">
                        <span class="hidden text-sm font-medium sm:block">
                            {{ auth('platform')->user()->name }}
                        </span>
                        <form method="POST" action="{{ route('platform.logout') }}">
                            @csrf
                            <button type="submit" class="btn-ghost text-sm">
                                <x-icon name="logout" class="h-4 w-4" />
                                <span class="hidden sm:inline">Sign out</span>
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </header>

        <main class="min-h-0 flex-1 overflow-y-auto">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
