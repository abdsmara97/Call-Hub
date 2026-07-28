<div class="flex h-full min-h-0" x-data="{ sidebarOpen: false }">
    {{-- Sidebar. Off-canvas below `md`, permanent above it. --}}
    <div class="fixed inset-y-0 left-0 z-modal w-72 shrink-0 transform border-r border-line bg-surface-sunken
                transition-transform duration-normal ease-standard
                md:relative md:z-auto md:translate-x-0"
         :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
         x-cloak
         @keydown.escape.window="sidebarOpen = false">
        <livewire:hub.sidebar :active-room-id="$roomId" />
    </div>

    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
         class="fixed inset-0 z-dropdown bg-black/40 md:hidden" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-1 flex-col">
        <button type="button" @click="sidebarOpen = true"
                class="btn-ghost m-2 self-start md:hidden" aria-label="Open conversation list">
            <x-icon name="menu" class="h-5 w-5" />
        </button>

        @if ($roomId)
            <livewire:hub.conversation :room-id="$roomId" :key="'conversation-'.$roomId" />
        @else
            <div class="flex flex-1 items-center justify-center p-8">
                <div class="max-w-sm text-center">
                    <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-tint text-brand-text">
                        <x-icon name="hash" class="h-6 w-6" />
                    </span>
                    <h2 class="text-lg font-semibold">No conversation open</h2>
                    <p class="mt-1 text-sm text-content-muted">
                        Pick a room from the list, or start a direct message from the
                        <a href="{{ route('directory') }}" class="font-medium text-brand-text hover:underline">directory</a>.
                    </p>
                </div>
            </div>
        @endif
    </div>
</div>
