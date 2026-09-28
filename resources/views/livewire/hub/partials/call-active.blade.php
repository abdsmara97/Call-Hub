{{--
    The call itself: dialling, connecting, in progress, and the closing message.

    A docked panel rather than a takeover. A one-to-one audio call is something
    people do *while* working — reading the room they are talking about is the
    normal case, so blocking the interface would be actively unhelpful. Video
    grows the panel; it still does not swallow the page.
--}}

{{--
    Always in the DOM, never inside a toggled branch: the element must exist
    before the remote track arrives, and playback is started from the Accept
    tap, which is the user gesture iOS requires. Get this wrong and the call
    connects with silent one-way audio.
--}}
<audio x-ref="remoteAudio" autoplay playsinline class="hidden"></audio>

<div x-show="isOutgoing || isConnecting || isActive || isEnded" x-cloak
     class="fixed bottom-4 right-4 z-modal w-[min(22rem,calc(100vw-2rem))]
            overflow-hidden rounded-xl border border-brand-border bg-surface shadow-lg"
     role="region" :aria-label="`Call with ${peerName}`">

    <header class="flex items-center gap-3 border-b border-brand-border px-4 py-3">
        <template x-if="peerAvatar">
            <img :src="peerAvatar" alt="" class="h-9 w-9 rounded-full object-cover" />
        </template>

        <template x-if="! peerAvatar">
            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-tint">
                <x-icon name="phone" class="h-4 w-4 text-brand" />
            </div>
        </template>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-content" x-text="peerName"></p>
            <p class="text-xs text-content-muted" x-text="isEnded ? endedMessage : statusLabel"></p>
        </div>
    </header>

    {{-- Video, only once someone has actually turned a camera on. --}}
    <div x-show="(isActive || isConnecting) && (call.video || remoteVideoOn)" x-cloak
         class="relative bg-black">
        <video x-ref="remoteVideo" autoplay playsinline
               class="aspect-video w-full object-cover"></video>

        <video x-ref="localVideo" autoplay playsinline muted
               x-show="cameraOn"
               class="absolute bottom-2 right-2 aspect-video w-24 rounded-md
                      border border-white/25 object-cover shadow"></video>
    </div>

    {{-- Anything worth saying that the status line alone would not carry:
         a quiet ring under Do Not Disturb, a refusal, a reconnect. --}}
    <p x-show="notice" x-cloak x-text="notice"
       class="border-b border-brand-border bg-brand-tint px-4 py-2 text-xs text-brand-text"></p>

    <div class="flex items-center gap-2 px-4 py-3">
        <template x-if="! isEnded">
            <div class="flex flex-1 items-center gap-2">
                <button type="button" @click="toggleMute()" x-show="isActive || isConnecting"
                        class="btn-secondary !px-2.5" :aria-pressed="muted ? 'true' : 'false'"
                        :aria-label="muted ? 'Unmute microphone' : 'Mute microphone'">
                    <template x-if="! muted"><x-icon name="microphone" class="h-4 w-4" /></template>
                    <template x-if="muted"><x-icon name="microphone-slash" class="h-4 w-4" /></template>
                </button>

                <button type="button" @click="toggleCamera()" x-show="isActive"
                        class="btn-secondary !px-2.5" :aria-pressed="cameraOn ? 'true' : 'false'"
                        :aria-label="cameraOn ? 'Turn camera off' : 'Turn camera on'">
                    <template x-if="! cameraOn"><x-icon name="video" class="h-4 w-4" /></template>
                    <template x-if="cameraOn"><x-icon name="video-slash" class="h-4 w-4" /></template>
                </button>

                <button type="button" @click="hangUp()"
                        class="btn-emergency ml-auto !py-1.5 !text-xs">
                    <x-icon name="phone-hangup" class="h-4 w-4" />
                    <span x-text="isOutgoing ? 'Cancel' : 'Hang up'"></span>
                </button>
            </div>
        </template>

        {{--
            The only thing a missed call leaves behind, and only if they choose
            it. There is no calls table and no missed-call record — a message
            they actually meant to send is worth more than a row saying they
            once tried.
        --}}
        <template x-if="isEnded">
            <div class="flex flex-1 items-center gap-2">
                <button type="button" @click="messageInstead()" x-show="offersMessage"
                        class="btn-secondary !py-1.5 !text-xs">
                    <x-icon name="send" class="h-3.5 w-3.5" />
                    Send a message
                </button>

                <button type="button" @click="dismiss()"
                        class="btn-secondary ml-auto !py-1.5 !text-xs">
                    Dismiss
                </button>
            </div>
        </template>
    </div>
</div>
