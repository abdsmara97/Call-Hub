/**
 * The huddle state machine, and the layout decisions that fall out of it.
 *
 * Pure: no DOM, no LiveKit, no clock. Same bargain call-machine.js already
 * struck, and for the same reason — there is no RTCPeerConnection in node, so
 * anything left inline in the SDK glue can only be verified by hand in a
 * browser. Everything here is a fold over events, and every decision below is
 * covered by tests/js/huddle-machine.test.mjs.
 *
 * The one structural rule the glue must respect: this file stores track *sids*,
 * never Track objects. LiveKit's Room, Participant and Track use #private
 * fields, and reading one through Alpine's reactivity Proxy throws outright.
 */

export const IDLE = 'idle';
export const JOINING = 'joining';         // token request and dynamic import in flight
export const CONNECTING = 'connecting';   // room.connect() in flight
export const LIVE = 'live';
export const RECONNECTING = 'reconnecting';
export const ENDED = 'ended';

export const REASONS = {
    left: 'You left the huddle.',
    empty: 'Everyone else left.',
    call_taken: 'You left the huddle to take a call.',
    duplicate: 'You joined this huddle in another tab.',
    media_denied: 'A microphone is needed to join a huddle.',
    token_refused: 'You cannot join this huddle.',
    full: 'This huddle is full.',
    signal_lost: 'Lost connection to the huddle.',
    room_closed: 'The huddle ended.',
};

export function reasonMessage(reason) {
    return REASONS[reason] ?? REASONS.signal_lost;
}

export function initial() {
    return {
        state: IDLE,
        roomId: null,
        reason: null,
        notice: '',

        /*
         * Confirmed by the SDK, never set optimistically. The button shows what
         * LiveKit actually did rather than what we asked for — a mic toggle the
         * browser refuses must leave the control reading "muted".
         */
        local: { micOn: false, camOn: false, screenOn: false, audioBlocked: false },

        /*
         * [{ identity, id, name, avatar, initials, joinedSeq, micOn, camOn,
         *    videoSid, screenSid, speaking }]
         *
         * Ordered by joinedSeq and never re-sorted — see the note on SPEAKERS.
         */
        peers: [],
        seq: 0,

        /*
         * What the browser offers, and what this person picked.
         *
         * Plain {deviceId, label} clones only — an enumerateDevices() entry is a
         * live MediaDeviceInfo, and the rule at the top of this file applies to
         * it for the same reason it applies to a Track.
         *
         * Empty lists are the normal state before a huddle: labels are blank
         * until microphone permission has been granted, so there is nothing
         * worth showing until we are connected.
         */
        devices: { audioinput: [], videoinput: [], audiooutput: [] },
        selected: { audioinput: null, videoinput: null, audiooutput: null },

        surface: 'dock',          // 'pill' | 'dock' | 'expanded'
        surfaceBeforeYield: null, // restored when a 1:1 call releases us
        yielding: false,
    };
}

export function reduce(s, event) {
    if (! event || typeof event.type !== 'string') return s;

    /*
     * The room guard, exactly analogous to call-machine.js's call-id guard. A
     * ParticipantConnected arriving after we left is ordinary traffic to be
     * dropped, not a special case in ten handlers.
     */
    if (event.roomId && s.roomId && event.roomId !== s.roomId) return s;

    switch (event.type) {
        case 'RESET':
            // A chosen headset outlives the huddle it was chosen in. The device
            // *list* does not: it is re-read on the next join, when the labels
            // are populated again.
            return { ...initial(), selected: s.selected };

        // Surface changes are always allowed; they touch no connection state.
        case 'SURFACE':
            return { ...s, surface: event.surface };

        case 'YIELD':
            return applyYield(s, event.yielding);

        /*
         * Devices are always allowed too, and for a stronger reason than
         * surface: a headset plugged in while ENDED is on screen must still
         * appear in the menu, and ENDED is absorbing for everything below.
         */
        case 'DEVICES':
            return { ...s, devices: { ...s.devices, ...event.devices } };

        case 'DEVICE_SELECTED':
            if (! (event.kind in s.selected)) return s;

            return { ...s, selected: { ...s.selected, [event.kind]: event.deviceId ?? null } };
    }

    // ENDED is absorbing. A late ParticipantDisconnected must not resurrect
    // anything; only RESET (handled above) returns to idle.
    if (s.state === ENDED) return s;

    switch (s.state) {
        case IDLE:
            return fromIdle(s, event);
        case JOINING:
            return fromJoining(s, event);
        case CONNECTING:
        case LIVE:
        case RECONNECTING:
            return fromConnected(s, event);
        default:
            return s;
    }
}

function fromIdle(s, event) {
    if (event.type !== 'JOIN_REQUESTED') return s;

    return {
        ...initial(),
        state: JOINING,
        roomId: event.roomId,
        surface: s.surface,
        selected: s.selected,
    };
}

function fromJoining(s, event) {
    switch (event.type) {
        case 'TOKEN_OK':
            return { ...s, state: CONNECTING };
        case 'TOKEN_REFUSED':
            // The server's own sentence beats a generic reason — same precedent
            // as call.js preferring the ring endpoint's message.
            return end(s, event.reason ?? 'token_refused', event.message);
        case 'LEAVE_LOCAL':
            return end(s, 'left');
        case 'DISCONNECTED':
            return end(s, event.reason ?? 'signal_lost');
        default:
            return s;
    }
}

function fromConnected(s, event) {
    switch (event.type) {
        case 'CONNECTED':
            return {
                ...s,
                state: LIVE,
                notice: '',
                ...addAll(s, event.peers ?? []),
            };

        case 'CONNECT_FAILED':
            return end(s, event.reason ?? 'signal_lost');

        case 'PARTICIPANT_JOINED':
            return addPeer(s, event.peer);

        case 'PARTICIPANT_LEFT':
            return removePeer(s, event.identity);

        case 'TRACK_CHANGED':
            return updatePeer(s, event.identity, (peer) => trackChange(peer, event));

        /*
         * Sets a flag and nothing else. Re-sorting the roster by who is talking
         * would move DOM nodes, and moving a node containing a <video>
         * re-attaches the track — visible flicker on every "mm-hm". Only stage
         * mode promotes anyone, and only after a dwell.
         */
        case 'SPEAKERS': {
            const speaking = new Set(event.identities ?? []);

            return {
                ...s,
                peers: s.peers.map((peer) => ({ ...peer, speaking: speaking.has(peer.identity) })),
            };
        }

        case 'LOCAL_CHANGED':
            return { ...s, local: { ...s.local, ...event.local } };

        case 'AUDIO_BLOCKED':
            return { ...s, local: { ...s.local, audioBlocked: !! event.blocked } };

        case 'NOTICE':
            return { ...s, notice: event.notice ?? '' };

        case 'NET_RECONNECTING':
            return { ...s, state: RECONNECTING, notice: 'Reconnecting…' };

        case 'NET_RECONNECTED':
            return { ...s, state: LIVE, notice: '' };

        case 'LEAVE_LOCAL':
            return end(s, event.reason ?? 'left');

        case 'DISCONNECTED':
            return end(s, event.reason ?? 'signal_lost');

        default:
            return s;
    }
}

function end(s, reason, message) {
    return {
        ...s,
        state: ENDED,
        reason,
        notice: message || reasonMessage(reason),
        peers: [],
        local: { micOn: false, camOn: false, screenOn: false, audioBlocked: false },
    };
}

/**
 * Collapse to the pill while a 1:1 call needs the screen, and put the surface
 * back afterwards. The huddle yields; the call stack never learns huddles exist.
 */
function applyYield(s, yielding) {
    if (yielding && ! s.yielding) {
        return { ...s, yielding: true, surfaceBeforeYield: s.surface, surface: 'pill' };
    }

    if (! yielding && s.yielding) {
        return { ...s, yielding: false, surface: s.surfaceBeforeYield ?? 'dock', surfaceBeforeYield: null };
    }

    return s;
}

function addAll(s, peers) {
    let next = { peers: s.peers, seq: s.seq };

    for (const peer of peers) {
        const merged = addPeer({ ...s, ...next }, peer);
        next = { peers: merged.peers, seq: merged.seq };
    }

    return next;
}

function addPeer(s, peer) {
    if (! peer || ! peer.identity) return s;

    // LiveKit re-fires ParticipantConnected on reconnect; one identity is one
    // tile, and re-adding must not duplicate or reorder it.
    const existing = s.peers.find((p) => p.identity === peer.identity);

    if (existing) {
        return updatePeer(s, peer.identity, (current) => ({ ...current, ...peer, joinedSeq: current.joinedSeq }));
    }

    const seq = s.seq + 1;

    return {
        ...s,
        seq,
        peers: [...s.peers, {
            micOn: false,
            camOn: false,
            videoSid: null,
            screenSid: null,
            speaking: false,
            ...peer,
            initials: initialsOf(peer.name),
            joinedSeq: seq,
        }],
    };
}

function removePeer(s, identity) {
    if (! s.peers.some((p) => p.identity === identity)) return s;

    return { ...s, peers: s.peers.filter((p) => p.identity !== identity) };
}

function updatePeer(s, identity, mutator) {
    if (! s.peers.some((p) => p.identity === identity)) return s;

    return { ...s, peers: s.peers.map((p) => (p.identity === identity ? mutator(p) : p)) };
}

function trackChange(peer, event) {
    const enabled = !! event.enabled;

    if (event.kind === 'audio') {
        return { ...peer, micOn: enabled };
    }

    if (event.kind === 'screen') {
        return { ...peer, screenSid: enabled ? event.sid ?? null : null };
    }

    // Unsubscribing clears the sid but never removes the peer — a camera going
    // off is not somebody leaving.
    return { ...peer, camOn: enabled, videoSid: enabled ? event.sid ?? null : null };
}

export function initialsOf(name) {
    if (! name) return '?';

    const parts = String(name).trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) return '?';

    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

/* ------------------------------------------------------------------ layout */

/*
 * A fixed lookup rather than a template string, because Tailwind's JIT reads
 * this file literally — see the resources/js entry in tailwind.config.js. An
 * interpolated `grid-cols-${n}` would compile to nothing at all.
 */
const COLUMNS = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-2',
    4: 'grid-cols-2',
    5: 'grid-cols-2 sm:grid-cols-3',
    6: 'grid-cols-2 sm:grid-cols-3',
    7: 'grid-cols-3',
    8: 'grid-cols-3',
    9: 'grid-cols-3',
    10: 'grid-cols-3 sm:grid-cols-4',
    11: 'grid-cols-3 sm:grid-cols-4',
    12: 'grid-cols-3 sm:grid-cols-4',
};

export const DOCK_TILES = 4;
export const EXPANDED_TILES = 12;

export function gridClass(count) {
    return COLUMNS[Math.min(Math.max(count || 1, 1), 12)];
}

/**
 * Past twelve, one large tile plus a filmstrip rather than pagination —
 * pagination in a call is a control nobody uses and everybody maintains.
 */
export function layoutMode(count, surface) {
    if (surface === 'dock' || surface === 'pill') return 'grid';

    return count > EXPANDED_TILES ? 'stage' : 'grid';
}

export function tileCap(surface) {
    return surface === 'expanded' ? EXPANDED_TILES : DOCK_TILES;
}

/**
 * @returns {{tiles: Array, overflow: number}}
 */
export function visibleTiles(peers, surface) {
    const cap = tileCap(surface);
    const ordered = [...peers].sort((a, b) => a.joinedSeq - b.joinedSeq);

    return {
        tiles: ordered.slice(0, cap),
        overflow: Math.max(0, ordered.length - cap),
    };
}

/**
 * Who holds the stage.
 *
 * A screen share always wins outright. Otherwise the loudest speaker takes it,
 * but only after a dwell — without that the stage flickers on every "mm-hm",
 * which is worse than not having a stage at all.
 */
export function chooseStage(peers, now, current = null, dwellMs = 2000) {
    const sharing = peers.find((p) => p.screenSid);

    if (sharing) return sharing.identity;

    const speaking = peers.filter((p) => p.speaking);

    if (speaking.length === 0) {
        return current ?? peers[0]?.identity ?? null;
    }

    const held = peers.find((p) => p.identity === current);

    if (held && held.speaking) return held.identity;

    const candidate = speaking[0];

    if (! candidate.speakingSince || now - candidate.speakingSince >= dwellMs) {
        return candidate.identity;
    }

    return current ?? candidate.identity;
}

/**
 * "You and Ivan", "Ivan, Grace and 4 others" — the banner and the pill subtitle.
 */
export function rosterLabel(participants, meId = null) {
    const others = participants.filter((p) => p.id !== meId);
    const iAmIn = others.length !== participants.length;
    const names = others.map((p) => firstNameOf(p.name));

    if (names.length === 0) return iAmIn ? 'Just you' : 'Nobody yet';

    if (iAmIn) {
        if (names.length === 1) return `You and ${names[0]}`;

        return `You, ${names[0]} and ${plural(names.length - 1)}`;
    }

    if (names.length === 1) return names[0];
    if (names.length === 2) return `${names[0]} and ${names[1]}`;
    if (names.length === 3) return `${names[0]}, ${names[1]} and ${names[2]}`;

    return `${names[0]}, ${names[1]} and ${plural(names.length - 2)}`;
}

function plural(n) {
    return n === 1 ? '1 other' : `${n} others`;
}

function firstNameOf(name) {
    if (! name) return 'Someone';

    return String(name).trim().split(/\s+/)[0];
}
