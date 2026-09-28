/**
 * The call state machine, the negotiation decision table, and the outbox.
 *
 * These are the parts of calling that decide whether it works, and none of them
 * can be observed in a browser test without two machines, two cameras and a lot
 * of patience. Kept pure, they are checked here in milliseconds.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

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
    needsSignalling,
    reasonMessage,
    reduce,
} from '../../resources/js/call-machine.js';

import { isPolite, shouldIgnoreOffer } from '../../resources/js/call-peer.js';
import { Outbox, SignalBuffer, addressedToMe, withinLimit } from '../../resources/js/call-signal.js';

const CALL = 'call-a';
const OTHER = 'call-b';

/** Drive a sequence of events from idle. */
function run(...events) {
    return events.reduce((state, event) => reduce(state, event), initial());
}

const dialled = (callId = CALL) => run({ type: 'DIAL_OK', callId, peer: 2 });
const ringing = (callId = CALL) => reduce(dialled(callId), { type: 'PEER_PRESENT', callId });
const incoming = (callId = CALL) => run({ type: 'INCOMING', callId, peer: 2 });

/* ------------------------------------------------------------- the outline */

test('starts idle with nothing in flight', () => {
    const s = initial();

    assert.equal(s.state, IDLE);
    assert.equal(s.callId, null);
});

test('a placed call waits for the peer before it rings', () => {
    assert.equal(dialled().state, DIALLING);
    assert.equal(ringing().state, RINGING_OUT);
});

/*
 * The "I answered and nothing happened" case. The callee's presence signal can
 * be lost — a whisper sent before the subscription finished, or a join event
 * that arrived before we were listening — and the caller would then sit in
 * dialling. Being answered proves presence better than any presence event does.
 */
test('answering rescues a caller who never saw the peer arrive', () => {
    const s = reduce(dialled(), { type: 'ACCEPT_REMOTE', callId: CALL });

    assert.equal(s.state, CONNECTING, 'an answer must connect even straight from dialling');
});

test('a decline is honoured straight from dialling too', () => {
    assert.equal(reduce(dialled(), { type: 'DECLINE_REMOTE', callId: CALL }).reason, 'declined');
    assert.equal(
        reduce(dialled(), { type: 'DECLINE_REMOTE', callId: CALL, reason: 'busy' }).reason,
        'busy',
    );
});

test('an accepted call connects, then goes active', () => {
    const accepted = reduce(ringing(), { type: 'ACCEPT_REMOTE', callId: CALL });
    assert.equal(accepted.state, CONNECTING);

    assert.equal(reduce(accepted, { type: 'PC_CONNECTED', callId: CALL }).state, ACTIVE);
});

test('an answered incoming call connects', () => {
    assert.equal(reduce(incoming(), { type: 'ACCEPT_LOCAL', callId: CALL }).state, CONNECTING);
});

/* ------------------------------------------------------------ the timeouts */

test('no live tab within the grace period reads as unreachable, not unanswered', () => {
    const s = reduce(dialled(), { type: 'ALERT_TIMEOUT', callId: CALL });

    assert.equal(s.state, ENDED);
    assert.equal(s.reason, 'unreachable');
});

test('ringing out with no answer ends as no_answer', () => {
    assert.equal(reduce(ringing(), { type: 'RING_TIMEOUT', callId: CALL }).reason, 'no_answer');
});

test('ringing in with no answer ends as no_answer', () => {
    assert.equal(reduce(incoming(), { type: 'RING_TIMEOUT', callId: CALL }).reason, 'no_answer');
});

test('ice that never connects ends rather than spinning forever', () => {
    const connecting = reduce(ringing(), { type: 'ACCEPT_REMOTE', callId: CALL });

    assert.equal(reduce(connecting, { type: 'CONNECT_TIMEOUT', callId: CALL }).reason, 'ice_failed');
    assert.equal(reduce(connecting, { type: 'PC_FAILED', callId: CALL }).reason, 'ice_failed');
});

test('a reconnect that never completes ends as signal_lost', () => {
    const active = run(
        { type: 'DIAL_OK', callId: CALL, peer: 2 },
        { type: 'PEER_PRESENT', callId: CALL },
        { type: 'ACCEPT_REMOTE', callId: CALL },
        { type: 'PC_CONNECTED', callId: CALL },
        { type: 'PC_DISCONNECTED', callId: CALL },
    );

    assert.equal(active.state, RECONNECTING);
    assert.equal(reduce(active, { type: 'RECONNECT_TIMEOUT', callId: CALL }).reason, 'signal_lost');
});

/* ------------------------------------------------------------- the refusals */

test('a decline and a busy signal are told apart', () => {
    assert.equal(reduce(ringing(), { type: 'DECLINE_REMOTE', callId: CALL }).reason, 'declined');
    assert.equal(
        reduce(ringing(), { type: 'DECLINE_REMOTE', callId: CALL, reason: 'busy' }).reason,
        'busy',
    );
});

test('the caller can cancel before it is answered', () => {
    assert.equal(reduce(ringing(), { type: 'CANCEL_LOCAL', callId: CALL }).reason, 'cancelled');
});

test('a cancelled ring closes the callee overlay too', () => {
    assert.equal(reduce(incoming(), { type: 'CANCEL_REMOTE', callId: CALL }).reason, 'cancelled');
});

test('refusing the microphone ends the call with a reason that says so', () => {
    assert.equal(reduce(incoming(), { type: 'MEDIA_DENIED', callId: CALL }).reason, 'media_denied');
});

/* -------------------------------------------------- the socket versus media */

/*
 * The property that keeps a deploy from cutting people off mid-sentence. Media
 * is peer-to-peer, so an established call does not need the signalling socket
 * at all.
 */
test('losing the socket does not end a call that is already up', () => {
    const active = run(
        { type: 'DIAL_OK', callId: CALL, peer: 2 },
        { type: 'PEER_PRESENT', callId: CALL },
        { type: 'ACCEPT_REMOTE', callId: CALL },
        { type: 'PC_CONNECTED', callId: CALL },
    );

    assert.equal(reduce(active, { type: 'SIGNAL_LOST', callId: CALL }).state, ACTIVE);
});

test('losing the socket does end a call that still needs it', () => {
    assert.equal(reduce(dialled(), { type: 'SIGNAL_LOST', callId: CALL }).reason, 'signal_lost');
    assert.equal(reduce(incoming(), { type: 'SIGNAL_LOST', callId: CALL }).reason, 'signal_lost');
});

test('needsSignalling agrees with that split', () => {
    assert.equal(needsSignalling(DIALLING), true);
    assert.equal(needsSignalling(RINGING_IN), true);
    assert.equal(needsSignalling(CONNECTING), true);
    assert.equal(needsSignalling(ACTIVE), false);
});

test('a peer that vanishes mid-call is a reconnect, not an ending', () => {
    const active = run(
        { type: 'DIAL_OK', callId: CALL, peer: 2 },
        { type: 'PEER_PRESENT', callId: CALL },
        { type: 'ACCEPT_REMOTE', callId: CALL },
        { type: 'PC_CONNECTED', callId: CALL },
    );

    assert.equal(reduce(active, { type: 'PEER_LEFT', callId: CALL }).state, RECONNECTING);
});

/* ----------------------------------------------------------------- the glare */

/*
 * The single most important test here. Both people dial at the same instant;
 * each machine sees its own call plus an incoming one, and both must land on
 * the same call id without exchanging another message.
 */
test('simultaneous dialling collapses onto one call, from either side', () => {
    const alice = reduce(dialled(CALL), { type: 'INCOMING', callId: OTHER, peer: 2 });
    const bob = reduce(dialled(OTHER), { type: 'INCOMING', callId: CALL, peer: 1 });

    assert.equal(alice.state, CONNECTING);
    assert.equal(bob.state, CONNECTING);
    assert.equal(alice.callId, bob.callId, 'both sides must agree on the surviving call');
    assert.equal(alice.callId, CALL, 'the lower id wins');
});

test('glare is resolved the same way while already ringing out', () => {
    const s = reduce(ringing(CALL), { type: 'INCOMING', callId: OTHER, peer: 2 });

    assert.equal(s.state, CONNECTING);
    assert.equal(s.callId, CALL);
});

test('exactly one side considers itself the originator after a collapse', () => {
    const alice = reduce(dialled(CALL), { type: 'INCOMING', callId: OTHER, peer: 2 });
    const bob = reduce(dialled(OTHER), { type: 'INCOMING', callId: CALL, peer: 1 });

    assert.notEqual(alice.outgoing, bob.outgoing);
});

/* ------------------------------------------------------- stale and foreign */

test('a whisper for another call is inert in every state', () => {
    const states = [dialled(), ringing(), incoming()];

    states.forEach((s) => {
        assert.deepEqual(reduce(s, { type: 'HANGUP_REMOTE', callId: 'someone-else' }), s);
        assert.deepEqual(reduce(s, { type: 'DECLINE_REMOTE', callId: 'someone-else' }), s);
    });
});

test('ended absorbs everything until it is reset', () => {
    const ended = reduce(ringing(), { type: 'RING_TIMEOUT', callId: CALL });

    assert.equal(reduce(ended, { type: 'ACCEPT_REMOTE', callId: CALL }).state, ENDED);
    assert.equal(reduce(ended, { type: 'PC_CONNECTED', callId: CALL }).state, ENDED);
    assert.equal(reduce(ended, { type: 'RESET' }).state, IDLE);
});

/*
 * Handlers can outlive a call — Echo offers no way to unbind presence callbacks
 * individually — so these must be no-ops rather than throwing.
 */
test('presence events left over from a finished call do nothing', () => {
    const idle = initial();

    assert.deepEqual(reduce(idle, { type: 'PEER_PRESENT' }), idle);
    assert.deepEqual(reduce(idle, { type: 'PEER_LEFT' }), idle);
    assert.deepEqual(reduce(idle, { type: 'NONSENSE' }), idle);
    assert.deepEqual(reduce(idle, undefined), idle);
});

test('a second incoming ring is ignored while one is already up', () => {
    const s = incoming(CALL);

    assert.deepEqual(reduce(s, { type: 'ACCEPT_REMOTE', callId: OTHER }), s);
});

test('another tab answering silences this one', () => {
    assert.equal(reduce(incoming(), { type: 'CLAIMED_ELSEWHERE', callId: CALL }).state, ENDED);
});

/* ------------------------------------------------------------ housekeeping */

test('isLive covers exactly the states that own the microphone', () => {
    assert.equal(isLive(CONNECTING), true);
    assert.equal(isLive(ACTIVE), true);
    assert.equal(isLive(RECONNECTING), true);
    assert.equal(isLive(RINGING_OUT), false);
    assert.equal(isLive(IDLE), false);
});

test('every ending has a sentence of its own', () => {
    const reasons = ['no_answer', 'busy', 'declined', 'unreachable', 'ice_failed', 'media_denied'];
    const messages = reasons.map(reasonMessage);

    assert.equal(new Set(messages).size, reasons.length, 'reasons must not share wording');
});

test('video can be toggled mid-call without changing state', () => {
    const active = run(
        { type: 'DIAL_OK', callId: CALL, peer: 2 },
        { type: 'PEER_PRESENT', callId: CALL },
        { type: 'ACCEPT_REMOTE', callId: CALL },
        { type: 'PC_CONNECTED', callId: CALL },
        { type: 'VIDEO_TOGGLED', callId: CALL, enabled: true },
    );

    assert.equal(active.state, ACTIVE);
    assert.equal(active.video, true);
});

/* -------------------------------------------------- perfect negotiation */

test('politeness is opposite on the two sides, and never a tie', () => {
    for (let a = 1; a < 50; a += 1) {
        const b = a + 7;

        assert.notEqual(isPolite(a, b), isPolite(b, a), `ids ${a} and ${b} must disagree`);
    }
});

test('the polite peer yields on a collision and the impolite one does not', () => {
    const collision = {
        makingOffer: true,
        signalingState: 'have-local-offer',
        settingRemoteAnswer: false,
        type: 'offer',
    };

    assert.equal(shouldIgnoreOffer({ ...collision, polite: true }).ignore, false);
    assert.equal(shouldIgnoreOffer({ ...collision, polite: false }).ignore, true);
});

test('an offer arriving on a stable connection is not a collision', () => {
    const result = shouldIgnoreOffer({
        polite: false,
        makingOffer: false,
        signalingState: 'stable',
        settingRemoteAnswer: false,
        type: 'offer',
    });

    assert.equal(result.collision, false);
    assert.equal(result.ignore, false);
});

test('an answer is never treated as a collision', () => {
    const result = shouldIgnoreOffer({
        polite: false,
        makingOffer: true,
        signalingState: 'have-local-offer',
        settingRemoteAnswer: false,
        type: 'answer',
    });

    assert.equal(result.collision, false);
});

/* ------------------------------------------------------------ the transport */

test('only messages for this call, addressed to us, get through', () => {
    const ctx = { callId: CALL, me: 1 };

    assert.equal(addressedToMe({ v: 1, callId: CALL, from: 2, to: 1 }, ctx), true);
    assert.equal(addressedToMe({ v: 1, callId: OTHER, from: 2, to: 1 }, ctx), false, 'other call');
    assert.equal(addressedToMe({ v: 1, callId: CALL, from: 2, to: 3 }, ctx), false, 'not for us');
    assert.equal(addressedToMe({ v: 1, callId: CALL, from: 1, to: 1 }, ctx), false, 'our own echo');
    assert.equal(addressedToMe({ v: 9, callId: CALL, from: 2, to: 1 }, ctx), false, 'wrong version');
    assert.equal(addressedToMe(null, ctx), false);
});

test('the outbox forgets anything too old to still be relevant', () => {
    const outbox = new Outbox({ ttlMs: 1000 });

    outbox.push({ event: 'call-ice' }, 0);
    outbox.push({ event: 'call-ice' }, 900);

    assert.equal(outbox.drain(1500).length, 1, 'the entry queued at 0 is too stale to replay');
});

test('the outbox is bounded, and drops the oldest first', () => {
    const outbox = new Outbox({ limit: 3 });

    ['a', 'b', 'c', 'd'].forEach((event) => outbox.push({ event }, 0));

    assert.deepEqual(outbox.drain(0).map((e) => e.event), ['b', 'c', 'd']);
});

test('draining empties the queue', () => {
    const outbox = new Outbox();

    outbox.push({ event: 'x' }, 0);
    outbox.drain(0);

    assert.equal(outbox.size, 0);
});

test('an oversized payload is refused rather than closing the socket', () => {
    assert.equal(withinLimit({ sdp: 'x'.repeat(100) }, 28000), true);
    assert.equal(withinLimit({ sdp: 'x'.repeat(40000) }, 28000), false);
});

/* -------------------------------------------------- early signalling */

/*
 * The bug that made every call sit on "Connecting…" and then time out.
 *
 * Both sides enter connecting together and both then await the microphone and
 * a credential fetch. Whoever finishes first sends its offer immediately, and
 * the other side has no peer connection to give it to yet. Dropping it there is
 * silent and fatal: no answer is ever produced, so ICE never begins.
 */
test('an offer arriving before the peer connection is built is held, not lost', () => {
    const buffer = new SignalBuffer();

    buffer.hold('description', { type: 'offer', sdp: 'v=0' });

    assert.equal(buffer.size, 1);
    assert.deepEqual(buffer.release(), [
        { kind: 'description', payload: { type: 'offer', sdp: 'v=0' } },
    ]);
});

test('held signalling comes back in arrival order', () => {
    const buffer = new SignalBuffer();

    buffer.hold('description', { type: 'offer' });
    buffer.hold('candidate', { candidate: 'a' });
    buffer.hold('candidate', { candidate: 'b' });

    // The offer must be applied before the candidates that belong to it.
    assert.deepEqual(buffer.release().map((e) => e.kind), ['description', 'candidate', 'candidate']);
});

test('releasing the buffer empties it, so nothing is applied twice', () => {
    const buffer = new SignalBuffer();

    buffer.hold('candidate', { candidate: 'a' });
    buffer.release();

    assert.equal(buffer.size, 0);
    assert.deepEqual(buffer.release(), []);
});

test('the buffer is bounded against a peer that will not stop trickling', () => {
    const buffer = new SignalBuffer({ limit: 2 });

    ['a', 'b', 'c'].forEach((candidate) => buffer.hold('candidate', { candidate }));

    assert.deepEqual(buffer.release().map((e) => e.payload.candidate), ['b', 'c']);
});

test('clearing the buffer drops everything, so a new call starts clean', () => {
    const buffer = new SignalBuffer();

    buffer.hold('description', { type: 'offer' });
    buffer.clear();

    assert.equal(buffer.size, 0);
});
