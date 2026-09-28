{{--
    The minimised huddle.

    Docked bottom-LEFT, opposite the call panel's bottom-right. That is the
    geometric half of the coexistence rule: both can be on screen at once and
    they never occupy the same coordinates, so no stacking arithmetic is needed
    beyond the z-huddle token sitting below z-modal.

    This is also what an incoming call collapses the huddle into — see
    huddle-arbiter.js.
--}}

<div x-show="showsPill" x-cloak
     class="fixed bottom-4 left-4 z-huddle flex items-center gap-2 rounded-full border border-brand-border
            bg-surface px-3 py-2 shadow-lg"
     role="region" aria-label="Minimised huddle">

    <span class="relative flex h-2 w-2 shrink-0" aria-hidden="true">
        <span class="absolute inline-flex h-full w-full rounded-full bg-brand opacity-60 motion-safe:animate-ping"></span>
        <span class="relative inline-flex h-2 w-2 rounded-full bg-brand"></span>
    </span>

    <span class="text-xs font-medium text-content" x-text="statusLabel"></span>

    <button type="button" @click="toggleMic()" class="btn btn-ghost !px-1.5 !py-1"
            :aria-label="huddle.local.micOn ? 'Mute your microphone' : 'Unmute your microphone'">
        <template x-if="huddle.local.micOn">
            <x-icon name="microphone" class="h-3.5 w-3.5" />
        </template>
        <template x-if="! huddle.local.micOn">
            <x-icon name="microphone-slash" class="h-3.5 w-3.5 text-alert" />
        </template>
    </button>

    <button type="button" @click="setSurface('dock')" class="btn btn-ghost !px-1.5 !py-1"
            aria-label="Show the huddle">
        <x-icon name="users" class="h-3.5 w-3.5" />
    </button>

    <button type="button" @click="leave()" class="btn btn-ghost !px-1.5 !py-1" aria-label="Leave the huddle">
        <x-icon name="phone-hangup" class="h-3.5 w-3.5 text-alert" />
    </button>
</div>
