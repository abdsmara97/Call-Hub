<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
    <title>{{ $title ?? config('app.name') }}</title>

    {{--
        Theme is resolved before first paint so a dark-mode user never sees a
        white flash. Runs inline, ahead of the stylesheet, on purpose.
    --}}
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
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-3 focus:z-emergency
              focus:rounded-md focus:bg-brand focus:px-3 focus:py-2 focus:text-sm focus:font-semibold
              focus:text-brand-on">
        Skip to main content
    </a>

    <div class="flex h-full flex-col">
        @include('partials.top-nav')

        <main id="main-content" class="min-h-0 flex-1">
            {{ $slot }}
        </main>
    </div>

    {{--
        Global emergency surface. Lives outside the page content so it survives
        navigation and fires wherever the user happens to be in the app.
    --}}
    @auth
        @livewire('hub.emergency-alerts')
    @endauth

    {{-- Screen readers get every emergency announced here. --}}
    <div id="a11y-announcer" class="sr-only-live" role="status" aria-live="assertive" aria-atomic="true"></div>

    @livewireScripts
</body>
</html>
