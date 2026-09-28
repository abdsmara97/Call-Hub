/**
 * The huddle session: LiveKit glue, and as little decision-making as possible.
 *
 * Everything that can be decided without a browser lives in huddle-machine.js
 * and huddle-arbiter.js, both of which are pure and tested in node. What is left
 * here is the part that genuinely needs the SDK.
 *
 * Two structural rules run through the whole file.
 *
 * 1. **LiveKit objects never touch Alpine.** Room, Participant and Track use
 *    #private fields, and reading a private field through Alpine's reactivity
 *    Proxy throws "Cannot read private member". So the SDK handles live in this
 *    factory's closure and only plain data — strings, numbers, booleans — is
 *    returned on the component. The reducer stores track *sids*; the directives
 *    below resolve a sid to a live Track through a module-level map.
 *
 * 2. **The 1:1 call stack is read-only.** The huddle observes the call's state
 *    and yields to it. call.js, call-machine.js, call-peer.js and call-signal.js
 *    are not imported, not edited and not aware this exists.
 */

import {
    ENDED,
    IDLE,
    LIVE,
    chooseStage,
    gridClass,
    initial,
    layoutMode,
    reduce,
    rosterLabel,
    visibleTiles,
} from './huddle-machine.js';

import { arbitrate } from './huddle-arbiter.js';
import { announce, postJson } from './http.js';

/**
 * trackSid => LiveKit Track.
 *
 * Module-level and keyed by sid, which is globally unique, so one map serves
 * every component on the page and the directives need no per-instance wiring.
 */
const tracks = new Map();

/** Courtesy only — LiveKit itself decides who wins. See onDisconnected. */
const TAB_CHANNEL = 'oaktree-huddle';

const SURFACE_KEY = 'oaktree-huddle-surface';

const DEVICE_KEY = 'oaktree-huddle-devices';

/** The three enumerateDevices() kinds we offer, in the order the menu shows them. */
const DEVICE_KINDS = ['audioinput', 'videoinput', 'audiooutput'];

/*
 * setSinkId is Chromium-only. Computed once rather than in the getter that reads
 * it: that getter is on a reactive object and Alpine re-evaluates it freely, and
 * this would otherwise build a throwaway <audio> element every time.
 */
const CAN_CHOOSE_SPEAKER = typeof document !== 'undefined'
    && typeof document.createElement('audio').setSinkId === 'function';

/**
 * A device the browser will not name.
 *
 * Chromium hands back a blank label for a device it considers privacy-sensitive
 * even after permission is granted, and "" is not a usable menu row.
 */
function defaultDeviceLabel(kind) {
    return {
        audioinput: 'Microphone',
        videoinput: 'Camera',
        audiooutput: 'Speaker',
    }[kind] ?? 'Device';
}

function stringOrNull(value) {
    return typeof value === 'string' && value !== '' ? value : null;
}

export function registerHuddleSession(Alpine) {
    registerTrackDirectives(Alpine);

    Alpine.data('huddleSession', (me, config = {}) => {
        // Closure state: never proxied, never reactive, never in a template.
        let sdk = null;
        let room = null;
        let tabs = null;
        let stopWatchingCall = null;
        let evicted = false;
        let stopWatchingDevices = null;

        return {
            huddle: initial(),
            me,
            config,
            peerNames: {},

            /** Pure UI, deliberately not in the reducer — nothing depends on it. */
            devicesOpen: false,

            init() {
                this.huddle.surface = this.restoreSurface();
                this.huddle.selected = this.restoreDevices();

                window.addEventListener('huddle-start', (event) => this.join(event.detail ?? {}));
                window.addEventListener('huddle-join', (event) => this.join(event.detail ?? {}));

                // A closed tab must not leave a ghost in the roster.
                window.addEventListener('pagehide', () => {
                    room?.disconnect();
                });

                this.watchCall(Alpine);
                this.openTabChannel();
            },

            destroy() {
                stopWatchingCall?.();
                stopWatchingDevices?.();
                tabs?.close();
                room?.disconnect();
            },

            /* ------------------------------------------------------ joining */

            async join(detail = {}) {
                const roomId = Number(detail.roomId ?? this.config.roomId);

                if (! roomId) return;

                /*
                 * The single funnel every entry point goes through — the banner
                 * button, the header button and both window events. The banner
                 * also disables its button and shows the reason, but that is the
                 * display half; this is the control.
                 */
                const verdict = arbitrate(this.callState(), this.huddle);

                if (verdict.blockJoin) {
                    this.apply({ type: 'NOTICE', roomId: this.huddle.roomId, notice: verdict.blockedReason });
                    announce(verdict.blockedReason);

                    return;
                }

                if (this.huddle.roomId === roomId && this.isLive) return;

                if (room) await this.leave({ silent: true });

                evicted = false;
                this.apply({ type: 'JOIN_REQUESTED', roomId });

                const { ok, status, payload } = await postJson(`/huddles/${roomId}/join`);

                if (! ok) {
                    this.apply({
                        type: 'TOKEN_REFUSED',
                        roomId,
                        reason: status === 409 ? (payload.reason ?? 'full') : 'token_refused',
                        // The server's own sentence is more specific than ours.
                        message: payload.message,
                    });

                    return;
                }

                this.apply({ type: 'TOKEN_OK', roomId });

                try {
                    await this.connect(roomId, payload, detail.audio !== false);
                } catch (error) {
                    this.apply({ type: 'CONNECT_FAILED', roomId, reason: 'signal_lost' });
                }
            },

            async connect(roomId, payload, withAudio) {
                /*
                 * Loaded on demand, never at the top of the module. livekit-client
                 * is ~150 kB gzipped, and in a 300-member room the banner is shown
                 * to 300 people of whom perhaps four ever join. Vite code-splits
                 * this automatically, so app.js stays the same weight for everyone
                 * who never presses the button.
                 */
                sdk ??= await import('livekit-client');

                /*
                 * The stored devices are capture *defaults* rather than a switch
                 * after the fact. Publishing on the built-in microphone and then
                 * switching is audible to everyone else — a second or so of the
                 * wrong device, on every join.
                 */
                const chosen = this.huddle.selected;

                room = new sdk.Room({
                    /*
                     * Not optimisations — the mechanism. adaptiveStream watches
                     * the attached element's size and visibility, so a tile that
                     * is display:none or removed stops pulling video. That is
                     * what makes minimising to the pill free, and what keeps the
                     * overflow cap from costing bandwidth.
                     */
                    adaptiveStream: true,
                    dynacast: true,
                    audioCaptureDefaults: chosen.audioinput ? { deviceId: chosen.audioinput } : undefined,
                    videoCaptureDefaults: {
                        resolution: sdk.VideoPresets.h360.resolution,
                        ...(chosen.videoinput ? { deviceId: chosen.videoinput } : {}),
                    },
                    publishDefaults: { simulcast: true },
                });

                this.bind(sdk, roomId);

                await room.connect(payload.url, payload.token, {
                    rtcConfig: payload.ice_servers?.length ? { iceServers: payload.ice_servers } : undefined,
                });

                this.apply({
                    type: 'CONNECTED',
                    roomId,
                    peers: room.remoteParticipants
                        ? Array.from(room.remoteParticipants.values()).map((p) => this.describe(p))
                        : [],
                });

                tabs?.postMessage({ kind: 'joining', roomId });

                try {
                    await room.localParticipant.setMicrophoneEnabled(withAudio);
                } catch {
                    /*
                     * Listening is a legitimate way to attend a huddle, so a
                     * refused microphone is a notice rather than an ending. This
                     * is where the huddle model diverges from the call model,
                     * which correctly ends on MEDIA_DENIED — you cannot have a
                     * one-to-one call nobody can hear you on.
                     */
                    this.apply({ type: 'LOCAL_CHANGED', roomId, local: { micOn: false } });
                    this.apply({
                        type: 'NOTICE',
                        roomId,
                        notice: 'We could not reach your microphone. You are listening only.',
                    });
                }

                /*
                 * Only now. enumerateDevices() returns entries with empty labels
                 * until a permission has been granted, and a menu of "" is worse
                 * than no menu — so the list is read after the microphone
                 * request above has been answered, either way.
                 */
                await this.refreshDevices();
                this.watchDevices();

                // The speaker is not a capture default, so it is the one choice
                // that has to be applied after connecting rather than before.
                if (chosen.audiooutput) {
                    await this.applyDevice('audiooutput', chosen.audiooutput);
                }

                announce('You joined the huddle.');
            },

            bind(lk, roomId) {
                const { RoomEvent } = lk;
                const at = (type, extra = {}) => this.apply({ type, roomId, ...extra });

                room
                    .on(RoomEvent.ParticipantConnected, (p) => at('PARTICIPANT_JOINED', { peer: this.describe(p) }))
                    .on(RoomEvent.ParticipantDisconnected, (p) => at('PARTICIPANT_LEFT', { identity: p.identity }))
                    .on(RoomEvent.TrackSubscribed, (track, pub, p) => {
                        tracks.set(pub.trackSid, track);
                        at('TRACK_CHANGED', this.trackEvent(lk, pub, p, true));
                    })
                    .on(RoomEvent.TrackUnsubscribed, (track, pub, p) => {
                        tracks.delete(pub.trackSid);
                        at('TRACK_CHANGED', this.trackEvent(lk, pub, p, false));
                    })
                    .on(RoomEvent.TrackMuted, (pub, p) => at('TRACK_CHANGED', this.trackEvent(lk, pub, p, false)))
                    .on(RoomEvent.TrackUnmuted, (pub, p) => at('TRACK_CHANGED', this.trackEvent(lk, pub, p, true)))
                    .on(RoomEvent.ActiveSpeakersChanged, (speakers) => {
                        at('SPEAKERS', { identities: speakers.map((s) => s.identity) });
                    })
                    .on(RoomEvent.LocalTrackPublished, () => at('LOCAL_CHANGED', { local: this.localFlags() }))
                    .on(RoomEvent.LocalTrackUnpublished, () => at('LOCAL_CHANGED', { local: this.localFlags() }))
                    .on(RoomEvent.Reconnecting, () => at('NET_RECONNECTING'))
                    .on(RoomEvent.Reconnected, () => at('NET_RECONNECTED'))
                    /*
                     * The LiveKit equivalent of the muted-autoplay problem the
                     * 1:1 panel handles with remote.play().catch(). On iOS a
                     * huddle joined without a fresh gesture connects silently,
                     * and the fix is a button the user can press.
                     */
                    .on(RoomEvent.AudioPlaybackStatusChanged, () => {
                        at('AUDIO_BLOCKED', { blocked: ! room.canPlaybackAudio });
                    })
                    .on(RoomEvent.Disconnected, (reason) => this.onDisconnected(lk, roomId, reason));
            },

            onDisconnected(lk, roomId, reason) {
                const { DisconnectReason } = lk;

                const mapped = evicted
                    ? 'call_taken'
                    : reason === DisconnectReason?.DUPLICATE_IDENTITY
                        ? 'duplicate'
                        : reason === DisconnectReason?.ROOM_DELETED
                            ? 'room_closed'
                            : reason === DisconnectReason?.CLIENT_INITIATED
                                ? 'left'
                                : 'signal_lost';

                room = null;
                this.apply({ type: 'DISCONNECTED', roomId, reason: mapped });
            },

            async leave({ silent = false, reason = 'left' } = {}) {
                const roomId = this.huddle.roomId;

                await room?.disconnect();
                room = null;

                if (! silent) {
                    this.apply({ type: 'LEAVE_LOCAL', roomId, reason });
                    announce('You left the huddle.');
                }
            },

            /* ----------------------------------------------------- controls */

            async toggleMic() {
                if (! room) return;

                try {
                    await room.localParticipant.setMicrophoneEnabled(! this.huddle.local.micOn);
                } catch {
                    this.notice('We could not reach your microphone.');
                }

                this.apply({ type: 'LOCAL_CHANGED', roomId: this.huddle.roomId, local: this.localFlags() });
            },

            async toggleCamera() {
                if (! room) return;

                try {
                    await room.localParticipant.setCameraEnabled(! this.huddle.local.camOn);
                } catch {
                    this.notice('We could not reach your camera.');
                }

                this.apply({ type: 'LOCAL_CHANGED', roomId: this.huddle.roomId, local: this.localFlags() });
            },

            async toggleScreen() {
                if (! room) return;

                try {
                    await room.localParticipant.setScreenShareEnabled(! this.huddle.local.screenOn);
                } catch {
                    // Includes the user simply cancelling the picker, which is
                    // not an error worth a notice.
                }

                this.apply({ type: 'LOCAL_CHANGED', roomId: this.huddle.roomId, local: this.localFlags() });
            },

            async startAudio() {
                await room?.startAudio();
                this.apply({ type: 'AUDIO_BLOCKED', roomId: this.huddle.roomId, blocked: false });
            },

            /* ------------------------------------------------------ devices */

            /**
             * What the browser currently offers, as plain data.
             *
             * MediaDeviceInfo is a live object and goes nowhere near Alpine —
             * same rule as Track, and it bites the same way. Entries with a
             * blank deviceId are dropped: they cannot be selected and only ever
             * show up as an unlabelled row.
             */
            async refreshDevices() {
                if (! navigator.mediaDevices?.enumerateDevices) return;

                let found = [];

                try {
                    found = await navigator.mediaDevices.enumerateDevices();
                } catch {
                    // Enumeration is refused outright in some hardened setups.
                    return;
                }

                const devices = { audioinput: [], videoinput: [], audiooutput: [] };

                for (const device of found) {
                    if (! DEVICE_KINDS.includes(device.kind) || ! device.deviceId) continue;

                    devices[device.kind].push({
                        deviceId: device.deviceId,
                        label: device.label || defaultDeviceLabel(device.kind),
                    });
                }

                this.apply({ type: 'DEVICES', devices });
            },

            /**
             * A headset plugged in mid-huddle should appear without a rejoin.
             */
            watchDevices() {
                if (stopWatchingDevices || ! navigator.mediaDevices) return;

                const onChange = () => this.refreshDevices();

                navigator.mediaDevices.addEventListener?.('devicechange', onChange);

                stopWatchingDevices = () => {
                    navigator.mediaDevices.removeEventListener?.('devicechange', onChange);
                    stopWatchingDevices = null;
                };
            },

            /**
             * Chosen from the menu: switch, remember, and only then record it.
             *
             * The order matters. Storing a device the browser refused would
             * leave the menu claiming a microphone that is not being used, and
             * would then be restored on the next join and fail again.
             */
            async selectDevice(kind, deviceId) {
                if (! DEVICE_KINDS.includes(kind) || ! deviceId) return;

                const applied = await this.applyDevice(kind, deviceId);

                if (! applied) return;

                this.apply({ type: 'DEVICE_SELECTED', kind, deviceId });
                this.persistDevices();
            },

            /**
             * @returns {Promise<boolean>} whether the switch actually happened
             */
            async applyDevice(kind, deviceId) {
                if (! room) return false;

                try {
                    await room.switchActiveDevice(kind, deviceId);

                    return true;
                } catch {
                    /*
                     * Overwhelmingly the speaker: setSinkId is Chromium-only, so
                     * Firefox and Safari reject an audiooutput switch outright.
                     * Worth saying, because the control is visible and the user
                     * has just pressed it.
                     */
                    this.notice(kind === 'audiooutput'
                        ? 'This browser does not let a page choose the speaker.'
                        : 'We could not switch to that device.');

                    return false;
                }
            },

            deviceLabel(kind) {
                return {
                    audioinput: 'Microphone',
                    videoinput: 'Camera',
                    audiooutput: 'Speaker',
                }[kind];
            },

            /** The speaker row is pointless where the browser cannot honour it. */
            get deviceKinds() {
                return CAN_CHOOSE_SPEAKER ? DEVICE_KINDS : ['audioinput', 'videoinput'];
            },

            persistDevices() {
                try {
                    window.localStorage.setItem(DEVICE_KEY, JSON.stringify(this.huddle.selected));
                } catch {
                    // Private browsing. The choice simply lasts this huddle.
                }
            },

            restoreDevices() {
                const empty = { audioinput: null, videoinput: null, audiooutput: null };

                try {
                    const stored = JSON.parse(window.localStorage.getItem(DEVICE_KEY) || '{}');

                    return {
                        audioinput: stringOrNull(stored.audioinput),
                        videoinput: stringOrNull(stored.videoinput),
                        audiooutput: stringOrNull(stored.audiooutput),
                    };
                } catch {
                    return empty;
                }
            },

            setSurface(surface) {
                this.apply({ type: 'SURFACE', surface });

                try {
                    window.localStorage.setItem(SURFACE_KEY, surface);
                } catch {
                    // Private browsing. The surface simply does not persist.
                }
            },

            cycleSurface() {
                const order = ['pill', 'dock', 'expanded'];
                const next = order[(order.indexOf(this.huddle.surface) + 1) % order.length];

                this.setSurface(next);
            },

            dismiss() {
                this.apply({ type: 'RESET' });
            },

            /* ------------------------------------------------- coexistence */

            /**
             * Reads the 1:1 call's state without owning any of it.
             *
             * There is no call-ended event to listen for and no shared store,
             * because the call stack was deliberately left untouched. Alpine's
             * $data() on the callSession root is a public API and hands back the
             * live reactive object, so an Alpine effect re-runs whenever
             * call.state changes.
             */
            watchCall(alpine) {
                const el = document.querySelector('[x-data^="callSession"]');

                if (! el) return;

                const effect = alpine.effect(() => {
                    const state = alpine.$data(el)?.call?.state ?? 'idle';

                    this.onCallState(state);
                });

                stopWatchingCall = () => alpine.release?.(effect);
            },

            callState() {
                const el = document.querySelector('[x-data^="callSession"]');

                if (! el) return 'idle';

                return Alpine.$data(el)?.call?.state ?? 'idle';
            },

            onCallState(state) {
                const verdict = arbitrate(state, this.huddle);

                this.apply({ type: 'YIELD', yielding: verdict.yield });

                if (verdict.evict) {
                    evicted = true;
                    this.leave({ silent: true }).then(() => {
                        this.apply({ type: 'DISCONNECTED', roomId: this.huddle.roomId, reason: 'call_taken' });
                    });

                    return;
                }

                if (! room) return;

                if (verdict.muteLocal && this.huddle.local.micOn) {
                    room.localParticipant.setMicrophoneEnabled(false);
                }

                // Pause remote audio so the huddle does not talk over a ringtone.
                if (verdict.yield) {
                    room.remoteParticipants?.forEach((p) => p.setVolume?.(0));
                } else {
                    room.remoteParticipants?.forEach((p) => p.setVolume?.(1));
                }
            },

            /**
             * Courtesy, not arbitration.
             *
             * LiveKit already decides this: connecting with the same identity
             * disconnects the older session outright. All this channel does is
             * let the tab about to be kicked say why, so the user reads "you
             * joined in another tab" instead of a bare disconnection. Its own
             * channel rather than the call's, whose protocol is keyed on callId
             * and has a tested contract worth leaving alone.
             */
            openTabChannel() {
                if (! ('BroadcastChannel' in window)) return;

                tabs = new BroadcastChannel(TAB_CHANNEL);

                tabs.onmessage = (event) => {
                    if (event.data?.kind !== 'joining') return;
                    if (event.data.roomId !== this.huddle.roomId) return;
                    if (! room) return;

                    this.notice('You joined this huddle in another tab.');
                };
            },

            /* -------------------------------------------------------- views */

            get isIdle() {
                return this.huddle.state === IDLE;
            },

            get isLive() {
                return this.huddle.state === LIVE || this.huddle.state === 'reconnecting';
            },

            get isEnded() {
                return this.huddle.state === ENDED;
            },

            get isBusy() {
                return this.huddle.state === 'joining' || this.huddle.state === 'connecting';
            },

            get showsPanel() {
                return this.huddle.state !== IDLE && this.huddle.surface !== 'pill';
            },

            get showsPill() {
                return this.huddle.state !== IDLE && this.huddle.surface === 'pill';
            },

            /** Every tile, including me, ordered by arrival. */
            get allTiles() {
                const mine = {
                    identity: `u${this.me.id}`,
                    id: this.me.id,
                    name: this.me.name,
                    avatar: this.me.avatar_url ?? null,
                    initials: this.me.initials ?? '',
                    joinedSeq: 0,
                    isMe: true,
                    micOn: this.huddle.local.micOn,
                    camOn: this.huddle.local.camOn,
                    videoSid: 'local-camera',
                    screenSid: this.huddle.local.screenOn ? 'local-screen' : null,
                    speaking: false,
                };

                return [mine, ...this.huddle.peers];
            },

            get tiles() {
                return visibleTiles(this.allTiles, this.huddle.surface).tiles;
            },

            get overflow() {
                return visibleTiles(this.allTiles, this.huddle.surface).overflow;
            },

            get gridColumns() {
                return gridClass(this.tiles.length);
            },

            get isStageMode() {
                return layoutMode(this.allTiles.length, this.huddle.surface) === 'stage';
            },

            get stageIdentity() {
                return chooseStage(this.allTiles, Date.now(), this.stageHeld ?? null);
            },

            get participantCount() {
                return this.huddle.peers.length + (this.isLive || this.isBusy ? 1 : 0);
            },

            get rosterText() {
                return rosterLabel(this.allTiles, this.me.id);
            },

            get statusLabel() {
                if (this.isBusy) return 'Joining…';
                if (this.huddle.state === 'reconnecting') return 'Reconnecting…';
                if (this.isEnded) return this.huddle.notice;

                return this.participantCount === 1
                    ? 'Waiting for others'
                    : `${this.participantCount} people`;
            },

            get surfaceLabel() {
                return {
                    pill: 'Expand the huddle',
                    dock: 'Make the huddle bigger',
                    expanded: 'Minimise the huddle',
                }[this.huddle.surface];
            },

            get canShareScreen() {
                return typeof navigator !== 'undefined' && !! navigator.mediaDevices?.getDisplayMedia;
            },

            /* ------------------------------------------------------ plumbing */

            apply(event) {
                this.huddle = reduce(this.huddle, event);
            },

            notice(text) {
                this.apply({ type: 'NOTICE', roomId: this.huddle.roomId, notice: text });
            },

            describe(participant) {
                let metadata = {};

                try {
                    metadata = JSON.parse(participant.metadata || '{}');
                } catch {
                    // Signed by us, but never trust a parse.
                }

                return {
                    identity: participant.identity,
                    id: metadata.user_id ?? null,
                    name: participant.name || 'Someone',
                    avatar: metadata.avatar_url ?? null,
                    micOn: participant.isMicrophoneEnabled ?? false,
                    camOn: participant.isCameraEnabled ?? false,
                };
            },

            trackEvent(lk, publication, participant, enabled) {
                const { Track } = lk;

                const kind = publication.source === Track.Source.ScreenShare
                    ? 'screen'
                    : publication.kind === Track.Kind.Audio ? 'audio' : 'video';

                return { identity: participant.identity, kind, sid: publication.trackSid, enabled };
            },

            localFlags() {
                const local = room?.localParticipant;

                return {
                    micOn: local?.isMicrophoneEnabled ?? false,
                    camOn: local?.isCameraEnabled ?? false,
                    screenOn: local?.isScreenShareEnabled ?? false,
                };
            },

            restoreSurface() {
                try {
                    const stored = window.localStorage.getItem(SURFACE_KEY);

                    return ['pill', 'dock', 'expanded'].includes(stored) ? stored : 'dock';
                } catch {
                    return 'dock';
                }
            },
        };
    });
}

/**
 * Two directives, because x-for means there is no x-ref per tile — and because
 * LiveKit's track.attach() *returns* an element rather than filling one, so
 * there is nothing for an x-ref to point at anyway.
 */
function registerTrackDirectives(Alpine) {
    /**
     * x-huddle-track="tile.videoSid"
     *
     * Owns the element's whole life: attach on change, detach on change and on
     * cleanup. Detaching matters — an orphaned <video> keeps its subscription
     * alive, so adaptive streaming never learns the tile is gone and we keep
     * paying for video nobody can see.
     */
    Alpine.directive('huddle-track', (el, { expression }, { evaluateLater, effect, cleanup }) => {
        const readSid = evaluateLater(expression);
        let attached = null;

        const detach = () => {
            if (! attached) return;

            tracks.get(attached.sid)?.detach(attached.node);
            attached.node.remove();
            attached = null;
        };

        effect(() => {
            readSid((sid) => {
                if (attached?.sid === sid) return;

                detach();

                const track = sid && tracks.get(sid);

                if (! track) return;

                const node = track.attach();
                node.setAttribute('playsinline', '');
                node.muted = true; // audio comes from the sink below, never a tile
                el.appendChild(node);
                attached = { sid, node };
            });
        });

        cleanup(detach);
    });

    /**
     * x-huddle-audio
     *
     * One hidden sink for every remote audio track, deliberately outside every
     * toggled branch. Same reasoning as the <audio x-ref="remoteAudio"> note in
     * the 1:1 panel: a tile scrolling past the visible cap must not take
     * somebody's voice with it.
     */
    Alpine.directive('huddle-audio', (el, meta, { cleanup }) => {
        const attached = new Map();

        const sync = () => {
            for (const [sid, track] of tracks) {
                if (track.kind !== 'audio' || attached.has(sid)) continue;

                const node = track.attach();
                node.setAttribute('playsinline', '');
                el.appendChild(node);
                attached.set(sid, node);
            }

            for (const [sid, node] of attached) {
                if (tracks.has(sid)) continue;

                node.remove();
                attached.delete(sid);
            }
        };

        const timer = window.setInterval(sync, 500);

        cleanup(() => {
            window.clearInterval(timer);
            attached.forEach((node) => node.remove());
            attached.clear();
        });
    });
}
