/**
 * The join banner's view model.
 *
 * This runs for everybody in a room — several hundred people in a company-wide
 * one — while the huddle machine runs only for the handful who join. So its
 * behaviour when nothing is happening matters as much as its behaviour when
 * something is.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { bannerFrom, elapsedLabel, mergeSnapshot } from '../../resources/js/huddle-roster.js';

const ME = 1;

function snapshot(count, { startedAt = 1_754_236_800, includeMe = false } = {}) {
    const participants = Array.from({ length: Math.min(count, 8) }, (_, i) => ({
        id: includeMe && i === 0 ? ME : i + 10,
        name: `Person ${i} Surname`,
        avatar_url: null,
    }));

    return {
        room_id: 7,
        active: true,
        version: 1,
        started_at: startedAt,
        started_by: { id: 10, name: 'Person 0 Surname' },
        participant_count: count,
        participants,
    };
}

test('a small huddle shows everyone and no overflow', () => {
    const b = bannerFrom(snapshot(3), { meId: ME });

    assert.equal(b.visible, true);
    assert.equal(b.phase, 'live');
    assert.equal(b.facepile.length, 3);
    assert.equal(b.overflow, 0);
    assert.equal(b.count, 3);
});

/*
 * The payload carries at most `broadcast_participants` entries but always the
 * true count, so the overflow has to be computed from the count and not from the
 * array — otherwise a 40-person huddle would read "+5".
 */
test('a large huddle reports the overflow from the true count', () => {
    const b = bannerFrom(snapshot(40), { meId: ME });

    assert.equal(b.facepile.length, 3);
    assert.equal(b.overflow, 37);
    assert.equal(b.count, 40);
});

test('an inactive payload is not shown', () => {
    const b = bannerFrom({ room_id: 7, active: false, participant_count: 0, participants: [] }, { meId: ME });

    assert.equal(b.visible, false);
    assert.equal(b.phase, 'hidden');
});

/*
 * The banner never simply vanishes mid-glance; that reads as a bug rather than
 * as an ending.
 */
test('a huddle that was live and is now empty shows an ended line', () => {
    const b = bannerFrom(
        { room_id: 7, active: false, participant_count: 0, participants: [], was_active: true },
        { meId: ME },
    );

    assert.equal(b.visible, true);
    assert.equal(b.phase, 'ended');
    assert.match(b.headline, /ended/i);
});

test('it knows whether I am already in the huddle', () => {
    assert.equal(bannerFrom(snapshot(3), { meId: ME }).iAmIn, false);
    assert.equal(bannerFrom(snapshot(3, { includeMe: true }), { meId: ME }).iAmIn, true);
});

/* -------------------------------------------------------------- dismissal */

test('dismissing hides this huddle', () => {
    const payload = snapshot(3, { startedAt: 5000 });

    assert.equal(bannerFrom(payload, { meId: ME, dismissedAt: 5000 }).visible, false);
});

/*
 * Keyed on the start time rather than the room, so a later huddle in the same
 * room is a new offer and shows up again.
 */
test('a later huddle in the same room reappears after a dismissal', () => {
    const dismissed = 5000;
    const later = snapshot(3, { startedAt: 9000 });

    assert.equal(bannerFrom(later, { meId: ME, dismissedAt: dismissed }).visible, true);
});

test('a dismissal never hides a huddle I am actually in', () => {
    const payload = snapshot(3, { startedAt: 5000, includeMe: true });

    assert.equal(bannerFrom(payload, { meId: ME, dismissedAt: 5000 }).visible, true);
});

/* ---------------------------------------------------------------- merging */

test('an older snapshot is discarded', () => {
    const current = { ...snapshot(5), version: 100 };
    const stale = { ...snapshot(2), version: 90 };

    assert.equal(mergeSnapshot(current, stale).participant_count, 5);
});

test('a newer snapshot wins', () => {
    const current = { ...snapshot(5), version: 100 };
    const fresh = { ...snapshot(9), version: 110 };

    assert.equal(mergeSnapshot(current, fresh).participant_count, 9);
});

test('merging remembers that a huddle had been live', () => {
    const current = { ...snapshot(2), version: 100 };
    const ended = { room_id: 7, active: false, version: 110, participant_count: 0, participants: [] };

    assert.equal(mergeSnapshot(current, ended).was_active, true);
    assert.equal(bannerFrom(mergeSnapshot(current, ended), { meId: ME }).phase, 'ended');
});

test('merging nothing keeps what we had', () => {
    const current = snapshot(3);

    assert.equal(mergeSnapshot(current, null), current);
});

/* ---------------------------------------------------------------- elapsed */

test('elapsed reads naturally across the ranges', () => {
    const started = 1_000_000;

    assert.equal(elapsedLabel(started, (started + 30) * 1000), 'just started');
    assert.equal(elapsedLabel(started, (started + 300) * 1000), '5 min');
    assert.equal(elapsedLabel(started, (started + 3900) * 1000), '1 hr 5 min');
    assert.equal(elapsedLabel(null, Date.now()), '');
});

test('a clock that disagrees does not produce a negative duration', () => {
    const started = 1_000_000;

    assert.equal(elapsedLabel(started, (started - 500) * 1000), 'just started');
});
