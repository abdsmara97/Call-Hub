/**
 * What a live one-to-one call requires of a huddle.
 *
 * The DM call stack — call.js, call-machine.js, call-peer.js, call-signal.js —
 * does not know huddles exist and must not learn. It is the tested, shipped
 * path, and the cost of teaching it about a second WebRTC session would be paid
 * in the one feature people already rely on. So the traffic is one-way: the
 * huddle reads the call's state and yields to it.
 *
 * Every rule lives in this one pure function rather than being scattered across
 * click handlers, because this is the riskiest part of the feature and it has to
 * be reviewable — and testable — in isolation.
 *
 * The two headline rules:
 *
 *  - In a call, Join is refused rather than queued. A queued join that fires
 *    when you hang up is a microphone opening in a room you had forgotten about.
 *
 *  - In a huddle, an incoming call wins. The huddle collapses to the pill and
 *    mutes, so the ringtone is audible and is not broadcast back into the room;
 *    answering evicts the huddle entirely. Evicting rather than backgrounding is
 *    deliberate — two live sessions with two microphone claims in one tab is
 *    where mobile Safari falls over, and a huddle you rejoin in one click costs
 *    almost nothing.
 */

const RINGING_IN = 'ringing_in';
const PLACING = ['dialling', 'ringing_out'];
const ON_A_CALL = ['connecting', 'active', 'reconnecting'];

export function arbitrate(callState, huddleState = {}) {
    const idle = ! callState || callState === 'idle' || callState === 'ended';

    if (idle) {
        return {
            blockJoin: false,
            blockedReason: '',
            yield: false,
            muteLocal: false,
            evict: false,
        };
    }

    if (callState === RINGING_IN) {
        return {
            blockJoin: true,
            blockedReason: 'A call is ringing.',
            yield: true,
            muteLocal: true,
            evict: false,
        };
    }

    if (PLACING.includes(callState)) {
        return {
            blockJoin: true,
            blockedReason: 'You are placing a call.',
            yield: true,
            muteLocal: true,
            evict: false,
        };
    }

    if (ON_A_CALL.includes(callState)) {
        return {
            blockJoin: true,
            blockedReason: 'You are on a call. Hang up first.',
            yield: true,
            muteLocal: true,
            // Idempotent by construction: a huddle that is already idle or ended
            // has nothing to evict, so a call flapping between active and
            // reconnecting does not fire disconnect twice.
            evict: isLive(huddleState),
        };
    }

    // An unknown call state is treated as "busy" rather than "free". Failing
    // closed here costs somebody one click; failing open opens a microphone.
    return {
        blockJoin: true,
        blockedReason: 'A call is in progress.',
        yield: true,
        muteLocal: true,
        evict: false,
    };
}

function isLive(huddleState) {
    const state = huddleState?.state;

    return state !== undefined && state !== 'idle' && state !== 'ended';
}
