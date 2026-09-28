<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

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
        @livewire('hub.message-notifier')
        {{-- Same reasoning as the emergency surface: a call has to reach you
             wherever you are, including a room that is not the one it came from. --}}
        @livewire('hub.call-panel')

        {{--
            The huddle dock.

            @persist, not merely wire:ignore. wire:navigate swaps the whole body
            when you click another room in the sidebar, and wire:ignore protects
            against a Livewire re-render but not against navigation. A huddle you
            cannot walk away from while it runs defeats its own premise — the
            entire point is reading the conversation, and other rooms, while you
            are in one. @persist keeps this exact subtree, and with it the
            LiveKit Room living in the Alpine closure.

            Sits at z-huddle, below the call panel's z-modal: an incoming call
            must always be able to cover a huddle, never the other way round.
        --}}
        @persist('huddle-dock')
            @include('partials.huddle-dock', [
                'huddleMe' => [
                    'id' => auth()->id(),
                    'name' => auth()->user()?->name,
                    'avatar_url' => auth()->user()?->avatar_url,
                ],
                'huddleConfig' => [],
            ])
        @endpersist
    @endauth

    {{-- Screen readers get every emergency announced here. --}}
    <div id="a11y-announcer" class="sr-only-live" role="status" aria-live="assertive" aria-atomic="true"></div>

    {{--
        Ordinary messages announce here instead. Deliberately a separate,
        polite region: routing routine chat through the assertive one above
        would interrupt a screen-reader user mid-sentence for every message.
    --}}
    <div id="a11y-announcer-polite" class="sr-only-live" role="status" aria-live="polite" aria-atomic="true"></div>

    @livewireScripts
</body>
</html>
