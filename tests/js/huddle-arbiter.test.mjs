/**
 * The rules that stop a huddle and a one-to-one call fighting over one
 * microphone.
 *
 * This is the highest-risk file in the huddle feature: it is the only thing
 * standing between "an incoming call rings while you are in a huddle" and two
 * live WebRTC sessions in one tab. Every row of the table in huddle-arbiter.js
 * is asserted here, because the failure mode in a browser is intermittent and
 * miserable to reproduce.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { arbitrate } from '../../resources/js/huddle-arbiter.js';

const liveHuddle = { state: 'live' };
const idleHuddle = { state: 'idle' };

const FREE = ['idle', 'ended', undefined, null];
const PLACING = ['dialling', 'ringing_out'];
const ON_A_CALL = ['connecting', 'active', 'reconnecting'];

test('no call means no constraint at all', () => {
    for (const state of FREE) {
        const r = arbitrate(state, liveHuddle);

        assert.equal(r.blockJoin, false, `call state ${state}`);
        assert.equal(r.yield, false, `call state ${state}`);
        assert.equal(r.muteLocal, false, `call state ${state}`);
        assert.equal(r.evict, false, `call state ${state}`);
    }
});

test('every non-idle call state blocks joining', () => {
    for (const state of ['ringing_in', ...PLACING, ...ON_A_CALL]) {
        const r = arbitrate(state, idleHuddle);

        assert.equal(r.blockJoin, true, `call state ${state}`);
        assert.ok(r.blockedReason.length > 0, `call state ${state} should explain itself`);
    }
});

test('the refusal says something different for ringing than for a live call', () => {
    const ringing = arbitrate('ringing_in', idleHuddle).blockedReason;
    const active = arbitrate('active', idleHuddle).blockedReason;

    assert.notEqual(ringing, active);
    assert.match(ringing, /ringing/i);
    assert.match(active, /hang up/i);
});

/*
 * A ringing phone must be audible, and the huddle must not broadcast the
 * ringtone back into the room — but nothing is decided yet, so the huddle is
 * only backgrounded, not ended.
 */
test('an incoming call collapses and mutes the huddle without ending it', () => {
    const r = arbitrate('ringing_in', liveHuddle);

    assert.equal(r.yield, true);
    assert.equal(r.muteLocal, true);
    assert.equal(r.evict, false);
});

test('placing a call behaves the same way as receiving one', () => {
    for (const state of PLACING) {
        const r = arbitrate(state, liveHuddle);

        assert.equal(r.yield, true, `call state ${state}`);
        assert.equal(r.muteLocal, true, `call state ${state}`);
        assert.equal(r.evict, false, `call state ${state}`);
    }
});

/*
 * Answering is the decision. Two live sessions with two microphone claims in one
 * tab is where mobile Safari falls over, so the huddle leaves and offers a
 * one-click rejoin instead.
 */
test('answering a call evicts a live huddle', () => {
    for (const state of ON_A_CALL) {
        assert.equal(arbitrate(state, liveHuddle).evict, true, `call state ${state}`);
    }
});

/*
 * Idempotence. A call that flaps between active and reconnecting must not fire
 * a second disconnect at a huddle that has already gone.
 */
test('a huddle that is not live is never evicted again', () => {
    for (const huddle of [{ state: 'idle' }, { state: 'ended' }, {}]) {
        for (const state of ON_A_CALL) {
            assert.equal(arbitrate(state, huddle).evict, false, `${state} / ${huddle.state}`);
        }
    }
});

test('a flapping call evicts once, not twice', () => {
    let huddle = { state: 'live' };

    assert.equal(arbitrate('active', huddle).evict, true);

    // The glue applies the eviction, so the huddle is now ended.
    huddle = { state: 'ended' };

    assert.equal(arbitrate('reconnecting', huddle).evict, false);
    assert.equal(arbitrate('active', huddle).evict, false);
});

test('hanging up releases the huddle completely', () => {
    const r = arbitrate('ended', liveHuddle);

    assert.equal(r.yield, false);
    assert.equal(r.muteLocal, false);
    assert.equal(r.blockJoin, false);
});

/*
 * Failing closed costs somebody one click. Failing open opens a microphone in a
 * room they had forgotten about.
 */
test('an unrecognised call state is treated as busy', () => {
    const r = arbitrate('some-future-state', liveHuddle);

    assert.equal(r.blockJoin, true);
    assert.equal(r.yield, true);
    assert.equal(r.muteLocal, true);
});

test('every state answers with the full shape', () => {
    for (const state of [...FREE, 'ringing_in', ...PLACING, ...ON_A_CALL, 'nonsense']) {
        const r = arbitrate(state, liveHuddle);

        for (const key of ['blockJoin', 'blockedReason', 'yield', 'muteLocal', 'evict']) {
            assert.ok(key in r, `call state ${state} is missing ${key}`);
        }
    }
});
