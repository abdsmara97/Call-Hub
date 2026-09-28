{{--
    Incoming call.

    An alertdialog rather than a banner: a ringing phone is a question, and it
    wants an answer before anything else happens. Focus is trapped on Answer so
    a keyboard user does not have to hunt for it, and Escape declines — the
    thing people reach for when they cannot take a call.

    Note there is no ringtone markup here. Sound belongs to ringtone.js, which
    the state machine drives, because a call that is quiet under Do Not Disturb
    still shows exactly this overlay.
--}}
<div x-show="isIncoming" x-cloak
     class="fixed inset-0 z-modal flex items-center justify-center bg-black/60 p-4"
     role="alertdialog" aria-modal="true"
     aria-labelledby="incoming-call-title"
     x-effect="isIncoming && $nextTick(() => $refs.answerButton?.focus())"
     @keydown.tab.prevent="$refs.answerButton?.focus()"
     @keydown.escape.window="isIncoming && decline()">

    <div class="w-full max-w-sm overflow-hidden rounded-xl border border-brand-border bg-surface shadow-lg">
        <div class="flex flex-col items-center gap-3 px-6 pt-7">
            <template x-if="peerAvatar">
                <img :src="peerAvatar" alt=""
                     class="h-20 w-20 rounded-full object-cover ring-2 ring-brand-border" />
            </template>

            <template x-if="! peerAvatar">
                <div class="flex h-20 w-20 items-center justify-center rounded-full bg-brand-tint">
                    <x-icon name="phone" class="h-8 w-8 text-brand" />
                </div>
            </template>

            <div class="text-center">
                <h2 id="incoming-call-title" class="text-lg font-semibold text-content">
                    <span x-text="peerName"></span> is calling
                </h2>
                <p class="mt-0.5 text-xs text-content-muted">
                    <span x-show="! call.video">Audio call</span>
                    <span x-show="call.video">Video call</span>
                </p>
            </div>
        </div>

        <div class="flex flex-col gap-2 px-6 pb-6 pt-5">
            <button type="button" x-ref="answerButton" @click="accept()"
                    class="btn-primary w-full justify-center">
                <x-icon name="phone" class="h-4 w-4" />
                Answer
            </button>

            <button type="button" @click="accept({ video: true })"
                    class="btn-secondary w-full justify-center">
                <x-icon name="video" class="h-4 w-4" />
                Answer with video
            </button>

            <button type="button" @click="decline()"
                    class="btn-secondary w-full justify-center !text-emergency">
                <x-icon name="phone-hangup" class="h-4 w-4" />
                Decline
            </button>
        </div>
    </div>
</div>
