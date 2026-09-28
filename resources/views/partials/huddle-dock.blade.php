{{--
    The huddle surface.

    Plain blade and Alpine, with no Livewire component behind it. Nothing here
    needs a server render — the roster arrives over the room's private channel
    and the token comes from a POST — and keeping Livewire out sidesteps the
    known fragility of Livewire components inside @persist.

    Three sizes, never a takeover. An expanded huddle is a tall right-hand panel
    with no scrim, because a scrim is what makes something a takeover and the
    whole premise is that people keep reading the conversation. Below `lg` the
    expanded panel does cover the conversation — on a narrow screen there was
    never room for both, and the pill is one tap away.
--}}

<div wire:ignore x-data="huddleSession(@js($huddleMe), @js($huddleConfig ?? []))">

    {{-- One sink for every remote audio track, deliberately outside every
         toggled branch. A tile scrolling past the visible cap must not take
         somebody's voice with it. --}}
    <div x-huddle-audio class="hidden" aria-hidden="true"></div>

    @include('livewire.hub.partials.huddle-pill')

    <div x-show="showsPanel" x-cloak
         class="fixed z-huddle flex flex-col overflow-hidden rounded-xl border border-brand-border
                bg-surface shadow-lg"
         :class="huddle.surface === 'expanded'
            ? 'inset-y-4 right-4 w-[min(52rem,calc(100vw-2rem))] max-lg:inset-x-4 max-lg:w-auto'
            : 'bottom-4 right-4 w-[min(26rem,calc(100vw-2rem))] h-[22rem]'"
         role="region" aria-label="Huddle">

        <header class="flex shrink-0 items-center gap-3 border-b border-brand-border px-4 py-3">
            <span class="relative flex h-2 w-2 shrink-0" aria-hidden="true">
                <span class="absolute inline-flex h-full w-full rounded-full bg-brand opacity-60
                             motion-safe:animate-ping" x-show="isLive"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-brand"></span>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-content">Huddle</p>
                <p class="truncate text-xs text-content-muted" x-text="isEnded ? huddle.notice : rosterText"></p>
            </div>

            <button type="button" @click="setSurface('pill')" x-show="! isEnded"
                    class="btn btn-ghost !px-1.5 !py-1" aria-label="Minimise the huddle">
                <x-icon name="chevron-down" class="h-4 w-4" />
            </button>
        </header>

        {{-- iOS refuses autoplay without a gesture, so a huddle joined from a
             background tab connects silently. This is the way back. --}}
        <button type="button" @click="startAudio()" x-show="huddle.local.audioBlocked" x-cloak
                class="shrink-0 border-b border-brand-border bg-brand-tint px-4 py-2 text-left text-xs
                       font-medium text-brand-text">
            Sound is blocked by your browser. Tap to turn it on.
        </button>

        <p x-show="huddle.notice && ! isEnded" x-cloak x-text="huddle.notice"
           class="shrink-0 border-b border-brand-border bg-brand-tint px-4 py-2 text-xs text-brand-text"></p>

        <template x-if="! isEnded">
            <div class="flex min-h-0 flex-1 flex-col">
                @include('livewire.hub.partials.huddle-grid')
                @include('livewire.hub.partials.huddle-controls')
            </div>
        </template>

        <template x-if="isEnded">
            <div class="flex flex-1 items-center justify-center gap-2 p-4">
                <button type="button" @click="join({ roomId: huddle.roomId })" class="btn btn-primary !text-xs">
                    <x-icon name="users" class="h-4 w-4" />
                    Rejoin
                </button>
                <button type="button" @click="dismiss()" class="btn btn-ghost !text-xs">Dismiss</button>
            </div>
        </template>
    </div>
</div>
