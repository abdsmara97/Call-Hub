@php
    $user = auth()->user();
    $navLink = fn (bool $active) => $active ? 'nav-item-active' : 'nav-item';
@endphp

<header class="z-sticky shrink-0 border-b border-line bg-surface">
    <div class="flex h-14 items-center gap-3 px-3 sm:px-4">
        {{-- Wordmark. The acorn glyph doubles as the app's favicon-scale identity. --}}
        <a href="{{ route('hub') }}" class="flex items-center gap-2 rounded-sm px-1 py-1">
            <span class="flex h-7 w-7 items-center justify-center rounded-md bg-brand text-brand-on">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2c3.6 0 6.5 2.4 6.5 5.4 0 .6-.5 1.1-1.1 1.1H6.6c-.6 0-1.1-.5-1.1-1.1C5.5 4.4 8.4 2 12 2Z"/>
                    <path d="M6.8 10h10.4c.5 0 .8.4.8.9 0 4.3-2.7 8-6 11.1-3.3-3.1-6-6.8-6-11.1 0-.5.3-.9.8-.9Z" opacity=".75"/>
                </svg>
            </span>
            <span class="hidden text-sm font-semibold tracking-tight sm:block">Oak Tree Hub</span>
        </a>

        @auth
            <nav class="flex items-center gap-1" aria-label="Primary">
                <a href="{{ route('hub') }}" class="{{ $navLink(request()->routeIs('hub') || request()->routeIs('rooms.*')) }}">
                    <x-icon name="hash" class="h-4 w-4" />
                    <span class="hidden sm:inline">Messages</span>
                </a>

                <a href="{{ route('directory') }}" class="{{ $navLink(request()->routeIs('directory')) }}">
                    <x-icon name="users" class="h-4 w-4" />
                    <span class="hidden sm:inline">Directory</span>
                </a>

                <a href="{{ route('saved') }}" class="{{ $navLink(request()->routeIs('saved')) }}">
                    <x-icon name="bookmark" class="h-4 w-4" />
                    <span class="hidden sm:inline">Saved</span>
                </a>

                @can('viewLog', \App\Models\Emergency::class)
                    <a href="{{ route('admin.emergency-log') }}" class="{{ $navLink(request()->routeIs('admin.emergency-log')) }}">
                        <x-icon name="alert" class="h-4 w-4" />
                        <span class="hidden sm:inline">Emergency log</span>
                    </a>
                @endcan

                @can('manage', \App\Models\User::class)
                    <a href="{{ route('admin.users') }}" class="{{ $navLink(request()->routeIs('admin.users') || request()->routeIs('admin.import') || request()->routeIs('admin.settings')) }}">
                        <x-icon name="cog" class="h-4 w-4" />
                        <span class="hidden sm:inline">Admin</span>
                    </a>
                @endcan
            </nav>

            <div class="ml-auto flex items-center gap-1">
                @can('broadcast', \App\Models\Emergency::class)
                    <a href="{{ route('admin.broadcast') }}" class="btn-emergency !py-1.5 text-xs">
                        <x-icon name="megaphone" class="h-4 w-4" />
                        <span class="hidden sm:inline">Broadcast</span>
                    </a>
                @endcan

                {{-- Theme toggle. Persisted per browser, not per account. --}}
                <button type="button"
                        x-data="{
                            dark: document.documentElement.classList.contains('dark'),
                            toggle() {
                                this.dark = !this.dark;
                                document.documentElement.classList.toggle('dark', this.dark);
                                localStorage.setItem('oaktree-theme', this.dark ? 'dark' : 'light');
                            },
                        }"
                        @click="toggle()"
                        class="btn-ghost !px-2"
                        :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'">
                    <x-icon name="sun" class="hidden h-4 w-4 dark:block" />
                    <x-icon name="moon" class="h-4 w-4 dark:hidden" />
                </button>

                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open" @keydown.escape.window="open = false"
                            class="flex items-center gap-2 rounded-md px-1.5 py-1 hover:bg-surface-hover"
                            :aria-expanded="open" aria-haspopup="menu">
                        <x-avatar :user="$user" size="sm" :presence="true" />
                        <span class="hidden text-sm font-medium md:block">{{ $user->name }}</span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-content-subtle" />
                    </button>

                    <div x-show="open" x-cloak @click.outside="open = false"
                         x-transition.opacity.duration.140ms
                         class="absolute right-0 z-dropdown mt-1 w-56 overflow-hidden rounded-lg border
                                border-line bg-surface-raised shadow-md" role="menu">
                        <div class="border-b border-line px-3 py-2">
                            <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                            <p class="truncate text-xs text-content-muted">{{ $user->job_title }}</p>
                            <p class="truncate text-2xs text-content-subtle">
                                {{ $user->administration?->name }} · {{ $user->company?->name }}
                            </p>
                        </div>

                        <a href="{{ route('profile') }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-surface-hover" role="menuitem">
                            <x-icon name="pencil" class="h-4 w-4 text-content-subtle" />
                            Profile &amp; notifications
                        </a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-hover" role="menuitem">
                                <x-icon name="logout" class="h-4 w-4 text-content-subtle" />
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endauth
    </div>
</header>
