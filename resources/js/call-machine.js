/**
 * The call state machine, as a pure function.
 *
 * Nothing in this file touches the DOM, WebRTC, Echo or the clock. That is
 * deliberate and it is the whole point: a call has eight states, roughly thirty
 * transitions and six different ways to time out, and none of that can be
 * tested in a browser without two machines and a lot of luck. Kept pure, it is
 * tested in node in milliseconds — see tests/js/call-machine.test.mjs.
 *
 * Everything impure lives in call.js, which owns the timers, the peer
 * connection and the microphone, and calls in here to decide what happens next.
 */

export const IDLE = 'idle';
export const DIALLING = 'dialling';
export const RINGING_OUT = 'ringing_out';
export const RINGING_IN = 'ringing_in';
export const CONNECTING = 'connecting';
export const ACTIVE = 'active';
export const RECONNECTING = 'reconnecting';
export const ENDED = 'ended';

/** Why a call ended. Drives the closing message, so each one is distinct. */
export const REASONS = {
    local_hangup: 'Call ended.',
    remote_hangup: 'They hung up.',
    declined: 'Call declined.',
    busy: 'They are on another call.',
    no_answer: 'No answer.',
    unreachable: 'They are not online right now.',
    cancelled: 'Call cancelled.',
    ice_failed: 'Could not connect. Your network may be blocking calls.',
    media_denied: 'Microphone access is needed to make a call.',
    signal_lost: 'Connection to the hub was lost.',
    unavailable: 'They cannot take calls right now.',
};

export function initial() {
    return {
        state: IDLE,
        callId: null,
        peer: null,
        reason: null,
        video: false,
        quiet: false,
        outgoing: false,
    };
}

const end = (s, reason) => ({ ...s, state: ENDED, reason });

/**
 * Both people dialled at once.
 *
 * Rather than making either of them press anything, collapse onto a single
 * call: both sides pick the lexicographically lower id, which they can each
 * compute alone from ids they both already hold. Both have evidently just
 * granted microphone access by pressing Call, so there is no consent gap to
 * bridge by asking again.
 *
 * Who sends the offer is settled afterwards by politeness (call-peer.js) and
 * NOT by who dialled — after this collapse there is no caller.
 */
function glare(s, event) {
    const winner = s.callId < event.callId ? s.callId : event.callId;

    return {
        ...s,
        state: CONNECTING,
        callId: winner,
        peer: event.peer ?? s.peer,
        video: s.video || Boolean(event.video),
        outgoing: s.callId === winner,
    };
}

/**
 * (state, event) -> state.
 *
 * Total by design: an unrecognised event in any state returns the state
 * unchanged rather than throwing. A whisper from a call that has already ended
 * is ordinary traffic, not an exception.
 */
export function reduce(s, event) {
    if (! event || typeof event.type !== 'string') return s;

    // Anything addressed to a different call is not our business. This one
    // guard is what makes stale whispers, multiple tabs and glare safe; without
    // it every handler below would need the same check.
    if (event.type !== 'INCOMING' && event.callId && s.callId && event.callId !== s.callId) {
        return s;
    }

    switch (s.state) {
        case IDLE:
            if (event.type === 'DIAL_OK') {
                return {
                    ...initial(),
                    state: DIALLING,
                    callId: event.callId,
                    peer: event.peer ?? null,
                    quiet: Boolean(event.quiet),
                    video: Boolean(event.video),
                    outgoing: true,
                };
            }
            if (event.type === 'DIAL_REFUSED') return end(s, event.reason ?? 'unavailable');
            if (event.type === 'INCOMING') {
                return {
                    ...initial(),
                    state: RINGING_IN,
                    callId: event.callId,
                    peer: event.peer ?? null,
                    quiet: Boolean(event.quiet),
                    video: Boolean(event.video),
                    outgoing: false,
                };
            }

            return s;

        case DIALLING:
            if (event.type === 'PEER_PRESENT') return { ...s, state: RINGING_OUT };

            /*
             * Answering is stronger evidence of presence than the presence
             * channel is, so these are honoured without waiting to pass through
             * ringing_out. Someone who picks up instantly — or whose "I am
             * here" whisper was lost to a subscription that had not finished —
             * must not be told they are unreachable while they are talking.
             */
            if (event.type === 'ACCEPT_REMOTE') {
                return { ...s, state: CONNECTING, video: s.video || Boolean(event.video) };
            }
            if (event.type === 'DECLINE_REMOTE') {
                return end(s, event.reason === 'busy' ? 'busy' : 'declined');
            }

            if (event.type === 'ALERT_TIMEOUT') return end(s, 'unreachable');
            if (event.type === 'CANCEL_LOCAL') return end(s, 'cancelled');
            if (event.type === 'MEDIA_DENIED') return end(s, 'media_denied');
            if (event.type === 'SIGNAL_LOST') return end(s, 'signal_lost');
            if (event.type === 'INCOMING') return glare(s, event);

            return s;

        case RINGING_OUT:
            if (event.type === 'ACCEPT_REMOTE') {
                return { ...s, state: CONNECTING, video: s.video || Boolean(event.video) };
            }
            if (event.type === 'DECLINE_REMOTE') {
                return end(s, event.reason === 'busy' ? 'busy' : 'declined');
            }
            if (event.type === 'RING_TIMEOUT') return end(s, 'no_answer');
            if (event.type === 'CANCEL_LOCAL') return end(s, 'cancelled');
            if (event.type === 'PEER_LEFT') return end(s, 'unreachable');
            if (event.type === 'MEDIA_DENIED') return end(s, 'media_denied');
            if (event.type === 'SIGNAL_LOST') return end(s, 'signal_lost');
            if (event.type === 'INCOMING') return glare(s, event);

            return s;

        case RINGING_IN:
            if (event.type === 'ACCEPT_LOCAL') {
                return { ...s, state: CONNECTING, video: s.video || Boolean(event.video) };
            }
            if (event.type === 'DECLINE_LOCAL') return end(s, 'declined');
            if (event.type === 'CANCEL_REMOTE') return end(s, 'cancelled');
            if (event.type === 'RING_TIMEOUT') return end(s, 'no_answer');
            // Another tab of ours picked it up first.
            if (event.type === 'CLAIMED_ELSEWHERE') return end(s, 'cancelled');
            if (event.type === 'MEDIA_DENIED') return end(s, 'media_denied');
            if (event.type === 'SIGNAL_LOST') return end(s, 'signal_lost');

            return s;

        case CONNECTING:
            if (event.type === 'PC_CONNECTED') return { ...s, state: ACTIVE };
            if (event.type === 'PC_FAILED') return end(s, 'ice_failed');
            if (event.type === 'CONNECT_TIMEOUT') return end(s, 'ice_failed');
            if (event.type === 'HANGUP_LOCAL') return end(s, 'local_hangup');
            if (event.type === 'HANGUP_REMOTE') return end(s, 'remote_hangup');
            if (event.type === 'MEDIA_DENIED') return end(s, 'media_denied');
            if (event.type === 'SIGNAL_LOST') return end(s, 'signal_lost');

            return s;

        case ACTIVE:
            if (event.type === 'PC_DISCONNECTED') return { ...s, state: RECONNECTING };
            if (event.type === 'PC_FAILED') return { ...s, state: RECONNECTING };
            if (event.type === 'PEER_LEFT') return { ...s, state: RECONNECTING };
            if (event.type === 'HANGUP_LOCAL') return end(s, 'local_hangup');
            if (event.type === 'HANGUP_REMOTE') return end(s, 'remote_hangup');
            if (event.type === 'VIDEO_TOGGLED') return { ...s, video: Boolean(event.enabled) };

            // SIGNAL_LOST is deliberately absent. Media is peer-to-peer, so an
            // established call does not need the signalling socket at all — and
            // a Reverb restart during a deploy must not drop people mid-sentence.
            return s;

        case RECONNECTING:
            if (event.type === 'PC_CONNECTED') return { ...s, state: ACTIVE };
            if (event.type === 'RECONNECT_TIMEOUT') return end(s, 'signal_lost');
            if (event.type === 'HANGUP_LOCAL') return end(s, 'local_hangup');
            if (event.type === 'HANGUP_REMOTE') return end(s, 'remote_hangup');
            if (event.type === 'VIDEO_TOGGLED') return { ...s, video: Boolean(event.enabled) };

            return s;

        case ENDED:
            // Absorbing: a late hangup from the peer must not restart anything.
            return event.type === 'RESET' ? initial() : s;

        default:
            return s;
    }
}

/** True while the call owns the microphone and the peer connection. */
export function isLive(state) {
    return state === CONNECTING || state === ACTIVE || state === RECONNECTING;
}

/** True while a ring is in progress in either direction. */
export function isRinging(state) {
    return state === DIALLING || state === RINGING_OUT || state === RINGING_IN;
}

/** Signalling is required until the media path is up. */
export function needsSignalling(state) {
    return isRinging(state) || state === CONNECTING;
}

export function reasonMessage(reason) {
    return REASONS[reason] ?? 'Call ended.';
}
