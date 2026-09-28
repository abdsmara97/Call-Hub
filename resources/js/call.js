/**
 * The call session: everything impure that call-machine.js deliberately is not.
 *
 * Owns the timers, the microphone, the peer connection and the socket. Decides
 * nothing on its own — every state change goes through the reducer, so the
 * awkward cases (both people dialling at once, a whisper from a call that has
 * already ended, a tab that was never the one ringing) are settled in code that
 * is tested rather than in code that is merely read.
 */

import {
    ACTIVE,
    CONNECTING,
    DIALLING,
    ENDED,
    IDLE,
    RECONNECTING,
    RINGING_IN,
    RINGING_OUT,
    initial,
    isLive,
    isRinging,
    reasonMessage,
    reduce,
} from './call-machine.js';

import { attachNegotiation, createPeerConnection, isPolite } from './call-peer.js';
import { SignalBuffer, createSignal } from './call-signal.js';
import { joinRoomPresence, releaseRoomPresence } from './presence.js';
import { startRingback, startRingtone, stopRinging } from './ringtone.js';

/** Cleared six seconds after a call ends, so the reason is readable but not sticky. */
const DISMISS_MS = 6000;

/** One ring per person, not per tab. */
const TAB_CHANNEL = 'oaktree-call';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function post(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, payload };
}

/** Announced politely: a ring is not an emergency and must not interrupt. */
function announce(text) {
    const region = document.getElementById('a11y-announcer-polite');

    if (! region) return;

    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = text;
    }, 60);
}

export function registerCallSession(Alpine) {
    Alpine.data('callSession', (me, config = {}) => ({
        call: initial(),

        // Everything below is plumbing the template never sees.
        roomId: null,
        peerName: '',
        peerAvatar: null,
        muted: false,
        cameraOn: false,
        remoteVideoOn: false,
        notice: '',

        config: {},
        tabId: '',
        buffer: new SignalBuffer(),
        channel: null,
        signal: null,
        pc: null,
        negotiation: null,
        localStream: null,
        remoteStream: null,
        tabs: null,
        timers: {},
        presenceHandlers: null,
        iceRestarted: false,

        init() {
            this.config = {
                alertGraceMs: (config.alertGraceSeconds ?? 6) * 1000,
                ringMs: (config.ringSeconds ?? 35) * 1000,
                calleeRingMs: (config.calleeRingSeconds ?? 40) * 1000,
                connectMs: (config.connectTimeoutSeconds ?? 20) * 1000,
                reconnectMs: (config.reconnectGraceSeconds ?? 10) * 1000,
                maxWhisperBytes: config.maxWhisperBytes ?? 28000,
            };

            // A ring lands on every tab this person has open. Elect one.
            this.tabId = window.crypto?.randomUUID?.() ?? String(Math.random());

            if ('BroadcastChannel' in window) {
                this.tabs = new BroadcastChannel(TAB_CHANNEL);
                this.tabs.onmessage = ({ data }) => this.onTabMessage(data);
            }

            // call-dial comes from the conversation header via Alpine, so its
            // detail is the object itself. The other two come from Livewire,
            // which wraps dispatched parameters in a named key.
            window.addEventListener('call-dial', (event) => this.dial(event.detail ?? {}));
            window.addEventListener('call-incoming', (event) => this.onIncoming(event.detail?.call ?? {}));
            window.addEventListener('call-cancelled', (event) => this.onRemoteCancel(event.detail?.call ?? {}));

            // A tab closing without a clean hangup is the common case on
            // mobile; this is best effort, and the peer has slower detectors
            // behind it.
            window.addEventListener('pagehide', () => {
                if (isLive(this.call.state) || isRinging(this.call.state)) {
                    this.signal?.send('call-hangup', { reason: 'gone' });
                }
            });
        },

        /* ---------------------------------------------------------- dispatch */

        /** Every state change goes through here, so effects stay in one place. */
        apply(event) {
            const before = this.call.state;
            const after = reduce(this.call, event);

            if (after === this.call) return;

            this.call = after;

            if (before !== after.state) this.onEnter(after.state, before);
        },

        onEnter(state, previous) {
            this.clearTimer('alert');
            this.clearTimer('ring');
            this.clearTimer('connect');
            this.clearTimer('reconnect');

            if (state === DIALLING) {
                startRingback();
                this.setTimer('alert', this.config.alertGraceMs, () => this.apply({ type: 'ALERT_TIMEOUT' }));
            }

            if (state === RINGING_OUT) {
                this.setTimer('ring', this.config.ringMs, () => this.apply({ type: 'RING_TIMEOUT' }));
            }

            if (state === RINGING_IN) {
                startRingtone({ quiet: this.call.quiet });
                announce(`${this.peerName} is calling.`);
                this.setTimer('ring', this.config.calleeRingMs, () => this.apply({ type: 'RING_TIMEOUT' }));
            }

            if (state === CONNECTING) {
                stopRinging();
                this.setTimer('connect', this.config.connectMs, () => this.apply({ type: 'CONNECT_TIMEOUT' }));
                this.connect();
            }

            if (state === ACTIVE) {
                this.notice = '';
                if (previous === RECONNECTING) announce('Reconnected.');
            }

            if (state === RECONNECTING) {
                this.notice = 'Reconnecting…';
                this.restartIceOnce();
                this.setTimer('reconnect', this.config.reconnectMs, () => this.apply({ type: 'RECONNECT_TIMEOUT' }));
            }

            if (state === ENDED) {
                this.teardown();
                announce(reasonMessage(this.call.reason));
                this.setTimer('dismiss', DISMISS_MS, () => this.apply({ type: 'RESET' }));
            }

            if (state === IDLE) {
                this.resetPlumbing();
            }
        },

        /* -------------------------------------------------------- outgoing */

        async dial({ roomId, peerId, peerName, peerAvatar, video = false }) {
            if (this.call.state !== IDLE) return;

            this.roomId = roomId;
            this.peerName = peerName ?? 'your colleague';
            this.peerAvatar = peerAvatar ?? null;
            this.cameraOn = Boolean(video);

            const { ok, payload } = await post(`/calls/${roomId}/ring`, { video });

            if (! ok) {
                // The server's sentence is more specific than anything the
                // reducer's generic reason could say — "Grace is off shift"
                // beats "they cannot take calls right now".
                this.notice = payload.message ?? 'That call could not be placed.';
                this.apply({ type: 'DIAL_REFUSED', reason: 'unavailable' });

                return;
            }

            this.openSignalling(roomId, peerId, payload.call_id);

            this.apply({
                type: 'DIAL_OK',
                callId: payload.call_id,
                peer: peerId,
                quiet: payload.quiet,
                video,
            });

            if (payload.quiet) {
                this.notice = `${this.peerName} has Do Not Disturb on — ringing quietly.`;
            }

            this.watchForPeer(peerId);
        },

        /**
         * Has a live tab of theirs answered the channel? Distinguishes "did not
         * pick up" from "has no browser open", which deserve different words.
         */
        watchForPeer(peerId) {
            if (! this.channel) return;

            const seen = (users) => {
                const list = Array.isArray(users) ? users : [users];

                if (list.some((user) => Number(user?.id) === Number(peerId))) {
                    this.apply({ type: 'PEER_PRESENT' });
                }
            };

            this.presenceHandlers = {
                here: (users) => seen(users),
                joining: (user) => seen(user),
                leaving: (user) => {
                    if (Number(user?.id) !== Number(peerId)) return;

                    // Only trust this if our own socket is healthy — otherwise
                    // it is us that dropped, not them.
                    if (window.Echo?.connector?.pusher?.connection?.state !== 'connected') return;

                    this.apply({ type: 'PEER_LEFT' });
                },
            };

            this.channel
                .here(this.presenceHandlers.here)
                .joining(this.presenceHandlers.joining)
                .leaving(this.presenceHandlers.leaving);
        },

        /* -------------------------------------------------------- incoming */

        onIncoming(detail) {
            const callId = detail.call_id;

            if (! callId) return;

            // Busy: still join and say so, rather than letting them ring out.
            if (this.call.state !== IDLE) {
                this.declineFrom(detail, 'busy');

                return;
            }

            if (! this.claimRing(callId)) return;

            this.roomId = detail.room_id;
            this.peerName = detail.caller_name ?? 'A colleague';
            this.peerAvatar = detail.caller_avatar_url ?? null;

            this.openSignalling(detail.room_id, detail.caller_id, callId);

            this.apply({
                type: 'INCOMING',
                callId,
                peer: detail.caller_id,
                quiet: detail.quiet,
                video: detail.video,
            });

            // Tells the caller a live tab is here, so they can stop guessing.
            this.signal?.send('call-alerting', {});
        },

        onRemoteCancel(detail) {
            if (detail?.call_id !== this.call.callId) return;

            this.apply({ type: 'CANCEL_REMOTE', callId: detail.call_id });
        },

        /** Declines on behalf of a tab that is already busy elsewhere. */
        async declineFrom(detail, reason) {
            const channel = joinRoomPresence(detail.room_id);

            if (! channel) return;

            channel.whisper('call-decline', {
                v: 1,
                callId: detail.call_id,
                from: me.id,
                to: detail.caller_id,
                reason,
            });

            releaseRoomPresence(detail.room_id);
        },

        /* ------------------------------------------------------- multi-tab */

        /**
         * Announce that this tab intends to ring. Every tab announces at the
         * same moment, so the winner cannot be "whoever spoke first" — it is
         * decided the same way glare is, by comparing two ids both sides hold.
         * The lower tab id keeps the ring; the others fall silent.
         */
        claimRing(callId) {
            this.tabs?.postMessage({ kind: 'claim', callId, tab: this.tabId });

            return true;
        },

        onTabMessage(data) {
            if (! data || data.callId !== this.call.callId) return;

            if (data.kind === 'answered' && this.call.state === RINGING_IN) {
                this.apply({ type: 'CLAIMED_ELSEWHERE', callId: data.callId });

                return;
            }

            // A competing tab announced itself. We yield only if theirs sorts
            // first, so exactly one of us stops.
            if (data.kind === 'claim' && this.call.state === RINGING_IN && data.tab < this.tabId) {
                this.apply({ type: 'CLAIMED_ELSEWHERE', callId: data.callId });
            }
        },

        /* ------------------------------------------------------ signalling */

        openSignalling(roomId, peerId, callId) {
            this.channel = joinRoomPresence(roomId);

            if (! this.channel) return;

            this.signal = createSignal({
                channel: this.channel,
                me: me.id,
                peerId,
                maxBytes: this.config.maxWhisperBytes,
                onOversize: () => {
                    this.notice = 'Video is not available on this connection.';
                },
            });

            this.signal.setCallId(callId);

            this.signal.on('call-alerting', () => this.apply({ type: 'PEER_PRESENT', callId }));
            this.signal.on('call-accept', (p) => this.apply({ type: 'ACCEPT_REMOTE', callId, video: p.video }));
            this.signal.on('call-decline', (p) => this.apply({ type: 'DECLINE_REMOTE', callId, reason: p.reason }));
            this.signal.on('call-hangup', () => this.apply({
                type: isRinging(this.call.state) ? 'CANCEL_REMOTE' : 'HANGUP_REMOTE',
                callId,
            }));

            // Held rather than dropped if the peer connection is not built yet
            // — see SignalBuffer. The peer's offer routinely arrives while this
            // side is still waiting on the microphone.
            this.signal.on('call-offer', (p) => this.onSignal('description', { type: p.type, sdp: p.sdp }));
            this.signal.on('call-answer', (p) => this.onSignal('description', { type: p.type, sdp: p.sdp }));
            this.signal.on('call-ice', (p) => this.onSignal('candidate', p.candidate));

            this.signal.on('call-media', (p) => {
                if (p.kind === 'video') this.remoteVideoOn = Boolean(p.enabled);
            });

            // A socket blip must not end a call that is already up.
            window.Echo?.connector?.pusher?.connection?.bind('connected', () => this.signal?.flush());
        },

        /** Deliver now if we can, hold it if we cannot. Never discard. */
        onSignal(kind, payload) {
            if (! this.negotiation) {
                this.buffer.hold(kind, payload);

                return;
            }

            this.deliver(kind, payload);
        },

        deliver(kind, payload) {
            if (kind === 'description') this.negotiation.onDescription(payload);
            if (kind === 'candidate') this.negotiation.onCandidate(payload);
        },

        /* ------------------------------------------------------- the media */

        async connect() {
            try {
                this.localStream = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                    video: this.call.video,
                });
            } catch {
                this.apply({ type: 'MEDIA_DENIED', callId: this.call.callId });

                return;
            }

            const { ok, payload } = await post(`/calls/${this.roomId}/ice-servers`);

            if (! ok) {
                this.apply({ type: 'PC_FAILED', callId: this.call.callId });

                return;
            }

            this.pc = createPeerConnection(payload.ice_servers);
            this.remoteStream = new MediaStream();

            this.negotiation = attachNegotiation(
                this.pc,
                this.signal,
                isPolite(me.id, this.call.peer),
                () => {},
            );

            this.pc.ontrack = ({ track }) => {
                this.remoteStream.addTrack(track);
                this.attachStreams();
            };

            this.pc.onconnectionstatechange = () => {
                const state = this.pc?.connectionState;

                if (state === 'connected') this.apply({ type: 'PC_CONNECTED', callId: this.call.callId });
                if (state === 'disconnected') this.apply({ type: 'PC_DISCONNECTED', callId: this.call.callId });
                if (state === 'failed') this.apply({ type: 'PC_FAILED', callId: this.call.callId });
            };

            // Both sides add tracks and let negotiationneeded fire; perfect
            // negotiation settles the collision. That makes setup and
            // renegotiation the same code path, so the renegotiation path is
            // exercised on every single call rather than only when someone
            // reaches for the camera.
            this.localStream.getTracks().forEach((track) => {
                this.pc.addTrack(track, this.localStream);
            });

            // Only now is there somewhere to put anything that arrived while we
            // were asking for the microphone. Released after addTrack, so an
            // offer we answer straight away is answered *with* our audio in it.
            this.buffer.release().forEach(({ kind, payload }) => this.deliver(kind, payload));

            this.attachStreams();
        },

        attachStreams() {
            const remote = this.$refs.remoteAudio;
            const remoteVideo = this.$refs.remoteVideo;
            const localVideo = this.$refs.localVideo;

            if (remote && remote.srcObject !== this.remoteStream) {
                remote.srcObject = this.remoteStream;
                // Started from the Accept tap, which is the user gesture iOS
                // requires. Without this the call connects silently one way.
                remote.play?.().catch(() => {});
            }

            if (remoteVideo && remoteVideo.srcObject !== this.remoteStream) {
                remoteVideo.srcObject = this.remoteStream;
            }

            if (localVideo && localVideo.srcObject !== this.localStream) {
                localVideo.srcObject = this.localStream;
            }
        },

        restartIceOnce() {
            if (this.iceRestarted || ! this.pc?.restartIce) return;

            this.iceRestarted = true;
            this.pc.restartIce();
        },

        /* --------------------------------------------------------- actions */

        async accept({ video = false } = {}) {
            if (this.call.state !== RINGING_IN) return;

            this.cameraOn = video;
            this.signal?.send('call-accept', { video });
            this.tabs?.postMessage({ kind: 'answered', callId: this.call.callId });

            this.apply({ type: 'ACCEPT_LOCAL', callId: this.call.callId, video });
        },

        decline() {
            if (this.call.state !== RINGING_IN) return;

            this.signal?.send('call-decline', { reason: 'declined' });
            this.apply({ type: 'DECLINE_LOCAL', callId: this.call.callId });
        },

        hangUp() {
            if (this.call.state === IDLE || this.call.state === ENDED) return;

            this.signal?.send('call-hangup', { reason: 'hangup' });

            if (isRinging(this.call.state)) {
                if (this.call.outgoing && this.roomId) {
                    post(`/calls/${this.roomId}/cancel`, { call_id: this.call.callId });
                }

                this.apply({ type: 'CANCEL_LOCAL', callId: this.call.callId });

                return;
            }

            this.apply({ type: 'HANGUP_LOCAL', callId: this.call.callId });
        },

        toggleMute() {
            this.muted = ! this.muted;

            this.localStream?.getAudioTracks().forEach((track) => {
                track.enabled = ! this.muted;
            });

            this.signal?.send('call-media', { kind: 'audio', enabled: ! this.muted });
        },

        async toggleCamera() {
            if (! isLive(this.call.state)) return;

            if (this.cameraOn) {
                this.localStream?.getVideoTracks().forEach((track) => {
                    track.stop();
                    const sender = this.pc?.getSenders().find((s) => s.track === track);
                    if (sender) this.pc.removeTrack(sender);
                    this.localStream.removeTrack(track);
                });

                this.cameraOn = false;
                this.signal?.send('call-media', { kind: 'video', enabled: false });
                this.apply({ type: 'VIDEO_TOGGLED', callId: this.call.callId, enabled: false });

                return;
            }

            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                const track = stream.getVideoTracks()[0];

                this.localStream.addTrack(track);

                const sender = this.pc.addTrack(track, this.localStream);
                const transceiver = this.pc.getTransceivers().find((t) => t.sender === sender);

                this.negotiation?.preferCommonVideoCodecs(transceiver);

                this.cameraOn = true;
                this.signal?.send('call-media', { kind: 'video', enabled: true });
                this.apply({ type: 'VIDEO_TOGGLED', callId: this.call.callId, enabled: true });

                this.attachStreams();
            } catch {
                this.notice = 'Camera access was refused.';
            }
        },

        /** Clears the closing message early. Goes through the reducer like
         *  everything else — nothing sets state by hand. */
        dismiss() {
            this.apply({ type: 'RESET' });
        },

        /** The one thing a missed call leaves behind, and only if they choose it. */
        messageInstead() {
            if (! this.roomId) return;

            window.location.assign(`/hub/rooms/${this.roomId}`);
        },

        /* -------------------------------------------------------- teardown */

        teardown() {
            stopRinging();

            this.localStream?.getTracks().forEach((track) => track.stop());
            this.localStream = null;
            this.remoteStream = null;

            if (this.pc) {
                this.pc.onicecandidate = null;
                this.pc.ontrack = null;
                this.pc.onconnectionstatechange = null;
                this.pc.onnegotiationneeded = null;
                this.pc.close();
                this.pc = null;
            }

            this.negotiation = null;
            this.buffer.clear();

            this.signal?.teardown();
            this.signal = null;

            if (this.roomId !== null && this.channel) {
                releaseRoomPresence(this.roomId);
            }

            // Echo offers no way to unbind here/joining/leaving individually,
            // so if the typing indicator is still holding this channel our
            // handlers outlive the call. They are harmless: PEER_PRESENT and
            // PEER_LEFT are inert in both idle and ended, which is exactly the
            // reason the reducer is total rather than throwing on surprises.
            this.channel = null;
            this.presenceHandlers = null;
            this.iceRestarted = false;
        },

        resetPlumbing() {
            this.roomId = null;
            this.peerName = '';
            this.peerAvatar = null;
            this.muted = false;
            this.cameraOn = false;
            this.remoteVideoOn = false;
            this.notice = '';
        },

        setTimer(name, ms, fn) {
            this.clearTimer(name);
            this.timers[name] = window.setTimeout(fn, ms);
        },

        clearTimer(name) {
            if (this.timers[name]) {
                window.clearTimeout(this.timers[name]);
                delete this.timers[name];
            }
        },

        /* ----------------------------------------------------- for the view */

        get isIdle() {
            return this.call.state === IDLE;
        },

        get isIncoming() {
            return this.call.state === RINGING_IN;
        },

        get isOutgoing() {
            return this.call.state === DIALLING || this.call.state === RINGING_OUT;
        },

        get isConnecting() {
            return this.call.state === CONNECTING;
        },

        get isActive() {
            return this.call.state === ACTIVE || this.call.state === RECONNECTING;
        },

        get isEnded() {
            return this.call.state === ENDED;
        },

        get showsSurface() {
            return this.call.state !== IDLE;
        },

        get endedMessage() {
            return reasonMessage(this.call.reason);
        },

        /** Offered only where trying again by message actually makes sense. */
        get offersMessage() {
            return ['no_answer', 'unreachable', 'declined', 'busy', 'unavailable']
                .includes(this.call.reason);
        },

        get statusLabel() {
            if (this.call.state === DIALLING) return 'Calling…';
            if (this.call.state === RINGING_OUT) return 'Ringing…';
            if (this.call.state === CONNECTING) return 'Connecting…';
            if (this.call.state === RECONNECTING) return 'Reconnecting…';
            if (this.call.state === ACTIVE) return 'Connected';

            return '';
        },
    }));
}
