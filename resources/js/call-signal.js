/**
 * Call signalling over presence-channel whispers.
 *
 * Offers, answers, ICE candidates and hangups are client events: they go
 * browser-to-browser through the socket server without ever reaching PHP. A
 * whole call setup costs less server traffic than one person typing for two
 * seconds, which is why calls do not need a queue, a job or a table.
 *
 * Two things this layer is responsible for:
 *
 *  - Addressing. Every message carries the call id and both user ids, and
 *    anything that does not match is dropped before it reaches the state
 *    machine. Presence channels are shared, so a second call — or a stale
 *    whisper from one that has ended — must be inert rather than confusing.
 *
 *  - Survival across a socket blip. Whispers attempted while the connection is
 *    down are queued briefly and flushed on reconnect, so a supervisor restart
 *    during a deploy does not kill a call that is mid-negotiation.
 */

export const PROTOCOL_VERSION = 1;

const OUTBOX_LIMIT = 64;
const OUTBOX_TTL_MS = 15000;

export function buildEnvelope({ callId, from, to }) {
    return { v: PROTOCOL_VERSION, callId, from, to };
}

/**
 * Is this message ours to act on?
 *
 * Rejects: wrong protocol version, a different call, someone else's traffic on
 * a shared channel, and our own messages echoed back.
 */
export function addressedToMe(payload, { callId, me }) {
    if (! payload || payload.v !== PROTOCOL_VERSION) return false;
    if (payload.to !== me) return false;
    if (payload.from === me) return false;

    return ! callId || payload.callId === callId;
}

/**
 * Holds whispers that could not be sent because the socket was down.
 *
 * Bounded twice over — by age and by count — because the alternative is
 * replaying a minute-old offer into a call that has long since ended. Anything
 * that cannot be delivered within a few seconds should be allowed to fail.
 */
export class Outbox {
    constructor({ limit = OUTBOX_LIMIT, ttlMs = OUTBOX_TTL_MS } = {}) {
        this.limit = limit;
        this.ttlMs = ttlMs;
        this.entries = [];
    }

    push(entry, now) {
        this.entries.push({ ...entry, queuedAt: now });

        // Drop the oldest rather than the newest: later signalling supersedes
        // earlier signalling.
        if (this.entries.length > this.limit) {
            this.entries.splice(0, this.entries.length - this.limit);
        }
    }

    /** Returns everything still fresh, and empties the queue. */
    drain(now) {
        const fresh = this.entries.filter((entry) => now - entry.queuedAt <= this.ttlMs);

        this.entries = [];

        return fresh;
    }

    clear() {
        this.entries = [];
    }

    get size() {
        return this.entries.length;
    }
}

/**
 * Holds signalling that arrived before the peer connection was ready for it.
 *
 * Both sides enter the connecting state at the same moment and both then wait
 * on the microphone and on a credential fetch. Whoever finishes first adds its
 * tracks, negotiation fires, and its offer goes out — while the other side has
 * no RTCPeerConnection to hand it to yet. Dropping it there is fatal and
 * silent: no answer is ever sent, ICE never starts, and the call sits on
 * "Connecting…" until it times out. The gap is widest exactly when it matters
 * most, because a first-time microphone prompt blocks for seconds.
 */
export class SignalBuffer {
    constructor({ limit = 64 } = {}) {
        this.limit = limit;
        this.entries = [];
    }

    hold(kind, payload) {
        this.entries.push({ kind, payload });

        // Later signalling supersedes earlier signalling, so drop from the front.
        if (this.entries.length > this.limit) {
            this.entries.splice(0, this.entries.length - this.limit);
        }
    }

    /** Returns what was held, in arrival order, and empties the buffer. */
    release() {
        const held = this.entries;

        this.entries = [];

        return held;
    }

    clear() {
        this.entries = [];
    }

    get size() {
        return this.entries.length;
    }
}

/** Whether a payload will fit through the socket's message size limit. */
export function withinLimit(payload, maxBytes) {
    try {
        return JSON.stringify(payload).length <= maxBytes;
    } catch {
        return false;
    }
}

function socketConnected() {
    return window.Echo?.connector?.pusher?.connection?.state === 'connected';
}

/**
 * @param {object} options
 * @param {object} options.channel        an Echo presence channel
 * @param {number} options.me             our user id
 * @param {number} options.peerId         the other party's user id
 * @param {number} options.maxBytes       refuse anything larger
 * @param {() => number} options.now      injected clock, so this is testable
 * @param {(event: string) => void} options.onOversize
 */
export function createSignal({
    channel,
    me,
    peerId,
    maxBytes,
    now = () => Date.now(),
    isConnected = socketConnected,
    onOversize = () => {},
}) {
    const outbox = new Outbox();
    const bound = [];

    let callId = null;

    function whisper(event, payload) {
        try {
            channel.whisper(event, payload);

            return true;
        } catch {
            return false;
        }
    }

    return {
        setCallId(id) {
            callId = id;
        },

        /**
         * Returns false when the message could not go out at all — as opposed
         * to being queued, which counts as sent from the caller's perspective.
         */
        send(event, payload = {}) {
            const message = { ...buildEnvelope({ callId, from: me, to: peerId }), ...payload };

            // Too large for the socket. Reverb closes the connection rather
            // than truncating, so refusing here turns "the camera killed the
            // call" into "video is unavailable on this connection".
            if (! withinLimit(message, maxBytes)) {
                onOversize(event);

                return false;
            }

            if (! isConnected()) {
                outbox.push({ event, message }, now());

                return true;
            }

            return whisper(event, message);
        },

        /** Called on reconnect. Stale entries are dropped, not replayed. */
        flush() {
            outbox.drain(now()).forEach(({ event, message }) => whisper(event, message));
        },

        /** Binds a handler that only sees traffic addressed to this call. */
        on(event, handler) {
            const wrapped = (payload) => {
                if (! addressedToMe(payload, { callId, me })) return;

                handler(payload);
            };

            channel.listenForWhisper(event, wrapped);
            bound.push([event, wrapped]);
        },

        /**
         * Unbinds only our own handlers. The channel itself belongs to whoever
         * else is holding it — see presence.js.
         */
        teardown() {
            bound.forEach(([event, wrapped]) => channel.stopListeningForWhisper(event, wrapped));
            bound.length = 0;
            outbox.clear();
            callId = null;
        },
    };
}
