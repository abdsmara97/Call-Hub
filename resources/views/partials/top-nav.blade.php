@php
    $user = auth()->user();
    $navLink = fn (bool $active) => $active ? 'nav-item-active' : 'nav-item';
@endphp

<header class="z-sticky shrink-0 border-b border-line bg-surface">
    <div class="flex h-14 items-center gap-3 px-3 sm:px-4">
        {{--
            The Oak Tree Ventures wordmark. It carries the company name itself, so
            there is no text beside it — the link's accessible name comes from
            aria-label instead, which also keeps it stable at every breakpoint.

            The asset has a white background (it is a JPEG, so no alpha), hence
            the white chip: invisible against the light surface, and a deliberate
            badge rather than a stray white rectangle in dark mode.
        --}}
        <a href="{{ route('hub') }}"
           class="flex shrink-0 items-center rounded-sm px-1 py-1"
           aria-label="Oak Tree Venture Hub — go to messages">
            <img src="{{ asset('images/logo.png') }}" alt=""
                 width="187" height="53"
                 class="h-8 w-auto rounded bg-white p-0.5">
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

                @php
                    // A plain partial, not a Livewire component, so this count
                    // refreshes on navigation rather than live. The desktop
                    // notification is what carries urgency.
                    $unreadMentions = \App\Models\MessageMention::query()
                        ->where('user_id', $user->id)
                        ->unread()
                        ->count();
                @endphp

                <a href="{{ route('mentions') }}" class="{{ $navLink(request()->routeIs('mentions')) }}">
                    <x-icon name="at-symbol" class="h-4 w-4" />
                    <span class="hidden sm:inline">Mentions</span>
                    @if ($unreadMentions > 0)
                        <span class="badge-brand shrink-0 tabular-nums"
                              aria-label="{{ $unreadMentions }} unread {{ Str::plural('mention', $unreadMentions) }}">
                            <span aria-hidden="true">{{ $unreadMentions > 99 ? '99+' : $unreadMentions }}</span>
                        </span>
                    @endif
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
